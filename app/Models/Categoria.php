<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use HasUuids;

    protected $table = 'categorias';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'padre_id',
        'nombre',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(Categoria::class, 'padre_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}
