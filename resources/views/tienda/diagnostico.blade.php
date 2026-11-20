@extends($layoutTienda ?? 'tienda.layout')

{{--
    Diagnóstico de cultivos por foto con IA (/diagnostico). El cliente sube o toma una foto y la misma página vuelve con
    el diagnóstico y los productos de la tienda que lo tratan. Funciona sin JavaScript (formulario normal); con él se
    ve la foto elegida y la respuesta llega sin recargar (tienda.js, bloque "diagnóstico").
--}}
@php
    $d = $diagnostico ?? null;
    $recomendados = $recomendados ?? collect();
    // cada plantilla tiene su tarjeta de producto: tienda.layout -> tienda.tarjeta, tienda.mercado.layout -> tienda.mercado.tarjeta
    $vistaTarjeta = str_replace('layout', 'tarjeta', $layoutTienda ?? 'tienda.layout');
    $severidad = [
        'ninguna' => ['Sin daño', 'bg-emerald-50 text-emerald-800 ring-emerald-200'],
        'leve' => ['Daño leve', 'bg-lime-50 text-lime-800 ring-lime-200'],
        'moderada' => ['Daño moderado', 'bg-amber-50 text-amber-800 ring-amber-200'],
        'severa' => ['Daño severo', 'bg-red-50 text-red-700 ring-red-200'],
    ];
    $confianza = ['alta' => 'Confianza alta', 'media' => 'Confianza media', 'baja' => 'Confianza baja: prueba con una foto más cercana'];
    $tipos = ['plaga' => 'Plaga', 'enfermedad' => 'Enfermedad', 'deficiencia' => 'Deficiencia nutricional', 'estres' => 'Estrés', 'maleza' => 'Maleza', 'sano' => 'Planta sana', 'indeterminado' => 'Sin determinar'];
@endphp

@section('titulo', "Diagnóstico de cultivos con IA · {$tienda['nombre']}")
@section('resumen', "Sube una foto de tu cultivo y la inteligencia artificial de {$tienda['nombre']} te dice qué plaga, enfermedad o deficiencia tiene y qué productos lo controlan.")
@section('canonica', rtrim($tienda['url'], '/').'/diagnostico')
{{-- el resultado de un análisis es de ese cliente: no se indexa --}}
@if ($d || isset($error))
    @section('robots', 'noindex,follow')
@endif

