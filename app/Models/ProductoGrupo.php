<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Un "modelo" con variantes (Polo básico en tallas y colores). Solo agrupa: cada variante es un
 * producto normal (productos.grupo_id) con sus valores en productos.atributos.
 */
class ProductoGrupo extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'producto_grupos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    public const DELETED_AT = 'eliminado_en';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'categoria_id',
        'marca_id',
        'descripcion',
        'imagen_url',
        'atributos',
        'omitidas',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            // definición ordenada: [{nombre: "Talla", valores: ["S", "M"]}, ...]
            'atributos' => 'array',
            // combinaciones que no se quieren tener: [["L", "Azul"], ...]
            'omitidas' => 'array',
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

    /**
     * ¿El modelo controla serie / IMEI? Lo dicen sus variantes a la venta (todas la llevan); sin ninguna activa,
     * las que tenga. Las variantes nuevas nacen igual.
     *
     * @param  Collection<int, Producto>|null  $variantes  ya cargadas (para no consultar otra vez)
     */
    public function controlaSerie($variantes = null): bool
    {
        $variantes ??= $this->variantes()->get(['id', 'activo', 'controla_serie']);
        $activas = $variantes->where('activo', true);
        $base = $activas->isNotEmpty() ? $activas : $variantes;

        return $base->isNotEmpty() && $base->every(fn (Producto $p) => (bool) $p->controla_serie);
    }

    /** Los productos del modelo, en el orden de sus combinaciones. */
    public function variantes(): HasMany
    {
        return $this->hasMany(Producto::class, 'grupo_id')->orderBy('variante_orden')->orderBy('nombre');
    }
}
