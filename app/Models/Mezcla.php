<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una tanda preparada: insumos que salieron del stock y el producto que entró con su costo calculado. */
class Mezcla extends Model
{
    use HasUuids;

    protected $table = 'mezclas';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'usuario_id',
        'numero',
        'producto_id',
        'presentacion_id',
        'lote_id',
        'capa_id',
        'cantidad',
        'cantidad_base',
        'costo_insumos',
        'otros_costos',
        'costo_total',
        'costo_unitario',
        'observacion',
        'estado',
        'anulada_en',
        'anulada_por',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'cantidad' => 'decimal:3',
            'cantidad_base' => 'decimal:3',
            'costo_insumos' => 'decimal:2',
            'otros_costos' => 'decimal:2',
            'costo_total' => 'decimal:2',
            'costo_unitario' => 'decimal:6',
            'anulada_en' => 'datetime',
            'creado_en' => 'datetime',
        ];
    }

    public function codigo(): string
    {
        return 'MZ-'.str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'anulada_por');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function capa(): BelongsTo
    {
        return $this->belongsTo(CapaCosto::class, 'capa_id');
    }

    public function insumos(): HasMany
    {
        return $this->hasMany(MezclaInsumo::class, 'mezcla_id');
    }
}
