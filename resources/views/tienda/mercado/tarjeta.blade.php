{{-- Plantilla Mercado: tarjeta de un producto en la cuadrícula. $p viene de CatalogoTiendaService::tarjeta() --}}
@php
    // la línea bajo el nombre: marca y presentación, lo que haya
    $detalle = implode(' · ', array_filter([$p['marca'], $p['presentacion']]));
    $mayorista = collect($p['presentaciones'])->firstWhere('mayorista', '!=', null)['mayorista'] ?? null;
@endphp
<article class="group flex h-full flex-col overflow-hidden rounded-xl bg-white ring-1 ring-slate-200 transition hover:shadow-lg hover:shadow-slate-900/10 hover:ring-slate-300" data-tarjeta-mercado>
    <a href="{{ $p['url'] }}" class="flex flex-1 flex-col focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--marca)">
        <div class="relative aspect-square overflow-hidden bg-slate-50">
            @if ($p['imagen'])
                <img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}"@if ($prioritaria ?? false) fetchpriority="high"@else loading="lazy"@endif decoding="async" width="600" height="600"
                    class="size-full object-contain p-4 mix-blend-multiply transition-transform duration-300 group-hover:scale-105 {{ $p['disponible'] === false ? 'opacity-40 grayscale' : '' }}">
            @else
                <div class="grid size-full place-items-center text-slate-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-12', 'grosor' => 1.25])</div>
            @endif

            {{-- insignia: agotado, destacado o precio por mayor --}}
            @if ($p['disponible'] === false)
                <span class="absolute top-2.5 left-2.5 rounded bg-slate-900 px-2 py-1 text-[11px] font-bold tracking-wide text-white uppercase">Agotado</span>
            @elseif ($p['destacado'])
                <span class="absolute top-2.5 left-2.5 rounded bg-(--marca) px-2 py-1 text-[11px] font-bold tracking-wide text-(--sobre-marca) uppercase">Destacado</span>
            @elseif ($mayorista)
                <span class="absolute top-2.5 left-2.5 rounded bg-amber-400 px-2 py-1 text-[11px] font-bold tracking-wide text-amber-950 uppercase">Por mayor</span>
            @endif
        </div>

        <div class="flex flex-1 flex-col px-3.5 pt-3">
            <h3 class="line-clamp-2 text-[15px] leading-snug font-bold text-slate-900 group-hover:text-(--marca-texto)">{{ $p['nombre'] }}</h3>
            @if ($detalle !== '')
                <p class="mt-0.5 truncate text-xs text-slate-500">{{ $detalle }}</p>
            @endif
            <p class="mt-auto pt-2 leading-none">
                @if ($p['precio'] !== null)
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">S/ {{ number_format($p['precio'], 2) }}</span>
                    @if ($mayorista)
                        <span class="mt-1 block text-xs text-slate-500">Por mayor S/ {{ number_format($mayorista['precio'], 2) }} desde {{ rtrim(rtrim(number_format($mayorista['desde'], 3), '0'), '.') }}</span>
                    @endif
                @else
                    <span class="text-sm font-semibold text-slate-500">Consultar precio</span>
                @endif
            </p>
        </div>
    </a>

    {{-- Agregar al carrito (el pedido completo sale por WhatsApp); si está agotado, consultar --}}
    @if ($p['pedido'])
        <div class="px-3.5 pt-3 pb-3.5" data-zona-pedido>
            @if ($p['disponible'] !== false)
                <button type="button" data-anadir="{{ json_encode($p['para_pedido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-necesita-js
                    class="flex h-10 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-(--mercado-fondo) px-2 text-[13px] font-bold text-(--sobre-fondo) transition hover:brightness-125 sm:text-sm"
                    aria-label="Agregar {{ $p['nombre'] }} al carrito">
                    @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-4'])<span data-anadir-texto>Agregar al carrito</span>
                </button>
                {{-- sin JavaScript, el pedido va directo por WhatsApp --}}
                <noscript><a href="{{ $p['pedido'] }}" target="_blank" rel="noopener" class="flex h-10 w-full items-center justify-center rounded-lg bg-(--mercado-fondo) text-sm font-bold text-(--sobre-fondo)">Pedir por WhatsApp</a></noscript>
            @else
                <a href="{{ $p['pedido'] }}" target="_blank" rel="noopener"
                    class="flex h-10 w-full items-center justify-center gap-1.5 rounded-lg px-2 text-[13px] font-semibold text-slate-700 ring-1 ring-slate-200 transition-colors hover:bg-slate-50 sm:text-sm"
                    aria-label="Consultar {{ $p['nombre'] }} por WhatsApp">
                    @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4 text-[#1fa855]'])Consultar
                </a>
            @endif
        </div>
    @endif
</article>
