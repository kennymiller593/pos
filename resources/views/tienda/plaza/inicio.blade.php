@extends('tienda.plaza.layout')

{{-- Plantilla Plaza: portada (campaña, beneficios, destacados, categorías, promociones) y catálogo con filtros al costado --}}
@php
    $pagina = $productos->currentPage();
    $base = $categoria ? $categoria->url : '/catalogo';
    // enlaces de los filtros: cada uno cambia una cosa y conserva lo demás (búsqueda, orden, marca, precio tope)
    $vigentes = array_filter(['q' => $buscar ?: null, 'orden' => $orden !== 'nombre' ? $orden : null, 'marca' => $marca ?: null, 'hasta' => $hasta]);
    $enlace = function (?string $ruta = null, array $cambios = []) use ($base, $vigentes) {
        $parametros = http_build_query(array_filter([...$vigentes, ...$cambios], fn ($v) => $v !== null && $v !== ''));

        return ($ruta ?? $base).($parametros !== '' ? "?{$parametros}" : '');
    };
    $canonica = rtrim($tienda['url'], '/').($categoria ? $categoria->url : ($portada ? '/' : '/catalogo')).($pagina > 1 ? "?page={$pagina}" : '');

    // la foto de portada del dueño va de fondo en la campaña; sin foto, la campaña es del color de la tienda
    $fondoPortada = $portada ? $tienda['portada']['imagen'] : null;

    // datos estructurados del negocio: el buscador puede mostrar teléfono, dirección, horario y mapa
    $direccionPostal = fn (string $calle) => array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $calle,
        'addressLocality' => $contactos['ciudad'],
        'addressRegion' => $contactos['region'],
        'addressCountry' => 'PE',
    ]);
    $telefonoNegocio = $contactos['telefono'] ?: ($contactos['whatsapp'] ? '+'.$contactos['whatsapp'] : null);
    // los demás locales (direcciones escritas por el dueño y sucursales con dirección)
    $otrasSedes = array_merge(
        array_map(fn ($d) => ['@type' => 'Store', 'name' => $tienda['nombre'], 'address' => $direccionPostal($d['texto']), 'hasMap' => $d['mapa']], array_slice($contactos['direcciones'], 1)),
        array_map(fn ($l) => array_filter(['@type' => 'Store', 'name' => "{$tienda['nombre']} · {$l['nombre']}", 'address' => $direccionPostal($l['direccion']), 'telephone' => $l['telefono'], 'hasMap' => $l['mapa']]), $contactos['locales']),
    );
    $negocio = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        '@id' => rtrim($tienda['url'], '/').'/#negocio',
        'name' => $tienda['nombre'],
        'url' => $tienda['url'],
        'description' => $tienda['descripcion'],
        'image' => $tienda['logo'] ? (str_starts_with($tienda['logo'], 'http') ? $tienda['logo'] : rtrim($tienda['url'], '/').$tienda['logo']) : null,
        'telephone' => $telefonoNegocio,
        'email' => $contactos['email'],
        'address' => $contactos['direccion'] ? $direccionPostal($contactos['direccion']) : null,
        'hasMap' => $contactos['mapa'],
        'geo' => $contactos['coordenadas'] ? ['@type' => 'GeoCoordinates', 'latitude' => $contactos['coordenadas'][0], 'longitude' => $contactos['coordenadas'][1]] : null,
        'openingHoursSpecification' => $contactos['horario_estructurado'] ?: null,
        'legalName' => $legal['razon_social'],
        'taxID' => $legal['ruc'],
        'currenciesAccepted' => 'PEN',
        'sameAs' => array_values(array_filter([$contactos['facebook'], $contactos['instagram'], $contactos['tiktok']])) ?: null,
        'department' => $otrasSedes ?: null,
    ]);

    // la lista de productos de la página (catálogo y categorías), con su ruta de navegación
    $listaProductos = $portada ? null : [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $categoria ? $categoria->nombre : 'Catálogo',
        'url' => $canonica,
        'isPartOf' => ['@id' => rtrim($tienda['url'], '/').'/#sitio'],
        'breadcrumb' => [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_values(array_filter([
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => $tienda['url']],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Catálogo', 'item' => rtrim($tienda['url'], '/').'/catalogo'],
                $categoria ? ['@type' => 'ListItem', 'position' => 3, 'name' => $categoria->nombre, 'item' => rtrim($tienda['url'], '/').$categoria->url] : null,
            ])),
        ],
        'mainEntity' => [
            '@type' => 'ItemList',
            'numberOfItems' => $productos->total(),
            'itemListElement' => $productos->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => ($pagina - 1) * $productos->perPage() + $i + 1, 'name' => $p['modelo']['nombre'] ?? $p['nombre'], 'url' => rtrim($tienda['url'], '/').$p['url']])->all(),
        ],
    ];

    $lugar = $contactos['ciudad'] ? " en {$contactos['ciudad']}" : '';
    $resumenListado = match (true) {
        $buscar !== '' => null,
        $categoria !== null => ($categoria->texto ?? null) ?: "{$categoria->nombre}{$lugar}: {$productos->total()} ".($productos->total() === 1 ? 'producto' : 'productos')." en {$tienda['nombre']}. Pide por WhatsApp y te confirmamos disponibilidad y envío.",
        ! $portada => "Catálogo completo de {$tienda['nombre']}{$lugar}: {$productos->total()} productos en {$categorias->count()} categorías. ".($tienda['descripcion'] ?: 'Pide por WhatsApp.'),
        default => null,
    };
    if ($resumenListado && $pagina > 1) {
        $resumenListado = "Página {$pagina}. {$resumenListado}";
    }
    $hayFiltros = $categorias->isNotEmpty() || $marcas->isNotEmpty() || $precioMaximo > 0;
    $filtroPrecio = $hasta ?? $precioMaximo;
    // cada sección va en un panel blanco sobre el fondo gris
    $panel = 'rounded-xl bg-white p-4 shadow-sm ring-1 shadow-slate-900/5 ring-slate-200/70 sm:p-6';
