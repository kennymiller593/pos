{{-- Una categoría en círculo (plantilla Mercado, "Compra por categoría"). $copia: la segunda vuelta del carrusel, fuera del foco del teclado --}}
<a href="{{ $cat->url }}" @if ($copia) tabindex="-1" @endif class="group w-24 shrink-0 snap-start text-center sm:w-28">
    <span class="mx-auto grid size-20 place-items-center overflow-hidden rounded-full bg-(--marca-suave) text-2xl font-extrabold text-(--marca-texto) ring-4 ring-white shadow-sm transition duration-300 group-hover:scale-105 group-hover:ring-(--marca) sm:size-24">
        @if ($cat->imagen)
            <img src="{{ $cat->imagen }}" alt="" loading="lazy" width="160" height="160" class="size-full object-contain p-3 mix-blend-multiply">
        @else
            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($cat->nombre, 0, 1)) }}
        @endif
    </span>
    <span class="mt-2.5 line-clamp-2 text-[13px] leading-tight font-bold group-hover:text-(--marca-texto)">{{ $cat->nombre }}</span>
    <span class="block text-[11px] text-slate-500">{{ $cat->productos }} producto{{ $cat->productos === 1 ? '' : 's' }}</span>
</a>
