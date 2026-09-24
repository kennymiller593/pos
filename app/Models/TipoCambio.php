<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoCambio extends Model
{
    protected $table = 'tipos_cambio';

    protected $primaryKey = 'fecha';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'fecha',
        'compra',
        'venta',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'compra' => 'decimal:4',
            'venta' => 'decimal:4',
        ];
    }
}
