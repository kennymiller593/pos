<?php

namespace App\Models;

use App\Support\Permisos;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $table = 'usuarios';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'rol_id',
        'email',
        'password_hash',
        'nombre_completo',
        'activo',
        'email_verificado_en',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'password_hash' => 'hashed',
            'email_verificado_en' => 'datetime',
            'creado_en' => 'datetime',
            // no es fillable a proposito: solo se asigna con superadmin:asignar
            'es_superadmin' => 'boolean',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function sucursales(): BelongsToMany
    {
        return $this->belongsToMany(Sucursal::class, 'usuario_sucursales', 'usuario_id', 'sucursal_id');
    }

    /** Código del rol (admin, cajero, vendedor, almacenero). */
    public function codigoRol(): ?string
    {
        return $this->loadMissing('rol')->rol?->codigo;
    }

    public function esAdmin(): bool
    {
        return $this->codigoRol() === 'admin';
    }

    /** Permiso según la matriz de App\Support\Permisos (es lo que consulta Gate). */
    public function puede(string $permiso): bool
    {
        return Permisos::tiene($this->codigoRol(), $permiso);
    }

    /** @return list<string> */
    public function permisos(): array
    {
        return Permisos::deRol($this->codigoRol());
    }

    /**
     * Ids de las sucursales donde trabaja; null = sin restricción (todas).
     */
    public function sucursalesPermitidas(): ?array
    {
        $ids = $this->sucursales()->pluck('sucursales.id')->all();

        return $ids ?: null;
    }

    /**
     * Tablas donde queda registro de lo que hizo un usuario (tabla => columna).
     * Si aparece en cualquiera, no se puede eliminar: solo desactivar.
     */
    public const TABLAS_HISTORIAL = [
        'comprobantes' => ['usuario_id', 'anulado_por'],
        'pagos' => ['usuario_id'],
        'cobros' => ['usuario_id'],
        'aperturas_caja' => ['usuario_id'],
        'movimientos_caja' => ['usuario_id'],
        'movimientos_inventario' => ['usuario_id'],
        'compras' => ['usuario_id', 'anulada_por'],
        'pagos_proveedor' => ['usuario_id'],
        'transferencias' => ['usuario_id'],
        'auditoria' => ['usuario_id'],
    ];

    /** SQL que vale TRUE si el usuario de la fila ("usuarios.id") tiene historial. */
    public static function sqlTieneHistorial(): string
    {
        $existe = [];
        foreach (self::TABLAS_HISTORIAL as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $existe[] = "EXISTS (SELECT 1 FROM {$tabla} WHERE {$tabla}.{$columna} = usuarios.id)";
            }
        }

        return '('.implode(' OR ', $existe).')';
    }

    public function tieneHistorial(): bool
    {
        return (bool) static::query()
            ->whereKey($this->id)
            ->selectRaw(static::sqlTieneHistorial().' AS con_historial')
            ->value('con_historial');
    }
}
