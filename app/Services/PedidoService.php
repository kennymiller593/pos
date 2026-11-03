<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\AperturaCaja;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\PedidoAdelanto;
use App\Models\Sucursal;
use App\Models\Usuario;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedidos (encargos): se calculan con las mismas reglas de la venta (precio de lista o mayorista,
 * descuentos, IGV, Nuevo RUS) pero no tocan stock ni SUNAT. Los adelantos sí entran a la caja abierta
 * del día (son dinero real); la venta nace al entregar desde el POS cobrando el saldo.
 */
class PedidoService
{
    public function __construct(private readonly VentaService $ventas, private readonly CajaService $caja) {}

    /**
     * Crea un pedido o reemplaza el contenido de uno pendiente.
     *
     * $datos ya validados: cliente_id, fecha_entrega, hora_entrega?, observaciones?,
     * items[{presentacion_id, cantidad, precio_unitario?, descuento?}]
     *
     * @throws ErrorDeNegocio
     */
    public function guardar(Usuario $usuario, string $sucursalId, array $datos, ?Pedido $pedido = null): Pedido
    {
        $empresaId = $usuario->empresa_id;

        if ($pedido && $pedido->estado !== 'pendiente') {
            throw new ErrorDeNegocio('Solo se puede editar un pedido pendiente.');
        }

        $cliente = Cliente::where('empresa_id', $empresaId)->find($datos['cliente_id']);
        if (! $cliente) {
            throw new ErrorDeNegocio('El cliente elegido no existe.');
        }

        [$lineas, $totales] = $this->ventas->calcularLineas($empresaId, $datos['items'], $usuario->empresa->esRus());

        return DB::transaction(function () use ($usuario, $empresaId, $sucursalId, $datos, $pedido, $cliente, $lineas, $totales) {
            $adelantos = 0.0;

            if ($pedido) {
                // con la fila bloqueada: un adelanto que entre a la vez no puede dejar el total por debajo
                $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
                if ($pedido->estado !== 'pendiente') {
                    throw new ErrorDeNegocio('Solo se puede editar un pedido pendiente.');
                }

                $adelantos = $pedido->totalAdelantos();
                if ($adelantos > round($totales['total'], 2) + 0.005) {
                    throw new ErrorDeNegocio(sprintf(
                        'El pedido ya tiene S/ %s de adelanto: el total no puede quedar por debajo. Si el cliente cambió de idea, anúlalo y devuelve el adelanto.',
                        number_format($adelantos, 2),
                    ));
                }
            }

            $cabecera = [
                'cliente_id' => $cliente->id,
                'fecha_entrega' => $datos['fecha_entrega'],
                'hora_entrega' => filled($datos['hora_entrega'] ?? null) ? $datos['hora_entrega'] : null,
                'observaciones' => $datos['observaciones'] ?? null,
                'cliente_tipo_doc' => $cliente->tipo_documento_codigo,
                'cliente_numero_doc' => $cliente->numero_documento,
                'cliente_nombre' => $cliente->nombre,
                'cliente_telefono' => $cliente->telefono,
                'cliente_direccion' => $cliente->direccion,
                'total_gravado' => round($totales['gravado'], 2),
                'total_exonerado' => round($totales['exonerado'], 2),
                'total_inafecto' => round($totales['inafecto'], 2),
                'total_igv' => round($totales['igv'], 2),
                'total_descuentos' => round($totales['descuentos'], 2),
                'total' => round($totales['total'], 2),
            ];

            if ($pedido) {
                $totalAnterior = (float) $pedido->total;
                $pedido->update($cabecera);
                $pedido->detalles()->delete();

                // cambiar el monto de un pedido con dinero recibido deja constancia
                if ($adelantos > 0 && abs($totalAnterior - (float) $pedido->total) > 0.005) {
                    Auditoria::registrar($usuario, 'pedido.editado', 'pedido', $pedido->id, [
                        'pedido' => $pedido->codigo(),
                        'total_anterior' => $totalAnterior,
                        'total_nuevo' => (float) $pedido->total,
                        'adelantos' => $adelantos,
                    ]);
                }
            } else {
                // el correlativo es por empresa: dos usuarios registrando a la vez se serializan aqui
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["pedidos:{$empresaId}"]);
                $numero = (int) Pedido::where('empresa_id', $empresaId)->max('numero') + 1;

                $pedido = Pedido::create([
                    ...$cabecera,
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'usuario_id' => $usuario->id,
                    'numero' => $numero,
                    'fecha_pedido' => now()->toDateString(),
                    'estado' => 'pendiente',
                ]);
            }

            foreach ($lineas as $orden => $linea) {
                $producto = $linea['producto'];
                $presentacion = $linea['presentacion'];

                $pedido->detalles()->create([
                    'empresa_id' => $empresaId,
                    'producto_id' => $producto->id,
                    'presentacion_id' => $presentacion->id,
                    'orden' => $orden,
                    'descripcion' => $presentacion->descripcionConProducto($producto->nombre),
                    'unidad_codigo' => trim((string) $presentacion->unidad_codigo) ?: $producto->unidad_base_codigo,
                    'tipo_afectacion_codigo' => $linea['afectacion'],
                    'cantidad' => $linea['cantidad'],
                    'valor_unitario' => $linea['valor_unitario'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'descuento' => $linea['descuento'],
                    'igv' => $linea['igv'],
                    'total' => $linea['total'],
                ]);
            }

            return $pedido;
        });
    }

