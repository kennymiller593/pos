<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Cotizacion;
use App\Models\Usuario;
use App\Support\NumeroALetras;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Barryvdh\Snappy\PdfWrapper;
use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones: se calculan con las mismas reglas de la venta (precio de lista o mayorista,
 * descuentos, IGV, Nuevo RUS) pero no tocan stock, caja ni SUNAT.
 */
class CotizacionService
{
    public function __construct(private readonly VentaService $ventas) {}

    /**
     * Crea una cotización o reemplaza el contenido de una pendiente.
     *
     * $datos ya validados: cliente_id?, fecha_emision, valida_hasta, tiempo_entrega?, direccion_envio?,
     * es_credito, observaciones?, items[{presentacion_id, cantidad, precio_unitario?, descuento?}]
     *
     * @throws ErrorDeNegocio
     */
    public function guardar(Usuario $usuario, string $sucursalId, array $datos, ?Cotizacion $cotizacion = null): Cotizacion
    {
        $empresaId = $usuario->empresa_id;

        if ($cotizacion && $cotizacion->estado !== 'pendiente') {
            throw new ErrorDeNegocio('Solo se puede editar una cotización pendiente. Duplícala para hacer una nueva.');
        }

        $cliente = null;
        if (filled($datos['cliente_id'] ?? null)) {
            $cliente = Cliente::where('empresa_id', $empresaId)->find($datos['cliente_id']);
            if (! $cliente) {
                throw new ErrorDeNegocio('El cliente elegido no existe.');
            }
        }

        [$lineas, $totales] = $this->ventas->calcularLineas($empresaId, $datos['items'], $usuario->empresa->esRus());

        return DB::transaction(function () use ($usuario, $empresaId, $sucursalId, $datos, $cotizacion, $cliente, $lineas, $totales) {
            $cabecera = [
                'cliente_id' => $cliente?->id,
                'fecha_emision' => $datos['fecha_emision'],
                'valida_hasta' => $datos['valida_hasta'],
                'tiempo_entrega' => $datos['tiempo_entrega'] ?? null,
                'direccion_envio' => $datos['direccion_envio'] ?? null,
                'es_credito' => (bool) ($datos['es_credito'] ?? false),
                'observaciones' => $datos['observaciones'] ?? null,
                'cliente_tipo_doc' => $cliente?->tipo_documento_codigo,
                'cliente_numero_doc' => $cliente?->numero_documento,
                'cliente_nombre' => $cliente?->nombre,
                'cliente_direccion' => $cliente?->direccion,
                'total_gravado' => round($totales['gravado'], 2),
                'total_exonerado' => round($totales['exonerado'], 2),
                'total_inafecto' => round($totales['inafecto'], 2),
                'total_igv' => round($totales['igv'], 2),
                'total_descuentos' => round($totales['descuentos'], 2),
                'total' => round($totales['total'], 2),
            ];

            if ($cotizacion) {
                $cotizacion->update($cabecera);
                $cotizacion->detalles()->delete();
            } else {
                // el correlativo es por empresa: dos usuarios cotizando a la vez se serializan aqui
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["cotizaciones:{$empresaId}"]);
                $numero = (int) Cotizacion::where('empresa_id', $empresaId)->max('numero') + 1;

                $cotizacion = Cotizacion::create([
                    ...$cabecera,
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'usuario_id' => $usuario->id,
                    'numero' => $numero,
                    'estado' => 'pendiente',
                ]);
            }

            foreach ($lineas as $orden => $linea) {
                $producto = $linea['producto'];
                $presentacion = $linea['presentacion'];

                $cotizacion->detalles()->create([
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

            return $cotizacion;
        });
    }

    /** @throws ErrorDeNegocio */
    public function anular(Cotizacion $cotizacion, Usuario $usuario): void
    {
        if ($cotizacion->estado !== 'pendiente') {
            throw new ErrorDeNegocio($cotizacion->estado === 'convertida'
                ? 'Esta cotización ya se convirtió en venta: si hubo un error, anula el comprobante.'
                : 'La cotización ya está anulada.');
        }

        $cotizacion->update(['estado' => 'anulada', 'anulada_en' => now(), 'anulada_por' => $usuario->id]);

        Auditoria::registrar($usuario, 'cotizacion.anulada', 'cotizacion', $cotizacion->id, ['cotizacion' => $cotizacion->codigo()]);
    }

    /**
     * Cotización que el POS puede cargar en el carrito: de la empresa y aún pendiente.
     * Vencida también se carga, pero ya sin respetar los precios cotizados.
     */
    public function paraVender(string $empresaId, ?string $id): ?Cotizacion
    {
        if (! $id || ! \Illuminate\Support\Str::isUuid($id)) {
            return null;
        }

        return Cotizacion::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', 'pendiente')
            ->with(['detalles', 'cliente'])
            ->find($id);
    }

    /** Precio cotizado por presentación, solo mientras la cotización sigue vigente. */
    public function preciosVigentes(?Cotizacion $cotizacion): array
    {
        if (! $cotizacion || $cotizacion->estaVencida()) {
            return [];
        }

        return $cotizacion->detalles
            ->mapWithKeys(fn ($d) => [$d->presentacion_id => (float) $d->precio_unitario])
            ->all();
    }

    /** Deja la cotización enlazada a la venta que la atendió. */
    public function marcarConvertida(Cotizacion $cotizacion, Comprobante $comprobante): void
    {
        Cotizacion::whereKey($cotizacion->id)
            ->where('estado', 'pendiente')
            ->update(['estado' => 'convertida', 'comprobante_id' => $comprobante->id, 'actualizado_en' => now()]);
    }

    /** PDF en A4 para descargar, compartir por enlace o adjuntar al correo. */
    public function pdf(Cotizacion $cotizacion): PdfWrapper
    {
        $cotizacion->loadMissing(['empresa', 'detalles', 'sucursal:id,nombre,direccion', 'usuario:id,nombre_completo']);

        return SnappyPdf::loadView('pdf.cotizacion', [
            'cotizacion' => $cotizacion,
            'empresa' => $cotizacion->empresa,
            'logo' => $cotizacion->empresa->logoParaPdf(),
            'letras' => NumeroALetras::enSoles((float) $cotizacion->total),
        ])
            ->setOption('page-size', 'A4')
            ->setOption('margin-top', '12')
            ->setOption('margin-bottom', '12')
            ->setOption('margin-left', '14')
            ->setOption('margin-right', '14')
            ->setOption('encoding', 'utf-8');
    }
}
