@php
    $titulo = trim($__env->yieldContent('titulo')) ?: "{$tienda['nombre']} | Catálogo y pedidos en línea";
    // una sola línea: la descripción puede venir con saltos y eso ensucia el resultado en el buscador
    $resumen = \Illuminate\Support\Str::squish(trim($__env->yieldContent('resumen')) ?: ($tienda['descripcion'] ?: "Catálogo en línea de {$tienda['nombre']}. Mira nuestros productos y haz tu pedido por WhatsApp."));
    $canonica = trim($__env->yieldContent('canonica')) ?: $tienda['url'];
    // las fotos locales son rutas relativas: para compartir el enlace deben ir completas
    $absoluta = fn (?string $ruta) => $ruta ? (str_starts_with($ruta, 'http') ? $ruta : rtrim($tienda['url'], '/').$ruta) : null;
    $imagenSocial = $absoluta(trim($__env->yieldContent('imagen')) ?: ($tienda['logo'] ?: $categorias->firstWhere('imagen', '!=', null)?->imagen));
    $soloNumero = fn (?string $telefono) => preg_replace('/[^0-9+]/', '', (string) $telefono);
    $categoriaActiva = $categoria->id ?? null;
@endphp
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($resumen, 160) }}">
    <meta name="robots" content="{{ $previa ? 'noindex,nofollow' : (trim($__env->yieldContent('robots')) ?: 'index,follow') }}">
    <link rel="canonical" href="{{ $canonica }}">
    <meta property="og:type" content="@yield('og_tipo', 'website')">
    <meta property="og:locale" content="es_PE">
    <meta property="og:site_name" content="{{ $tienda['nombre'] }}">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($resumen, 200) }}">
    <meta property="og:url" content="{{ $canonica }}">
    @if ($imagenSocial)
        <meta property="og:image" content="{{ $imagenSocial }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <meta name="theme-color" content="{{ $tienda['colores'][0] }}">
    <link rel="icon" href="{{ $tienda['logo'] ?: '/favicon.ico?v=5' }}">
    @fonts
    @vite(['resources/css/app.css'])
    {{-- color de marca elegido por la tienda --}}
    <style>:root { --marca: {{ $tienda['colores'][0] }}; --marca-oscuro: {{ $tienda['colores'][1] }}; --marca-suave: {{ $tienda['colores'][2] }}; }</style>
    @stack('cabecera')
