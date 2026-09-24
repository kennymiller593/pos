<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleConsumoCapa extends Model
{
    use HasUuids;

    protected $table = 'detalle_consumo_capas';

    public $timestamps = false;

    protected $fillable = [
        'detalle_id',
        'capa_id',
        'cantidad',
        'costo_unitario',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:6',
        ];
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(ComprobanteDetalle::class, 'detalle_id');
    }

    public function capa(): BelongsTo
    {
        return $this->belongsTo(CapaCosto::class, 'capa_id');
    }
}
