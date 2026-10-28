<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un fallo avisado desde el navegador: lo que no llega al servidor no deja rastro en ningún otro log. */
class ErrorCliente extends Model
{
    use HasUuids;

    protected $table = 'errores_cliente';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    public const TIPOS = [
        'js' => 'Error de JavaScript',
        'red' => 'Envío sin respuesta',
        'respuesta' => 'Respuesta ajena al sistema',
        'negocio' => 'Rechazo del sistema',
        'validacion' => 'Formulario rechazado (datos faltantes)',
    ];

    protected $fillable = ['empresa_id', 'usuario_id', 'tipo', 'pantalla', 'accion', 'mensaje', 'detalle', 'navegador', 'ip'];

    protected function casts(): array
    {
        return ['detalle' => 'array', 'creado_en' => 'datetime'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
