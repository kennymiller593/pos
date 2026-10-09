@extends('tienda.layout')

@php
    $principal = $producto['presentaciones'][0];
    // sin descripción del dueño, una frase con lo que sí sabemos: qué es, de quién, dónde y cuánto
    $complemento = trim(implode(' ', array_filter([
        $producto['nombre'].($producto['marca'] ? " de {$producto['marca']}" : '').($producto['categoria'] ? ", {$producto['categoria']}" : '').'.',
        count($producto['presentaciones']) > 1 ? 'Presentaciones: '.implode(', ', array_column($producto['presentaciones'], 'nombre')).'.' : null,
        $producto['precio'] !== null ? 'Precio S/ '.number_format($producto['precio'], 2).'.' : null,
        "Pídelo por WhatsApp en {$tienda['nombre']}".($contactos['ciudad'] ? ", {$contactos['ciudad']}" : '').'.',
    ])));
    // una descripción de una sola línea ("Foliar en polvo.") se completa con lo que sí sabemos
    $descripcion = match (true) {
        blank($producto['descripcion']) => $complemento,
        mb_strlen(trim($producto['descripcion'])) < 80 => rtrim(trim($producto['descripcion']), '.').'. '.$complemento,
        default => $producto['descripcion'],
    };
    $imagen = $producto['imagen'] ? (str_starts_with($producto['imagen'], 'http') ? $producto['imagen'] : rtrim($tienda['url'], '/').$producto['imagen']) : null;
    $variasPresentaciones = count($producto['presentaciones']) > 1;
    $cantidad = fn (float $n) => rtrim(rtrim(number_format($n, 3), '0'), '.');
    $soloNumero = fn (?string $telefono) => preg_replace('/[^0-9+]/', '', (string) $telefono);
    $textoPedido = $producto['disponible'] === false ? 'Preguntar cuándo llega' : 'Pedir por WhatsApp';

    // datos estructurados: así el buscador puede mostrar el producto con su precio
    $datos = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $producto['nombre'],
        'sku' => $producto['codigo'],
        'description' => \Illuminate\Support\Str::squish($descripcion),
        'image' => $imagen,
        'brand' => $producto['marca'] ? ['@type' => 'Brand', 'name' => $producto['marca']] : null,
        'category' => $producto['categoria'],
        'offers' => $producto['precio'] !== null ? array_filter([
            '@type' => 'Offer',
            'url' => $enlace,
            'priceCurrency' => 'PEN',
            'price' => number_format($producto['precio'], 2, '.', ''),
            // si la tienda no marca lo agotado, lo que está publicado se vende
            'availability' => 'https://schema.org/'.($producto['disponible'] === false ? 'OutOfStock' : 'InStock'),
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Store', '@id' => rtrim($tienda['url'], '/').'/#negocio', 'name' => $tienda['nombre']],
        ]) : null,
    ]);

    // la ruta Inicio › Catálogo › Categoría › Producto, también para el buscador
    $ruta = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_values(array_filter([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => $tienda['url']],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Catálogo', 'item' => rtrim($tienda['url'], '/').'/catalogo'],
            $producto['categoria'] && ($categoriaUrl ?? null) ? ['@type' => 'ListItem', 'position' => 3, 'name' => $producto['categoria'], 'item' => rtrim($tienda['url'], '/').$categoriaUrl] : null,
            ['@type' => 'ListItem', 'position' => $producto['categoria'] && ($categoriaUrl ?? null) ? 4 : 3, 'name' => $producto['nombre']],
        ])),
    ];
@endphp

@section('titulo', "{$producto['nombre']} · {$tienda['nombre']}")
@section('resumen', $descripcion)
@section('canonica', $enlace)
@section('imagen', $producto['imagen'] ?? '')
@section('og_tipo', 'product')

