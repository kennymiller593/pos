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
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        @if ($portada)
            {{-- Campaña: título, presentación y botones, con la foto de portada al lado --}}
            <section class="mt-5 overflow-hidden rounded-2xl bg-(--mercado-fondo) text-(--sobre-fondo) sm:mt-6" data-portada="mercado">
                <div class="relative grid {{ $fotoCampana ? 'lg:grid-cols-[1.1fr_1fr]' : '' }}">
                    {{-- brillo suave del color de la tienda, para que la campaña no sea un bloque plano --}}
                    <div class="pointer-events-none absolute -top-32 -left-24 size-96 rounded-full bg-(--marca) opacity-25 blur-3xl" aria-hidden="true"></div>
                    <div class="relative px-6 py-10 sm:px-10 sm:py-14 lg:py-16">
                        <p class="text-xs font-bold tracking-[0.2em] uppercase opacity-75">{{ $tienda['anuncio'] ? 'Campaña' : 'Tienda en línea' }}</p>
                        <h1 class="mt-3 text-3xl leading-[1.05] font-extrabold tracking-tight text-balance sm:text-5xl">{{ $tienda['portada']['titulo'] }}</h1>
                        <p class="mt-4 max-w-lg text-base leading-relaxed opacity-85 sm:text-lg">{{ $tienda['descripcion'] ?: 'Mira nuestro catálogo, busca lo que necesitas y haz tu pedido en un momento.' }}</p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a href="/catalogo" class="inline-flex h-12 items-center gap-2 rounded-lg bg-(--marca) px-6 text-sm font-bold text-(--sobre-marca) shadow-sm ring-1 ring-white/20 transition hover:brightness-110">{{ $tienda['portada']['boton'] }} @include('tienda.icono', ['n' => 'flecha'])</a>
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center gap-2 rounded-lg px-6 text-sm font-bold ring-1 ring-white/40 transition-colors hover:bg-white/10">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4.5'])Pedir por WhatsApp</a>
                            @endif
                        </div>
                        {{-- lo que ofrece la tienda, de un vistazo --}}
                        <ul class="mt-8 flex flex-wrap gap-2 text-xs font-semibold" data-beneficios>
                            <li class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 ring-1 ring-white/15">@include('tienda.mercado.icono', ['n' => 'camion', 'clase' => 'size-4'])Envíos a todo el Perú</li>
                            <li class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 ring-1 ring-white/15">@include('tienda.mercado.icono', ['n' => 'escudo', 'clase' => 'size-4'])Pago seguro</li>
                            @if ($whatsapp)
                                <li class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 ring-1 ring-white/15">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4'])Asesoría por WhatsApp</li>
                            @endif
                        </ul>
                    </div>
                    @if ($fotoCampana)
                        <div class="relative min-h-56 bg-black/20 lg:min-h-full">
                            <img src="{{ $fotoCampana }}" alt="{{ $tienda['nombre'] }}" fetchpriority="high" class="absolute inset-0 size-full object-cover">
                        </div>
                    @endif
                </div>
            </section>

            {{-- Banners del dueño --}}
            @if ($bannersRestantes)
                <section class="mt-6 grid gap-4 {{ count($bannersRestantes) > 1 ? 'sm:grid-cols-2' : '' }}" aria-label="Promociones" data-banners>
                    @foreach ($bannersRestantes as $banner)
                        @php $etiqueta = $banner['enlace'] ? 'a' : 'div'; @endphp
                        <{{ $etiqueta }} @if ($banner['enlace']) href="{{ $banner['enlace'] }}" @if ($banner['externo']) target="_blank" rel="noopener" @endif @endif class="group relative block overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
                            <img src="{{ $banner['imagen'] }}" alt="{{ $banner['titulo'] ?? '' }}" width="1600" height="600" loading="lazy" class="aspect-[16/6] w-full object-cover transition-transform duration-500 {{ $banner['enlace'] ? 'group-hover:scale-[1.02]' : '' }}">
                            @if ($banner['titulo'])
                                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/80 to-transparent px-5 pt-10 pb-4 text-lg font-bold text-white">{{ $banner['titulo'] }}</span>
                            @endif
                        </{{ $etiqueta }}>
                    @endforeach
                </section>
            @endif
            {{-- Compra por categoría --}}
            @if ($categorias->count() >= 3)
                <section class="mt-10" aria-labelledby="titulo-categorias" data-categorias>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <h2 id="titulo-categorias" class="text-2xl font-extrabold tracking-tight">Compra por categoría</h2>
                        <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Ver todo @include('tienda.icono', ['n' => 'flecha'])</a>
                    </div>
                    <div class="mt-5 grid grid-cols-3 gap-3 sm:grid-cols-4 sm:gap-4 lg:grid-cols-6">
                        @foreach ($categorias->take(6) as $cat)
                            <a href="{{ $cat->url }}" class="group rounded-xl bg-white p-3 text-center ring-1 ring-slate-200 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/10 hover:ring-(--marca)">
                                <span class="mx-auto grid size-16 place-items-center overflow-hidden rounded-full bg-(--marca-suave) text-xl font-extrabold text-(--marca-texto) sm:size-20">
                                    @if ($cat->imagen)
                                        <img src="{{ $cat->imagen }}" alt="" loading="lazy" width="160" height="160" class="size-full object-contain p-2 mix-blend-multiply transition-transform duration-300 group-hover:scale-110">
                                    @else
                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($cat->nombre, 0, 1)) }}
                                    @endif
                                </span>
                                <span class="mt-2.5 block truncate text-sm font-bold group-hover:text-(--marca-texto)">{{ $cat->nombre }}</span>
                                <span class="block text-xs text-slate-500">{{ $cat->productos }} producto{{ $cat->productos === 1 ? '' : 's' }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @else
            <nav class="mt-5 flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
                <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                @if ($categoria || $buscar !== '')
                    <a href="/catalogo" class="hover:text-(--marca-texto)">Catálogo</a>
                    @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                    <span class="text-slate-800">{{ $categoria ? $categoria->nombre : 'Búsqueda' }}</span>
                @else
                    <span class="text-slate-800">Catálogo</span>
                @endif
            </nav>
        @endif

        {{-- Catálogo: filtros al costado y cuadrícula --}}
        <section id="catalogo" class="mt-8 grid gap-6 lg:mt-10 {{ ! $portada && $hayFiltros ? 'lg:grid-cols-[15rem_1fr]' : '' }}" aria-labelledby="titulo-catalogo">
            @if (! $portada && $hayFiltros)
                {{-- en el celular los filtros se abren con un toque; en pantalla grande van siempre a la vista --}}
                <div class="min-w-0">
                <details class="group rounded-xl bg-white ring-1 ring-slate-200 lg:hidden" {{ $marca !== '' || $hasta !== null ? 'open' : '' }} data-filtros>
                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold select-none [&::-webkit-details-marker]:hidden">
                        <span class="flex items-center gap-2">@include('tienda.icono', ['n' => 'cuadricula', 'clase' => 'size-4 text-(--marca-texto)'])Filtros</span>
                        @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-4 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                    </summary>
                    <div class="space-y-6 border-t border-slate-100 px-4 pt-4 pb-5">
                        @include('tienda.mercado.filtros', ['sufijo' => '-movil'])
                    </div>
                </details>
                <aside class="hidden self-start lg:sticky lg:top-32 lg:block lg:space-y-6" aria-label="Filtros" data-filtros-pc>
                    @include('tienda.mercado.filtros')
                </aside>
                </div>
            @endif

            <div class="min-w-0">
                <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                    <div class="min-w-0">
                        @php $etiquetaTitulo = $portada ? 'h2' : 'h1'; @endphp
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
                        <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Ver todos @include('tienda.icono', ['n' => 'flecha'])</a>
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
                                <a href="/catalogo" class="inline-flex h-11 items-center rounded-lg bg-white px-5 text-sm font-bold ring-1 ring-slate-200 hover:ring-slate-300">Ver todo el catálogo</a>
                            @endif
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-lg bg-(--mercado-fondo) px-5 text-sm font-bold text-(--sobre-fondo) hover:brightness-125">@include('tienda.icono', ['n' => 'whatsapp'])Preguntar por WhatsApp</a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 {{ ! $portada && $hayFiltros ? 'xl:grid-cols-4' : 'lg:grid-cols-4' }}">
                        @foreach ($productos as $p)
                            @include('tienda.mercado.tarjeta', ['p' => $p, 'prioritaria' => $loop->index < 4])
                        @endforeach
                    </div>

                    @if ($portada)
                        @if ($productos->hasMorePages())
                            <div class="mt-8 text-center">
                                <a href="/catalogo" class="inline-flex h-12 items-center gap-2 rounded-lg bg-(--mercado-fondo) px-6 text-sm font-bold text-(--sobre-fondo) shadow-sm transition hover:brightness-125">Ver los {{ number_format($productos->total()) }} productos @include('tienda.icono', ['n' => 'flecha'])</a>
                            </div>
                        @endif
                    @else
                        {{ $productos->onEachSide(1)->links('tienda.paginacion') }}
                    @endif
                @endif
            </div>
        </section>

        {{-- Destacados en la portada, después del catálogo --}}
        @if ($portada && $destacados && $destacados['productos']->isNotEmpty())
            <section class="mt-12" aria-labelledby="titulo-destacados">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="titulo-destacados" class="text-2xl font-extrabold tracking-tight">Lo más pedido</h2>
                    <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-bold text-(--marca-texto) hover:underline">Ver todo el catálogo @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
                    @foreach ($destacados['productos']->take(4) as $p)
                        @include('tienda.mercado.tarjeta', ['p' => $p])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Sobre nosotros --}}
        @if ($portada && filled($nosotros))
            <section class="mt-12 rounded-2xl bg-white p-6 ring-1 ring-slate-200 sm:p-8 lg:flex lg:items-center lg:gap-10" aria-labelledby="titulo-nosotros">
                <div class="lg:w-1/3">
                    <p class="text-xs font-bold tracking-[0.2em] text-(--marca-texto) uppercase">Conócenos</p>
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
