<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AperturaCaja extends Model
{
    use HasUuids;

    protected $table = 'aperturas_caja';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'caja_id',
        'usuario_id',
        'monto_inicial',
        'monto_cierre',
        'monto_sistema',
        'abierta_en',
        'cerrada_en',
    ];

    protected function casts(): array
    {
        return [
            'monto_inicial' => 'decimal:2',
            'monto_cierre' => 'decimal:2',
            'monto_sistema' => 'decimal:2',
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'apertura_id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'apertura_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'apertura_id');
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class, 'apertura_id');
    }

    public function pagosProveedor(): HasMany
    {
        return $this->hasMany(PagoProveedor::class, 'apertura_id');
    }
}
