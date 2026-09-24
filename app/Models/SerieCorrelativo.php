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
