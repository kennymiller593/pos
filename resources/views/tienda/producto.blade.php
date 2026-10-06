@extends('tienda.layout')

@php
    $descripcion = $producto['descripcion']
        ?: trim("{$producto['nombre']}".($producto['marca'] ? " de {$producto['marca']}" : '').". Disponible en {$tienda['nombre']}.");
    $imagen = $producto['imagen'] ? (str_starts_with($producto['imagen'], 'http') ? $producto['imagen'] : rtrim($tienda['url'], '/').$producto['imagen']) : null;
    $variasPresentaciones = count($producto['presentaciones']) > 1;

    // datos estructurados: así el buscador puede mostrar el producto con su precio
    $datos = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $producto['nombre'],
        'sku' => $producto['codigo'],
        'description' => $descripcion,
        'image' => $imagen,
        'brand' => $producto['marca'] ? ['@type' => 'Brand', 'name' => $producto['marca']] : null,
        'category' => $producto['categoria'],
        'offers' => $producto['precio'] !== null ? array_filter([
            '@type' => 'Offer',
            'url' => $enlace,
            'priceCurrency' => 'PEN',
            'price' => number_format($producto['precio'], 2, '.', ''),
            'availability' => $producto['disponible'] === null ? null : 'https://schema.org/'.($producto['disponible'] ? 'InStock' : 'OutOfStock'),
        ]) : null,
    ]);
@endphp

@section('titulo', "{$producto['nombre']} · {$tienda['nombre']}")
@section('resumen', $descripcion)
@section('canonica', $enlace)
@section('imagen', $producto['imagen'] ?? '')
@section('og_tipo', 'product')

