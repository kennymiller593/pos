<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auditoria extends Model
{
    protected $table = 'auditoria';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = null;

    protected $fillable = [
        'empresa_id',
        'usuario_id',
        'accion',
        'entidad',
        'entidad_id',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'creado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /**
     * Deja constancia de una accion sensible. Nunca interrumpe la operacion
     * principal: si la auditoria falla, solo se reporta el error.
     */
    public static function registrar(Usuario $usuario, string $accion, string $entidad, ?string $entidadId = null, array $detalle = []): void
    {
        try {
            static::create([
                'empresa_id' => $usuario->empresa_id,
                'usuario_id' => $usuario->id,
                'accion' => $accion,
                'entidad' => $entidad,
                'entidad_id' => $entidadId,
                'detalle' => $detalle ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
