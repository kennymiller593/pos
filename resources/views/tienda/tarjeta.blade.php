{{-- Tarjeta de un producto en una cuadrícula. $p viene de CatalogoTiendaService::tarjeta() --}}
<a href="{{ $p['url'] }}" class="group flex h-full flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white transition-shadow hover:shadow-lg hover:shadow-stone-900/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--marca)">
    <div class="relative aspect-[4/3] bg-white">
        @if ($p['imagen'])
            <img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}" loading="lazy" decoding="async" class="size-full object-contain transition-transform duration-300 group-hover:scale-[1.03] {{ $p['disponible'] === false ? 'opacity-50' : '' }}">
        @else
            <div class="grid size-full place-items-center bg-stone-50 text-stone-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-12'])</div>
        @endif

        @if ($p['disponible'] === false)
            <span class="absolute top-2 left-2 rounded-lg bg-neutral-900/85 px-2 py-1 text-[11px] font-semibold text-white">Agotado</span>
        @elseif ($p['destacado'] && ($conInsignia ?? false))
            <span class="absolute top-2 left-2 inline-flex items-center gap-1 rounded-lg bg-amber-400 px-2 py-1 text-[11px] font-semibold text-amber-950">
                @include('tienda.icono', ['n' => 'estrella', 'clase' => 'size-3', 'relleno' => true])Destacado
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col border-t border-stone-100 p-3 sm:p-4">
        @if ($p['marca'])
            <p class="truncate text-[11px] font-semibold tracking-wider text-neutral-400 uppercase">{{ $p['marca'] }}</p>
        @endif
        <h3 class="line-clamp-2 text-sm leading-snug font-medium group-hover:text-(--marca)">{{ $p['nombre'] }}</h3>

        <div class="mt-auto pt-2.5">
            @if ($p['precio'] !== null)
                <p class="text-lg leading-none font-bold tracking-tight">
                    S/ {{ number_format($p['precio'], 2) }}
                    @if ($p['presentacion'])
                        <span class="text-xs font-normal text-neutral-500">/ {{ $p['presentacion'] }}</span>
                    @endif
                </p>
            @else
                <p class="text-sm font-semibold text-(--marca)">Consultar precio</p>
            @endif
        </div>
    </div>
</a>
