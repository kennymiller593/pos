@php
    $titulo = trim($__env->yieldContent('titulo')) ?: $tienda['nombre'];
    $resumen = trim($__env->yieldContent('resumen')) ?: ($tienda['descripcion'] ?: "Catálogo de productos de {$tienda['nombre']}. Mira lo que tenemos y haz tu pedido.");
    $canonica = trim($__env->yieldContent('canonica')) ?: $tienda['url'];
    $imagenSocial = trim($__env->yieldContent('imagen')) ?: $tienda['logo'];
    // las fotos locales son rutas relativas: para compartir el enlace deben ir completas
    $absoluta = fn (?string $ruta) => $ruta ? (str_starts_with($ruta, 'http') ? $ruta : rtrim($tienda['url'], '/').$ruta) : null;
    $mapa = fn (string $direccion) => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($direccion);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($resumen, 160) }}">
    <meta name="robots" content="@yield('robots', 'index,follow')">
    <link rel="canonical" href="{{ $canonica }}">
    <meta property="og:type" content="@yield('og_tipo', 'website')">
    <meta property="og:site_name" content="{{ $tienda['nombre'] }}">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($resumen, 200) }}">
    <meta property="og:url" content="{{ $canonica }}">
    @if ($imagenSocial)
        <meta property="og:image" content="{{ $absoluta($imagenSocial) }}">
    @endif
    <link rel="icon" href="{{ $tienda['logo'] ?: '/favicon.ico?v=5' }}">
    @vite(['resources/css/app.css'])
    {{-- color de marca elegido por la tienda --}}
    <style>:root { --marca: {{ $tienda['colores'][0] }}; --marca-oscuro: {{ $tienda['colores'][1] }}; --marca-suave: {{ $tienda['colores'][2] }}; }</style>
    @stack('cabecera')
