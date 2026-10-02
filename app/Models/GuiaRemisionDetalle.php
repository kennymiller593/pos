<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuiaRemisionDetalle extends Model
{
    use HasUuids;

    protected $table = 'guia_remision_detalles';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'guia_id',
        'producto_id',
        'presentacion_id',
        'orden',
        'codigo',
        'descripcion',
        'unidad_codigo',
        'cantidad',
    ];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:3'];
    }

    public function guia(): BelongsTo
    {
        return $this->belongsTo(GuiaRemision::class, 'guia_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
