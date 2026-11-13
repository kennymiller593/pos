<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una unidad física de un producto que controla serie (un celular con su IMEI, una laptop con su número de serie).
 * Es una capa encima del inventario: el stock y el costo FIFO siguen siendo los del producto.
 */
class ProductoSerie extends Model
{
    use HasUuids;

    public const ESTADOS = [
        'disponible' => 'Disponible',
        'vendida' => 'Vendida',
        'en_transito' => 'En tránsito',
        'baja' => 'De baja',
    ];

    protected $table = 'producto_series';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'sucursal_id',
        'numero_serie',
        'serie_alterna',
        'estado',
        'costo_unitario',
        'compra_detalle_id',
        'comprobante_detalle_id',
    ];

    protected function casts(): array
    {
        return [
            'costo_unitario' => 'decimal:6',
        ];
    }

    /** Como se guarda y se compara una serie: en MAYÚSCULAS y sin espacios ("35 123-4a" → "35123-4A"). */
    public static function normalizar(mixed $numero): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $numero));
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }

    /** La línea del comprobante con que está vendida ahora (null si no está vendida). */
    public function comprobanteDetalle(): BelongsTo
    {
        return $this->belongsTo(ComprobanteDetalle::class, 'comprobante_detalle_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(SerieMovimiento::class, 'serie_id');
    }
}
