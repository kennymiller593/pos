<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoDetalle extends Model
{
    use HasUuids;

    protected $table = 'pedido_detalles';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'pedido_id',
        'producto_id',
        'presentacion_id',
        'orden',
        'descripcion',
        'unidad_codigo',
        'tipo_afectacion_codigo',
        'cantidad',
        'valor_unitario',
        'precio_unitario',
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
            'descuento' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }
}
