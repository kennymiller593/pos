<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\UnidadMedida;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Catálogo de unidades de medida (códigos del catálogo 03 de SUNAT), común a todas las empresas. */
class UnidadMedidaController extends Controller
{
    public function index(): Response
    {
        $enProductos = DB::table('productos')->selectRaw('unidad_base_codigo AS codigo, COUNT(*) AS n')->groupBy('unidad_base_codigo')->pluck('n', 'codigo');
        $enPresentaciones = DB::table('producto_presentaciones')->selectRaw('unidad_codigo AS codigo, COUNT(*) AS n')->groupBy('unidad_codigo')->pluck('n', 'codigo');

        return Inertia::render('Admin/Unidades/Index', [
            'unidades' => UnidadMedida::query()->orderByDesc('activo')->orderBy('descripcion_sunat')->get()
                ->map(fn ($u) => [
                    'codigo' => $u->codigo,
                    'nombre' => $u->nombre,
                    'descripcion_sunat' => $u->descripcion_sunat,
                    'permite_decimales' => $u->permite_decimales,
                    'activo' => $u->activo,
                    'en_uso' => (int) ($enProductos[$u->codigo] ?? 0) + (int) ($enPresentaciones[$u->codigo] ?? 0),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'regex:/^[A-Z0-9]{2,5}$/', Rule::unique('unidades_medida', 'codigo')],
            'nombre' => ['required', 'string', 'max:80', Rule::unique('unidades_medida', 'nombre')],
            'descripcion_sunat' => ['required', 'string', 'max:80'],
            'permite_decimales' => ['required', 'boolean'],
            'activo' => ['required', 'boolean'],
        ], [
            'codigo.regex' => 'El código va en mayúsculas, de 2 a 5 letras o números, tal como figura en el catálogo 03 de SUNAT (ej. NIU, KGM, BX).',
            'codigo.unique' => 'Ese código ya existe.',
            'nombre.unique' => 'Ya hay una unidad con ese nombre.',
        ]);

        $datos['descripcion_sunat'] = mb_strtoupper($datos['descripcion_sunat']);
        $unidad = UnidadMedida::create($datos);

        Auditoria::registrar($request->user(), 'plataforma.unidad_creada', 'unidad_medida', null, $datos);

        return back()->with('success', "Unidad {$unidad->codigo} - {$unidad->descripcion_sunat} creada.");
    }

    public function update(Request $request, UnidadMedida $unidad): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('unidades_medida', 'nombre')->ignore($unidad->codigo, 'codigo')],
            'descripcion_sunat' => ['required', 'string', 'max:80'],
            'permite_decimales' => ['required', 'boolean'],
            'activo' => ['required', 'boolean'],
        ], [
            'nombre.unique' => 'Ya hay una unidad con ese nombre.',
        ]);

        // la unidad suelta de todos los productos: siempre disponible
        if ($unidad->codigo === 'NIU') {
            $datos['activo'] = true;
        }

        $datos['descripcion_sunat'] = mb_strtoupper($datos['descripcion_sunat']);
        $antes = $unidad->only(array_keys($datos));
        $unidad->update($datos);

        Auditoria::registrar($request->user(), 'plataforma.unidad_editada', 'unidad_medida', null, ['codigo' => $unidad->codigo, 'antes' => $antes, 'despues' => $datos]);

        return back()->with('success', "Unidad {$unidad->codigo} actualizada.");
    }
}
