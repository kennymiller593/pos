<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cotización (proforma): documento interno que no va a SUNAT ni mueve stock o caja.
 * Cuando el cliente la acepta se convierte en venta desde el POS.
 */
class Cotizacion extends Model
{
    use HasUuids;

    protected $table = 'cotizaciones';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'cliente_id',
        'usuario_id',
        'numero',
        'fecha_emision',
        'valida_hasta',
        'tiempo_entrega',
        'direccion_envio',
        'es_credito',
        'observaciones',
        'cliente_tipo_doc',
        'cliente_numero_doc',
        'cliente_nombre',
        'cliente_direccion',
        'total_gravado',
        'total_exonerado',
        'total_inafecto',
        'total_igv',
        'total_descuentos',
        'total',
        'estado',
        'comprobante_id',
        'anulada_en',
        'anulada_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'valida_hasta' => 'date',
            'es_credito' => 'boolean',
            'total_gravado' => 'decimal:2',
            'total_exonerado' => 'decimal:2',
            'total_inafecto' => 'decimal:2',
            'total_igv' => 'decimal:2',
            'total_descuentos' => 'decimal:2',
            'total' => 'decimal:2',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    /** COT-000001 */
    public function codigo(): string
    {
        return 'COT-'.str_pad((string) $this->numero, 6, '0', STR_PAD_LEFT);
    }

    /** Pendiente cuya fecha de validez ya pasó: sigue en la lista, pero no se puede vender tal cual. */
    public function estaVencida(): bool
    {
        return $this->estado === 'pendiente' && $this->valida_hasta->lt(now()->startOfDay());
    }

    /** Estado que ve el usuario: pendiente, vencida, convertida o anulada. */
    public function estadoVisible(): string
    {
        return $this->estaVencida() ? 'vencida' : $this->estado;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CotizacionDetalle::class, 'cotizacion_id')->orderBy('orden');
    }
}
