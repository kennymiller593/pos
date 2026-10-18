{{--
    Plantilla "Mercado": tienda en línea estilo marketplace. Barra superior con beneficios, cabecera blanca con
    buscador grande, barra de categorías en el color de la tienda, sellos de confianza y pie oscuro en columnas.
    Comparte con la plantilla clásica el pedido (carrito), el buscador con sugerencias y el chatbot (tienda.js).
--}}
@php
    $titulo = trim($__env->yieldContent('titulo')) ?: ($tienda['seo']['titulo'] ?: "{$tienda['nombre']} | Tienda en línea".($contactos['ciudad'] ? " en {$contactos['ciudad']}" : ''));
    $resumen = \Illuminate\Support\Str::squish(trim($__env->yieldContent('resumen')) ?: ($tienda['seo']['descripcion'] ?: ($tienda['descripcion'] ?: "Tienda en línea de {$tienda['nombre']}. Mira nuestros productos y haz tu pedido por WhatsApp.")));
    $canonica = trim($__env->yieldContent('canonica')) ?: $tienda['url'];
    $absoluta = fn (?string $ruta) => $ruta ? (str_starts_with($ruta, 'http') ? $ruta : rtrim($tienda['url'], '/').$ruta) : null;
    $imagenSocial = $absoluta(trim($__env->yieldContent('imagen')) ?: ($tienda['logo'] ?: $categorias->firstWhere('imagen', '!=', null)?->imagen));
    $soloNumero = fn (?string $telefono) => preg_replace('/[^0-9+]/', '', (string) $telefono);
    $categoriaActiva = $categoria->id ?? null;
    $enCatalogo = request()->is('catalogo');
    $sitioEsquema = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => rtrim($tienda['url'], '/').'/#sitio',
        'name' => $tienda['nombre'],
        'url' => $tienda['url'],
        'inLanguage' => 'es-PE',
        'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => rtrim($tienda['url'], '/').'/catalogo?q={termino}'], 'query-input' => 'required name=termino'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    $entrega = $entrega ?? ['envios' => null, 'pagos' => null];
    $marcasTienda = $marcasTienda ?? collect();
    // atajos de la barra de categorías: las de más productos
    $atajos = $categorias->sortByDesc('productos')->take(5);
    // sellos de confianza de una tienda en línea
    $sellos = [
        ['camion', 'Envíos a todo el Perú', $entrega['envios'] ?: 'Coordinamos la entrega contigo al confirmar tu pedido.'],
        ['escudo', 'Compra segura', $entrega['pagos'] ?: 'Te confirmamos el total y el medio de pago antes de pagar.'],
        $whatsapp
            ? ['audifonos', 'Asesoría especializada', 'Te orientamos por WhatsApp antes de realizar tu compra.']
            : ['audifonos', 'Asesoría especializada', $contactos['telefono'] ? 'Llámanos al '.$contactos['telefono'].' y te orientamos.' : 'Escríbenos y te orientamos antes de comprar.'],
        ['sello', 'Productos originales', 'Catálogo real de nuestra tienda, con precios al día.'],
    ];
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
    <link rel="icon" href="{{ $tienda['favicon'] ?: ($tienda['logo'] ?: '/favicon.ico?v=5') }}">
    @if ($tienda['favicon'])
        <link rel="apple-touch-icon" href="{{ $tienda['favicon'] }}">
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/tienda.js'])
    <style>:root { --marca: {{ $tienda['colores'][0] }}; --marca-oscuro: {{ $tienda['colores'][1] }}; --marca-suave: {{ $tienda['colores'][2] }}; --sobre-marca: {{ $tienda['colores'][3] }}; --marca-texto: {{ $tienda['colores'][4] }}; }
        /* el tono oscuro (barra superior, pie, mosaicos) sale del color de la tienda mezclado con azul noche */
        :root { --mercado-fondo: #0f172a; --sobre-fondo: #ffffff; }
        @supports (color: color-mix(in srgb, red 50%, blue)) { :root { --mercado-fondo: color-mix(in srgb, var(--marca) 22%, #0b1627); } }
        html:not(.con-pedido) [data-necesita-js] { display: none !important; }
        html:not(.con-bot) [data-necesita-bot] { display: none !important; }</style>
    <script type="application/json" id="tienda-datos">{!! json_encode(['nombre' => $tienda['nombre'], 'whatsapp' => $contactos['whatsapp'], 'asistente' => $asistente ? ['nombre' => $asistente['nombre'], 'saludo' => $asistente['saludo'], 'sugerencias' => $asistente['sugerencias']] : null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <script type="application/ld+json">{!! $sitioEsquema !!}</script>
    @stack('cabecera')
</head>
<body class="flex min-h-screen flex-col bg-[#f5f8fa] font-sans text-slate-900 antialiased" data-plantilla="mercado">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-xl focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Ir al contenido</a>

    @if ($previa)
        <div class="sticky top-0 z-50 bg-amber-400 text-amber-950">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 text-sm sm:px-6 lg:px-8">
                <p><span class="font-semibold">Vista previa.</span> Así se verá tu tienda. Tus clientes no ven estos cambios hasta que los guardes.</p>
                <a href="/?previa=salir" class="font-semibold underline underline-offset-2 hover:no-underline">Salir de la vista previa</a>
            </div>
        </div>
    @endif

    {{-- Barra superior: beneficios y atención al cliente --}}
    <div class="bg-(--mercado-fondo) text-(--sobre-fondo)" data-franja>
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-1.5 text-xs sm:px-6 lg:px-8">
            @if ($tienda['anuncio'])
                <p class="min-w-0 truncate font-semibold">{{ $tienda['anuncio'] }}</p>
            @else
                <ul class="flex min-w-0 items-center gap-x-5 overflow-hidden whitespace-nowrap">
                    <li class="flex items-center gap-1.5 font-semibold">@include('tienda.mercado.icono', ['n' => 'camion', 'clase' => 'size-3.5'])Envíos a todo el Perú</li>
                    <li class="hidden items-center gap-1.5 sm:flex">@include('tienda.mercado.icono', ['n' => 'audifonos', 'clase' => 'size-3.5'])Asesoría especializada</li>
                    <li class="hidden items-center gap-1.5 md:flex">@include('tienda.mercado.icono', ['n' => 'sello', 'clase' => 'size-3.5'])Productos originales</li>
                </ul>
            @endif
            @if ($whatsapp || $contactos['telefono'])
                <a href="{{ $whatsapp ?: 'tel:'.$soloNumero($contactos['telefono']) }}" @if ($whatsapp) target="_blank" rel="noopener" @endif class="flex shrink-0 items-center gap-1.5 font-bold hover:underline">
                    <span class="hidden opacity-80 sm:inline">Atención al cliente:</span>
                    @include('tienda.icono', ['n' => $whatsapp ? 'whatsapp' : 'telefono', 'clase' => 'size-3.5'])
                    {{ $whatsapp ? $contactos['whatsapp_texto'] : $contactos['telefono'] }}
                </a>
            @endif
        </div>
    </div>

    {{-- Cabecera blanca: marca, buscador grande, WhatsApp y carrito --}}
    <header class="z-30 bg-white shadow-sm shadow-slate-900/5 {{ $previa ? '' : 'lg:sticky lg:top-0' }}">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3 py-3 lg:gap-6">
                <a href="/" class="flex min-w-0 items-center gap-2.5" aria-label="{{ $tienda['nombre'] }}: inicio">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo'] }}" alt="" class="size-11 shrink-0 object-contain">
                    @else
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca) text-lg font-extrabold text-(--sobre-marca)">{{ $tienda['inicial'] }}</span>
                    @endif
                    <span class="min-w-0">
                        <span class="block truncate text-lg leading-tight font-extrabold tracking-tight text-(--marca-texto) sm:text-xl">{{ $tienda['nombre'] }}</span>
                        <span class="hidden text-[11px] font-medium text-slate-500 sm:block">Tienda en línea</span>
                    </span>
                </a>

                <form action="/catalogo" method="get" role="search" class="relative hidden min-w-0 flex-1 lg:block">
                    <label for="buscador" class="sr-only">Buscar productos</label>
                    <input id="buscador" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Buscar productos, marcas, presentación..." autocomplete="off" maxlength="80"
                        class="h-12 w-full rounded-full border border-slate-200 bg-slate-50 pr-14 pl-5 text-[15px] placeholder-slate-400 transition-colors focus:border-(--marca) focus:bg-white focus:ring-4 focus:ring-(--marca)/15 focus:outline-none">
                    <button type="submit" aria-label="Buscar" class="absolute top-1/2 right-1.5 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-(--marca) text-(--sobre-marca) transition hover:brightness-110">
                        @include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-4.5'])
                    </button>
                    <div id="sugerencias" class="absolute inset-x-0 top-full z-40 mt-2 hidden overflow-hidden rounded-2xl bg-white text-left shadow-2xl ring-1 shadow-slate-900/15 ring-slate-200" aria-live="polite"></div>
                </form>

                <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="hidden h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-100 md:inline-flex">
                            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5 text-[#1fa855]'])WhatsApp
                        </a>
                        <button type="button" data-pedido-abrir data-necesita-js aria-label="Ver mi carrito"
                            class="relative inline-flex h-11 cursor-pointer items-center gap-2 rounded-xl px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-100">
                            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-5 text-(--marca-texto)'])
                            <span class="hidden sm:inline">Carrito</span>
                            <span data-pedido-cuenta class="absolute top-0.5 left-6.5 hidden min-w-4.5 rounded-full bg-(--marca) px-1 text-center text-[10px] leading-4.5 font-bold text-(--sobre-marca) ring-2 ring-white"></span>
                        </button>
                    @else
                        <a href="#contacto" class="inline-flex h-11 items-center rounded-xl bg-(--marca) px-4 text-sm font-bold text-(--sobre-marca)">Contacto</a>
                    @endif
                </div>
            </div>

            <form action="/catalogo" method="get" role="search" class="relative pb-3 lg:hidden">
                <label for="buscador-movil" class="sr-only">Buscar productos</label>
                <input id="buscador-movil" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Buscar productos, marcas, presentación..." autocomplete="off" maxlength="80"
                    class="h-11 w-full rounded-full border border-slate-200 bg-slate-50 pr-13 pl-4 text-[15px] placeholder-slate-400 focus:border-(--marca) focus:bg-white focus:ring-4 focus:ring-(--marca)/15 focus:outline-none">
                <button type="submit" aria-label="Buscar" class="absolute top-1/2 right-1.5 grid size-8 -translate-y-[calc(50%+0.375rem)] place-items-center rounded-full bg-(--marca) text-(--sobre-marca)">
                    @include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-4'])
                </button>
            </form>
        </div>

        {{-- Barra de categorías en el color de la tienda --}}
        <nav class="bg-(--marca) text-(--sobre-marca)" aria-label="Categorías">
            <div class="mx-auto flex max-w-7xl items-stretch gap-1 px-2 text-sm font-semibold sm:px-4 lg:px-6">
                @if ($categorias->isNotEmpty())
                    <details class="group relative shrink-0">
                        <summary class="flex h-11 cursor-pointer list-none items-center gap-2 px-3 whitespace-nowrap transition-colors select-none hover:bg-black/10 group-open:bg-black/15 group-open:before:fixed group-open:before:inset-0 group-open:before:z-30 group-open:before:cursor-default group-open:before:content-[''] [&::-webkit-details-marker]:hidden" data-menu-categorias>
                            @include('tienda.mercado.icono', ['n' => 'menu', 'clase' => 'size-4.5'])Todas las categorías
                            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5 rotate-90 opacity-70 transition-transform group-open:-rotate-90'])
                        </summary>
                        <div class="absolute top-full left-0 z-40 w-[min(90vw,40rem)] overflow-hidden rounded-b-2xl bg-white p-3 text-slate-900 shadow-2xl shadow-slate-900/20 ring-1 ring-slate-200">
                            <a href="/catalogo" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 font-bold hover:bg-(--marca-suave) hover:text-(--marca-texto)">
                                <span>Ver toda la tienda</span><span class="text-xs font-normal text-slate-400 tabular-nums">{{ number_format($tienda['productos']) }} productos</span>
                            </a>
                            <ul class="mt-1 grid gap-x-3 border-t border-slate-100 pt-1 sm:grid-cols-2">
                                @foreach ($categorias as $cat)
                                    <li>
                                        <a href="{{ $cat->url }}" class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-(--marca-suave) hover:text-(--marca-texto) {{ $categoriaActiva === $cat->id ? 'font-bold text-(--marca-texto)' : 'font-medium text-slate-700' }}">
                                            <span class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-full bg-(--marca-suave) text-xs font-extrabold text-(--marca-texto)">
                                                @if ($cat->imagen)<img src="{{ $cat->imagen }}" alt="" loading="lazy" class="size-full object-contain p-1">@else{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($cat->nombre, 0, 1)) }}@endif
                                            </span>
                                            <span class="min-w-0 truncate">{{ $cat->nombre }}</span><span class="ml-auto shrink-0 text-xs font-normal text-slate-400 tabular-nums">{{ $cat->productos }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </details>
                @endif
                <div class="flex min-w-0 flex-1 items-stretch gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <a href="/catalogo" class="flex h-11 shrink-0 items-center gap-1.5 px-3 whitespace-nowrap transition-colors hover:bg-black/10 {{ $enCatalogo && ! $categoriaActiva ? 'bg-black/15' : '' }}">@include('tienda.icono', ['n' => 'cuadricula', 'clase' => 'size-4'])Tienda</a>
                    @foreach ($atajos as $cat)
                        <a href="{{ $cat->url }}" @if ($categoriaActiva === $cat->id) aria-current="true" @endif class="flex h-11 shrink-0 items-center px-3 whitespace-nowrap transition-colors hover:bg-black/10 {{ $categoriaActiva === $cat->id ? 'bg-black/15' : '' }}">{{ $cat->nombre }}</a>
                    @endforeach
                    @if ($marcasTienda->isNotEmpty())
                        <details class="group relative shrink-0">
                            <summary class="flex h-11 cursor-pointer list-none items-center gap-1.5 px-3 whitespace-nowrap transition-colors select-none hover:bg-black/10 group-open:bg-black/15 group-open:before:fixed group-open:before:inset-0 group-open:before:z-30 group-open:before:cursor-default group-open:before:content-[''] [&::-webkit-details-marker]:hidden" data-menu-marcas>
                                @include('tienda.mercado.icono', ['n' => 'etiqueta', 'clase' => 'size-4'])Marcas
                                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5 rotate-90 opacity-70 transition-transform group-open:-rotate-90'])
                            </summary>
                            <ul class="absolute top-full left-0 z-40 max-h-[60vh] w-64 overflow-y-auto rounded-b-2xl bg-white p-2 text-slate-900 shadow-2xl shadow-slate-900/20 ring-1 ring-slate-200">
                                @foreach ($marcasTienda as $m)
                                    <li><a href="/catalogo?marca={{ urlencode($m['nombre']) }}" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-(--marca-suave) hover:text-(--marca-texto)"><span class="truncate">{{ $m['nombre'] }}</span><span class="text-xs font-normal text-slate-400 tabular-nums">{{ $m['productos'] }}</span></a></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    <a href="#contacto" class="flex h-11 shrink-0 items-center gap-1.5 px-3 whitespace-nowrap transition-colors hover:bg-black/10">@include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-4'])Contacto</a>
                </div>
            </div>
        </nav>
    </header>

    <main id="contenido" class="flex-1">
        @yield('contenido')
    </main>

    {{-- Sellos de confianza --}}
    <section class="mx-auto mt-14 max-w-7xl px-4 sm:px-6 lg:px-8" aria-label="Cómo te atendemos" data-sellos>
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($sellos as [$icono, $tituloSello, $detalle])
                <li class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200/80">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-(--marca-suave) text-(--marca-texto)">@include('tienda.mercado.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold">{{ $tituloSello }}</span>
                        <span class="line-clamp-2 text-xs text-slate-500">{{ $detalle }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Pie oscuro en columnas --}}
    <footer id="contacto" class="mt-10 bg-(--mercado-fondo) text-(--sobre-fondo)">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-12 lg:px-8">
            <div class="lg:col-span-4">
                <div class="flex items-center gap-3">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo'] }}" alt="" class="size-11 rounded-xl bg-white object-contain p-0.5">
                    @else
                        <span class="grid size-11 place-items-center rounded-xl bg-(--marca) text-lg font-extrabold text-(--sobre-marca)">{{ $tienda['inicial'] }}</span>
                    @endif
                    <span>
                        <span class="block text-xl leading-tight font-extrabold tracking-tight">{{ $tienda['nombre'] }}</span>
                        <span class="text-xs opacity-70">Tienda en línea</span>
                    </span>
                </div>
                <p class="mt-4 max-w-sm text-sm leading-relaxed opacity-80">{{ $tienda['descripcion'] ?: 'Mira nuestro catálogo en línea y haz tu pedido en un momento. Te atendemos con gusto.' }}</p>
                <div class="mt-5 flex flex-wrap gap-2 text-[11px] font-bold tracking-wider uppercase">
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-1.5 ring-1 ring-white/15">@include('tienda.mercado.icono', ['n' => 'escudo', 'clase' => 'size-3.5'])Compra segura</span>
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-1.5 ring-1 ring-white/15">@include('tienda.mercado.icono', ['n' => 'camion', 'clase' => 'size-3.5'])Envíos nacionales</span>
                </div>
                @if ($contactos['facebook'] || $contactos['instagram'] || $contactos['tiktok'])
                    <p class="mt-6 text-[11px] font-bold tracking-wider uppercase opacity-70">Síguenos en</p>
                    <div class="mt-2 flex gap-2">
                        @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $red => $nombreRed)
                            @if ($contactos[$red])
                                <a href="{{ $contactos[$red] }}" target="_blank" rel="noopener" aria-label="{{ $nombreRed }}" title="{{ $nombreRed }}" class="grid size-9 place-items-center rounded-full bg-white/10 ring-1 ring-white/15 transition-colors hover:bg-(--marca) hover:text-(--sobre-marca)">
                                    @include('tienda.icono', ['n' => $red, 'clase' => 'size-4'])
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="lg:col-span-2">
                <h2 class="text-xs font-bold tracking-wider uppercase">Tienda</h2>
                <ul class="mt-4 space-y-2.5 text-sm opacity-85">
                    <li><a href="/catalogo" class="hover:underline">Toda la tienda</a></li>
                    @foreach ($categorias->take(7) as $cat)
                        <li><a href="{{ $cat->url }}" class="hover:underline">{{ $cat->nombre }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-xs font-bold tracking-wider uppercase">Información</h2>
                <ul class="mt-4 space-y-2.5 text-sm opacity-85" data-paginas>
                    @foreach ($paginas as $enlacePagina)
                        <li><a href="{{ $enlacePagina['url'] }}" class="hover:underline">{{ $enlacePagina['titulo'] }}</a></li>
                    @endforeach
                    <li><a href="#contacto" class="hover:underline">Contáctanos</a></li>
                    @if ($whatsapp)
                        <li><a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="hover:underline">Pedir por WhatsApp</a></li>
                    @endif
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-xs font-bold tracking-wider uppercase">Contáctanos</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @if ($whatsapp)
                        <li><a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'mt-0.5 size-4 text-[#25D366]'])<span><span class="block text-[11px] opacity-60">Asesoría de compra</span><span class="font-bold">{{ $contactos['whatsapp_texto'] }}</span></span></a></li>
                    @endif
                    @if ($contactos['telefono'])
                        <li><a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'telefono', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] opacity-60">Teléfono</span><span class="font-semibold">{{ $contactos['telefono'] }}</span></span></a></li>
                    @endif
                    @if ($contactos['email'])
                        <li><a href="mailto:{{ $contactos['email'] }}" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'correo', 'clase' => 'mt-0.5 size-4 opacity-70'])<span class="min-w-0"><span class="block text-[11px] opacity-60">Correo</span><span class="block truncate font-semibold">{{ $contactos['email'] }}</span></span></a></li>
                    @endif
                    @foreach ($contactos['direcciones'] as $lugar)
                        <li><a href="{{ $lugar['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] opacity-60">Visítanos</span><span class="font-semibold">{{ $lugar['texto'] }}</span></span></a></li>
                    @endforeach
                    @foreach ($contactos['locales'] as $local)
                        <li><a href="{{ $local['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] opacity-60">{{ $local['nombre'] }}</span><span class="font-semibold">{{ $local['direccion'] }}</span></span></a></li>
                    @endforeach
                    @if ($contactos['horarios'])
                        <li class="flex items-start gap-2.5">@include('tienda.icono', ['n' => 'reloj', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] opacity-60">Atención</span>@foreach ($contactos['horarios'] as $linea)<span class="block font-semibold">{{ $linea }}</span>@endforeach</span></li>
                    @endif
                    @if (! $whatsapp && ! $contactos['telefono'] && ! $contactos['email'] && ! $contactos['direcciones'])
                        <li class="opacity-80">Pronto publicaremos nuestros datos de contacto.</li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-4 text-xs opacity-70 sm:flex-row sm:px-6 lg:px-8">
                <span>© {{ now()->year }} {{ $tienda['nombre'] }}. Todos los derechos reservados.</span>
                <a href="{{ config('app.url') }}" target="_blank" rel="noopener" class="hover:underline">Tienda creada con <span class="font-bold">inkaPos</span></a>
            </div>
        </div>
    </footer>

    {{-- WhatsApp flotante (lo reemplaza el chatbot si está encendido) --}}
    @if ($whatsapp && ! $asistente)
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp" data-whatsapp-flotante
            class="group fixed right-4 bottom-4 z-40 h-14 items-center rounded-full bg-[#25D366] text-white shadow-lg ring-4 shadow-black/25 ring-white transition-colors hover:bg-[#1eb957] sm:right-6 sm:bottom-6 {{ $__env->hasSection('barra_movil') ? 'hidden sm:flex' : 'flex' }}">
            <span class="grid size-14 shrink-0 place-items-center">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-7'])</span>
            <span class="hidden max-w-0 overflow-hidden text-sm font-semibold whitespace-nowrap transition-[max-width,padding] duration-300 group-hover:max-w-48 group-hover:pr-5 lg:block">Consulta precios aquí</span>
        </a>
    @endif
    @if ($asistente)
        @include('tienda.asistente')
    @endif
    @yield('barra_movil')

    {{-- El carrito: lo que el cliente va agregando, para enviarlo completo por WhatsApp (lo maneja tienda.js) --}}
    @if ($whatsapp)
        <button type="button" data-pedido-abrir data-pedido-flotante data-necesita-js aria-label="Ver mi carrito"
            class="fixed left-4 z-40 hidden size-14 cursor-pointer place-items-center rounded-full bg-(--mercado-fondo) text-(--sobre-fondo) shadow-lg ring-4 shadow-black/25 ring-white transition hover:brightness-125 sm:right-6 sm:bottom-[6.5rem] sm:left-auto [&:not(.hidden)]:grid bottom-4 {{ $__env->hasSection('barra_movil') ? 'max-sm:!hidden' : '' }}">
            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-6'])
            <span data-pedido-cuenta class="absolute -top-1 -right-1 hidden min-w-6 rounded-full bg-(--marca) px-1.5 text-center text-xs leading-6 font-semibold text-(--sobre-marca) ring-2 ring-white"></span>
        </button>

        <div id="pedido-aviso" class="fixed inset-x-4 bottom-4 z-50 mx-auto hidden max-w-md items-center gap-3 rounded-2xl bg-slate-900 py-3 pr-3 pl-4 text-sm text-white shadow-2xl [&:not(.hidden)]:flex" role="status" aria-live="polite">
            @include('tienda.icono', ['n' => 'check', 'clase' => 'size-5 text-emerald-400'])
            <span data-aviso-texto class="min-w-0 flex-1 truncate"></span>
            <button type="button" data-pedido-abrir class="h-9 shrink-0 cursor-pointer rounded-xl bg-white px-3.5 text-sm font-semibold text-slate-900 transition-colors hover:bg-slate-100">Ver carrito</button>
        </div>

        <div id="pedido" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="titulo-pedido">
            <div class="absolute inset-0 bg-slate-950/50" data-pedido-cerrar></div>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="titulo-pedido" class="text-lg font-bold tracking-tight">Tu carrito</h2>
                        <p class="text-sm text-slate-500">Lo envías por WhatsApp y te lo confirmamos.</p>
                    </div>
                    <button type="button" data-pedido-cerrar data-pedido-cerrar-boton aria-label="Cerrar" class="grid size-10 cursor-pointer place-items-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900">
                        @include('tienda.icono', ['n' => 'cerrar', 'clase' => 'size-5'])
                    </button>
                </div>

                <div data-pedido-vacio class="flex flex-1 flex-col items-center justify-center px-8 text-center">
                    <span class="grid size-16 place-items-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-slate-200">@include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-7'])</span>
                    <p class="mt-5 text-lg font-bold tracking-tight">Tu carrito está vacío</p>
                    <p class="mt-1 text-slate-500">Toca "Agregar al carrito" en los productos que quieras y aquí se irán sumando.</p>
                    <a href="/catalogo" class="mt-6 inline-flex h-11 items-center gap-2 rounded-xl bg-(--marca) px-5 text-sm font-bold text-(--sobre-marca) transition hover:brightness-110">Ver la tienda @include('tienda.icono', ['n' => 'flecha'])</a>
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
                        class="mt-3 flex h-13 items-center justify-center gap-2 rounded-xl bg-[#25D366] px-6 text-base font-bold text-white shadow-sm transition-colors hover:bg-[#1eb957]">
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
