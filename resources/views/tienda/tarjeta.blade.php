{{-- Tarjeta de un producto en una cuadrícula. $p viene de CatalogoTiendaService::tarjeta() --}}
<a href="{{ $p['url'] }}" class="group relative flex h-full flex-col overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-slate-900/10 hover:ring-slate-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--marca)">
    {{-- las fotos traen fondo blanco: con "multiply" se funden con el gris y el producto queda flotando --}}
    <div class="relative aspect-[4/3] overflow-hidden bg-slate-50">
        @if ($p['imagen'])
            <img src="{{ $p['imagen'] }}" alt="{{ $p['nombre'] }}" loading="lazy" decoding="async" width="800" height="600"
                class="size-full object-contain p-3 mix-blend-multiply transition-transform duration-300 group-hover:scale-105 {{ $p['disponible'] === false ? 'opacity-40 grayscale' : '' }}">
        @else
            <div class="grid size-full place-items-center text-slate-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-12', 'grosor' => 1.25])</div>
        @endif

        @if ($p['disponible'] === false)
            <span class="absolute top-3 left-3 rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold tracking-wide text-white uppercase">Agotado</span>
        @elseif ($p['destacado'] && ($conInsignia ?? false))
            <span class="absolute top-3 left-3 inline-flex items-center gap-1 rounded-md bg-amber-400 px-2 py-1 text-[11px] font-semibold tracking-wide text-amber-950 uppercase">
                @include('tienda.icono', ['n' => 'estrella', 'clase' => 'size-3', 'relleno' => true])Destacado
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        @if ($p['marca'] || $p['categoria'])
            <p class="truncate text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ $p['marca'] ?: $p['categoria'] }}</p>
        @endif
        <h3 class="mt-1 line-clamp-2 text-sm leading-snug font-medium text-slate-900">{{ $p['nombre'] }}</h3>

        <div class="mt-auto flex items-end justify-between gap-2 pt-4">
            @if ($p['precio'] !== null)
                <p class="leading-none">
                    <span class="text-xs font-medium text-slate-500">S/</span>
                    <span class="text-xl font-semibold tracking-tight">{{ number_format($p['precio'], 2) }}</span>
                    @if ($p['presentacion'])
                        <span class="mt-1 block text-xs text-slate-500">por {{ mb_strtolower($p['presentacion']) }}</span>
                    @endif
                </p>
            @else
                <p class="text-[13px] font-medium whitespace-nowrap text-slate-500 sm:text-sm">Consultar precio</p>
            @endif
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-600 transition-colors group-hover:bg-(--marca) group-hover:text-white" aria-hidden="true">
                @include('tienda.icono', ['n' => 'flecha'])
            </span>
        </div>
    </div>
</a>
