{{-- Tarjeta de un producto en una cuadrícula. $p viene de CatalogoTiendaService::tarjeta() --}}
@php
    // variante de un modelo (tallas y colores): la tarjeta es del modelo entero; null en un producto suelto
    $m = $p['modelo'] ?? null;
    $titulo = $m ? $m['nombre'] : $p['nombre'];
    $disponible = $m ? $m['disponible'] : $p['disponible'];
    $precio = $m ? $m['precio_desde'] : $p['precio'];
    $foto = $p['imagen'] ?: ($m['imagen'] ?? null);
    // con varias tallas o colores no se añade a ciegas: el botón lleva a la página a elegir
    $elegir = $m && $m['cuantas'] > 1;
@endphp
<article class="group relative flex h-full flex-col overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-slate-900/10 hover:ring-slate-300"@if ($m) data-tarjeta-modelo="{{ $m['cuantas'] }}"@endif>
    <a href="{{ $p['url'] }}" class="flex flex-1 flex-col focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--marca)">
        {{-- las fotos traen fondo blanco: con "multiply" se funden con el gris y el producto queda flotando --}}
        <div class="relative aspect-[4/3] overflow-hidden bg-slate-50">
            @if ($foto)
                <img src="{{ $foto }}" alt="{{ $titulo }}"@if ($prioritaria ?? false) fetchpriority="high"@else loading="lazy"@endif decoding="async" width="800" height="600" @if (($p['miniatura'] ?? null) && $p['imagen']) srcset="{{ $p['miniatura'] }} 400w, {{ $p['imagen'] }} 800w" sizes="(min-width: 1280px) 16vw, (min-width: 768px) 30vw, 48vw"@endif
                    class="size-full object-contain p-3 mix-blend-multiply transition-transform duration-300 group-hover:scale-105 {{ $disponible === false ? 'opacity-40 grayscale' : '' }}">
            @else
                <div class="grid size-full place-items-center text-slate-300">@include('tienda.icono', ['n' => 'paquete', 'clase' => 'size-12', 'grosor' => 1.25])</div>
            @endif

            @if ($disponible === false)
                <span class="absolute top-3 left-3 rounded-md bg-(--secundario) px-2 py-1 text-[11px] font-semibold tracking-wide text-white uppercase">Agotado</span>
            @elseif ($p['destacado'] && ($conInsignia ?? false))
                <span class="absolute top-3 left-3 inline-flex items-center gap-1 rounded-md bg-amber-400 px-2 py-1 text-[11px] font-semibold tracking-wide text-amber-950 uppercase">
                    @include('tienda.icono', ['n' => 'estrella', 'clase' => 'size-3', 'relleno' => true])Destacado
                </span>
            @endif
        </div>

        <div class="flex flex-1 flex-col p-3 sm:p-4">
            @if ($p['marca'] || $p['categoria'])
                <p class="truncate text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ $p['marca'] ?: $p['categoria'] }}</p>
            @endif
            <h3 class="mt-1 line-clamp-2 text-sm leading-snug font-medium text-slate-900 group-hover:text-(--marca-texto)">{{ $titulo }}</h3>
            @if ($m && $m['variacion'])
                <p class="mt-1 truncate text-xs text-slate-500" data-modelo-variacion>{{ $m['variacion'] }}</p>
            @endif

            <div class="mt-auto pt-3">
                @if ($precio !== null)
                    <p class="leading-none"@if ($m) data-modelo-precio @endif>
                        <span class="text-xs font-medium text-slate-500">@if ($m && $m['varios_precios'])Desde @endif{{ 'S/' }}</span>
                        <span class="text-xl font-semibold tracking-tight">{{ number_format($precio, 2) }}</span>
                        @if ($p['presentacion'])
                            <span class="mt-1 block text-xs text-slate-500">por {{ mb_strtolower($p['presentacion']) }}</span>
                        @endif
                    </p>
                @else
                    <p class="text-[13px] font-medium whitespace-nowrap text-slate-500 sm:text-sm">Consultar precio</p>
                @endif
            </div>
        </div>
    </a>

    {{-- Pedir este producto por WhatsApp, o sumarlo al pedido (el pedido completo también sale por WhatsApp) --}}
    @if ($p['pedido'] && $elegir)
        <div class="px-3 pb-3 sm:px-4 sm:pb-4">
            {{-- en celular, 44 px de alto: es el único botón de la tarjeta --}}
            <a href="{{ $p['url'] }}" data-elegir-modelo
                class="inline-flex h-11 w-full items-center justify-center gap-1.5 rounded-xl bg-(--boton-carrito) px-2 text-[13px] font-semibold text-(--sobre-boton-carrito) transition-colors hover:bg-(--marca-oscuro) sm:h-10 sm:text-sm"
                aria-label="Ver las opciones de {{ $titulo }}">
                Ver opciones
            </a>
        </div>
    @elseif ($p['pedido'])
        <div class="flex gap-2 px-3 pb-3 sm:px-4 sm:pb-4" data-zona-pedido>
            <a href="{{ $p['pedido'] }}" target="_blank" rel="noopener"
                class="inline-flex h-10 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-xl px-2 text-[13px] font-semibold ring-1 ring-slate-200 transition-colors hover:bg-slate-50 hover:ring-slate-300 sm:text-sm"
                aria-label="{{ $p['disponible'] === false ? 'Consultar' : 'Pedir' }} {{ $p['nombre'] }} por WhatsApp">
                @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4 text-[#1fa855]']){{ $p['disponible'] === false ? 'Consultar' : 'Pedir' }}
            </a>
            @if ($p['disponible'] !== false)
                <button type="button" data-anadir="{{ json_encode($p['para_pedido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-necesita-js
                    class="inline-flex h-10 min-w-0 flex-1 cursor-pointer items-center justify-center gap-1 rounded-xl bg-(--boton-carrito) px-2 text-[13px] font-semibold text-(--sobre-boton-carrito) transition-colors hover:bg-(--marca-oscuro) sm:text-sm"
                    aria-label="Añadir {{ $p['nombre'] }} al pedido">
                    @include('tienda.icono', ['n' => 'mas', 'clase' => 'hidden size-4 sm:block'])<span data-anadir-texto>Añadir</span>
                </button>
            @endif
        </div>
    @endif
</article>
