@extends('tienda.layout')

@php
    $pagina = $productos->currentPage();
    // enlace a una categoría (o a todo el catálogo) conservando la búsqueda y el orden ya elegidos
    $enlace = function (?object $cat) use ($buscar, $orden) {
        $parametros = http_build_query(array_filter(['q' => $buscar ?: null, 'orden' => $orden !== 'nombre' ? $orden : null]));

        return ($cat ? $cat->url : '/catalogo').($parametros !== '' ? "?{$parametros}" : '');
    };
    $subtitulos = [
        'elegidos' => 'Lo que más recomendamos de nuestra tienda.',
        'vendidos' => 'Lo que más se llevan nuestros clientes.',
        'nuevos' => 'Lo más reciente de nuestra tienda.',
    ];
    // fotos reales de la tienda para la portada: destacados con foto, primero los que hay para vender
    $vitrina = $destacados
        ? $destacados['productos']->filter(fn ($p) => $p['imagen'])->sortBy(fn ($p) => $p['disponible'] === false ? 1 : 0)->take(3)->values()
        : collect();
    $categoriasConFoto = $categorias->filter(fn ($c) => $c->imagen);

    // cada página del catálogo es su propia dirección (la 2 no es una copia de la portada)
    $canonica = rtrim($tienda['url'], '/').($categoria ? $categoria->url : ($portada ? '/' : '/catalogo')).($pagina > 1 ? "?page={$pagina}" : '');

    // datos estructurados del negocio: el buscador puede mostrar teléfono, dirección y horario
    $negocio = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        'name' => $tienda['nombre'],
        'url' => $tienda['url'],
        'description' => $tienda['descripcion'],
        'image' => $tienda['logo'] ? (str_starts_with($tienda['logo'], 'http') ? $tienda['logo'] : rtrim($tienda['url'], '/').$tienda['logo']) : null,
        'telephone' => $contactos['telefono'] ?: ($contactos['whatsapp'] ? '+'.$contactos['whatsapp'] : null),
        'email' => $contactos['email'],
        'address' => $contactos['direccion'] ? ['@type' => 'PostalAddress', 'streetAddress' => $contactos['direccion'], 'addressCountry' => 'PE'] : null,
        'openingHours' => $contactos['horario'],
    ]);
@endphp

@section('titulo', $buscar !== '' ? "“{$buscar}” en {$tienda['nombre']}" : ($categoria ? "{$categoria->nombre} · {$tienda['nombre']}" : ($portada ? '' : "Catálogo de {$tienda['nombre']}".($pagina > 1 ? " · página {$pagina}" : ''))))
@section('canonica', $canonica)
{{-- los resultados de búsqueda no se indexan: son infinitas combinaciones de la misma tienda --}}
@section('robots', $buscar !== '' ? 'noindex,follow' : 'index,follow')