    /**
     * Recibe un adelanto: entra como ingreso a la caja abierta del usuario (sin comprobante) y queda
     * enlazado al pedido. $datos ya validados: monto, medio_pago_codigo, referencia?
     *
     * @throws ErrorDeNegocio
     */
    public function registrarAdelanto(Pedido $pedido, Usuario $usuario, array $datos): PedidoAdelanto
    {
        if (! $pedido->estaAbierto()) {
            throw new ErrorDeNegocio($pedido->estado === 'entregado'
                ? 'Este pedido ya se entregó: no se reciben más adelantos.'
                : 'Este pedido está anulado.');
        }

        $apertura = $this->aperturaParaElPedido($pedido, $usuario, 'recibir el adelanto');

        return DB::transaction(function () use ($pedido, $usuario, $datos, $apertura) {
            // dos cajeros recibiendo adelantos a la vez no pueden pasar el saldo, ni colarse en un pedido recién anulado
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if (! $pedido->estaAbierto()) {
                throw new ErrorDeNegocio('Este pedido ya no está abierto: no se reciben adelantos.');
            }

            $monto = round((float) $datos['monto'], 2);
            $saldo = $pedido->saldo();
            if ($monto > $saldo + 0.005) {
                throw new ErrorDeNegocio(sprintf('El adelanto (S/ %s) supera el saldo del pedido (S/ %s).', number_format($monto, 2), number_format($saldo, 2)));
            }

            $movimiento = MovimientoCaja::create([
                'empresa_id' => $pedido->empresa_id,
                'apertura_id' => $apertura->id,
                'usuario_id' => $usuario->id,
                'tipo' => 'ingreso',
                'concepto' => Str::limit("Adelanto pedido {$pedido->codigo()} · {$pedido->cliente_nombre}", 200, ''),
                'monto' => $monto,
                'medio_pago_codigo' => $datos['medio_pago_codigo'],
                'referencia' => $datos['referencia'] ?? null,
            ]);

            $adelanto = PedidoAdelanto::create([
                'empresa_id' => $pedido->empresa_id,
                'pedido_id' => $pedido->id,
                'movimiento_caja_id' => $movimiento->id,
                'apertura_id' => $apertura->id,
                'usuario_id' => $usuario->id,
                'medio_pago_codigo' => $datos['medio_pago_codigo'],
                'monto' => $monto,
                'referencia' => $datos['referencia'] ?? null,
            ]);

            $pedido->touch();

            Auditoria::registrar($usuario, 'pedido.adelanto', 'pedido', $pedido->id, [
                'pedido' => $pedido->codigo(),
                'cliente' => $pedido->cliente_nombre,
                'monto' => $monto,
                'medio' => $datos['medio_pago_codigo'],
                'caja' => $apertura->caja?->nombre,
            ]);

            return $adelanto;
        });
    }

