<?php

namespace App\Services;

use App\Models\AperturaCaja;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\PagoProveedor;
use App\Models\Pedido;
use App\Models\PedidoAdelanto;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Todo lo que entró y salió de caja en un rango de fechas (o en un turno), fila por fila y con su medio de pago,
 * como la lista de movimientos del sistema anterior: arriba lo que entró, abajo lo que salió, y el saldo.
 */
class MovimientosCajaService
{
    public const TIPOS_COMPROBANTE = ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito'];

    /**
     * @param  list<string>|null  $sucursalIds  null = todas las sucursales de la empresa
     * @return array{entradas: list<array>, salidas: list<array>, resumen: array}
     */
    public function listar(Usuario $usuario, ?array $sucursalIds, ?AperturaCaja $turno, string $desde, string $hasta): array
    {
        $aperturas = $this->aperturas($usuario, $sucursalIds, $turno);
        $inicio = CarbonImmutable::parse($desde, config('app.timezone'))->startOfDay();
        $fin = CarbonImmutable::parse($hasta, config('app.timezone'))->endOfDay();
        $enRango = fn (Builder $q, string $tabla) => $turno ? $q : $q->whereBetween("{$tabla}.creado_en", [$inicio, $fin]);

        $nombres = MedioPago::pluck('nombre', 'codigo');
        $medio = fn (?string $codigo) => $nombres[$codigo] ?? ucfirst((string) $codigo);
        $turnoDe = fn ($apertura) => $apertura ? ['id' => $apertura->id, 'caja' => $apertura->caja?->nombre, 'cajero' => $apertura->usuario?->nombre_completo] : null;

        // ---- entradas ----
        $ventas = $enRango(Pago::query(), 'pagos')
            ->whereIn('pagos.apertura_id', $aperturas)
            ->whereNotIn('pagos.medio_pago_codigo', MedioPago::INTERNOS)
            ->with(['comprobante:id,serie,correlativo,tipo_comprobante_codigo,cliente_nombre,total,es_credito', 'apertura.caja:id,nombre', 'apertura.usuario:id,nombre_completo', 'usuario:id,nombre_completo'])
            ->get()
            ->map(fn (Pago $p) => [
                'clave' => "venta-{$p->id}", 'tipo' => 'venta', 'detalle_tipo' => 'venta', 'detalle_id' => $p->comprobante_id,
                'titulo' => self::TIPOS_COMPROBANTE[$p->comprobante?->tipo_comprobante_codigo] ?? 'Venta',
                'detalle' => trim(($p->comprobante ? "{$p->comprobante->serie}-".str_pad((string) $p->comprobante->correlativo, 6, '0', STR_PAD_LEFT) : '').' · '.($p->comprobante?->cliente_nombre ?: 'Público general'), ' ·'),
                'fecha' => $p->creado_en, 'medio_codigo' => $p->medio_pago_codigo, 'medio' => $medio($p->medio_pago_codigo), 'referencia' => $p->referencia,
                'monto' => (float) $p->monto, 'usuario' => $p->usuario?->nombre_completo, 'turno' => $turnoDe($p->apertura),
            ]);

        $cobros = $enRango(Cobro::query(), 'cobros')
            ->whereIn('cobros.apertura_id', $aperturas)
            ->with(['cuenta.cliente:id,nombre', 'cuenta.comprobante:id,serie,correlativo', 'apertura.caja:id,nombre', 'apertura.usuario:id,nombre_completo', 'usuario:id,nombre_completo'])
            ->get()
            ->map(fn (Cobro $c) => [
                'clave' => "cobro-{$c->id}", 'tipo' => 'cobro', 'detalle_tipo' => 'cobro', 'detalle_id' => $c->id,
                'titulo' => $c->abono_inicial ? 'A cuenta en venta al crédito' : 'Cobro de crédito',
                'detalle' => trim(($c->cuenta?->cliente?->nombre ?? '').($c->cuenta?->comprobante ? " · venta {$c->cuenta->comprobante->serie}-".str_pad((string) $c->cuenta->comprobante->correlativo, 6, '0', STR_PAD_LEFT) : ''), ' ·'),
                'fecha' => $c->creado_en, 'medio_codigo' => $c->medio_pago_codigo, 'medio' => $medio($c->medio_pago_codigo), 'referencia' => $c->referencia,
                'monto' => (float) $c->monto, 'usuario' => $c->usuario?->nombre_completo, 'turno' => $turnoDe($c->apertura),
            ]);

        $manuales = $enRango(MovimientoCaja::query(), 'movimientos_caja')
            ->whereIn('movimientos_caja.apertura_id', $aperturas)
            ->with(['apertura.caja:id,nombre', 'apertura.usuario:id,nombre_completo', 'usuario:id,nombre_completo'])
            ->get();
        $adelantos = PedidoAdelanto::whereIn('movimiento_caja_id', $manuales->pluck('id'))->with('pedido:id,numero,cliente_nombre')->get()->keyBy('movimiento_caja_id');
        $devolucionesPedido = Pedido::whereIn('devolucion_movimiento_id', $manuales->pluck('id'))->get(['id', 'numero', 'cliente_nombre', 'devolucion_movimiento_id'])->keyBy('devolucion_movimiento_id');

        $filaManual = function (MovimientoCaja $m) use ($adelantos, $devolucionesPedido, $medio, $turnoDe) {
            [$tipo, $titulo, $detalle] = match (true) {
                isset($adelantos[$m->id]) => ['adelanto', 'Adelanto de pedido', $adelantos[$m->id]->pedido?->codigo().' · '.$adelantos[$m->id]->pedido?->cliente_nombre],
                isset($devolucionesPedido[$m->id]) => ['devolucion', 'Devolución de adelanto', $devolucionesPedido[$m->id]->codigo().' · '.$devolucionesPedido[$m->id]->cliente_nombre],
                str_starts_with($m->concepto, 'Devolución por nota de crédito') => ['devolucion', 'Devolución al cliente', $m->concepto],
                $m->tipo === 'ingreso' => ['ingreso', 'Ingreso', $m->concepto],
                default => ['egreso', 'Egreso', $m->concepto],
            };

            return [
                'clave' => "mov-{$m->id}", 'tipo' => $tipo, 'detalle_tipo' => 'movimiento', 'detalle_id' => $m->id,
                'titulo' => $titulo, 'detalle' => $detalle,
                'fecha' => $m->creado_en, 'medio_codigo' => $m->medio_pago_codigo, 'medio' => $medio($m->medio_pago_codigo), 'referencia' => $m->referencia,
                'monto' => (float) $m->monto, 'usuario' => $m->usuario?->nombre_completo, 'turno' => $turnoDe($m->apertura),
            ];
        };
        $ingresos = $manuales->where('tipo', 'ingreso')->map($filaManual);
        $egresos = $manuales->where('tipo', 'egreso')->map($filaManual);

        // ---- salidas ----
        $proveedores = $enRango(PagoProveedor::query(), 'pagos_proveedor')
            ->whereIn('pagos_proveedor.apertura_id', $aperturas)
            ->with(['cuenta.proveedor' => fn ($q) => $q->withTrashed()->select(['id', 'razon_social']), 'compra:id,proveedor_id,serie_numero', 'compra.proveedor' => fn ($q) => $q->withTrashed()->select(['id', 'razon_social']), 'apertura.caja:id,nombre', 'apertura.usuario:id,nombre_completo', 'usuario:id,nombre_completo'])
            ->get()
            ->map(function (PagoProveedor $p) use ($medio, $turnoDe) {
                $proveedor = $p->compra_id ? $p->compra?->proveedor?->razon_social : $p->cuenta?->proveedor?->razon_social;

                return [
                    'clave' => "prov-{$p->id}", 'tipo' => $p->compra_id ? 'compra' : 'pago_proveedor',
                    'detalle_tipo' => $p->compra_id ? 'compra' : 'pago_proveedor', 'detalle_id' => $p->compra_id ?: $p->id,
                    'titulo' => $p->compra_id ? 'Compra' : 'Pago a proveedor',
                    'detalle' => trim(($p->compra?->serie_numero ? "{$p->compra->serie_numero} · " : '').($proveedor ?: ($p->compra_id ? 'sin proveedor' : 'deuda')), ' ·'),
                    'fecha' => $p->creado_en, 'medio_codigo' => $p->medio_pago_codigo, 'medio' => $medio($p->medio_pago_codigo), 'referencia' => $p->referencia,
                    'monto' => (float) $p->monto, 'usuario' => $p->usuario?->nombre_completo, 'turno' => $turnoDe($p->apertura),
                ];
            });

        $entradas = $ventas->concat($cobros)->concat($ingresos)->sortByDesc('fecha')->values();
        $salidas = $egresos->concat($proveedores)->sortByDesc('fecha')->values();

        $porMedio = fn (Collection $filas) => $filas->groupBy('medio_codigo')
            ->map(fn ($g, $codigo) => ['codigo' => $codigo, 'medio' => $medio($codigo), 'monto' => round($g->sum('monto'), 2)])
            ->sortBy(fn ($f) => ($f['codigo'] === 'efectivo' ? '0' : '1').$f['medio'])->values()->all();

        $totalEntro = round($entradas->sum('monto'), 2);
        $totalSalio = round($salidas->sum('monto'), 2);

        return [
            'entradas' => $entradas->all(),
            'salidas' => $salidas->all(),
            'resumen' => [
                'entro' => $totalEntro,
                'entro_por_medio' => $porMedio($entradas),
                'salio' => $totalSalio,
                'salio_por_medio' => $porMedio($salidas),
                'saldo' => round($totalEntro - $totalSalio, 2),
                'movimientos' => $entradas->count() + $salidas->count(),
            ],
        ];
    }