@section('contenido')
    <div class="mx-auto max-w-5xl px-4 pt-6 pb-4 sm:px-6 sm:pt-8 lg:px-8">
        <nav class="flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
            <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
            <span class="text-slate-800" aria-current="page">Diagnóstico con IA</span>
        </nav>

        <header class="mt-5 max-w-2xl">
            <p class="inline-flex items-center gap-1.5 rounded-full bg-(--marca-suave) px-3 py-1 text-xs font-semibold text-(--marca-texto)">
                @include('tienda.icono', ['n' => 'chispa', 'clase' => 'size-3.5'])Inteligencia artificial
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-balance text-slate-900 sm:text-4xl">Diagnóstico de cultivos por foto</h1>
            <p class="mt-3 text-[17px] leading-relaxed text-pretty text-slate-600">
                Toma una foto de la hoja, el fruto o la planta afectada. Te decimos qué puede tener y qué productos de nuestra tienda lo controlan.
            </p>
        </header>

        <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,26rem)_1fr] lg:items-start">
            {{-- el formulario --}}
            <form method="post" action="/diagnostico" enctype="multipart/form-data" class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5 lg:sticky lg:top-24" data-diagnostico-form>
                <input type="hidden" name="visitante" value="" data-diagnostico-visitante>

                <label for="diagnostico-foto" class="group relative grid aspect-[4/3] cursor-pointer place-items-center overflow-hidden rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 text-center transition-colors hover:border-(--marca) hover:bg-(--marca-suave) has-[:focus-visible]:border-(--marca)" data-diagnostico-zona>
                    <img src="" alt="Foto elegida" class="absolute inset-0 hidden size-full object-cover" data-diagnostico-vista>
                    <span class="flex flex-col items-center px-6" data-diagnostico-indicacion>
                        <span class="grid size-14 place-items-center rounded-2xl bg-white text-(--marca-texto) shadow-sm ring-1 ring-slate-200 transition-transform group-hover:scale-105">
                            @include('tienda.icono', ['n' => 'camara', 'clase' => 'size-7'])
                        </span>
                        <span class="mt-3 text-base font-semibold text-slate-900">Toma o elige una foto</span>
                        <span class="mt-1 text-sm text-slate-500">JPG, PNG o WEBP, hasta 10 MB</span>
                    </span>
                    <input id="diagnostico-foto" type="file" name="foto" accept="image/jpeg,image/png,image/webp" required class="sr-only" data-diagnostico-foto>
                </label>
                <button type="button" class="mt-2 hidden h-9 w-full items-center justify-center gap-1.5 rounded-xl text-sm font-semibold text-(--marca-texto) hover:bg-(--marca-suave)" data-diagnostico-cambiar>
                    @include('tienda.icono', ['n' => 'imagen', 'clase' => 'size-4'])Cambiar foto
                </button>

                <label for="diagnostico-nota" class="mt-4 block text-sm font-medium text-slate-800">¿Algo más que debamos saber? <span class="font-normal text-slate-400">(opcional)</span></label>
                <textarea id="diagnostico-nota" name="nota" rows="2" maxlength="300" placeholder="Ej.: papa de 2 meses, las manchas aparecieron después de la lluvia" class="mt-1.5 block w-full resize-none rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-(--marca) focus:outline-none">{{ $nota ?? '' }}</textarea>

                @isset($error)
                    <p class="mt-3 flex items-start gap-2 rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700 ring-1 ring-red-200" role="alert" data-diagnostico-error>{{ $error }}</p>
                @endisset
                <p class="mt-3 hidden rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700 ring-1 ring-red-200" role="alert" data-diagnostico-aviso></p>

                <button type="submit" class="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-(--marca) px-5 text-[15px] font-semibold text-(--sobre-marca) shadow-sm transition hover:brightness-110 disabled:cursor-wait disabled:opacity-70" data-diagnostico-enviar>
                    <span class="inline-flex items-center gap-2" data-diagnostico-texto>@include('tienda.icono', ['n' => 'chispa', 'clase' => 'size-5'])Analizar mi cultivo</span>
                    <span class="hidden items-center gap-2" data-diagnostico-cargando>
                        <svg class="size-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        Analizando la foto…
                    </span>
                </button>
                <p class="mt-3 text-center text-xs leading-relaxed text-slate-400">Toma la foto de cerca, con buena luz y enfocando la parte dañada.</p>
            </form>

            {{-- el resultado (o, antes de analizar, cómo funciona) --}}
            <div data-diagnostico-resultado>
                @if ($d && ! $d['es_cultivo'])
                    <section class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6" aria-live="polite">
                        <span class="grid size-12 place-items-center rounded-2xl bg-amber-50 text-amber-600 ring-1 ring-amber-200">@include('tienda.icono', ['n' => 'imagen', 'clase' => 'size-6'])</span>
                        <h2 class="mt-4 text-xl font-semibold tracking-tight text-slate-900">No vemos un cultivo en esta foto</h2>
                        <p class="mt-2 text-[15px] leading-relaxed text-slate-600">{{ $d['resumen'] ?: 'Toma una foto de cerca de la hoja, el fruto o la planta afectada e inténtalo de nuevo.' }}</p>
                    </section>
                @elseif ($d)
                    @php [$etiquetaSeveridad, $claseSeveridad] = $severidad[$d['severidad']] ?? $severidad['leve']; @endphp
                    <section class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6" aria-live="polite" data-diagnostico-listo>
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                            <span class="rounded-full bg-(--marca-suave) px-2.5 py-1 text-(--marca-texto)">{{ $tipos[$d['tipo']] ?? 'Diagnóstico' }}</span>
                            @if ($d['tipo'] !== 'sano')
                                <span class="rounded-full px-2.5 py-1 ring-1 {{ $claseSeveridad }}">{{ $etiquetaSeveridad }}</span>
                            @endif
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">{{ $confianza[$d['confianza']] ?? '' }}</span>
                        </div>
                        @if ($d['cultivo'])
                            <p class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500">@include('tienda.icono', ['n' => 'hoja', 'clase' => 'size-4 text-(--marca-texto)']){{ \Illuminate\Support\Str::ucfirst($d['cultivo']) }}</p>
                        @endif
                        <h2 class="mt-1 text-2xl font-semibold tracking-tight text-balance text-slate-900">{{ \Illuminate\Support\Str::ucfirst($d['problema']) }}</h2>
                        @if ($d['resumen'])
                            <p class="mt-2 text-[15px] leading-relaxed text-slate-600">{{ $d['resumen'] }}</p>
                        @endif

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            @if ($d['sintomas'])
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900">Lo que se ve en la foto</h3>
                                    <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-slate-600">
                                        @foreach ($d['sintomas'] as $s)
                                            <li class="flex gap-2"><span class="mt-2 size-1.5 shrink-0 rounded-full bg-slate-400"></span>{{ $s }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @if ($d['recomendaciones'])
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900">Qué hacer</h3>
                                    <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-slate-600">
                                        @foreach ($d['recomendaciones'] as $r)
                                            <li class="flex gap-2">@include('tienda.icono', ['n' => 'check', 'clase' => 'mt-0.5 size-4 text-(--marca-texto)']){{ $r }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        @if ($whatsappDiagnostico ?? null)
                            <a href="{{ $whatsappDiagnostico }}" target="_blank" rel="noopener" class="mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] px-5 text-sm font-semibold text-white transition-colors hover:bg-[#1eb957] sm:w-auto" data-diagnostico-whatsapp>
                                @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Consultar con un especialista
                            </a>
                        @endif
                    </section>

                    @if ($recomendados->isNotEmpty())
                        <section class="mt-6" aria-labelledby="titulo-recomendados">
                            <h2 id="titulo-recomendados" class="text-lg font-semibold tracking-tight text-slate-900">Productos que te pueden ayudar</h2>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:gap-4">
                                @foreach ($recomendados as $p)
                                    @include($vistaTarjeta, ['p' => $p])
                                @endforeach
                            </div>
                        </section>
                    @elseif ($d['tipo'] !== 'sano')
                        <p class="mt-6 rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200">No encontramos en la tienda un producto específico para esto. Escríbenos y te recomendamos uno.</p>
                    @endif

                    <p class="mt-6 text-xs leading-relaxed text-slate-400">Diagnóstico orientativo hecho por inteligencia artificial a partir de una foto. Antes de aplicar un producto, lee su etiqueta y, si tienes dudas, consulta con un especialista.</p>
                @else
                    <section class="rounded-3xl bg-(--marca-suave)/60 p-5 ring-1 ring-slate-200 sm:p-6">
                        <h2 class="text-lg font-semibold tracking-tight text-slate-900">Cómo funciona</h2>
                        <ol class="mt-4 space-y-4">
                            @foreach ([['camara', 'Toma una foto', 'De cerca y con buena luz: la hoja, el fruto o la planta con el problema.'], ['chispa', 'La IA la analiza', 'Identifica el cultivo y si tiene una plaga, una enfermedad o le falta algún nutriente.'], ['carrito', 'Te recomendamos productos', 'Los de nuestra tienda que lo controlan, listos para pedir por WhatsApp.']] as $i => [$icono, $titulo, $texto])
                                <li class="flex gap-3.5">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-(--marca-texto) shadow-sm ring-1 ring-slate-200">@include('tienda.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                                    <div>
                                        <p class="font-semibold text-slate-900">{{ $i + 1 }}. {{ $titulo }}</p>
                                        <p class="mt-0.5 text-sm leading-relaxed text-slate-600">{{ $texto }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            </div>
        </div>
    </div>
@endsection
