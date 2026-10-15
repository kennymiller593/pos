<?php

namespace App\Support;

use App\Models\AperturaCaja;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * La sucursal "actual" del usuario, resuelta una sola vez por petición y usada igual en
 * todas las pantallas: la elegida en el selector de la barra superior; si solo puede entrar
 * a una, esa; si no eligió ninguna, todas las que tiene permitidas.
 *
 * Con esto el dashboard, los reportes, las listas y las pantallas de trabajo (stock, compras,
 * transferencias) muestran siempre lo mismo que dice el selector.
 */
final class ContextoSucursal
{
    /** @var list<string>|null ids permitidos; null = sin restricción (todas las de la empresa) */
    public readonly ?array $permitidas;

    /** @var Collection<int, Sucursal> sucursales activas a las que puede entrar (id, nombre) */
    public readonly Collection $accesibles;

    /** La empresa tiene más de una sucursal activa (decide si se muestra el selector y las columnas "Sucursal"). */
    public readonly bool $multisucursal;

    /** @var array{sucursal_id: string, sucursal_nombre: string, caja_nombre: string}|null caja abierta por el usuario */
    public readonly ?array $cajaAbierta;

    /** Sucursal elegida (válida); null = todas las accesibles. */
    public readonly ?string $consultaId;

    private function __construct(Request $request)
    {
        $usuario = $request->user();

        $this->permitidas = $usuario->sucursalesPermitidas();

        $activas = Sucursal::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $this->multisucursal = $activas->count() > 1;
        $this->accesibles = $this->permitidas
            ? $activas->whereIn('id', $this->permitidas)->values()
            : $activas;

        $apertura = AperturaCaja::query()
            ->where('usuario_id', $usuario->id)
            ->whereNull('cerrada_en')
            ->with(['caja:id,nombre,sucursal_id', 'caja.sucursal:id,nombre'])
            ->latest('abierta_en')
            ->first();
        $this->cajaAbierta = $apertura?->caja?->sucursal ? [
            'sucursal_id' => $apertura->caja->sucursal_id,
            'sucursal_nombre' => $apertura->caja->sucursal->nombre,
            'caja_nombre' => $apertura->caja->nombre,
        ] : null;

        $elegida = $request->session()->get('sucursal_activa_id');
        if ($elegida && ! $this->esAccesible($elegida)) {
            $elegida = null;
        }
        // quien solo puede entrar a una sucursal siempre está en esa, elija o no
        if (! $elegida && $this->accesibles->count() === 1) {
            $elegida = $this->accesibles->first()->id;
        }
        $this->consultaId = $elegida;
    }

    public static function de(Request $request): self
    {
        $contexto = $request->attributes->get('contexto_sucursal');
        if (! $contexto instanceof self) {
            $contexto = new self($request);
            $request->attributes->set('contexto_sucursal', $contexto);
        }

        return $contexto;
    }

    public function esAccesible(string $id): bool
    {
        return $this->accesibles->contains('id', $id);
    }

    /**
     * Ids para filtrar consultas: la elegida, o todas las permitidas; null = sin filtro (toda la empresa).
     *
     * @return list<string>|null
     */
    public function consultaIds(): ?array
    {
        if ($this->consultaId) {
            return [$this->consultaId];
        }

        return $this->permitidas;
    }

    /**
     * Sucursal concreta para pantallas que trabajan sobre una sola (stock, compras, transferencias).
     * null = está viendo "todas" y la pantalla debe pedirle que elija.
     */
    public function trabajoId(): ?string
    {
        return $this->consultaId;
    }

    /**
     * Sucursal por defecto cuando hace falta una y no eligió: su predeterminada si puede entrar, o la primera.
     */
    public function porDefectoId(Request $request): ?string
    {
        $predeterminada = $request->user()->sucursal_id;
        if ($predeterminada && $this->esAccesible($predeterminada)) {
            return $predeterminada;
        }

        return $this->accesibles->first()?->id;
    }

    public function nombreDe(?string $id): ?string
    {
        return $id ? $this->accesibles->firstWhere('id', $id)?->nombre : null;
    }

    /** Etiqueta de lo que está viendo: el nombre de la sucursal o "Todas las sucursales". */
    public function etiqueta(): string
    {
        if ($this->consultaId) {
            return $this->nombreDe($this->consultaId) ?? 'Sucursal';
        }

        return $this->permitidas ? 'Todas mis sucursales' : 'Todas las sucursales';
    }

    /** Lo que comparte Inertia con el layout. */
    public function paraLayout(): array
    {
        return [
            'sucursales_accesibles' => $this->accesibles->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre])->values()->all(),
            'sucursal_consulta_id' => $this->consultaId,
            'sucursal_etiqueta' => $this->etiqueta(),
            'puede_ver_todas' => $this->permitidas === null,
            'caja_abierta' => $this->cajaAbierta,
        ];
    }
}
