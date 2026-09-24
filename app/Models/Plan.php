<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    protected $table = 'planes';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'precio_mensual',
        'max_sucursales',
        'max_usuarios',
        'max_comprobantes_mes',
    ];

    protected function casts(): array
    {
        return [
            'precio_mensual' => 'decimal:2',
        ];
    }

    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class, 'plan_id');
    }
}
