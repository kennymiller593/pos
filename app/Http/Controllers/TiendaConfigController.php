<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Producto;
use App\Services\CatalogoTiendaService;
use App\Services\PortadaTiendaService;
use App\Support\Tienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Configuración de la tienda en línea de la empresa: su dirección, lo que muestra y qué productos van. */
class TiendaConfigController extends Controller
{
    public function __construct(private readonly CatalogoTiendaService $catalogo) {}

    /** La tienda es un adicional: sin él, la pantalla no existe para la empresa. */
    private function exigirAdicional(Request $request): void
    {
        abort_unless($request->user()->empresa->tienda_habilitada, 403);
    }

    public function edit(Request $request): Response
    {
        $this->exigirAdicional($request);

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
                'categorias' => $this->catalogo->categorias($empresa)->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values(),
                'limites' => ['banners' => Tienda::MAX_BANNERS, 'preguntas' => Tienda::MAX_PREGUNTAS],
            ],
            'resumen' => $this->catalogo->resumen($empresa),
            'maxDestacados' => CatalogoTiendaService::DESTACADOS,
            'productos' => $productos,
            'filtros' => $filtros,
        ]);
    }

    public function update(Request $request, PortadaTiendaService $portada): RedirectResponse
    {
        $this->exigirAdicional($request);

        $empresa = $request->user()->empresa;
        $datos = $this->validar($request);

        // una tienda sin forma de contacto no le sirve al cliente que quiere comprar
        if ($datos['publicada'] && blank($datos['whatsapp'] ?? null) && blank($datos['telefono'] ?? null)) {
            return back()->withErrors(['whatsapp' => 'Para publicar la tienda pon un WhatsApp o un teléfono de contacto.'])->withInput();
        }

        $antes = ['slug' => $empresa->tienda_slug, 'publicada' => (bool) $empresa->tienda_publicada];
        $anterior = Tienda::config($empresa);
        $fotosAnteriores = array_filter([$anterior['portada_imagen'], ...array_column($anterior['banners'], 'imagen')]);

        $config = $this->armarConfig($request, $datos, fn ($archivo) => $portada->guardar($archivo, $empresa));

        $empresa->update([
            'tienda_slug' => $datos['slug'],
            'tienda_publicada' => $datos['publicada'],
            'tienda_config' => $config,
        ]);

        // las fotos que se reemplazaron o se quitaron (portada o banners) ya no se usan
        foreach (array_diff($fotosAnteriores, [$config['portada_imagen'], ...array_column($config['banners'], 'imagen')]) as $sobrante) {
            $portada->eliminar($sobrante);
        }

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

    /**
     * Enlace para ver la tienda con lo que hay en el formulario, sin guardarlo: los visitantes
     * siguen viendo la versión guardada. Sirve también para mirarla antes de publicarla.
     */
    public function vistaPrevia(Request $request, PortadaTiendaService $portada): JsonResponse
    {
        $this->exigirAdicional($request);

        $datos = $this->validar($request);
        $config = $this->armarConfig($request, $datos, fn ($archivo) => $portada->incrustada($archivo));

        return response()->json([
            'url' => $portada->crearVistaPrevia($request->user()->empresa, $datos['slug'], $config),
            'minutos' => PortadaTiendaService::MINUTOS_PREVIA,
        ]);
    }

    private function validar(Request $request): array
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
            'color' => ['required', Rule::in([...array_keys(config('tienda.colores')), 'propio'])],
            'color_propio' => ['nullable', 'required_if:color,propio', 'string', function (string $atributo, mixed $valor, \Closure $falla) {
                if (! Tienda::esColor((string) $valor)) {
                    $falla('Elige un color válido, por ejemplo #33CC66.');
                }
            }],
            'color_texto' => ['nullable', Rule::in(['claro', 'oscuro'])],
            'mostrar_precios' => ['required', 'boolean'],
            'mostrar_stock' => ['required', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\s()-]{6,20}$/'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:400'],
            'mapa_url' => ['nullable', 'string', 'max:500', function (string $atributo, mixed $valor, \Closure $falla) {
                if (! Tienda::esEnlaceDeMapa(trim((string) $valor))) {
                    $falla('Pega el enlace de Google Maps de tu local (en Maps: Compartir, Copiar enlace).');
                }
            }],
            'horario' => ['nullable', 'string', 'max:300'],
            'facebook' => ['nullable', 'string', 'max:150'],
            'instagram' => ['nullable', 'string', 'max:150'],
            'tiktok' => ['nullable', 'string', 'max:150'],
            // apariencia de la portada (opcionales: quien no los manda conserva lo que tenía)
            'portada_estilo' => ['sometimes', Rule::in(Tienda::ESTILOS)],
            'portada_titulo' => ['nullable', 'string', 'max:80'],
            'portada_boton' => ['nullable', 'string', 'max:30'],
            'anuncio' => ['nullable', 'string', 'max:120'],
            'portada_imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'portada_imagen_quitar' => ['nullable', 'boolean'],
            // banners (solo cuentan si el formulario avisa que los manda: una lista vacía no viaja)
            'con_banners' => ['sometimes', 'boolean'],
            'banners' => ['nullable', 'array', 'max:'.Tienda::MAX_BANNERS],
            'banners.*.imagen' => ['nullable', 'string', 'max:500'],
            'banners.*.archivo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'banners.*.titulo' => ['nullable', 'string', 'max:80'],
            'banners.*.destino' => ['nullable', Rule::in(Tienda::DESTINOS)],
            'banners.*.categoria' => ['nullable', 'string', 'max:40'],
            'banners.*.url' => ['nullable', 'string', 'max:300', 'url:https'],
            // contenido
            'nosotros' => ['nullable', 'string', 'max:3000'],
            'envios' => ['nullable', 'string', 'max:2000'],
            'devoluciones' => ['nullable', 'string', 'max:2000'],
            'pagos' => ['nullable', 'string', 'max:1000'],
            'con_preguntas' => ['sometimes', 'boolean'],
            'preguntas' => ['nullable', 'array', 'max:'.Tienda::MAX_PREGUNTAS],
            'preguntas.*.pregunta' => ['required', 'string', 'max:160'],
            'preguntas.*.respuesta' => ['required', 'string', 'max:1000'],
        ], [
            'color_propio.required_if' => 'Elige tu color.',
            'banners.max' => 'Puedes tener hasta '.Tienda::MAX_BANNERS.' banners.',
            'banners.*.archivo.image' => 'El banner debe ser una imagen.',
            'banners.*.archivo.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'banners.*.archivo.max' => 'El banner no debe pesar más de 6 MB.',
            'banners.*.titulo.max' => 'El texto del banner no puede pasar de 80 caracteres.',
            'banners.*.url.url' => 'El enlace debe empezar con https://',
            'banners.*.url.max' => 'El enlace es demasiado largo.',
            'nosotros.max' => 'Este texto no puede pasar de 3000 caracteres.',
            'envios.max' => 'Este texto no puede pasar de 2000 caracteres.',
            'devoluciones.max' => 'Este texto no puede pasar de 2000 caracteres.',
            'pagos.max' => 'Este texto no puede pasar de 1000 caracteres.',
            'preguntas.max' => 'Puedes tener hasta '.Tienda::MAX_PREGUNTAS.' preguntas.',
            'preguntas.*.pregunta.required' => 'Escribe la pregunta.',
            'preguntas.*.pregunta.max' => 'La pregunta no puede pasar de 160 caracteres.',
            'preguntas.*.respuesta.required' => 'Escribe la respuesta.',
            'preguntas.*.respuesta.max' => 'La respuesta no puede pasar de 1000 caracteres.',
            'slug.required' => 'Elige la dirección de tu tienda.',
            'slug.min' => 'La dirección debe tener al menos 3 caracteres.',
            'slug.max' => 'La dirección no puede pasar de 40 caracteres.',
            'slug.unique' => 'Esa dirección ya la usa otro negocio. Prueba con otra.',
            'whatsapp.regex' => 'El WhatsApp no es válido. Ejemplo: 987 654 321.',
            'email.email' => 'El correo no es válido.',
            'portada_imagen.image' => 'La foto de portada debe ser una imagen.',
            'portada_imagen.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'portada_imagen.max' => 'La foto de portada no debe pesar más de 6 MB.',
            'direccion.max' => 'La dirección no puede pasar de 400 caracteres.',
            'horario.max' => 'El horario no puede pasar de 300 caracteres.',
            'portada_titulo.max' => 'El título no puede pasar de 80 caracteres.',
            'portada_boton.max' => 'El texto del botón no puede pasar de 30 caracteres.',
            'anuncio.max' => 'El anuncio no puede pasar de 120 caracteres.',
        ]);

        // el estilo "foto grande" necesita una foto: la que ya tiene o la que está subiendo
        $tendraFoto = $request->hasFile('portada_imagen')
            || (filled(Tienda::config($empresa)['portada_imagen']) && ! $request->boolean('portada_imagen_quitar'));

        if (($datos['portada_estilo'] ?? null) === 'foto' && ! $tendraFoto) {
            throw ValidationException::withMessages(['portada_imagen' => 'Para el estilo "Foto grande" sube una foto de portada.']);
        }

        // cada banner es una imagen: la que ya tenía guardada o la que está subiendo
        $guardadas = array_column(Tienda::config($empresa)['banners'], 'imagen');
        $errores = [];

        foreach ($datos['banners'] ?? [] as $i => $banner) {
            if (! ($banner['archivo'] ?? null) && ! in_array($banner['imagen'] ?? null, $guardadas, true)) {
                $errores["banners.{$i}.archivo"] = 'Sube la imagen de este banner.';
            }
            if (($banner['destino'] ?? null) === 'url' && blank($banner['url'] ?? null)) {
                $errores["banners.{$i}.url"] = 'Pega el enlace al que lleva el banner.';
            }
            if (($banner['destino'] ?? null) === 'categoria' && blank($banner['categoria'] ?? null)) {
                $errores["banners.{$i}.categoria"] = 'Elige la categoría a la que lleva el banner.';
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $datos;
    }

    /**
     * La configuración completa a partir del formulario. $fotoNueva recibe el archivo subido y
     * devuelve lo que se guarda como foto (su dirección al guardar; la imagen incrustada en la vista previa).
     */
    private function armarConfig(Request $request, array $datos, \Closure $fotoNueva): array
    {
        $actual = Tienda::config($request->user()->empresa);

        $config = collect(Tienda::CONFIG)->map(fn ($porDefecto, $clave) => match (true) {
            // las listas (banners, preguntas) se arman más abajo
            is_array($porDefecto) || ! array_key_exists($clave, $datos) => $actual[$clave],
            is_bool($porDefecto) => (bool) $datos[$clave],
            default => filled($datos[$clave]) ? trim((string) $datos[$clave]) : null,
        })->all();

        // dirección y horario: una por línea, sin líneas vacías ni espacios sobrantes
        foreach (['direccion', 'horario'] as $clave) {
            $config[$clave] = implode("\n", Tienda::lineas($config[$clave])) ?: null;
        }

        $config['color_propio'] = $config['color'] === 'propio' ? strtoupper((string) $config['color_propio']) : null;
        $config['color_texto'] = $config['color'] === 'propio' ? $config['color_texto'] : null;

        if ($request->boolean('con_banners')) {
            $config['banners'] = array_values(array_map(function (array $banner) use ($fotoNueva) {
                $destino = $banner['destino'] ?? 'ninguno';

                return [
                    'imagen' => ($banner['archivo'] ?? null) ? $fotoNueva($banner['archivo']) : $banner['imagen'],
                    'titulo' => filled($banner['titulo'] ?? null) ? trim($banner['titulo']) : null,
                    'destino' => $destino ?: 'ninguno',
                    'valor' => match ($destino) {
                        'categoria' => $banner['categoria'],
                        'url' => trim($banner['url']),
                        default => null,
                    },
                ];
            }, $datos['banners'] ?? []));
        }

        if ($request->boolean('con_preguntas')) {
            $config['preguntas'] = array_values(array_map(
                fn (array $p) => ['pregunta' => trim($p['pregunta']), 'respuesta' => trim($p['respuesta'])],
                $datos['preguntas'] ?? [],
            ));
        }

        $config['portada_estilo'] = $datos['portada_estilo'] ?? $actual['portada_estilo'];
        $config['portada_imagen'] = match (true) {
            $request->hasFile('portada_imagen') => $fotoNueva($request->file('portada_imagen')),
            $request->boolean('portada_imagen_quitar') => null,
            default => $actual['portada_imagen'],
        };

        return $config;
    }

    /** Cambia cómo aparece un producto en la tienda: si se muestra, si va destacado y su descripción. */
    public function producto(Request $request, Producto $producto): RedirectResponse
    {
        $this->exigirAdicional($request);
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