@if ($portada)
    @push('cabecera')
        <script type="application/ld+json">{!! json_encode($negocio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endpush
@endif

@section('contenido')
    @if ($portada)
        {{-- Portada: el dueño elige entre vitrina de productos, foto grande o solo texto --}}
        @if ($tienda['portada']['estilo'] === 'foto')
            <section class="relative isolate overflow-hidden bg-slate-900" data-portada="foto">
                <img src="{{ $tienda['portada']['imagen'] }}" alt="" fetchpriority="high" class="absolute inset-0 -z-10 size-full object-cover">
                {{-- velo oscuro: el texto se lee sobre cualquier foto, clara u oscura --}}
                <div class="absolute inset-0 -z-10 bg-gradient-to-r from-slate-950/90 via-slate-950/65 to-slate-950/25" aria-hidden="true"></div>
                <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8 lg:py-28">
                    @include('tienda.portada-texto', ['sobreFoto' => true])
                </div>
            </section>
        @elseif ($tienda['portada']['estilo'] === 'texto')
            <section class="relative overflow-hidden border-b border-slate-100 bg-(--marca-suave)" data-portada="texto">
                <div class="pointer-events-none absolute -top-40 left-1/2 size-[40rem] -translate-x-1/2 rounded-full bg-(--marca)/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
                    @include('tienda.portada-texto', ['centrado' => true])
                </div>
            </section>
        @else
            <section class="relative overflow-hidden border-b border-slate-100 bg-(--marca-suave)" data-portada="vitrina">
                <div class="pointer-events-none absolute -top-40 -right-24 size-[34rem] rounded-full bg-(--marca)/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative mx-auto grid max-w-7xl items-center gap-10 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-2 lg:px-8 lg:py-20">
                    <div class="{{ $vitrina->isEmpty() ? 'lg:col-span-2 lg:max-w-3xl' : '' }}">
                        @include('tienda.portada-texto')
                    </div>

                    {{-- vitrina con productos reales de la tienda --}}
                    @if ($vitrina->isNotEmpty())
                        <div class="grid grid-cols-2 gap-4 {{ $vitrina->count() === 1 ? 'mx-auto max-w-sm grid-cols-1' : '' }}">
                            @foreach ($vitrina as $i => $p)
                                <a href="{{ $p['url'] }}" class="group relative overflow-hidden rounded-3xl bg-white shadow-xl ring-1 shadow-slate-900/5 ring-slate-200/70 transition duration-300 hover:-translate-y-1 hover:shadow-2xl {{ $i === 0 && $vitrina->count() === 3 ? 'row-span-2' : '' }}">
                                    <div class="{{ $i === 0 && $vitrina->count() === 3 ? 'h-full min-h-72' : 'aspect-[4/3]' }}">
                                        <img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}" width="800" height="600" @if ($i > 0) loading="lazy" @else fetchpriority="high" @endif
                                            class="size-full object-contain p-5 transition-transform duration-500 group-hover:scale-105">
                                    </div>
                                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-white via-white/95 to-transparent px-4 pt-8 pb-3.5">
                                        <p class="line-clamp-1 text-sm font-medium">{{ $p['nombre'] }}</p>
                                        @if ($p['precio'] !== null)
                                            <p class="text-sm font-semibold text-(--marca-texto)">S/ {{ number_format($p['precio'], 2) }}</p>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- Banners del dueño: promociones, campañas, novedades. Se deslizan con el dedo o el ratón, sin JavaScript --}}
        @if ($banners)
            @php $varios = count($banners) > 1; @endphp
            <section class="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8" aria-label="Promociones" data-banners>
                <div class="{{ $varios ? '-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:thin] sm:mx-0 sm:px-0' : '' }}">
                    @foreach ($banners as $i => $banner)
                        @php $etiqueta = $banner['enlace'] ? 'a' : 'div'; @endphp
                        <{{ $etiqueta }} @if ($banner['enlace']) href="{{ $banner['enlace'] }}" @if ($banner['externo']) target="_blank" rel="noopener" @endif @endif
                            class="group relative block overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200 {{ $varios ? 'w-[88%] shrink-0 snap-center sm:w-[70%] sm:snap-start '.(count($banners) === 2 ? 'lg:w-[calc(50%-0.5rem)]' : 'lg:w-[46%]') : '' }}">
                            <img src="{{ $banner['imagen'] }}" alt="{{ $banner['titulo'] ?? '' }}" width="1600" height="600" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif
                                class="w-full object-cover transition-transform duration-500 {{ $banner['enlace'] ? 'group-hover:scale-[1.02]' : '' }} {{ $varios ? 'aspect-[16/9] sm:aspect-[8/3]' : 'aspect-[16/9] sm:aspect-[16/5]' }}">
                            @if ($banner['titulo'])
                                <span class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 bg-gradient-to-t from-slate-950/80 via-slate-950/35 to-transparent px-5 pt-12 pb-4 text-white">
                                    <span class="text-lg leading-snug font-semibold tracking-tight text-balance sm:text-xl">{{ $banner['titulo'] }}</span>
                                    @if ($banner['enlace'])
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white text-slate-900">@include('tienda.icono', ['n' => 'flecha'])</span>
                                    @endif
                                </span>
                            @endif
                        </{{ $etiqueta }}>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Por qué comprar aquí: solo lo que la tienda realmente ofrece --}}
        @php
            $ventajas = array_values(array_filter([
                $whatsapp ? ['whatsapp', 'Pedidos por WhatsApp', 'Te atendemos y confirmamos tu pedido al momento.'] : null,
                ['actualizado', 'Catálogo al día', 'Lo que ves aquí es lo que tenemos en tienda.'],
                $contactos['direccion'] ? ['lugar', 'Recojo en tienda', implode(' · ', array_column($contactos['direcciones'], 'texto'))] : null,
                $contactos['telefono'] ? ['telefono', 'Atención por teléfono', $contactos['telefono']] : null,
            ]));
        @endphp
        <section class="border-b border-slate-100 {{ $banners ? 'mt-2' : '' }}" aria-label="Cómo te atendemos">
            <ul class="mx-auto grid max-w-7xl gap-x-8 gap-y-5 px-4 py-7 sm:grid-cols-2 sm:px-6 {{ [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4'][count($ventajas)] }} lg:px-8">
                @foreach ($ventajas as [$icono, $tituloVentaja, $detalle])
                    <li class="flex items-center gap-3.5">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca-suave) text-(--marca-texto)">@include('tienda.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ $tituloVentaja }}</span>
                            <span class="line-clamp-2 text-sm text-slate-500">{{ $detalle }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Categorías con foto --}}
        @if ($categoriasConFoto->count() >= 3)
            <section class="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8" aria-labelledby="titulo-categorias">
                <h2 id="titulo-categorias" class="text-2xl font-semibold tracking-tight">Compra por categoría</h2>
                <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($categoriasConFoto->take(6) as $cat)
                        <a href="{{ $cat->url }}" class="group rounded-2xl bg-slate-50 p-4 text-center ring-1 ring-slate-100 transition hover:bg-white hover:shadow-lg hover:shadow-slate-900/5 hover:ring-slate-200">
                            <div class="mx-auto aspect-square w-full max-w-28">
                                <img src="{{ $cat->imagen }}" alt="" loading="lazy" width="800" height="600" class="size-full object-contain mix-blend-multiply transition-transform duration-300 group-hover:scale-110">
                            </div>
                            <p class="mt-3 truncate text-sm font-semibold group-hover:text-(--marca-texto)">{{ $cat->nombre }}</p>
                            <p class="text-xs text-slate-500">{{ $cat->productos }} producto{{ $cat->productos === 1 ? '' : 's' }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    {{-- Productos destacados --}}
    @if ($destacados && $destacados['productos']->isNotEmpty())
        <section class="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8" aria-labelledby="titulo-destacados">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="titulo-destacados" class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                        Productos destacados
                    </h2>
                    <p class="mt-1 text-slate-500">{{ $subtitulos[$destacados['origen']] }}</p>
                </div>
                <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-semibold text-(--marca-texto) hover:underline">Ver todo el catálogo @include('tienda.icono', ['n' => 'flecha'])</a>
            </div>
            <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 lg:gap-5">
                @foreach ($destacados['productos'] as $p)
                    @include('tienda.tarjeta', ['p' => $p])
                @endforeach
            </div>
        </section>
    @endif

    {{-- Catálogo --}}
    <section id="catalogo" class="mx-auto max-w-7xl scroll-mt-4 px-4 pt-14 sm:px-6 lg:scroll-mt-36 lg:px-8" aria-labelledby="titulo-catalogo">
        @unless ($portada)
            <nav class="mb-3 flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
                <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                @if ($filtrando)
                    <a href="/catalogo" class="hover:text-(--marca-texto)">Catálogo</a>
                    @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                    <span class="text-slate-800">{{ $categoria ? $categoria->nombre : 'Búsqueda' }}</span>
                @else
                    <span class="text-slate-800">Catálogo</span>
                @endif
            </nav>
        @endunless

        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <h2 id="titulo-catalogo" class="text-2xl font-semibold tracking-tight">
                    @if ($buscar !== '')
                        Resultados para “{{ $buscar }}”
                    @elseif ($categoria)
                        {{ $categoria->nombre }}
                    @elseif ($portada)
                        Nuestro catálogo
                    @else
                        Todos los productos
                    @endif
                </h2>
                <p class="mt-1 text-slate-500">
                    {{ number_format($productos->total()) }} producto{{ $productos->total() === 1 ? '' : 's' }}@if ($buscar !== '' && $categoria) en {{ $categoria->nombre }}@endif
                    @if ($filtrando)
                        · <a href="/catalogo" class="font-medium text-(--marca-texto) hover:underline">Ver todo</a>
                    @endif
                </p>
            </div>

            @if ($portada && $productos->hasMorePages())
                <a href="/catalogo" class="inline-flex items-center gap-1.5 text-sm font-semibold text-(--marca-texto) hover:underline">Ver todos @include('tienda.icono', ['n' => 'flecha'])</a>
            @elseif (! $portada && $tienda['mostrar_precios'] && $productos->total() > 1)
                <form action="{{ $categoria ? $categoria->url : '/catalogo' }}" method="get" class="flex items-center gap-2">
                    @if ($buscar !== '') <input type="hidden" name="q" value="{{ $buscar }}"> @endif
                    <label for="orden" class="text-sm text-slate-500">Ordenar por</label>
                    <select id="orden" name="orden" onchange="this.form.submit()" class="h-10 rounded-xl border border-slate-200 bg-white pr-8 pl-3 text-sm font-medium focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        <option value="nombre" @selected($orden === 'nombre')>Nombre</option>
                        <option value="menor" @selected($orden === 'menor')>Menor precio</option>
                        <option value="mayor" @selected($orden === 'mayor')>Mayor precio</option>
                    </select>
                    <noscript><button type="submit" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium">Aplicar</button></noscript>
                </form>
            @endif
        </div>

        @php $conPanel = ! $portada && $categorias->isNotEmpty(); @endphp
        <div class="mt-6 grid gap-8 {{ $conPanel ? 'lg:grid-cols-[15rem_1fr]' : '' }}">
            {{-- categorías: panel lateral en pantallas grandes (en el celular están en la barra de arriba) --}}
            @if ($conPanel)
                <aside class="hidden lg:block" aria-label="Filtrar por categoría">
                    <div class="sticky top-40 rounded-2xl ring-1 ring-slate-200">
                        <p class="border-b border-slate-100 px-4 py-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">Categorías</p>
                        <ul class="max-h-[60vh] overflow-y-auto p-2 text-sm">
                            <li>
                                <a href="{{ $enlace(null) }}" @if (! $categoria) aria-current="true" @endif
                                    class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 transition-colors {{ $categoria ? 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' : 'bg-(--marca-suave) font-semibold text-(--marca-texto)' }}">
                                    Todas <span class="text-xs {{ $categoria ? 'text-slate-400' : '' }}">{{ number_format($tienda['productos']) }}</span>
                                </a>
                            </li>
                            @foreach ($categorias as $cat)
                                <li>
                                    <a href="{{ $enlace($cat) }}" @if ($categoria?->id === $cat->id) aria-current="true" @endif
                                        class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 transition-colors {{ $categoria?->id === $cat->id ? 'bg-(--marca-suave) font-semibold text-(--marca-texto)' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                                        <span class="truncate">{{ $cat->nombre }}</span>
                                        <span class="text-xs {{ $categoria?->id === $cat->id ? '' : 'text-slate-400' }}">{{ $cat->productos }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </aside>
            @endif

            <div class="min-w-0">
                @if ($productos->isEmpty())
                    <div class="rounded-3xl bg-slate-50 px-6 py-16 text-center ring-1 ring-slate-100">
                        <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-white text-slate-400 ring-1 ring-slate-200">@include('tienda.icono', ['n' => $filtrando ? 'buscar' : 'paquete', 'clase' => 'size-6'])</span>
                        <p class="mt-5 text-lg font-semibold tracking-tight">
                            {{ $filtrando ? 'No encontramos productos con esa búsqueda' : 'Pronto verás aquí nuestros productos' }}
                        </p>
                        <p class="mx-auto mt-1.5 max-w-sm text-slate-500">
                            @if ($filtrando)
                                Prueba con otra palabra o mira todo el catálogo.@if ($whatsapp) Si buscas algo en especial, escríbenos. @endif
                            @else
                                Estamos preparando el catálogo.@if ($whatsapp) Mientras tanto, escríbenos y te atendemos. @endif
                            @endif
                        </p>
                        <div class="mt-6 flex flex-wrap justify-center gap-2">
                            @if ($filtrando)
                                <a href="/catalogo" class="inline-flex h-11 items-center rounded-xl bg-white px-5 text-sm font-semibold ring-1 ring-slate-200 hover:ring-slate-300">Ver todo el catálogo</a>
                            @endif
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl bg-(--marca) px-5 text-sm font-semibold text-(--sobre-marca) hover:bg-(--marca-oscuro)">
                                    @include('tienda.icono', ['n' => 'whatsapp'])Preguntar por WhatsApp
                                </a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:gap-5 {{ $conPanel ? 'xl:grid-cols-4' : 'lg:grid-cols-4' }}">
                        @foreach ($productos as $p)
                            @include('tienda.tarjeta', ['p' => $p, 'conInsignia' => true])
                        @endforeach
                    </div>

                    @if ($portada)
                        {{-- la portada solo muestra una parte: el resto está en /catalogo --}}
                        @if ($productos->hasMorePages())
                            <div class="mt-10 text-center">
                                <a href="/catalogo" class="inline-flex h-12 items-center gap-2 rounded-xl bg-(--marca) px-6 text-sm font-semibold text-(--sobre-marca) shadow-sm transition-colors hover:bg-(--marca-oscuro)">
                                    Ver los {{ number_format($productos->total()) }} productos @include('tienda.icono', ['n' => 'flecha'])
                                </a>
                            </div>
                        @endif
                    @else
                        {{ $productos->onEachSide(1)->links('tienda.paginacion') }}
                    @endif
                @endif
            </div>
        </div>
    </section>

    {{-- Sobre nosotros: un adelanto de lo que el dueño escribió, con enlace a la página completa --}}
    @if ($portada && filled($nosotros))
        <section class="mx-auto max-w-7xl px-4 pt-16 sm:px-6 lg:px-8" aria-labelledby="titulo-nosotros">
            <div class="rounded-3xl bg-(--marca-suave) px-6 py-10 sm:px-10 lg:flex lg:items-center lg:gap-12">
                <div class="lg:w-1/3">
                    <p class="text-sm font-semibold text-(--marca-texto)">Conócenos</p>
                    <h2 id="titulo-nosotros" class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Sobre {{ $tienda['nombre'] }}</h2>
                </div>
                <div class="mt-4 lg:mt-0 lg:flex-1">
                    <p class="text-[17px] leading-relaxed text-slate-600">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($nosotros), 320) }}</p>
                    <a href="/nosotros" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-(--marca-texto) hover:underline">Conocer más @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>
            </div>
        </section>
    @endif
@endsection
