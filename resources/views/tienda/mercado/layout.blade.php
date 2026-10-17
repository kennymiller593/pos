{{--
    Plantilla "Mercado": cabecera oscura con buscador, barra de categorías, sellos de confianza y pie.
    Comparte con la plantilla clásica el pedido (carrito), el buscador con sugerencias y el chatbot,
    que viven en tienda.js y esperan los mismos data-* de siempre.
--}}
@php
    $titulo = trim($__env->yieldContent('titulo')) ?: ($tienda['seo']['titulo'] ?: "{$tienda['nombre']} | Catálogo y pedidos en línea".($contactos['ciudad'] ? " en {$contactos['ciudad']}" : ''));
    $resumen = \Illuminate\Support\Str::squish(trim($__env->yieldContent('resumen')) ?: ($tienda['seo']['descripcion'] ?: ($tienda['descripcion'] ?: "Catálogo en línea de {$tienda['nombre']}. Mira nuestros productos y haz tu pedido por WhatsApp.")));
    $canonica = trim($__env->yieldContent('canonica')) ?: $tienda['url'];
    $absoluta = fn (?string $ruta) => $ruta ? (str_starts_with($ruta, 'http') ? $ruta : rtrim($tienda['url'], '/').$ruta) : null;
    $imagenSocial = $absoluta(trim($__env->yieldContent('imagen')) ?: ($tienda['logo'] ?: $categorias->firstWhere('imagen', '!=', null)?->imagen));
    $soloNumero = fn (?string $telefono) => preg_replace('/[^0-9+]/', '', (string) $telefono);
    $categoriaActiva = $categoria->id ?? null;
    $enCatalogo = request()->is('catalogo');
    $sitioEsquema = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => rtrim($tienda['url'], '/').'/#sitio',
        'name' => $tienda['nombre'],
        'url' => $tienda['url'],
        'inLanguage' => 'es-PE',
        'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => rtrim($tienda['url'], '/').'/catalogo?q={termino}'], 'query-input' => 'required name=termino'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    // franja superior: el anuncio del dueño o, si no escribió uno, la promesa de una tienda en línea
    $franja = $tienda['anuncio'] ?: implode(' · ', array_filter([
        'Envíos a todo el Perú',
        $whatsapp ? 'Pedidos y asesoría por WhatsApp' : 'Pedidos en línea',
        $contactos['horario'] ? 'Atención '.$contactos['horario'] : null,
    ]));
    // sellos de confianza de una tienda en línea (ícono, título, detalle, es-whatsapp)
    $entrega = $entrega ?? ['envios' => null, 'pagos' => null];
    $sellos = [
        ['camion', 'Envíos a todo el Perú', $entrega['envios'] ?: 'Coordinamos la entrega contigo al confirmar tu pedido.'],
        ['escudo', 'Pago seguro', $entrega['pagos'] ?: 'Te confirmamos el total y el medio de pago antes de pagar.'],
        $whatsapp
            ? ['whatsapp', 'Asesoría por WhatsApp', 'Te ayudamos a elegir y te confirmamos disponibilidad al momento.']
            : ['audifonos', 'Atención personalizada', $contactos['telefono'] ? 'Llámanos al '.$contactos['telefono'].' y te asesoramos.' : 'Escríbenos y te asesoramos con gusto.'],
        ['sello', 'Productos originales', 'Catálogo real de nuestra tienda, con precios al día.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($resumen, 158, '…', true) }}">
    <meta name="robots" content="{{ $previa ? 'noindex,nofollow' : (trim($__env->yieldContent('robots')) ?: 'index,follow') }}">
    <link rel="canonical" href="{{ $canonica }}">
    <meta property="og:type" content="@yield('og_tipo', 'website')">
    <meta property="og:locale" content="es_PE">
    <meta property="og:site_name" content="{{ $tienda['nombre'] }}">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($resumen, 200, '…', true) }}">
    <meta property="og:url" content="{{ $canonica }}">
    @if ($imagenSocial)
        <meta property="og:image" content="{{ $imagenSocial }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <meta name="theme-color" content="{{ $tienda['colores'][1] }}">
    <link rel="icon" href="{{ $tienda['favicon'] ?: ($tienda['logo'] ?: '/favicon.ico?v=5') }}">
    @if ($tienda['favicon'])
        <link rel="apple-touch-icon" href="{{ $tienda['favicon'] }}">
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/tienda.js'])
    <style>:root { --marca: {{ $tienda['colores'][0] }}; --marca-oscuro: {{ $tienda['colores'][1] }}; --marca-suave: {{ $tienda['colores'][2] }}; --sobre-marca: {{ $tienda['colores'][3] }}; --marca-texto: {{ $tienda['colores'][4] }}; }
        /* la plantilla Mercado pinta cabecera, campaña y pie con un tono oscuro sacado del color de la tienda:
           así el color queda para los botones y resalta, sea claro u oscuro el que eligió el dueño */
        :root { --mercado-fondo: var(--marca-oscuro); --sobre-fondo: var(--sobre-marca); }
        @supports (color: color-mix(in srgb, red 50%, blue)) { :root { --mercado-fondo: color-mix(in srgb, var(--marca) 38%, #0b1a14); --sobre-fondo: #ffffff; } }
        html:not(.con-pedido) [data-necesita-js] { display: none !important; }
        html:not(.con-bot) [data-necesita-bot] { display: none !important; }</style>
    <script type="application/json" id="tienda-datos">{!! json_encode(['nombre' => $tienda['nombre'], 'whatsapp' => $contactos['whatsapp'], 'asistente' => $asistente ? ['nombre' => $asistente['nombre'], 'saludo' => $asistente['saludo'], 'sugerencias' => $asistente['sugerencias']] : null], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <script type="application/ld+json">{!! $sitioEsquema !!}</script>
    @stack('cabecera')
</head>
<body class="flex min-h-screen flex-col bg-slate-50 font-sans text-slate-900 antialiased" data-plantilla="mercado">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-xl focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Ir al contenido</a>

    @if ($previa)
        <div class="sticky top-0 z-50 bg-amber-400 text-amber-950">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 text-sm sm:px-6 lg:px-8">
                <p><span class="font-semibold">Vista previa.</span> Así se verá tu tienda. Tus clientes no ven estos cambios hasta que los guardes.</p>
                <a href="/?previa=salir" class="font-semibold underline underline-offset-2 hover:no-underline">Salir de la vista previa</a>
            </div>
        </div>
    @endif

    {{-- Franja superior --}}
    @if ($franja !== '')
        <p class="bg-(--marca) px-4 py-1.5 text-center text-xs font-semibold text-(--sobre-marca) sm:text-[13px]" data-franja>{{ $franja }}</p>
    @endif

    {{-- Cabecera: marca, buscador, WhatsApp y carrito --}}
    <header class="bg-(--mercado-fondo) text-(--sobre-fondo) {{ $previa ? '' : 'lg:sticky lg:top-0' }} z-30">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3 py-3 lg:gap-6">
                <a href="/" class="flex min-w-0 items-center gap-3" aria-label="{{ $tienda['nombre'] }}: inicio">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo'] }}" alt="" class="size-10 shrink-0 rounded-lg bg-white object-contain p-0.5">
                    @endif
                    <span class="truncate text-lg font-extrabold tracking-wide uppercase sm:text-xl">{{ $tienda['nombre'] }}</span>
                </a>

                {{-- buscador: en pantalla grande va en medio; en el celular, en su propia fila --}}
                <form action="/catalogo" method="get" role="search" class="relative hidden min-w-0 flex-1 lg:flex">
                    <label for="buscador" class="sr-only">Buscar productos</label>
                    <input id="buscador" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Busca por producto, marca o código" autocomplete="off" maxlength="80"
                        class="h-11 w-full rounded-l-lg border-0 bg-white px-4 text-[15px] text-slate-900 placeholder-slate-400 focus:ring-4 focus:ring-(--marca)/40 focus:outline-none">
                    <button type="submit" class="h-11 shrink-0 rounded-r-lg bg-(--marca) px-5 text-sm font-bold text-(--sobre-marca) transition-colors hover:brightness-110">Buscar</button>
                    <div id="sugerencias" class="absolute inset-x-0 top-full z-40 mt-2 hidden overflow-hidden rounded-xl bg-white text-left text-slate-900 shadow-2xl ring-1 shadow-slate-900/15 ring-slate-200" aria-live="polite"></div>
                </form>

                <div class="ml-auto flex shrink-0 items-center gap-2">
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="hidden h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold transition-colors hover:bg-white/10 sm:inline-flex">
                            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4.5'])WhatsApp
                        </a>
                        <button type="button" data-pedido-abrir data-necesita-js aria-label="Ver mi carrito"
                            class="relative inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg bg-white/10 px-3.5 text-sm font-bold ring-1 ring-white/20 transition-colors hover:bg-white/20">
                            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-5'])
                            <span class="hidden sm:inline">Carrito</span>
                            <span data-pedido-cuenta class="absolute -top-1.5 -right-1.5 hidden min-w-5 rounded-full bg-(--marca) px-1 text-center text-[11px] leading-5 font-bold text-(--sobre-marca) ring-2 ring-(--mercado-fondo)"></span>
                        </button>
                    @else
                        <a href="#contacto" class="inline-flex h-11 items-center rounded-lg bg-white/10 px-4 text-sm font-bold ring-1 ring-white/20 transition-colors hover:bg-white/20">Contacto</a>
                    @endif
                </div>
            </div>

            <form action="/catalogo" method="get" role="search" class="relative flex pb-3 lg:hidden">
                <label for="buscador-movil" class="sr-only">Buscar productos</label>
                <input id="buscador-movil" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="Busca por producto, marca o código" autocomplete="off" maxlength="80"
                    class="h-11 w-full rounded-l-lg border-0 bg-white px-4 text-[15px] text-slate-900 placeholder-slate-400 focus:ring-4 focus:ring-(--marca)/40 focus:outline-none">
                <button type="submit" class="h-11 shrink-0 rounded-r-lg bg-(--marca) px-4 text-sm font-bold text-(--sobre-marca)">Buscar</button>
            </form>
        </div>

        {{-- Categorías: en el celular se deslizan --}}
        @if ($categorias->isNotEmpty())
            <nav class="bg-(--marca) text-(--sobre-marca)" aria-label="Categorías">
                <div class="mx-auto flex max-w-7xl items-stretch gap-1 overflow-x-auto px-2 text-sm font-semibold [scrollbar-width:none] sm:px-4 lg:px-6 [&::-webkit-scrollbar]:hidden">
                    <a href="/catalogo" class="flex h-10 shrink-0 items-center px-3 whitespace-nowrap transition-colors hover:bg-black/10 {{ $enCatalogo ? 'bg-black/15' : '' }}">Todos</a>
                    @foreach ($categorias as $cat)
                        <a href="{{ $cat->url }}" @if ($categoriaActiva === $cat->id) aria-current="true" @endif
                            class="flex h-10 shrink-0 items-center px-3 whitespace-nowrap transition-colors hover:bg-black/10 {{ $categoriaActiva === $cat->id ? 'bg-black/15' : '' }}">{{ $cat->nombre }}</a>
                    @endforeach
                </div>
            </nav>
        @endif
    </header>

    <main id="contenido" class="flex-1">
        @yield('contenido')
    </main>

    {{-- Sellos de confianza --}}
    <section class="mt-14 border-t border-slate-200 bg-white" aria-label="Cómo te atendemos" data-sellos>
        <ul class="mx-auto grid max-w-7xl gap-4 px-4 py-8 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
            @foreach ($sellos as [$icono, $tituloSello, $detalle])
                <li class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-(--marca-suave) text-(--marca-texto)">@include($icono === 'whatsapp' ? 'tienda.icono' : 'tienda.mercado.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold">{{ $tituloSello }}</span>
                        <span class="line-clamp-2 text-xs text-slate-500">{{ $detalle }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Pie --}}
    <footer id="contacto" class="bg-(--mercado-fondo) text-(--sobre-fondo)">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-2 lg:grid-cols-3 lg:px-8">
            <div>
                <p class="text-lg font-extrabold tracking-wide uppercase">{{ $tienda['nombre'] }}</p>
                <p class="mt-2 max-w-sm text-sm opacity-80">{{ $tienda['descripcion'] ?: 'Mira nuestro catálogo en línea y haz tu pedido en un momento.' }}</p>
                @if ($contactos['facebook'] || $contactos['instagram'] || $contactos['tiktok'])
                    <div class="mt-4 flex gap-2">
                        @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $red => $nombreRed)
                            @if ($contactos[$red])
                                <a href="{{ $contactos[$red] }}" target="_blank" rel="noopener" aria-label="{{ $nombreRed }}" title="{{ $nombreRed }}" class="grid size-9 place-items-center rounded-lg bg-white/10 transition-colors hover:bg-white/20">
                                    @include('tienda.icono', ['n' => $red, 'clase' => 'size-4'])
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <h2 class="text-sm font-bold tracking-wider uppercase opacity-70">Visítanos y contáctanos</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($contactos['direcciones'] as $lugar)
                        <li><a href="{{ $lugar['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-2 hover:underline">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-4 opacity-70'])<span>{{ $lugar['texto'] }}</span></a></li>
                    @endforeach
                    @foreach ($contactos['locales'] as $local)
                        <li><a href="{{ $local['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-2 hover:underline">@include('tienda.icono', ['n' => 'lugar', 'clase' => 'mt-0.5 size-4 opacity-70'])<span>{{ $local['nombre'] }}: {{ $local['direccion'] }}</span></a></li>
                    @endforeach
                    @foreach ($contactos['horarios'] as $linea)
                        <li class="flex items-start gap-2">@include('tienda.icono', ['n' => 'reloj', 'clase' => 'mt-0.5 size-4 opacity-70'])<span>{{ $linea }}</span></li>
                    @endforeach
                    @if ($contactos['telefono'])
                        <li><a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="flex items-center gap-2 hover:underline">@include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-4 opacity-70']){{ $contactos['telefono'] }}</a></li>
                    @endif
                    @if ($whatsapp)
                        <li><a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-center gap-2 font-semibold hover:underline">@include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-4'])WhatsApp {{ $contactos['whatsapp_texto'] }}</a></li>
                    @endif
                    @if ($contactos['email'])
                        <li><a href="mailto:{{ $contactos['email'] }}" class="flex items-center gap-2 hover:underline">@include('tienda.icono', ['n' => 'correo', 'clase' => 'size-4 opacity-70']){{ $contactos['email'] }}</a></li>
                    @endif
                    @if (! $whatsapp && ! $contactos['telefono'] && ! $contactos['email'] && ! $contactos['direcciones'])
                        <li class="opacity-80">Pronto publicaremos nuestros datos de contacto.</li>
                    @endif
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-bold tracking-wider uppercase opacity-70">Información</h2>
                <ul class="mt-3 space-y-2 text-sm" data-paginas>
                    <li><a href="/catalogo" class="hover:underline">Todo el catálogo</a></li>
                    @foreach ($categorias->take(5) as $cat)
                        <li><a href="{{ $cat->url }}" class="hover:underline">{{ $cat->nombre }}</a></li>
                    @endforeach
                    @foreach ($paginas as $enlacePagina)
                        <li><a href="{{ $enlacePagina['url'] }}" class="font-semibold hover:underline">{{ $enlacePagina['titulo'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="border-t border-white/15">
            <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-4 text-xs opacity-75 sm:flex-row sm:px-6 lg:px-8">
                <span>© {{ now()->year }} {{ $tienda['nombre'] }}. Todos los derechos reservados.</span>
                <a href="{{ config('app.url') }}" target="_blank" rel="noopener" class="hover:underline">Tienda creada con <span class="font-bold">inkaPos</span></a>
            </div>
        </div>
    </footer>

    {{-- WhatsApp flotante (lo reemplaza el chatbot si está encendido) --}}
    @if ($whatsapp && ! $asistente)
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp" data-whatsapp-flotante
            class="fixed right-4 bottom-4 z-40 grid size-14 place-items-center rounded-full bg-[#25D366] text-white shadow-lg ring-4 shadow-black/25 ring-white transition-colors hover:bg-[#1eb957] sm:right-6 sm:bottom-6 {{ $__env->hasSection('barra_movil') ? 'max-sm:hidden' : '' }}">
            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-7'])
        </a>
    @endif
    @if ($asistente)
        @include('tienda.asistente')
    @endif
    @yield('barra_movil')

    {{-- El carrito: lo que el cliente va agregando, para enviarlo completo por WhatsApp (lo maneja tienda.js) --}}
    @if ($whatsapp)
        <button type="button" data-pedido-abrir data-pedido-flotante data-necesita-js aria-label="Ver mi carrito"
            class="fixed left-4 z-40 hidden size-14 cursor-pointer place-items-center rounded-full bg-(--mercado-fondo) text-(--sobre-fondo) shadow-lg ring-4 shadow-black/25 ring-white transition hover:brightness-125 sm:right-6 sm:bottom-[6.5rem] sm:left-auto [&:not(.hidden)]:grid bottom-4 {{ $__env->hasSection('barra_movil') ? 'max-sm:!hidden' : '' }}">
            @include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-6'])
            <span data-pedido-cuenta class="absolute -top-1 -right-1 hidden min-w-6 rounded-full bg-(--marca) px-1.5 text-center text-xs leading-6 font-semibold text-(--sobre-marca) ring-2 ring-white"></span>
        </button>

        <div id="pedido-aviso" class="fixed inset-x-4 bottom-4 z-50 mx-auto hidden max-w-md items-center gap-3 rounded-xl bg-slate-900 py-3 pr-3 pl-4 text-sm text-white shadow-2xl [&:not(.hidden)]:flex" role="status" aria-live="polite">
            @include('tienda.icono', ['n' => 'check', 'clase' => 'size-5 text-emerald-400'])
            <span data-aviso-texto class="min-w-0 flex-1 truncate"></span>
            <button type="button" data-pedido-abrir class="h-9 shrink-0 cursor-pointer rounded-lg bg-white px-3.5 text-sm font-semibold text-slate-900 transition-colors hover:bg-slate-100">Ver carrito</button>
        </div>

        <div id="pedido" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="titulo-pedido">
            <div class="absolute inset-0 bg-slate-950/50" data-pedido-cerrar></div>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="titulo-pedido" class="text-lg font-bold tracking-tight">Tu carrito</h2>
                        <p class="text-sm text-slate-500">Lo envías por WhatsApp y te lo confirmamos.</p>
                    </div>
                    <button type="button" data-pedido-cerrar data-pedido-cerrar-boton aria-label="Cerrar" class="grid size-10 cursor-pointer place-items-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900">
                        @include('tienda.icono', ['n' => 'cerrar', 'clase' => 'size-5'])
                    </button>
                </div>

                <div data-pedido-vacio class="flex flex-1 flex-col items-center justify-center px-8 text-center">
                    <span class="grid size-16 place-items-center rounded-2xl bg-slate-50 text-slate-400 ring-1 ring-slate-200">@include('tienda.icono', ['n' => 'carrito', 'clase' => 'size-7'])</span>
                    <p class="mt-5 text-lg font-bold tracking-tight">Tu carrito está vacío</p>
                    <p class="mt-1 text-slate-500">Toca "Agregar al carrito" en los productos que quieras y aquí se irán sumando.</p>
                    <a href="/catalogo" class="mt-6 inline-flex h-11 items-center gap-2 rounded-lg bg-(--mercado-fondo) px-5 text-sm font-bold text-(--sobre-fondo) transition hover:brightness-125">Ver el catálogo @include('tienda.icono', ['n' => 'flecha'])</a>
                </div>

                <ul data-pedido-lista class="hidden flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain"></ul>

                <div data-pedido-pie class="hidden border-t border-slate-200 bg-slate-50 px-5 pt-4 pb-5">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div>
                            <label for="pedido-nombre" class="sr-only">Tu nombre</label>
                            <input id="pedido-nombre" data-pedido-nombre type="text" maxlength="60" autocomplete="name" placeholder="Tu nombre (opcional)"
                                class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm placeholder-slate-400 focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        </div>
                        <div>
                            <label for="pedido-nota" class="sr-only">Nota para la tienda</label>
                            <input id="pedido-nota" data-pedido-nota type="text" maxlength="160" placeholder="Nota: envío, recojo... (opcional)"
                                class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm placeholder-slate-400 focus:border-(--marca) focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                        </div>
                    </div>
                    <p data-pedido-total class="mt-4 flex items-baseline justify-between gap-3"></p>
                    <a href="{{ $whatsapp }}" data-pedido-enviar target="_blank" rel="noopener"
                        class="mt-3 flex h-13 items-center justify-center gap-2 rounded-lg bg-[#25D366] px-6 text-base font-bold text-white shadow-sm transition-colors hover:bg-[#1eb957]">
                        @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Enviar pedido por WhatsApp
                    </a>
                    <div class="mt-3 flex items-center justify-between gap-3 text-xs text-slate-500">
                        <span>La tienda te confirma disponibilidad y precio final.</span>
                        <button type="button" data-pedido-vaciar class="shrink-0 cursor-pointer font-medium text-slate-600 underline underline-offset-2 hover:text-red-600">Vaciar</button>
                    </div>
                </div>
            </aside>
        </div>
    @endif
</body>
</html>
