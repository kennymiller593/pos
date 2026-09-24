<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\SerieCorrelativo;
use App\Models\Sucursal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Sucursales/Index', [
            'sucursales' => Sucursal::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->with([
                    'cajas' => fn ($q) => $q->orderBy('nombre')->with('aperturaAbierta.usuario:id,nombre_completo'),
                    'series' => fn ($q) => $q->orderBy('tipo_comprobante_codigo')->orderBy('serie')->with('caja:id,nombre'),
                    'ubigeoInfo',
                ])
                ->withCount('usuarios')
                ->orderByDesc('activo')
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $sucursal = Sucursal::create([
            ...$datos,
            'empresa_id' => $request->user()->empresa_id,
        ]);

        // toda sucursal necesita al menos una caja para operar
        Caja::create([
            'empresa_id' => $sucursal->empresa_id,
            'sucursal_id' => $sucursal->id,
            'nombre' => 'Caja 1',
            'activo' => true,
        ]);

        return back()->with('success', "Sucursal \"{$sucursal->nombre}\" creada con su Caja 1.");
    }

    public function update(Request $request, Sucursal $sucursal): RedirectResponse
    {
        abort_unless($sucursal->empresa_id === $request->user()->empresa_id, 403);

        $datos = $this->validar($request, $sucursal);

        if (! $datos['activo'] && $sucursal->activo) {
            $otrasActivas = Sucursal::query()
                ->where('empresa_id', $sucursal->empresa_id)
                ->where('id', '!=', $sucursal->id)
                ->where('activo', true)
                ->exists();

            if (! $otrasActivas) {
                return back()->with('error', 'No puedes desactivar la única sucursal activa.');
            }
        }

        $sucursal->update($datos);

        return back()->with('success', 'Sucursal actualizada.');
    }

    public function guardarCaja(Request $request, Sucursal $sucursal): RedirectResponse
    {
        abort_unless($sucursal->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'caja_id' => ['nullable', 'uuid', Rule::exists('cajas', 'id')->where('sucursal_id', $sucursal->id)],
            'nombre' => ['required', 'string', 'max:50'],
            'ancho_ticket' => ['required', 'integer', Rule::in([58, 80])],
            'activo' => ['required', 'boolean'],
        ], [
            'nombre.required' => 'Ingresa el nombre de la caja.',
            'ancho_ticket.in' => 'El ancho debe ser 58 u 80 mm.',
        ]);

        $duplicada = Caja::query()
            ->where('sucursal_id', $sucursal->id)
            ->where('nombre', $datos['nombre'])
            ->when($datos['caja_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($duplicada) {
            return back()->with('error', 'Ya existe una caja con ese nombre en la sucursal.');
        }

        if ($datos['caja_id'] ?? null) {
            $caja = Caja::findOrFail($datos['caja_id']);

            if (! $datos['activo'] && $caja->aperturaAbierta) {
                return back()->with('error', 'No puedes desactivar una caja con un turno abierto.');
            }

            $caja->update([
                'nombre' => $datos['nombre'],
                'ancho_ticket' => $datos['ancho_ticket'],
                'activo' => $datos['activo'],
            ]);

            return back()->with('success', 'Caja actualizada.');
        }

        Caja::create([
            'empresa_id' => $sucursal->empresa_id,
            'sucursal_id' => $sucursal->id,
            'nombre' => $datos['nombre'],
            'ancho_ticket' => $datos['ancho_ticket'],
            'activo' => $datos['activo'],
        ]);

        return back()->with('success', 'Caja creada.');
    }

    public function cambiarActiva(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'sucursal_id' => [
                'nullable', 'uuid',
                Rule::exists('sucursales', 'id')
                    ->where('empresa_id', $request->user()->empresa_id)
                    ->where('activo', true),
            ],
        ]);

        $id = $datos['sucursal_id'] ?? null;

        $permitidas = $request->user()->sucursalesPermitidas();
        if ($id && $permitidas && ! in_array($id, $permitidas, true)) {
            abort(403);
        }

        $request->session()->put('sucursal_activa_id', $id);

        return back();
    }

    public function guardarSerie(Request $request, Sucursal $sucursal): RedirectResponse
    {
        abort_unless($sucursal->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'serie_id' => ['nullable', 'uuid', Rule::exists('series_correlativos', 'id')->where('sucursal_id', $sucursal->id)],
            'tipo_comprobante_codigo' => ['required', Rule::in(['00', '03', '01'])],
            'serie' => ['required', 'string', 'size:4', 'regex:/^[A-Z][A-Z0-9]{3}$/'],
            'caja_id' => ['nullable', 'uuid', Rule::exists('cajas', 'id')->where('sucursal_id', $sucursal->id)],
            'correlativo' => ['required', 'integer', 'min:0', 'max:99999999'],
        ], [
            'serie.required' => 'Ingresa la serie.',
            'serie.size' => 'La serie debe tener 4 caracteres (ej. B002).',
            'serie.regex' => 'La serie debe empezar con letra y usar solo mayúsculas y números (ej. B002).',
            'correlativo.required' => 'Ingresa el correlativo.',
            'correlativo.min' => 'El correlativo no puede ser negativo.',
        ]);

        // prefijo segun SUNAT: boletas B, facturas F
        $prefijos = ['03' => 'B', '01' => 'F'];
        $prefijo = $prefijos[$datos['tipo_comprobante_codigo']] ?? null;
        if ($prefijo && ! str_starts_with($datos['serie'], $prefijo)) {
            return back()->with('error', "Las series de este comprobante deben empezar con \"{$prefijo}\" (ej. {$prefijo}002).");
        }

        // la serie es unica en toda la empresa
        $serieTomada = SerieCorrelativo::query()
            ->where('empresa_id', $sucursal->empresa_id)
            ->where('tipo_comprobante_codigo', $datos['tipo_comprobante_codigo'])
            ->where('serie', $datos['serie'])
            ->when($datos['serie_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->with('sucursal:id,nombre')
            ->first();

        if ($serieTomada) {
            return back()->with('error', "La serie {$datos['serie']} ya está en uso en la sucursal \"{$serieTomada->sucursal->nombre}\".");
        }

        // una sola serie por comprobante para cada caja (o para la sucursal en general)
        $duplicada = SerieCorrelativo::query()
            ->where('sucursal_id', $sucursal->id)
            ->where('tipo_comprobante_codigo', $datos['tipo_comprobante_codigo'])
            ->when(
                $datos['caja_id'] ?? null,
                fn ($q, $cajaId) => $q->where('caja_id', $cajaId),
                fn ($q) => $q->whereNull('caja_id'),
            )
            ->when($datos['serie_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($duplicada) {
            return back()->with('error', ($datos['caja_id'] ?? null)
                ? 'Esa caja ya tiene una serie para este comprobante.'
                : 'La sucursal ya tiene una serie general para este comprobante.');
        }

        if ($datos['serie_id'] ?? null) {
            $serie = SerieCorrelativo::findOrFail($datos['serie_id']);

            if ($serie->correlativo > 0 && $serie->serie !== $datos['serie']) {
                return back()->with('error', 'No puedes cambiar el código de una serie que ya emitió comprobantes.');
            }
            if ($datos['correlativo'] < $serie->correlativo) {
                return back()->with('error', "El correlativo no puede retroceder (actual: {$serie->correlativo}).");
            }

            $serie->update([
                'serie' => $datos['serie'],
                'caja_id' => $datos['caja_id'] ?? null,
                'correlativo' => $datos['correlativo'],
            ]);

            return back()->with('success', "Serie {$serie->serie} actualizada.");
        }

        SerieCorrelativo::create([
            'empresa_id' => $sucursal->empresa_id,
            'sucursal_id' => $sucursal->id,
            'caja_id' => $datos['caja_id'] ?? null,
            'tipo_comprobante_codigo' => $datos['tipo_comprobante_codigo'],
            'serie' => $datos['serie'],
            'correlativo' => $datos['correlativo'],
        ]);

        return back()->with('success', "Serie {$datos['serie']} creada. El próximo comprobante será {$datos['serie']}-" . ($datos['correlativo'] + 1) . '.');
    }

    public function eliminarSerie(Request $request, Sucursal $sucursal, SerieCorrelativo $serie): RedirectResponse
    {
        abort_unless($sucursal->empresa_id === $request->user()->empresa_id, 403);
        abort_unless($serie->sucursal_id === $sucursal->id, 404);

        if ($serie->correlativo > 0) {
            return back()->with('error', 'No puedes eliminar una serie que ya emitió comprobantes.');
        }

        $serie->delete();

        return back()->with('success', "Serie {$serie->serie} eliminada.");
    }

    private function validar(Request $request, ?Sucursal $sucursal = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'codigo_sunat' => [
                'required', 'digits:4',
                Rule::unique('sucursales', 'codigo_sunat')
                    ->where('empresa_id', $request->user()->empresa_id)
                    ->ignore($sucursal?->id),
            ],
            'direccion' => ['nullable', 'string', 'max:250'],
            'ubigeo' => ['nullable', 'digits:6', Rule::exists('ubigeos', 'codigo')],
            'telefono' => ['nullable', 'string', 'max:20'],
            'activo' => ['required', 'boolean'],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'codigo_sunat.required' => 'Ingresa el código de anexo SUNAT (4 dígitos).',
            'codigo_sunat.digits' => 'El código SUNAT debe tener 4 dígitos.',
            'codigo_sunat.unique' => 'Ya usas ese código en otra sucursal.',
            'ubigeo.digits' => 'El ubigeo debe tener 6 dígitos.',
            'ubigeo.exists' => 'Ese ubigeo no está en el catálogo: usa el buscador.',
        ]);
    }
}
