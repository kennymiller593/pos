<?php

namespace App\Http\Controllers;

use App\Mail\ReclamacionRegistrada;
use App\Models\Reclamacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/** El Libro de Reclamaciones de la tienda en línea, del lado del dueño: ver las hojas y responderlas. */
class ReclamacionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->empresa->tienda_habilitada, 403);
        $estado = in_array($request->query('estado'), ['pendiente', 'respondido'], true) ? $request->query('estado') : null;

        $hojas = Reclamacion::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('numero')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Reclamacion $r) => [
                'id' => $r->id,
                'codigo' => $r->codigo(),
                'tipo' => $r->tipo,
                'estado' => $r->estado,
                'fecha' => $r->creado_en->timezone('America/Lima')->format('d/m/Y H:i'),
                'vence' => $r->venceEl()->format('d/m/Y'),
                'vencido' => $r->estado === 'pendiente' && $r->venceEl()->endOfDay()->isPast(),
                'nombre' => $r->nombre,
                'documento' => "{$r->documento_tipo} {$r->documento_numero}",
                'domicilio' => $r->domicilio,
                'email' => $r->email,
                'telefono' => $r->telefono,
                'apoderado' => $r->menor_de_edad ? ($r->apoderado ?: '—') : null,
                'bien' => ucfirst($r->bien_tipo).': '.$r->bien_descripcion,
                'monto' => $r->monto !== null ? (float) $r->monto : null,
                'detalle' => $r->detalle,
                'pedido' => $r->pedido,
                'respuesta' => $r->respuesta,
                'respondido_en' => $r->respondido_en?->timezone('America/Lima')->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Tienda/Reclamaciones', [
            'hojas' => $hojas,
            'estado' => $estado,
            'pendientes' => Reclamacion::where('empresa_id', $request->user()->empresa_id)->where('estado', 'pendiente')->count(),
            'plazo' => Reclamacion::PLAZO_DIAS_HABILES,
        ]);
    }

    /** Guarda la respuesta y se la envía al consumidor por correo. */
    public function responder(Request $request, Reclamacion $reclamacion): RedirectResponse
    {
        abort_unless($reclamacion->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate(['respuesta' => ['required', 'string', 'min:10', 'max:5000']], [
            'respuesta.required' => 'Escribe la respuesta para el cliente.',
            'respuesta.min' => 'La respuesta es muy corta.',
        ]);

        $reclamacion->forceFill([
            'respuesta' => trim($datos['respuesta']),
            'estado' => 'respondido',
            'respondido_en' => now(),
            'respondido_por' => $request->user()->id,
        ])->save();

        Mail::to($reclamacion->email)->queue(new ReclamacionRegistrada($reclamacion, 'respuesta'));

        return back()->with('success', "Respuesta enviada a {$reclamacion->email}.");
    }
}
