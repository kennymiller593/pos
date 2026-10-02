<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Guía de remisión electrónica del remitente (tipo 09): sustenta el traslado de
 * mercadería. No mueve stock ni caja; eso lo hacen la venta y la transferencia.
 */
class GuiaRemision extends Model
{
    use HasUuids;

    protected $table = 'guias_remision';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    /** Motivos de traslado que se ofrecen (catálogo 20 de SUNAT). */
    public const MOTIVOS = [
        '01' => 'Venta',
        '04' => 'Traslado entre establecimientos de la misma empresa',
        '13' => 'Otros',
    ];

    /** Modalidades de traslado (catálogo 18 de SUNAT). */
    public const MODALIDADES = [
        '01' => 'Transporte público',
        '02' => 'Transporte privado',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_traslado' => 'date',
            'vehiculo_menor' => 'boolean',
            'peso_bruto' => 'decimal:3',
            'creado_en' => 'datetime',
            'enviado_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    /** T001-000001 */
    public function numero(): string
    {
        return "{$this->serie}-".str_pad((string) $this->correlativo, 6, '0', STR_PAD_LEFT);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class, 'transferencia_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(GuiaRemisionDetalle::class, 'guia_id')->orderBy('orden');
    }
}
