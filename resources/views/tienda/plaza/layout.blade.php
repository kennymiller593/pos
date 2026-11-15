{{--
    Plantilla "Plaza": tienda en línea estilo gran almacén. Cabecera entera en el color de la tienda (marca, buscador
    grande y menú en dos filas), secciones en paneles blancos sobre fondo gris y pie oscuro en columnas.
    El color secundario del dueño es el acento (botón de buscar, carrito, botón de la portada).
    Comparte con las otras plantillas el pedido (carrito), el buscador con sugerencias y el chatbot (tienda.js).
--}}
@php
    // @section ya escapa su texto y aquí se vuelve a escapar al imprimirlo: se deshace uno para que 1" no salga como 1&quot;
    $titulo = trim(html_entity_decode($__env->yieldContent('titulo'), ENT_QUOTES | ENT_HTML5)) ?: ($tienda['seo']['titulo'] ?: "{$tienda['nombre']} | Tienda en línea".($contactos['ciudad'] ? " en {$contactos['ciudad']}" : ''));
    $resumen = \Illuminate\Support\Str::squish(trim(html_entity_decode($__env->yieldContent('resumen'), ENT_QUOTES | ENT_HTML5)) ?: ($tienda['seo']['descripcion'] ?: ($tienda['descripcion'] ?: "Tienda en línea de {$tienda['nombre']}. Mira nuestros productos y haz tu pedido por WhatsApp.")));
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
    // atajos del menú: las categorías con más productos
    $atajos = $categorias->sortByDesc('productos')->take(5);
    // en la portada los beneficios van bajo la campaña; en las demás páginas, antes del pie
    $enPortada = (bool) ($portada ?? false);
    // el acento: el color secundario tal como lo eligió el dueño; si no eligió uno, el tono oscuro de su color principal
    $acentoPropio = $tienda['secundario_propio'];
    $acento = $tienda['acento'] ?? null;
    // "Contacto" va una sola vez en el menú: a su página si la tienda la tiene, si no al pie
    $paginasMenu = array_slice(array_values(array_filter($paginas, fn ($p) => $p['url'] !== '/contacto')), 0, 2);
    $enlaceContacto = collect($paginas)->contains('url', '/contacto') ? '/contacto' : '#contacto';
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
        /* acento (buscar, carrito, botón de la portada) y su versión para texto sobre blanco */
        :root { --plaza-acento: {{ $acento ? $acento[0] : 'var(--marca-oscuro)' }}; --sobre-acento: {{ $acento ? $acento[1] : 'var(--sobre-marca)' }}; --acento-texto: {{ $acentoPropio ? $tienda['secundario'][0] : 'var(--marca-texto)' }}; }
        /* el tono oscuro (pie, panel de promoción, avisos): azul noche teñido con el color de la tienda */
        :root { --plaza-oscuro: #1b2233; }
        @supports (color: color-mix(in srgb, red 50%, blue)) { :root { --plaza-oscuro: color-mix(in srgb, var(--marca) 14%, #171d2b); } }
        /* las vistas compartidas (ficha del producto, tarjeta de relacionados, filtros) usan estos nombres */
        :root { --secundario: {{ $acentoPropio ? $tienda['secundario'][0] : 'var(--plaza-oscuro)' }}; --secundario-oscuro: {{ $acentoPropio ? $tienda['secundario'][1] : '#0f141f' }}; --sobre-secundario: #FFFFFF; --mercado-fondo: var(--plaza-oscuro); --sobre-fondo: #FFFFFF; --boton-carrito: var(--marca); --sobre-boton-carrito: var(--sobre-marca); }
        /* los puntitos de las fotos de portada suben para no quedar bajo las tarjetas de beneficios, que se montan sobre el borde */
        [data-portada="plaza"]:not([data-portada-sola]) [data-portada-puntos] { bottom: 3.25rem; }
        html:not(.con-pedido) [data-necesita-js] { display: none !important; }
        html:not(.con-bot) [data-necesita-bot] { display: none !important; }</style>
    <script type="application/json" id="tienda-datos">{!! json_encode(['nombre' => $tienda['nombre'], 'whatsapp' => $contactos['whatsapp'], 'asistente' => $asistente ? ['nombre' => $asistente['nombre'], 'saludo' => $asistente['saludo'], 'sugerencias' => $asistente['sugerencias']] : null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <script type="application/ld+json">{!! $sitioEsquema !!}</script>
    @if ($medicion['google_verificacion'] ?? null)
        <meta name="google-site-verification" content="{{ $medicion['google_verificacion'] }}">
    @endif
    @if (array_diff_key($medicion ?? [], ['google_verificacion' => 1]))
        {{-- identificadores de Analytics / píxeles / Clarity: tienda.js carga sus scripts (nada en línea, por la CSP) --}}
        <script type="application/json" id="medicion-datos">{!! json_encode([...array_diff_key($medicion, ['google_verificacion' => 1]), 'evento' => $medicionEvento ?? null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endif
    @stack('cabecera')
</head>
<body class="flex min-h-screen flex-col bg-[#eef1f6] font-sans text-slate-900 antialiased" data-plantilla="plaza">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-xl focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Ir al contenido</a>

    @if ($previa)
        <div class="sticky top-0 z-50 bg-amber-400 text-amber-950">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 text-sm sm:px-6 lg:px-8">
                <p><span class="font-semibold">Vista previa.</span> Así se verá tu tienda. Tus clientes no ven estos cambios hasta que los guardes.</p>
                <a href="/?previa=salir" class="font-semibold underline underline-offset-2 hover:no-underline">Salir de la vista previa</a>
            </div>
        </div>
    @endif

    {{-- Anuncio del dueño: franja delgada sobre la cabecera --}}
    @if ($tienda['anuncio'])
        <div class="bg-(--marca-oscuro) text-(--sobre-marca)" data-franja>
            <p class="mx-auto max-w-7xl truncate px-4 py-1.5 text-center text-xs font-semibold sm:px-6 lg:px-8">{{ $tienda['anuncio'] }}</p>
        </div>
    @endif

    {{-- Cabecera en el color de la tienda: marca, buscador grande y atención; debajo, el menú --}}
    {{-- en celular la cabecera se "disuelve" (contents) para que solo el menú quede fijo al bajar --}}
    <header class="z-30 bg-(--marca) text-(--sobre-marca) max-lg:contents lg:shadow-md lg:shadow-slate-900/10 {{ $previa ? '' : 'lg:sticky lg:top-0' }}">
        <div class="mx-auto w-full max-w-7xl bg-(--marca) px-3 text-(--sobre-marca) sm:px-6 lg:bg-transparent lg:px-8">
            <div class="flex items-center gap-3 pt-3 pb-2.5 lg:gap-8 lg:pt-4 lg:pb-2">
                <a href="/" class="flex min-w-0 items-center gap-2.5" aria-label="{{ $tienda['nombre'] }}: inicio">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo_ligero'] }}" alt="" class="size-11 shrink-0 rounded-lg bg-white object-contain p-0.5 lg:size-12">
                    @else
                        <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-white text-xl font-extrabold text-(--marca-texto) lg:size-12">{{ $tienda['inicial'] }}</span>
                    @endif
                    <span class="min-w-0 truncate text-xl leading-tight font-extrabold tracking-tight lg:max-w-56 lg:text-2xl">{{ $tienda['nombre'] }}</span>
                </a>

                <form action="/catalogo" method="get" role="search" class="relative hidden min-w-0 flex-1 lg:block">
                    <label for="buscador" class="sr-only">Buscar productos</label>
                    <input id="buscador" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Buscar productos, marcas y más..." autocomplete="off" maxlength="80"
                        class="h-12 w-full rounded-lg bg-white pr-16 pl-4 text-[15px] text-slate-900 placeholder-slate-400 shadow-sm focus:ring-4 focus:ring-white/35 focus:outline-none">
                    <button type="submit" aria-label="Buscar" class="absolute inset-y-0 right-0 grid w-14 cursor-pointer place-items-center rounded-r-lg bg-(--plaza-acento) text-(--sobre-acento) transition hover:brightness-110">
                        @include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-5'])
                    </button>
                    <div id="sugerencias" class="absolute inset-x-0 top-full z-40 mt-2 hidden overflow-hidden rounded-xl bg-white text-left text-slate-900 shadow-2xl ring-1 shadow-slate-900/15 ring-slate-200" aria-live="polite"></div>
                </form>

                {{-- atención al cliente (pantalla grande) --}}
                @if ($whatsapp || $contactos['telefono'])
                    <a href="{{ $whatsapp ?: 'tel:'.$soloNumero($contactos['telefono']) }}" @if ($whatsapp) target="_blank" rel="noopener" @endif class="hidden shrink-0 items-center gap-2.5 rounded-lg px-2 py-1 transition-colors hover:bg-black/10 lg:flex" data-atencion>
                        @include('tienda.icono', ['n' => $whatsapp ? 'whatsapp' : 'telefono', 'clase' => 'size-7'])
                        <span class="leading-tight">
                            <span class="block text-sm font-bold">{{ $whatsapp ? $contactos['whatsapp_texto'] : $contactos['telefono'] }}</span>
                            <span class="block text-xs opacity-85">{{ $whatsapp ? 'Atención por WhatsApp' : 'Atención al cliente' }}</span>
                        </span>
                    </a>
                @endif

                {{-- carrito (celular y tablet): en pantalla grande va en el menú --}}
                <div class="ml-auto flex shrink-0 items-center lg:hidden">
                    @if ($whatsapp)
                        <button type="button" data-pedido-abrir data-necesita-js aria-label="Ver mi carrito"
                            class="relative grid size-11 cursor-pointer place-items-center rounded-lg transition-colors hover:bg-black/10">
                            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-6'])
                            <span data-pedido-cuenta class="absolute top-0.5 right-0 hidden min-w-4.5 rounded-full bg-white px-1 text-center text-[10px] leading-4.5 font-bold text-slate-900"></span>
                        </button>
                    @else
                        <a href="#contacto" class="inline-flex h-10 items-center rounded-lg bg-(--plaza-acento) px-4 text-sm font-bold text-(--sobre-acento)">Contacto</a>
                    @endif
                </div>
            </div>

            <form action="/catalogo" method="get" role="search" class="relative pb-3 lg:hidden">
                <label for="buscador-movil" class="sr-only">Buscar productos</label>
                <input id="buscador-movil" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Buscar productos, marcas y más..." autocomplete="off" maxlength="80"
                    class="h-11 w-full rounded-lg bg-white pr-14 pl-4 text-[15px] text-slate-900 placeholder-slate-400 focus:ring-4 focus:ring-white/35 focus:outline-none">
                <button type="submit" aria-label="Buscar" class="absolute top-0 right-0 grid h-11 w-12 cursor-pointer place-items-center rounded-r-lg bg-(--plaza-acento) text-(--sobre-acento)">
                    @include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-5'])
                </button>
            </form>
        </div>

        {{-- Menú: categorías, atajos, marcas, páginas y carrito --}}
        <nav class="bg-(--marca) text-(--sobre-marca) {{ $previa ? '' : 'max-lg:sticky max-lg:top-0 max-lg:z-30 max-lg:shadow-md max-lg:shadow-slate-900/15' }}" aria-label="Menú de la tienda" data-menu-fijo>
            <div class="mx-auto flex max-w-7xl items-center gap-1 border-t border-white/10 px-1.5 text-sm sm:px-4 lg:border-t-0 lg:px-6 lg:pb-2">
                <span class="mr-3 hidden shrink-0 items-center gap-2 pl-2 leading-tight xl:flex" data-envios-a>
                    @include('tienda.icono', ['n' => 'lugar', 'clase' => 'size-5 opacity-90'])
                    <span><span class="block text-[11px] opacity-80">Envíos a</span><span class="block text-[13px] font-semibold">todo el Perú</span></span>
                </span>

                @if ($categorias->isNotEmpty())
                    <details class="group relative shrink-0">
                        <summary class="flex h-10 cursor-pointer list-none items-center gap-1.5 rounded-md px-2.5 font-semibold whitespace-nowrap transition-colors select-none hover:bg-black/10 group-open:bg-black/15 group-open:before:fixed group-open:before:inset-0 group-open:before:z-30 group-open:before:cursor-default group-open:before:content-[''] [&::-webkit-details-marker]:hidden" data-menu-categorias>
                            @include('tienda.plaza.icono', ['n' => 'menu', 'clase' => 'size-4.5 lg:hidden'])Categorías
                            @include('tienda.plaza.icono', ['n' => 'abajo', 'clase' => 'size-3.5 opacity-80 transition-transform group-open:rotate-180'])
                        </summary>
                        <div class="absolute top-full left-0 z-40 mt-1 scroll-fino max-h-[calc(100dvh-5rem)] w-[min(92vw,40rem)] overflow-y-auto rounded-xl bg-white p-3 text-slate-900 shadow-2xl shadow-slate-900/20 ring-1 ring-slate-200">
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

                {{-- los atajos que no entran se deslizan; se desvanecen al borde para que no se vean cortados --}}
                <div class="flex min-w-0 flex-1 items-center gap-0.5 overflow-x-auto [mask-image:linear-gradient(to_right,black_calc(100%-2.5rem),transparent)] pr-8 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <a href="/catalogo" class="flex h-10 shrink-0 items-center rounded-md px-2.5 whitespace-nowrap transition-colors hover:bg-black/10 {{ $enCatalogo && ! $categoriaActiva ? 'bg-black/15 font-semibold' : '' }}">Tienda</a>
                    @foreach ($atajos as $cat)
                        <a href="{{ $cat->url }}" @if ($categoriaActiva === $cat->id) aria-current="true" @endif class="flex h-10 shrink-0 items-center rounded-md px-2.5 whitespace-nowrap transition-colors hover:bg-black/10 {{ $categoriaActiva === $cat->id ? 'bg-black/15 font-semibold' : '' }}">{{ $cat->nombre }}</a>
                    @endforeach
                </div>

                @if ($marcasTienda->isNotEmpty())
                    <details class="group relative hidden shrink-0 md:block">
                        <summary class="flex h-10 cursor-pointer list-none items-center gap-1.5 rounded-md px-2.5 whitespace-nowrap transition-colors select-none hover:bg-black/10 group-open:bg-black/15 group-open:before:fixed group-open:before:inset-0 group-open:before:z-30 group-open:before:cursor-default group-open:before:content-[''] [&::-webkit-details-marker]:hidden" data-menu-marcas>
                            Marcas
                            @include('tienda.plaza.icono', ['n' => 'abajo', 'clase' => 'size-3.5 opacity-80 transition-transform group-open:rotate-180'])
                        </summary>
                        <ul class="absolute top-full right-0 z-40 mt-1 max-h-[60vh] w-64 overflow-y-auto rounded-xl bg-white p-2 text-slate-900 shadow-2xl shadow-slate-900/20 ring-1 ring-slate-200">
                            @foreach ($marcasTienda as $m)
                                <li><a href="/catalogo?marca={{ urlencode($m['nombre']) }}" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 font-medium text-slate-700 hover:bg-(--marca-suave) hover:text-(--marca-texto)"><span class="truncate">{{ $m['nombre'] }}</span><span class="text-xs font-normal text-slate-400 tabular-nums">{{ $m['productos'] }}</span></a></li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <div class="hidden shrink-0 items-center gap-0.5 lg:flex">
                    @foreach ($paginasMenu as $enlacePagina)
                        <a href="{{ $enlacePagina['url'] }}" class="hidden h-10 items-center rounded-md px-2.5 whitespace-nowrap transition-colors hover:bg-black/10 xl:flex">{{ $enlacePagina['titulo'] }}</a>
                    @endforeach
                    <a href="{{ $enlaceContacto }}" class="flex h-10 items-center rounded-md px-2.5 whitespace-nowrap transition-colors hover:bg-black/10">Contacto</a>
                    @if ($whatsapp)
                        <button type="button" data-pedido-abrir data-necesita-js aria-label="Ver mi carrito"
                            class="ml-2 inline-flex h-9 cursor-pointer items-center gap-2 rounded-full bg-(--plaza-acento) px-4 text-sm font-bold text-(--sobre-acento) shadow-sm transition hover:brightness-110">
                            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-4.5'])Carrito
                            <span data-pedido-cuenta class="hidden min-w-5 rounded-full bg-white px-1.5 text-center text-[11px] leading-5 font-bold text-slate-900"></span>
                        </button>
                    @endif
                </div>
            </div>
        </nav>
    </header>

    <main id="contenido" class="flex-1">
        @yield('contenido')
    </main>

    {{-- Beneficios: en la portada van bajo la campaña; en las demás páginas, antes del pie --}}
    @unless ($enPortada)
        <section class="mx-auto mt-12 w-full max-w-7xl px-3 sm:px-6 lg:px-8" aria-label="Cómo te atendemos" data-sellos>
            @include('tienda.plaza.beneficios')
        </section>
    @endunless

    {{-- Pie oscuro en columnas --}}
    <footer id="contacto" class="mt-12 bg-(--plaza-oscuro) text-white">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-12 lg:px-8">
            <div class="lg:col-span-4">
                <div class="flex items-center gap-3">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo_ligero'] }}" alt="" class="size-11 rounded-lg bg-white object-contain p-0.5">
                    @else
                        <span class="grid size-11 place-items-center rounded-lg bg-(--marca) text-lg font-extrabold text-(--sobre-marca)">{{ $tienda['inicial'] }}</span>
                    @endif
                    <span class="text-xl leading-tight font-extrabold tracking-tight">{{ $tienda['nombre'] }}</span>
                </div>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/75">{{ $tienda['descripcion'] ?: 'Mira nuestro catálogo en línea y haz tu pedido en un momento. Te atendemos con gusto.' }}</p>
                @if ($contactos['facebook'] || $contactos['instagram'] || $contactos['tiktok'])
                    <div class="mt-5 flex gap-2">
                        @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $red => $nombreRed)
                            @if ($contactos[$red])
                                <a href="{{ $contactos[$red] }}" target="_blank" rel="noopener" aria-label="{{ $nombreRed }}" title="{{ $nombreRed }}" class="grid size-11 place-items-center rounded-full bg-white/10 ring-1 ring-white/15 transition-colors hover:bg-(--plaza-acento) hover:text-(--sobre-acento)">
                                    @include('tienda.icono', ['n' => $red, 'clase' => 'size-4'])
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="lg:col-span-2">
                <h2 class="text-[15px] font-bold">Comprar</h2>
                <ul class="mt-4 space-y-2.5 text-sm text-white/75">
                    <li><a href="/catalogo" class="hover:text-white hover:underline">Toda la tienda</a></li>
                    @foreach ($categorias->take(6) as $cat)
                        <li><a href="{{ $cat->url }}" class="hover:text-white hover:underline">{{ $cat->nombre }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-[15px] font-bold">Ayuda</h2>
                <ul class="mt-4 space-y-2.5 text-sm text-white/75" data-paginas>
                    @foreach ($paginas as $enlacePagina)
                        <li><a href="{{ $enlacePagina['url'] }}" class="hover:text-white hover:underline">{{ $enlacePagina['titulo'] }}</a></li>
                    @endforeach
                    @unless (collect($paginas)->contains('url', '/contacto'))
                        <li><a href="#contacto" class="hover:text-white hover:underline">Contáctanos</a></li>
                    @endunless
                    @if ($whatsapp)
                        <li><a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="hover:text-white hover:underline">Pedir por WhatsApp</a></li>
                    @endif
                    <li><a href="/libro-de-reclamaciones" class="hover:text-white hover:underline">Libro de Reclamaciones</a></li>
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-[15px] font-bold">Contacto</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @if ($whatsapp)
                        <li><a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'mt-0.5 size-4 text-[#25D366]'])<span><span class="block text-[11px] text-white/60">Asesoría de compra</span><span class="font-bold">{{ $contactos['whatsapp_texto'] }}</span></span></a></li>
                    @endif
                    @if ($contactos['telefono'])
                        <li><a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'telefono', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] text-white/60">Teléfono</span><span class="font-semibold">{{ $contactos['telefono'] }}</span></span></a></li>
                    @endif
                    @if ($contactos['email'])
                        <li><a href="mailto:{{ $contactos['email'] }}" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'correo', 'clase' => 'mt-0.5 size-4 opacity-70'])<span class="min-w-0"><span class="block text-[11px] text-white/60">Correo</span><span class="block truncate font-semibold">{{ $contactos['email'] }}</span></span></a></li>
                    @endif
                    @foreach ($contactos['direcciones'] as $lugar)
                        <li><a href="{{ $lugar['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] text-white/60">Visítanos</span><span class="font-semibold">{{ $lugar['texto'] }}</span></span></a></li>
                    @endforeach
                    @foreach ($contactos['locales'] as $local)
                        <li><a href="{{ $local['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-2.5 hover:underline">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] text-white/60">{{ $local['nombre'] }}</span><span class="font-semibold">{{ $local['direccion'] }}</span></span></a></li>
                    @endforeach
                    @if ($contactos['horarios'])
                        <li class="flex items-start gap-2.5">@include('tienda.icono', ['n' => 'reloj', 'clase' => 'mt-0.5 size-4 opacity-70'])<span><span class="block text-[11px] text-white/60">Atención</span>@foreach ($contactos['horarios'] as $linea)<span class="block font-semibold">{{ $linea }}</span>@endforeach</span></li>
                    @endif
                    @if (! $whatsapp && ! $contactos['telefono'] && ! $contactos['email'] && ! $contactos['direcciones'])
                        <li class="text-white/75">Pronto publicaremos nuestros datos de contacto.</li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-4 text-xs text-white/65 sm:flex-row sm:px-6 lg:px-8">
                <span class="text-center sm:text-left">© {{ now()->year }} {{ $legal['razon_social'] }} · RUC {{ $legal['ruc'] }}</span>
                <a href="/libro-de-reclamaciones" class="inline-flex items-center gap-1.5 font-semibold hover:text-white hover:underline" data-libro-enlace>@include('tienda.icono', ['n' => 'libro', 'clase' => 'size-4'])Libro de Reclamaciones</a>
                <a href="{{ config('app.url') }}" target="_blank" rel="noopener" class="hover:text-white hover:underline">Tienda creada con <span class="font-bold">inkaPos</span></a>
            </div>
        </div>
    </footer>

    {{-- WhatsApp flotante, siempre a la vista y con su puntito rojo (lo reemplaza el chatbot si está encendido) --}}
    @if ($whatsapp && ! $asistente)
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp" data-whatsapp-flotante
            class="group fixed right-3 bottom-3 z-40 h-11 items-center rounded-full bg-[#25D366] text-white shadow-lg ring-2 shadow-black/25 ring-white transition-colors hover:bg-[#1eb957] sm:right-6 sm:bottom-6 sm:h-14 sm:ring-4 {{ $__env->hasSection('barra_movil') ? 'hidden sm:flex' : 'flex' }}">
            {{-- puntito rojo, como un mensaje sin leer: invita a tocar --}}
            <span class="absolute top-0 right-0 size-3.5 rounded-full bg-red-500 ring-2 ring-white" aria-hidden="true" data-whatsapp-aviso><span class="absolute inset-0 animate-ping rounded-full bg-red-500 opacity-75 motion-reduce:animate-none"></span></span>
            <span class="grid size-11 shrink-0 place-items-center sm:size-14">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-6 sm:size-7'])</span>
            <span class="hidden max-w-0 overflow-hidden text-sm font-semibold whitespace-nowrap transition-[max-width,padding] duration-300 group-hover:max-w-48 group-hover:pr-5 lg:block">Consulta precios aquí</span>
        </a>
    @endif
    @if ($asistente)
        {{-- en el celular el avatar va más chico: a tamaño completo tapaba el botón de la tarjeta de la derecha --}}
        @include('tienda.asistente', ['flotanteCompacto' => true])
    @endif
    @yield('barra_movil')

    {{-- El carrito: lo que el cliente va agregando, para enviarlo completo por WhatsApp (lo maneja tienda.js) --}}
    @if ($whatsapp)
        <button type="button" data-pedido-abrir data-pedido-flotante data-necesita-js aria-label="Ver mi carrito"
            class="fixed bottom-4 left-4 z-40 hidden size-14 cursor-pointer place-items-center rounded-full bg-(--plaza-acento) text-(--sobre-acento) shadow-lg ring-4 shadow-black/25 ring-white transition hover:brightness-110 sm:right-6 sm:bottom-[6.5rem] sm:left-auto [&:not(.hidden)]:grid {{ $__env->hasSection('barra_movil') ? 'max-sm:!hidden' : '' }}">
            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-6'])
            <span data-pedido-cuenta class="absolute -top-1 -right-1 hidden min-w-6 rounded-full bg-white px-1.5 text-center text-xs leading-6 font-bold text-slate-900 ring-2 ring-(--plaza-acento)"></span>
        </button>

        <div id="pedido-aviso" class="fixed inset-x-4 bottom-4 z-50 mx-auto hidden max-w-md items-center gap-3 rounded-xl bg-(--plaza-oscuro) py-3 pr-3 pl-4 text-sm text-white shadow-2xl [&:not(.hidden)]:flex" role="status" aria-live="polite">
            @include('tienda.icono', ['n' => 'check', 'clase' => 'size-5 text-emerald-400'])
            <span data-aviso-texto class="min-w-0 flex-1 truncate"></span>
            <button type="button" data-pedido-abrir class="h-9 shrink-0 cursor-pointer rounded-lg bg-white px-3.5 text-sm font-semibold text-slate-900 transition-colors hover:bg-slate-100">Ver carrito</button>
        </div>

        <div id="pedido" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="titulo-pedido">
            <div class="absolute inset-0 bg-slate-950/50" data-pedido-cerrar></div>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="titulo-pedido" class="text-lg font-bold tracking-tight">Tu carrito</h2>
                        <p class="text-sm text-slate-500">Lo envías por WhatsApp y te lo confirmamos.</p>
                    </div>
                    <button type="button" data-pedido-cerrar data-pedido-cerrar-boton aria-label="Cerrar" class="grid size-10 cursor-pointer place-items-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900">
                        @include('tienda.icono', ['n' => 'cerrar', 'clase' => 'size-5'])
                    </button>
                </div>

                <div data-pedido-vacio class="flex flex-1 flex-col items-center justify-center px-8 text-center">
                    <span class="grid size-16 place-items-center rounded-full bg-(--marca-suave) text-(--marca-texto)">@include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-7'])</span>
                    <p class="mt-5 text-lg font-bold tracking-tight">Tu carrito está vacío</p>
                    <p class="mt-1 text-slate-500">Toca "Agregar" en los productos que quieras y aquí se irán sumando.</p>
                    <a href="/catalogo" class="mt-6 inline-flex h-11 items-center gap-2 rounded-lg bg-(--marca) px-5 text-sm font-bold text-(--sobre-marca) transition hover:brightness-110">Ver la tienda @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>

                <ul data-pedido-lista class="hidden flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain"></ul>

                <div data-pedido-pie class="hidden border-t border-slate-200 bg-slate-50 px-5 pt-4 pb-5">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <label for="pedido-nombre" class="sr-only">Tu nombre</label>
                            <input id="pedido-nombre" data-pedido-nombre type="text" maxlength="60" autocomplete="name" placeholder="Tu nombre (opcional)"
                                class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm placeholder-slate-400 focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        </div>
                        <div>
                            <label for="pedido-nota" class="sr-only">Nota para la tienda</label>
                            <input id="pedido-nota" data-pedido-nota type="text" maxlength="160" placeholder="Nota: envío, recojo... (opcional)"
                                class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm placeholder-slate-400 focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        </div>
                    </div>
                    <p data-pedido-total class="mt-4 flex items-baseline justify-between gap-3"></p>
                    <a href="{{ $whatsapp }}" data-pedido-enviar target="_blank" rel="noopener"
                        class="mt-3 flex h-13 items-center justify-center gap-2 rounded-lg bg-[#25D366] px-6 text-base font-bold text-white shadow-sm transition-colors hover:bg-[#1eb957]">
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