    /** @throws ErrorDeNegocio */
    public function marcarListo(Pedido $pedido, Usuario $usuario): void
    {
        if ($pedido->estado !== 'pendiente') {
            throw new ErrorDeNegocio(match ($pedido->estado) {
                'listo' => 'Este pedido ya está marcado como listo.',
                'entregado' => 'Este pedido ya se entregó.',
                default => 'Este pedido está anulado.',
            });
        }

        $pedido->update(['estado' => 'listo', 'listo_en' => now(), 'listo_por' => $usuario->id]);
    }

    /**
     * Anula un pedido abierto. Si tiene adelantos y $devolver, el total devuelto sale como egreso
     * de la caja abierta del usuario; si no, queda registrado como no devuelto.
     *
     * @throws ErrorDeNegocio
     */
    public function anular(Pedido $pedido, Usuario $usuario, string $motivo, bool $devolver, ?string $medioDevolucion = null): void
    {
        if (! $pedido->estaAbierto()) {
            throw new ErrorDeNegocio($pedido->estado === 'entregado'
                ? 'Este pedido ya se entregó: si hubo un error, anula el comprobante de la venta.'
                : 'El pedido ya está anulado.');
        }

        $apertura = $devolver && $pedido->totalAdelantos() > 0 ? $this->aperturaParaElPedido($pedido, $usuario, 'devolver el adelanto') : null;

        DB::transaction(function () use ($pedido, $usuario, $motivo, $devolver, $medioDevolucion, $apertura) {
            // con la fila bloqueada: dos anulaciones a la vez no devuelven dos veces el mismo adelanto
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if (! $pedido->estaAbierto()) {
                throw new ErrorDeNegocio('El pedido ya está anulado.');
            }

            $adelantos = $pedido->totalAdelantos();
            $devolver = $devolver && $adelantos > 0;
            if ($devolver && ! $apertura) {
                $apertura = $this->aperturaParaElPedido($pedido, $usuario, 'devolver el adelanto');
            }
            $movimiento = null;

            if ($devolver) {
                $movimiento = MovimientoCaja::create([
                    'empresa_id' => $pedido->empresa_id,
                    'apertura_id' => $apertura->id,
                    'usuario_id' => $usuario->id,
                    'tipo' => 'egreso',
                    'concepto' => Str::limit("Devolución adelanto {$pedido->codigo()} · {$pedido->cliente_nombre}", 200, ''),
                    'monto' => $adelantos,
                    'medio_pago_codigo' => $medioDevolucion ?: $this->medioDeLosAdelantos($pedido),
                ]);
            }

            $pedido->update([
                'estado' => 'anulado',
                'anulado_en' => now(),
                'anulado_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
                'devolucion_monto' => $devolver ? $adelantos : null,
                'devolucion_movimiento_id' => $movimiento?->id,
            ]);

            Auditoria::registrar($usuario, 'pedido.anulado', 'pedido', $pedido->id, [
                'pedido' => $pedido->codigo(),
                'cliente' => $pedido->cliente_nombre,
                'motivo' => $motivo,
                'adelantos' => $adelantos,
                'devuelto' => $devolver ? $adelantos : 0,
                'caja' => $apertura?->caja?->nombre,
            ]);
        });
    }

    /** Pedido que el POS puede cargar en el carrito: de la empresa y aún abierto. */
    public function paraVender(string $empresaId, ?string $id): ?Pedido
    {
        if (! $id || ! Str::isUuid($id)) {
            return null;
        }

        return Pedido::query()
            ->where('empresa_id', $empresaId)
            ->abiertos()
            ->with(['detalles', 'cliente', 'adelantos'])
            ->find($id);
    }

    /** Precio fijado al pedir, por presentación: se respeta al entregar aunque la lista haya cambiado. */
    public function preciosFijados(?Pedido $pedido): array
    {
        if (! $pedido) {
            return [];
        }

        return $pedido->detalles
            ->mapWithKeys(fn ($d) => [$d->presentacion_id => (float) $d->precio_unitario])
            ->all();
    }

