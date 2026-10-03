<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecorridoController extends Controller
{
    /** El usuario terminó (u omitió) el recorrido guiado de bienvenida: no se le vuelve a abrir solo. */
    public function visto(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        if ($usuario->recorrido_visto_en === null) {
            $usuario->forceFill(['recorrido_visto_en' => now()])->save();
        }

        return back();
    }
}
