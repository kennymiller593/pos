@php
    // la portada (sin título propio) usa el que el dueño escribió para Google, si lo hay
    $titulo = trim($__env->yieldContent('titulo')) ?: ($tienda['seo']['titulo'] ?: "{$tienda['nombre']} | Catálogo y pedidos en línea".($contactos['ciudad'] ? " en {$contactos['ciudad']}" : ''));
    // una sola línea: la descripción puede venir con saltos y eso ensucia el resultado en el buscador
    $resumen = \Illuminate\Support\Str::squish(trim($__env->yieldContent('resumen')) ?: ($tienda['seo']['descripcion'] ?: ($tienda['descripcion'] ?: "Catálogo en línea de {$tienda['nombre']}. Mira nuestros productos y haz tu pedido por WhatsApp.")));
    $canonica = trim($__env->yieldContent('canonica')) ?: $tienda['url'];
    // las fotos locales son rutas relativas: para compartir el enlace deben ir completas
    $absoluta = fn (?string $ruta) => $ruta ? (str_starts_with($ruta, 'http') ? $ruta : rtrim($tienda['url'], '/').$ruta) : null;
    $imagenSocial = $absoluta(trim($__env->yieldContent('imagen')) ?: ($tienda['logo'] ?: $categorias->firstWhere('imagen', '!=', null)?->imagen));
    $soloNumero = fn (?string $telefono) => preg_replace('/[^0-9+]/', '', (string) $telefono);
    $categoriaActiva = $categoria->id ?? null;
    $enCatalogo = request()->is('catalogo');
    // se arma aquí, en PHP: escrito en la plantilla, Blade tomaría "@context" por una directiva
    $sitioEsquema = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => rtrim($tienda['url'], '/').'/#sitio',
        'name' => $tienda['nombre'],
        'url' => $tienda['url'],
        'inLanguage' => 'es-PE',
        'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => rtrim($tienda['url'], '/').'/catalogo?q={termino}'], 'query-input' => 'required name=termino'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
