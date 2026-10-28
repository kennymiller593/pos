<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fórmula guardada de un producto: los insumos que lleva una tanda de `rinde_base` unidades. */
class Receta extends Model
{
    use HasUuids;

    protected $table = 'recetas';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = ['empresa_id', 'producto_id', 'rinde_base', 'otros_costos'];

    protected function casts(): array
    {
        return [
            'rinde_base' => 'decimal:3',
            'otros_costos' => 'decimal:2',
            'actualizado_en' => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function insumos(): HasMany
    {
        return $this->hasMany(RecetaInsumo::class, 'receta_id');
    }
}
