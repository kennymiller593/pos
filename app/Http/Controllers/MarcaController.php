<?php

namespace App\Http\Controllers;

use App\Models\Marca;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarcaController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Marca::create([
            ...$this->validar($request),
            'empresa_id' => $request->user()->empresa_id,
        ]);

        return back()->with('success', 'Marca creada.');
    }

    public function update(Request $request, Marca $marca): RedirectResponse
    {
        abort_unless($marca->empresa_id === $request->user()->empresa_id, 403);

        $marca->update($this->validar($request, $marca));

        return back()->with('success', 'Marca actualizada.');
    }

    public function destroy(Request $request, Marca $marca): RedirectResponse
    {
        abort_unless($marca->empresa_id === $request->user()->empresa_id, 403);

        if ($marca->productos()->exists()) {
            return back()->with('error', 'No se puede eliminar: hay productos con esta marca.');
        }

        $marca->delete();

        return back()->with('success', 'Marca eliminada.');
    }

    private function validar(Request $request, ?Marca $marca = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:100',
                Rule::unique('marcas', 'nombre')
                    ->where('empresa_id', $request->user()->empresa_id)
                    ->ignore($marca?->id),
            ],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'nombre.unique' => 'Ya existe una marca con ese nombre.',
        ]);
    }
}
