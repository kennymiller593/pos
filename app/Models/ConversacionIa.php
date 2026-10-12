<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversación de un usuario con un agente dentro de la app. */
class ConversacionIa extends Model
{
    use HasUuids;

    protected $table = 'ia_conversaciones';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = ['empresa_id', 'usuario_id', 'agente_id', 'titulo'];

    protected function casts(): array
    {
        return ['creado_en' => 'datetime', 'actualizado_en' => 'datetime'];
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(AgenteIa::class, 'agente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeIa::class, 'conversacion_id')->orderBy('creado_en')->orderBy('id');
    }
}
