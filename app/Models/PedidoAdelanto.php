<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Adelanto de un pedido: siempre respaldado por un ingreso en la caja abierta de quien lo recibió. */
class PedidoAdelanto extends Model
{
    use HasUuids;

    protected $table = 'pedido_adelantos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'pedido_id',
        'movimiento_caja_id',
        'apertura_id',
        'usuario_id',
        'medio_pago_codigo',
        'monto',
        'referencia',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'creado_en' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoCaja::class, 'movimiento_caja_id');
    }

    public function apertura(): BelongsTo
    {
        return $this->belongsTo(AperturaCaja::class, 'apertura_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class, 'medio_pago_codigo', 'codigo');
    }
}
