<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoAfectacionIgv extends Model
{
    protected $table = 'tipos_afectacion_igv';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'afecto',
    ];

    protected function casts(): array
    {
        return [
            'afecto' => 'boolean',
        ];
    }
}
