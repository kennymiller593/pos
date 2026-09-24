<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Rubro;
use App\Models\SerieCorrelativo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->autorizarAdmin($request);

        $empresa = $request->user()->empresa;

        return Inertia::render('Empresa/Editar', [
            'empresa' => [
                'ruc' => $empresa->ruc,
                'razon_social' => $empresa->razon_social,
                'nombre_comercial' => $empresa->nombre_comercial,
                'regimen_tributario' => $empresa->regimen_tributario,
                'rubro_codigo' => $empresa->rubro_codigo,
                'logo_url' => $empresa->logo_url,
                'usuario_sol' => $empresa->usuario_sol,
                'tiene_certificado' => filled($empresa->certificado_digital),
                'tiene_clave_sol' => filled($empresa->clave_sol),
                'tiene_clave_certificado' => filled($empresa->clave_certificado),
                'facturacion_electronica' => (bool) $empresa->facturacion_electronica,
                'entorno_sunat' => $empresa->entorno_sunat,
            ],
            'rubros' => Rubro::orderBy('nombre')->get(['codigo', 'nombre']),
            'requisitosFacturacion' => $this->requisitosFacturacion($request),
        ]);
    }

    /**
     * Enciende o apaga la facturación electrónica. Para encenderla,
     * el checklist de requisitos debe estar completo.
     */
    public function alternarFacturacion(Request $request): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $empresa = $request->user()->empresa;

        if ($empresa->facturacion_electronica) {
            $empresa->update(['facturacion_electronica' => false]);
            Auditoria::registrar($request->user(), 'empresa.facturacion_desactivada', 'empresa', $empresa->id);

            return back()->with('success', 'Facturación electrónica desactivada. El POS volverá a emitir solo notas de venta.');
        }

        $faltantes = collect($this->requisitosFacturacion($request))
            ->filter(fn ($cumplido) => ! $cumplido)
            ->keys();

        if ($faltantes->isNotEmpty()) {
            $etiquetas = [
                'certificado' => 'el certificado digital',
                'clave_certificado' => 'la contraseña del certificado',
                'credenciales_sol' => 'el usuario y la clave SOL',
                'serie' => 'una serie de boleta (B...) o factura (F...) en Sucursales',
            ];

            return back()->with('error', 'Para activar la facturación falta: '
                .$faltantes->map(fn ($clave) => $etiquetas[$clave] ?? $clave)->implode(', ').'.');
        }

        $empresa->update(['facturacion_electronica' => true]);
        Auditoria::registrar($request->user(), 'empresa.facturacion_activada', 'empresa', $empresa->id, [
            'entorno' => $empresa->entorno_sunat,
        ]);

        return back()->with('success', 'Facturación electrónica activada en entorno '
            .($empresa->entorno_sunat === 'produccion' ? 'de producción' : 'beta (pruebas)').'.');
    }

    /** Checklist para poder facturar electronicamente. */
    private function requisitosFacturacion(Request $request): array
    {
        $empresa = $request->user()->empresa;

        return [
            'certificado' => filled($empresa->certificado_digital),
            'clave_certificado' => filled($empresa->clave_certificado),
            'credenciales_sol' => filled($empresa->usuario_sol) && filled($empresa->clave_sol),
            'serie' => SerieCorrelativo::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('tipo_comprobante_codigo', ['01', '03'])
                ->exists(),
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $datos = $request->validate([
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'regimen_tributario' => ['required', Rule::in(['RUS', 'RER', 'MYPE', 'GENERAL'])],
            'rubro_codigo' => ['required', Rule::exists('rubros', 'codigo')],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'logo_eliminar' => ['nullable', 'boolean'],
            'usuario_sol' => ['nullable', 'string', 'max:50'],
            'certificado_digital' => ['nullable', 'string'],
            'clave_sol' => ['nullable', 'string', 'max:100'],
            'clave_certificado' => ['nullable', 'string', 'max:100'],
            'entorno_sunat' => ['nullable', Rule::in(['beta', 'produccion'])],
        ], [
            'razon_social.required' => 'Ingresa la razón social.',
            'rubro_codigo.required' => 'Elige el rubro.',
            'logo.image' => 'El logo debe ser una imagen.',
            'logo.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'logo.max' => 'El logo no debe pesar más de 2 MB.',
        ]);

        $empresa = $request->user()->empresa;

        if ($request->hasFile('logo')) {
            $ruta = $request->file('logo')->store('logos', 'public');
            $this->eliminarLogoLocal($empresa->logo_url);
            $datos['logo_url'] = Storage::url($ruta);
        } elseif ($request->boolean('logo_eliminar')) {
            $this->eliminarLogoLocal($empresa->logo_url);
            $datos['logo_url'] = null;
        }

        unset($datos['logo'], $datos['logo_eliminar']);

        // credenciales de solo escritura: si vienen vacias, se conserva la actual
        foreach (['certificado_digital', 'clave_sol', 'clave_certificado'] as $campo) {
            if (blank($datos[$campo] ?? null)) {
                unset($datos[$campo]);
            }
        }
        if (blank($datos['entorno_sunat'] ?? null)) {
            unset($datos['entorno_sunat']);
        }

        $empresa->update($datos);

        return back()->with('success', 'Datos de la empresa actualizados.');
    }

    private function autorizarAdmin(Request $request): void
    {
        abort_unless($request->user()->can('empresa.gestionar'), 403);
    }

    /** Borra el archivo del logo solo si fue subido a nuestro almacenamiento. */
    private function eliminarLogoLocal(?string $url): void
    {
        if ($url && str_starts_with($url, '/storage/')) {
            Storage::disk('public')->delete(substr($url, strlen('/storage/')));
        }
    }
}
