{{--
    Texto de la portada: etiqueta, título, presentación, botones y cifras de la tienda.
    $sobreFoto: va encima de una foto oscurecida (texto claro). $centrado: alineado al centro.
--}}
@php
    $sobreFoto = $sobreFoto ?? false;
    $centrado = $centrado ?? false;
    $tenue = $sobreFoto ? 'text-slate-300' : 'text-slate-500';
@endphp
<div class="{{ $centrado ? 'mx-auto max-w-3xl text-center' : '' }}">
    <p class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold {{ $sobreFoto ? 'bg-white/15 text-white ring-1 ring-white/25 backdrop-blur' : 'bg-white text-(--marca) ring-1 ring-(--marca)/20' }}">
        <span class="size-1.5 rounded-full {{ $sobreFoto ? 'bg-white' : 'bg-(--marca)' }}"></span>Catálogo en línea
    </p>
    <h1 class="mt-4 text-4xl leading-[1.08] font-semibold tracking-tight text-balance sm:text-5xl {{ $sobreFoto ? 'text-white' : '' }}">{{ $tienda['portada']['titulo'] }}</h1>
    <p class="mt-4 text-lg leading-relaxed {{ $sobreFoto ? 'text-slate-200' : 'text-slate-600' }} {{ $centrado ? 'mx-auto max-w-2xl' : 'max-w-xl' }}">
        {{ $tienda['descripcion'] ?: 'Mira nuestro catálogo, busca lo que necesitas y haz tu pedido en un momento.' }}
    </p>

    <div class="mt-7 flex flex-wrap gap-3 {{ $centrado ? 'justify-center' : '' }}">
        <a href="#catalogo" class="inline-flex h-12 items-center gap-2 rounded-xl bg-(--marca) px-6 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-(--marca-oscuro)">
            {{ $tienda['portada']['boton'] }} @include('tienda.icono', ['n' => 'flecha'])
        </a>
        @if ($whatsapp)
            <a href="{{ $whatsapp }}" target="_blank" rel="noopener"
                class="inline-flex h-12 items-center gap-2 rounded-xl px-6 text-sm font-semibold transition-colors {{ $sobreFoto ? 'bg-white/10 text-white ring-1 ring-white/40 backdrop-blur hover:bg-white/20' : 'bg-white text-slate-900 ring-1 ring-slate-200 hover:ring-slate-300' }}">
                @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4.5 '.($sobreFoto ? '' : 'text-(--marca)')])Pedir por WhatsApp
            </a>
        @endif
    </div>

    <dl class="mt-9 flex flex-wrap gap-x-10 gap-y-4 {{ $centrado ? 'justify-center' : '' }} {{ $sobreFoto ? 'text-white' : '' }}">
        <div>
            <dt class="text-sm {{ $tenue }}">Productos</dt>
            <dd class="text-2xl font-semibold tracking-tight">{{ number_format($tienda['productos']) }}</dd>
        </div>
        @if ($categorias->count() > 1)
            <div>
                <dt class="text-sm {{ $tenue }}">Categorías</dt>
                <dd class="text-2xl font-semibold tracking-tight">{{ $categorias->count() }}</dd>
            </div>
        @endif
        @if ($contactos['horario'])
            <div class="min-w-0">
                <dt class="text-sm {{ $tenue }}">Atención</dt>
                <dd class="text-base leading-8 font-semibold tracking-tight">{{ $contactos['horario'] }}</dd>
            </div>
        @endif
    </dl>
</div>
