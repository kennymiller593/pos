@extends('tienda.layout')

{{-- Página de texto de la tienda (Sobre nosotros, políticas, preguntas frecuentes): la escribe el dueño en "Contenido" --}}
@php
    // el texto es plano: cada línea en blanco separa un párrafo
    $parrafos = fn (string $texto) => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', trim($texto)))));
    $primerTexto = $pagina['secciones'][0][1] ?? ($pagina['preguntas'][0]['respuesta'] ?? '');

    $datos = $pagina['preguntas'] ? [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($p) => [
            '@type' => 'Question',
            'name' => $p['pregunta'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p['respuesta']],
        ], $pagina['preguntas']),
    ] : null;
@endphp

@section('titulo', "{$pagina['titulo']} · {$tienda['nombre']}")
@section('resumen', \Illuminate\Support\Str::limit($primerTexto, 155))
@section('canonica', rtrim($tienda['url'], '/').$pagina['url'])

@if ($datos)
    @push('cabecera')
        <script type="application/ld+json">{!! json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endpush
@endif

@section('contenido')
    <div class="mx-auto max-w-3xl px-4 pt-8 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
            <a href="/" class="hover:text-(--marca)">Inicio</a>
            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
            <span class="text-slate-800" aria-current="page">{{ $pagina['titulo'] }}</span>
        </nav>

        <h1 class="mt-5 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ $pagina['titulo'] }}</h1>

        @foreach ($pagina['secciones'] as [$subtitulo, $texto])
            <section class="{{ $subtitulo ? 'mt-10' : 'mt-6' }}">
                @if ($subtitulo)
                    <h2 class="text-xl font-semibold tracking-tight">{{ $subtitulo }}</h2>
                @endif
                <div class="mt-3 space-y-4 text-[17px] leading-relaxed text-slate-600">
                    @foreach ($parrafos($texto) as $parrafo)
                        <p>{!! nl2br(e($parrafo)) !!}</p>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($pagina['preguntas'])
            <div class="mt-8 divide-y divide-slate-200 rounded-2xl ring-1 ring-slate-200">
                @foreach ($pagina['preguntas'] as $i => $p)
                    <details class="group" @if ($i === 0) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-semibold transition-colors hover:text-(--marca) [&::-webkit-details-marker]:hidden">
                            {{ $p['pregunta'] }}
                            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-4 shrink-0 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                        </summary>
                        <div class="px-5 pb-5 leading-relaxed text-slate-600">{!! nl2br(e($p['respuesta'])) !!}</div>
                    </details>
                @endforeach
            </div>
        @endif

        @if ($whatsapp)
            <p class="mt-10 rounded-2xl bg-(--marca-suave) px-5 py-4 text-slate-700">
                ¿Tienes otra consulta?
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="font-semibold text-(--marca) hover:underline">Escríbenos por WhatsApp</a>
            </p>
        @endif
    </div>
@endsection
