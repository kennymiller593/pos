<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapaCosto extends Model
{
    use HasUuids;

    protected $table = 'capas_costo';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'sucursal_id',
        'lote_id',
        'compra_detalle_id',
        'cantidad_inicial',
        'cantidad_restante',
        'costo_unitario',
        'fecha_ingreso',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_inicial' => 'decimal:3',
            'cantidad_restante' => 'decimal:3',
            'costo_unitario' => 'decimal:6',
            'fecha_ingreso' => 'datetime',
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

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(DetalleConsumoCapa::class, 'capa_id');
    }
}
