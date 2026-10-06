<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Empresa extends Model
{
    use HasUuids;

    /** Afectacion con la que el Nuevo RUS emite lo gravado (catalogo 07: exonerado, operacion onerosa). */
    public const AFECTACION_RUS = '20';

    protected $table = 'empresas';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'ruc',
        'razon_social',
        'nombre_comercial',
        'regimen_tributario',
        'rubro_codigo',
        'logo_url',
        'cuentas_bancarias',
        'puntos_activo',
        'puntos_soles_por_punto',
        'puntos_valor',
        'puntos_minimo_canje',
        'tienda_slug',
        'tienda_publicada',
        'tienda_config',
        'gre_client_id',
        'gre_client_secret',
        'usuario_sol',
        'certificado_digital',
        'clave_sol',
        'clave_certificado',
        'facturacion_electronica',
        'entorno_sunat',
        'certificado_vence_en',
        'activo',
    ];

    protected $hidden = [
        'certificado_digital',
        'clave_sol',
        'clave_certificado',
        'gre_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'facturacion_electronica' => 'boolean',
            'puntos_activo' => 'boolean',
            'puntos_soles_por_punto' => 'decimal:2',
            'puntos_valor' => 'decimal:2',
            'puntos_minimo_canje' => 'integer',
            'tienda_publicada' => 'boolean',
            'tienda_config' => 'array',
            'clave_sol' => 'encrypted',
            'gre_client_secret' => 'encrypted',
            'clave_certificado' => 'encrypted',
            // la llave privada de firma no puede quedar en claro en un backup
            'certificado_digital' => 'encrypted',
            'certificado_vence_en' => 'date',
        ];
    }

    /**
     * Logo listo para incrustar en PDFs: data-URI si es un archivo subido,
     * la URL tal cual si es externa, o null si no hay logo.
     */
    public function logoParaPdf(): ?string
    {
        if (! $this->logo_url) {
            return null;
        }

        if (! str_starts_with($this->logo_url, '/storage/')) {
            return $this->logo_url;
        }

        $ruta = substr($this->logo_url, strlen('/storage/'));
        $disco = Storage::disk('public');

        if (! $disco->exists($ruta)) {
            return null;
        }

        return 'data:'.$disco->mimeType($ruta).';base64,'.base64_encode($disco->get($ruta));
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class, 'rubro_codigo', 'codigo');
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class, 'empresa_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'empresa_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'empresa_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'empresa_id');
    }

    public function proveedores(): HasMany
    {
        return $this->hasMany(Proveedor::class, 'empresa_id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class, 'empresa_id');
    }

    public function marcas(): HasMany
    {
        return $this->hasMany(Marca::class, 'empresa_id');
    }

    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class, 'empresa_id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'empresa_id');
    }

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class, 'empresa_id');
    }

    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class, 'empresa_id');
    }

    /** Nuevo RUS: solo boletas (nunca facturas) y sin discriminar IGV. */
    public function esRus(): bool
    {
        return $this->regimen_tributario === 'RUS';
    }
}
