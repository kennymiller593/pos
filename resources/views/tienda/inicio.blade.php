@extends('tienda.layout')

@php
    $pagina = $productos->currentPage();
    // enlace de la portada conservando lo que ya se eligió (búsqueda, categoría, orden)
    $enlace = fn (array $cambios) => '/?'.http_build_query(array_filter([
        'q' => $buscar ?: null,
        'categoria' => $categoria?->id,
        'orden' => $orden !== 'nombre' ? $orden : null,
        ...$cambios,
    ], fn ($v) => $v !== null && $v !== '')).'#catalogo';
    $subtitulos = [
        'elegidos' => 'Lo que más recomendamos de nuestra tienda.',
        'vendidos' => 'Lo que más se llevan nuestros clientes.',
        'nuevos' => 'Lo más reciente de nuestra tienda.',
    ];
@endphp

@section('titulo', $buscar !== '' ? "“{$buscar}” en {$tienda['nombre']}" : ($categoria ? "{$categoria->nombre} · {$tienda['nombre']}" : ($pagina > 1 ? "{$tienda['nombre']} · página {$pagina}" : $tienda['nombre'])))
@section('canonica', $tienda['url'].($categoria ? '?categoria='.$categoria->id : ''))
{{-- los resultados de búsqueda no se indexan: son infinitas combinaciones de la misma tienda --}}
@section('robots', $buscar !== '' ? 'noindex,follow' : 'index,follow')

