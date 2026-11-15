@php
    /*
     * Página de aterrizaje pública de inkaPos. Blade puro: todo el contenido va en el HTML que reciben los buscadores.
     * Colores y tipografías viven en resources/css/landing.css y no se comparten con el panel ni con las tiendas.
     */
    $url = rtrim(url('/'), '/');
    // en el logo la marca va con mayúscula inicial
    $marca = ucfirst($marca);
    $titulo = "{$marca} | Sistema POS y facturación electrónica SUNAT en Perú";
    $resumen = "Sistema de punto de venta en la nube con boletas y facturas electrónicas SUNAT, inventario, caja y tienda en línea. Pruébalo gratis {$diasPrueba} días, sin tarjeta.";
    $imagenSocial = "{$url}/images/landing-social.png";
    $enlaceWhatsapp = $whatsapp ? "https://wa.me/{$whatsapp}?text=".rawurlencode("Hola, quiero información sobre {$marca}") : null;

    $rubros = ['Bodegas', 'Minimarkets', 'Ferreterías', 'Boticas', 'Agroveterinarias', 'Tiendas de ropa', 'Celulares y accesorios'];

    $funciones = [
        ['factura', 'Facturación electrónica SUNAT', 'Boletas, facturas, notas de crédito y guías de remisión. El comprobante se envía solo y, si SUNAT no responde, se reintenta.'],
        ['carrito', 'Punto de venta rápido', 'Cobra con lector de código de barras, desde la computadora o el celular. Efectivo, Yape, Plin, tarjeta y pagos mixtos.'],
        ['paquete', 'Inventario con lotes y vencimientos', 'Stock al día en cada venta y compra, costeo FIFO, lotes con fecha de vencimiento y avisos de stock bajo.'],
        ['caja', 'Caja y arqueo por turno', 'Abre y cierra caja con todo lo que entró y salió, por medio de pago. Cuadra el efectivo al final del día.'],
        ['tienda', 'Varias sucursales', 'Cada local con su stock, su caja y sus series. Transfiere mercadería entre sucursales con un clic.'],
        ['personas', 'Ventas al crédito', 'Cuentas por cobrar a tus clientes y por pagar a tus proveedores, con abonos y saldos siempre claros.'],
        ['camion', 'Compras y proveedores', 'Registra tus compras, actualiza costos y revisa el historial de cada proveedor y de cada producto.'],
        ['pedido', 'Cotizaciones y pedidos', 'Envía cotizaciones, recibe encargos con adelanto y conviértelos en venta cuando entregas.'],
        ['capas', 'Tallas, colores y series', 'Productos con variantes para ropa y calzado, y control de serie o IMEI para celulares y equipos.'],
        ['grafico', 'Reportes para decidir', 'Ventas, ganancia, productos más vendidos y registro de ventas listo para tu contador.'],
        ['escudo', 'Usuarios y permisos', 'Administrador, cajero, vendedor y almacenero. Cada persona ve y hace solo lo que le corresponde.'],
        ['impresora', 'Ticket, A4 y correo', 'Imprime en ticketera de 58 u 80 mm o en A4, y envía el comprobante al correo de tu cliente.'],
    ];

    $pasos = [
        ['registro', 'Regístrate con tu RUC', 'Traemos los datos de tu empresa desde SUNAT. En minutos tienes tu cuenta lista para usar.'],
        ['subir', 'Carga tus productos', 'Sube tu catálogo desde un Excel o regístralo a mano, con su stock inicial por sucursal.'],
        ['recibo', 'Vende y factura', 'Cobra desde el punto de venta y el comprobante electrónico sale a SUNAT sin pasos extra.'],
    ];

    $preguntas = [
        ['¿Qué necesito para emitir boletas y facturas electrónicas?', 'Tu RUC, tu usuario SOL secundario y tu certificado digital. Los cargas una sola vez en la sección Empresa. Mientras los consigues, puedes probar todo el sistema en el ambiente de pruebas de SUNAT, sin valor tributario.'],
        ['¿Funciona en celular y tablet?', "Sí. {$marca} se usa desde el navegador en computadora, tablet o celular, sin instalar nada. Solo necesitas conexión a internet."],
        ['¿Puedo imprimir en ticketera térmica?', 'Sí. Los comprobantes salen en formato de 58 mm y 80 mm para ticketera térmica, y también en A4.'],
        ['¿Puedo tener varias sucursales?', 'Sí. Cada sucursal tiene su propio stock, su caja y sus series de comprobantes, y puedes transferir mercadería entre ellas. La cantidad de sucursales depende del plan.'],
        ['¿Qué pasa cuando termina la prueba gratis?', 'Tienes 3 días de gracia para elegir un plan. Tus datos no se borran: al activar el plan sigues exactamente donde lo dejaste.'],
        ['¿Sirve para mi tipo de negocio?', "{$marca} se usa en bodegas, minimarkets, ferreterías, boticas, agroveterinarias, tiendas de ropa y tiendas de celulares. Si vendes productos y necesitas boletas o facturas, te sirve."],
    ];

    // los tres pilares, como tarjetas grandes
    $servicios = [
        ['carrito', 'Sistema de ventas', 'Cobra rápido en el mostrador, desde la computadora o el celular.', ['Lector de código de barras', 'Efectivo, Yape, Plin y tarjeta', 'Caja y arqueo por turno'], 'Ver funciones', '#funciones', 'ventas'],
        ['factura', 'Facturación electrónica', 'Cumple con SUNAT de forma fácil, rápida y segura.', ['Boletas, facturas y notas de crédito', 'Envío automático a SUNAT', 'Ticket, A4 y envío por correo'], 'Probar gratis', '/registro', 'factura'],
        ['mundo', 'Tienda en línea', 'Tu catálogo en internet, conectado a tu stock.', ['Pedidos por WhatsApp', 'Con tu marca y tu dominio', 'Asistente que responde por ti'], 'Conocer más', '#tienda-en-linea', 'tienda'],
    ];

    $razones = [
        ['velocidad', 'Rápido y fácil de usar', 'Fácil de aprender: tu equipo vende sin demoras.'],
        ['escudo', 'Seguro y confiable', 'Tu información protegida y con respaldo diario.'],
        ['auriculares', 'Soporte cercano', 'Te atendemos por WhatsApp cuando lo necesitas.'],
        ['nube', 'Todo en un solo lugar', 'Ventas, facturación, stock y caja, sin instalar nada.'],
        ['bandera', 'Hecho para el Perú', 'Pensado para SUNAT y para cómo vende tu negocio.'],
    ];

    // ---- planes ----
    $precio = fn ($plan) => rtrim(rtrim(number_format((float) $plan->precio_mensual, 2, '.', ','), '0'), '.');
    $limite = fn ($valor, string $singular, string $plural, string $ilimitado) => $valor === null
        ? $ilimitado
        : number_format((int) $valor, 0, '.', ',').' '.((int) $valor === 1 ? $singular : $plural);
    $destacado = fn ($plan) => $plan->codigo === 'negocio';
    $gratis = fn ($plan) => (float) $plan->precio_mensual <= 0;

    // ---- datos estructurados para buscadores (se arman aquí: en la plantilla, Blade tomaría "@context" por una directiva) ----
    $precios = $planes->pluck('precio_mensual')->map(fn ($p) => (float) $p)->filter(fn ($p) => $p > 0);
    $esquemas = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => "{$url}/#organizacion",
            'name' => $marca,
            'url' => $url,
            'logo' => "{$url}/images/inkapos-logo.png",
            ...($whatsapp || $email ? ['contactPoint' => array_filter([
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'areaServed' => 'PE',
                'availableLanguage' => 'es',
                'telephone' => $whatsapp ? "+{$whatsapp}" : null,
                'email' => $email,
            ])] : []),
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => "{$url}/#sitio",
            'name' => $marca,
            'url' => $url,
            'inLanguage' => 'es-PE',
            'publisher' => ['@id' => "{$url}/#organizacion"],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $marca,
            'url' => $url,
            'description' => $resumen,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => 'es-PE',
            'publisher' => ['@id' => "{$url}/#organizacion"],
            ...($precios->isNotEmpty() ? ['offers' => [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'PEN',
                'lowPrice' => number_format($precios->min(), 2, '.', ''),
                'highPrice' => number_format($precios->max(), 2, '.', ''),
                'offerCount' => $precios->count(),
            ]] : []),
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($q) => [
                '@type' => 'Question',
                'name' => $q[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
            ], $preguntas),
        ],
    ];
    $banderas = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;

    // tipografías que pintan lo primero que se ve: se precargan para que el título no "salte"
    $precargar = [];
    foreach (['montserrat-latin-800-normal', 'poppins-latin-400-normal'] as $fuente) {
        try {
            $precargar[] = \Illuminate\Support\Facades\Vite::asset("resources/fonts/landing/{$fuente}.woff2");
        } catch (\Throwable) {
            // sin manifiesto (o en modo desarrollo) simplemente no se precarga
        }
    }
