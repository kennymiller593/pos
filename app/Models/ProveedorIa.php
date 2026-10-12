<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proveedor de IA (DeepSeek, OpenAI, Anthropic, OpenRouter) con su clave y su estado de salud. */
class ProveedorIa extends Model
{
    protected $table = 'ia_proveedores';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    /** Fallos seguidos a partir de los cuales el proveedor se pausa. */
    public const FALLOS_PARA_PAUSAR = 3;

    /** Minutos que dura la pausa. */
    public const MINUTOS_DE_PAUSA = 5;

    protected $fillable = [
        'codigo', 'nombre', 'driver', 'api_key', 'url', 'activo', 'orden',
        'ultimo_ok_en', 'ultimo_error', 'ultimo_error_en', 'fallos_seguidos', 'pausado_hasta',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            // la clave nunca queda en claro en la base ni en un respaldo
            'api_key' => 'encrypted',
            'activo' => 'boolean',
            'orden' => 'integer',
            'fallos_seguidos' => 'integer',
            'ultimo_ok_en' => 'datetime',
            'ultimo_error_en' => 'datetime',
            'pausado_hasta' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function modelos(): HasMany
    {
        return $this->hasMany(ModeloIa::class, 'proveedor_codigo', 'codigo')->orderBy('modelo');
    }

    /** Puede atender ahora: activo, con clave y sin pausa vigente. */
    public function disponible(): bool
    {
        return $this->activo && filled($this->api_key) && ! $this->enPausa();
    }

    public function enPausa(): bool
    {
        return $this->pausado_hasta !== null && $this->pausado_hasta->isFuture();
    }

    /** Últimos caracteres de la clave, para que el superadmin sepa cuál puso sin verla entera. */
    public function finalDeClave(): ?string
    {
        return filled($this->api_key) ? substr($this->api_key, -4) : null;
    }
}
