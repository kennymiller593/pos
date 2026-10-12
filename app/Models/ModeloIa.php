<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Precio de un modelo (dólares por millón de tokens) para valorizar cada consulta. */
class ModeloIa extends Model
{
    use HasUuids;

    protected $table = 'ia_modelos';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = ['proveedor_codigo', 'modelo', 'precio_entrada', 'precio_salida', 'nota'];

    protected function casts(): array
    {
        return [
            'precio_entrada' => 'decimal:4',
            'precio_salida' => 'decimal:4',
            'actualizado_en' => 'datetime',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorIa::class, 'proveedor_codigo', 'codigo');
    }

    /** Costo en dólares de una consulta con estos tokens. */
    public function costo(int $tokensEntrada, int $tokensSalida): float
    {
        return round($tokensEntrada * (float) $this->precio_entrada / 1_000_000 + $tokensSalida * (float) $this->precio_salida / 1_000_000, 6);
    }
}
