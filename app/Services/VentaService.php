<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\AperturaCaja;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\CuentaPorCobrar;
use App\Models\DetalleConsumoCapa;
use App\Models\Pago;
use App\Models\ProductoPresentacion;
use App\Models\SerieCorrelativo;
use App\Models\TipoAfectacionIgv;
use App\Models\Usuario;
use App\Support\DocumentoIdentidad;
use Illuminate\Support\Facades\DB;

class VentaService
{
    private const IGV = 0.18;

    private const SERIES_POR_DEFECTO = ['00' => 'NV01', '03' => 'B001', '01' => 'F001'];

    /** Dias calendario, contados desde la emision, en que SUNAT admite el envio (facturas 3, boletas 7). */
    private const PLAZO_ENVIO_DIAS = ['01' => 3, '03' => 7];

    /** Desde este importe la boleta debe identificar al comprador (Reglamento de Comprobantes de Pago). */
    private const BOLETA_EXIGE_DOCUMENTO_DESDE = 700.0;

    public function __construct(
        private readonly InventarioService $inventario,
        private readonly SunatService $sunat,
    ) {}

    /**
     * Registra una venta completa: comprobante, detalles, FIFO, stock, kardex y pagos.
     *
     * $datos ya validados: tipo_comprobante_codigo, cliente_id?, es_credito,
     * items[{presentacion_id, cantidad}], pagos[{medio_pago_codigo, monto, referencia?}]
     *
     * @throws ErrorDeNegocio
     */
    public function registrar(Usuario $usuario, AperturaCaja $apertura, array $datos): Comprobante
    {
        $empresaId = $usuario->empresa_id;
        $sucursalId = $apertura->caja->sucursal_id;
        $esCredito = (bool) $datos['es_credito'];

        $cliente = $this->resolverCliente($empresaId, $datos['cliente_id'] ?? null, $esCredito);

        [$lineas, $totales] = $this->calcularLineas($empresaId, $datos['items']);
        $totalVenta = round($totales['total'], 2);

        $this->validarClienteParaTipo($datos['tipo_comprobante_codigo'], $cliente, $totalVenta);

        if ($esCredito) {
            $this->validarLineaDeCredito($cliente, $totalVenta);
            $datos['pagos'] = [];
        } else {
            $this->validarPagos($datos['pagos'] ?? [], $totalVenta);
        }

        $this->validarStock($lineas, $sucursalId);

        return DB::transaction(function () use ($datos, $lineas, $totales, $totalVenta, $apertura, $usuario, $empresaId, $sucursalId, $cliente, $esCredito) {
            $serie = $this->tomarCorrelativo($empresaId, $sucursalId, $apertura->caja_id, $datos['tipo_comprobante_codigo']);

            $comprobante = Comprobante::create([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'caja_id' => $apertura->caja_id,
                'apertura_id' => $apertura->id,
                'cliente_id' => $cliente?->id,
                'usuario_id' => $usuario->id,
                'tipo_comprobante_codigo' => $datos['tipo_comprobante_codigo'],
                'serie' => $serie->serie,
                'correlativo' => $serie->correlativo,
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'moneda' => 'PEN',
                'tipo_cambio' => 1,
                'cliente_tipo_doc' => $cliente?->tipo_documento_codigo,
                'cliente_numero_doc' => $cliente?->numero_documento,
                'cliente_nombre' => $cliente?->nombre,
                'cliente_direccion' => $cliente?->direccion,
                'total_gravado' => round($totales['gravado'], 2),
                'total_exonerado' => round($totales['exonerado'], 2),
                'total_inafecto' => round($totales['inafecto'], 2),
                'total_igv' => round($totales['igv'], 2),
                'total_descuentos' => round($totales['descuentos'], 2),
                'total' => $totalVenta,
                'es_credito' => $esCredito,
                'estado' => 'emitido',
                'origen' => 'online',
            ]);

            if ($esCredito) {
                CuentaPorCobrar::create([
                    'empresa_id' => $empresaId,
                    'comprobante_id' => $comprobante->id,
                    'cliente_id' => $cliente->id,
                    'monto_total' => $totalVenta,
                    'monto_pagado' => 0,
                    'estado' => 'pendiente',
                ]);
            }

            foreach ($lineas as $linea) {
                $this->registrarDetalle($comprobante, $linea, $sucursalId, $usuario);
            }

            // constancia de las lineas vendidas con precio distinto al de lista
            $editadas = collect($lineas)
                ->filter(fn ($l) => abs($l['precio_unitario'] - $l['precio_lista']) >= 0.005)
                ->map(fn ($l) => [
                    'producto' => $l['producto']->nombre,
                    'precio_lista' => $l['precio_lista'],
                    'precio_cobrado' => $l['precio_unitario'],
                ])
                ->values();

            if ($editadas->isNotEmpty()) {
                Auditoria::registrar($usuario, 'venta.precio_modificado', 'comprobante', $comprobante->id, [
                    'comprobante' => "{$comprobante->serie}-{$comprobante->correlativo}",
                    'lineas' => $editadas->all(),
                ]);
            }

            foreach ($datos['pagos'] as $pago) {
                Pago::create([
                    'empresa_id' => $empresaId,
                    'comprobante_id' => $comprobante->id,
                    'apertura_id' => $apertura->id,
                    'usuario_id' => $usuario->id,
                    'medio_pago_codigo' => $pago['medio_pago_codigo'],
                    'monto' => round((float) $pago['monto'], 2),
                    'referencia' => $pago['referencia'] ?? null,
                ]);
            }

            return $comprobante;
        });
    }

