<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cada cambio en los puntos de un cliente: lo que ganó, canjeó, se le devolvió o se le ajustó. */
class MovimientoPuntos extends Model
{
    use HasUuids;

    public const TIPOS = [
        'ganado' => 'Ganados en compra',
        'canje' => 'Canjeados',
        'devolucion' => 'Devolución',
        'anulacion' => 'Anulación',
        'ajuste' => 'Ajuste manual',
    ];

    protected $table = 'movimientos_puntos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'comprobante_id',
        'usuario_id',
        'tipo',
        'puntos',
        'saldo',
        'concepto',
    ];

    protected function casts(): array
    {
        return [
            'puntos' => 'integer',
            'saldo' => 'integer',
            'creado_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
