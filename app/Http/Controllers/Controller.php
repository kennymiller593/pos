<?php

namespace App\Http\Controllers;

use App\Support\ContextoSucursal;
use Illuminate\Http\Request;

abstract class Controller
{
    /** La sucursal actual del usuario (selector de la barra superior), resuelta una vez por petición. */
    protected function contextoSucursal(Request $request): ContextoSucursal
    {
        return ContextoSucursal::de($request);
    }

    /**
     * Sucursal elegida en el selector; null = todas las que puede ver.
     */
    protected function sucursalConsultaId(Request $request): ?string
    {
        return ContextoSucursal::de($request)->consultaId;
    }

    /**
     * Ids para filtrar lo que ve: la elegida o todas las permitidas; null = toda la empresa.
     *
     * @return list<string>|null
     */
    protected function sucursalesConsulta(Request $request): ?array
    {
        return ContextoSucursal::de($request)->consultaIds();
    }

    /**
     * Sucursal concreta para pantallas que trabajan sobre una sola sede (stock, compras,
     * transferencias): la elegida en el selector. null = está viendo "todas" y debe elegir.
     */
    protected function sucursalDeTrabajo(Request $request): ?string
    {
        return ContextoSucursal::de($request)->trabajoId();
    }

    /**
     * Sucursal para una acción concreta: la que mandó el formulario o la URL (si puede entrar a ella),
     * si no la del selector, si no la predeterminada. Así, viendo "todas", la pantalla ofrece elegirla
     * y nunca se toma una a escondidas sin mostrarla.
     */
    protected function sucursalElegida(Request $request, string $campo): ?string
    {
        $contexto = ContextoSucursal::de($request);
        $id = $request->input($campo) ?? $request->query($campo);
        if (is_string($id) && $id !== '' && $contexto->esAccesible($id)) {
            return $id;
        }

        return $contexto->trabajoId() ?? $contexto->porDefectoId($request);
    }

    /**
     * Sucursal por defecto cuando hace falta una y está viendo "todas": su predeterminada o la primera.
     */
    protected function sucursalPorDefecto(Request $request): ?string
    {
        return ContextoSucursal::de($request)->porDefectoId($request);
    }
}