@push('cabecera')
    <script type="application/ld+json">{!! json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    <script type="application/ld+json">{!! json_encode($ruta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('contenido')
    <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
            <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
            <a href="/catalogo" class="hover:text-(--marca-texto)">Catálogo</a>
            @if ($producto['categoria'])
                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
                <a href="{{ $categoriaUrl ?? '/catalogo' }}" class="hover:text-(--marca-texto)">{{ $producto['categoria'] }}</a>
            @endif
            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
            <span class="truncate text-slate-800" aria-current="page">{{ $producto['nombre'] }}</span>
        </nav>

        <article class="mt-5 grid gap-8 lg:grid-cols-2 lg:gap-14">
            {{-- Foto --}}
            <div class="relative self-start overflow-hidden rounded-3xl bg-slate-50 ring-1 ring-slate-100 lg:sticky lg:top-40">
                <div class="aspect-[4/3] sm:aspect-square">
                    @if ($producto['imagen'])
                        <img src="{{ $producto['imagen'] }}" alt="{{ $producto['nombre'] }}" width="800" height="600" fetchpriority="high"
                            class="size-full object-contain p-6 mix-blend-multiply sm:p-10 {{ $producto['disponible'] === false ? 'opacity-50 grayscale' : '' }}">
                    @else
                        <div class="grid size-full place-items-center text-slate-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-24', 'grosor' => 1])</div>
                    @endif
                </div>
                @if ($producto['destacado'])
                    <span class="absolute top-4 left-4 inline-flex items-center gap-1 rounded-md bg-amber-400 px-2.5 py-1 text-xs font-semibold tracking-wide text-amber-950 uppercase">
                        @include('tienda.icono', ['n' => 'estrella', 'clase' => 'size-3.5', 'relleno' => true])Destacado
                    </span>
                @endif
            </div>

            {{-- Detalles --}}
            <div class="min-w-0">
                @if ($producto['marca'])
                    <p class="text-sm font-semibold tracking-wider text-(--marca-texto) uppercase">{{ $producto['marca'] }}</p>
                @endif
                <h1 class="mt-1.5 text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-4xl">{{ $producto['nombre'] }}</h1>

                <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                    @if ($producto['disponible'] === true)
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 ring-1 ring-emerald-600/15">
                            <span class="size-1.5 rounded-full bg-emerald-500"></span>Disponible
                        </span>
                    @elseif ($producto['disponible'] === false)
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1 font-semibold text-slate-700 ring-1 ring-slate-900/10">
                            <span class="size-1.5 rounded-full bg-slate-400"></span>Agotado por ahora
                        </span>
                    @endif
                    <span class="text-slate-500">Código {{ $producto['codigo'] }}</span>
                </div>

                {{-- Precio y pedido --}}
                <div class="mt-6 rounded-2xl bg-slate-50 p-5 ring-1 ring-slate-100 sm:p-6">
                    @if ($producto['precio'] !== null)
                        <p class="text-sm text-slate-500">Precio{{ $variasPresentaciones ? ' por '.mb_strtolower($principal['nombre']) : '' }}</p>
                        <p class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="text-lg font-medium text-slate-500">S/</span>
                            <span class="text-4xl font-semibold tracking-tight sm:text-5xl">{{ number_format($producto['precio'], 2) }}</span>
                        </p>
                        @if (! $variasPresentaciones && $principal['mayorista'])
                            <p class="mt-2 text-sm text-slate-600">
                                Por mayor: <span class="font-semibold text-slate-900">S/ {{ number_format($principal['mayorista']['precio'], 2) }}</span>
                                desde {{ $cantidad($principal['mayorista']['desde']) }} unidades
                            </p>
                        @endif
                    @else
                        <p class="text-xl font-semibold tracking-tight">Consulta el precio</p>
                        <p class="mt-0.5 text-sm text-slate-500">Escríbenos y te lo pasamos al momento.</p>
                    @endif

                    {{-- sumar al pedido, eligiendo cuántos --}}
                    @if ($pedido && $producto['disponible'] !== false)
                        <div class="mt-5 flex gap-2" data-zona-pedido data-necesita-js>
                            <div class="flex h-13 shrink-0 items-center rounded-xl bg-white ring-1 ring-slate-200">
                                <button type="button" data-elegir-menos aria-label="Uno menos" class="grid h-13 w-11 cursor-pointer place-items-center rounded-l-xl text-slate-600 transition-colors hover:bg-slate-100">@include('tienda.icono', ['n' => 'menos'])</button>
                                <input type="text" inputmode="numeric" data-cantidad-elegida value="1" maxlength="3" aria-label="Cantidad" class="h-13 w-11 bg-transparent text-center text-base font-semibold tabular-nums focus:outline-none">
                                <button type="button" data-elegir-mas aria-label="Uno más" class="grid h-13 w-11 cursor-pointer place-items-center rounded-r-xl text-slate-600 transition-colors hover:bg-slate-100">@include('tienda.icono', ['n' => 'mas'])</button>
                            </div>
                            <button type="button" data-anadir="{{ json_encode($producto['para_pedido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                                class="inline-flex h-13 min-w-0 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 text-base font-semibold text-white transition-colors hover:bg-slate-700">
                                @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-5'])<span data-anadir-texto>Añadir al pedido</span>
                            </button>
                        </div>
                    @endif

                    <div class="{{ $pedido && $producto['disponible'] !== false ? 'mt-2' : 'mt-5' }} flex flex-col gap-2 sm:flex-row">
                        @if ($pedido)
                            <a href="{{ $pedido }}" target="_blank" rel="noopener" class="inline-flex h-13 shrink-0 items-center sm:flex-1 justify-center gap-2 rounded-xl bg-(--marca) px-6 text-base font-semibold text-(--sobre-marca) shadow-sm transition-colors hover:bg-(--marca-oscuro)">
                                @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5']){{ $textoPedido }}
                            </a>
                        @endif
                        @if ($contactos['telefono'])
                            <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="inline-flex h-13 items-center justify-center gap-2 rounded-xl bg-white px-6 text-base font-semibold ring-1 ring-slate-200 transition-colors hover:ring-slate-300 shrink-0 {{ $pedido ? '' : 'sm:flex-1' }}">
                                @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-5'])Llamar
                            </a>
                        @endif
                        @if (! $pedido && ! $contactos['telefono'])
                            <a href="#contacto" class="inline-flex h-13 shrink-0 items-center sm:flex-1 justify-center rounded-xl bg-(--marca) px-6 text-base font-semibold text-(--sobre-marca) transition-colors hover:bg-(--marca-oscuro)">Ver cómo contactarnos</a>
                        @endif
                    </div>
                </div>

                {{-- Presentaciones --}}
                @if ($variasPresentaciones)
                    <div class="mt-6">
                        <h2 class="text-sm font-semibold">Presentaciones</h2>
                        <ul class="mt-2 divide-y divide-slate-100 overflow-hidden rounded-2xl ring-1 ring-slate-200">
                            @foreach ($producto['presentaciones'] as $pres)
                                <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <span class="min-w-0">
                                        <span class="block font-medium">{{ $pres['nombre'] }}</span>
                                        @if ($pres['mayorista'])
                                            <span class="block text-xs text-slate-500">Por mayor S/ {{ number_format($pres['mayorista']['precio'], 2) }} desde {{ $cantidad($pres['mayorista']['desde']) }}</span>
                                        @endif
                                    </span>
                                    @if ($pres['precio'] !== null)
                                        <span class="shrink-0 font-semibold tabular-nums">S/ {{ number_format($pres['precio'], 2) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Descripción y ficha --}}
                @if ($producto['descripcion'])
                    <div class="mt-8 border-t border-slate-100 pt-6">
                        <h2 class="text-lg font-semibold tracking-tight">Descripción</h2>
                        <p class="mt-2 leading-relaxed whitespace-pre-line text-slate-600">{{ $producto['descripcion'] }}</p>
                    </div>
                @endif

                <div class="mt-8 border-t border-slate-100 pt-6">
                    <h2 class="text-lg font-semibold tracking-tight">Ficha del producto</h2>
                    <dl class="mt-3 divide-y divide-slate-100 text-sm">
                        @if ($producto['marca'])
                            <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Marca</dt><dd class="text-right font-medium">{{ $producto['marca'] }}</dd></div>
                        @endif
                        @if ($producto['categoria'])
                            <div class="flex justify-between gap-4 py-2.5">
                                <dt class="text-slate-500">Categoría</dt>
                                <dd class="text-right font-medium"><a href="{{ $categoriaUrl ?? '/catalogo' }}" class="hover:text-(--marca-texto) hover:underline">{{ $producto['categoria'] }}</a></dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Código</dt><dd class="text-right font-medium">{{ $producto['codigo'] }}</dd></div>
                        @if (! $variasPresentaciones)
                            <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Presentación</dt><dd class="text-right font-medium">{{ $principal['nombre'] }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>
        </article>

        @if ($relacionados->isNotEmpty())
            <section class="mt-16" aria-labelledby="titulo-relacionados">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="titulo-relacionados" class="text-2xl font-semibold tracking-tight">También te puede interesar</h2>
                    @if ($producto['categoria'])
                        <a href="{{ $categoriaUrl ?? '/catalogo' }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-(--marca-texto) hover:underline">Ver más de {{ $producto['categoria'] }} @include('tienda.icono', ['n' => 'flecha'])</a>
                    @endif
                </div>
                <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 lg:gap-5">
                    @foreach ($relacionados as $p)
                        @include('tienda.tarjeta', ['p' => $p])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection

{{-- En el celular el botón de pedir acompaña mientras se baja por la página --}}
@if ($pedido)
    @section('barra_movil')
        <div class="h-20 sm:hidden" aria-hidden="true"></div>
        <div class="fixed inset-x-0 bottom-0 z-40 flex items-center gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:hidden">
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs text-slate-500">{{ $producto['nombre'] }}</p>
                <p class="text-lg leading-tight font-semibold tracking-tight">{{ $producto['precio'] !== null ? 'S/ '.number_format($producto['precio'], 2) : 'Consulta el precio' }}</p>
            </div>
            <button type="button" data-pedido-abrir data-necesita-js aria-label="Ver mi pedido" class="relative grid size-12 shrink-0 cursor-pointer place-items-center rounded-xl text-slate-700 ring-1 ring-slate-200">
                @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-5'])
                <span data-pedido-cuenta class="absolute -top-1.5 -right-1.5 hidden min-w-5 rounded-full bg-(--marca) px-1 text-center text-[11px] leading-5 font-semibold text-(--sobre-marca) ring-2 ring-white"></span>
            </button>
            <a href="{{ $pedido }}" target="_blank" rel="noopener" class="inline-flex h-12 shrink-0 items-center gap-2 rounded-xl bg-(--marca) px-5 text-sm font-semibold text-(--sobre-marca)">
                @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Pedir
            </a>
        </div>
    @endsection
@endif
