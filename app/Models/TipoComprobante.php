<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoComprobante extends Model
{
    protected $table = 'tipos_comprobante';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'es_electronico',
    ];

    protected function casts(): array
    {
        return [
            'es_electronico' => 'boolean',
        ];
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'tipo_comprobante_codigo', 'codigo');
    }
}
