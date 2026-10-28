<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\ErrorCliente;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Errores avisados desde el navegador de los clientes, para soporte de la plataforma. */
class ErrorClienteController extends Controller
{
    public function index(Request $request): Response
    {
        // filtros: empresa, tipo y rango de fechas; un valor mal formado se ignora
        $filtros = [];
        if (Str::isUuid((string) $request->query('empresa'))) {
            $filtros['empresa'] = $request->query('empresa');
        }
        if (array_key_exists((string) $request->query('tipo'), ErrorCliente::TIPOS)) {
            $filtros['tipo'] = $request->query('tipo');
        }
        foreach (['desde', 'hasta'] as $campo) {
            $valor = (string) $request->query($campo);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor)) {
                $filtros[$campo] = $valor;
            }
        }

        $errores = ErrorCliente::query()
            ->when($filtros['empresa'] ?? null, fn ($q, $e) => $q->where('empresa_id', $e))
            ->when($filtros['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('creado_en', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('creado_en', '<=', $h))
            ->with(['empresa:id,nombre_comercial,razon_social', 'usuario:id,email,nombre_completo'])
            ->latest('creado_en')->orderByDesc('id') // uuid v7: desempata los del mismo segundo
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ErrorCliente $e) => [
                'id' => $e->id,
                'creado_en' => $e->creado_en,
                'empresa' => $e->empresa ? ($e->empresa->nombre_comercial ?: $e->empresa->razon_social) : '—',
                'usuario' => $e->usuario ? ($e->usuario->nombre_completo.' · '.$e->usuario->email) : '—',
                'tipo' => $e->tipo,
                'pantalla' => $e->pantalla,
                'accion' => $e->accion,
                'mensaje' => $e->mensaje,
                'detalle' => $e->detalle,
                'navegador' => $e->navegador,
                'ip' => $e->ip,
            ]);

        return Inertia::render('Admin/Errores/Index', [
            'errores' => $errores,
            'filtros' => $filtros,
            'tipos' => ErrorCliente::TIPOS,
            'empresas' => Empresa::query()->orderByRaw('coalesce(nombre_comercial, razon_social)')->get(['id', 'nombre_comercial', 'razon_social'])
                ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombre_comercial ?: $e->razon_social]),
        ]);
    }
}