</head>
<body class="min-h-screen bg-stone-50 text-neutral-900 antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-xl focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Ir al contenido</a>

    <header class="sticky top-0 z-30 border-b border-stone-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3 sm:flex-nowrap sm:px-6">
            <a href="/" class="flex min-w-0 items-center gap-3" aria-label="{{ $tienda['nombre'] }}: inicio">
                @if ($tienda['logo'])
                    <img src="{{ $tienda['logo'] }}" alt="" class="size-10 shrink-0 rounded-xl border border-stone-200 bg-white object-contain">
                @else
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-(--marca) text-lg font-bold text-white">{{ $tienda['inicial'] }}</span>
                @endif
                <span class="truncate text-base font-semibold tracking-tight sm:text-lg">{{ $tienda['nombre'] }}</span>
            </a>

            <nav class="ml-auto flex shrink-0 items-center gap-1 sm:order-3 sm:ml-0" aria-label="Principal">
                <a href="/#catalogo" class="hidden rounded-xl px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-stone-100 hover:text-neutral-900 md:block">Productos</a>
                <a href="#contacto" class="rounded-xl px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-stone-100 hover:text-neutral-900">Contacto</a>
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="hidden h-10 items-center gap-2 rounded-xl bg-(--marca) px-4 text-sm font-semibold text-white transition-colors hover:bg-(--marca-oscuro) sm:inline-flex">
                        @include('tienda.icono', ['n' => 'whatsapp'])
                        WhatsApp
                    </a>
                @endif
            </nav>

            {{-- buscador: en el celular ocupa su propia fila --}}
            <form action="/" method="get" role="search" class="relative w-full sm:order-2 sm:mx-auto sm:max-w-md sm:flex-1">
                <label for="buscador" class="sr-only">Buscar productos</label>
                <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-neutral-400">@include('tienda.icono', ['n' => 'buscar'])</span>
                <input id="buscador" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Buscar productos..." autocomplete="off" maxlength="80"
                    class="h-11 w-full rounded-xl border border-stone-200 bg-stone-50 pr-24 pl-10 text-sm placeholder-neutral-400 focus:border-(--marca) focus:bg-white focus:ring-2 focus:ring-(--marca)/20 focus:outline-none">
                <button type="submit" class="absolute top-1/2 right-1.5 h-8 -translate-y-1/2 rounded-lg bg-(--marca) px-3.5 text-sm font-semibold text-white transition-colors hover:bg-(--marca-oscuro)">Buscar</button>
            </form>
        </div>
    </header>

    <main id="contenido" class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
        @yield('contenido')
    </main>

    {{-- Contactos --}}
    <footer id="contacto" class="mt-10 scroll-mt-24 border-t border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <h2 class="text-xl font-semibold tracking-tight">Contáctanos</h2>
            <p class="mt-1 text-sm text-neutral-500">¿Quieres hacer un pedido o tienes una consulta? Escríbenos, te atendemos.</p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-2xl border border-stone-200 p-4 transition-colors hover:border-(--marca) hover:bg-(--marca-suave)">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca) text-white">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold tracking-wider text-neutral-400 uppercase">WhatsApp</span>
                            <span class="block truncate font-semibold">{{ $contactos['whatsapp_texto'] }}</span>
                        </span>
                    </a>
                @endif
                @if ($contactos['telefono'])
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactos['telefono']) }}" class="flex items-center gap-3 rounded-2xl border border-stone-200 p-4 transition-colors hover:border-(--marca) hover:bg-(--marca-suave)">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca-suave) text-(--marca)">@include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold tracking-wider text-neutral-400 uppercase">Teléfono</span>
                            <span class="block truncate font-semibold">{{ $contactos['telefono'] }}</span>
                        </span>
                    </a>
                @endif
                @if ($contactos['email'])
                    <a href="mailto:{{ $contactos['email'] }}" class="flex items-center gap-3 rounded-2xl border border-stone-200 p-4 transition-colors hover:border-(--marca) hover:bg-(--marca-suave)">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca-suave) text-(--marca)">@include('tienda.icono', ['n' => 'correo', 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold tracking-wider text-neutral-400 uppercase">Correo</span>
                            <span class="block truncate font-semibold">{{ $contactos['email'] }}</span>
                        </span>
                    </a>
                @endif
                @if ($contactos['horario'])
                    <div class="flex items-center gap-3 rounded-2xl border border-stone-200 p-4">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca-suave) text-(--marca)">@include('tienda.icono', ['n' => 'reloj', 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold tracking-wider text-neutral-400 uppercase">Horario</span>
                            <span class="block font-semibold">{{ $contactos['horario'] }}</span>
                        </span>
                    </div>
                @endif
                @if ($contactos['direccion'])
                    <a href="{{ $mapa($contactos['direccion']) }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-2xl border border-stone-200 p-4 transition-colors hover:border-(--marca) hover:bg-(--marca-suave)">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca-suave) text-(--marca)">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'size-5'])</span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold tracking-wider text-neutral-400 uppercase">Dirección · ver mapa</span>
                            <span class="block font-semibold">{{ $contactos['direccion'] }}</span>
                        </span>
                    </a>
                @endif
            </div>

            @if (count($contactos['locales']) && ! (count($contactos['locales']) === 1 && $contactos['locales'][0]['direccion'] === $contactos['direccion']))
                <h3 class="mt-8 text-sm font-semibold tracking-tight">Nuestros locales</h3>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($contactos['locales'] as $local)
                        <li class="rounded-2xl border border-stone-200 p-4 text-sm">
                            <p class="font-semibold">{{ $local['nombre'] }}</p>
                            <a href="{{ $mapa($local['direccion']) }}" target="_blank" rel="noopener" class="mt-1 flex items-start gap-1.5 text-neutral-600 hover:text-(--marca)">
                                <span class="mt-0.5">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'size-3.5'])</span>{{ $local['direccion'] }}
                            </a>
                            @if ($local['telefono'])
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $local['telefono']) }}" class="mt-1 flex items-center gap-1.5 text-neutral-600 hover:text-(--marca)">
                                    @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-3.5']){{ $local['telefono'] }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($contactos['facebook'] || $contactos['instagram'] || $contactos['tiktok'])
                <div class="mt-8 flex flex-wrap items-center gap-2">
                    <span class="mr-1 text-sm text-neutral-500">Síguenos</span>
                    @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $red => $nombreRed)
                        @if ($contactos[$red])
                            <a href="{{ $contactos[$red] }}" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-xl border border-stone-200 px-3.5 text-sm font-medium transition-colors hover:border-(--marca) hover:text-(--marca)">
                                @include('tienda.icono', ['n' => $red]){{ $nombreRed }}
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        <div class="border-t border-stone-200">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-1 px-4 py-4 text-xs text-neutral-500 sm:flex-row sm:px-6">
                <span>© {{ now()->year }} {{ $tienda['nombre'] }}</span>
                <a href="{{ config('app.url') }}" target="_blank" rel="noopener" class="hover:text-neutral-800">Tienda creada con <span class="font-semibold">inkaPos</span></a>
            </div>
        </div>
    </footer>

    {{-- acceso rápido a WhatsApp en el celular --}}
    @if ($whatsapp)
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp"
            class="fixed right-4 bottom-4 z-40 grid size-14 place-items-center rounded-full bg-(--marca) text-white shadow-lg shadow-black/20 transition-colors hover:bg-(--marca-oscuro) sm:hidden">
            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-6'])
        </a>
    @endif
</body>
</html>