    /**
     * Reglas para entregar desde el POS: la caja debe ser de la sucursal del pedido y un pedido
     * con adelanto se cobra al contado (el saldo se paga al recibirlo).
     *
     * @throws ErrorDeNegocio
     */
    public function validarEntrega(Pedido $pedido, AperturaCaja $apertura, bool $esCredito): void
    {
        $this->exigirSucursal($pedido, $apertura, 'entregar');

        if ($esCredito && $pedido->totalAdelantos() > 0) {
            throw new ErrorDeNegocio('Un pedido con adelanto se entrega al contado: el saldo se cobra ahora.');
        }
    }

    /** Deja el pedido entregado y enlazado a la venta que lo atendió. */
    public function marcarEntregado(Pedido $pedido, Comprobante $comprobante): void
    {
        Pedido::whereKey($pedido->id)
            ->abiertos()
            ->update(['estado' => 'entregado', 'comprobante_id' => $comprobante->id, 'entregado_en' => now(), 'actualizado_en' => now()]);
    }

    /**
     * "Orden de pedido" para la térmica: con $html devuelve la vista que se imprime sola desde el navegador
     * (impresión directa); si no, el PDF al ancho de la caja.
     */
    public function ticket(Pedido $pedido, Empresa $empresa, int $ancho = 80, bool $html = false)
    {
        $pedido->loadMissing(['detalles', 'adelantos.medioPago:codigo,nombre', 'usuario:id,nombre_completo', 'sucursal:id,nombre,direccion']);

        $datos = [
            'pedido' => $pedido,
            'empresa' => $empresa,
            'logo' => $empresa->logoParaPdf(),
            'ancho' => $ancho,
            'adelantos' => $pedido->totalAdelantos(),
            'saldo' => $pedido->saldo(),
        ];

        if ($html) {
            return view('pdf.pedido-ticket', [...$datos, 'imprimirDirecto' => true]);
        }

        // alto del papel según el contenido (la térmica corta al final; 58mm ocupa más líneas por fila)
        $alto = (int) ceil(
            ($ancho === 58 ? 60 : 66)
            + $pedido->detalles->count() * ($ancho === 58 ? 6.5 : 5)
            + $pedido->adelantos->count() * 4
            + (filled($pedido->observaciones) ? 6 + ceil(mb_strlen($pedido->observaciones) / ($ancho === 58 ? 36 : 50)) * 4 : 0)
            + ($datos['logo'] ? ($ancho === 58 ? 26 : 32) : 0)
        );
        $alto = max($alto, $ancho + 5);

        return SnappyPdf::loadView('pdf.pedido-ticket', $datos)
            ->setOption('page-width', "{$ancho}mm")
            ->setOption('page-height', "{$alto}mm")
            ->setOption('margin-top', '3')
            ->setOption('margin-bottom', '3')
            ->setOption('margin-left', '4')
            ->setOption('margin-right', '4')
            ->setOption('encoding', 'utf-8')
            ->inline("{$pedido->codigo()}.pdf");
    }

    /**
     * Caja abierta del usuario, en la sucursal del pedido (el dinero de un encargo entra y sale de su sede).
     *
     * @throws ErrorDeNegocio
     */
    private function aperturaParaElPedido(Pedido $pedido, Usuario $usuario, string $accion): AperturaCaja
    {
        $apertura = $this->caja->aperturaDe($usuario);
        if (! $apertura) {
            throw new ErrorDeNegocio("Necesitas abrir caja para {$accion}.");
        }

        $this->exigirSucursal($pedido, $apertura, $accion);

        return $apertura;
    }

    /** @throws ErrorDeNegocio */
    private function exigirSucursal(Pedido $pedido, AperturaCaja $apertura, string $accion): void
    {
        if ($apertura->caja?->sucursal_id !== $pedido->sucursal_id) {
            $nombre = Sucursal::whereKey($pedido->sucursal_id)->value('nombre') ?? 'otra sucursal';
            throw new ErrorDeNegocio("Este pedido es de la sucursal {$nombre}: para {$accion} usa una caja de esa sucursal.");
        }
    }

    /** Medio con el que se devuelve por defecto: el del único adelanto; con varios, efectivo. */
    private function medioDeLosAdelantos(Pedido $pedido): string
    {
        $medios = $pedido->adelantos()->reorder()->distinct()->pluck('medio_pago_codigo');

        return $medios->count() === 1 ? (string) $medios->first() : 'efectivo';
    }
}
