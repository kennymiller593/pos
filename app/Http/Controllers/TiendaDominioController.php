<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Services\DominioTiendaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** El dominio propio de la tienda (www.agrocampo.com): el dueño lo registra, lo verifica y lo quita. */
class TiendaDominioController extends Controller
{
    public function __construct(private readonly DominioTiendaService $dominios) {}

    /** Es un adicional aparte de la tienda: sin él (o sin el servicio configurado) no hay nada que hacer aquí. */
    private function exigirAdicional(Request $request): void
    {
        $empresa = $request->user()->empresa;

        abort_unless($empresa->tienda_habilitada && $empresa->tienda_dominio_habilitado && DominioTiendaService::disponible(), 403);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $this->exigirAdicional($request);
        $empresa = $request->user()->empresa;

        $datos = $request->validate(['dominio' => ['required', 'string', 'max:260']], [
            'dominio.required' => 'Escribe tu dominio, por ejemplo www.tunegocio.com.',
        ]);

        $dominio = $this->dominios->normalizar($datos['dominio']);

        if (! $dominio) {
            return back()->withErrors(['dominio' => 'Eso no parece un dominio. Escríbelo como www.tunegocio.com.'])->withInput();
        }

        if ($motivo = $this->dominios->rechazo($dominio, $empresa)) {
            return back()->withErrors(['dominio' => $motivo])->withInput();
        }

        $nuevo = $empresa->tienda_dominio !== $dominio;
        $this->dominios->guardar($empresa, $dominio);

        if ($nuevo) {
            Auditoria::registrar($request->user(), 'tienda.dominio', 'empresa', $empresa->id, ['dominio' => $dominio]);
        }

        return back()->with('success', $this->mensaje($empresa->fresh()));
    }

    public function verificar(Request $request): RedirectResponse
    {
        $this->exigirAdicional($request);
        $empresa = $request->user()->empresa;

        abort_unless($empresa->tienda_dominio, 404);

        // mientras Cloudflare emite el certificado, "verificar" es volver a preguntarle ahora mismo
        if ($empresa->tienda_dominio_estado === 'verificando') {
            $this->dominios->revisar($empresa);
        } else {
            $this->dominios->verificar($empresa);
        }

        return back()->with('success', $this->mensaje($empresa->fresh()));
    }

    public function quitar(Request $request): RedirectResponse
    {
        $this->exigirAdicional($request);
        $empresa = $request->user()->empresa;
        $dominio = $empresa->tienda_dominio;

        $this->dominios->quitar($empresa);

        if ($dominio) {
            Auditoria::registrar($request->user(), 'tienda.dominio_quitado', 'empresa', $empresa->id, ['dominio' => $dominio]);
        }

        return back()->with('success', 'Tu tienda vuelve a atenderse solo en su dirección gratuita.');
    }

    private function mensaje($empresa): string
    {
        return match ($empresa->tienda_dominio_estado) {
            'activo' => "Listo: tu tienda ya atiende en https://{$empresa->tienda_dominio}.",
            'verificando' => 'El CNAME está bien. Estamos emitiendo el certificado de seguridad; suele tardar unos minutos.',
            'error' => 'No se pudo activar el dominio. Revisa el detalle.',
            default => 'Dominio guardado. Falta que el CNAME apunte a nosotros: sigue los pasos y luego pulsa "Verificar".',
        };
    }
}
