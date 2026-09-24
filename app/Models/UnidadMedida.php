<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'nombre',
        'permite_decimales',
    ];

    protected function casts(): array
    {
        return [
            'permite_decimales' => 'boolean',
        ];
    }
}
