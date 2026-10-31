@extends($layoutTienda ?? 'tienda.layout')

{{-- Página de texto de la tienda (Sobre nosotros, políticas, preguntas frecuentes): la escribe el dueño en "Contenido". /contacto sale de sus datos de contacto --}}
@php
    // el texto es plano: cada línea en blanco separa un párrafo
    $parrafos = fn (string $texto) => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', trim($texto)))));
    $esContacto = $pagina['contacto'] ?? false;
    // sin dirección escrita por el dueño, vale la de su primera sucursal
    $direccionPrincipal = $contactos['direccion'] ?: ($contactos['locales'][0]['direccion'] ?? null);
    $primerTexto = $esContacto
        ? "Cómo contactar a {$tienda['nombre']}.".($direccionPrincipal ? " Visítanos en {$direccionPrincipal}." : '')
        : ($pagina['secciones'][0][1] ?? ($pagina['preguntas'][0]['respuesta'] ?? ''));
    $lugarMapa = $contactos['coordenadas'] ? implode(',', $contactos['coordenadas']) : $direccionPrincipal;

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
            <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
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
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-semibold transition-colors hover:text-(--marca-texto) [&::-webkit-details-marker]:hidden">
                            {{ $p['pregunta'] }}
                            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-4 shrink-0 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                        </summary>
                        <div class="px-5 pb-5 leading-relaxed text-slate-600">{!! nl2br(e($p['respuesta'])) !!}</div>
                    </details>
                @endforeach
            </div>
        @endif

        @if ($esContacto)
            <div class="mt-8 grid gap-3 sm:grid-cols-2" data-contacto>
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 transition-colors hover:ring-(--marca)">
                        @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'mt-0.5 size-5 shrink-0 text-[#1fa855]'])
                        <span><span class="block text-xs text-slate-500">WhatsApp</span><span class="font-semibold">{{ $contactos['whatsapp_texto'] }}</span></span>
                    </a>
                @endif
                @if ($contactos['telefono'])
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactos['telefono']) }}" class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 transition-colors hover:ring-(--marca)">
                        @include('tienda.icono', ['n' => 'telefono', 'clase' => 'mt-0.5 size-5 shrink-0 text-slate-500'])
                        <span><span class="block text-xs text-slate-500">Teléfono</span><span class="font-semibold">{{ $contactos['telefono'] }}</span></span>
                    </a>
                @endif
                @if ($contactos['email'])
                    <a href="mailto:{{ $contactos['email'] }}" class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 transition-colors hover:ring-(--marca)">
                        @include('tienda.icono', ['n' => 'correo', 'clase' => 'mt-0.5 size-5 shrink-0 text-slate-500'])
                        <span class="min-w-0"><span class="block text-xs text-slate-500">Correo</span><span class="block truncate font-semibold">{{ $contactos['email'] }}</span></span>
                    </a>
                @endif
                @foreach ($contactos['direcciones'] as $lugar)
                    <a href="{{ $lugar['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 transition-colors hover:ring-(--marca)">
                        @include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-5 shrink-0 text-slate-500'])
                        <span><span class="block text-xs text-slate-500">Dirección · cómo llegar</span><span class="font-semibold">{{ $lugar['texto'] }}</span></span>
                    </a>
                @endforeach
                @foreach ($contactos['locales'] as $local)
                    <a href="{{ $local['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 transition-colors hover:ring-(--marca)">
                        @include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-5 shrink-0 text-slate-500'])
                        <span><span class="block text-xs text-slate-500">{{ $local['nombre'] }} · cómo llegar</span><span class="font-semibold">{{ $local['direccion'] }}</span></span>
                    </a>
                @endforeach
                @if ($contactos['horarios'])
                    <div class="flex items-start gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                        @include('tienda.icono', ['n' => 'reloj', 'clase' => 'mt-0.5 size-5 shrink-0 text-slate-500'])
                        <span><span class="block text-xs text-slate-500">Horario</span>@foreach ($contactos['horarios'] as $linea)<span class="block font-semibold">{{ $linea }}</span>@endforeach</span>
                    </div>
                @endif
            </div>

            @if ($lugarMapa)
                <iframe src="https://www.google.com/maps?q={{ rawurlencode($lugarMapa) }}&amp;z=17&amp;output=embed" title="Mapa de {{ $tienda['nombre'] }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" class="mt-4 h-80 w-full rounded-2xl ring-1 ring-slate-200"></iframe>
            @endif
        @elseif ($whatsapp)
            <p class="mt-10 rounded-2xl bg-(--marca-suave) px-5 py-4 text-slate-700">
                ¿Tienes otra consulta?
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="font-semibold text-(--marca-texto) hover:underline">Escríbenos por WhatsApp</a>
            </p>
        @endif
    </div>
@endsection
