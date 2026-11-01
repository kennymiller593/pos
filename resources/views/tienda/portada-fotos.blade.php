{{--
    Fotos de portada (la principal y hasta tres más). Con varias, se van pasando solas y hay puntitos para elegir (tienda.js).
    $modo 'fondo': detrás del texto, con fundido entre una y otra. $modo 'slider': enteras, una tras otra, deslizables con el dedo.
    $claseFondo: clases extra del fondo (la plantilla clásica lo manda al fondo con -z-10).
--}}
@php
    $fotos = $tienda['portada']['fotos'];
    $varias = count($fotos) > 1;
    $claseFondo = $claseFondo ?? '';
@endphp
@if ($modo === 'fondo')
    <div class="pointer-events-none absolute inset-0 {{ $claseFondo }}" @if ($varias) data-portada-fondo @endif aria-hidden="true">
        @foreach ($fotos as $i => $foto)
            <picture>
                @if ($foto['imagen_movil'])
                    <source media="(max-width: 639px)" srcset="{{ $foto['imagen_movil'] }}">
                @endif
                <img src="{{ $foto['imagen'] }}" alt="" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif
                    class="absolute inset-0 size-full object-cover transition-opacity duration-700 motion-reduce:transition-none {{ $i === 0 ? 'opacity-100' : 'opacity-0' }}" data-portada-slide>
            </picture>
        @endforeach
    </div>
@else
    <div class="relative" @if ($varias) data-portada-slider @endif>
        <div class="flex snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-portada-pista>
            @foreach ($fotos as $i => $foto)
                <a href="/catalogo" class="block w-full shrink-0 snap-start" aria-label="{{ $tienda['portada']['boton'] }}">
                    <picture>
                        @if ($foto['imagen_movil'])
                            <source media="(max-width: 639px)" srcset="{{ $foto['imagen_movil'] }}">
                        @endif
                        {{-- las demás se cargan de una vez: si llegaran recién al pasar, la pista cambiaría de alto a mitad de la visita --}}
                        <img src="{{ $foto['imagen'] }}" alt="{{ $i === 0 ? $tienda['portada']['titulo'] : '' }}" @if ($i === 0) fetchpriority="high" @endif class="block h-auto w-full">
                    </picture>
                </a>
            @endforeach
        </div>
    </div>
@endif
@if ($varias)
    <div class="absolute {{ $modo === 'fondo' ? 'right-4 bottom-3' : 'inset-x-0 bottom-3 justify-center' }} z-10 flex gap-1.5" data-portada-puntos>
        @foreach ($fotos as $i => $foto)
            <button type="button" data-portada-punto @if ($i === 0) data-activo @endif aria-label="Foto {{ $i + 1 }}"
                class="size-2.5 cursor-pointer rounded-full bg-white/60 ring-1 ring-black/15 transition data-[activo]:scale-125 data-[activo]:bg-white"></button>
        @endforeach
    </div>
@endif
