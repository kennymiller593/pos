{{-- Plantilla Plaza: tarjeta de un producto. $p viene de CatalogoTiendaService::tarjeta(); $insignia opcional ('nuevo') --}}
@php
    $mayorista = collect($p['presentaciones'])->firstWhere('mayorista', '!=', null)['mayorista'] ?? null;
    $subtitulo = $p['marca'] ?: $p['categoria'];
    // variante de un modelo (tallas y colores): la tarjeta es del modelo entero; null en un producto suelto
    $m = $p['modelo'] ?? null;
    $titulo = $m ? $m['nombre'] : $p['nombre'];
    $disponible = $m ? $m['disponible'] : $p['disponible'];
    $precio = $m ? $m['precio_desde'] : $p['precio'];
    $foto = $p['imagen'] ?: ($m['imagen'] ?? null);
    // con varias tallas o colores no se añade a ciegas: el botón lleva a la página a elegir
    $elegir = $m && $m['cuantas'] > 1;
@endphp
<article class="group relative flex h-full flex-col overflow-hidden rounded-lg bg-white ring-1 ring-slate-200 transition duration-200 hover:shadow-lg hover:shadow-slate-900/10 hover:ring-slate-300"@if ($m) data-tarjeta-modelo="{{ $m['cuantas'] }}"@endif data-tarjeta-plaza>
    <a href="{{ $p['url'] }}" class="flex flex-1 flex-col focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--marca)">
        {{-- la foto va sobre gris claro: las fotos con fondo blanco se funden con él --}}
        <div class="relative aspect-[5/4] overflow-hidden border-b border-slate-100 bg-slate-50">
            @if ($foto)
                <img src="{{ $foto }}" alt="{{ $titulo }}"@if ($prioritaria ?? false) fetchpriority="high"@else loading="lazy"@endif decoding="async" width="600" height="600" @if (($p['miniatura'] ?? null) && $p['imagen']) srcset="{{ $p['miniatura'] }} 400w, {{ $p['imagen'] }} 800w" sizes="(min-width: 1280px) 16vw, (min-width: 768px) 30vw, 48vw"@endif
                    class="size-full object-contain p-3 mix-blend-multiply transition-transform duration-300 group-hover:scale-105 {{ $disponible === false ? 'opacity-40 grayscale' : '' }}">
            @else
                <div class="grid size-full place-items-center text-slate-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-12', 'grosor' => 1.25])</div>
            @endif
            {{-- insignia --}}
            @if ($disponible === false)
                <span class="absolute top-2 left-2 rounded bg-slate-800 px-2 py-0.5 text-[11px] font-bold tracking-wide text-white uppercase">Agotado</span>
            @elseif (($insignia ?? null) === 'nuevo')
                <span class="absolute top-2 left-2 rounded bg-(--plaza-acento) px-2 py-0.5 text-[11px] font-bold tracking-wide text-(--sobre-acento) uppercase">Nuevo</span>
            @elseif ($p['destacado'])
                <span class="absolute top-2 left-2 rounded bg-(--plaza-acento) px-2 py-0.5 text-[11px] font-bold tracking-wide text-(--sobre-acento) uppercase">Destacado</span>
            @elseif ($mayorista)
                <span class="absolute top-2 left-2 rounded bg-amber-400 px-2 py-0.5 text-[11px] font-bold tracking-wide text-amber-950 uppercase">Por mayor</span>
            @endif
        </div>

        <div class="flex flex-1 flex-col px-3 pt-3 sm:px-3.5">
            <h3 class="line-clamp-2 text-sm leading-snug text-slate-700 group-hover:text-(--marca-texto)">{{ $titulo }}</h3>
            @if ($m && $m['variacion'])
                <p class="mt-1 truncate text-xs text-slate-500" data-modelo-variacion>{{ $m['variacion'] }}</p>
            @endif
            <p class="mt-auto pt-2.5 leading-none"@if ($m) data-modelo-precio @endif>
                @if ($precio !== null)
                    @if ($m && $m['varios_precios'])
                        <span class="text-xs text-slate-500">Desde</span>
                    @endif
                    <span class="text-[1.35rem] font-bold tracking-tight text-slate-900">S/ {{ number_format($precio, 2) }}</span>
                    @if ($p['presentacion'])
                        <span class="text-xs text-slate-500">/ {{ mb_strtolower($p['presentacion']) }}</span>
                    @endif
                @else
                    <span class="text-sm font-semibold text-slate-500">Consultar precio</span>
                @endif
            </p>
            @if ($precio !== null && $mayorista)
                <p class="mt-1.5 text-[13px] leading-tight text-(--acento-texto)">Por mayor S/ {{ number_format($mayorista['precio'], 2) }} desde {{ rtrim(rtrim(number_format($mayorista['desde'], 3), '0'), '.') }}</p>
            @endif
            @if ($subtitulo)
                <p class="mt-1 truncate text-[13px] font-semibold text-(--acento-texto)">{{ $subtitulo }}</p>
            @endif
        </div>
    </a>

    {{-- cantidad, agregar al carrito (el pedido completo sale por WhatsApp) y comprar este producto por WhatsApp; si está agotado, consultar --}}
    @if ($p['pedido'] && $elegir)
        <div class="px-3 pt-3 pb-3 sm:px-3.5 sm:pb-3.5">
            <a href="{{ $p['url'] }}" data-elegir-modelo
                class="flex h-11 w-full items-center justify-center gap-1.5 rounded-lg bg-(--marca) px-2 text-[13px] font-bold text-(--sobre-marca) transition hover:brightness-110 sm:h-10"
                aria-label="Ver las opciones de {{ $titulo }}">
                Ver opciones
            </a>
        </div>
    @elseif ($p['pedido'])
        <div class="@container px-3 pt-3 pb-3 sm:px-3.5 sm:pb-3.5" data-zona-pedido>
            @if ($p['disponible'] !== false)
                {{-- en tarjetas angostas (celular) la cantidad va arriba; en las anchas, todo en una fila --}}
                <div class="flex flex-col gap-1.5 @min-[17rem]:flex-row">
                    <div class="flex h-10 items-center justify-between rounded-lg bg-slate-50 ring-1 ring-slate-200 @min-[17rem]:shrink-0 @min-[17rem]:justify-start" data-necesita-js>
                        <button type="button" data-elegir-menos aria-label="Uno menos" class="grid h-10 w-10 cursor-pointer place-items-center rounded-l-lg text-slate-600 transition-colors hover:bg-slate-200 @min-[17rem]:w-8">@include('tienda.icono', ['n' => 'menos', 'clase' => 'size-3.5'])</button>
                        <input type="text" inputmode="numeric" data-cantidad-elegida value="1" maxlength="3" aria-label="Cantidad" class="h-10 min-w-0 flex-1 bg-transparent text-center text-sm font-bold tabular-nums focus:outline-none @min-[17rem]:w-8 @min-[17rem]:flex-none">
                        <button type="button" data-elegir-mas aria-label="Uno más" class="grid h-10 w-10 cursor-pointer place-items-center rounded-r-lg text-slate-600 transition-colors hover:bg-slate-200 @min-[17rem]:w-8">@include('tienda.icono', ['n' => 'mas', 'clase' => 'size-3.5'])</button>
                    </div>
                    <div class="flex min-w-0 flex-1 gap-1.5">
                        <button type="button" data-anadir="{{ json_encode($p['para_pedido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-necesita-js
                            class="flex h-10 min-w-0 flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-(--marca) px-2 text-[13px] font-bold text-(--sobre-marca) transition hover:brightness-110"
                            aria-label="Agregar al carrito: {{ $p['nombre'] }}">
                            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-4'])<span data-anadir-texto class="truncate">Agregar</span>
                        </button>
                        <a href="{{ $p['pedido'] }}" target="_blank" rel="noopener" data-comprar
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[#25D366] text-white transition-colors hover:bg-[#1eb957]"
                            aria-label="Comprar {{ $p['nombre'] }} por WhatsApp" title="Comprar por WhatsApp">
                            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4.5'])
                        </a>
                    </div>
                </div>
            @else
                <a href="{{ $p['pedido'] }}" target="_blank" rel="noopener"
                    class="flex h-10 w-full items-center justify-center gap-1.5 rounded-lg px-2 text-[13px] font-semibold text-slate-700 ring-1 ring-slate-200 transition-colors hover:bg-slate-50"
                    aria-label="Consultar {{ $p['nombre'] }} por WhatsApp">
                    @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4 text-[#1fa855]'])Consultar
                </a>
            @endif
        </div>
    @endif
</article>
