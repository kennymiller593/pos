<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { useDark, useToggle } from '@vueuse/core'
import {
    ArrowRight, ArrowRightLeft, BarChart3, Boxes, CheckCircle2, ChevronDown, FileCheck2, FileKey2, HandCoins,
    Mail, MessageCircle, Moon, Printer, Receipt, ShieldCheck, ShoppingCart, Sun, Truck, UserPlus, Wallet,
} from '@lucide/vue'

const props = defineProps({
    planes: { type: Array, default: () => [] },
    diasPrueba: { type: Number, default: 14 },
    contacto: { type: Object, default: () => ({ whatsapp: null, email: null }) },
})

const page = usePage()
const marca = computed(() => page.props.appName || 'POS App')

const esOscuro = useDark()
const alternarTema = useToggle(esOscuro)

// Desplazamiento suave para los enlaces ancla (solo mientras la landing está montada)
onMounted(() => document.documentElement.classList.add('scroll-smooth'))
onBeforeUnmount(() => document.documentElement.classList.remove('scroll-smooth'))

const anio = new Date().getFullYear()

const whatsapp = computed(() => String(props.contacto?.whatsapp ?? '').replace(/\D/g, ''))
const email = computed(() => props.contacto?.email || null)

const funciones = [
    { icon: FileCheck2, titulo: 'Facturación electrónica SUNAT', texto: 'Boletas, facturas y notas de crédito con envío automático a SUNAT y reintentos si algo falla.' },
    { icon: Boxes, titulo: 'Inventario FIFO y lotes', texto: 'Costeo FIFO real, lotes con fecha de vencimiento y alertas de stock bajo.' },
    { icon: ArrowRightLeft, titulo: 'Multi-sucursal', texto: 'Stock por sucursal y transferencias de mercadería entre tus locales.' },
    { icon: Wallet, titulo: 'Caja y arqueo', texto: 'Apertura, cierre y arqueo de caja con efectivo, Yape, Plin y tarjeta.' },
    { icon: HandCoins, titulo: 'Crédito y cuentas', texto: 'Ventas al crédito, cuentas por cobrar a clientes y por pagar a proveedores.' },
    { icon: Truck, titulo: 'Compras y proveedores', texto: 'Registra tus compras, actualiza costos y lleva el historial de cada proveedor.' },
    { icon: BarChart3, titulo: 'Reportes', texto: 'Ventas, margen, kardex y registro de ventas listo para tu contador.' },
    { icon: ShieldCheck, titulo: 'Roles y permisos', texto: 'Administrador, cajero, vendedor y almacenero: cada uno ve solo lo suyo.' },
    { icon: Printer, titulo: 'Impresión y correo', texto: 'Imprime en ticketera térmica y envía el comprobante por correo al cliente.' },
]

const pasos = [
    { icon: UserPlus, titulo: 'Regístrate con tu RUC', texto: 'Traemos los datos de tu empresa desde SUNAT. En minutos tienes tu cuenta lista.' },
    { icon: FileKey2, titulo: 'Carga tu certificado y productos', texto: 'Sube tu certificado digital, tus productos y el stock inicial de cada sucursal.' },
    { icon: ShoppingCart, titulo: 'Vende y factura', texto: 'Cobra desde el POS y el comprobante sale a SUNAT solo. Tú te enfocas en vender.' },
]

const preguntas = [
    { p: '¿Necesito certificado digital?', r: 'Sí. Para emitir comprobantes electrónicos válidos necesitas tu certificado digital, que se carga en la sección Empresa. Mientras tanto puedes probar todo en el ambiente beta de SUNAT, sin valor tributario.' },
    { p: '¿Funciona sin internet?', r: 'No, por ahora el sistema requiere conexión a internet para vender y enviar los comprobantes a SUNAT.' },
    { p: '¿Puedo imprimir en impresora térmica?', r: 'Sí. Los comprobantes tienen formato para ticketeras térmicas de 58 mm y 80 mm, además de A4.' },
    { p: '¿Qué pasa cuando termina la prueba?', r: 'Tienes 3 días de gracia para elegir un plan. Tus datos no se borran: cuando activas el plan sigues exactamente donde lo dejaste.' },
    { p: '¿Puedo tener varias sucursales?', r: 'Sí. Cada sucursal tiene su propio stock, caja y series de comprobantes, y puedes transferir mercadería entre ellas. La cantidad depende del plan.' },
]