</head>
<body class="flex min-h-screen flex-col bg-white font-sans text-slate-900 antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-xl focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg">Ir al contenido</a>

    {{-- Vista previa: solo la ve el dueño con su enlace --}}
    @if ($previa)
        <div class="sticky top-0 z-50 bg-amber-400 text-amber-950">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 text-sm sm:px-6 lg:px-8">
                <p><span class="font-semibold">Vista previa.</span> Así se verá tu tienda. Tus clientes no ven estos cambios hasta que los guardes.</p>
                <a href="/?previa=salir" class="font-semibold underline underline-offset-2 hover:no-underline">Salir de la vista previa</a>
            </div>
        </div>
    @endif

    {{-- Anuncio de la tienda --}}
    @if ($tienda['anuncio'])
        <p class="bg-(--marca) px-4 py-2 text-center text-sm font-medium text-white">{{ $tienda['anuncio'] }}</p>
    @endif

    {{-- Franja superior: datos de atención --}}
    @if ($contactos['horario'] || $contactos['direccion'] || $contactos['telefono'] || $whatsapp)
        <div class="bg-slate-950 text-slate-300">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2 text-xs sm:px-6 lg:px-8">
                <p class="flex min-w-0 items-center gap-x-5">
                    @if ($contactos['horario'])
                        <span class="flex min-w-0 items-center gap-1.5">@include('tienda.icono', ['n' => 'reloj', 'clase' => 'size-3.5 text-slate-500'])<span class="truncate">{{ $contactos['horario'] }}</span></span>
                    @endif
                    @if ($contactos['direccion'])
                        <a href="{{ $contactos['mapa'] }}" target="_blank" rel="noopener" class="hidden min-w-0 items-center gap-1.5 hover:text-white md:flex">
                            @include('tienda.icono', ['n' => 'lugar', 'clase' => 'size-3.5 text-slate-500'])<span class="truncate">{{ $contactos['direccion'] }}</span>
                        </a>
                    @endif
                </p>
                <p class="flex shrink-0 items-center gap-x-5">
                    @if ($contactos['telefono'])
                        <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="hidden items-center gap-1.5 hover:text-white sm:flex">
                            @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-3.5 text-slate-500']){{ $contactos['telefono'] }}
                        </a>
                    @endif
                    @if ($whatsapp)
                        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-center gap-1.5 font-medium text-white hover:underline">
                            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-3.5'])WhatsApp {{ $contactos['whatsapp_texto'] }}
                        </a>
                    @endif
                </p>
            </div>
        </div>
    @endif

    {{-- fija solo en pantallas grandes: en el celular ocuparía un cuarto de la pantalla --}}
    <header class="z-30 border-b border-slate-200 bg-white lg:sticky {{ $previa ? 'lg:top-9' : 'lg:top-0' }}">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3.5 sm:px-6 lg:flex-nowrap lg:px-8">
            <a href="/" class="flex min-w-0 items-center gap-3" aria-label="{{ $tienda['nombre'] }}: inicio">
                @if ($tienda['logo'])
                    <img src="{{ $tienda['logo'] }}" alt="" class="size-11 shrink-0 rounded-xl bg-white object-contain ring-1 ring-slate-200">
                @else
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-(--marca) text-lg font-semibold text-white">{{ $tienda['inicial'] }}</span>
                @endif
                <span class="min-w-0">
                    <span class="block truncate text-lg leading-tight font-semibold tracking-tight">{{ $tienda['nombre'] }}</span>
                    <span class="hidden text-xs text-slate-500 sm:block">Tienda en línea</span>
                </span>
            </a>

            <div class="ml-auto flex shrink-0 items-center gap-2 lg:order-3 lg:ml-0">
                <a href="#contacto" class="hidden h-11 items-center rounded-xl px-4 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 sm:inline-flex">Contacto</a>
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-2 rounded-xl bg-(--marca) px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-(--marca-oscuro)">
                        @include('tienda.icono', ['n' => 'whatsapp'])
                        <span class="hidden sm:inline">Hacer pedido</span><span class="sm:hidden">Pedir</span>
                    </a>
                @else
                    <a href="#contacto" class="inline-flex h-11 items-center rounded-xl bg-(--marca) px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-(--marca-oscuro) sm:hidden">Contacto</a>
                @endif
            </div>

            {{-- buscador: en pantallas chicas ocupa su propia fila --}}
            <form action="/" method="get" role="search" class="relative w-full lg:order-2 lg:mx-auto lg:max-w-2xl lg:flex-1">
                <label for="buscador" class="sr-only">Buscar productos</label>
                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-slate-400">@include('tienda.icono', ['n' => 'buscar', 'clase' => 'size-4.5'])</span>
                <input id="buscador" type="search" name="q" value="{{ $buscar ?? '' }}" placeholder="¿Qué producto buscas?" autocomplete="off" maxlength="80"
                    class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pr-28 pl-11 text-[15px] placeholder-slate-400 transition-colors focus:border-(--marca) focus:bg-white focus:ring-4 focus:ring-(--marca)/10 focus:outline-none">
                <button type="submit" class="absolute top-1/2 right-1.5 h-9 -translate-y-1/2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition-colors hover:bg-(--marca)">Buscar</button>
            </form>
        </div>

        {{-- categorías: siempre a la mano --}}
        @if ($categorias->isNotEmpty())
            @php
                // con muchas categorías, la fila solo lleva las que entran (primero las de más productos)
                // y el resto queda en el menú "Categorías", que las lista todas
                $muchas = $categorias->count() > 6;
                $enFila = $muchas
                    ? $categorias->sortByDesc('productos')->sortByDesc(fn ($c) => $c->id === $categoriaActiva)->values()
                    : $categorias;
            @endphp
            <nav class="border-t border-slate-100" aria-label="Categorías">
                <div class="relative mx-auto flex max-w-7xl items-stretch px-4 text-sm sm:px-6 lg:px-8">
                    @if ($muchas)
                        <details class="group shrink-0">
                            <summary class="flex h-11 cursor-pointer list-none items-center gap-2 pr-4 font-semibold text-slate-900 transition-colors select-none hover:text-(--marca) group-open:text-(--marca) group-open:before:fixed group-open:before:inset-0 group-open:before:z-30 group-open:before:cursor-default group-open:before:content-[''] [&::-webkit-details-marker]:hidden">
                                @include('tienda.icono', ['n' => 'cuadricula'])Categorías
                                @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5 rotate-90 text-slate-400 transition-transform group-open:-rotate-90'])
                            </summary>
                            <div class="absolute inset-x-4 top-full z-40 max-h-[70vh] overflow-y-auto overscroll-contain rounded-b-2xl border border-t-0 border-slate-200 bg-white p-3 shadow-xl shadow-slate-900/10 sm:inset-x-6 sm:p-4 lg:inset-x-8">
                                <a href="/#catalogo" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 font-semibold text-slate-900 hover:bg-(--marca-suave) hover:text-(--marca)">
                                    <span>Todo el catálogo</span><span class="text-xs font-normal text-slate-400 tabular-nums">{{ number_format($tienda['productos']) }}</span>
                                </a>
                                <ul class="mt-1 grid gap-x-4 border-t border-slate-100 pt-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    @foreach ($categorias as $cat)
                                        <li>
                                            <a href="{{ $cat->url }}" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-(--marca-suave) hover:text-(--marca) {{ $categoriaActiva === $cat->id ? 'font-semibold text-(--marca)' : 'text-slate-700' }}">
                                                <span class="min-w-0 truncate">{{ $cat->nombre }}</span><span class="shrink-0 text-xs font-normal text-slate-400 tabular-nums">{{ $cat->productos }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endif
                    {{-- en celular la fila se desliza; en pantalla grande solo se ven las que entran completas --}}
                    <div class="flex h-11 min-w-0 flex-1 items-stretch gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden {{ $muchas ? 'border-l border-slate-100 pl-1 lg:flex-wrap lg:overflow-hidden' : '' }}">
                        @unless ($muchas)
                            <a href="/#catalogo" class="flex shrink-0 items-center gap-2 border-b-2 pr-3 font-semibold whitespace-nowrap {{ $categoriaActiva ? 'border-transparent text-slate-900 hover:text-(--marca)' : 'border-(--marca) text-(--marca)' }}">
                                @include('tienda.icono', ['n' => 'cuadricula'])Todo el catálogo
                            </a>
                        @endunless
                        @foreach ($enFila as $cat)
                            <a href="{{ $cat->url }}" @if ($categoriaActiva === $cat->id) aria-current="true" @endif
                                class="flex h-11 shrink-0 items-center border-b-2 px-3 whitespace-nowrap transition-colors {{ $categoriaActiva === $cat->id ? 'border-(--marca) font-semibold text-(--marca)' : 'border-transparent text-slate-600 hover:text-slate-900' }}">
                                {{ $cat->nombre }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </nav>
        @endif
    </header>

    <main id="contenido" class="flex-1">
        @yield('contenido')
    </main>

    {{-- Llamado a pedir --}}
    <section class="mt-16 bg-(--marca) text-white">
        <div class="mx-auto flex max-w-7xl flex-col items-start gap-5 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">¿No encuentras lo que buscas?</h2>
                <p class="mt-1 text-white/85">Escríbenos y te ayudamos a encontrarlo, con precio y disponibilidad al momento.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($whatsapp)
                    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center gap-2 rounded-xl bg-white px-6 text-sm font-semibold text-(--marca) shadow-sm transition-colors hover:bg-white/90">
                        @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])Escribir por WhatsApp
                    </a>
                @endif
                @if ($contactos['telefono'])
                    <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="inline-flex h-12 items-center gap-2 rounded-xl border border-white/40 px-6 text-sm font-semibold text-white transition-colors hover:bg-white/10">
                        @include('tienda.icono', ['n' => 'telefono', 'clase' => 'size-5'])Llamar
                    </a>
                @endif
                @if (! $whatsapp && ! $contactos['telefono'])
                    <a href="#contacto" class="inline-flex h-12 items-center rounded-xl bg-white px-6 text-sm font-semibold text-(--marca)">Ver cómo contactarnos</a>
                @endif
            </div>
        </div>
    </section>

    {{-- Pie: quiénes somos y contactos --}}
    <footer id="contacto" class="bg-slate-950 text-slate-400 lg:scroll-mt-32">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:grid-cols-2 sm:px-6 lg:grid-cols-12 lg:px-8">
            <div class="lg:col-span-4">
                <div class="flex items-center gap-3">
                    @if ($tienda['logo'])
                        <img src="{{ $tienda['logo'] }}" alt="" class="size-11 rounded-xl bg-white object-contain">
                    @else
                        <span class="grid size-11 place-items-center rounded-xl bg-(--marca) text-lg font-semibold text-white">{{ $tienda['inicial'] }}</span>
                    @endif
                    <span class="text-lg font-semibold tracking-tight text-white">{{ $tienda['nombre'] }}</span>
                </div>
                <p class="mt-4 max-w-sm text-sm leading-relaxed">
                    {{ $tienda['descripcion'] ?: 'Mira nuestro catálogo en línea y haz tu pedido en un momento. Te atendemos con gusto.' }}
                </p>
                @if ($contactos['facebook'] || $contactos['instagram'] || $contactos['tiktok'])
                    <div class="mt-5 flex gap-2">
                        @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $red => $nombreRed)
                            @if ($contactos[$red])
                                <a href="{{ $contactos[$red] }}" target="_blank" rel="noopener" aria-label="{{ $nombreRed }}" title="{{ $nombreRed }}"
                                    class="grid size-10 place-items-center rounded-xl bg-white/5 text-slate-300 transition-colors hover:bg-(--marca) hover:text-white">
                                    @include('tienda.icono', ['n' => $red, 'clase' => 'size-4.5'])
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($categorias->isNotEmpty())
                <div class="lg:col-span-2">
                    <h2 class="text-sm font-semibold text-white">Categorías</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        @foreach ($categorias->take(6) as $cat)
                            <li><a href="{{ $cat->url }}" class="transition-colors hover:text-white">{{ $cat->nombre }}</a></li>
                        @endforeach
                        <li><a href="/#catalogo" class="font-medium text-slate-300 transition-colors hover:text-white">Ver todo el catálogo</a></li>
                    </ul>
                </div>
            @endif

            <div class="lg:col-span-3">
                <h2 class="text-sm font-semibold text-white">Contáctanos</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @if ($whatsapp)
                        <li>
                            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex items-center gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'whatsapp'])</span>
                                <span><span class="block text-xs text-slate-500">WhatsApp</span><span class="font-medium text-slate-200">{{ $contactos['whatsapp_texto'] }}</span></span>
                            </a>
                        </li>
                    @endif
                    @if ($contactos['telefono'])
                        <li>
                            <a href="tel:{{ $soloNumero($contactos['telefono']) }}" class="flex items-center gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'telefono'])</span>
                                <span><span class="block text-xs text-slate-500">Teléfono</span><span class="font-medium text-slate-200">{{ $contactos['telefono'] }}</span></span>
                            </a>
                        </li>
                    @endif
                    @if ($contactos['email'])
                        <li>
                            <a href="mailto:{{ $contactos['email'] }}" class="flex items-center gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'correo'])</span>
                                <span class="min-w-0"><span class="block text-xs text-slate-500">Correo</span><span class="block truncate font-medium text-slate-200">{{ $contactos['email'] }}</span></span>
                            </a>
                        </li>
                    @endif
                    @if (! $whatsapp && ! $contactos['telefono'] && ! $contactos['email'])
                        <li>Pronto publicaremos nuestros datos de contacto.</li>
                    @endif
                </ul>
            </div>

            <div class="lg:col-span-3">
                <h2 class="text-sm font-semibold text-white">Visítanos</h2>
                <ul class="mt-4 space-y-3 text-sm">
                    @foreach ($contactos['direcciones'] as $lugar)
                        <li>
                            <a href="{{ $lugar['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'lugar'])</span>
                                <span><span class="block font-medium text-slate-200">{{ $lugar['texto'] }}</span><span class="text-xs text-slate-500">Ver en el mapa</span></span>
                            </a>
                        </li>
                    @endforeach
                    @if ($contactos['horarios'])
                        <li class="flex items-start gap-3">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'reloj'])</span>
                            <span>
                                <span class="block text-xs text-slate-500">Horario de atención</span>
                                @foreach ($contactos['horarios'] as $linea)
                                    <span class="block font-medium text-slate-200">{{ $linea }}</span>
                                @endforeach
                            </span>
                        </li>
                    @endif
                    @foreach ($contactos['locales'] as $local)
                        <li>
                            <a href="{{ $local['mapa'] }}" target="_blank" rel="noopener" class="flex items-start gap-3 transition-colors hover:text-white">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/5 text-slate-300">@include('tienda.icono', ['n' => 'lugar'])</span>
                                <span>
                                    <span class="block text-xs text-slate-500">{{ $local['nombre'] }}</span>
                                    <span class="block font-medium text-slate-200">{{ $local['direccion'] }}</span>
                                    @if ($local['telefono'])<span class="text-xs">{{ $local['telefono'] }}</span>@endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-5 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8">
                <span>© {{ now()->year }} {{ $tienda['nombre'] }}. Todos los derechos reservados.</span>
                <a href="{{ config('app.url') }}" target="_blank" rel="noopener" class="transition-colors hover:text-slate-300">Tienda creada con <span class="font-semibold text-slate-300">inkaPos</span></a>
            </div>
        </div>
    </footer>

    {{-- acceso rápido a WhatsApp en el celular (la página de producto trae su propia barra) --}}
    @if ($whatsapp && ! $__env->hasSection('barra_movil'))
        <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Escríbenos por WhatsApp"
            class="fixed right-4 bottom-4 z-40 grid size-14 place-items-center rounded-full bg-(--marca) text-white shadow-lg ring-4 shadow-black/25 ring-white transition-colors hover:bg-(--marca-oscuro) sm:hidden">
            @include('tienda.icono', ['n' => 'whatsapp', 'clase' => 'size-6'])
        </a>
    @endif
    @yield('barra_movil')
</body>
</html>
