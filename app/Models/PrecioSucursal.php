<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecioSucursal extends Model
{
    use HasUuids;

    protected $table = 'precios_sucursal';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'presentacion_id',
        'sucursal_id',
        'precio_venta',
        'precio_mayorista',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:4',
            'precio_mayorista' => 'decimal:4',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }
}
