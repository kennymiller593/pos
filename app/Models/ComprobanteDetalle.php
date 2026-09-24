<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComprobanteDetalle extends Model
{
    use HasUuids;

    protected $table = 'comprobante_detalles';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'comprobante_id',
        'producto_id',
        'presentacion_id',
        'lote_id',
        'descripcion',
        'unidad_codigo',
        'tipo_afectacion_codigo',
        'cantidad',
        'valor_unitario',
        'precio_unitario',
        'costo_unitario',
        'descuento',
        'igv',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'valor_unitario' => 'decimal:6',
            'precio_unitario' => 'decimal:6',
            'costo_unitario' => 'decimal:6',
            'descuento' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_codigo', 'codigo');
    }

    public function tipoAfectacion(): BelongsTo
    {
        return $this->belongsTo(TipoAfectacionIgv::class, 'tipo_afectacion_codigo', 'codigo');
    }

    public function consumosCapas(): HasMany
    {
        return $this->hasMany(DetalleConsumoCapa::class, 'detalle_id');
    }
}
