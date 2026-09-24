<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Comprobante extends Model
{
    use HasUuids;

    /** Expresión SQL del total con signo: las notas de crédito restan. */
    public const SQL_TOTAL_NETO = "CASE WHEN tipo_comprobante_codigo = '07' THEN -total ELSE total END";

    protected $table = 'comprobantes';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'caja_id',
        'apertura_id',
        'cliente_id',
        'usuario_id',
        'tipo_comprobante_codigo',
        'serie',
        'correlativo',
        'fecha_emision',
        'hora_emision',
        'moneda',
        'tipo_cambio',
        'cliente_tipo_doc',
        'cliente_numero_doc',
        'cliente_nombre',
        'cliente_direccion',
        'total_gravado',
        'total_exonerado',
        'total_inafecto',
        'total_igv',
        'total_icbper',
        'total_descuentos',
        'total',
        'comprobante_ref_id',
        'motivo_nota',
        'es_credito',
        'fecha_vencimiento',
        'estado',
        'anulado_en',
        'anulado_por',
        'motivo_anulacion',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'tipo_cambio' => 'decimal:4',
            'total_gravado' => 'decimal:2',
            'total_exonerado' => 'decimal:2',
            'total_inafecto' => 'decimal:2',
            'total_igv' => 'decimal:2',
            'total_icbper' => 'decimal:2',
            'total_descuentos' => 'decimal:2',
            'total' => 'decimal:2',
            'es_credito' => 'boolean',
            'fecha_vencimiento' => 'date',
            'anulado_en' => 'datetime',
            'creado_en' => 'datetime',
            'sunat_respuesta' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function apertura(): BelongsTo
    {
        return $this->belongsTo(AperturaCaja::class, 'apertura_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function tipoComprobante(): BelongsTo
    {
        return $this->belongsTo(TipoComprobante::class, 'tipo_comprobante_codigo', 'codigo');
    }

    public function comprobanteRef(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_ref_id');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'comprobante_ref_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'anulado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ComprobanteDetalle::class, 'comprobante_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'comprobante_id');
    }

    public function sunat(): HasOne
    {
        return $this->hasOne(ComprobanteSunat::class, 'comprobante_id');
    }

    public function cuentaPorCobrar(): HasOne
    {
        return $this->hasOne(CuentaPorCobrar::class, 'comprobante_id');
    }
}
