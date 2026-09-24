<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\TipoDocumentoIdentidad;
use App\Support\DocumentoIdentidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $filtros = $request->only(['buscar']);

        $clientes = Cliente::query()
            ->where('empresa_id', $empresaId)
            ->addSelect(['deuda' => CuentaPorCobrar::query()
                ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0)')
                ->whereColumn('cliente_id', 'clientes.id')
                ->where('estado', '!=', 'pagado'),
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', "%{$buscar}%")
                ->orWhere('numero_documento', 'ilike', "{$buscar}%")))
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'filtros' => $filtros,
            'tiposDocumento' => TipoDocumentoIdentidad::orderBy('codigo')->get(['codigo', 'nombre', 'longitud']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        // solo quien administra el credito puede abrir una linea al crear
        if (! $request->user()->can('clientes.credito')) {
            $datos['limite_credito'] = 0;
        }

        $cliente = Cliente::create([
            ...$datos,
            'empresa_id' => $request->user()->empresa_id,
        ]);

        if ((float) $datos['limite_credito'] > 0) {
            $this->auditarCredito($request, $cliente, 0.0, (float) $datos['limite_credito']);
        }

        return back()->with('success', 'Cliente creado.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        abort_unless($cliente->empresa_id === $request->user()->empresa_id, 403);

        $datos = $this->validar($request, $cliente);
        $limiteAntes = (float) $cliente->limite_credito;

        if (! $request->user()->can('clientes.credito')) {
            $datos['limite_credito'] = $limiteAntes;
        }

        $cliente->update($datos);

        if (abs((float) $datos['limite_credito'] - $limiteAntes) >= 0.005) {
            $this->auditarCredito($request, $cliente, $limiteAntes, (float) $datos['limite_credito']);
        }

        return back()->with('success', 'Cliente actualizado.');
    }

    /** Cambiar la linea de credito es dar plata fiada: queda constancia de quien y cuanto. */
    private function auditarCredito(Request $request, Cliente $cliente, float $de, float $a): void
    {
        Auditoria::registrar($request->user(), 'cliente.limite_credito', 'cliente', $cliente->id, [
            'cliente' => $cliente->nombre,
            'de' => $de,
            'a' => $a,
        ]);
    }

    public function destroy(Request $request, Cliente $cliente): RedirectResponse
    {
        abort_unless($cliente->empresa_id === $request->user()->empresa_id, 403);

        $tieneDeuda = CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->id)
            ->where('estado', '!=', 'pagado')
            ->exists();

        if ($tieneDeuda) {
            return back()->with('error', 'No se puede eliminar: el cliente tiene deudas pendientes.');
        }

        $cliente->delete();

        return back()->with('success', 'Cliente eliminado.');
    }

    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $empresaId = $request->user()->empresa_id;

        return $request->validate([
            'tipo_documento_codigo' => ['required', Rule::exists('tipos_documento_identidad', 'codigo')],
            'numero_documento' => [
                'nullable', 'string', 'max:15',
                DocumentoIdentidad::regla($request->input('tipo_documento_codigo')),
                Rule::unique('clientes', 'numero_documento')
                    ->where('empresa_id', $empresaId)
                    ->where('tipo_documento_codigo', $request->input('tipo_documento_codigo'))
                    ->whereNull('eliminado_en')
                    ->ignore($cliente?->id),
            ],
            'nombre' => ['required', 'string', 'max:200'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'limite_credito' => ['nullable', 'numeric', 'min:0'],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'numero_documento.unique' => 'Ya tienes un cliente con este documento.',
            'limite_credito.min' => 'El límite no puede ser negativo.',
            'email.email' => 'El correo no es válido.',
        ]);
    }
}
