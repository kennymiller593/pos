<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecetaInsumo extends Model
{
    use HasUuids;

    protected $table = 'receta_insumos';

    public $timestamps = false;

    protected $fillable = ['receta_id', 'producto_id', 'presentacion_id', 'cantidad_base'];

    protected function casts(): array
    {
        return ['cantidad_base' => 'decimal:4'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