@endphp
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($resumen, 158, '…', true) }}">
    <meta name="robots" content="{{ $previa ? 'noindex,nofollow' : (trim($__env->yieldContent('robots')) ?: 'index,follow') }}">
    <link rel="canonical" href="{{ $canonica }}">
    <meta property="og:type" content="@yield('og_tipo', 'website')">
    <meta property="og:locale" content="es_PE">
    <meta property="og:site_name" content="{{ $tienda['nombre'] }}">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($resumen, 200, '…', true) }}">
    <meta property="og:url" content="{{ $canonica }}">
    @if ($imagenSocial)
        <meta property="og:image" content="{{ $imagenSocial }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <meta name="theme-color" content="{{ $tienda['colores'][0] }}">
    {{-- ícono de la pestaña: el que subió el dueño; si no, su logo; si no, el de inkaPos --}}
    <link rel="icon" href="{{ $tienda['favicon'] ?: ($tienda['logo'] ?: '/favicon.ico?v=5') }}">
    @if ($tienda['favicon'])
        <link rel="apple-touch-icon" href="{{ $tienda['favicon'] }}">
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/tienda.js'])
    {{-- color de marca elegido por la tienda --}}
    <style>:root { --marca: {{ $tienda['colores'][0] }}; --marca-oscuro: {{ $tienda['colores'][1] }}; --marca-suave: {{ $tienda['colores'][2] }}; --sobre-marca: {{ $tienda['colores'][3] }}; --marca-texto: {{ $tienda['colores'][4] }}; }
        /* lo que solo sirve con el pedido activo (necesita JavaScript) no aparece hasta que carga */
        html:not(.con-pedido) [data-necesita-js] { display: none !important; }</style>
    <script type="application/json" id="tienda-datos">{!! json_encode(['nombre' => $tienda['nombre'], 'whatsapp' => $contactos['whatsapp']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    {{-- el sitio y su buscador, para que Google entienda la tienda como un todo --}}
    <script type="application/ld+json">{!! $sitioEsquema !!}</script>
    @stack('cabecera')
</head>
<body class="flex min-h-screen flex-col bg-white font-sans text-slate-900 antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-xl focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Ir al contenido</a>

    {{-- Vista previa: solo la ve el dueño con su enlace --}}
    @if ($previa)
        <div class="sticky top-0 z-50 bg-amber-400 text-amber-950">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 text-sm sm:px-6 lg:px-8">
                <p><span class="font-semibold">Vista previa.</span> Así se verá tu tienda. Tus clientes no ven estos cambios hasta que los guardes.</p>
                <a href="/?previa=salir" class="font-semibold underline underline-offset-2 hover:no-underline">Salir de la vista previa</a>
            </div>
        </div>
    @endif

    {{-- Anuncio de la tienda --}}
    @if ($tienda['anuncio'])
        <p class="bg-(--marca) px-4 py-2 text-center text-sm font-medium text-(--sobre-marca)">{{ $tienda['anuncio'] }}</p>
    @endif

    {{-- Franja superior: datos de atención --}}
    @if ($contactos['horario'] || $contactos['direccion'] || $contactos['telefono'] || $whatsapp)
        <div class="bg-slate-950 text-slate-300">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2 text-xs sm:px-6 lg:px-8">
                <p class="flex min-w-0 items-center gap-x-5">
                    @if ($contactos['horario'])
                        <span class="flex min-w-0 items-center gap-1.5">@include('tienda.icono', ['n' => 'reloj', 'clase' => 'size-3.5 text-slate-500'])<span class="truncate">{{ $contactos['horario'] }}</span></span>
                    @endif
                    @if ($contactos['direccion'])
                        <a href="{{ $contactos['mapa'] }}" target="_blank" rel="noopener" class="hidden min-w-0 items-center gap-1.5 hover:text-white md:flex">
                            @include('tienda.icono', ['n' => 'lugar', 'clase' => 'size-3.5 text-slate-500'])<span class="truncate">{{ $contactos['direccion'] }}</span>
                        </a>
                    @endif
                </p>
                <p class="flex shrink-0 items-center gap-x-5">
                    @if ($contactos['telefono'])
                        <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="hidden items-center gap-1.5 hover:text-white sm:flex">
                            @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-3.5 text-slate-500']){{ $contactos['telefono'] }}
                        </a>
                    @endif
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-center gap-1.5 font-medium text-white hover:underline">
                            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-3.5'])WhatsApp {{ $contactos['whatsapp_texto'] }}
                        </a>
                    @endif
                </p>
            </div>
        </div>
    @endif

    {{--
        En pantallas grandes la cabecera entera queda fija. En el celular entera ocuparía un cuarto de la pantalla:
        ahí se quedan fijas solo la franja de la marca (con el pedido) y la de categorías, y el buscador se va al bajar.
        Para eso, en pantallas chicas la cabecera y su contenedor "no existen" (display: contents) y cada franja
        se fija por su cuenta. En la vista previa del dueño no se fija nada en el celular (ya está su aviso arriba).
    --}}
    @php $fijaMovil = $previa ? '' : 'max-lg:sticky max-lg:z-30'; @endphp
    <header class="z-30 border-b border-slate-200 bg-white max-lg:contents lg:sticky {{ $previa ? 'lg:top-9' : 'lg:top-0' }}">
        <div class="max-lg:contents lg:mx-auto lg:flex lg:max-w-7xl lg:items-center lg:gap-x-6 lg:px-8 lg:py-3.5">
          <div class="flex h-18 items-center gap-x-4 bg-white px-4 sm:px-6 lg:contents {{ $fijaMovil }} max-lg:top-0">
            <a href="/" class="flex min-w-0 items-center gap-3" aria-label="{{ $tienda['nombre'] }}: inicio">
                @if ($tienda['logo'])
                    <img src="{{ $tienda['logo'] }}" alt="" class="size-11 shrink-0 object-contain">
                @else
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca) text-lg font-semibold text-(--sobre-marca)">{{ $tienda['inicial'] }}</span>
                @endif
                <span class="min-w-0">
                    <span class="block truncate text-lg leading-tight font-semibold tracking-tight">{{ $tienda['nombre'] }}</span>
                    <span class="hidden text-xs text-slate-500 sm:block">Tienda en línea</span>
                </span>
            </a>

            <div class="ml-auto flex shrink-0 items-center gap-2 lg:order-3 lg:ml-0">
                <a href="#contacto" class="hidden h-11 items-center rounded-xl px-4 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 sm:inline-flex">Contacto</a>
                @if ($whatsapp)
                    <button type="button" data-pedido-abrir data-necesita-js aria-label="Ver mi pedido"
                        class="relative grid size-11 cursor-pointer place-items-center rounded-xl text-slate-700 ring-1 ring-slate-200 transition-colors hover:bg-slate-50 hover:ring-slate-300">
                        @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-5'])
                        <span data-pedido-cuenta class="absolute -top-1.5 -right-1.5 hidden min-w-5 rounded-full bg-(--marca) px-1 text-center text-[11px] leading-5 font-semibold text-(--sobre-marca) ring-2 ring-white"></span>
                    </button>
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl bg-(--marca) px-4 text-sm font-semibold text-(--sobre-marca) shadow-sm transition-colors hover:bg-(--marca-oscuro)">
                        @include('tienda.icono', ['n' => 'whatsapp'])
                        <span class="hidden sm:inline">Hacer pedido</span><span class="sm:hidden">Pedir</span>
                    </a>
                @else
                    <a href="#contacto" class="inline-flex h-11 items-center rounded-xl bg-(--marca) px-4 text-sm font-semibold text-(--sobre-marca) shadow-sm transition-colors hover:bg-(--marca-oscuro) sm:hidden">Contacto</a>
                @endif
            </div>
          </div>

          {{-- buscador: en pantallas chicas ocupa su propia fila (y no se queda fija) --}}
          <div class="bg-white px-4 pb-3.5 sm:px-6 lg:contents {{ $categorias->isEmpty() ? 'max-lg:border-b max-lg:border-slate-200' : '' }}">
            <form action="/catalogo" method="get" role="search" class="relative w-full lg:order-2 lg:mx-auto lg:max-w-2xl lg:flex-1">
                <label for="buscador" class="sr-only">Buscar productos</label>
                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-slate-400">@include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-4.5'])</span>
                <input id="buscador" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="¿Qué producto buscas?" autocomplete="off" maxlength="80"
                    class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pr-28 pl-11 text-[15px] placeholder-slate-400 transition-colors focus:border-(--marca) focus:bg-white focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                <button type="submit" class="absolute top-1/2 right-1.5 h-9 -translate-y-1/2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition-colors hover:bg-(--marca) hover:text-(--sobre-marca)">Buscar</button>
                {{-- sugerencias mientras se escribe (las llena tienda.js) --}}
                <div id="sugerencias" class="absolute inset-x-0 top-full z-40 mt-2 hidden overflow-hidden rounded-2xl bg-white text-left shadow-2xl ring-1 shadow-slate-900/15 ring-slate-200" aria-live="polite"></div>
            </form>
          </div>
        </div>

        {{-- categorías: siempre a la mano --}}
        @if ($categorias->isNotEmpty())
            @php
                // con muchas categorías, la fila solo lleva las que entran (primero las de más productos)
                // y el resto queda en el menú "Categorías", que las lista todas
                $muchas = $categorias->count() > 6;
                $enFila = $muchas
                    ? $categorias->sortByDesc('productos')->sortByDesc(fn ($c) => $c->id === $categoriaActiva)->values()
                    : $categorias;
            @endphp
            <nav class="border-t border-slate-100 bg-white max-lg:border-b max-lg:border-b-slate-200 {{ $fijaMovil }} max-lg:top-18" aria-label="Categorías">
                <div class="relative mx-auto flex max-w-7xl items-stretch px-4 text-sm sm:px-6 lg:px-8">
                    @if ($muchas)
                        <details class="group shrink-0">
                            <summary class="flex h-11 cursor-pointer list-none items-center gap-2 pr-4 font-semibold text-slate-900 transition-colors select-none hover:text-(--marca-texto) group-open:text-(--marca-texto) group-open:before:fixed group-open:before:inset-0 group-open:before:z-30 group-open:before:cursor-default group-open:before:content-[''] [&::-webkit-details-marker]:hidden">
                                @include('tienda.icono', ['n' => 'cuadricula'])Categorías
                                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                            </summary>
                            <div class="absolute inset-x-4 top-full z-40 max-h-[70vh] overflow-y-auto overscroll-contain rounded-b-2xl border border-t-0 border-slate-200 bg-white p-3 shadow-xl shadow-slate-900/10 sm:inset-x-6 sm:p-4 lg:inset-x-8">
                                <a href="/catalogo" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 font-semibold text-slate-900 hover:bg-(--marca-suave) hover:text-(--marca-texto)">
                                    <span>Todo el catálogo</span><span class="text-xs font-normal text-slate-400 tabular-nums">{{ number_format($tienda['productos']) }}</span>
                                </a>
                                <ul class="mt-1 grid gap-x-4 border-t border-slate-100 pt-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    @foreach ($categorias as $cat)
                                        <li>
                                            <a href="{{ $cat->url }}" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-(--marca-suave) hover:text-(--marca-texto) {{ $categoriaActiva === $cat->id ? 'font-semibold text-(--marca-texto)' : 'text-slate-700' }}">
                                                <span class="min-w-0 truncate">{{ $cat->nombre }}</span><span class="shrink-0 text-xs font-normal text-slate-400 tabular-nums">{{ $cat->productos }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endif
                    {{-- en celular la fila se desliza; en pantalla grande solo se ven las que entran completas --}}
                    <div class="flex h-11 min-w-0 flex-1 items-stretch gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden {{ $muchas ? 'border-l border-slate-100 pl-1 lg:flex-wrap lg:overflow-hidden' : '' }}">
                        @unless ($muchas)
                            <a href="/catalogo" class="flex shrink-0 items-center gap-2 border-b-2 pr-3 font-semibold whitespace-nowrap {{ $enCatalogo ? 'border-(--marca) text-(--marca-texto)' : 'border-transparent text-slate-900 hover:text-(--marca-texto)' }}">
                                @include('tienda.icono', ['n' => 'cuadricula'])Todo el catálogo
                            </a>
                        @endunless
                        @foreach ($enFila as $cat)
                            <a href="{{ $cat->url }}" @if ($categoriaActiva === $cat->id) aria-current="true" @endif
                                class="flex h-11 shrink-0 items-center border-b-2 px-3 whitespace-nowrap transition-colors {{ $categoriaActiva === $cat->id ? 'border-(--marca) font-semibold text-(--marca-texto)' : 'border-transparent text-slate-600 hover:text-slate-900' }}">
                                {{ $cat->nombre }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </nav>
        @endif
    </header>

    <main id="contenido" class="flex-1">
        @yield('contenido')
    </main>

    {{-- Llamado a pedir --}}
    <section class="mt-16 bg-(--marca) text-(--sobre-marca)">
        <div class="mx-auto flex max-w-7xl flex-col items-start gap-5 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">¿No encuentras lo que buscas?</h2>
                <p class="mt-1 opacity-85">Escríbenos y te ayudamos a encontrarlo, con precio y disponibilidad al momento.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center gap-2 rounded-xl bg-white px-6 text-sm font-semibold text-(--marca-texto) shadow-sm ring-1 ring-black/5 transition-colors hover:bg-white/90">
                        @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Escribir por WhatsApp
                    </a>
                @endif
                @if ($contactos['telefono'])
                    <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="inline-flex h-12 items-center gap-2 rounded-xl border border-(--sobre-marca)/40 px-6 text-sm font-semibold text-(--sobre-marca) transition-colors hover:bg-(--sobre-marca)/10">
                        @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-5'])Llamar
                    </a>
                @endif
                @if (! $whatsapp && ! $contactos['telefono'])
                    <a href="#contacto" class="inline-flex h-12 items-center rounded-xl bg-white px-6 text-sm font-semibold text-(--marca-texto)">Ver cómo contactarnos</a>
                @endif
            </div>
        </div>
    </section>

    {{-- Pie: quiénes somos y contactos --}}
    <footer id="contacto" class="bg-slate-950 text-slate-400 lg:scroll-mt-32">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:grid-cols-2 sm:px-6 lg:grid-cols-12 lg:px-8">
            <div class="lg:col-span-4">
                <div class="flex items-center gap-3">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo'] }}" alt="" class="size-11 rounded-xl bg-white object-contain">
                    @else
                        <span class="grid size-11 place-items-center rounded-xl bg-(--marca) text-lg font-semibold text-(--sobre-marca)">{{ $tienda['inicial'] }}</span>
                    @endif
                    <span class="text-lg font-semibold tracking-tight text-white">{{ $tienda['nombre'] }}</span>
                </div>
                <p class="mt-4 max-w-sm text-sm leading-relaxed">
                    {{ $tienda['descripcion'] ?: 'Mira nuestro catálogo en línea y haz tu pedido en un momento. Te atendemos con gusto.' }}
                </p>
                @if ($contactos['facebook'] || $contactos['instagram'] || $contactos['tiktok'])
                    <div class="mt-5 flex gap-2">
                        @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $red => $nombreRed)
                            @if ($contactos[$red])
                                <a href="{{ $contactos[$red] }}" target="_blank" rel="noopener" aria-label="{{ $nombreRed }}" title="{{ $nombreRed }}"
                                    class="grid size-10 place-items-center rounded-xl bg-white/5 text-slate-300 transition-colors hover:bg-(--marca) hover:text-(--sobre-marca)">
                                    @include('tienda.icono', ['n' => $red, 'clase' => 'size-4.5'])
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($categorias->isNotEmpty())
                <div class="lg:col-span-2">
                    <h2 class="text-sm font-semibold text-white">Categorías</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        @foreach ($categorias->take(6) as $cat)
                            <li><a href="{{ $cat->url }}" class="transition-colors hover:text-white">{{ $cat->nombre }}</a></li>
                        @endforeach
                        <li><a href="/catalogo" class="font-medium text-slate-300 transition-colors hover:text-white">Ver todo el catálogo</a></li>
                    </ul>
                    @if ($paginas)
                        <h2 class="mt-8 text-sm font-semibold text-white">Información</h2>
                        <ul class="mt-4 space-y-2.5 text-sm" data-paginas>
                            @foreach ($paginas as $enlacePagina)
                                <li><a href="{{ $enlacePagina['url'] }}" class="transition-colors hover:text-white">{{ $enlacePagina['titulo'] }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @elseif ($paginas)
                <div class="lg:col-span-2">
                    <h2 class="text-sm font-semibold text-white">Información</h2>
                    <ul class="mt-4 space-y-2.5 text-sm" data-paginas>
                        @foreach ($paginas as $enlacePagina)
                            <li><a href="{{ $enlacePagina['url'] }}" class="transition-colors hover:text-white">{{ $enlacePagina['titulo'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="lg:col-span-3">
                <h2 class="text-sm font-semibold text-white">Contáctanos</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @if ($whatsapp)
                        <li>
                            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-center gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'whatsapp'])</span>
                                <span><span class="block text-xs text-slate-500">WhatsApp</span><span class="font-medium text-slate-200">{{ $contactos['whatsapp_texto'] }}</span></span>
                            </a>
                        </li>
                    @endif
                    @if ($contactos['telefono'])
                        <li>
                            <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="flex items-center gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'telefono'])</span>
                                <span><span class="block text-xs text-slate-500">Teléfono</span><span class="font-medium text-slate-200">{{ $contactos['telefono'] }}</span></span>
                            </a>
                        </li>
                    @endif
                    @if ($contactos['email'])
                        <li>
                            <a href="mailto:{{ $contactos['email'] }}" class="flex items-center gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'correo'])</span>
                                <span class="min-w-0"><span class="block text-xs text-slate-500">Correo</span><span class="block truncate font-medium text-slate-200">{{ $contactos['email'] }}</span></span>
                            </a>
                        </li>
                    @endif
                    @if (! $whatsapp && ! $contactos['telefono'] && ! $contactos['email'])
                        <li>Pronto publicaremos nuestros datos de contacto.</li>
                    @endif
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-sm font-semibold text-white">Visítanos</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @foreach ($contactos['direcciones'] as $lugar)
                        <li>
                            <a href="{{ $lugar['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'lugar'])</span>
                                <span><span class="block font-medium text-slate-200">{{ $lugar['texto'] }}</span><span class="text-xs text-slate-500">Ver en el mapa</span></span>
                            </a>
                        </li>
                    @endforeach
                    @if ($contactos['horarios'])
                        <li class="flex items-start gap-3">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'reloj'])</span>
                            <span>
                                <span class="block text-xs text-slate-500">Horario de atención</span>
                                @foreach ($contactos['horarios'] as $linea)
                                    <span class="block font-medium text-slate-200">{{ $linea }}</span>
                                @endforeach
                            </span>
                        </li>
                    @endif
                    @foreach ($contactos['locales'] as $local)
                        <li>
                            <a href="{{ $local['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'lugar'])</span>
                                <span>
                                    <span class="block text-xs text-slate-500">{{ $local['nombre'] }}</span>
                                    <span class="block font-medium text-slate-200">{{ $local['direccion'] }}</span>
                                    @if ($local['telefono'])<span class="text-xs">{{ $local['telefono'] }}</span>@endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-5 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8">
                <span>© {{ now()->year }} {{ $tienda['nombre'] }}. Todos los derechos reservados.</span>
                <a href="{{ config('app.url') }}" target="_blank" rel="noopener" class="transition-colors hover:text-slate-300">Tienda creada con <span class="font-semibold text-slate-300">inkaPos</span></a>
            </div>
        </div>
    </footer>

    {{-- botón flotante de WhatsApp, siempre a la mano (en celular, la página de producto ya trae su propia barra para pedir) --}}
    @if ($whatsapp)
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp" data-whatsapp-flotante
            class="group fixed right-4 bottom-4 z-40 h-14 items-center rounded-full bg-[#25D366] text-white shadow-lg ring-4 shadow-black/25 ring-white transition-colors hover:bg-[#1eb957] sm:right-6 sm:bottom-6 {{ $__env->hasSection('barra_movil') ? 'hidden sm:flex' : 'flex' }}">
            <span class="grid size-14 shrink-0 place-items-center">
                <svg viewBox="0 0 24 24" fill="currentColor" class="size-7" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
            </span>
            {{-- en pantalla grande, al pasar el cursor se abre con el texto --}}
            <span class="hidden max-w-0 overflow-hidden text-sm font-semibold whitespace-nowrap transition-[max-width,padding] duration-300 group-hover:max-w-48 group-hover:pr-5 group-focus-visible:max-w-48 group-focus-visible:pr-5 lg:block">Escríbenos por WhatsApp</span>
        </a>
    @endif
    @yield('barra_movil')

    {{-- El pedido: lo que el cliente va añadiendo, para enviarlo completo por WhatsApp (lo maneja tienda.js) --}}
    @if ($whatsapp)
        {{-- acceso al pedido mientras se baja por la página: aparece cuando ya hay algo añadido.
             En celular va a la izquierda, para no apilarse con el de WhatsApp encima de los productos --}}
        <button type="button" data-pedido-abrir data-pedido-flotante data-necesita-js aria-label="Ver mi pedido"
            class="fixed left-4 z-40 hidden size-14 cursor-pointer place-items-center rounded-full bg-slate-900 text-white shadow-lg ring-4 shadow-black/25 ring-white transition-colors hover:bg-slate-700 sm:right-6 sm:bottom-[6.5rem] sm:left-auto [&:not(.hidden)]:grid bottom-4 {{ $__env->hasSection('barra_movil') ? 'max-sm:!hidden' : '' }}">
            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-6'])
            <span data-pedido-cuenta class="absolute -top-1 -right-1 hidden min-w-6 rounded-full bg-(--marca) px-1.5 text-center text-xs leading-6 font-semibold text-(--sobre-marca) ring-2 ring-white"></span>
        </button>

        <div id="pedido-aviso" class="fixed inset-x-4 bottom-4 z-50 mx-auto hidden max-w-md items-center gap-3 rounded-2xl bg-slate-900 py-3 pr-3 pl-4 text-sm text-white shadow-2xl [&:not(.hidden)]:flex" role="status" aria-live="polite">
            @include('tienda.icono', ['n' => 'check', 'clase' => 'size-5 text-emerald-400'])
            <span data-aviso-texto class="min-w-0 flex-1 truncate"></span>
            <button type="button" data-pedido-abrir class="h-9 shrink-0 cursor-pointer rounded-xl bg-white px-3.5 text-sm font-semibold text-slate-900 transition-colors hover:bg-slate-100">Ver pedido</button>
        </div>

        <div id="pedido" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="titulo-pedido">
            <div class="absolute inset-0 bg-slate-950/50" data-pedido-cerrar></div>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="titulo-pedido" class="text-lg font-semibold tracking-tight">Tu pedido</h2>
                        <p class="text-sm text-slate-500">Lo envías por WhatsApp y te lo confirmamos.</p>
                    </div>
                    <button type="button" data-pedido-cerrar data-pedido-cerrar-boton aria-label="Cerrar" class="grid size-10 cursor-pointer place-items-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900">
                        @include('tienda.icono', ['n' => 'cerrar', 'clase' => 'size-5'])
                    </button>
                </div>

                <div data-pedido-vacio class="flex flex-1 flex-col items-center justify-center px-8 text-center">
                    <span class="grid size-16 place-items-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-slate-200">@include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-7'])</span>
                    <p class="mt-5 text-lg font-semibold tracking-tight">Tu pedido está vacío</p>
                    <p class="mt-1 text-slate-500">Toca "Añadir" en los productos que quieras y aquí se irán sumando.</p>
                    <a href="/catalogo" class="mt-6 inline-flex h-11 items-center gap-2 rounded-xl bg-(--marca) px-5 text-sm font-semibold text-(--sobre-marca) transition-colors hover:bg-(--marca-oscuro)">Ver el catálogo @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>

                <ul data-pedido-lista class="hidden flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain"></ul>

                <div data-pedido-pie class="hidden border-t border-slate-200 bg-slate-50 px-5 pt-4 pb-5">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <label for="pedido-nombre" class="sr-only">Tu nombre</label>
                            <input id="pedido-nombre" data-pedido-nombre type="text" maxlength="60" autocomplete="name" placeholder="Tu nombre (opcional)"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm placeholder-slate-400 focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        </div>
                        <div>
                            <label for="pedido-nota" class="sr-only">Nota para la tienda</label>
                            <input id="pedido-nota" data-pedido-nota type="text" maxlength="160" placeholder="Nota: envío, recojo... (opcional)"
                                class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm placeholder-slate-400 focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        </div>
                    </div>
                    <p data-pedido-total class="mt-4 flex items-baseline justify-between gap-3"></p>
                    <a href="{{ $whatsapp }}" data-pedido-enviar target="_blank" rel="noopener"
                        class="mt-3 flex h-13 items-center justify-center gap-2 rounded-xl bg-[#25D366] px-6 text-base font-semibold text-white shadow-sm transition-colors hover:bg-[#1eb957]">
                        @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Enviar pedido por WhatsApp
                    </a>
                    <div class="mt-3 flex items-center justify-between gap-3 text-xs text-slate-500">
                        <span>La tienda te confirma disponibilidad y precio final.</span>
                        <button type="button" data-pedido-vaciar class="shrink-0 cursor-pointer font-medium text-slate-600 underline underline-offset-2 hover:text-red-600">Vaciar</button>
                    </div>
                </div>
            </aside>
        </div>
    @endif
</body>
</html>
