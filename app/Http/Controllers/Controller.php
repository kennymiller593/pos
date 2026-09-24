<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Sucursal elegida en el selector de la barra superior; null = todas.
     */
    protected function sucursalConsultaId(Request $request): ?string
    {
        $id = $request->session()->get('sucursal_activa_id');

        if (! $id) {
            return null;
        }

        $permitidas = $request->user()->sucursalesPermitidas();
        if ($permitidas && ! in_array($id, $permitidas, true)) {
            return null;
        }

        return $id;
    }

    /**
     * Sucursal concreta para pantallas que trabajan sobre una sola sede
     * (stock, compras, kardex): selector > predeterminada > primera activa.
     */
    protected function sucursalDeTrabajo(Request $request): ?string
    {
        return $this->sucursalConsultaId($request)
            ?? $request->user()->sucursal_id
            ?? Sucursal::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->where('activo', true)
                ->value('id');
    }
}
