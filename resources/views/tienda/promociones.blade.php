{{--
    Promociones del dueño (en la configuración: "banners") en una ventana que se levanta al entrar a la tienda.
    La abre tienda.js una sola vez al día por visitante (lo recuerda el navegador). La comparten las tres plantillas.
    En la vista previa del dueño se abre siempre en la portada, para que vea cómo quedó.
--}}
@if (! empty($banners))
    @php $variasPromos = count($banners) > 1; @endphp
    <div id="promociones" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 [&:not(.hidden)]:flex" role="dialog" aria-modal="true" aria-label="Promociones de {{ $tienda['nombre'] }}"
        @if ($previa && ($portada ?? false)) data-promociones-siempre @elseif ($previa) data-promociones-nunca @endif>
        <div class="absolute inset-0 bg-slate-950/65" data-promociones-cerrar></div>
        <div class="relative flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <button type="button" data-promociones-cerrar data-promociones-cerrar-boton aria-label="Cerrar promociones"
                class="absolute top-2.5 right-2.5 z-10 grid size-10 cursor-pointer place-items-center rounded-full bg-white text-slate-700 shadow-md ring-1 ring-slate-200 transition-colors hover:bg-slate-100 hover:text-slate-950">
                @include('tienda.icono', ['n' => 'cerrar', 'clase' => 'size-5'])
            </button>

            <div class="flex snap-x snap-mandatory overflow-x-auto overflow-y-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-promociones-pista>
                @foreach ($banners as $i => $promo)
                    <div class="flex w-full shrink-0 snap-start flex-col" data-promocion>
                        @if ($promo['enlace'])
                            <a href="{{ $promo['enlace'] }}" @if ($promo['externo']) target="_blank" rel="noopener" @endif class="block bg-slate-100" aria-label="{{ $promo['titulo'] ?: 'Ver promoción' }}">
                                <img src="{{ $promo['imagen'] }}" alt="{{ $promo['titulo'] ?? '' }}" loading="lazy" class="mx-auto block max-h-[62dvh] w-full object-contain">
                            </a>
                        @else
                            <div class="bg-slate-100">
                                <img src="{{ $promo['imagen'] }}" alt="{{ $promo['titulo'] ?? '' }}" loading="lazy" class="mx-auto block max-h-[62dvh] w-full object-contain">
                            </div>
                        @endif
                        @if ($promo['titulo'] || $promo['enlace'])
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 px-5 py-4">
                                @if ($promo['titulo'])
                                    <p class="min-w-0 flex-1 text-base leading-snug font-bold text-slate-900 sm:text-lg">{{ $promo['titulo'] }}</p>
                                @endif
                                @if ($promo['enlace'])
                                    <a href="{{ $promo['enlace'] }}" @if ($promo['externo']) target="_blank" rel="noopener" @endif
                                        class="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl bg-(--marca) px-5 text-sm font-bold text-(--sobre-marca) transition hover:brightness-110 {{ $promo['titulo'] ? '' : 'ml-auto' }}">
                                        Ver promoción @include('tienda.icono', ['n' => 'flecha'])
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($variasPromos)
                {{-- varias promociones: flechas para pasar y puntitos que dicen en cuál va --}}
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-3 py-2">
                    <button type="button" data-promociones-anterior aria-label="Promoción anterior" class="grid size-10 cursor-pointer place-items-center rounded-full text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-950">
                        @include('tienda.icono', ['n' => 'izquierda', 'clase' => 'size-5'])
                    </button>
                    <div class="flex items-center gap-1.5" aria-hidden="true">
                        @foreach ($banners as $i => $promo)
                            <span data-promociones-punto @if ($i === 0) data-activo @endif class="size-2 rounded-full bg-slate-300 transition data-[activo]:w-5 data-[activo]:bg-(--marca)"></span>
                        @endforeach
                    </div>
                    <button type="button" data-promociones-siguiente aria-label="Promoción siguiente" class="grid size-10 cursor-pointer place-items-center rounded-full text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-950">
                        @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-5'])
                    </button>
                </div>
            @endif
        </div>
    </div>
@endif
