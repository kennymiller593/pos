<?php

namespace App\Http\Controllers;

use App\Models\ErrorCliente;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Recibe los avisos del navegador (ver resources/js/reporteErrores.js). */
class ErrorClienteController extends Controller
{
    public function store(Request $request): Response
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ErrorCliente::TIPOS))],
            'mensaje' => ['required', 'string', 'max:2000'],
            'pantalla' => ['nullable', 'string', 'max:1000'],
            'accion' => ['nullable', 'string', 'max:1000'],
            'detalle' => ['nullable', 'array'],
        ]);

        $usuario = $request->user();
        // el detalle se recorta para que un aviso no pese más que un par de pantallas de texto
        $detalle = collect($datos['detalle'] ?? [])->map(fn ($v) => is_string($v) ? Str::limit($v, 1500, '…') : $v)->take(12)->all();

        $error = ErrorCliente::create([
            'empresa_id' => $usuario->empresa_id,
            'usuario_id' => $usuario->id,
            'tipo' => $datos['tipo'],
            'pantalla' => Str::limit($datos['pantalla'] ?? '', 300, '') ?: null,
            'accion' => Str::limit($datos['accion'] ?? '', 300, '') ?: null,
            'mensaje' => mb_substr($datos['mensaje'], 0, 500),
            'detalle' => $detalle ?: null,
            'navegador' => Str::limit((string) $request->userAgent(), 300, ''),
            'ip' => $request->ip(),
        ]);

        Log::channel('cliente')->warning("[{$error->tipo}] {$error->mensaje}", [
            'empresa' => $usuario->empresa?->nombre_comercial ?? $usuario->empresa?->razon_social,
            'usuario' => $usuario->email,
            'pantalla' => $error->pantalla,
            'accion' => $error->accion,
            'detalle' => $error->detalle,
        ]);

        return response()->noContent();
    }
}
