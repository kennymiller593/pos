<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Services\CobroQrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Configuración de los QR de cobro (Yape, Plin) que el POS muestra al cliente. */
class CobroQrController extends Controller
{
    public function __construct(private readonly CobroQrService $cobros) {}

    public function edit(Request $request): Response
    {
        $empresa = $request->user()->empresa;

        $sucursales = Sucursal::where('empresa_id', $empresa->id)->where('activo', true)->orderBy('nombre')->get();

        return Inertia::render('Empresa/CobrosQr', [
            'billeteras' => collect(CobroQrService::BILLETERAS)->map(fn ($nombre, $codigo) => ['codigo' => $codigo, 'nombre' => $nombre])->values(),
            'negocio' => $empresa->nombre_comercial ?: $empresa->razon_social,
            'empresa' => $this->cobros->propios($empresa),
            'sucursales' => $sucursales->map(fn (Sucursal $s) => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                'propios' => $this->cobros->propios($s),
            ]),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;

        // el número se guarda solo con dígitos: "987 654 321" y "987-654-321" valen igual
        if ($request->filled('numero')) {
            $request->merge(['numero' => preg_replace('/\D+/', '', (string) $request->input('numero'))]);
        }

        $datos = $request->validate([
            'ambito' => ['required', 'string', function ($atributo, $valor, $fallo) use ($empresa) {
                if ($valor !== 'empresa' && ! Sucursal::where('empresa_id', $empresa->id)->whereKey($valor)->exists()) {
                    $fallo('La sucursal no existe.');
                }
            }],
            'medio' => ['required', Rule::in(array_keys(CobroQrService::BILLETERAS))],
            'numero' => ['nullable', 'regex:/^9\d{8}$/'],
            'titular' => ['nullable', 'string', 'max:80'],
            'qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'qr_eliminar' => ['nullable', 'boolean'],
        ], [
            'numero.regex' => 'El celular debe tener 9 dígitos y empezar con 9.',
            'titular.max' => 'El nombre del titular no debe pasar de 80 caracteres.',
            'qr.image' => 'El QR debe ser una imagen.',
            'qr.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'qr.max' => 'La imagen del QR no debe pesar más de 4 MB.',
        ]);

        $ambito = $datos['ambito'] === 'empresa'
            ? $empresa
            : Sucursal::where('empresa_id', $empresa->id)->findOrFail($datos['ambito']);

        $this->cobros->guardar($ambito, $datos['medio'], $datos, $request->file('qr'), $request->boolean('qr_eliminar'));

        Auditoria::registrar($request->user(), 'empresa.cobro_qr', 'empresa', $empresa->id, [
            'medio' => $datos['medio'],
            'ambito' => $ambito instanceof Sucursal ? $ambito->nombre : 'empresa',
        ]);

        $nombre = CobroQrService::BILLETERAS[$datos['medio']];
        $quedo = $this->cobros->propios($ambito->refresh())[$datos['medio']];

        return back()->with('success', $quedo
            ? "{$nombre} guardado. Ya aparece al cobrar en el punto de venta."
            : ($ambito instanceof Sucursal ? "{$nombre}: esta sucursal usará el QR de la empresa." : "{$nombre} quitado."));
    }
}
