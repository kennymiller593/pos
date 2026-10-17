{{-- Plantilla Mercado: filtros del catálogo (categoría, marca y precio tope). Se usa dos veces: plegado en el celular y fijo en pantalla grande --}}
                        @if ($categorias->isNotEmpty())
                            <div>
                                <h2 class="text-sm font-bold">Categoría</h2>
                                <ul class="mt-2.5 space-y-2 text-sm">
                                    <li>
                                        <a href="{{ $enlace('/catalogo', ['marca' => null]) }}" class="flex items-center gap-2.5 text-slate-700 hover:text-(--marca-texto)">
                                            <span class="grid size-4 place-items-center rounded border {{ ! $categoria ? 'border-(--marca) bg-(--marca) text-(--sobre-marca)' : 'border-slate-300 bg-white' }}" aria-hidden="true">@if (! $categoria)@include('tienda.icono', ['n' => 'check', 'clase' => 'size-3', 'grosor' => 3])@endif</span>
                                            <span class="{{ ! $categoria ? 'font-semibold' : '' }}">Todas</span>
                                        </a>
                                    </li>
                                    @foreach ($categorias as $cat)
                                        @php $activa = $categoria?->id === $cat->id; @endphp
                                        <li>
                                            <a href="{{ $activa ? $enlace('/catalogo', ['marca' => null]) : $enlace($cat->url, ['marca' => null]) }}" @if ($activa) aria-current="true" @endif class="flex items-center gap-2.5 text-slate-700 hover:text-(--marca-texto)">
                                                <span class="grid size-4 shrink-0 place-items-center rounded border {{ $activa ? 'border-(--marca) bg-(--marca) text-(--sobre-marca)' : 'border-slate-300 bg-white' }}" aria-hidden="true">@if ($activa)@include('tienda.icono', ['n' => 'check', 'clase' => 'size-3', 'grosor' => 3])@endif</span>
                                                <span class="min-w-0 truncate {{ $activa ? 'font-semibold' : '' }}">{{ $cat->nombre }}</span>
                                                <span class="ml-auto text-xs text-slate-400 tabular-nums">{{ $cat->productos }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($marcas->isNotEmpty())
                            <div data-filtro-marcas>
                                <h2 class="text-sm font-bold">Marca</h2>
                                <ul class="mt-2.5 max-h-64 space-y-2 overflow-y-auto text-sm">
                                    @foreach ($marcas as $m)
                                        @php $activa = $marca === $m['nombre']; @endphp
                                        <li>
                                            <a href="{{ $enlace(null, ['marca' => $activa ? null : $m['nombre']]) }}" @if ($activa) aria-current="true" @endif class="flex items-center gap-2.5 text-slate-700 hover:text-(--marca-texto)">
                                                <span class="grid size-4 shrink-0 place-items-center rounded border {{ $activa ? 'border-(--marca) bg-(--marca) text-(--sobre-marca)' : 'border-slate-300 bg-white' }}" aria-hidden="true">@if ($activa)@include('tienda.icono', ['n' => 'check', 'clase' => 'size-3', 'grosor' => 3])@endif</span>
                                                <span class="min-w-0 truncate {{ $activa ? 'font-semibold' : '' }}">{{ $m['nombre'] }}</span>
                                                <span class="ml-auto text-xs text-slate-400 tabular-nums">{{ $m['productos'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($precioMaximo > 0)
                            <form action="{{ $base }}" method="get" data-filtro-precio>
                                @foreach ($vigentes as $clave => $valor)
                                    @if ($clave !== 'hasta') <input type="hidden" name="{{ $clave }}" value="{{ $valor }}"> @endif
                                @endforeach
                                <h2 class="text-sm font-bold">Precio</h2>
                                <label for="hasta{{ $sufijo ?? '' }}" class="mt-2.5 flex items-center justify-between text-xs text-slate-500">
                                    <span>Hasta</span><output data-filtro-valor class="font-semibold text-slate-900">S/ {{ number_format($filtroPrecio, 0, '.', ',') }}</output>
                                </label>
                                <input id="hasta{{ $sufijo ?? '' }}" name="hasta" type="range" min="1" max="{{ (int) $precioMaximo }}" step="1" value="{{ (int) $filtroPrecio }}" data-filtro-auto class="mt-1.5 w-full accent-(--marca)">
                                <div class="mt-1 flex justify-between text-[11px] text-slate-400"><span>S/ 1</span><span>S/ {{ number_format($precioMaximo, 0, '.', ',') }}</span></div>
                                <noscript><button type="submit" class="mt-2 h-9 rounded-lg bg-(--mercado-fondo) px-3 text-xs font-bold text-(--sobre-fondo)">Aplicar</button></noscript>
                                @if ($hasta !== null)
                                    <a href="{{ $enlace(null, ['hasta' => null]) }}" class="mt-2 inline-block text-xs font-semibold text-(--marca-texto) hover:underline">Quitar el tope de precio</a>
                                @endif
                            </form>
                        @endif
                    