    /**
     * Anula un comprobante: repone stock y capas, retira pagos y elimina la deuda si la hubiera.
     *
     * Devuelve 'anulado' si la anulación se completó, o 'baja_pendiente' si SUNAT
     * recibió la baja pero aún la procesa: en ese caso el comprobante sigue
     * emitido y la anulación interna se completa al confirmarla.
     *
     * @throws ErrorDeNegocio
     */
    public function anular(Comprobante $comprobante, Usuario $usuario, string $motivo): string
    {
        $this->validarAnulable($comprobante);

        // un comprobante electronico ya aceptado exige comunicar la baja a SUNAT
        // antes de anularlo internamente (si SUNAT no la recibe o la rechaza, se aborta)
        if (in_array($comprobante->tipo_comprobante_codigo, ['01', '03'], true)) {
            $registro = $this->sunat->solicitarBaja($comprobante, $motivo, $usuario->id);

            if ($registro->estado === 'baja_pendiente') {
                return 'baja_pendiente';
            }
        }

        $this->completarAnulacion($comprobante, $usuario, $motivo);

        return 'anulado';
    }

    /**
     * Vuelve a consultar una baja en proceso y, si SUNAT la confirmó,
     * completa la anulación interna con el motivo y el usuario que la pidieron.
     */
    public function confirmarBajaPendiente(Comprobante $comprobante): ComprobanteSunat
    {
        $registro = $this->sunat->consultarBaja($comprobante);

        if ($registro->estado === 'baja' && $comprobante->fresh()->estado === 'emitido') {
            $baja = (array) ($comprobante->sunat_respuesta['baja'] ?? []);
            $usuario = Usuario::find($baja['usuario_id'] ?? null) ?? $comprobante->usuario;

            $this->completarAnulacion($comprobante, $usuario, (string) ($baja['motivo'] ?? 'Baja confirmada por SUNAT'));
        }

        return $registro;
    }

