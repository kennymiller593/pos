<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoriaController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        Categoria::create([...$datos, 'empresa_id' => $request->user()->empresa_id]);

        return back()->with('success', 'Categoría creada.');
    }

    public function update(Request $request, Categoria $categoria): RedirectResponse
    {
        abort_unless($categoria->empresa_id === $request->user()->empresa_id, 403);

        $datos = $this->validar($request, $categoria);
        $this->evitarCiclos($categoria, $datos['padre_id'] ?? null);

        $categoria->update($datos);

        return back()->with('success', 'Categoría actualizada.');
    }

    public function destroy(Request $request, Categoria $categoria): RedirectResponse
    {
        abort_unless($categoria->empresa_id === $request->user()->empresa_id, 403);

        if ($categoria->productos()->exists()) {
            return back()->with('error', 'No se puede eliminar: hay productos en esta categoría.');
        }

        if ($categoria->hijos()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene subcategorías.');
        }

        $categoria->delete();

        return back()->with('success', 'Categoría eliminada.');
    }

    private function validar(Request $request, ?Categoria $categoria = null): array
    {
        $empresaId = $request->user()->empresa_id;

        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('categorias', 'nombre')->where('empresa_id', $empresaId)->ignore($categoria?->id),
            ],
            'padre_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'nombre.unique' => 'Ya existe una categoría con ese nombre.',
        ]);
    }

    /** Impide que una categoría sea su propio ancestro. */
    private function evitarCiclos(Categoria $categoria, ?string $padreId): void
    {
        while ($padreId !== null) {
            if ($padreId === $categoria->id) {
                throw ValidationException::withMessages([
                    'padre_id' => 'La categoría padre no puede ser la misma categoría ni una de sus subcategorías.',
                ]);
            }
            $padreId = Categoria::whereKey($padreId)->value('padre_id');
        }
    }
}
