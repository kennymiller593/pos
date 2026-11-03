<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedioPago extends Model
{
    /** Medio interno: el adelanto de un pedido ya entró a caja el día que lo dejaron; solo lo escribe el sistema al entregar. */
    public const ADELANTO = 'adelanto';

    /** Medios que las pantallas no ofrecen ni aceptan como pago. */
    public const INTERNOS = [self::ADELANTO];

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

    /** Medios con los que un cliente puede pagar (sin los internos). */
    public function scopeElegibles(Builder $query): Builder
    {
        return $query->whereNotIn('codigo', self::INTERNOS);
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
