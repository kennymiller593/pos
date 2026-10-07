@extends('tienda.layout')

@php
    $pagina = $productos->currentPage();
    $portada = ! $filtrando && $pagina === 1;
    // enlace a una categoría (o a todo el catálogo) conservando la búsqueda y el orden ya elegidos
    $enlace = function (?object $cat) use ($buscar, $orden) {
        $parametros = http_build_query(array_filter(['q' => $buscar ?: null, 'orden' => $orden !== 'nombre' ? $orden : null]));

        return ($cat ? $cat->url : '/').($parametros !== '' ? "?{$parametros}" : '').($cat ? '' : '#catalogo');
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
    $canonica = rtrim($tienda['url'], '/').($categoria ? $categoria->url : '/').($pagina > 1 ? "?page={$pagina}" : '');

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

@section('titulo', $buscar !== '' ? "“{$buscar}” en {$tienda['nombre']}" : ($categoria ? "{$categoria->nombre} · {$tienda['nombre']}" : ($pagina > 1 ? "Catálogo de {$tienda['nombre']} · página {$pagina}" : '')))
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
        {{-- Portada --}}
        <section class="relative overflow-hidden border-b border-slate-100 bg-(--marca-suave)">
            <div class="pointer-events-none absolute -top-40 -right-24 size-[34rem] rounded-full bg-(--marca)/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative mx-auto grid max-w-7xl items-center gap-10 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-2 lg:px-8 lg:py-20">
                <div class="{{ $vitrina->isEmpty() ? 'lg:col-span-2 lg:max-w-3xl' : '' }}">
                    <p class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-(--marca) ring-1 ring-(--marca)/20">
                        <span class="size-1.5 rounded-full bg-(--marca)"></span>Catálogo en línea
                    </p>
                    <h1 class="mt-4 text-4xl leading-[1.08] font-semibold tracking-tight text-balance sm:text-5xl">{{ $tienda['nombre'] }}</h1>
                    <p class="mt-4 max-w-xl text-lg leading-relaxed text-slate-600">
                        {{ $tienda['descripcion'] ?: 'Mira nuestro catálogo, busca lo que necesitas y haz tu pedido en un momento.' }}
                    </p>
                    <div class="mt-7 flex flex-wrap gap-3">
                        <a href="#catalogo" class="inline-flex h-12 items-center gap-2 rounded-xl bg-(--marca) px-6 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-(--marca-oscuro)">
                            Ver catálogo @include('tienda.icono', ['n' => 'flecha'])
                        </a>
                        @if ($whatsapp)
                            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center gap-2 rounded-xl bg-white px-6 text-sm font-semibold text-slate-900 ring-1 ring-slate-200 transition-colors hover:ring-slate-300">
                                @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4.5 text-(--marca)'])Pedir por WhatsApp
                            </a>
                        @endif
                    </div>

                    <dl class="mt-9 flex flex-wrap gap-x-10 gap-y-4">
                        <div>
                            <dt class="text-sm text-slate-500">Productos</dt>
                            <dd class="text-2xl font-semibold tracking-tight">{{ number_format($tienda['productos']) }}</dd>
                        </div>
                        @if ($categorias->count() > 1)
                            <div>
                                <dt class="text-sm text-slate-500">Categorías</dt>
                                <dd class="text-2xl font-semibold tracking-tight">{{ $categorias->count() }}</dd>
                            </div>
                        @endif
                        @if ($contactos['horario'])
                            <div class="min-w-0">
                                <dt class="text-sm text-slate-500">Atención</dt>
                                <dd class="text-base leading-8 font-semibold tracking-tight">{{ $contactos['horario'] }}</dd>
                            </div>
                        @endif
                    </dl>
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
                                        <p class="text-sm font-semibold text-(--marca)">S/ {{ number_format($p['precio'], 2) }}</p>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- Por qué comprar aquí: solo lo que la tienda realmente ofrece --}}
        @php
            $ventajas = array_values(array_filter([
                $whatsapp ? ['whatsapp', 'Pedidos por WhatsApp', 'Te atendemos y confirmamos tu pedido al momento.'] : null,
                ['actualizado', 'Catálogo al día', 'Lo que ves aquí es lo que tenemos en tienda.'],
                $contactos['direccion'] ? ['lugar', 'Recojo en tienda', $contactos['direccion']] : null,
                $contactos['telefono'] ? ['telefono', 'Atención por teléfono', $contactos['telefono']] : null,
            ]));
        @endphp
        <section class="border-b border-slate-100" aria-label="Cómo te atendemos">
            <ul class="mx-auto grid max-w-7xl gap-x-8 gap-y-5 px-4 py-7 sm:grid-cols-2 sm:px-6 {{ [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4'][count($ventajas)] }} lg:px-8">
                @foreach ($ventajas as [$icono, $tituloVentaja, $detalle])
                    <li class="flex items-center gap-3.5">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca-suave) text-(--marca)">@include('tienda.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ $tituloVentaja }}</span>
                            <span class="block truncate text-sm text-slate-500">{{ $detalle }}</span>
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
                            <p class="mt-3 truncate text-sm font-semibold group-hover:text-(--marca)">{{ $cat->nombre }}</p>
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
                <a href="#catalogo" class="inline-flex items-center gap-1.5 text-sm font-semibold text-(--marca) hover:underline">Ver todo el catálogo @include('tienda.icono', ['n' => 'flecha'])</a>
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
        @if ($filtrando)
            <nav class="mb-3 flex items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
                <a href="/" class="hover:text-(--marca)">Inicio</a>
                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                <span class="text-slate-800">{{ $buscar !== '' ? 'Búsqueda' : $categoria->nombre }}</span>
            </nav>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <h2 id="titulo-catalogo" class="text-2xl font-semibold tracking-tight">
                    @if ($buscar !== '')
                        Resultados para “{{ $buscar }}”
                    @elseif ($categoria)
                        {{ $categoria->nombre }}
                    @else
                        Todos los productos
                    @endif
                </h2>
                <p class="mt-1 text-slate-500">
                    {{ number_format($productos->total()) }} producto{{ $productos->total() === 1 ? '' : 's' }}@if ($buscar !== '' && $categoria) en {{ $categoria->nombre }}@endif
                    @if ($filtrando)
                        · <a href="/#catalogo" class="font-medium text-(--marca) hover:underline">Ver todo</a>
                    @endif
                </p>
            </div>

            @if ($tienda['mostrar_precios'] && $productos->total() > 1)
                <form action="{{ $categoria ? $categoria->url : '/#catalogo' }}" method="get" class="flex items-center gap-2">
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

        <div class="mt-6 grid gap-8 lg:grid-cols-[15rem_1fr]">
            {{-- categorías: panel lateral en pantallas grandes (en el celular están en la barra de arriba) --}}
            @if ($categorias->isNotEmpty())
                <aside class="hidden lg:block" aria-label="Filtrar por categoría">
                    <div class="sticky top-40 rounded-2xl ring-1 ring-slate-200">
                        <p class="border-b border-slate-100 px-4 py-3 text-xs font-semibold tracking-wider text-slate-400 uppercase">Categorías</p>
                        <ul class="max-h-[60vh] overflow-y-auto p-2 text-sm">
                            <li>
                                <a href="{{ $enlace(null) }}" @if (! $categoria) aria-current="true" @endif
                                    class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 transition-colors {{ $categoria ? 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' : 'bg-(--marca-suave) font-semibold text-(--marca)' }}">
                                    Todas <span class="text-xs {{ $categoria ? 'text-slate-400' : '' }}">{{ number_format($tienda['productos']) }}</span>
                                </a>
                            </li>
                            @foreach ($categorias as $cat)
                                <li>
                                    <a href="{{ $enlace($cat) }}" @if ($categoria?->id === $cat->id) aria-current="true" @endif
                                        class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 transition-colors {{ $categoria?->id === $cat->id ? 'bg-(--marca-suave) font-semibold text-(--marca)' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                                        <span class="truncate">{{ $cat->nombre }}</span>
                                        <span class="text-xs {{ $categoria?->id === $cat->id ? '' : 'text-slate-400' }}">{{ $cat->productos }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </aside>
            @endif

            <div class="min-w-0 {{ $categorias->isEmpty() ? 'lg:col-span-2' : '' }}">
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
                                <a href="/#catalogo" class="inline-flex h-11 items-center rounded-xl bg-white px-5 text-sm font-semibold ring-1 ring-slate-200 hover:ring-slate-300">Ver todo el catálogo</a>
                            @endif
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl bg-(--marca) px-5 text-sm font-semibold text-white hover:bg-(--marca-oscuro)">
                                    @include('tienda.icono', ['n' => 'whatsapp'])Preguntar por WhatsApp
                                </a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:gap-5 {{ $categorias->isEmpty() ? 'lg:grid-cols-4' : 'xl:grid-cols-4' }}">
                        @foreach ($productos as $p)
                            @include('tienda.tarjeta', ['p' => $p, 'conInsignia' => true])
                        @endforeach
                    </div>

                    {{ $productos->onEachSide(1)->fragment('catalogo')->links('tienda.paginacion') }}
                @endif
            </div>
        </div>
    </section>
@endsection
