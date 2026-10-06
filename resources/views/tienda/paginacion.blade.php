{{-- Paginación del catálogo: anterior, números y siguiente --}}
@if ($paginator->hasPages())
    @php
        $base = 'inline-flex h-10 min-w-10 items-center justify-center gap-1 rounded-xl border px-3 text-sm font-medium transition-colors';
        $normal = 'border-stone-200 bg-white text-neutral-700 hover:border-(--marca) hover:text-(--marca)';
        $apagado = 'border-stone-200 bg-white text-neutral-300';
    @endphp
    <nav class="mt-8 flex flex-wrap items-center justify-center gap-1.5" aria-label="Páginas">
        @if ($paginator->onFirstPage())
            <span class="{{ $base }} {{ $apagado }}" aria-disabled="true">@include('tienda.icono', ['n' => 'izquierda'])<span class="hidden sm:inline">Anterior</span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $base }} {{ $normal }}">@include('tienda.icono', ['n' => 'izquierda'])<span class="hidden sm:inline">Anterior</span></a>
        @endif

        @foreach ($elements as $elemento)
            @if (is_string($elemento))
                <span class="px-1 text-neutral-400" aria-hidden="true">…</span>
            @endif
            @if (is_array($elemento))
                @foreach ($elemento as $numero => $url)
                    @if ($numero === $paginator->currentPage())
                        <span class="{{ $base }} border-(--marca) bg-(--marca) text-white" aria-current="page">{{ $numero }}</span>
                    @else
                        <a href="{{ $url }}" class="{{ $base }} {{ $normal }}" aria-label="Página {{ $numero }}">{{ $numero }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $base }} {{ $normal }}"><span class="hidden sm:inline">Siguiente</span>@include('tienda.icono', ['n' => 'derecha'])</a>
        @else
            <span class="{{ $base }} {{ $apagado }}" aria-disabled="true"><span class="hidden sm:inline">Siguiente</span>@include('tienda.icono', ['n' => 'derecha'])</span>
        @endif
    </nav>
@endif