@section('contenido')
    {{-- Presentación de la tienda (solo en la portada) --}}
    @unless ($filtrando || $pagina > 1)
        <section class="relative overflow-hidden rounded-3xl bg-(--marca) px-6 py-8 text-white sm:px-10 sm:py-12">
            <div class="pointer-events-none absolute -top-16 -right-16 size-64 rounded-full bg-white/10" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -right-8 -bottom-24 size-56 rounded-full bg-white/5" aria-hidden="true"></div>
            <div class="relative max-w-2xl">
                <h1 class="text-2xl font-bold tracking-tight sm:text-4xl">{{ $tienda['nombre'] }}</h1>
                <p class="mt-2 text-sm text-white/90 sm:text-base">
                    {{ $tienda['descripcion'] ?: 'Mira nuestro catálogo, busca lo que necesitas y haz tu pedido en un momento.' }}
                </p>
                <div class="mt-5 flex flex-wrap gap-2">
                    <a href="#catalogo" class="inline-flex h-11 items-center rounded-xl bg-white px-5 text-sm font-semibold text-(--marca) transition-colors hover:bg-white/90">Ver productos</a>
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/40 px-5 text-sm font-semibold text-white transition-colors hover:bg-white/10">
                            @include('tienda.icono', ['n' => 'whatsapp'])Pedir por WhatsApp
                        </a>
                    @endif
                </div>
            </div>
        </section>
    @endunless

    {{-- Productos destacados --}}
    @if ($destacados && $destacados['productos']->isNotEmpty())
        <section class="mt-8" aria-labelledby="titulo-destacados">
            <div class="flex items-center gap-2">
                <span class="text-amber-400">@include('tienda.icono', ['n' => 'estrella', 'clase' => 'size-5', 'relleno' => true])</span>
                <h2 id="titulo-destacados" class="text-xl font-semibold tracking-tight">Productos destacados</h2>
            </div>
            <p class="mt-0.5 text-sm text-neutral-500">{{ $subtitulos[$destacados['origen']] }}</p>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($destacados['productos'] as $p)
                    @include('tienda.tarjeta', ['p' => $p])
                @endforeach
            </div>
        </section>
    @endif

    {{-- Catálogo --}}
    <section id="catalogo" class="scroll-mt-36 sm:scroll-mt-24 {{ $filtrando || $pagina > 1 ? '' : 'mt-10' }}" aria-labelledby="titulo-catalogo">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h2 id="titulo-catalogo" class="text-xl font-semibold tracking-tight">
                    @if ($buscar !== '')
                        Resultados para “{{ $buscar }}”
                    @elseif ($categoria)
                        {{ $categoria->nombre }}
                    @else
                        Todos los productos
                    @endif
                </h2>
                <p class="mt-0.5 text-sm text-neutral-500">
                    {{ $productos->total() }} producto{{ $productos->total() === 1 ? '' : 's' }}@if ($buscar !== '' && $categoria) en {{ $categoria->nombre }}@endif
                    @if ($filtrando)
                        · <a href="/#catalogo" class="font-medium text-(--marca) hover:underline">Ver todo</a>
                    @endif
                </p>
            </div>

            @if ($tienda['mostrar_precios'] && $productos->total() > 1)
                <form action="/#catalogo" method="get" class="flex items-center gap-2">
                    @if ($buscar !== '') <input type="hidden" name="q" value="{{ $buscar }}"> @endif
                    @if ($categoria) <input type="hidden" name="categoria" value="{{ $categoria->id }}"> @endif
                    <label for="orden" class="text-sm text-neutral-500">Ordenar</label>
                    <select id="orden" name="orden" onchange="this.form.submit()" class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-(--marca) focus:outline-none">
                        <option value="nombre" @selected($orden === 'nombre')>Por nombre</option>
                        <option value="menor" @selected($orden === 'menor')>Menor precio</option>
                        <option value="mayor" @selected($orden === 'mayor')>Mayor precio</option>
                    </select>
                    <noscript><button type="submit" class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm font-medium">Aplicar</button></noscript>
                </form>
            @endif
        </div>

        {{-- categorías --}}
        @if ($categorias->isNotEmpty())
            <nav class="-mx-4 mt-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0" aria-label="Categorías">
                <a href="{{ $enlace(['categoria' => null]) }}" @if (! $categoria) aria-current="true" @endif
                    class="shrink-0 rounded-xl border px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-colors {{ $categoria ? 'border-stone-200 bg-white text-neutral-600 hover:border-(--marca) hover:text-(--marca)' : 'border-(--marca) bg-(--marca) text-white' }}">
                    Todas
                </a>
                @foreach ($categorias as $c)
                    <a href="{{ $enlace(['categoria' => $c->id]) }}" @if ($categoria?->id === $c->id) aria-current="true" @endif
                        class="shrink-0 rounded-xl border px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-colors {{ $categoria?->id === $c->id ? 'border-(--marca) bg-(--marca) text-white' : 'border-stone-200 bg-white text-neutral-600 hover:border-(--marca) hover:text-(--marca)' }}">
                        {{ $c->nombre }} <span class="{{ $categoria?->id === $c->id ? 'text-white/75' : 'text-neutral-400' }}">{{ $c->productos }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($productos->isEmpty())
            <div class="mt-6 rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-stone-100 text-stone-400">@include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-6'])</span>
                <p class="mt-4 font-semibold">
                    {{ $filtrando ? 'No encontramos productos con esa búsqueda' : 'Pronto verás aquí nuestros productos' }}
                </p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-neutral-500">
                    @if ($filtrando)
                        Prueba con otra palabra o mira todo el catálogo.@if ($whatsapp) Si buscas algo en especial, escríbenos. @endif
                    @else
                        Estamos preparando el catálogo.@if ($whatsapp) Mientras tanto, escríbenos y te atendemos. @endif
                    @endif
                </p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    @if ($filtrando)
                        <a href="/#catalogo" class="inline-flex h-10 items-center rounded-xl border border-stone-300 px-4 text-sm font-semibold hover:bg-stone-50">Ver todo el catálogo</a>
                    @endif
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-xl bg-(--marca) px-4 text-sm font-semibold text-white hover:bg-(--marca-oscuro)">
                            @include('tienda.icono', ['n' => 'whatsapp'])Preguntar por WhatsApp
                        </a>
                    @endif
                </div>
            </div>
        @else
            <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($productos as $p)
                    @include('tienda.tarjeta', ['p' => $p, 'conInsignia' => true])
                @endforeach
            </div>

            {{ $productos->onEachSide(1)->fragment('catalogo')->links('tienda.paginacion') }}
        @endif
    </section>
@endsection
