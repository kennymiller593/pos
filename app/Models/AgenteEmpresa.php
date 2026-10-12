<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Activación de un agente para una empresa (adicional de pago). */
class AgenteEmpresa extends Model
{
    use HasUuids;

    protected $table = 'ia_agentes_empresa';

    public $timestamps = false;

    protected $fillable = ['empresa_id', 'agente_id', 'activo', 'limite_mensual', 'activado_en', 'desactivado_en'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'limite_mensual' => 'integer',
            'activado_en' => 'datetime',
            'desactivado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(AgenteIa::class, 'agente_id');
    }
}
