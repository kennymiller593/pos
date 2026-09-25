<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Producto extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * Siguiente codigo interno automatico de la empresa: P0001, P0002...
     * Cuenta tambien los eliminados (el UNIQUE de la BD los incluye) y solo
     * los codigos con ese formato, asi los codigos manuales antiguos no estorban.
     */
    public static function siguienteCodigo(string $empresaId): string
    {
        $mayor = (int) static::withTrashed()
            ->where('empresa_id', $empresaId)
            ->whereRaw("codigo_interno ~ '^P[0-9]+$'")
            ->max(DB::raw('CAST(SUBSTRING(codigo_interno FROM 2) AS BIGINT)'));

        return 'P'.str_pad((string) ($mayor + 1), 4, '0', STR_PAD_LEFT);
    }

    protected $table = 'productos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    public const DELETED_AT = 'eliminado_en';

    protected $fillable = [
        'empresa_id',
        'categoria_id',
        'marca_id',
        'codigo_interno',
        'nombre',
        'unidad_base_codigo',
        'tipo_afectacion_codigo',
        'permite_fraccion',
        'controla_lote',
        'controla_stock',
        'stock_minimo',
        'atributos',
        'imagen_url',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'permite_fraccion' => 'boolean',
            'controla_lote' => 'boolean',
            'controla_stock' => 'boolean',
            'stock_minimo' => 'decimal:3',
            'atributos' => 'array',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class, 'marca_id');
    }

    public function unidadBase(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_base_codigo', 'codigo');
    }

    public function tipoAfectacion(): BelongsTo
    {
        return $this->belongsTo(TipoAfectacionIgv::class, 'tipo_afectacion_codigo', 'codigo');
    }

    public function presentaciones(): HasMany
    {
        return $this->hasMany(ProductoPresentacion::class, 'producto_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'producto_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'producto_id');
    }

    public function capasCosto(): HasMany
    {
        return $this->hasMany(CapaCosto::class, 'producto_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }
}
