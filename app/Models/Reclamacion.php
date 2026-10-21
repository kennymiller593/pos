<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una hoja del Libro de Reclamaciones de la tienda en línea. */
class Reclamacion extends Model
{
    use HasUuids;

    protected $table = 'reclamaciones';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    /** Días hábiles que tiene el proveedor para responder. */
    public const PLAZO_DIAS_HABILES = 15;

    protected $fillable = [
        'empresa_id', 'numero', 'tipo', 'nombre', 'documento_tipo', 'documento_numero', 'domicilio', 'telefono', 'email',
        'menor_de_edad', 'apoderado', 'bien_tipo', 'monto', 'bien_descripcion', 'detalle', 'pedido', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'menor_de_edad' => 'boolean',
            'monto' => 'decimal:2',
            'creado_en' => 'datetime',
            'respondido_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function respondidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'respondido_por');
    }

    /** "Reclamo N.º 000012-2026" */
    public function codigo(): string
    {
        return str_pad((string) $this->numero, 6, '0', STR_PAD_LEFT).'-'.$this->creado_en->format('Y');
    }

    /** Fecha límite de respuesta: 15 días hábiles (lunes a viernes) desde que se registró. */
    public function venceEl(): \Carbon\CarbonInterface
    {
        $fecha = $this->creado_en->copy()->startOfDay();
        for ($dias = 0; $dias < self::PLAZO_DIAS_HABILES;) {
            $fecha->addDay();
            if (! $fecha->isWeekend()) {
                $dias++;
            }
        }

        return $fecha;
    }
}
