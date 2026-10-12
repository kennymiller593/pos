<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\MensajeIa;
use App\Models\ModeloIa;
use App\Models\ProveedorIa;
use App\Services\IA\ProveedorIaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Proveedores de IA de la plataforma: claves, estado de salud y precios por modelo. */
class ProveedorIaController extends Controller
{
    public function __construct(private readonly ProveedorIaService $proveedores) {}

    public function index(): Response
    {
        $usoMes = MensajeIa::query()
            ->selectRaw('proveedor_codigo, count(*) as consultas, coalesce(sum(costo_usd), 0) as costo')
            ->where('rol', 'assistant')->where('creado_en', '>=', now()->startOfMonth())
            ->groupBy('proveedor_codigo')->get()->keyBy('proveedor_codigo');

        return Inertia::render('Admin/IA/Proveedores', [
            'proveedores' => ProveedorIa::with('modelos')->orderBy('orden')->get()->map(fn (ProveedorIa $p) => [
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'url' => $p->url,
                'activo' => $p->activo,
                'tiene_clave' => filled($p->api_key),
                'clave_final' => $p->finalDeClave(),
                'ultimo_ok_en' => $p->ultimo_ok_en?->toDateTimeString(),
                'ultimo_error' => $p->ultimo_error,
                'ultimo_error_en' => $p->ultimo_error_en?->toDateTimeString(),
                'fallos_seguidos' => $p->fallos_seguidos,
                'en_pausa' => $p->enPausa(),
                'pausado_hasta' => $p->pausado_hasta?->toDateTimeString(),
                'consultas_mes' => (int) ($usoMes[$p->codigo]->consultas ?? 0),
                'costo_mes' => (float) ($usoMes[$p->codigo]->costo ?? 0),
                'modelos' => $p->modelos->map(fn (ModeloIa $m) => [
                    'id' => $m->id,
                    'modelo' => $m->modelo,
                    'precio_entrada' => (float) $m->precio_entrada,
                    'precio_salida' => (float) $m->precio_salida,
                    'nota' => $m->nota,
                ]),
            ]),
            'ayuda' => self::AYUDA,
        ]);
    }

    /** Dónde se saca la clave y cómo se nombran los modelos en cada proveedor. */
    private const AYUDA = [
        'deepseek' => ['consola' => 'https://platform.deepseek.com/api_keys', 'modelos' => 'deepseek-v4-pro, deepseek-flash'],
        'openrouter' => ['consola' => 'https://openrouter.ai/settings/keys', 'modelos' => 'deepseek/deepseek-v4-pro, anthropic/claude-haiku-5.5 (formato proveedor/modelo)'],
        'anthropic' => ['consola' => 'https://console.anthropic.com/settings/keys', 'modelos' => 'claude-haiku-5-5, claude-sonnet-5-5, claude-opus-5-5'],
        'openai' => ['consola' => 'https://platform.openai.com/api-keys', 'modelos' => 'el nombre del modelo tal como aparece en la consola de OpenAI'],
    ];

    public function update(Request $request, ProveedorIa $proveedor): RedirectResponse
    {
        $datos = $request->validate([
            'api_key' => ['nullable', 'string', 'max:500'],
            'quitar_clave' => ['nullable', 'boolean'],
            'url' => ['nullable', 'url', 'max:200'],
            'activo' => ['required', 'boolean'],
        ], ['url.url' => 'La URL no es válida (debe empezar con https://).']);

        $cambios = ['url' => $datos['url'] ?: null, 'activo' => $datos['activo']];
        $detalle = ['clave' => 'sin cambios'];

        if (filled($datos['api_key'] ?? null)) {
            $cambios['api_key'] = trim($datos['api_key']);
            $cambios += ['fallos_seguidos' => 0, 'pausado_hasta' => null, 'ultimo_error' => null, 'ultimo_error_en' => null];
            $detalle['clave'] = 'reemplazada';
        } elseif ($request->boolean('quitar_clave')) {
            $cambios['api_key'] = null;
            $cambios['activo'] = false;
            $detalle['clave'] = 'quitada';
        }

        $proveedor->forceFill($cambios)->save();
        Auditoria::registrar($request->user(), 'plataforma.ia_proveedor', 'ia_proveedor', null, $detalle + ['proveedor' => $proveedor->codigo, 'activo' => $cambios['activo']]);

        return back()->with('success', "{$proveedor->nombre} guardado.");
    }

    /** Manda una consulta mínima para ver si la clave y el modelo responden. */
    public function probar(Request $request, ProveedorIa $proveedor): RedirectResponse
    {
        $datos = $request->validate(['modelo' => ['required', 'string', 'max:120']]);

        if (blank($proveedor->api_key)) {
            return back()->with('error', "{$proveedor->nombre} no tiene clave: guárdala primero.");
        }

        $r = $this->proveedores->probar($proveedor, trim($datos['modelo']));

        return back()->with($r['ok'] ? 'success' : 'error', $r['ok']
            ? "{$proveedor->nombre} · {$r['modelo']}: {$r['mensaje']} en {$r['ms']} ms".($r['tokens'] ? " ({$r['tokens']} tokens)." : '.')
            : "{$proveedor->nombre} · {$r['modelo']} falló en {$r['ms']} ms: {$r['mensaje']}");
    }

    public function guardarModelo(Request $request, ProveedorIa $proveedor): RedirectResponse
    {
        $datos = $request->validate([
            'modelo' => ['required', 'string', 'max:120'],
            'precio_entrada' => ['required', 'numeric', 'min:0', 'max:9999'],
            'precio_salida' => ['required', 'numeric', 'min:0', 'max:9999'],
            'nota' => ['nullable', 'string', 'max:160'],
        ]);

        ModeloIa::updateOrCreate(
            ['proveedor_codigo' => $proveedor->codigo, 'modelo' => trim($datos['modelo'])],
            ['precio_entrada' => $datos['precio_entrada'], 'precio_salida' => $datos['precio_salida'], 'nota' => $datos['nota'] ?? null],
        );

        return back()->with('success', "Precio de {$datos['modelo']} guardado.");
    }

    public function eliminarModelo(ModeloIa $modelo): RedirectResponse
    {
        $modelo->delete();

        return back()->with('success', "Modelo {$modelo->modelo} quitado de la lista de precios.");
    }

    /** Validación compartida del nombre de un proveedor en las rutas. */
    public static function reglaProveedor(): array
    {
        return ['required', Rule::exists('ia_proveedores', 'codigo')];
    }
}
