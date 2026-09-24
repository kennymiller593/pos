<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Services\TransferenciaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransferenciaController extends Controller
{
    public function __construct(private readonly TransferenciaService $transferencias) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Transferencias/Index', [
            'transferencias' => Transferencia::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->with([
                    'sucursalOrigen:id,nombre',
                    'sucursalDestino:id,nombre',
                    'usuario:id,nombre_completo',
                    'detalles.producto:id,nombre',
                    'detalles.lote:id,numero_lote,fecha_vencimiento',
                ])
                ->latest('creado_en')
                ->paginate(10)
                ->withQueryString(),
        ]);
    }

    public function crear(Request $request): Response
    {
        $usuario = $request->user();
        $origenId = $this->sucursalOrigen($request);

        $productos = Producto::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->withSum(['stock as stock' => fn ($q) => $q->where('sucursal_id', $origenId)], 'cantidad')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'permite_fraccion']);

        return Inertia::render('Transferencias/Crear', [
            'origen' => Sucursal::find($origenId)?->only('id', 'nombre'),
            'destinos' => Sucursal::query()
                ->where('empresa_id', $usuario->empresa_id)
                ->where('activo', true)
                ->where('id', '!=', $origenId)
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'permite_fraccion' => $p->permite_fraccion,
                'stock' => (float) ($p->stock ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'sucursal_destino_id' => ['required', 'uuid'],
            'observacion' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ], [
            'sucursal_destino_id.required' => 'Elige la sucursal de destino.',
            'items.required' => 'Agrega al menos un producto.',
        ]);

        $origenId = $this->sucursalOrigen($request);

        if (! $origenId) {
            return back()->with('error', 'No tienes una sucursal asignada.');
        }

        try {
            $this->transferencias->enviar($request->user(), $origenId, $datos);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar la transferencia. Intenta de nuevo.');
        }

        return redirect()->route('transferencias.index')
            ->with('success', 'Transferencia enviada. El destino debe confirmarla al recibir la mercadería.');
    }

    public function recibir(Request $request, Transferencia $transferencia): RedirectResponse
    {
        abort_unless($transferencia->empresa_id === $request->user()->empresa_id, 403);

        try {
            $this->transferencias->recibir($transferencia, $request->user());
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo recibir la transferencia. Intenta de nuevo.');
        }

        return back()->with('success', 'Transferencia recibida. Stock actualizado en el destino.');
    }

    public function anular(Request $request, Transferencia $transferencia): RedirectResponse
    {
        abort_unless($transferencia->empresa_id === $request->user()->empresa_id, 403);

        try {
            $this->transferencias->anular($transferencia, $request->user());
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo anular la transferencia. Intenta de nuevo.');
        }

        return back()->with('success', 'Transferencia anulada. La mercadería volvió al origen.');
    }

    private function sucursalOrigen(Request $request): ?string
    {
        return $this->sucursalDeTrabajo($request);
    }
}