@endphp
<!DOCTYPE html>
<html lang="es-PE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ $resumen }}">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="{{ $url }}/">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="es_PE">
    <meta property="og:site_name" content="{{ $marca }}">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ $resumen }}">
    <meta property="og:url" content="{{ $url }}/">
    <meta property="og:image" content="{{ $imagenSocial }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $marca }}: sistema POS y facturación electrónica SUNAT">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $titulo }}">
    <meta name="twitter:description" content="{{ $resumen }}">
    <meta name="twitter:image" content="{{ $imagenSocial }}">
    <meta name="theme-color" content="#053C8F">
    {{-- ícono propio de InkaPos, solo en esta página --}}
    <link rel="icon" type="image/svg+xml" href="/images/inkapos-favicon.svg?v=2">
    <link rel="apple-touch-icon" href="/images/inkapos-icono-180.png?v=1">
    @foreach ($precargar as $fuente)
        <link rel="preload" href="{{ $fuente }}" as="font" type="font/woff2" crossorigin>
    @endforeach
    @vite('resources/css/landing.css')
    @foreach ($esquemas as $esquema)
        <script type="application/ld+json">{!! json_encode($esquema, $banderas) !!}</script>
    @endforeach
</head>
<body class="antialiased">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-full focus:bg-white focus:px-5 focus:py-2.5 focus:text-sm focus:font-semibold focus:text-marca focus:shadow-lg">Saltar al contenido</a>

    {{-- ============ Barra superior ============ --}}
    <span id="arriba" class="absolute top-0" aria-hidden="true"></span>
    <header class="sticky top-0 z-40 border-b border-tinta/10 bg-white/95 backdrop-blur-md">
        <div class="mx-auto flex h-[4.5rem] max-w-7xl items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
            <a href="#inicio" aria-label="{{ $marca }}, ir al inicio">
                @include('landing.logo', ['tamano' => 'size-10', 'texto' => 'text-[1.6rem]'])
            </a>

            <nav class="hidden items-center gap-8 text-sm font-medium text-tinta lg:flex" aria-label="Secciones">
                <a href="#inicio" class="border-b-2 border-cian pb-1 text-marca">Inicio</a>
                <a href="#servicios" class="border-b-2 border-transparent pb-1 transition-colors hover:border-cian hover:text-marca">Servicios</a>
                <a href="#planes" class="border-b-2 border-transparent pb-1 transition-colors hover:border-cian hover:text-marca">Planes</a>
                <a href="#por-que" class="border-b-2 border-transparent pb-1 transition-colors hover:border-cian hover:text-marca">Nosotros</a>
                <a href="#contacto" class="border-b-2 border-transparent pb-1 transition-colors hover:border-cian hover:text-marca">Contacto</a>
            </nav>

            <div class="flex items-center gap-2.5">
                <a href="/login" class="hidden h-11 items-center gap-2 rounded-full border-2 border-marca px-5 text-sm font-semibold text-marca transition-colors hover:bg-marca hover:text-white sm:inline-flex">
                    @include('landing.icono', ['n' => 'usuario', 'clase' => 'size-4'])
                    Área de clientes
                </a>
                <a href="/registro" class="inline-flex h-11 items-center gap-2 rounded-full bg-linear-to-r from-cian to-verde px-5 text-sm font-semibold text-white shadow-md shadow-cian/30 transition-opacity hover:opacity-90">
                    Prueba gratis
                </a>

                {{-- menú en celular y tablet: funciona sin JavaScript --}}
                <details class="relative lg:hidden" data-menu>
                    <summary class="grid size-11 place-items-center rounded-full border border-tinta/15 text-tinta transition-colors hover:bg-nube" aria-label="Abrir el menú">
                        @include('landing.icono', ['n' => 'menu', 'clase' => 'size-5'])
                    </summary>
                    <nav class="absolute top-14 right-0 w-64 rounded-2xl border border-tinta/10 bg-white p-2 text-sm font-medium text-tinta shadow-xl shadow-tinta/10" aria-label="Menú">
                        <a href="#servicios" class="block rounded-xl px-4 py-3 hover:bg-nube">Servicios</a>
                        <a href="#funciones" class="block rounded-xl px-4 py-3 hover:bg-nube">Funciones</a>
                        <a href="#planes" class="block rounded-xl px-4 py-3 hover:bg-nube">Planes</a>
                        <a href="#por-que" class="block rounded-xl px-4 py-3 hover:bg-nube">Nosotros</a>
                        <a href="#preguntas" class="block rounded-xl px-4 py-3 hover:bg-nube">Preguntas</a>
                        <a href="#contacto" class="block rounded-xl px-4 py-3 hover:bg-nube">Contacto</a>
                        <a href="/login" class="mt-1 flex items-center gap-2 rounded-xl border-t border-tinta/10 px-4 py-3 font-semibold text-marca hover:bg-nube sm:hidden">@include('landing.icono', ['n' => 'usuario', 'clase' => 'size-4']) Área de clientes</a>
                    </nav>
                </details>
            </div>
        </div>
    </header>

    <main id="contenido">
        {{-- ============ Portada ============ --}}
        <section id="inicio" class="relative overflow-hidden bg-linear-to-br from-tinta via-marca-fuerte to-marca text-white">
            {{-- ondas de color, decorativas --}}
            <svg class="pointer-events-none absolute -top-10 right-0 hidden h-[130%] w-[62%] lg:block" viewBox="0 0 800 700" fill="none" preserveAspectRatio="none" aria-hidden="true">
                <path d="M330 0C250 160 420 300 300 470C240 560 250 640 300 700H800V0Z" fill="url(#onda-a)" opacity=".28"/>
                <path d="M420 0C350 170 520 310 400 480C345 565 360 640 410 700H800V0Z" fill="url(#onda-b)" opacity=".45"/>
                <defs>
                    <linearGradient id="onda-a" x1="300" y1="0" x2="800" y2="700" gradientUnits="userSpaceOnUse"><stop stop-color="#00B1C3"/><stop offset="1" stop-color="#08AA81"/></linearGradient>
                    <linearGradient id="onda-b" x1="400" y1="0" x2="800" y2="700" gradientUnits="userSpaceOnUse"><stop stop-color="#00B1C3"/><stop offset="1" stop-color="#053C8F"/></linearGradient>
                </defs>
            </svg>
            <div class="pointer-events-none absolute -bottom-40 -left-32 size-[28rem] rounded-full bg-cian/15 blur-3xl" aria-hidden="true"></div>

            <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 py-14 sm:px-6 sm:py-16 lg:grid-cols-[1fr_1.05fr] lg:gap-8 lg:px-8 lg:py-20">
                <div>
                    <p class="text-xs font-semibold tracking-[0.2em] uppercase sm:text-[13px]">
                        <span class="text-cian">Punto de venta</span> <span class="text-white/60">+</span> Facturación electrónica <span class="text-white/60">+</span> Inventario
                    </p>
                    <h1 class="mt-4 text-[2.15rem] leading-[1.1] font-extrabold tracking-tight sm:text-5xl lg:text-[3.2rem]">
                        Sistema POS y <span class="bg-linear-to-r from-cian to-[#5fe0c0] bg-clip-text text-transparent">facturación electrónica</span> para tu negocio
                    </h1>
                    <p class="mt-5 max-w-xl text-base leading-relaxed text-white/85 sm:text-lg">
                        Con {{ $marca }} vendes, emites boletas y facturas a SUNAT y controlas tu stock y tu caja,
                        todo en un solo lugar. En la computadora o en el celular, sin instalar nada.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="/registro" class="inline-flex h-13 items-center justify-center gap-2.5 rounded-full bg-linear-to-r from-cian to-verde px-7 text-base font-semibold text-white shadow-lg shadow-cian/30 transition-opacity hover:opacity-90">
                            @include('landing.icono', ['n' => 'recibo', 'clase' => 'size-5'])
                            Empieza gratis {{ $diasPrueba }} días
                        </a>
                        @if ($enlaceWhatsapp)
                            <a href="{{ $enlaceWhatsapp }}" target="_blank" rel="noopener" class="inline-flex h-13 items-center justify-center gap-2.5 rounded-full border-2 border-white/80 px-7 text-base font-semibold text-white transition-colors hover:bg-white hover:text-marca">
                                @include('landing.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])
                                Solicitar una demo
                            </a>
                        @endif
                    </div>

                    <ul class="mt-7 flex flex-col gap-2.5 text-sm text-white/85 sm:flex-row sm:flex-wrap sm:gap-x-6">
                        @foreach (['Sin tarjeta de crédito', 'Lista en minutos con tu RUC', 'Soporte por WhatsApp'] as $ventaja)
                            <li class="flex items-center gap-2">
                                <span class="grid size-5 shrink-0 place-items-center rounded-full bg-verde text-white">@include('landing.icono', ['n' => 'check', 'clase' => 'size-3.5', 'grosor' => 3])</span>
                                {{ $ventaja }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Captura real del POS en una laptop, con la boleta y el celular flotando encima --}}
                <div class="relative mx-auto w-full max-w-lg pb-16 lg:max-w-none lg:pr-6" aria-hidden="true">
                    {{-- laptop --}}
                    <div class="overflow-hidden rounded-t-2xl border-[10px] border-b-0 border-tinta bg-tinta shadow-2xl shadow-black/40">
                        <img src="/images/landing-pos.webp?v=1" width="1400" height="617" alt="Pantalla de punto de venta de {{ $marca }} con productos y carrito"
                            class="block h-auto w-full" fetchpriority="high" decoding="async">
                    </div>
                    <div class="mx-[-4%] h-3 rounded-b-2xl bg-linear-to-b from-[#c9d3dc] to-[#9aa8b5]"></div>

                    {{-- boleta aceptada --}}
                    <div class="absolute bottom-0 -left-2 w-44 rounded-2xl bg-white p-3.5 text-tinta shadow-xl shadow-black/30 sm:-left-6 sm:w-52">
                        <p class="text-[10px] font-semibold tracking-wide text-tinta/60 uppercase">Boleta electrónica</p>
                        <p class="font-display text-sm font-bold">B001-00042381</p>
                        <p class="mt-2 flex items-center gap-1.5 rounded-lg bg-verde/12 px-2 py-1.5 text-[11px] font-semibold text-verde">
                            @include('landing.icono', ['n' => 'check', 'clase' => 'size-3.5', 'grosor' => 3])
                            Aceptada por SUNAT
                        </p>
                    </div>

                    {{-- celular --}}
                    <div class="absolute -right-1 bottom-0 w-24 rounded-[1.1rem] border-[5px] border-tinta bg-white p-2 text-tinta shadow-xl shadow-black/40 sm:w-28 lg:-right-2">
                        <p class="text-[8px] font-semibold text-tinta/60">Ventas de hoy</p>
                        <p class="font-display text-sm font-extrabold">S/ 1,284</p>
                        <div class="mt-2 flex h-10 items-end gap-1">
                            @foreach ([40, 65, 50, 80, 60, 100] as $alto)
                                <span class="flex-1 rounded-sm bg-linear-to-t from-marca to-cian" style="height: {{ $alto }}%"></span>
                            @endforeach
                        </div>
                        <span class="mt-2 block rounded-md bg-verde py-1 text-center text-[8px] font-semibold text-white">Ver reporte</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Servicios ============ --}}
        <section id="servicios" class="bg-nube py-14 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="text-center">
                    <p class="text-xs font-semibold tracking-[0.3em] text-marca uppercase sm:text-sm">Nuestros servicios</p>
                    <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight text-tinta sm:text-4xl">Soluciones que <span class="bg-linear-to-r from-cian to-verde bg-clip-text text-transparent">impulsan tu negocio</span></h2>
                </div>

                <div class="mt-10 grid gap-5 lg:grid-cols-3">
                    @foreach ($servicios as [$icono, $nombre, $texto, $puntos, $boton, $destino, $dibujo])
                        <article class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm shadow-tinta/8 sm:flex-row lg:flex-col xl:flex-row">
                            {{-- dibujo del servicio, decorativo --}}
                            <div class="relative grid min-h-36 shrink-0 place-items-center overflow-hidden bg-linear-to-br from-marca to-tinta p-5 text-white sm:w-44 lg:w-auto xl:w-40" aria-hidden="true">
                                <div class="absolute -top-10 -right-10 size-32 rounded-full bg-cian/30 blur-2xl"></div>
                                @if ($dibujo === 'ventas')
                                    <div class="relative w-32 rounded-xl bg-white p-2.5 text-tinta shadow-lg">
                                        <p class="flex justify-between text-[9px]"><span>2× Arroz</span><b>49.80</b></p>
                                        <p class="mt-1 flex justify-between text-[9px]"><span>1× Aceite</span><b>10.50</b></p>
                                        <p class="mt-1.5 border-t border-dashed border-tinta/20 pt-1 text-right font-display text-xs font-extrabold">S/ 60.30</p>
                                        <span class="mt-1.5 block rounded-md bg-marca py-1 text-center text-[9px] font-semibold text-white">Cobrar</span>
                                    </div>
                                @elseif ($dibujo === 'factura')
                                    <div class="relative w-32 rounded-xl bg-white p-2.5 text-tinta shadow-lg">
                                        <p class="text-[8px] font-semibold tracking-wide text-tinta/60 uppercase">Factura electrónica</p>
                                        <p class="font-display text-[11px] font-bold">F001-00001245</p>
                                        <div class="mt-1.5 space-y-1"><span class="block h-1 rounded bg-tinta/10"></span><span class="block h-1 w-4/5 rounded bg-tinta/10"></span><span class="block h-1 w-3/5 rounded bg-tinta/10"></span></div>
                                        <p class="mt-2 flex items-center gap-1 rounded-md bg-verde/15 px-1.5 py-1 text-[8px] font-semibold text-verde">@include('landing.icono', ['n' => 'check', 'clase' => 'size-3', 'grosor' => 3]) Aceptada por SUNAT</p>
                                    </div>
                                @else
                                    <div class="relative w-32 rounded-xl bg-white p-2.5 text-tinta shadow-lg">
                                        <p class="font-display text-[10px] font-extrabold">Mi Tienda</p>
                                        <div class="mt-1.5 grid grid-cols-2 gap-1.5">
                                            <span class="grid aspect-square place-items-center rounded-md bg-nube text-marca/40">@include('landing.icono', ['n' => 'paquete', 'clase' => 'size-4', 'grosor' => 1.5])</span>
                                            <span class="grid aspect-square place-items-center rounded-md bg-nube text-marca/40">@include('landing.icono', ['n' => 'paquete', 'clase' => 'size-4', 'grosor' => 1.5])</span>
                                        </div>
                                        <span class="mt-1.5 flex items-center justify-center gap-1 rounded-md bg-verde py-1 text-[8px] font-semibold text-white">@include('landing.icono', ['n' => 'whatsapp', 'clase' => 'size-2.5']) Pedir</span>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-5 sm:p-6">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-linear-to-br from-cian to-verde text-white">@include('landing.icono', ['n' => $icono, 'clase' => 'size-6'])</span>
                                    <h3 class="text-lg leading-tight font-bold text-marca">{{ $nombre }}</h3>
                                </div>
                                <p class="mt-3 text-sm leading-relaxed text-tinta/75">{{ $texto }}</p>
                                <ul class="mt-3 flex-1 space-y-2 text-sm text-tinta/85">
                                    @foreach ($puntos as $punto)
                                        <li class="flex items-start gap-2">
                                            <span class="mt-0.5 grid size-4.5 shrink-0 place-items-center rounded-full bg-verde text-white">@include('landing.icono', ['n' => 'check', 'clase' => 'size-3', 'grosor' => 3.5])</span>
                                            {{ $punto }}
                                        </li>
                                    @endforeach
                                </ul>
                                <a href="{{ $destino }}" class="mt-5 inline-flex h-10 w-fit items-center gap-2 rounded-full bg-linear-to-r from-cian to-verde px-5 text-sm font-semibold text-white transition-opacity hover:opacity-90">
                                    {{ $boton }}
                                    @include('landing.icono', ['n' => 'flecha', 'clase' => 'size-4'])
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Por qué elegirnos ============ --}}
        <section id="por-que" class="bg-white py-14 sm:py-20">
            <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[1fr_2.2fr] lg:items-center lg:px-8">
                <div>
                    <p class="text-xs font-semibold tracking-[0.25em] text-marca uppercase sm:text-sm">¿Por qué elegir {{ $marca }}?</p>
                    <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight text-marca sm:text-[2.1rem]">Tecnología, confianza y soporte siempre</h2>
                    <p class="mt-4 text-base leading-relaxed text-tinta/75">No solo te damos un sistema: te acompañamos para que tu negocio venda más y esté siempre en orden.</p>
                </div>
                <ul class="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-5 lg:gap-x-0 lg:divide-x lg:divide-tinta/10">
                    @foreach ($razones as [$icono, $nombre, $texto])
                        <li class="flex flex-col items-center px-2 text-center lg:px-4">
                            <span class="grid size-14 place-items-center rounded-full bg-nube text-marca">@include('landing.icono', ['n' => $icono, 'clase' => 'size-7', 'grosor' => 1.6])</span>
                            <h3 class="mt-3 text-sm leading-snug font-bold text-tinta">{{ $nombre }}</h3>
                            <p class="mt-1.5 text-[13px] leading-relaxed text-tinta/70">{{ $texto }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- ============ Funciones ============ --}}
        <section id="funciones" class="bg-nube py-14 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="text-center">
                    <p class="text-xs font-semibold tracking-[0.3em] text-marca uppercase sm:text-sm">Funciones</p>
                    <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight text-tinta sm:text-4xl">Todo lo que necesitas para <span class="bg-linear-to-r from-cian to-verde bg-clip-text text-transparent">vender y facturar</span></h2>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($funciones as [$icono, $nombre, $texto])
                        <article class="flex gap-4 rounded-2xl bg-white p-5 shadow-sm shadow-tinta/8">
                            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-marca/8 text-marca">@include('landing.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                            <div class="min-w-0">
                                <h3 class="text-base font-bold text-tinta">{{ $nombre }}</h3>
                                <p class="mt-1.5 text-sm leading-relaxed text-tinta/70">{{ $texto }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Tienda en línea ============ --}}
        <section id="tienda-en-linea" class="relative overflow-hidden bg-linear-to-br from-tinta via-marca-fuerte to-marca py-14 text-white sm:py-20">
            <div class="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full bg-cian/20 blur-3xl" aria-hidden="true"></div>
            <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div>
                    <p class="text-xs font-semibold tracking-[0.3em] text-cian uppercase sm:text-sm">Tienda en línea</p>
                    <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight sm:text-4xl">Tu catálogo en internet, <span class="text-cian">conectado a tu stock</span></h2>
                    <p class="mt-4 text-base leading-relaxed text-white/80">
                        Muestra tus productos en una página con tu marca. Tus clientes arman su pedido y te lo envían por WhatsApp,
                        con los precios y el stock que ya tienes en el sistema.
                    </p>
                    <ul class="mt-7 space-y-4 text-sm">
                        @foreach ([
                            ['mundo', 'Con tu propio dominio', 'Tu tienda puede vivir en tudominio.com, con tu logo, tus colores y tus banners.'],
                            ['whatsapp', 'Pedidos por WhatsApp', 'El cliente elige, arma su pedido y te llega listo para confirmar. Sin comisiones por venta.'],
                            ['chat', 'Asistente que responde por ti', 'Un asistente con inteligencia artificial contesta las consultas sobre tus productos a cualquier hora.'],
                        ] as [$icono, $nombre, $texto])
                            <li class="flex gap-4">
                                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white/10 text-cian">@include('landing.icono', ['n' => $icono, 'clase' => 'size-5'])</span>
                                <span>
                                    <span class="block font-display text-base font-bold">{{ $nombre }}</span>
                                    <span class="mt-1 block leading-relaxed text-white/75">{{ $texto }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Captura real de una tienda en línea, en el celular --}}
                <div class="mx-auto w-full max-w-sm" aria-hidden="true">
                    <div class="rounded-[2rem] border border-white/15 bg-white/5 p-3">
                        <img src="/images/landing-tienda.webp?v=1" width="422" height="837" alt="Tienda en línea de {{ $marca }} vista desde el celular"
                            class="block h-auto w-full rounded-[1.5rem] bg-white" loading="lazy" decoding="async">
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Planes ============ --}}
        @if ($planes->isNotEmpty())
            <section id="planes" class="bg-white py-14 sm:py-20">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-xs font-semibold tracking-[0.3em] text-marca uppercase sm:text-sm">Planes</p>
                        <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight text-tinta sm:text-4xl">Precios simples, <span class="bg-linear-to-r from-cian to-verde bg-clip-text text-transparent">en soles</span></h2>
                        <p class="mt-4 text-base leading-relaxed text-tinta/70">Todos los planes empiezan con {{ $diasPrueba }} días gratis y todas las funciones. Cambia de plan cuando tu negocio crezca.</p>
                    </div>

                    <div @class(['mx-auto mt-12 grid max-w-md gap-6', 'md:max-w-3xl md:grid-cols-2 xl:max-w-none xl:grid-cols-4 xl:gap-5' => $planes->count() >= 4, 'lg:max-w-6xl lg:grid-cols-3' => $planes->count() === 3, 'lg:max-w-3xl lg:grid-cols-2' => $planes->count() === 2])>
                        @foreach ($planes as $plan)
                            <article @class([
                                'relative flex flex-col rounded-3xl p-7 xl:p-6',
                                'bg-linear-to-br from-marca to-tinta text-white shadow-2xl shadow-marca/30 xl:-my-3 xl:py-9' => $destacado($plan),
                                'bg-nube text-tinta' => ! $destacado($plan),
                            ])>
                                @if ($destacado($plan))
                                    <p class="absolute -top-3 left-7 rounded-full bg-linear-to-r from-cian to-verde px-3.5 py-1 text-xs font-semibold text-white">El más elegido</p>
                                @endif
                                <h3 @class(['text-lg font-bold', 'text-marca' => ! $destacado($plan)])>{{ $plan->nombre }}</h3>
                                @if ($plan->descripcion)
                                    <p @class(['mt-1.5 text-sm leading-relaxed', 'text-white/75' => $destacado($plan), 'text-tinta/70' => ! $destacado($plan)])>{{ $plan->descripcion }}</p>
                                @endif
                                <p class="mt-6 flex items-baseline gap-1.5">
                                    <span class="font-display text-4xl font-extrabold tracking-tight tabular-nums">{{ $gratis($plan) ? 'Gratis' : 'S/ '.$precio($plan) }}</span>
                                    <span @class(['text-sm', 'text-white/75' => $destacado($plan), 'text-tinta/60' => ! $destacado($plan)])>{{ $gratis($plan) ? "por {$diasPrueba} días" : 'al mes' }}</span>
                                </p>
                                <ul class="mt-6 flex-1 space-y-3 text-sm">
                                    @foreach ([
                                        $limite($plan->max_sucursales, 'sucursal', 'sucursales', 'Sucursales ilimitadas'),
                                        $limite($plan->max_usuarios, 'usuario', 'usuarios', 'Usuarios ilimitados'),
                                        $plan->max_comprobantes_mes === null ? 'Comprobantes ilimitados' : $limite($plan->max_comprobantes_mes, 'comprobante', 'comprobantes', '').' al mes',
                                        'Facturación electrónica SUNAT',
                                        'Soporte por WhatsApp',
                                    ] as $incluye)
                                        <li class="flex items-start gap-2.5">
                                            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-verde text-white">@include('landing.icono', ['n' => 'check', 'clase' => 'size-3.5', 'grosor' => 3])</span>
                                            {{ $incluye }}
                                        </li>
                                    @endforeach
                                </ul>
                                <a href="/registro" @class([
                                    'mt-8 inline-flex h-12 items-center justify-center gap-2 rounded-full px-5 text-sm font-semibold transition-opacity hover:opacity-90',
                                    'bg-linear-to-r from-cian to-verde text-white' => $destacado($plan),
                                    'bg-marca text-white' => ! $destacado($plan),
                                ])>{{ $gratis($plan) ? 'Empezar gratis' : "Probar gratis {$diasPrueba} días" }} @include('landing.icono', ['n' => 'flecha', 'clase' => 'size-4'])</a>
                            </article>
                        @endforeach
                    </div>
                    <p class="mt-10 text-center text-sm text-tinta/60">Precios en soles. La tienda en línea se activa aparte: consúltanos.</p>
                </div>
            </section>
        @endif

        {{-- ============ Preguntas frecuentes ============ --}}
        <section id="preguntas" class="bg-nube py-14 sm:py-20">
            <div class="mx-auto max-w-3xl px-4 sm:px-6">
                <div class="text-center">
                    <p class="text-xs font-semibold tracking-[0.3em] text-marca uppercase sm:text-sm">Preguntas frecuentes</p>
                    <h2 class="mt-3 text-3xl leading-tight font-bold tracking-tight text-tinta sm:text-4xl">Resolvemos <span class="bg-linear-to-r from-cian to-verde bg-clip-text text-transparent">tus dudas</span></h2>
                </div>
                <div class="mt-10 space-y-3">
                    @foreach ($preguntas as [$pregunta, $respuesta])
                        <details class="group rounded-2xl bg-white shadow-sm shadow-tinta/8">
                            <summary class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6">
                                <h3 class="font-sans text-base font-semibold text-tinta">{{ $pregunta }}</h3>
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-nube text-marca transition-transform group-open:rotate-180">@include('landing.icono', ['n' => 'abajo', 'clase' => 'size-4'])</span>
                            </summary>
                            <p class="px-5 pb-5 text-sm leading-relaxed text-tinta/75 sm:px-6">{{ $respuesta }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Franja de contacto ============ --}}
        <section id="contacto" class="bg-white px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
            <div class="relative mx-auto max-w-7xl overflow-hidden rounded-[2rem] bg-linear-to-r from-tinta via-marca-fuerte to-marca px-6 py-9 text-white sm:px-10 lg:rounded-full lg:px-14 lg:py-8">
                <div class="pointer-events-none absolute -top-24 right-10 size-72 rounded-full bg-cian/25 blur-3xl" aria-hidden="true"></div>
                <div class="relative flex flex-col items-start gap-6 lg:flex-row lg:items-center lg:gap-8">
                    <span class="hidden size-14 shrink-0 place-items-center rounded-full bg-white/10 text-cian lg:grid">@include('landing.icono', ['n' => 'recibo', 'clase' => 'size-7', 'grosor' => 1.8])</span>
                    <div class="flex-1">
                        <h2 class="text-2xl leading-tight font-bold tracking-tight sm:text-[1.7rem]">¿Listo para llevar tu negocio <span class="text-cian">al siguiente nivel?</span></h2>
                        <p class="mt-1.5 text-sm text-white/85 sm:text-base">Contáctanos <span class="text-cian">y recibe asesoría personalizada.</span></p>
                    </div>
                    <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                        @if ($enlaceWhatsapp)
                            <a href="{{ $enlaceWhatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center justify-center gap-2.5 rounded-full bg-verde px-6 text-sm font-semibold text-white shadow-lg shadow-black/20 transition-opacity hover:opacity-90">
                                @include('landing.icono', ['n' => 'whatsapp', 'clase' => 'size-5'])
                                Escríbenos por WhatsApp
                                @include('landing.icono', ['n' => 'flecha', 'clase' => 'size-4'])
                            </a>
                        @endif
                        <a href="/registro" class="inline-flex h-12 items-center justify-center rounded-full border-2 border-white/80 px-6 text-sm font-semibold text-white transition-colors hover:bg-white hover:text-marca">Crear mi cuenta gratis</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    {{-- ============ Pie ============ --}}
    <footer class="bg-tinta text-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-9 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <div>
                @include('landing.logo', ['claro' => true, 'tamano' => 'size-9', 'texto' => 'text-2xl'])
                <p class="mt-3 max-w-xs text-sm leading-relaxed text-white/70">Sistema de punto de venta y facturación electrónica SUNAT para negocios del Perú.</p>
            </div>
            <nav aria-label="Enlaces del sitio">
                <ul class="flex flex-wrap gap-x-7 gap-y-3 text-sm text-white/80">
                    <li><a href="#inicio" class="transition-colors hover:text-cian">Inicio</a></li>
                    <li><a href="#servicios" class="transition-colors hover:text-cian">Servicios</a></li>
                    <li><a href="#funciones" class="transition-colors hover:text-cian">Funciones</a></li>
                    <li><a href="#planes" class="transition-colors hover:text-cian">Planes</a></li>
                    <li><a href="#preguntas" class="transition-colors hover:text-cian">Preguntas</a></li>
                    <li><a href="/login" class="transition-colors hover:text-cian">Área de clientes</a></li>
                </ul>
            </nav>
            <div class="flex items-center gap-4">
                <ul class="flex flex-col gap-2 text-sm text-white/80">
                    @if ($enlaceWhatsapp)
                        <li><a href="{{ $enlaceWhatsapp }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 transition-colors hover:text-cian">@include('landing.icono', ['n' => 'whatsapp', 'clase' => 'size-4']) WhatsApp</a></li>
                    @endif
                    @if ($email)
                        <li><a href="mailto:{{ $email }}" class="inline-flex items-center gap-2 break-all transition-colors hover:text-cian">@include('landing.icono', ['n' => 'correo', 'clase' => 'size-4 shrink-0']) {{ $email }}</a></li>
                    @endif
                </ul>
                <a href="#arriba" class="grid size-11 shrink-0 place-items-center rounded-full bg-marca text-white transition-colors hover:bg-cian" aria-label="Volver arriba">@include('landing.icono', ['n' => 'arriba', 'clase' => 'size-5'])</a>
            </div>
        </div>
        <div class="border-t border-white/10">
            <p class="mx-auto max-w-7xl px-4 py-4 text-xs text-white/60 sm:px-6 lg:px-8">© {{ date('Y') }} {{ $marca }} · Punto de venta <span class="text-cian">+</span> Facturación electrónica <span class="text-cian">+</span> Inventario · Hecho en Perú</p>
        </div>
    </footer>

    {{-- el menú de celular se cierra al elegir una sección (sin esto también funciona, solo queda abierto) --}}
    <script>
        document.querySelectorAll('[data-menu]').forEach((menu) => {
            menu.querySelectorAll('a').forEach((enlace) => enlace.addEventListener('click', () => menu.removeAttribute('open')))
            document.addEventListener('click', (e) => { if (!menu.contains(e.target)) menu.removeAttribute('open') })
        })
    </script>
</body>
</html>
