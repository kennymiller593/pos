<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprobanteSunat extends Model
{
    protected $table = 'comprobantes_sunat';

    protected $primaryKey = 'comprobante_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const CREATED_AT = null;
    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'comprobante_id',
        'estado',
        'hash_cpe',
        'xml_url',
        'cdr_url',
        'pdf_url',
        'ticket',
        'mensaje_sunat',
        'intentos',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'enviado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }
}