@endphp
@if ($resumenListado)
    @section('resumen', $resumenListado)
@endif
@section('titulo', $buscar !== '' ? "“{$buscar}” en {$tienda['nombre']}" : ($categoria ? "{$categoria->nombre}".($pagina > 1 ? " · página {$pagina}" : '')." · {$tienda['nombre']}" : ($portada ? '' : "Catálogo de {$tienda['nombre']}".($pagina > 1 ? " · página {$pagina}" : ''))))
@section('canonica', $canonica)
@section('robots', $pagina > 1 || $buscar !== '' || $marca !== '' || $hasta !== null || $orden !== 'nombre' ? 'noindex,follow' : 'index,follow')

@if ($portada)
    @push('cabecera')
        <script type="application/ld+json">{!! json_encode($negocio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endpush
@elseif ($listaProductos && $buscar === '' && $marca === '' && $hasta === null)
    @push('cabecera')
        <script type="application/ld+json">{!! json_encode($listaProductos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endpush
@endif

@section('contenido')
    <div class="mx-auto max-w-7xl px-3 sm:px-6 lg:px-8">
        @if ($portada)
            @php
                // junto al título, hasta 2 destacados con foto dentro de círculos blancos
                $vitrina = ! $fondoPortada && $destacados ? $destacados['productos']->filter(fn ($p) => $p['imagen'] ?: ($p['modelo']['imagen'] ?? null))->take(2)->values() : collect();
                $soloFoto = $fondoPortada && $tienda['portada']['solo_foto'];
            @endphp
            @if ($soloFoto)
                {{-- La foto del dueño ya trae su propio texto: se muestra entera, sin recortes ni nada encima --}}
                <section class="relative mt-4 overflow-hidden rounded-xl bg-slate-100 sm:mt-6" data-portada="plaza" data-portada-sola>
                    <h1 class="sr-only">{{ $tienda['portada']['titulo'] }}</h1>
                    @include('tienda.portada-fotos', ['modo' => 'slider'])
                </section>
            @else
                {{-- Campaña: panel en el color de la tienda con un gran círculo en el color de acento --}}
                <section class="relative mt-4 overflow-hidden rounded-xl sm:mt-6 {{ $fondoPortada ? 'bg-neutral-900 text-white' : 'bg-(--marca) text-(--sobre-marca)' }}" data-portada="plaza" @if ($fondoPortada) data-portada-foto @endif>
                    @if ($fondoPortada)
                        {{-- la foto del dueño (o varias, que se van pasando), con velo para que el título se lea --}}
                        @include('tienda.portada-fotos', ['modo' => 'fondo'])
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-neutral-950/85 via-neutral-950/55 to-neutral-950/25" aria-hidden="true"></div>
                    @else
                        {{-- el círculo de acento solo va donde no pisa el título: en pantallas chicas es un brillo suave --}}
                        <div class="pointer-events-none absolute -top-24 -right-24 size-64 rounded-full bg-white/10 sm:size-80 lg:-top-32 lg:-right-20 lg:size-[30rem] lg:bg-(--plaza-acento) xl:-top-44 xl:-right-16 xl:size-[40rem]" aria-hidden="true"></div>
                        <div class="pointer-events-none absolute right-[34%] -bottom-48 hidden size-80 rounded-full bg-white/15 lg:block" aria-hidden="true"></div>
                    @endif
                    <div class="relative grid items-center gap-6 px-5 pt-9 pb-16 sm:px-10 sm:pt-12 sm:pb-20 lg:min-h-[25rem] lg:px-16 {{ $vitrina->isNotEmpty() ? 'lg:grid-cols-[1.15fr_1fr]' : '' }}">
                        <div class="max-w-xl lg:max-w-[28rem] xl:max-w-xl">
                            <h1 class="max-w-[85%] text-[2rem] leading-[1.05] font-extrabold tracking-tight text-balance sm:max-w-none sm:text-5xl lg:text-[3.4rem]">{{ $tienda['portada']['titulo'] }}</h1>
                            <p class="mt-4 max-w-lg text-[15px] leading-relaxed opacity-90 sm:text-lg">{{ $tienda['descripcion'] ?: 'Mira nuestro catálogo, busca lo que necesitas y haz tu pedido en un momento.' }}</p>
                            <div class="mt-7 flex flex-wrap gap-3">
                                <a href="/catalogo" class="inline-flex h-12 items-center gap-2 rounded-lg px-6 text-[15px] font-bold shadow-lg shadow-black/15 transition hover:brightness-110 {{ $fondoPortada ? 'bg-(--marca) text-(--sobre-marca)' : 'bg-(--plaza-acento) text-(--sobre-acento) ring-1 ring-white/25' }}">{{ $tienda['portada']['boton'] }}</a>
                                @if ($whatsapp)
                                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center gap-2 rounded-lg px-5 text-[15px] font-bold ring-1 ring-white/50 transition-colors hover:bg-white/10">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Pedir por WhatsApp</a>
                                @endif
                            </div>
                        </div>

                        @if ($vitrina->isNotEmpty())
                            @php
                                $primero = $vitrina[0];
                                $precioPrimero = $primero['modelo'] ? $primero['modelo']['precio_desde'] : $primero['precio'];
                                $segundo = $vitrina[1] ?? null;
                            @endphp
                            <div class="relative hidden h-80 lg:block" data-ofertas>
                                @if ($segundo)
                                    <a href="{{ $segundo['url'] }}" class="absolute bottom-2 left-0 grid size-40 place-items-center overflow-hidden rounded-full bg-white shadow-xl shadow-black/20 transition-transform duration-300 hover:scale-105" aria-label="{{ $segundo['modelo']['nombre'] ?? $segundo['nombre'] }}">
                                        <img src="{{ $segundo['imagen'] ?: $segundo['modelo']['imagen'] }}" alt="" loading="lazy" width="300" height="300" class="size-28 object-contain">
                                    </a>
                                @endif
                                <a href="{{ $primero['url'] }}" class="group/vitrina absolute top-1/2 right-4 grid size-72 -translate-y-1/2 place-items-center rounded-full bg-white shadow-2xl shadow-black/25" aria-label="{{ $primero['modelo']['nombre'] ?? $primero['nombre'] }}">
                                    <img src="{{ $primero['imagen'] ?: $primero['modelo']['imagen'] }}" alt="{{ $primero['modelo']['nombre'] ?? $primero['nombre'] }}" fetchpriority="high" width="500" height="500" class="size-52 object-contain transition-transform duration-300 group-hover/vitrina:scale-105">
                                    <span class="absolute right-0 -bottom-1 rounded-full bg-white px-5 py-2 text-xl font-extrabold tracking-tight text-(--marca-texto) shadow-lg ring-1 shadow-black/15 ring-slate-200">
                                        @if ($precioPrimero !== null)
                                            @if ($primero['modelo'] && $primero['modelo']['varios_precios'])<span class="text-sm font-semibold text-slate-500">Desde</span>@endif
                                            S/ {{ number_format($precioPrimero, 2) }}
                                        @else
                                            {{ match ($destacados['origen']) { 'nuevos' => 'Nuevo', 'vendidos' => 'Más pedido', default => 'Destacado' } }}
                                        @endif
                                    </span>
                                </a>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Beneficios: tres tarjetas montadas sobre el borde de la campaña --}}
            <section class="relative {{ $soloFoto ? 'mt-4' : '-mt-9 sm:mx-0' }}" aria-label="Cómo te atendemos" data-sellos>
                @include('tienda.plaza.beneficios')
            </section>

            {{-- Destacados: los que eligió el dueño, los más pedidos o las novedades --}}
            @if ($destacados && $destacados['productos']->isNotEmpty())
                @php
                    [$tituloDestacados, $etiquetaDestacados] = match ($destacados['origen']) {
                        'nuevos' => ['Recién llegados', 'Novedades'],
                        'vendidos' => ['Lo más pedido', 'Favoritos'],
                        default => ['Productos destacados', 'Selección de la tienda'],
                    };
                    $tituloDestacados = $destacados['titulo'] ?? $tituloDestacados;
                    $listaDestacados = $destacados['productos']->take(6);
                    // con pocos destacados las tarjetas se ensanchan para llenar la fila
                    $columnasDestacados = match (true) {
                        $listaDestacados->count() <= 4 => 'lg:grid-cols-4',
                        $listaDestacados->count() === 5 => 'xl:grid-cols-5',
                        default => 'xl:grid-cols-6',
                    };
                @endphp
                <section class="mt-6 {{ $panel }}" aria-labelledby="titulo-destacados" data-destacados>
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                            <h2 id="titulo-destacados" class="text-xl font-bold tracking-tight sm:text-2xl">{{ $tituloDestacados }}</h2>
                            <span class="rounded-md bg-(--plaza-oscuro) px-2.5 py-1 text-xs font-bold text-white">{{ $etiquetaDestacados }}</span>
                        </div>
                        <a href="/catalogo" class="text-sm font-medium text-(--marca-texto) hover:underline">Ver todos</a>
                    </div>
                    {{-- en celular se deslizan de lado, como en las tiendas grandes --}}
                    <div class="-mx-4 mt-4 flex snap-x gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 sm:pb-0 lg:gap-4 {{ $columnasDestacados }} [&::-webkit-scrollbar]:hidden">
                        @foreach ($listaDestacados as $p)
                            <div class="w-[46%] shrink-0 snap-start sm:w-auto">
                                @include('tienda.plaza.tarjeta', ['p' => $p, 'insignia' => $destacados['origen'] === 'nuevos' ? 'nuevo' : null])
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Categorías en círculos --}}
            @if ($categorias->count() >= 3)
                <section class="mt-6 {{ $panel }}" aria-labelledby="titulo-categorias" data-categorias>
                    <div class="flex items-center justify-between gap-4">
                        <h2 id="titulo-categorias" class="text-xl font-bold tracking-tight sm:text-2xl">Categorías populares</h2>
                        <a href="/catalogo" class="text-sm font-medium text-(--marca-texto) hover:underline">Ver todas</a>
                    </div>
                    <div class="-mx-4 mt-4 flex snap-x gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0 lg:grid lg:grid-cols-8 lg:gap-3 lg:overflow-visible lg:pb-0 [&::-webkit-scrollbar]:hidden">
                        @foreach ($categorias->take(8) as $cat)
                            <a href="{{ $cat->url }}" class="group w-24 shrink-0 snap-start text-center sm:w-28 lg:w-auto">
                                <span class="mx-auto grid size-20 place-items-center overflow-hidden rounded-full bg-(--marca-suave) text-2xl font-extrabold text-(--marca-texto) ring-1 ring-slate-200 transition duration-300 group-hover:ring-2 group-hover:ring-(--marca) sm:size-24">
                                    @if ($cat->imagen)
                                        <img src="{{ $cat->imagen }}" alt="" loading="lazy" width="160" height="160" class="size-full object-contain p-4 mix-blend-multiply transition-transform duration-300 group-hover:scale-110">
                                    @else
                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($cat->nombre, 0, 1)) }}
                                    @endif
                                </span>
                                <span class="mt-2.5 line-clamp-2 text-sm leading-tight text-slate-700 group-hover:text-(--marca-texto)">{{ $cat->nombre }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Promociones: los banners del dueño; si no subió ninguno, dos paneles con lo que la tienda ofrece --}}
            @if ($banners)
                <section class="mt-6 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-1 [scrollbar-width:none] sm:grid sm:overflow-visible sm:pb-0 [&::-webkit-scrollbar]:hidden {{ count($banners) > 1 ? 'sm:grid-cols-2' : '' }}" aria-label="Promociones" data-banners>
                    @foreach ($banners as $banner)
                        @php $etiqueta = $banner['enlace'] ? 'a' : 'div'; @endphp
                        <{{ $etiqueta }} @if ($banner['enlace']) href="{{ $banner['enlace'] }}" @if ($banner['externo']) target="_blank" rel="noopener" @endif @endif class="group relative block w-[85%] shrink-0 snap-start overflow-hidden rounded-xl bg-slate-100 shadow-sm ring-1 ring-slate-200/70 sm:w-auto">
                            <img src="{{ $banner['imagen'] }}" alt="{{ $banner['titulo'] ?? '' }}" width="1600" height="600" loading="lazy" class="aspect-[16/6] w-full object-cover transition-transform duration-500 {{ $banner['enlace'] ? 'group-hover:scale-[1.02]' : '' }}">
                            @if ($banner['titulo'])
                                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/80 to-transparent px-5 pt-10 pb-4 text-lg font-bold text-white">{{ $banner['titulo'] }}</span>
                            @endif
                        </{{ $etiqueta }}>
                    @endforeach
                </section>
            @else
                <section class="mt-6 grid gap-4 md:grid-cols-2" aria-label="Lo que te ofrecemos" data-promos>
                    <a href="/catalogo" class="group relative flex min-h-40 items-center justify-between gap-5 overflow-hidden rounded-xl bg-(--plaza-acento) px-6 py-7 text-(--sobre-acento) shadow-sm sm:px-10 sm:py-9">
                        <span class="min-w-0">
                            <span class="block text-2xl leading-tight font-extrabold tracking-tight text-balance sm:text-[1.75rem]">Envíos a todo el Perú</span>
                            <span class="mt-2 block max-w-xs text-sm leading-relaxed opacity-90">{{ $entrega['envios'] ?: 'Coordinamos la entrega contigo al confirmar tu pedido.' }}</span>
                        </span>
                        @include('tienda.plaza.icono', ['n' => 'caja', 'clase' => 'size-20 opacity-90 transition-transform duration-300 group-hover:scale-110 sm:size-24', 'grosor' => 1.1])
                    </a>
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="group relative flex min-h-40 items-center justify-between gap-5 overflow-hidden rounded-xl bg-(--plaza-oscuro) px-6 py-7 text-white shadow-sm sm:px-10 sm:py-9">
                            <span class="min-w-0">
                                <span class="block text-2xl leading-tight font-extrabold tracking-tight text-balance sm:text-[1.75rem]">Haz tu pedido por WhatsApp</span>
                                <span class="mt-2 block max-w-xs text-sm leading-relaxed text-white/80">Arma tu carrito y envíalo en un solo mensaje. Te confirmamos al momento.</span>
                            </span>
                            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-16 text-[#25D366] transition-transform duration-300 group-hover:scale-110 sm:size-20'])
                        </a>
                    @else
                        <a href="/catalogo" class="group relative flex min-h-40 items-center justify-between gap-5 overflow-hidden rounded-xl bg-(--plaza-oscuro) px-6 py-7 text-white shadow-sm sm:px-10 sm:py-9">
                            <span class="min-w-0">
                                <span class="block text-2xl leading-tight font-extrabold tracking-tight text-balance sm:text-[1.75rem]">Todo el catálogo en línea</span>
                                <span class="mt-2 block max-w-xs text-sm leading-relaxed text-white/80">{{ number_format($tienda['productos']) }} productos con sus precios y presentaciones.</span>
                            </span>
                            @include('tienda.plaza.icono', ['n' => 'bolsa', 'clase' => 'size-20 opacity-90 transition-transform duration-300 group-hover:scale-110 sm:size-24', 'grosor' => 1.1])
                        </a>
                    @endif
                </section>
            @endif

            @if ($conDiagnostico ?? false)
                @include('tienda.diagnostico-promo', ['clase' => 'mt-6'])
            @endif
        @else
            <nav class="mt-5 flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
                <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                @if ($categoria || $buscar !== '')
                    <a href="/catalogo" class="hover:text-(--marca-texto)">Tienda</a>
                    @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                    <span class="text-slate-800">{{ $categoria ? $categoria->nombre : 'Búsqueda' }}</span>
                @else
                    <span class="text-slate-800">Tienda</span>
                @endif
            </nav>
        @endif

        {{-- Catálogo: filtros al costado y cuadrícula --}}
        <section id="catalogo" class="grid gap-4 lg:gap-6 {{ $portada ? 'mt-6' : 'mt-4' }} {{ ! $portada && $hayFiltros ? 'lg:grid-cols-[15rem_1fr]' : '' }}" aria-labelledby="titulo-catalogo">
            @if (! $portada && $hayFiltros)
                {{-- en el celular los filtros se abren con un toque; en pantalla grande van siempre a la vista --}}
                <div class="min-w-0">
                    <details class="group rounded-xl bg-white shadow-sm ring-1 shadow-slate-900/5 ring-slate-200/70 lg:hidden" {{ $marca !== '' || $hasta !== null ? 'open' : '' }} data-filtros>
                        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold select-none [&::-webkit-details-marker]:hidden">
                            <span class="flex items-center gap-2">@include('tienda.icono', ['n' => 'cuadricula', 'clase' => 'size-4 text-(--marca-texto)'])Filtrar productos</span>
                            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-4 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                        </summary>
                        <div class="space-y-6 border-t border-slate-100 px-4 pt-4 pb-5">
                            @include('tienda.mercado.filtros', ['sufijo' => '-movil'])
                        </div>
                    </details>
                    <aside class="hidden self-start rounded-xl bg-white p-5 shadow-sm ring-1 shadow-slate-900/5 ring-slate-200/70 lg:sticky lg:top-32 lg:block lg:space-y-6" aria-label="Filtros" data-filtros-pc>
                        @include('tienda.mercado.filtros')
                    </aside>
                </div>
            @endif

            <div class="min-w-0 {{ $panel }}">
                <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                    <div class="min-w-0">
                        @php $etiquetaTitulo = $portada ? 'h2' : 'h1'; @endphp
                        <{{ $etiquetaTitulo }} id="titulo-catalogo" class="text-xl font-bold tracking-tight sm:text-2xl">
                            @if ($portada)
                                Explora el catálogo
                            @elseif ($buscar !== '')
                                Resultados para “{{ $buscar }}”
                            @elseif ($categoria)
                                {{ $categoria->nombre }}@if ($contactos['ciudad']) <span class="font-semibold text-slate-500">en {{ $contactos['ciudad'] }}</span>@endif
                            @else
                                Todos los productos
                            @endif
                        </{{ $etiquetaTitulo }}>
                        @if ($categoria && ($categoria->texto ?? null) && $buscar === '' && $pagina === 1)
                            <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-slate-600">{{ $categoria->texto }}</p>
                        @endif
                        @unless ($portada)
                            <p class="mt-1 text-sm text-slate-500">
                                {{ number_format($productos->total()) }} producto{{ $productos->total() === 1 ? '' : 's' }}@if ($marca !== '') de {{ $marca }}@endif @if ($hasta !== null) hasta S/ {{ number_format($hasta, 0, '.', ',') }}@endif
                                @if ($filtrando) · <a href="/catalogo" class="font-semibold text-(--marca-texto) hover:underline">Ver todo</a> @endif
                            </p>
                        @endunless
                    </div>

                    @if ($portada && $productos->hasMorePages())
                        <a href="/catalogo" class="text-sm font-medium text-(--marca-texto) hover:underline">Ver más</a>
                    @elseif (! $portada && $tienda['mostrar_precios'] && $productos->total() > 1)
                        <form action="{{ $base }}" method="get" class="flex items-center gap-2">
                            @foreach ($vigentes as $clave => $valor)
                                @if ($clave !== 'orden') <input type="hidden" name="{{ $clave }}" value="{{ $valor }}"> @endif
                            @endforeach
                            <label for="orden" class="text-sm text-slate-500">Ordenar:</label>
                            <select id="orden" name="orden" class="h-10 rounded-lg border border-slate-200 bg-white pr-8 pl-3 text-sm font-semibold focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                                <option value="nombre" @selected($orden === 'nombre')>Nombre</option>
                                <option value="menor" @selected($orden === 'menor')>Menor precio</option>
                                <option value="mayor" @selected($orden === 'mayor')>Mayor precio</option>
                            </select>
                            <noscript><button type="submit" class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium">Aplicar</button></noscript>
                        </form>
                    @endif
                </div>

                @if ($productos->isEmpty())
                    <div class="px-2 py-14 text-center">
                        <span class="mx-auto grid size-14 place-items-center rounded-full bg-(--marca-suave) text-(--marca-texto)">@include('tienda.icono', ['n' => $filtrando ? 'buscar' : 'paquete', 'clase' => 'size-6'])</span>
                        <p class="mt-5 text-lg font-bold tracking-tight">{{ $filtrando ? 'No encontramos productos con esos filtros' : 'Pronto verás aquí nuestros productos' }}</p>
                        <p class="mx-auto mt-1.5 max-w-sm text-slate-500">
                            @if ($filtrando)
                                Prueba con otra palabra o quita algún filtro.@if ($whatsapp) Si buscas algo en especial, escríbenos. @endif
                            @else
                                Estamos preparando el catálogo.@if ($whatsapp) Mientras tanto, escríbenos y te atendemos. @endif
                            @endif
                        </p>
                        <div class="mt-6 flex flex-wrap justify-center gap-2">
                            @if ($filtrando)
                                <a href="/catalogo" class="inline-flex h-11 items-center rounded-lg bg-white px-5 text-sm font-bold ring-1 ring-slate-200 hover:ring-slate-300">Ver toda la tienda</a>
                            @endif
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-lg bg-(--marca) px-5 text-sm font-bold text-(--sobre-marca) hover:brightness-110">@include('tienda.icono', ['n' => 'whatsapp'])Preguntar por WhatsApp</a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 lg:gap-4 {{ ! $portada && $hayFiltros ? 'xl:grid-cols-4' : 'lg:grid-cols-4 xl:grid-cols-6' }}">
                        @foreach ($productos as $p)
                            @include('tienda.plaza.tarjeta', ['p' => $p, 'prioritaria' => ! $portada && $loop->index < 4])
                        @endforeach
                    </div>

                    @if ($portada)
                        @if ($productos->hasMorePages())
                            <div class="mt-6 text-center">
                                <a href="/catalogo" class="inline-flex h-12 items-center gap-2 rounded-lg bg-(--marca) px-6 text-sm font-bold text-(--sobre-marca) shadow-sm transition hover:brightness-110">Ver los {{ number_format($productos->total()) }} productos @include('tienda.icono', ['n' => 'flecha'])</a>
                            </div>
                        @endif
                    @else
                        {{ $productos->onEachSide(1)->links('tienda.paginacion') }}
                    @endif
                @endif
            </div>
        </section>

        {{-- Sobre nosotros --}}
        @if ($portada && filled($nosotros))
            <section class="mt-6 {{ $panel }} lg:flex lg:items-center lg:gap-10" aria-labelledby="titulo-nosotros">
                <h2 id="titulo-nosotros" class="text-xl font-bold tracking-tight sm:text-2xl lg:w-1/3">Sobre {{ $tienda['nombre'] }}</h2>
                <div class="mt-3 lg:mt-0 lg:flex-1">
                    <p class="leading-relaxed text-slate-600">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($nosotros), 320) }}</p>
                    <a href="/nosotros" class="mt-3 inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Conocer más @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>
            </section>
        @endif
    </div>
@endsection
