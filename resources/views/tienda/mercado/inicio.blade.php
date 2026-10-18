@extends('tienda.mercado.layout')

{{-- Plantilla Mercado: portada (campaña), catálogo con filtros al costado y cuadrícula de productos --}}
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

    // la campaña de la portada: la foto de portada; si no hay, el primer banner; si no, solo el texto
    $fotoCampana = $portada ? ($tienda['portada']['imagen'] ?: ($banners[0]['imagen'] ?? null)) : null;
    $bannersRestantes = $portada ? array_values(array_filter($banners, fn ($b) => $b['imagen'] !== $fotoCampana || $tienda['portada']['imagen'])) : [];

    // datos estructurados: el negocio en la portada, la lista de productos en el catálogo y las categorías
    $direccionPostal = fn (string $calle) => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $calle, 'addressLocality' => $contactos['ciudad'], 'addressRegion' => $contactos['region'], 'addressCountry' => 'PE']);
    $negocio = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        '@id' => rtrim($tienda['url'], '/').'/#negocio',
        'name' => $tienda['nombre'],
        'url' => $tienda['url'],
        'description' => $tienda['descripcion'],
        'image' => $tienda['logo'] ? (str_starts_with($tienda['logo'], 'http') ? $tienda['logo'] : rtrim($tienda['url'], '/').$tienda['logo']) : null,
        'telephone' => $contactos['telefono'] ?: ($contactos['whatsapp'] ? '+'.$contactos['whatsapp'] : null),
        'email' => $contactos['email'],
        'address' => $contactos['direccion'] ? $direccionPostal($contactos['direccion']) : null,
        'hasMap' => $contactos['mapa'],
        'openingHoursSpecification' => $contactos['horario_estructurado'] ?: null,
        'currenciesAccepted' => 'PEN',
        'sameAs' => array_values(array_filter([$contactos['facebook'], $contactos['instagram'], $contactos['tiktok']])) ?: null,
    ]);
    $listaProductos = $portada ? null : [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $categoria ? $categoria->nombre : 'Catálogo',
        'url' => $canonica,
        'isPartOf' => ['@id' => rtrim($tienda['url'], '/').'/#sitio'],
        'mainEntity' => [
            '@type' => 'ItemList',
            'numberOfItems' => $productos->total(),
            'itemListElement' => $productos->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => ($pagina - 1) * $productos->perPage() + $i + 1, 'name' => $p['nombre'], 'url' => rtrim($tienda['url'], '/').$p['url']])->all(),
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
@endphp
@if ($resumenListado)
    @section('resumen', $resumenListado)
@endif
@section('titulo', $buscar !== '' ? "“{$buscar}” en {$tienda['nombre']}" : ($categoria ? "{$categoria->nombre} · {$tienda['nombre']}" : ($portada ? '' : "Catálogo de {$tienda['nombre']}".($pagina > 1 ? " · página {$pagina}" : ''))))
@section('canonica', $canonica)
@section('robots', $buscar !== '' || $marca !== '' || $hasta !== null ? 'noindex,follow' : 'index,follow')

