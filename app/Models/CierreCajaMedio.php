<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuadre de un medio de pago al cerrar un turno: lo esperado por el sistema y lo declarado por el cajero. */
class CierreCajaMedio extends Model
{
    use HasUuids;

    protected $table = 'cierres_caja_medios';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'apertura_id',
        'medio_pago_codigo',
        'esperado',
        'declarado',
        'diferencia',
    ];

    protected function casts(): array
    {
        return [
            'esperado' => 'decimal:2',
            'declarado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'creado_en' => 'datetime',
        ];
    }

    public function apertura(): BelongsTo
    {
        return $this->belongsTo(AperturaCaja::class, 'apertura_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class, 'medio_pago_codigo', 'codigo');
    }
}