    /** Detalle de una fila para el "ojo": la venta con sus productos, la compra, el cobro o el movimiento manual. */
    public function detalle(Usuario $usuario, ?array $sucursalIds, string $tipo, string $id): ?array
    {
        $empresaId = $usuario->empresa_id;
        $aperturas = $this->aperturas($usuario, $sucursalIds, null);
        $numero = fn ($c) => "{$c->serie}-".str_pad((string) $c->correlativo, 6, '0', STR_PAD_LEFT);
        $unidades = UnidadMedida::pluck('nombre', 'codigo');
        $unidad = fn (?string $codigo) => $unidades[trim((string) $codigo)] ?? trim((string) $codigo);

        return match ($tipo) {
            'venta' => ($c = Comprobante::where('empresa_id', $empresaId)->whereHas('pagos', fn ($q) => $q->whereIn('apertura_id', $aperturas))
                ->with(['detalles:id,comprobante_id,descripcion,unidad_codigo,cantidad,precio_unitario,total', 'pagos.medioPago:codigo,nombre', 'usuario:id,nombre_completo'])->find($id)) ? [
                    'tipo' => 'venta', 'titulo' => (self::TIPOS_COMPROBANTE[$c->tipo_comprobante_codigo] ?? 'Venta').' '.$numero($c),
                    'fecha' => $c->creado_en, 'cliente' => $c->cliente_nombre ?: 'Público general', 'usuario' => $c->usuario?->nombre_completo,
                    'condicion' => $c->es_credito ? 'Crédito' : 'Contado', 'estado' => $c->estado,
                    'items' => $c->detalles->map(fn ($d) => ['descripcion' => $d->descripcion, 'cantidad' => (float) $d->cantidad, 'unidad' => $unidad($d->unidad_codigo), 'precio' => (float) $d->precio_unitario, 'total' => (float) $d->total])->values(),
                    'pagos' => $c->pagos->map(fn ($p) => ['medio' => $p->medioPago?->nombre ?? $p->medio_pago_codigo, 'monto' => (float) $p->monto, 'referencia' => $p->referencia])->values(),
                    'total' => (float) $c->total,
                    'ticket' => route('comprobantes.ticket', $c),
                ] : null,
            'compra' => ($co = Compra::where('empresa_id', $empresaId)->whereHas('pagoCaja', fn ($q) => $q->whereIn('apertura_id', $aperturas))
                ->with(['proveedor' => fn ($q) => $q->withTrashed()->select(['id', 'razon_social']), 'detalles.producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre'), 'detalles.presentacion:id,nombre', 'pagoCaja.medioPago:codigo,nombre', 'usuario:id,nombre_completo'])->find($id)) ? [
                    'tipo' => 'compra', 'titulo' => 'Compra'.($co->serie_numero ? " {$co->serie_numero}" : ''),
                    'fecha' => $co->creado_en, 'proveedor' => $co->proveedor?->razon_social ?? 'Sin proveedor', 'usuario' => $co->usuario?->nombre_completo, 'estado' => $co->estado,
                    'items' => $co->detalles->map(fn ($d) => ['descripcion' => trim(($d->producto?->nombre ?? 'Producto').($d->presentacion ? " ({$d->presentacion->nombre})" : '')), 'cantidad' => (float) $d->cantidad, 'precio' => (float) $d->costo_unitario, 'total' => (float) $d->total])->values(),
                    'pagos' => $co->pagoCaja ? [['medio' => $co->pagoCaja->medioPago?->nombre ?? $co->pagoCaja->medio_pago_codigo, 'monto' => (float) $co->pagoCaja->monto, 'referencia' => $co->pagoCaja->referencia]] : [],
                    'total' => (float) $co->total,
                    // el PDF de la compra exige compras.ver: a quien no lo tiene no se le ofrece el botón
                    'pdf' => $usuario->can('compras.ver') ? route('compras.pdf', $co) : null,
                ] : null,
            'pago_proveedor' => ($p = PagoProveedor::where('empresa_id', $empresaId)->whereIn('apertura_id', $aperturas)
                ->with(['cuenta.proveedor' => fn ($q) => $q->withTrashed()->select(['id', 'razon_social']), 'cuenta.compra:id,serie_numero', 'medioPago:codigo,nombre', 'usuario:id,nombre_completo'])->find($id)) ? [
                    'tipo' => 'pago_proveedor', 'titulo' => 'Pago a proveedor', 'fecha' => $p->creado_en,
                    'proveedor' => $p->cuenta?->proveedor?->razon_social, 'compra' => $p->cuenta?->compra?->serie_numero, 'usuario' => $p->usuario?->nombre_completo,
                    'monto' => (float) $p->monto, 'medio' => $p->medioPago?->nombre ?? $p->medio_pago_codigo, 'referencia' => $p->referencia,
                    'deuda_total' => (float) ($p->cuenta?->monto_total ?? 0), 'deuda_pagada' => (float) ($p->cuenta?->monto_pagado ?? 0),
                ] : null,
            'cobro' => ($c = Cobro::where('empresa_id', $empresaId)->whereIn('apertura_id', $aperturas)
                ->with(['cuenta.cliente:id,nombre', 'cuenta.comprobante:id,serie,correlativo', 'medioPago:codigo,nombre', 'usuario:id,nombre_completo'])->find($id)) ? [
                    'tipo' => 'cobro', 'titulo' => $c->abono_inicial ? 'A cuenta en venta al crédito' : 'Cobro de crédito', 'fecha' => $c->creado_en,
                    'cliente' => $c->cuenta?->cliente?->nombre, 'venta' => $c->cuenta?->comprobante ? $numero($c->cuenta->comprobante) : null, 'usuario' => $c->usuario?->nombre_completo,
                    'monto' => (float) $c->monto, 'medio' => $c->medioPago?->nombre ?? $c->medio_pago_codigo, 'referencia' => $c->referencia,
                    'deuda_total' => (float) ($c->cuenta?->monto_total ?? 0), 'deuda_pagada' => (float) ($c->cuenta?->monto_pagado ?? 0),
                ] : null,
            'movimiento' => ($m = MovimientoCaja::where('empresa_id', $empresaId)->whereIn('apertura_id', $aperturas)
                ->with(['medioPago:codigo,nombre', 'usuario:id,nombre_completo', 'apertura.caja:id,nombre'])->find($id)) ? [
                    'tipo' => 'movimiento', 'titulo' => $m->tipo === 'ingreso' ? 'Ingreso de caja' : 'Egreso de caja', 'fecha' => $m->creado_en,
                    'concepto' => $m->concepto, 'usuario' => $m->usuario?->nombre_completo, 'caja' => $m->apertura?->caja?->nombre,
                    'monto' => (float) $m->monto, 'medio' => $m->medioPago?->nombre ?? $m->medio_pago_codigo, 'referencia' => $m->referencia,
                    'pedido' => ($a = PedidoAdelanto::where('movimiento_caja_id', $m->id)->with('pedido:id,numero,cliente_nombre')->first())
                        ? ['codigo' => $a->pedido?->codigo(), 'cliente' => $a->pedido?->cliente_nombre]
                        : (($pe = Pedido::where('devolucion_movimiento_id', $m->id)->first(['id', 'numero', 'cliente_nombre'])) ? ['codigo' => $pe->codigo(), 'cliente' => $pe->cliente_nombre] : null),
                ] : null,
            default => null,
        };
    }

    /**
     * Ids de los turnos que el usuario puede ver: los de la empresa (y sucursal elegida); sin caja.ver_todas, solo los suyos.
     *
     * @return Builder ids de aperturas_caja (subconsulta)
     */
    private function aperturas(Usuario $usuario, ?array $sucursalIds, ?AperturaCaja $turno): Builder
    {
        return AperturaCaja::query()->select('id')
            ->where('empresa_id', $usuario->empresa_id)
            ->when($turno, fn ($q) => $q->whereKey($turno->id))
            ->when(! $usuario->can('caja.ver_todas'), fn ($q) => $q->where('usuario_id', $usuario->id))
            // un turno concreto ya lo autorizó el controlador (empresa, cajero y sucursal): no se filtra por el selector
            ->when($sucursalIds && ! $turno, fn ($q) => $q->whereHas('caja', fn ($c) => $c->whereIn('sucursal_id', $sucursalIds)));
    }
}
