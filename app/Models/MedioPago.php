<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedioPago extends Model
{
    protected $table = 'medios_pago';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'requiere_referencia',
    ];

    protected function casts(): array
    {
        return [
            'requiere_referencia' => 'boolean',
        ];
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'medio_pago_codigo', 'codigo');
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class, 'medio_pago_codigo', 'codigo');
    }
}