    /**
     * Convierte una nota de venta ya cobrada en boleta o factura electrónica:
     * el mismo comprobante toma el tipo, la serie y el correlativo nuevos y
     * conserva detalles, pagos, stock y caja. Solo dentro del plazo en que
     * SUNAT admite el envío contado desde la fecha de la venta.
     *
     * @throws ErrorDeNegocio
     */
    public function convertir(Comprobante $comprobante, Usuario $usuario, string $tipo, ?string $clienteId): Comprobante
    {
        if (! isset(self::PLAZO_ENVIO_DIAS[$tipo])) {
            throw new ErrorDeNegocio('Solo se puede convertir en boleta o factura.');
        }

        if ($comprobante->tipo_comprobante_codigo !== '00') {
            throw new ErrorDeNegocio('Solo una nota de venta se puede convertir en comprobante electrónico.');
        }

        if ($comprobante->estado !== 'emitido') {
            throw new ErrorDeNegocio('Una nota de venta anulada no se puede convertir.');
        }

        if (! $comprobante->empresa->facturacion_electronica) {
            throw new ErrorDeNegocio('Activa la facturación electrónica en Empresa para emitir boletas y facturas.');
        }

        $dias = $comprobante->fecha_emision->startOfDay()->diffInDays(now()->startOfDay());
        if ($dias > self::PLAZO_ENVIO_DIAS[$tipo]) {
            throw new ErrorDeNegocio(sprintf(
                'Ya pasaron %d días desde la venta: SUNAT solo admite %s hasta %d días después. Registra una venta nueva.',
                $dias,
                $tipo === '01' ? 'facturas' : 'boletas',
                self::PLAZO_ENVIO_DIAS[$tipo],
            ));
        }

        $cliente = $clienteId
            ? $this->resolverCliente($comprobante->empresa_id, $clienteId, false)
            : $comprobante->cliente;

        $this->validarClienteParaTipo($tipo, $cliente, (float) $comprobante->total);

        return DB::transaction(function () use ($comprobante, $usuario, $tipo, $cliente) {
            $bloqueado = Comprobante::lockForUpdate()->find($comprobante->id);

            if (! $bloqueado || $bloqueado->tipo_comprobante_codigo !== '00' || $bloqueado->estado !== 'emitido') {
                throw new ErrorDeNegocio('Esta nota de venta ya fue convertida o anulada.');
            }

            $origen = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);
            $serie = $this->tomarCorrelativo($comprobante->empresa_id, $comprobante->sucursal_id, $comprobante->caja_id, $tipo);

            $comprobante->forceFill([
                'tipo_comprobante_codigo' => $tipo,
                'serie' => $serie->serie,
                'correlativo' => $serie->correlativo,
                'cliente_id' => $cliente?->id,
                'cliente_tipo_doc' => $cliente?->tipo_documento_codigo,
                'cliente_numero_doc' => $cliente?->numero_documento,
                'cliente_nombre' => $cliente?->nombre,
                'cliente_direccion' => $cliente?->direccion,
                'sunat_respuesta' => array_merge((array) $comprobante->sunat_respuesta, ['convertido_de' => $origen]),
            ])->save();

            Auditoria::registrar($usuario, 'comprobante.convertido', 'comprobante', $comprobante->id, [
                'de' => $origen,
                'a' => "{$serie->serie}-".str_pad($serie->correlativo, 6, '0', STR_PAD_LEFT),
                'tipo' => $tipo,
                'total' => (float) $comprobante->total,
            ]);

            return $comprobante;
        });
    }

    /**
     * Vuelve a enviar a SUNAT un comprobante rechazado con el mismo número:
     * SUNAT no registra los rechazados, así que basta corregir los datos del
     * cliente (se toman de nuevo de su ficha) y reenviar.
     *
     * @throws ErrorDeNegocio
     */
    public function reemitir(Comprobante $comprobante): ComprobanteSunat
    {
        if ($comprobante->estado !== 'emitido') {
            throw new ErrorDeNegocio('Un comprobante anulado no se reenvía.');
        }

        if ($comprobante->sunat?->estado !== 'rechazado') {
            throw new ErrorDeNegocio('Solo un comprobante rechazado por SUNAT se corrige y reenvía; los pendientes usan "Enviar".');
        }

        $cliente = $comprobante->cliente;

        if ($cliente) {
            $comprobante->forceFill([
                'cliente_tipo_doc' => $cliente->tipo_documento_codigo,
                'cliente_numero_doc' => $cliente->numero_documento,
                'cliente_nombre' => $cliente->nombre,
                'cliente_direccion' => $cliente->direccion,
            ]);
        }

        // el comprobante conserva el tipo original; si es una nota de credito se valida como su referencia
        $tipoParaValidar = $comprobante->tipo_comprobante_codigo === '07'
            ? (string) $comprobante->comprobanteRef?->tipo_comprobante_codigo
            : $comprobante->tipo_comprobante_codigo;

        $this->validarClienteParaTipo($tipoParaValidar, $cliente, (float) $comprobante->total);
        $comprobante->save();

        return $this->sunat->emitir($comprobante->fresh(['empresa', 'sucursal', 'detalles']));
    }

    /**
     * Reglas del Reglamento de Comprobantes de Pago sobre el adquirente:
     * la factura exige RUC válido; la boleta identifica al cliente desde
     * S/ 700; y cualquier documento informado debe tener el formato correcto.
     *
     * @throws ErrorDeNegocio
     */
    private function validarClienteParaTipo(string $tipo, ?Cliente $cliente, float $total): void
    {
        if ($tipo === '00') {
            return;
        }

        $tipoDoc = trim((string) $cliente?->tipo_documento_codigo);
        $numeroDoc = trim((string) $cliente?->numero_documento);
        $identificado = $cliente && $tipoDoc !== DocumentoIdentidad::SIN_DOCUMENTO && $numeroDoc !== '';

        if ($tipo === '01') {
            if (! $cliente || $tipoDoc !== DocumentoIdentidad::RUC) {
                throw new ErrorDeNegocio('La factura necesita un cliente con RUC. Selecciónalo o créalo desde el buscador de clientes.');
            }

            if (! DocumentoIdentidad::rucValido($numeroDoc)) {
                throw new ErrorDeNegocio("El RUC de \"{$cliente->nombre}\" ({$numeroDoc}) no es válido. Corrígelo en Clientes antes de facturar.");
            }

            return;
        }

        if ($total >= self::BOLETA_EXIGE_DOCUMENTO_DESDE && ! $identificado) {
            throw new ErrorDeNegocio(sprintf(
                'Las boletas desde S/ %s deben identificar al cliente con su DNI u otro documento.',
                number_format(self::BOLETA_EXIGE_DOCUMENTO_DESDE, 2),
            ));
        }

        if ($identificado && ! DocumentoIdentidad::esValido($tipoDoc, $numeroDoc)) {
            throw new ErrorDeNegocio(sprintf(
                'El %s de "%s" (%s) no es válido: %s Corrígelo en Clientes.',
                DocumentoIdentidad::nombre($tipoDoc),
                $cliente->nombre,
                $numeroDoc,
                DocumentoIdentidad::mensaje($tipoDoc),
            ));
        }
    }

    /** @throws ErrorDeNegocio */
    private function validarAnulable(Comprobante $comprobante): void
    {
        if ($comprobante->estado !== 'emitido') {
            throw new ErrorDeNegocio('Este comprobante ya está anulado.');
        }

        if ($comprobante->tipo_comprobante_codigo === '07') {
            throw new ErrorDeNegocio('Una nota de crédito no se puede anular.');
        }

        $cuenta = $comprobante->cuentaPorCobrar;
        if ($cuenta && (float) $cuenta->monto_pagado > 0) {
            throw new ErrorDeNegocio('No se puede anular: la venta al crédito ya tiene cobros registrados.');
        }

        // lo devuelto por una nota de credito ya repuso stock y dinero: anular encima lo duplicaria
        if ($comprobante->notas()->where('estado', 'emitido')->exists()) {
            throw new ErrorDeNegocio('No se puede anular: este comprobante ya tiene notas de crédito emitidas.');
        }
    }

    /** Parte interna de la anulación: stock, capas, pagos, deuda y estado. */
    private function completarAnulacion(Comprobante $comprobante, Usuario $usuario, string $motivo): void
    {
        DB::transaction(function () use ($comprobante, $usuario, $motivo) {
            // se vuelve a leer con bloqueo: dos anulaciones simultaneas no deben reponer el stock dos veces
            $bloqueado = Comprobante::lockForUpdate()->find($comprobante->id);

            if (! $bloqueado || $bloqueado->estado !== 'emitido') {
                throw new ErrorDeNegocio('Este comprobante ya está anulado.');
            }

            $comprobante->load(['detalles.presentacion', 'detalles.producto']);

            foreach ($comprobante->detalles as $detalle) {
                $producto = $detalle->producto;
                if (! $producto || ! $producto->controla_stock) {
                    continue;
                }

                $factor = (float) ($detalle->presentacion?->factor_conversion ?? 1);
                $cantidadBase = round((float) $detalle->cantidad * $factor, 3);

                $consumos = DetalleConsumoCapa::where('detalle_id', $detalle->id)->get();
                $this->inventario->revertirConsumos($consumos);
                DetalleConsumoCapa::where('detalle_id', $detalle->id)->delete();

                $this->inventario->incrementarStock($comprobante->empresa_id, $producto->id, $comprobante->sucursal_id, $cantidadBase);

                // la BD solo admite los tipos del CHECK; devolucion = reingreso por anulacion
                $this->inventario->registrarMovimiento(
                    $comprobante->empresa_id,
                    $comprobante->sucursal_id,
                    $producto->id,
                    'devolucion',
                    $cantidadBase,
                    referenciaId: $comprobante->id,
                    usuarioId: $usuario->id,
                );
            }

            // los pagos dejan de ser ingresos validos (el arqueo del turno abierto se ajusta solo)
            $comprobante->pagos()->delete();
            $comprobante->cuentaPorCobrar?->delete();

            $comprobante->update([
                'estado' => 'anulado',
                'anulado_en' => now(),
                'anulado_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            Auditoria::registrar($usuario, 'comprobante.anulado', 'comprobante', $comprobante->id, [
                'comprobante' => "{$comprobante->serie}-{$comprobante->correlativo}",
                'total' => (float) $comprobante->total,
                'motivo' => $motivo,
            ]);
        });
    }

    // ---------------------------------------------------------------

    private function resolverCliente(string $empresaId, ?string $clienteId, bool $esCredito): ?Cliente
    {
        if (! $clienteId) {
            if ($esCredito) {
                throw new ErrorDeNegocio('La venta al crédito necesita un cliente.');
            }

            return null;
        }

        $cliente = Cliente::where('empresa_id', $empresaId)->find($clienteId);

        if (! $cliente) {
            throw new ErrorDeNegocio('El cliente elegido no existe.');
        }

        return $cliente;
    }

    /**
     * Construye las lineas de venta con su calculo de IGV.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, float>}
     */
    private function calcularLineas(string $empresaId, array $items): array
    {
        $presentaciones = ProductoPresentacion::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', collect($items)->pluck('presentacion_id'))
            ->with('producto:id,nombre,controla_stock,permite_fraccion,unidad_base_codigo,tipo_afectacion_codigo')
            ->get()
            ->keyBy('id');

        $afectaciones = TipoAfectacionIgv::all()->keyBy('codigo');

        $lineas = [];
        $totales = ['gravado' => 0.0, 'exonerado' => 0.0, 'inafecto' => 0.0, 'igv' => 0.0, 'descuentos' => 0.0, 'total' => 0.0];

        foreach ($items as $item) {
            $presentacion = $presentaciones->get($item['presentacion_id']);
            if (! $presentacion) {
                throw new ErrorDeNegocio('Uno de los productos del carrito ya no existe.');
            }

            $producto = $presentacion->producto;
            $cantidad = (float) $item['cantidad'];

            if (! $producto->permite_fraccion && fmod($cantidad, 1) != 0) {
                throw new ErrorDeNegocio("\"{$producto->nombre}\" no permite cantidades fraccionadas.");
            }

            // el cajero puede fijar un precio manual; si no, aplica el de lista (o mayorista)
            $precioLista = $this->precioAplicable($presentacion, $cantidad);
            $precioManual = $item['precio_unitario'] ?? null;
            $precio = ($precioManual !== null && $precioManual !== '')
                ? round((float) $precioManual, 2)
                : $precioLista;

            if ($precio <= 0) {
                throw new ErrorDeNegocio("El precio de \"{$producto->nombre}\" debe ser mayor a 0.");
            }

            $bruto = round($precio * $cantidad, 2);
            $descuento = round((float) ($item['descuento'] ?? 0), 2);

            if ($descuento < 0 || ($descuento > 0 && $descuento >= $bruto)) {
                throw new ErrorDeNegocio("El descuento en \"{$producto->nombre}\" no puede igualar o superar el importe de la línea.");
            }

            // el IGV se calcula sobre el importe ya rebajado
            $totalLinea = round($bruto - $descuento, 2);
            $totales['descuentos'] += $descuento;
            $esGravado = (bool) ($afectaciones->get($producto->tipo_afectacion_codigo)?->afecto);

            if ($esGravado) {
                $base = round($totalLinea / (1 + self::IGV), 2);
                $igvLinea = round($totalLinea - $base, 2);
                $valorUnitario = round($precio / (1 + self::IGV), 6);
                $totales['gravado'] += $base;
                $totales['igv'] += $igvLinea;
            } else {
                $igvLinea = 0.0;
                $valorUnitario = $precio;
                $clave = $producto->tipo_afectacion_codigo === '20' ? 'exonerado' : 'inafecto';
                $totales[$clave] += $totalLinea;
            }

            $totales['total'] += $totalLinea;

            $lineas[] = [
                'presentacion' => $presentacion,
                'producto' => $producto,
                'cantidad' => $cantidad,
                'cantidad_base' => round($cantidad * (float) $presentacion->factor_conversion, 3),
                'precio_unitario' => $precio,
                'precio_lista' => $precioLista,
                'valor_unitario' => $valorUnitario,
                'descuento' => $descuento,
                'igv' => $igvLinea,
                'total' => $totalLinea,
            ];
        }

        return [$lineas, $totales];
    }

    /**
     * Al llegar a la cantidad minima mayorista, el precio mayorista
     * se aplica automaticamente sobre toda la linea.
     */
    private function precioAplicable(ProductoPresentacion $presentacion, float $cantidad): float
    {
        $umbral = (float) ($presentacion->cantidad_mayorista ?? 0);

        if ($umbral > 0 && $presentacion->precio_mayorista !== null && $cantidad >= $umbral) {
            return (float) $presentacion->precio_mayorista;
        }

        return (float) $presentacion->precio_venta;
    }

    private function validarLineaDeCredito(Cliente $cliente, float $totalVenta): void
    {
        $limite = (float) $cliente->limite_credito;

        if ($limite <= 0) {
            throw new ErrorDeNegocio("\"{$cliente->nombre}\" no tiene línea de crédito. Asígnale un límite en Clientes.");
        }

        $deuda = (float) CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->id)
            ->where('estado', '!=', 'pagado')
            ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
            ->value('saldo');

        if ($deuda + $totalVenta > $limite) {
            $disponible = number_format(max(0, $limite - $deuda), 2);

            throw new ErrorDeNegocio("La venta excede el crédito de \"{$cliente->nombre}\" (disponible: S/ {$disponible}).");
        }
    }

    private function validarPagos(array $pagos, float $totalVenta): void
    {
        $totalPagos = round(collect($pagos)->sum('monto'), 2);

        if (abs($totalPagos - $totalVenta) > 0.01) {
            throw new ErrorDeNegocio("Los pagos (S/ {$totalPagos}) no coinciden con el total (S/ {$totalVenta}).");
        }
    }

    private function validarStock(array $lineas, string $sucursalId): void
    {
        foreach ($lineas as $linea) {
            if (! $linea['producto']->controla_stock) {
                continue;
            }

            $disponible = $this->inventario->stockDisponible($linea['producto']->id, $sucursalId);
            $requerido = collect($lineas)
                ->filter(fn ($l) => $l['producto']->id === $linea['producto']->id)
                ->sum('cantidad_base');

            if ($requerido > $disponible) {
                throw new ErrorDeNegocio("Stock insuficiente de \"{$linea['producto']->nombre}\" (disponible: {$disponible}).");
            }
        }
    }

    private function tomarCorrelativo(string $empresaId, string $sucursalId, ?string $cajaId, string $tipoComprobante): SerieCorrelativo
    {
        // prefiere la serie asignada a la caja; si no hay, usa la general de la sucursal
        $serie = SerieCorrelativo::query()
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('tipo_comprobante_codigo', $tipoComprobante)
            ->where(fn ($q) => $q->where('caja_id', $cajaId)->orWhereNull('caja_id'))
            ->orderByRaw('caja_id is null')
            ->lockForUpdate()
            ->first();

        if (! $serie) {
            $serie = SerieCorrelativo::create([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'tipo_comprobante_codigo' => $tipoComprobante,
                'serie' => $this->siguienteSerieLibre($empresaId, $tipoComprobante),
                'correlativo' => 0,
            ]);
        }

        $serie->increment('correlativo');

        return $serie;
    }

    /**
     * La serie es unica por empresa, asi que cada sucursal recibe la siguiente
     * libre del mismo prefijo: B001 (principal), B002 (segunda sucursal), etc.
     */
    private function siguienteSerieLibre(string $empresaId, string $tipoComprobante): string
    {
        $porDefecto = self::SERIES_POR_DEFECTO[$tipoComprobante];
        $prefijo = preg_replace('/\d+$/', '', $porDefecto);

        $mayorUsada = SerieCorrelativo::query()
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_codigo', $tipoComprobante)
            ->lockForUpdate()
            ->pluck('serie')
            ->map(fn (string $serie) => (int) substr($serie, strlen($prefijo)))
            ->max() ?? 0;

        return $prefijo.str_pad((string) ($mayorUsada + 1), strlen($porDefecto) - strlen($prefijo), '0', STR_PAD_LEFT);
    }

    private function registrarDetalle(Comprobante $comprobante, array $linea, string $sucursalId, Usuario $usuario): void
    {
        $producto = $linea['producto'];
        $consumo = ['consumos' => [], 'costo_total' => 0.0];

        if ($producto->controla_stock) {
            $consumo = $this->inventario->consumirFifo($producto->id, $sucursalId, $linea['cantidad_base']);
        }

        $detalle = $comprobante->detalles()->create([
            'empresa_id' => $comprobante->empresa_id,
            'producto_id' => $producto->id,
            'presentacion_id' => $linea['presentacion']->id,
            'lote_id' => $consumo['consumos'][0]['lote_id'] ?? null,
            'descripcion' => $producto->nombre.($linea['presentacion']->nombre !== 'Unidad' ? " ({$linea['presentacion']->nombre})" : ''),
            'unidad_codigo' => $producto->unidad_base_codigo,
            'tipo_afectacion_codigo' => $producto->tipo_afectacion_codigo,
            'cantidad' => $linea['cantidad'],
            'valor_unitario' => $linea['valor_unitario'],
            'precio_unitario' => $linea['precio_unitario'],
            'costo_unitario' => $linea['cantidad'] > 0 ? round($consumo['costo_total'] / $linea['cantidad'], 6) : 0,
            'descuento' => $linea['descuento'],
            'igv' => $linea['igv'],
            'total' => $linea['total'],
        ]);

        foreach ($consumo['consumos'] as $datosConsumo) {
            DetalleConsumoCapa::create([
                'detalle_id' => $detalle->id,
                'capa_id' => $datosConsumo['capa_id'],
                'cantidad' => $datosConsumo['cantidad'],
                'costo_unitario' => $datosConsumo['costo_unitario'],
            ]);
        }

        if ($producto->controla_stock) {
            $this->inventario->descontarStock($producto->id, $sucursalId, $linea['cantidad_base']);
            $this->inventario->registrarMovimiento(
                $comprobante->empresa_id,
                $sucursalId,
                $producto->id,
                'venta',
                $linea['cantidad_base'],
                $linea['cantidad_base'] > 0 ? $consumo['costo_total'] / $linea['cantidad_base'] : 0,
                $comprobante->id,
                $usuario->id,
            );
        }
    }
}