@push('cabecera')
    <script type="application/ld+json">{!! json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('contenido')
    <nav class="flex flex-wrap items-center gap-1 text-sm text-neutral-500" aria-label="Ruta">
        <a href="/" class="hover:text-(--marca)">Inicio</a>
        @if ($producto['categoria'])
            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
            <a href="/?categoria={{ $producto['categoria_id'] }}#catalogo" class="hover:text-(--marca)">{{ $producto['categoria'] }}</a>
        @endif
        @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
        <span class="truncate text-neutral-800" aria-current="page">{{ $producto['nombre'] }}</span>
    </nav>

    <article class="mt-4 grid gap-6 lg:grid-cols-2 lg:gap-10">
        {{-- Foto --}}
        <div class="relative self-start overflow-hidden rounded-3xl border border-stone-200 bg-white">
            <div class="aspect-[4/3]">
                @if ($producto['imagen'])
                    <img src="{{ $producto['imagen'] }}" alt="{{ $producto['nombre'] }}" class="size-full object-contain {{ $producto['disponible'] === false ? 'opacity-60' : '' }}">
                @else
                    <div class="grid size-full place-items-center bg-stone-50 text-stone-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-20'])</div>
                @endif
            </div>
            @if ($producto['destacado'])
                <span class="absolute top-3 left-3 inline-flex items-center gap-1 rounded-lg bg-amber-400 px-2.5 py-1 text-xs font-semibold text-amber-950">
                    @include('tienda.icono', ['n' => 'estrella', 'clase' => 'size-3.5', 'relleno' => true])Destacado
                </span>
            @endif
        </div>

        {{-- Detalles --}}
        <div class="min-w-0">
            @if ($producto['marca'])
                <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">{{ $producto['marca'] }}</p>
            @endif
            <h1 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $producto['nombre'] }}</h1>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                @if ($producto['disponible'] === true)
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700">
                        @include('tienda.icono', ['n' => 'check', 'clase' => 'size-3.5'])Disponible
                    </span>
                @elseif ($producto['disponible'] === false)
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-stone-200 px-2.5 py-1 font-semibold text-neutral-700">
                        @include('tienda.icono', ['n' => 'cerrar', 'clase' => 'size-3.5'])Agotado por ahora
                    </span>
                @endif
                <span class="text-neutral-500">Código {{ $producto['codigo'] }}</span>
            </div>

            {{-- Precio --}}
            <div class="mt-5">
                @if ($producto['precio'] !== null)
                    <p class="text-3xl font-bold tracking-tight sm:text-4xl">
                        S/ {{ number_format($producto['precio'], 2) }}
                        @if ($variasPresentaciones)
                            <span class="text-base font-normal text-neutral-500">/ {{ $producto['presentaciones'][0]['nombre'] }}</span>
                        @endif
                    </p>
                    @if (! $variasPresentaciones && $producto['presentaciones'][0]['mayorista'])
                        <p class="mt-1 text-sm text-neutral-600">
                            Por mayor: <span class="font-semibold">S/ {{ number_format($producto['presentaciones'][0]['mayorista']['precio'], 2) }}</span>
                            desde {{ rtrim(rtrim(number_format($producto['presentaciones'][0]['mayorista']['desde'], 3), '0'), '.') }} unidades
                        </p>
                    @endif
                @else
                    <p class="text-xl font-semibold text-(--marca)">Consulta el precio</p>
                    <p class="mt-0.5 text-sm text-neutral-500">Escríbenos y te lo pasamos al momento.</p>
                @endif
            </div>

            {{-- Presentaciones --}}
            @if ($variasPresentaciones)
                <div class="mt-5 overflow-hidden rounded-2xl border border-stone-200 bg-white">
                    <p class="border-b border-stone-200 px-4 py-2.5 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Presentaciones</p>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($producto['presentaciones'] as $pres)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <span class="min-w-0">
                                    <span class="block font-medium">{{ $pres['nombre'] }}</span>
                                    @if ($pres['mayorista'])
                                        <span class="block text-xs text-neutral-500">
                                            Por mayor S/ {{ number_format($pres['mayorista']['precio'], 2) }} desde {{ rtrim(rtrim(number_format($pres['mayorista']['desde'], 3), '0'), '.') }}
                                        </span>
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

            {{-- Pedir --}}
            <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                @if ($pedido)
                    <a href="{{ $pedido }}" target="_blank" rel="noopener" class="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-(--marca) px-6 text-base font-semibold text-white transition-colors hover:bg-(--marca-oscuro)">
                        @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])
                        {{ $producto['disponible'] === false ? 'Preguntar cuándo llega' : 'Pedir por WhatsApp' }}
                    </a>
                @endif
                @if ($contactos['telefono'])
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactos['telefono']) }}" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-6 text-base font-semibold transition-colors hover:bg-stone-50 {{ $pedido ? '' : 'flex-1' }}">
                        @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-5'])Llamar
                    </a>
                @endif
                @if (! $pedido && ! $contactos['telefono'])
                    <a href="#contacto" class="inline-flex h-12 flex-1 items-center justify-center rounded-xl bg-(--marca) px-6 text-base font-semibold text-white transition-colors hover:bg-(--marca-oscuro)">Ver cómo contactarnos</a>
                @endif
            </div>

            {{-- Descripción y ficha --}}
            @if ($producto['descripcion'])
                <div class="mt-7">
                    <h2 class="text-sm font-semibold tracking-tight">Descripción</h2>
                    <p class="mt-1.5 text-sm leading-relaxed whitespace-pre-line text-neutral-600">{{ $producto['descripcion'] }}</p>
                </div>
            @endif

            <dl class="mt-7 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 border-t border-stone-200 pt-5 text-sm">
                @if ($producto['marca'])
                    <dt class="text-neutral-500">Marca</dt><dd class="font-medium">{{ $producto['marca'] }}</dd>
                @endif
                @if ($producto['categoria'])
                    <dt class="text-neutral-500">Categoría</dt>
                    <dd class="font-medium"><a href="/?categoria={{ $producto['categoria_id'] }}#catalogo" class="hover:text-(--marca) hover:underline">{{ $producto['categoria'] }}</a></dd>
                @endif
                <dt class="text-neutral-500">Código</dt><dd class="font-medium">{{ $producto['codigo'] }}</dd>
            </dl>
        </div>
    </article>

    @if ($relacionados->isNotEmpty())
        <section class="mt-12" aria-labelledby="titulo-relacionados">
            <h2 id="titulo-relacionados" class="text-xl font-semibold tracking-tight">También te puede interesar</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($relacionados as $p)
                    @include('tienda.tarjeta', ['p' => $p])
                @endforeach
            </div>
        </section>
    @endif
@endsection
