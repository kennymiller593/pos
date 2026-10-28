<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** De qué capa de costo salió cada parte de un insumo; al anular la mezcla vuelve a esa capa. */
class MezclaConsumo extends Model
{
    use HasUuids;

    protected $table = 'mezcla_consumos';

    public $timestamps = false;

    protected $fillable = ['insumo_id', 'capa_id', 'cantidad', 'costo_unitario'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:6',
        ];
    }
}
