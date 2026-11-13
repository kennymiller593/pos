<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /** Los productos del modelo, en el orden de sus combinaciones. */
    public function variantes(): HasMany
    {
        return $this->hasMany(Producto::class, 'grupo_id')->orderBy('variante_orden')->orderBy('nombre');
    }
}
