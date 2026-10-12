<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensaje de una conversación. Las respuestas del asistente guardan proveedor, modelo, tokens y costo. */
class MensajeIa extends Model
{
    use HasUuids;

    protected $table = 'ia_mensajes';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'conversacion_id', 'empresa_id', 'rol', 'contenido', 'proveedor_codigo', 'modelo',
        'tokens_entrada', 'tokens_salida', 'costo_usd', 'duracion_ms', 'herramientas', 'intentos', 'productos',
    ];

    protected function casts(): array
    {
        return [
            'tokens_entrada' => 'integer',
            'tokens_salida' => 'integer',
            'costo_usd' => 'decimal:6',
            'duracion_ms' => 'integer',
            'herramientas' => 'array',
            'intentos' => 'array',
            'productos' => 'array',
            'creado_en' => 'datetime',
        ];
    }

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(ConversacionIa::class, 'conversacion_id');
    }
}
