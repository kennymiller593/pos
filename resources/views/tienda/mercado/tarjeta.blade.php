{{-- Plantilla Mercado: tarjeta de un producto. $p viene de CatalogoTiendaService::tarjeta(); $insignia opcional ('nuevo') --}}
@php
    $mayorista = collect($p['presentaciones'])->firstWhere('mayorista', '!=', null)['mayorista'] ?? null;
    $subtitulo = $p['categoria'] ?: $p['marca'];
@endphp
<article class="group relative flex h-full flex-col overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/80 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-slate-900/10 hover:ring-slate-300" data-tarjeta-mercado>
    <a href="{{ $p['url'] }}" class="flex flex-1 flex-col focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--marca)">
        <div class="relative aspect-square overflow-hidden bg-white">
            @if ($p['imagen'])
                <img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}"@if ($prioritaria ?? false) fetchpriority="high"@else loading="lazy"@endif decoding="async" width="600" height="600"
                    class="size-full object-contain p-5 transition-transform duration-300 group-hover:scale-105 {{ $p['disponible'] === false ? 'opacity-40 grayscale' : '' }}">
            @else
                <div class="grid size-full place-items-center bg-slate-50 text-slate-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-12', 'grosor' => 1.25])</div>
            @endif
            {{-- la marca, como sello discreto arriba a la derecha --}}
            @if ($p['marca'])
                <span class="absolute top-2.5 right-2.5 max-w-[55%] truncate rounded-md bg-white/90 px-2 py-0.5 text-[10px] font-bold tracking-wider text-slate-500 uppercase ring-1 ring-slate-200">{{ $p['marca'] }}</span>
            @endif
            {{-- insignia --}}
            @if ($p['disponible'] === false)
                <span class="absolute top-2.5 left-2.5 rounded-md bg-slate-900 px-2 py-1 text-[11px] font-bold tracking-wide text-white uppercase">Agotado</span>
            @elseif (($insignia ?? null) === 'nuevo')
                <span class="absolute top-2.5 left-2.5 rounded-md bg-red-600 px-2 py-1 text-[11px] font-bold tracking-wide text-white uppercase">Novedad</span>
            @elseif ($p['destacado'])
                <span class="absolute top-2.5 left-2.5 rounded-md bg-(--marca) px-2 py-1 text-[11px] font-bold tracking-wide text-(--sobre-marca) uppercase">Destacado</span>
            @elseif ($mayorista)
                <span class="absolute top-2.5 left-2.5 rounded-md bg-amber-400 px-2 py-1 text-[11px] font-bold tracking-wide text-amber-950 uppercase">Por mayor</span>
            @endif
        </div>
        {{-- línea de color que separa la foto del texto, como en las tiendas grandes --}}
        <div class="h-1 bg-(--marca)/80"></div>

        <div class="flex flex-1 flex-col px-3.5 pt-3">
            <h3 class="line-clamp-2 text-[15px] leading-snug font-bold text-slate-900 group-hover:text-(--marca-texto)">{{ $p['nombre'] }}</h3>
            <p class="mt-auto pt-2 leading-none">
                @if ($p['precio'] !== null)
                    <span class="text-xl font-extrabold tracking-tight text-(--marca-texto)">S/ {{ number_format($p['precio'], 2) }}</span>
                    @if ($p['presentacion'])
                        <span class="text-xs text-slate-500">/ {{ mb_strtolower($p['presentacion']) }}</span>
                    @endif
                    @if ($mayorista)
                        <span class="mt-1.5 block text-[11px] text-slate-500">Por mayor S/ {{ number_format($mayorista['precio'], 2) }} desde {{ rtrim(rtrim(number_format($mayorista['desde'], 3), '0'), '.') }}</span>
                    @endif
                @else
                    <span class="text-sm font-semibold text-slate-500">Consultar precio</span>
                @endif
            </p>
            @if ($subtitulo)
                <p class="mt-2 truncate text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ $subtitulo }}</p>
            @endif
        </div>
    </a>

    {{-- cantidad, agregar al carrito (el pedido completo sale por WhatsApp) y comprar este producto por WhatsApp; si está agotado, consultar --}}
    @if ($p['pedido'])
        <div class="@container px-3 pt-3 pb-3 sm:px-3.5 sm:pb-3.5" data-zona-pedido>
            @if ($p['disponible'] !== false)
                {{-- en tarjetas angostas (celular) va todo apilado; en las anchas, cantidad y botón en una fila --}}
                <div class="flex flex-col gap-1.5 @min-[17rem]:flex-row">
                    <div class="flex h-10 items-center justify-between rounded-lg bg-slate-50 ring-1 ring-slate-200 @min-[17rem]:w-auto @min-[17rem]:shrink-0 @min-[17rem]:justify-start" data-necesita-js>
                        <button type="button" data-elegir-menos aria-label="Uno menos" class="grid h-10 w-9 cursor-pointer place-items-center rounded-l-lg text-slate-600 transition-colors hover:bg-slate-200 @min-[17rem]:w-8">@include('tienda.icono', ['n' => 'menos', 'clase' => 'size-3.5'])</button>
                        <input type="text" inputmode="numeric" data-cantidad-elegida value="1" maxlength="3" aria-label="Cantidad" class="h-10 min-w-0 flex-1 bg-transparent text-center text-sm font-bold tabular-nums focus:outline-none @min-[17rem]:w-8 @min-[17rem]:flex-none">
                        <button type="button" data-elegir-mas aria-label="Uno más" class="grid h-10 w-9 cursor-pointer place-items-center rounded-r-lg text-slate-600 transition-colors hover:bg-slate-200 @min-[17rem]:w-8">@include('tienda.icono', ['n' => 'mas', 'clase' => 'size-3.5'])</button>
                    </div>
                    <button type="button" data-anadir="{{ json_encode($p['para_pedido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-necesita-js
                        class="flex h-10 w-full shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-(--marca) px-2 text-[13px] font-bold text-(--sobre-marca) transition hover:brightness-110 @min-[17rem]:min-w-0 @min-[17rem]:flex-1"
                        aria-label="Agregar al carrito: {{ $p['nombre'] }}">
                        @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-4'])<span data-anadir-texto class="truncate">Agregar al carrito</span>
                    </button>
                </div>
                <a href="{{ $p['pedido'] }}" target="_blank" rel="noopener" data-comprar
                    class="mt-1.5 flex h-10 w-full items-center justify-center gap-1.5 rounded-lg bg-[#25D366] px-2 text-[13px] font-bold text-white transition-colors hover:bg-[#1eb957]"
                    aria-label="Comprar {{ $p['nombre'] }} por WhatsApp">
                    @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4'])Comprar
                </a>
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
