<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MezclaInsumo extends Model
{
    use HasUuids;

    protected $table = 'mezcla_insumos';

    public $timestamps = false;

    protected $fillable = [
        'mezcla_id',
        'producto_id',
        'presentacion_id',
        'cantidad',
        'cantidad_base',
        'costo_unitario',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_base' => 'decimal:3',
            'costo_unitario' => 'decimal:6',
            'total' => 'decimal:2',
        ];
    }

    public function mezcla(): BelongsTo
    {
        return $this->belongsTo(Mezcla::class, 'mezcla_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(MezclaConsumo::class, 'insumo_id');
    }
}
