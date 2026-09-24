<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoPresentacion extends Model
{
    use HasUuids;

    protected $table = 'producto_presentaciones';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'nombre',
        'unidad_codigo',
        'factor_conversion',
        'codigo_barras',
        'precio_venta',
        'precio_mayorista',
        'cantidad_mayorista',
        'es_default',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'factor_conversion' => 'decimal:4',
            'precio_venta' => 'decimal:4',
            'precio_mayorista' => 'decimal:4',
            'cantidad_mayorista' => 'decimal:3',
            'es_default' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_codigo', 'codigo');
    }

    public function preciosSucursal(): HasMany
    {
        return $this->hasMany(PrecioSucursal::class, 'presentacion_id');
    }
}