@if ($portada)
    @push('cabecera')
        <script type="application/ld+json">{!! json_encode($negocio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endpush
@elseif ($listaProductos && ! $filtrando)
    @push('cabecera')
        <script type="application/ld+json">{!! json_encode($listaProductos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endpush
@endif

@section('contenido')
    <div class="mx-auto max-w-[100rem] px-3 sm:px-6 lg:px-8">
        @if ($portada)
            @php
                // la campaña: a la derecha, hasta 3 destacados con foto como "ofertas"; si no hay, la foto de portada
                $ofertas = $destacados ? $destacados['productos']->filter(fn ($p) => $p['imagen'])->take(3)->values() : collect();
                $conLateral = $ofertas->isNotEmpty() || $fotoCampana;
            @endphp
            {{-- Campaña: panel en el color de la tienda con el título, los botones y los productos de la semana --}}
            <section class="relative mt-4 overflow-hidden rounded-3xl bg-(--marca) text-(--sobre-marca) sm:mt-6" data-portada="mercado">
                <div class="pointer-events-none absolute -top-24 -right-16 size-80 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -bottom-32 left-1/3 size-96 rounded-full bg-black/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative grid gap-7 px-5 py-7 sm:px-10 sm:py-12 {{ $conLateral ? 'lg:grid-cols-[1fr_1.15fr] lg:items-center lg:gap-10' : 'lg:py-16' }}">
                    <div class="{{ $conLateral ? '' : 'max-w-3xl' }}">
                        <p class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-[11px] font-bold tracking-[0.18em] uppercase ring-1 ring-white/20">@include('tienda.mercado.icono', ['n' => 'chispa', 'clase' => 'size-3.5']){{ $tienda['anuncio'] ? 'Campaña' : 'Tienda en línea' }}</p>
                        <h1 class="mt-4 text-[1.9rem] leading-[1.05] font-extrabold tracking-tight text-balance sm:text-5xl">{{ $tienda['portada']['titulo'] }}</h1>
                        <p class="mt-3 max-w-lg text-[15px] leading-relaxed opacity-90 sm:mt-4 sm:text-lg">{{ $tienda['descripcion'] ?: 'Mira nuestro catálogo, busca lo que necesitas y haz tu pedido en un momento.' }}</p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a href="/catalogo" class="inline-flex h-11 items-center gap-2 rounded-xl bg-white px-4 text-[13px] font-bold text-(--marca-texto) sm:h-12 sm:px-6 sm:text-sm shadow-lg shadow-black/10 transition hover:bg-slate-50">{{ $tienda['portada']['boton'] }} @include('tienda.icono', ['n' => 'flecha'])</a>
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl px-4 text-[13px] font-bold ring-1 ring-white/50 sm:h-12 sm:px-6 sm:text-sm transition-colors hover:bg-white/10">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4.5'])Pedir por WhatsApp</a>
                            @endif
                        </div>
                        <ul class="mt-8 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold opacity-90" data-beneficios>
                            <li class="inline-flex items-center gap-1.5">@include('tienda.mercado.icono', ['n' => 'camion', 'clase' => 'size-4'])Envíos a todo el Perú</li>
                            <li class="inline-flex items-center gap-1.5">@include('tienda.mercado.icono', ['n' => 'escudo', 'clase' => 'size-4'])Compra segura</li>
                            @if ($whatsapp)
                                <li class="inline-flex items-center gap-1.5">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4'])Asesoría por WhatsApp</li>
                            @endif
                        </ul>
                    </div>

                    @if ($ofertas->isNotEmpty())
                        <div class="-mx-5 flex snap-x snap-mandatory gap-3 overflow-x-auto px-5 pb-1 sm:-mx-10 sm:px-10 lg:mx-0 lg:grid lg:px-0 lg:pb-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden {{ $ofertas->count() === 1 ? 'lg:grid-cols-1 lg:justify-items-end' : ($ofertas->count() === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-3') }}" data-ofertas>
                            @foreach ($ofertas as $p)
                                <a href="{{ $p['url'] }}" class="group flex w-44 shrink-0 snap-start flex-col overflow-hidden rounded-2xl bg-white text-slate-900 shadow-xl shadow-black/15 transition hover:-translate-y-1 sm:w-52 lg:w-auto {{ $ofertas->count() === 1 ? 'lg:max-w-xs' : '' }}">
                                    <span class="relative block aspect-square overflow-hidden bg-white">
                                        <img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}" fetchpriority="high" width="400" height="400" class="size-full object-contain p-4 transition-transform duration-300 group-hover:scale-105">
                                        <span class="absolute top-2.5 left-2.5 rounded-md bg-(--mercado-fondo) px-2 py-1 text-[10px] font-bold tracking-wider text-(--sobre-fondo) uppercase">{{ $destacados['origen'] === 'nuevos' ? 'Novedad' : ($destacados['origen'] === 'vendidos' ? 'Más pedido' : 'Destacado') }}</span>
                                    </span>
                                    <span class="flex flex-1 flex-col px-3.5 pt-2 pb-3.5">
                                        <span class="line-clamp-2 text-sm leading-snug font-bold">{{ $p['nombre'] }}</span>
                                        <span class="mt-auto pt-2">
                                            @if ($p['precio'] !== null)
                                                <span class="inline-block rounded-lg bg-(--marca-suave) px-2.5 py-1 text-base font-extrabold text-(--marca-texto)">S/ {{ number_format($p['precio'], 2) }}</span>
                                            @else
                                                <span class="text-xs font-semibold text-slate-500">Consultar precio</span>
                                            @endif
                                        </span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @elseif ($fotoCampana)
                        <div class="relative -mx-5 -mb-7 min-h-56 sm:-mx-10 sm:-mb-12 lg:mx-0 lg:mb-0 lg:min-h-80 lg:overflow-hidden lg:rounded-2xl lg:shadow-xl lg:shadow-black/15">
                            <img src="{{ $fotoCampana }}" alt="{{ $tienda['nombre'] }}" fetchpriority="high" class="absolute inset-0 size-full object-cover">
                        </div>
                    @endif
                </div>
            </section>

            {{-- Banners del dueño --}}
            @if ($bannersRestantes)
                <section class="mt-5 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-1 [scrollbar-width:none] sm:grid [&::-webkit-scrollbar]:hidden {{ count($bannersRestantes) > 1 ? 'sm:grid-cols-2' : '' }}" aria-label="Promociones" data-banners>
                    @foreach ($bannersRestantes as $banner)
                        @php $etiqueta = $banner['enlace'] ? 'a' : 'div'; @endphp
                        <{{ $etiqueta }} @if ($banner['enlace']) href="{{ $banner['enlace'] }}" @if ($banner['externo']) target="_blank" rel="noopener" @endif @endif class="group relative block w-[85%] shrink-0 snap-start overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200 sm:w-auto">
                            <img src="{{ $banner['imagen'] }}" alt="{{ $banner['titulo'] ?? '' }}" width="1600" height="600" loading="lazy" class="aspect-[16/6] w-full object-cover transition-transform duration-500 {{ $banner['enlace'] ? 'group-hover:scale-[1.02]' : '' }}">
                            @if ($banner['titulo'])
                                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/80 to-transparent px-5 pt-10 pb-4 text-lg font-bold text-white">{{ $banner['titulo'] }}</span>
                            @endif
                        </{{ $etiqueta }}>
                    @endforeach
                </section>
            @endif

            {{-- Categorías en círculos, como en un mercado --}}
            @if ($categorias->count() >= 3)
                <section class="mt-10" aria-labelledby="titulo-categorias" data-categorias>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold tracking-[0.18em] text-(--marca-texto) uppercase">Explora</p>
                            <h2 id="titulo-categorias" class="text-2xl font-extrabold tracking-tight">Compra por categoría</h2>
                        </div>
                        <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Ver toda la tienda @include('tienda.icono', ['n' => 'flecha'])</a>
                    </div>
                    <div class="-mx-3 mt-5 flex snap-x gap-3 overflow-x-auto px-3 pb-2 sm:-mx-6 sm:px-6 lg:mx-0 lg:grid lg:grid-cols-6 lg:px-0 xl:grid-cols-8 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        @foreach ($categorias->take(8) as $cat)
                            <a href="{{ $cat->url }}" class="group w-24 shrink-0 snap-start text-center sm:w-28 lg:w-auto">
                                <span class="mx-auto grid size-20 place-items-center overflow-hidden rounded-full bg-(--marca-suave) text-2xl font-extrabold text-(--marca-texto) ring-4 ring-white shadow-sm transition duration-300 group-hover:scale-105 group-hover:ring-(--marca) sm:size-24">
                                    @if ($cat->imagen)
                                        <img src="{{ $cat->imagen }}" alt="" loading="lazy" width="160" height="160" class="size-full object-contain p-3 mix-blend-multiply">
                                    @else
                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($cat->nombre, 0, 1)) }}
                                    @endif
                                </span>
                                <span class="mt-2.5 line-clamp-2 text-[13px] leading-tight font-bold group-hover:text-(--marca-texto)">{{ $cat->nombre }}</span>
                                <span class="block text-[11px] text-slate-500">{{ $cat->productos }} producto{{ $cat->productos === 1 ? '' : 's' }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Destacados: los que eligió el dueño, los más pedidos o las novedades --}}
            @if ($destacados && $destacados['productos']->isNotEmpty())
                @php
                    [$tituloDestacados, $etiquetaDestacados] = match ($destacados['origen']) {
                        'nuevos' => ['Productos de lanzamiento', 'Novedades'],
                        'vendidos' => ['Lo más pedido', 'Favoritos de nuestros clientes'],
                        default => ['Productos destacados', 'Selección de la tienda'],
                    };
                @endphp
                <section class="mt-12" aria-labelledby="titulo-destacados" data-destacados>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold tracking-[0.18em] text-(--marca-texto) uppercase">{{ $etiquetaDestacados }}</p>
                            <h2 id="titulo-destacados" class="text-2xl font-extrabold tracking-tight">{{ $tituloDestacados }}</h2>
                        </div>
                        <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Ver toda la tienda @include('tienda.icono', ['n' => 'flecha'])</a>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach ($destacados['productos']->take(6) as $p)
                            @include('tienda.mercado.tarjeta', ['p' => $p, 'insignia' => $destacados['origen'] === 'nuevos' ? 'nuevo' : null])
                        @endforeach
                    </div>
                </section>
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
        <section id="catalogo" class="mt-8 grid gap-6 lg:mt-10 {{ ! $portada && $hayFiltros ? 'lg:grid-cols-[15rem_1fr]' : '' }}" aria-labelledby="titulo-catalogo">
            @if (! $portada && $hayFiltros)
                {{-- en el celular los filtros se abren con un toque; en pantalla grande van siempre a la vista --}}
                <div class="min-w-0">
                <details class="group rounded-2xl bg-white ring-1 ring-slate-200 lg:hidden" {{ $marca !== '' || $hasta !== null ? 'open' : '' }} data-filtros>
                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold select-none [&::-webkit-details-marker]:hidden">
                        <span class="flex items-center gap-2">@include('tienda.icono', ['n' => 'cuadricula', 'clase' => 'size-4 text-(--marca-texto)'])Filtrar productos</span>
                        @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-4 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                    </summary>
                    <div class="space-y-6 border-t border-slate-100 px-4 pt-4 pb-5">
                        @include('tienda.mercado.filtros', ['sufijo' => '-movil'])
                    </div>
                </details>
                <aside class="hidden self-start rounded-2xl bg-white p-5 ring-1 ring-slate-200 lg:sticky lg:top-36 lg:block lg:space-y-6" aria-label="Filtros" data-filtros-pc>
                    @include('tienda.mercado.filtros')
                </aside>
                </div>
            @endif

            <div class="min-w-0">
                <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                    <div class="min-w-0">
                        @php $etiquetaTitulo = $portada ? 'h2' : 'h1'; @endphp
                        @if ($portada)
                            <p class="text-[11px] font-bold tracking-[0.18em] text-(--marca-texto) uppercase">Catálogo</p>
                        @endif
                        <{{ $etiquetaTitulo }} id="titulo-catalogo" class="text-2xl font-extrabold tracking-tight">
                            @if ($buscar !== '')
                                Resultados para “{{ $buscar }}”
                            @elseif ($categoria)
                                {{ $categoria->nombre }}
                            @else
                                Todos los productos
                            @endif
                        </{{ $etiquetaTitulo }}>
                        @if ($categoria && ($categoria->texto ?? null) && $buscar === '' && $pagina === 1)
                            <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-slate-600">{{ $categoria->texto }}</p>
                        @endif
                        <p class="mt-1 text-sm text-slate-500">
                            {{ number_format($productos->total()) }} producto{{ $productos->total() === 1 ? '' : 's' }}@if ($marca !== '') de {{ $marca }}@endif @if ($hasta !== null) hasta S/ {{ number_format($hasta, 0, '.', ',') }}@endif
                            @if ($filtrando) · <a href="/catalogo" class="font-semibold text-(--marca-texto) hover:underline">Ver todo</a> @endif
                        </p>
                    </div>

                    @if ($portada && $productos->hasMorePages())
                        <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Ver toda la tienda @include('tienda.icono', ['n' => 'flecha'])</a>
                    @elseif (! $portada && $tienda['mostrar_precios'] && $productos->total() > 1)
                        <form action="{{ $base }}" method="get" class="flex items-center gap-2">
                            @foreach ($vigentes as $clave => $valor)
                                @if ($clave !== 'orden') <input type="hidden" name="{{ $clave }}" value="{{ $valor }}"> @endif
                            @endforeach
                            <label for="orden" class="text-sm text-slate-500">Ordenar:</label>
                            <select id="orden" name="orden" class="h-10 rounded-xl border border-slate-200 bg-white pr-8 pl-3 text-sm font-semibold focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                                <option value="nombre" @selected($orden === 'nombre')>Nombre</option>
                                <option value="menor" @selected($orden === 'menor')>Menor precio</option>
                                <option value="mayor" @selected($orden === 'mayor')>Mayor precio</option>
                            </select>
                            <noscript><button type="submit" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium">Aplicar</button></noscript>
                        </form>
                    @endif
                </div>

                @if ($productos->isEmpty())
                    <div class="mt-6 rounded-2xl bg-white px-6 py-16 text-center ring-1 ring-slate-200">
                        <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-slate-200">@include('tienda.icono', ['n' => $filtrando ? 'buscar' : 'paquete', 'clase' => 'size-6'])</span>
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
                                <a href="/catalogo" class="inline-flex h-11 items-center rounded-xl bg-white px-5 text-sm font-bold ring-1 ring-slate-200 hover:ring-slate-300">Ver toda la tienda</a>
                            @endif
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl bg-(--mercado-fondo) px-5 text-sm font-bold text-(--sobre-fondo) hover:brightness-125">@include('tienda.icono', ['n' => 'whatsapp'])Preguntar por WhatsApp</a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 {{ ! $portada && $hayFiltros ? 'xl:grid-cols-4' : 'lg:grid-cols-4 xl:grid-cols-6' }}">
                        @foreach ($productos as $p)
                            @include('tienda.mercado.tarjeta', ['p' => $p, 'prioritaria' => ! $portada && $loop->index < 4])
                        @endforeach
                    </div>

                    @if ($portada)
                        @if ($productos->hasMorePages())
                            <div class="mt-8 text-center">
                                <a href="/catalogo" class="inline-flex h-12 items-center gap-2 rounded-xl bg-(--mercado-fondo) px-6 text-sm font-bold text-(--sobre-fondo) shadow-sm transition hover:brightness-125">Ver los {{ number_format($productos->total()) }} productos @include('tienda.icono', ['n' => 'flecha'])</a>
                            </div>
                        @endif
                    @else
                        {{ $productos->onEachSide(1)->links('tienda.paginacion') }}
                    @endif
                @endif
            </div>
        </section>

        {{-- Mosaico de categorías con foto --}}
        @php $mosaico = $portada ? $categorias->filter(fn ($c) => $c->imagen)->take(3)->values() : collect(); @endphp
        @if ($mosaico->count() >= 2)
            <section class="mt-12 grid gap-4 sm:grid-cols-2 {{ $mosaico->count() === 3 ? 'lg:grid-cols-3' : '' }}" aria-label="Categorías destacadas" data-mosaico>
                @foreach ($mosaico as $cat)
                    <a href="{{ $cat->url }}" class="group relative flex min-h-56 flex-col justify-end overflow-hidden rounded-3xl bg-(--mercado-fondo) p-6 text-(--sobre-fondo) ring-1 ring-black/5 sm:min-h-64">
                        <img src="{{ $cat->imagen }}" alt="" loading="lazy" width="600" height="600" class="absolute -top-6 -right-6 size-44 rotate-6 object-contain opacity-90 drop-shadow-2xl transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3 sm:size-52">
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent" aria-hidden="true"></div>
                        <span class="relative inline-flex w-fit rounded-full bg-(--marca) px-2.5 py-1 text-[10px] font-bold tracking-wider text-(--sobre-marca) uppercase">{{ $cat->productos }} producto{{ $cat->productos === 1 ? '' : 's' }}</span>
                        <span class="relative mt-2 text-2xl font-extrabold tracking-tight">{{ $cat->nombre }}</span>
                        <span class="relative mt-1 line-clamp-2 text-sm opacity-80">{{ ($cat->texto ?? null) ? \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($cat->texto), 90) : 'Ver la categoría completa' }}</span>
                    </a>
                @endforeach
            </section>
        @endif

        {{-- Sobre nosotros --}}
        @if ($portada && filled($nosotros))
            <section class="mt-12 rounded-3xl bg-white p-6 ring-1 ring-slate-200 sm:p-8 lg:flex lg:items-center lg:gap-10" aria-labelledby="titulo-nosotros">
                <div class="lg:w-1/3">
                    <p class="text-[11px] font-bold tracking-[0.18em] text-(--marca-texto) uppercase">Conócenos</p>
                    <h2 id="titulo-nosotros" class="mt-1 text-2xl font-extrabold tracking-tight">Sobre {{ $tienda['nombre'] }}</h2>
                </div>
                <div class="mt-3 lg:mt-0 lg:flex-1">
                    <p class="leading-relaxed text-slate-600">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($nosotros), 320) }}</p>
                    <a href="/nosotros" class="mt-3 inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Conocer más @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>
            </section>
        @endif
    </div>
@endsection
