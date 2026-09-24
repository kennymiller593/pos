<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\MovimientoCaja;
use App\Models\SerieCorrelativo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class NotaCreditoService
{
    /** Motivos soportados del catálogo 09 de SUNAT. */
    public const MOTIVOS = [
        '01' => 'Anulación de la operación',
        '06' => 'Devolución total',
        '07' => 'Devolución por ítem',
    ];

    public function __construct(
        private readonly InventarioService $inventario,
        private readonly CajaService $caja,
    ) {}

    /**
     * Emite una nota de crédito sobre una boleta o factura aceptada por SUNAT.
     * Repone el stock de lo devuelto, reduce la deuda si la venta fue al crédito
     * y registra la salida de caja por lo que se devuelve en efectivo.
     *
     * $datos ya validados: motivo (01|06|07), items[{detalle_id, cantidad}] solo para 07.
     *
     * @throws ErrorDeNegocio
     */
    public function emitir(Comprobante $original, Usuario $usuario, array $datos): Comprobante
    {
        if (! in_array($original->tipo_comprobante_codigo, ['01', '03'], true)) {
            throw new ErrorDeNegocio('Las notas de crédito solo aplican a boletas o facturas.');
        }

        if ($original->estado !== 'emitido') {
            throw new ErrorDeNegocio('No se puede emitir una nota de crédito sobre un comprobante anulado.');
        }

        if (! in_array($original->sunat?->estado, ['aceptado', 'observado'], true)) {
            throw new ErrorDeNegocio('La nota de crédito solo procede cuando SUNAT ya aceptó el comprobante. Si aún no fue aceptado, usa la anulación.');
        }

        $motivo = $datos['motivo'];
        $original->load(['detalles.producto', 'detalles.presentacion', 'cuentaPorCobrar']);

        $lineas = $motivo === '07'
            ? $this->lineasParciales($original, $datos['items'] ?? [])
            : $this->lineasTotales($original);

        $totales = $this->totalizar($lineas);
        $montoNota = $totales['total'];

        // lo acreditado (esta nota + las anteriores) no puede superar el total original
        $yaAcreditado = (float) Comprobante::query()
            ->where('comprobante_ref_id', $original->id)
            ->where('estado', 'emitido')
            ->sum('total');

        if ($yaAcreditado + $montoNota > (float) $original->total + 0.01) {
            $disponible = number_format((float) $original->total - $yaAcreditado, 2);

            throw new ErrorDeNegocio("Con esta nota se acreditaría más que el comprobante original (disponible: S/ {$disponible}).");
        }

        // el dinero: primero se descuenta de la deuda pendiente; lo demas sale de caja
        $cuenta = $original->cuentaPorCobrar;
        $saldoDeuda = $cuenta && $cuenta->estado !== 'pagado'
            ? round((float) $cuenta->monto_total - (float) $cuenta->monto_pagado, 2)
            : 0.0;
        $reduccionDeuda = round(min($montoNota, max(0, $saldoDeuda)), 2);
        $aDevolver = round($montoNota - $reduccionDeuda, 2);

        // el dinero vuelve por el medio elegido (por defecto, el de la venta);
        // solo el efectivo sale del cajon y por eso solo el efectivo se comprueba
        $medio = $datos['medio_pago_codigo'] ?? $this->medioDeLaVenta($original);
        $referencia = $datos['referencia'] ?? null;
        $apertura = null;

        if ($aDevolver > 0) {
            $apertura = $this->caja->aperturaDe($usuario);

            if (! $apertura) {
                $monto = number_format($aDevolver, 2);

                throw new ErrorDeNegocio("Para devolver S/ {$monto} al cliente necesitas tener una caja abierta.");
            }

            if ($medio === 'efectivo') {
                $disponible = $this->caja->resumen($apertura)['esperado'];

                if ($aDevolver > $disponible + 0.001) {
                    throw new ErrorDeNegocio(sprintf(
                        'No hay suficiente efectivo en caja para devolver S/ %s (disponible: S/ %s). Devuelve por otro medio o registra un ingreso.',
                        number_format($aDevolver, 2),
                        number_format($disponible, 2),
                    ));
                }
            }
        }

        return DB::transaction(function () use ($original, $usuario, $motivo, $lineas, $totales, $montoNota, $cuenta, $reduccionDeuda, $aDevolver, $apertura, $medio, $referencia) {
            // con el original bloqueado se recalcula el tope: dos notas simultaneas no pueden acreditar de mas
            $bloqueado = Comprobante::lockForUpdate()->find($original->id);
            $acreditadoAhora = (float) Comprobante::query()
                ->where('comprobante_ref_id', $original->id)
                ->where('estado', 'emitido')
                ->sum('total');

            if (! $bloqueado || $bloqueado->estado !== 'emitido' || $acreditadoAhora + $montoNota > (float) $original->total + 0.01) {
                throw new ErrorDeNegocio('El comprobante cambió mientras emitías la nota (otra nota o una anulación se adelantó). Revisa y vuelve a intentarlo.');
            }

            $serie = $this->tomarSerie($original);

            $nota = Comprobante::create([
                'empresa_id' => $original->empresa_id,
                'sucursal_id' => $original->sucursal_id,
                'caja_id' => $apertura?->caja_id,
                'apertura_id' => $apertura?->id,
                'cliente_id' => $original->cliente_id,
                'usuario_id' => $usuario->id,
                'tipo_comprobante_codigo' => '07',
                'serie' => $serie->serie,
                'correlativo' => $serie->correlativo,
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'moneda' => 'PEN',
                'tipo_cambio' => 1,
                'cliente_tipo_doc' => $original->cliente_tipo_doc,
                'cliente_numero_doc' => $original->cliente_numero_doc,
                'cliente_nombre' => $original->cliente_nombre,
                'cliente_direccion' => $original->cliente_direccion,
                'total_gravado' => $totales['gravado'],
                'total_exonerado' => $totales['exonerado'],
                'total_inafecto' => $totales['inafecto'],
                'total_igv' => $totales['igv'],
                'total_descuentos' => $totales['descuentos'],
                'total' => $montoNota,
                'comprobante_ref_id' => $original->id,
                'motivo_nota' => $motivo,
                'es_credito' => false,
                'estado' => 'emitido',
                'origen' => 'online',
            ]);

            foreach ($lineas as $linea) {
                $this->registrarDetalleYReponerStock($nota, $linea, $usuario);
            }

            if ($reduccionDeuda > 0) {
                $cuenta->monto_total = round((float) $cuenta->monto_total - $reduccionDeuda, 2);

                if ((float) $cuenta->monto_pagado >= (float) $cuenta->monto_total - 0.001) {
                    $cuenta->estado = 'pagado';
                }

                $cuenta->save();
            }

            $numeroNota = "{$nota->serie}-{$nota->correlativo}";
            $numeroOriginal = "{$original->serie}-{$original->correlativo}";

            if ($aDevolver > 0) {
                MovimientoCaja::create([
                    'empresa_id' => $original->empresa_id,
                    'apertura_id' => $apertura->id,
                    'usuario_id' => $usuario->id,
                    'tipo' => 'egreso',
                    'concepto' => "Devolución por nota de crédito {$numeroNota} ({$numeroOriginal})",
                    'monto' => $aDevolver,
                    'medio_pago_codigo' => $medio,
                    'referencia' => $referencia,
                ]);
            }

            Auditoria::registrar($usuario, 'nota_credito.emitida', 'comprobante', $nota->id, [
                'nota' => $numeroNota,
                'modifica' => $numeroOriginal,
                'motivo' => self::MOTIVOS[$motivo] ?? $motivo,
                'total' => $montoNota,
                'devuelto' => $aDevolver,
                'medio' => $aDevolver > 0 ? $medio : null,
                'reduccion_deuda' => $reduccionDeuda,
            ]);

            return $nota;
        });
    }

    // ---------------------------------------------------------------

    /** Medio con el que se cobro la venta (el de mayor importe si hubo varios); efectivo si fue al credito. */
    private function medioDeLaVenta(Comprobante $original): string
    {
        return (string) ($original->pagos()
            ->selectRaw('medio_pago_codigo, SUM(monto) as total')
            ->groupBy('medio_pago_codigo')
            ->orderByDesc('total')
            ->value('medio_pago_codigo') ?? 'efectivo');
    }

    /** Nota total: replica todas las líneas del comprobante original. */
    private function lineasTotales(Comprobante $original): array
    {
        return $original->detalles
            ->map(fn (ComprobanteDetalle $d) => $this->lineaDesdeDetalle($d, (float) $d->cantidad))
            ->all();
    }

    /** Nota parcial: solo los ítems elegidos, sin exceder lo aún no acreditado. */
    private function lineasParciales(Comprobante $original, array $items): array
    {
        if ($items === []) {
            throw new ErrorDeNegocio('Elige al menos un producto a devolver.');
        }

        $acreditadas = $this->cantidadesYaAcreditadas($original);

        return collect($items)->map(function (array $item) use ($original, $acreditadas) {
            $detalle = $original->detalles->firstWhere('id', $item['detalle_id']);

            if (! $detalle) {
                throw new ErrorDeNegocio('Uno de los ítems no pertenece al comprobante.');
            }

            $cantidad = (float) $item['cantidad'];
            $clave = $detalle->producto_id.'|'.$detalle->presentacion_id;
            $disponible = round((float) $detalle->cantidad - ($acreditadas[$clave] ?? 0), 3);

            if ($cantidad <= 0 || $cantidad > $disponible + 0.001) {
                throw new ErrorDeNegocio("De \"{$detalle->descripcion}\" solo queda por acreditar {$disponible}.");
            }

            if (! $detalle->producto?->permite_fraccion && fmod($cantidad, 1) != 0) {
                throw new ErrorDeNegocio("\"{$detalle->descripcion}\" no permite cantidades fraccionadas.");
            }

            return $this->lineaDesdeDetalle($detalle, $cantidad);
        })->all();
    }

    /** Cantidades por producto/presentación ya incluidas en notas anteriores. */
    private function cantidadesYaAcreditadas(Comprobante $original): array
    {
        return ComprobanteDetalle::query()
            ->whereIn('comprobante_id', Comprobante::query()
                ->where('comprobante_ref_id', $original->id)
                ->where('estado', 'emitido')
                ->select('id'))
            ->get()
            ->groupBy(fn ($d) => $d->producto_id.'|'.$d->presentacion_id)
            ->map(fn ($grupo) => (float) $grupo->sum('cantidad'))
            ->all();
    }

    /** Importes proporcionales a la cantidad acreditada (incluye IGV y descuento). */
    private function lineaDesdeDetalle(ComprobanteDetalle $detalle, float $cantidad): array
    {
        $proporcion = (float) $detalle->cantidad > 0 ? $cantidad / (float) $detalle->cantidad : 0;

        return [
            'detalle' => $detalle,
            'cantidad' => $cantidad,
            'total' => round((float) $detalle->total * $proporcion, 2),
            'igv' => round((float) $detalle->igv * $proporcion, 2),
            'descuento' => round((float) $detalle->descuento * $proporcion, 2),
        ];
    }

    private function totalizar(array $lineas): array
    {
        $totales = ['gravado' => 0.0, 'exonerado' => 0.0, 'inafecto' => 0.0, 'igv' => 0.0, 'descuentos' => 0.0, 'total' => 0.0];

        foreach ($lineas as $linea) {
            $codigo = trim((string) $linea['detalle']->tipo_afectacion_codigo);

            if ($codigo === '10') {
                $totales['gravado'] += round($linea['total'] - $linea['igv'], 2);
            } else {
                $totales[$codigo === '20' ? 'exonerado' : 'inafecto'] += $linea['total'];
            }

            $totales['igv'] += $linea['igv'];
            $totales['descuentos'] += $linea['descuento'];
            $totales['total'] += $linea['total'];
        }

        return array_map(fn ($v) => round($v, 2), $totales);
    }

    private function registrarDetalleYReponerStock(Comprobante $nota, array $linea, Usuario $usuario): void
    {
        /** @var ComprobanteDetalle $detalle */
        $detalle = $linea['detalle'];
        $cantidad = $linea['cantidad'];

        $nota->detalles()->create([
            'empresa_id' => $nota->empresa_id,
            'producto_id' => $detalle->producto_id,
            'presentacion_id' => $detalle->presentacion_id,
            'lote_id' => $detalle->lote_id,
            'descripcion' => $detalle->descripcion,
            'unidad_codigo' => $detalle->unidad_codigo,
            'tipo_afectacion_codigo' => $detalle->tipo_afectacion_codigo,
            'cantidad' => $cantidad,
            'valor_unitario' => $detalle->valor_unitario,
            'precio_unitario' => $detalle->precio_unitario,
            'costo_unitario' => $detalle->costo_unitario,
            'descuento' => $linea['descuento'],
            'igv' => $linea['igv'],
            'total' => $linea['total'],
        ]);

        $producto = $detalle->producto;

        if (! $producto || ! $producto->controla_stock) {
            return;
        }

        $factor = (float) ($detalle->presentacion?->factor_conversion ?? 1);
        $cantidadBase = round($cantidad * $factor, 3);
        $costoBase = $factor > 0 ? round((float) $detalle->costo_unitario / $factor, 6) : 0;

        $this->inventario->incrementarStock($nota->empresa_id, $producto->id, $nota->sucursal_id, $cantidadBase);

        // lo devuelto reingresa como una capa nueva al costo con el que salio,
        // para que el FIFO siga cuadrando
        CapaCosto::create([
            'empresa_id' => $nota->empresa_id,
            'producto_id' => $producto->id,
            'sucursal_id' => $nota->sucursal_id,
            'cantidad_inicial' => $cantidadBase,
            'cantidad_restante' => $cantidadBase,
            'costo_unitario' => $costoBase,
            'fecha_ingreso' => now(),
        ]);

        $this->inventario->registrarMovimiento(
            $nota->empresa_id,
            $nota->sucursal_id,
            $producto->id,
            'devolucion',
            $cantidadBase,
            $costoBase,
            $nota->id,
            $usuario->id,
        );
    }

    /**
     * Serie propia de la nota según el documento que modifica: FC01 para
     * facturas, BC01 para boletas (SUNAT exige que empiece con F o B).
     */
    private function tomarSerie(Comprobante $original): SerieCorrelativo
    {
        $prefijo = $original->tipo_comprobante_codigo === '01' ? 'FC' : 'BC';

        $serie = SerieCorrelativo::query()
            ->where('empresa_id', $original->empresa_id)
            ->where('sucursal_id', $original->sucursal_id)
            ->where('tipo_comprobante_codigo', '07')
            ->where('serie', 'like', "{$prefijo}%")
            ->lockForUpdate()
            ->first();

        if (! $serie) {
            // la serie es unica por empresa: cada sucursal toma la siguiente libre
            $mayorUsada = SerieCorrelativo::query()
                ->where('empresa_id', $original->empresa_id)
                ->where('tipo_comprobante_codigo', '07')
                ->where('serie', 'like', "{$prefijo}%")
                ->lockForUpdate()
                ->pluck('serie')
                ->map(fn (string $s) => (int) substr($s, 2))
                ->max() ?? 0;

            $serie = SerieCorrelativo::create([
                'empresa_id' => $original->empresa_id,
                'sucursal_id' => $original->sucursal_id,
                'tipo_comprobante_codigo' => '07',
                'serie' => $prefijo.str_pad((string) ($mayorUsada + 1), 2, '0', STR_PAD_LEFT),
                'correlativo' => 0,
            ]);
        }

        $serie->increment('correlativo');

        return $serie;
    }
}
