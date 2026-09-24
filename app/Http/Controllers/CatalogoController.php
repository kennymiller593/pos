<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Marca;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogoController extends Controller
{
    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;

        return Inertia::render('Catalogos/Index', [
            'categorias' => Categoria::query()
                ->where('empresa_id', $empresaId)
                ->select('id', 'nombre', 'padre_id')
                ->with('padre:id,nombre')
                ->withCount('productos')
                ->orderBy('nombre')
                ->get(),
            'marcas' => Marca::query()
                ->where('empresa_id', $empresaId)
                ->select('id', 'nombre')
                ->withCount('productos')
                ->orderBy('nombre')
                ->get(),
        ]);
    }
}