// ---- planes ----
function precio(p) {
    const n = Number(p.precio_mensual ?? 0)
    return Number.isInteger(n) ? String(n) : n.toFixed(2)
}
function limite(valor, singular, plural, ilimitado) {
    if (valor === null || valor === undefined) return ilimitado
    const n = Number(valor)
    return `${n.toLocaleString('es-PE')} ${n === 1 ? singular : plural}`
}
function limiteComprobantes(valor) {
    if (valor === null || valor === undefined) return 'Comprobantes ilimitados'
    const n = Number(valor)
    return `${n.toLocaleString('es-PE')} ${n === 1 ? 'comprobante' : 'comprobantes'} al mes`
}
const destacado = (p) => p.codigo === 'negocio'
</script>

<template>
    <Head title="POS y facturación electrónica para tu negocio" />

    <div class="min-h-screen bg-[#F5F9F6] text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">

        <!-- ============ Barra superior ============ -->
        <header
            class="sticky top-0 z-30 border-b border-stone-200/70 bg-[#F5F9F6]/85 backdrop-blur-md dark:border-neutral-800/70 dark:bg-neutral-950/85">
            <nav class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <a href="#inicio" class="flex items-center gap-2.5">
                    <span class="grid size-9 place-items-center rounded-xl bg-emerald-600 text-white shadow-md shadow-emerald-600/25">
                        <Receipt class="size-5" />
                    </span>
                    <span class="font-display text-lg font-bold tracking-tight">{{ marca }}</span>
                </a>

                <div class="hidden items-center gap-7 text-sm font-medium text-neutral-600 md:flex dark:text-neutral-300">
                    <a href="#funciones" class="transition-colors hover:text-emerald-600 dark:hover:text-emerald-400">Funciones</a>
                    <a href="#planes" class="transition-colors hover:text-emerald-600 dark:hover:text-emerald-400">Planes</a>
                    <a href="#preguntas" class="transition-colors hover:text-emerald-600 dark:hover:text-emerald-400">Preguntas</a>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" :title="esOscuro ? 'Modo claro' : 'Modo oscuro'"
                        class="grid size-10 place-items-center rounded-xl text-neutral-500 transition-colors hover:bg-stone-200/60 hover:text-neutral-800 focus-visible:ring-2 focus-visible:ring-emerald-400 focus-visible:outline-none dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        @click="alternarTema()">
                        <Sun v-if="esOscuro" class="size-4" />
                        <Moon v-else class="size-4" />
                    </button>
                    <Link href="/login"
                        class="hidden h-10 items-center rounded-xl px-4 text-sm font-semibold text-neutral-700 transition-colors hover:bg-stone-200/60 sm:inline-flex dark:text-neutral-200 dark:hover:bg-neutral-800">
                        Ingresar
                    </Link>
                    <Link href="/registro"
                        class="inline-flex h-10 items-center rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white shadow-md shadow-emerald-600/25 transition-colors hover:bg-emerald-500">
                        Prueba gratis
                    </Link>
                </div>
            </nav>
        </header>

        <main>
            <!-- ============ Hero ============ -->
            <section id="inicio" class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute -top-40 -right-40 size-[36rem] rounded-full bg-emerald-400/20 blur-[120px] dark:bg-emerald-500/15" />
                <div
                    class="pointer-events-none absolute -bottom-52 -left-40 size-[30rem] rounded-full bg-[#4078F5]/10 blur-[120px]" />

                <div class="relative mx-auto grid max-w-6xl items-center gap-14 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                    <div class="anim">
                        <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-400">
                            &lt;punto_de_venta /&gt; · hecho en Perú
                        </p>
                        <h1 class="font-display mt-4 text-4xl leading-[1.08] font-bold tracking-tight text-balance sm:text-5xl">
                            Vende, factura a SUNAT y controla tu stock
                            <span class="bg-gradient-to-r from-emerald-600 to-[#3B6FE0] bg-clip-text text-transparent dark:from-emerald-400 dark:to-[#5B8DF7]">desde un solo lugar.</span>
                        </h1>
                        <p class="mt-5 max-w-xl text-lg leading-relaxed text-neutral-600 dark:text-neutral-400">
                            Boletas y facturas electrónicas, inventario, caja y reportes para bodegas, minimarkets,
                            ferreterías, farmacias y tiendas.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <Link href="/registro"
                                class="group inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-500 focus-visible:ring-4 focus-visible:ring-emerald-400/40 focus-visible:outline-none">
                                Empieza gratis {{ diasPrueba }} días
                                <ArrowRight class="size-4 transition-transform group-hover:translate-x-0.5" />
                            </Link>
                            <Link href="/login"
                                class="inline-flex h-12 items-center justify-center rounded-xl border border-stone-300 bg-white px-6 text-sm font-semibold text-neutral-800 transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-800">
                                Ya tengo cuenta
                            </Link>
                        </div>
                        <p class="mt-4 text-sm text-neutral-500 dark:text-neutral-400">
                            Sin tarjeta. Sin instalación. Funciona en cualquier navegador.
                        </p>
                    </div>

                    <!-- Ilustración: boleta + chip de ventas -->
                    <div class="anim d2 flex justify-center lg:justify-end" aria-hidden="true">
                        <div class="relative">
                            <div
                                class="absolute -top-6 -left-10 hidden w-56 -rotate-6 rounded-2xl border border-stone-200 bg-white p-4 shadow-xl shadow-stone-900/5 sm:block dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-black/40">
                                <p class="text-[11px] font-semibold tracking-wider text-neutral-400 uppercase">Venta #1287</p>
                                <div class="mt-2 space-y-1.5 text-xs">
                                    <div class="flex justify-between"><span>Arroz Costeño 5kg</span><span class="tabular-nums">24.90</span></div>
                                    <div class="flex justify-between"><span>Aceite Primor 1L</span><span class="tabular-nums">10.50</span></div>
                                </div>
                                <div class="mt-3 flex gap-1.5">
                                    <span class="rounded-md bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">Yape</span>
                                    <span class="rounded-md bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">Plin</span>
                                    <span class="rounded-md bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">Tarjeta</span>
                                </div>
                            </div>

                            <div class="ticket relative w-72 rotate-2 bg-[#FAFAF8] px-6 pt-6 pb-9 text-neutral-800 shadow-2xl shadow-stone-900/15 dark:shadow-black/60">
                                <div class="font-receipt text-[11.5px] leading-relaxed">
                                    <p class="text-center font-semibold tracking-wide">MINIMARKET SAN JOSÉ</p>
                                    <p class="text-center text-neutral-500">RUC 20601234567</p>
                                    <p class="text-center text-neutral-500">BOLETA ELECTRÓNICA</p>
                                    <p class="text-center text-neutral-500">B001-00042381</p>
                                    <div class="my-2.5 border-t border-dashed border-neutral-300" />
                                    <div class="flex justify-between"><span>INCA KOLA 500ML</span><span>3.50</span></div>
                                    <div class="flex justify-between"><span>PAN FRANCÉS x6</span><span>2.40</span></div>
                                    <div class="flex justify-between"><span>LECHE GLORIA x2</span><span>9.80</span></div>
                                    <div class="my-2.5 border-t border-dashed border-neutral-300" />
                                    <div class="flex justify-between text-neutral-500"><span>OP. GRAVADA</span><span>13.31</span></div>
                                    <div class="flex justify-between text-neutral-500"><span>IGV 18%</span><span>2.39</span></div>
                                    <div class="mt-1.5 flex justify-between text-[13px] font-bold"><span>TOTAL S/</span><span>15.70</span></div>
                                    <div class="mt-3 flex items-center justify-center gap-1.5 text-emerald-700">
                                        <CheckCircle2 class="size-3.5" />
                                        <span class="font-medium">ACEPTADA POR SUNAT</span>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="absolute -right-6 -bottom-5 flex -rotate-2 items-center gap-2.5 rounded-2xl border border-stone-200 bg-white/95 px-4 py-3 shadow-xl shadow-stone-900/10 backdrop-blur-md dark:border-white/10 dark:bg-neutral-900/90 dark:shadow-black/40">
                                <div class="grid size-8 place-items-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400">
                                    <BarChart3 class="size-4" />
                                </div>
                                <div class="leading-tight">
                                    <p class="font-receipt text-sm font-bold tracking-tight">S/ 1,240.50</p>
                                    <p class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Ventas de hoy</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ Funciones ============ -->
            <section id="funciones" class="scroll-mt-20 border-t border-stone-200 bg-white py-20 dark:border-neutral-800 dark:bg-neutral-900/40">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="max-w-2xl">
                        <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-400">Funciones</p>
                        <h2 class="font-display mt-3 text-3xl font-bold tracking-tight text-balance sm:text-4xl">
                            Todo lo que tu negocio necesita para vender en regla
                        </h2>
                        <p class="mt-3 text-neutral-600 dark:text-neutral-400">
                            Pensado para el comercio peruano: SUNAT, IGV, Yape y Plin desde el primer día.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <article v-for="f in funciones" :key="f.titulo"
                            class="rounded-2xl border border-stone-200 bg-[#FBFCFB] p-6 transition-colors hover:border-emerald-300 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-emerald-700">
                            <div class="grid size-11 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                                <component :is="f.icon" class="size-5" />
                            </div>
                            <h3 class="mt-4 font-semibold tracking-tight">{{ f.titulo }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">{{ f.texto }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- ============ Cómo funciona ============ -->
            <section class="py-20">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="max-w-2xl">
                        <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-400">Cómo funciona</p>
                        <h2 class="font-display mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Empieza a vender en 3 pasos</h2>
                    </div>
                    <ol class="mt-12 grid gap-4 md:grid-cols-3">
                        <li v-for="(paso, i) in pasos" :key="paso.titulo"
                            class="relative rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="flex items-center justify-between">
                                <div class="grid size-11 place-items-center rounded-xl bg-emerald-600 text-white">
                                    <component :is="paso.icon" class="size-5" />
                                </div>
                                <span class="font-receipt text-3xl font-semibold text-stone-200 dark:text-neutral-800">0{{ i + 1 }}</span>
                            </div>
                            <h3 class="mt-4 font-semibold tracking-tight">{{ paso.titulo }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">{{ paso.texto }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- ============ Planes ============ -->
            <section id="planes" class="scroll-mt-20 border-y border-stone-200 bg-white py-20 dark:border-neutral-800 dark:bg-neutral-900/40">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-400">Planes</p>
                        <h2 class="font-display mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Precios simples, en soles</h2>
                        <p class="mt-3 text-neutral-600 dark:text-neutral-400">
                            Todos los planes empiezan con {{ diasPrueba }} días gratis. Sin pasarela de pago aún:
                            activamos tu plan el mismo día por WhatsApp.
                        </p>
                    </div>

                    <div v-if="!planes.length"
                        class="mx-auto mt-12 max-w-md rounded-2xl border border-stone-200 bg-[#FBFCFB] p-8 text-center text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400">
                        Pronto publicaremos nuestros planes. Mientras tanto, empieza tu prueba gratis.
                    </div>

                    <div v-else class="mx-auto mt-12 grid max-w-5xl items-stretch gap-5 md:grid-cols-3">
                        <article v-for="p in planes" :key="p.codigo"
                            class="relative flex flex-col rounded-2xl border p-6"
                            :class="destacado(p)
                                ? 'border-emerald-500 bg-white shadow-xl shadow-emerald-600/10 ring-1 ring-emerald-500 md:-my-3 md:py-9 dark:bg-neutral-900'
                                : 'border-stone-200 bg-[#FBFCFB] dark:border-neutral-800 dark:bg-neutral-900'">
                            <span v-if="destacado(p)"
                                class="absolute -top-3 left-6 rounded-lg bg-emerald-600 px-2.5 py-1 text-[11px] font-bold tracking-wide text-white uppercase">
                                Más elegido
                            </span>
                            <h3 class="font-display text-lg font-bold tracking-tight">{{ p.nombre }}</h3>
                            <p v-if="p.descripcion" class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ p.descripcion }}</p>

                            <p class="mt-5">
                                <span class="text-4xl font-bold tracking-tight tabular-nums">S/ {{ precio(p) }}</span>
                                <span class="text-sm text-neutral-500 dark:text-neutral-400"> / mes</span>
                            </p>

                            <ul class="mt-5 flex-1 space-y-2.5 text-sm">
                                <li class="flex items-center gap-2">
                                    <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    {{ limite(p.max_sucursales, 'sucursal', 'sucursales', 'Sucursales ilimitadas') }}
                                </li>
                                <li class="flex items-center gap-2">
                                    <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    {{ limite(p.max_usuarios, 'usuario', 'usuarios', 'Usuarios ilimitados') }}
                                </li>
                                <li class="flex items-center gap-2">
                                    <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    {{ limiteComprobantes(p.max_comprobantes_mes) }}
                                </li>
                                <li class="flex items-center gap-2">
                                    <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    Facturación electrónica SUNAT
                                </li>
                            </ul>

                            <Link href="/registro"
                                class="mt-6 flex h-11 items-center justify-center rounded-xl text-sm font-semibold transition-colors"
                                :class="destacado(p)
                                    ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/25 hover:bg-emerald-500'
                                    : 'border border-emerald-600 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-500 dark:text-emerald-400 dark:hover:bg-emerald-500/10'">
                                Empezar prueba gratis
                            </Link>
                        </article>
                    </div>
                </div>
            </section>

            <!-- ============ Preguntas frecuentes ============ -->
            <section id="preguntas" class="scroll-mt-20 py-20">
                <div class="mx-auto max-w-3xl px-4 sm:px-6">
                    <div class="text-center">
                        <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-700 uppercase dark:text-emerald-400">Preguntas</p>
                        <h2 class="font-display mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Preguntas frecuentes</h2>
                    </div>
                    <div class="mt-10 space-y-3">
                        <details v-for="q in preguntas" :key="q.p"
                            class="group rounded-2xl border border-stone-200 bg-white px-5 open:pb-5 dark:border-neutral-800 dark:bg-neutral-900">
                            <summary
                                class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-semibold tracking-tight [&::-webkit-details-marker]:hidden">
                                {{ q.p }}
                                <ChevronDown class="size-4 shrink-0 text-neutral-400 transition-transform group-open:rotate-180" />
                            </summary>
                            <p class="text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">{{ q.r }}</p>
                        </details>
                    </div>
                </div>
            </section>

            <!-- ============ CTA final ============ -->
            <section class="px-4 pb-20 sm:px-6">
                <div class="relative mx-auto max-w-6xl overflow-hidden rounded-2xl bg-[#050807] px-6 py-14 text-center text-white sm:px-12">
                    <div class="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full bg-emerald-500/25 blur-[100px]" />
                    <div class="pointer-events-none absolute -bottom-40 -left-24 size-96 rounded-full bg-[#4078F5]/20 blur-[100px]" />
                    <div class="relative">
                        <h2 class="font-display text-3xl font-bold tracking-tight text-balance sm:text-4xl">
                            Tu próxima boleta puede salir hoy
                        </h2>
                        <p class="mx-auto mt-3 max-w-xl text-neutral-300">
                            Crea tu cuenta con tu RUC y prueba {{ marca }} gratis durante {{ diasPrueba }} días.
                        </p>
                        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                            <Link href="/registro"
                                class="group inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-500 px-6 text-sm font-semibold text-neutral-950 transition-colors hover:bg-emerald-400">
                                Empieza gratis {{ diasPrueba }} días
                                <ArrowRight class="size-4 transition-transform group-hover:translate-x-0.5" />
                            </Link>
                            <a v-if="whatsapp" :href="`https://wa.me/${whatsapp}`" target="_blank" rel="noopener"
                                class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-white/20 px-6 text-sm font-semibold text-white transition-colors hover:bg-white/10">
                                <MessageCircle class="size-4" />
                                Hablar por WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- ============ Pie ============ -->
        <footer class="border-t border-stone-200 dark:border-neutral-800">
            <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="grid size-8 place-items-center rounded-lg bg-emerald-600 text-white">
                            <Receipt class="size-4" />
                        </span>
                        <span class="font-display font-bold tracking-tight">{{ marca }}</span>
                    </div>
                    <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                        © {{ anio }} {{ marca }} · Hecho en Perú
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm font-medium text-neutral-600 dark:text-neutral-300">
                    <a v-if="whatsapp" :href="`https://wa.me/${whatsapp}`" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-1.5 hover:text-emerald-600 dark:hover:text-emerald-400">
                        <MessageCircle class="size-4" /> WhatsApp
                    </a>
                    <a v-if="email" :href="`mailto:${email}`"
                        class="inline-flex items-center gap-1.5 hover:text-emerald-600 dark:hover:text-emerald-400">
                        <Mail class="size-4" /> {{ email }}
                    </a>
                    <Link href="/login" class="hover:text-emerald-600 dark:hover:text-emerald-400">Ingresar</Link>
                    <Link href="/registro" class="hover:text-emerald-600 dark:hover:text-emerald-400">Registro</Link>
                </div>
            </div>
        </footer>
    </div>
</template>

<style>
/* Mismas fuentes y boleta que PanelMarcaAuth (aquí no se monta ese componente) */
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap');

.font-display {
    font-family: 'Sora', ui-sans-serif, system-ui, sans-serif;
}

.font-receipt {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
}

.ticket {
    border-radius: 6px 6px 0 0;
    -webkit-mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / 14px 100%;
    mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / 14px 100%;
}

.anim {
    animation: fadeUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.d2 {
    animation-delay: 0.16s;
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(14px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .anim {
        animation: none;
    }
}
</style>
