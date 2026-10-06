<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Producto;
use App\Services\CatalogoTiendaService;
use App\Support\Tienda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Configuración de la tienda en línea de la empresa: su dirección, lo que muestra y qué productos van. */
class TiendaConfigController extends Controller
{
    public function __construct(private readonly CatalogoTiendaService $catalogo) {}

    public function edit(Request $request): Response
    {
        $empresa = $request->user()->empresa;
        $filtros = ['buscar' => trim((string) $request->query('buscar')), 'ver' => in_array($request->query('ver'), ['destacados', 'ocultos'], true) ? $request->query('ver') : 'todos'];

        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->with(['categoria:id,nombre', 'presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('factor_conversion')])
            ->when($filtros['buscar'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', "%{$filtros['buscar']}%")
                ->orWhere('codigo_interno', 'ilike', "%{$filtros['buscar']}%")))
            ->when($filtros['ver'] === 'destacados', fn ($q) => $q->where('destacado', true))
            ->when($filtros['ver'] === 'ocultos', fn ($q) => $q->where('en_tienda', false))
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Producto $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo' => $p->codigo_interno,
                'categoria' => $p->categoria?->nombre,
                'imagen' => $p->imagen_url,
                'precio' => $p->presentaciones->first() ? (float) $p->presentaciones->first()->precio_venta : null,
                'sin_presentacion' => $p->presentaciones->isEmpty(),
                'en_tienda' => (bool) $p->en_tienda,
                'destacado' => (bool) $p->destacado,
                'descripcion' => $p->descripcion,
            ]);

        return Inertia::render('Tienda/Configurar', [
            'tienda' => [
                'slug' => $empresa->tienda_slug,
                'sugerencia' => $empresa->tienda_slug ?: Tienda::sugerirSlug($empresa),
                'publicada' => (bool) $empresa->tienda_publicada,
                'config' => Tienda::config($empresa),
                'dominio' => Tienda::dominio(),
                'url' => Tienda::url($empresa->tienda_slug),
                'colores' => collect(config('tienda.colores'))->map(fn ($c) => $c[0])->all(),
            ],
            'resumen' => $this->catalogo->resumen($empresa),
            'maxDestacados' => CatalogoTiendaService::DESTACADOS,
            'productos' => $productos,
            'filtros' => $filtros,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        $request->merge(['slug' => mb_strtolower(trim((string) $request->input('slug')))]);

        $datos = $request->validate([
            'slug' => [
                'required', 'string', 'min:3', 'max:40',
                function (string $atributo, mixed $valor, \Closure $falla) {
                    if (! Tienda::slugValido((string) $valor)) {
                        $falla('Usa solo letras, números y guiones (sin espacios, tildes ni ñ), y que empiece y termine en letra o número.');
                    } elseif (Tienda::reservado((string) $valor)) {
                        $falla('Esa dirección está reservada. Elige otra.');
                    }
                },
                Rule::unique('empresas', 'tienda_slug')->ignore($empresa->id),
            ],
            'publicada' => ['required', 'boolean'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            'color' => ['required', Rule::in(array_keys(config('tienda.colores')))],
            'mostrar_precios' => ['required', 'boolean'],
            'mostrar_stock' => ['required', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\s()-]{6,20}$/'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'horario' => ['nullable', 'string', 'max:200'],
            'facebook' => ['nullable', 'string', 'max:150'],
            'instagram' => ['nullable', 'string', 'max:150'],
            'tiktok' => ['nullable', 'string', 'max:150'],
        ], [
            'slug.required' => 'Elige la dirección de tu tienda.',
            'slug.min' => 'La dirección debe tener al menos 3 caracteres.',
            'slug.max' => 'La dirección no puede pasar de 40 caracteres.',
            'slug.unique' => 'Esa dirección ya la usa otro negocio. Prueba con otra.',
            'whatsapp.regex' => 'El WhatsApp no es válido. Ejemplo: 987 654 321.',
            'email.email' => 'El correo no es válido.',
        ]);

        // una tienda sin forma de contacto no le sirve al cliente que quiere comprar
        if ($datos['publicada'] && blank($datos['whatsapp'] ?? null) && blank($datos['telefono'] ?? null)) {
            return back()->withErrors(['whatsapp' => 'Para publicar la tienda pon un WhatsApp o un teléfono de contacto.'])->withInput();
        }

        $antes = ['slug' => $empresa->tienda_slug, 'publicada' => (bool) $empresa->tienda_publicada];
        $config = collect(Tienda::CONFIG)->map(fn ($porDefecto, $clave) => match (true) {
            is_bool($porDefecto) => (bool) $datos[$clave],
            default => filled($datos[$clave] ?? null) ? trim((string) $datos[$clave]) : null,
        })->all();

        $empresa->update([
            'tienda_slug' => $datos['slug'],
            'tienda_publicada' => $datos['publicada'],
            'tienda_config' => $config,
        ]);

        $ahora = ['slug' => $datos['slug'], 'publicada' => (bool) $datos['publicada']];
        if ($antes !== $ahora) {
            Auditoria::registrar($request->user(), 'tienda.publicacion', 'empresa', $empresa->id, ['antes' => $antes, 'ahora' => $ahora]);
        }

        return back()->with('success', match (true) {
            $ahora['publicada'] && ! $antes['publicada'] => 'Tu tienda ya está publicada.',
            ! $ahora['publicada'] && $antes['publicada'] => 'Tu tienda dejó de estar visible.',
            default => 'Tienda actualizada.',
        });
    }

    /** Cambia cómo aparece un producto en la tienda: si se muestra, si va destacado y su descripción. */
    public function producto(Request $request, Producto $producto): RedirectResponse
    {
        abort_unless($producto->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'en_tienda' => ['sometimes', 'boolean'],
            'destacado' => ['sometimes', 'boolean'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:1500'],
        ], [
            'descripcion.max' => 'La descripción no puede pasar de 1500 caracteres.',
        ]);

        if (($datos['destacado'] ?? false) && ! $producto->destacado) {
            $destacados = Producto::where('empresa_id', $producto->empresa_id)->where('activo', true)->where('destacado', true)->count();

            if ($destacados >= CatalogoTiendaService::DESTACADOS) {
                return back()->with('error', 'Ya tienes '.CatalogoTiendaService::DESTACADOS.' productos destacados. Quita uno para destacar otro.');
            }

            // lo destacado se tiene que ver
            $datos['en_tienda'] = true;
        }

        // lo que se oculta de la tienda deja de estar destacado
        if (array_key_exists('en_tienda', $datos) && ! $datos['en_tienda']) {
            $datos['destacado'] = false;
        }

        if (array_key_exists('descripcion', $datos)) {
            $datos['descripcion'] = filled($datos['descripcion']) ? trim($datos['descripcion']) : null;
        }

        $producto->update($datos);

        return back();
    }
}
