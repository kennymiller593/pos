<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    use HasUuids;

    protected $table = 'sucursales';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'codigo_sunat',
        'nombre',
        'direccion',
        'ubigeo',
        'telefono',
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

    public function ubigeoInfo(): BelongsTo
    {
        return $this->belongsTo(Ubigeo::class, 'ubigeo', 'codigo');
    }

    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class, 'sucursal_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'sucursal_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'sucursal_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'sucursal_id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'sucursal_id');
    }

    public function preciosSucursal(): HasMany
    {
        return $this->hasMany(PrecioSucursal::class, 'sucursal_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(SerieCorrelativo::class, 'sucursal_id');
    }
}
