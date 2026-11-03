<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido (encargo): el cliente encarga productos para una fecha de entrega y deja adelantos.
 * No reserva stock ni va a SUNAT: la venta nace al entregarlo desde el POS cobrando el saldo.
 */
class Pedido extends Model
{
    use HasUuids;

    protected $table = 'pedidos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    /** Estados en los que el pedido sigue vivo (se puede adelantar, alistar, entregar o anular). */
    public const ABIERTOS = ['pendiente', 'listo'];

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'cliente_id',
        'usuario_id',
        'numero',
        'fecha_pedido',
        'fecha_entrega',
        'hora_entrega',
        'observaciones',
        'cliente_tipo_doc',
        'cliente_numero_doc',
        'cliente_nombre',
        'cliente_telefono',
        'cliente_direccion',
        'total_gravado',
        'total_exonerado',
        'total_inafecto',
        'total_igv',
        'total_descuentos',
        'total',
        'estado',
        'listo_en',
        'listo_por',
        'comprobante_id',
        'entregado_en',
        'anulado_en',
        'anulado_por',
        'motivo_anulacion',
        'devolucion_monto',
        'devolucion_movimiento_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_pedido' => 'date',
            'fecha_entrega' => 'date',
            'total_gravado' => 'decimal:2',
            'total_exonerado' => 'decimal:2',
            'total_inafecto' => 'decimal:2',
            'total_igv' => 'decimal:2',
            'total_descuentos' => 'decimal:2',
            'total' => 'decimal:2',
            'devolucion_monto' => 'decimal:2',
            'listo_en' => 'datetime',
            'entregado_en' => 'datetime',
            'anulado_en' => 'datetime',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    /** PED-000001 */
    public function codigo(): string
    {
        return 'PED-'.str_pad((string) $this->numero, 6, '0', STR_PAD_LEFT);
    }

    public function estaAbierto(): bool
    {
        return in_array($this->estado, self::ABIERTOS, true);
    }

    /** Abierto y con la fecha de entrega ya pasada. */
    public function estaAtrasado(): bool
    {
        return $this->estaAbierto() && $this->fecha_entrega->lt(now()->startOfDay());
    }

    /** Suma de los adelantos recibidos (usa el withSum 'adelantos_sum_monto' si ya vino cargado). */
    public function totalAdelantos(): float
    {
        if (array_key_exists('adelantos_sum_monto', $this->attributes)) {
            return round((float) $this->attributes['adelantos_sum_monto'], 2);
        }

        return round((float) ($this->relationLoaded('adelantos') ? $this->adelantos->sum('monto') : $this->adelantos()->sum('monto')), 2);
    }

    /** Lo que falta cobrar al entregar. */
    public function saldo(): float
    {
        return round(max(0, (float) $this->total - $this->totalAdelantos()), 2);
    }

    /** Hora de entrega como HH:MM (la BD guarda TIME con segundos). */
    public function horaEntrega(): ?string
    {
        return $this->hora_entrega ? substr((string) $this->hora_entrega, 0, 5) : null;
    }

    public function scopeAbiertos(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ABIERTOS);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'pedido_id')->orderBy('orden');
    }

    public function adelantos(): HasMany
    {
        return $this->hasMany(PedidoAdelanto::class, 'pedido_id')->orderBy('creado_en');
    }
}
