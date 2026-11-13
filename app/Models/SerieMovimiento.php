<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un paso en la vida de una unidad con serie: entró por compra, se vendió, volvió, se dio de baja... */
class SerieMovimiento extends Model
{
    use HasUuids;

    public const TIPOS = [
        'compra' => 'Entró por compra',
        'ajuste_entrada' => 'Entró por ajuste de stock',
        'regularizacion' => 'Serie regularizada',
        'venta' => 'Vendida',
        'venta_anulada' => 'Volvió por anulación de la venta',
        'devolucion' => 'Devuelta con nota de crédito',
        'transferencia_salida' => 'Salió por transferencia',
        'transferencia_entrada' => 'Llegó por transferencia',
        'transferencia_anulada' => 'Volvió por transferencia anulada',
        'baja' => 'Dada de baja',
        'compra_anulada' => 'Retirada por anulación de la compra',
    ];

    protected $table = 'serie_movimientos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'serie_id',
        'sucursal_id',
        'usuario_id',
        'tipo',
        'referencia_id',
        'comprobante_detalle_id',
    ];

    public function serie(): BelongsTo
    {
        return $this->belongsTo(ProductoSerie::class, 'serie_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
