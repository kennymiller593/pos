<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Rubro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Rubros de negocio que se ofrecen al registrar una empresa. */
class RubroController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Rubros/Index', [
            'rubros' => Rubro::query()->withCount('empresas')->orderByDesc('activo')->orderBy('nombre')->get()
                ->map(fn ($r) => [
                    'codigo' => $r->codigo,
                    'nombre' => $r->nombre,
                    'activo' => $r->activo,
                    'empresas' => (int) $r->empresas_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('rubros', 'nombre')],
            'activo' => ['required', 'boolean'],
        ], ['nombre.unique' => 'Ya existe un rubro con ese nombre.']);

        // "Panadería y pastelería" -> "panaderia_y_pasteleria" (con sufijo si ya existe)
        $base = Str::limit(Str::slug($datos['nombre'], '_'), 16, '') ?: 'rubro';
        $codigo = $base;
        for ($i = 2; Rubro::where('codigo', $codigo)->exists(); $i++) {
            $codigo = "{$base}_{$i}";
        }

        $rubro = Rubro::create([...$datos, 'codigo' => $codigo]);
        Auditoria::registrar($request->user(), 'plataforma.rubro_creado', 'rubro', null, ['codigo' => $rubro->codigo, ...$datos]);

        return back()->with('success', "Rubro {$rubro->nombre} creado.");
    }

    public function update(Request $request, Rubro $rubro): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('rubros', 'nombre')->ignore($rubro->codigo, 'codigo')],
            'activo' => ['required', 'boolean'],
        ], ['nombre.unique' => 'Ya existe un rubro con ese nombre.']);

        $antes = $rubro->only(['nombre', 'activo']);
        $rubro->update($datos);
        Auditoria::registrar($request->user(), 'plataforma.rubro_editado', 'rubro', null, ['codigo' => $rubro->codigo, 'antes' => $antes, 'despues' => $datos]);

        return back()->with('success', "Rubro {$rubro->nombre} actualizado.");
    }
}
