<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Agente de IA definido por la plataforma: instrucciones de un rubro y su cadena de modelos de respaldo. */
class AgenteIa extends Model
{
    use HasUuids;

    protected $table = 'ia_agentes';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'codigo', 'nombre', 'rubro_codigo', 'descripcion', 'instrucciones', 'cadena',
        'limite_mensual', 'max_pasos', 'sugerencias', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'cadena' => 'array',
            'sugerencias' => 'array',
            'limite_mensual' => 'integer',
            'max_pasos' => 'integer',
            'activo' => 'boolean',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class, 'rubro_codigo', 'codigo');
    }

    public function activaciones(): HasMany
    {
        return $this->hasMany(AgenteEmpresa::class, 'agente_id');
    }

    /** @return list<array{proveedor: string, modelo: string}> */
    public function eslabones(): array
    {
        return collect($this->cadena ?? [])
            ->filter(fn ($e) => is_array($e) && filled($e['proveedor'] ?? null) && filled($e['modelo'] ?? null))
            ->map(fn ($e) => ['proveedor' => (string) $e['proveedor'], 'modelo' => trim((string) $e['modelo'])])
            ->values()
            ->all();
    }
}
