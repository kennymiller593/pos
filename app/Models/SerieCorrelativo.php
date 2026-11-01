<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerieCorrelativo extends Model
{
    use HasUuids;

    protected $table = 'series_correlativos';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'caja_id',
        'tipo_comprobante_codigo',
        'serie',
        'correlativo',
    ];

    /**
     * Una serie está en uso solo si ya salió algún comprobante (o guía) con ella.
     * El correlativo inicial que escribe el admin (p. ej. 100 para continuar su numeración anterior) no cuenta.
     */
    public function enUso(): bool
    {
        if ($this->tipo_comprobante_codigo === '09') {
            return GuiaRemision::where('empresa_id', $this->empresa_id)->where('serie', $this->serie)->exists();
        }

        return Comprobante::where('empresa_id', $this->empresa_id)
            ->where('tipo_comprobante_codigo', $this->tipo_comprobante_codigo)
            ->where('serie', $this->serie)
            ->exists();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function tipoComprobante(): BelongsTo
    {
        return $this->belongsTo(TipoComprobante::class, 'tipo_comprobante_codigo', 'codigo');
    }
}
