<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Caja extends Model
{
    use HasUuids;

    protected $table = 'cajas';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'nombre',
        'ancho_ticket',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function aperturas(): HasMany
    {
        return $this->hasMany(AperturaCaja::class, 'caja_id');
    }

    public function aperturaAbierta(): HasOne
    {
        return $this->hasOne(AperturaCaja::class, 'caja_id')->whereNull('cerrada_en');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'caja_id');
    }

    public function seriesCorrelativos(): HasMany
    {
        return $this->hasMany(SerieCorrelativo::class, 'caja_id');
    }
}
