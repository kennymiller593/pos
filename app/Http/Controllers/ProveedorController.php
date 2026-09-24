<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProveedorController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->only(['buscar']);

        $proveedores = Proveedor::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->withCount('compras')
            ->addSelect(['total_comprado' => Compra::query()
                ->selectRaw('COALESCE(SUM(total), 0)')
                ->whereColumn('proveedor_id', 'proveedores.id'),
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(fn ($w) => $w
                ->where('razon_social', 'ilike', "%{$buscar}%")
                ->orWhere('ruc', 'ilike', "{$buscar}%")))
            ->orderBy('razon_social')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Proveedores/Index', [
            'proveedores' => $proveedores,
            'filtros' => $filtros,
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('buscar'));

        if ($buscar === '') {
            return response()->json([]);
        }

        return response()->json(
            Proveedor::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->where(fn ($q) => $q
                    ->where('razon_social', 'ilike', "%{$buscar}%")
                    ->orWhere('ruc', 'ilike', "{$buscar}%"))
                ->orderBy('razon_social')
                ->limit(10)
                ->get(['id', 'razon_social', 'ruc'])
        );
    }

    /** Crea un proveedor. Responde JSON al modal de compras e Inertia a la página. */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $proveedor = Proveedor::create([
            ...$this->validar($request),
            'empresa_id' => $request->user()->empresa_id,
        ]);

        if ($request->expectsJson()) {
            return response()->json($proveedor->only('id', 'razon_social', 'ruc'), 201);
        }

        return back()->with('success', 'Proveedor creado.');
    }

    public function update(Request $request, Proveedor $proveedor): RedirectResponse
    {
        abort_unless($proveedor->empresa_id === $request->user()->empresa_id, 403);

        $proveedor->update($this->validar($request, $proveedor));

        return back()->with('success', 'Proveedor actualizado.');
    }

    public function destroy(Request $request, Proveedor $proveedor): RedirectResponse
    {
        abort_unless($proveedor->empresa_id === $request->user()->empresa_id, 403);

        $proveedor->delete();

        return back()->with('success', 'Proveedor eliminado. Sus compras históricas se conservan.');
    }

    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        return $request->validate([
            'razon_social' => ['required', 'string', 'max:200'],
            'ruc' => [
                'nullable', 'digits:11',
                Rule::unique('proveedores', 'ruc')
                    ->where('empresa_id', $request->user()->empresa_id)
                    ->whereNull('eliminado_en')
                    ->ignore($proveedor?->id),
            ],
            'contacto' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ], [
            'razon_social.required' => 'Ingresa la razón social.',
            'ruc.digits' => 'El RUC debe tener 11 dígitos.',
            'ruc.unique' => 'Ya tienes un proveedor con este RUC.',
        ]);
    }
}
