<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { onClickOutside, useDark, useStorage, useToggle } from '@vueuse/core'
import DialogoConfirmacion from '@/Components/DialogoConfirmacion.vue'
import { usePermisos } from '@/composables/permisos'
import {
    ArrowLeftRight,
    Banknote,
    Bell,
    Boxes,
    Building2,
    CalendarClock,
    ChartColumnBig,
    CheckCircle2,
    ChevronDown,
    CreditCard,
    ChevronsLeft,
    ChevronsRight,
    HandCoins,
    LayoutDashboard,
    LogOut,
    Mail,
    MapPin,
    Menu,
    Moon,
    Package,
    ReceiptText,
    ScrollText,
    Settings,
    ShoppingCart,
    Sparkles,
    TriangleAlert,
    Store,
    Sun,
    Tags,
    Truck,
    UserCog,
    Users,
    Wallet,
    X,
    XCircle,
    Zap,
} from '@lucide/vue'

defineProps({
    titulo: { type: String, default: '' },
})

const page = usePage()
const usuario = computed(() => page.props.auth?.user ?? null)
const empresa = computed(() => usuario.value?.empresa ?? null)
const nombreEmpresa = computed(() => empresa.value?.nombre_comercial || empresa.value?.razon_social || page.props.appName || 'inkaPos')
const inicialEmpresa = computed(() => nombreEmpresa.value.charAt(0).toUpperCase())
const sucursalActiva = computed(() => usuario.value?.sucursal_activa ?? null)

const menuBase = [
    {
        seccion: 'General',
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, exact: true },
            { label: 'Reportes', href: '/reportes', icon: ChartColumnBig, permiso: 'reportes.ver' },
        ],
    },
    {
        seccion: 'Ventas',
        items: [
            { label: 'POS', href: '/pos', icon: ShoppingCart, permiso: 'pos.vender' },
            { label: 'Comprobantes', href: '/comprobantes', icon: ReceiptText, permiso: 'comprobantes.ver' },
            { label: 'Cuentas por cobrar', href: '/cuentas-por-cobrar', icon: HandCoins, permiso: 'cuentas_cobrar.ver' },
        ],
    },
    {
        seccion: 'Inventario',
        items: [
            { label: 'Productos', href: '/productos', icon: Package, permiso: 'productos.ver' },
            { label: 'Categorías y marcas', href: '/catalogos', icon: Tags, permiso: 'productos.ver' },
            { label: 'Stock', href: '/stock', icon: Boxes, permiso: 'stock.ver' },
            { label: 'Transferencias', href: '/transferencias', icon: ArrowLeftRight, permiso: 'transferencias.ver' },
        ],
    },
    {
        seccion: 'Compras',
        items: [
            { label: 'Compras', href: '/compras', icon: Truck, permiso: 'compras.ver' },
            { label: 'Cuentas por pagar', href: '/cuentas-por-pagar', icon: Banknote, permiso: 'cuentas_pagar.ver' },
            { label: 'Proveedores', href: '/proveedores', icon: Building2, permiso: 'proveedores.ver' },
        ],
    },
    {
        seccion: 'Caja y clientes',
        items: [
            { label: 'Caja', href: '/caja', icon: Wallet, permiso: 'caja.operar' },
            { label: 'Clientes', href: '/clientes', icon: Users, permiso: 'clientes.ver' },
        ],
    },
    {
        seccion: 'Configuración',
        items: [
            { label: 'Sucursales', href: '/sucursales', icon: Store, permiso: 'sucursales.gestionar' },
            { label: 'Usuarios', href: '/usuarios', icon: UserCog, permiso: 'usuarios.gestionar' },
            { label: 'Auditoría', href: '/auditoria', icon: ScrollText, permiso: 'auditoria.ver' },
            { label: 'Empresa', href: '/empresa', icon: Settings, permiso: 'empresa.gestionar' },
            { label: 'Suscripción', href: '/suscripcion', icon: CreditCard, permiso: 'empresa.gestionar' },
        ],
    },
]

// cada item se muestra solo si el usuario tiene su permiso (sin permiso = visible para todos)
const { puede } = usePermisos()
const menu = computed(() => menuBase
    .map((grupo) => ({
        ...grupo,
        items: grupo.items.filter((item) => !item.permiso || puede(item.permiso)),
    }))
    .filter((grupo) => grupo.items.length))

// modo oscuro: agrega .dark en <html>, persiste en localStorage y respeta la preferencia del sistema
const esOscuro = useDark()
const alternarTema = useToggle(esOscuro)

// colapsado (solo iconos) en escritorio, persistido entre sesiones
const colapsado = useStorage('sidebar-colapsado', false)
// abierto en móvil (off-canvas)
const abiertoMovil = ref(false)

const menuUsuarioAbierto = ref(false)
const menuUsuarioRef = ref(null)
onClickOutside(menuUsuarioRef, () => (menuUsuarioAbierto.value = false))

// ---- selector de sucursal (barra superior) ----
const sucursalesAccesibles = computed(() => usuario.value?.sucursales_accesibles ?? [])
const puedeVerTodas = computed(() => usuario.value?.puede_ver_todas ?? false)
const sucursalConsultaId = computed(() => usuario.value?.sucursal_consulta_id ?? null)
const mostrarSelector = computed(() => sucursalesAccesibles.value.length > 1)

const etiquetaSelector = computed(() => {
    if (sucursalConsultaId.value) {
        return sucursalesAccesibles.value.find((s) => s.id === sucursalConsultaId.value)?.nombre ?? 'Sucursal'
    }
    return puedeVerTodas.value ? 'Todas las sucursales' : (usuario.value?.sucursal?.nombre ?? 'Sucursal')
})

const selectorAbierto = ref(false)
const selectorRef = ref(null)
onClickOutside(selectorRef, () => (selectorAbierto.value = false))

function cambiarSucursal(id) {
    selectorAbierto.value = false
    if (id === sucursalConsultaId.value) return
    router.post('/sucursal-activa', { sucursal_id: id }, { preserveScroll: true })
}

// ---- notificaciones (campanita) ----
const ICONOS_NOTIFICACION = {
    stock_bajo: Boxes,
    lotes_por_vencer: CalendarClock,
    por_cobrar: HandCoins,
    por_pagar: Banknote,
}

const notificaciones = ref({ items: [], total: 0 })
const notiAbierto = ref(false)
const notiRef = ref(null)
onClickOutside(notiRef, () => (notiAbierto.value = false))

async function cargarNotificaciones() {
    try {
        const r = await fetch('/notificaciones', { headers: { Accept: 'application/json' } })
        if (r.ok) notificaciones.value = await r.json()
    } catch {
        // sin red no pasa nada: la campanita simplemente no marca alertas
    }
}

function alternarNotificaciones() {
    notiAbierto.value = !notiAbierto.value
    if (notiAbierto.value) cargarNotificaciones()
}

onMounted(() => {
    if (usuario.value) cargarNotificaciones()
})

function esActivo(item) {
    const url = page.url
    return item.exact ? url === item.href : url.startsWith(item.href)
}

// ---- aviso de suscripcion (prueba por vencer, plan por vencer o en gracia) ----
const suscripcion = computed(() => usuario.value?.suscripcion ?? null)

// 'YYYY-MM-DD' se interpreta como fecha local (new Date('YYYY-MM-DD') seria UTC y podria restar un dia)
function fechaLarga(iso) {
    if (!iso) return ''
    const [a, m, d] = iso.split('-').map(Number)
    return new Date(a, m - 1, d).toLocaleDateString('es-PE', { day: '2-digit', month: 'long' })
}

const avisoSuscripcion = computed(() => {
    const s = suscripcion.value
    if (!s) return null
    const dias = s.dias_restantes ?? 0
    if (s.estado === 'en_gracia') {
        return {
            tono: 'rojo',
            texto: `Tu plan venció el ${fechaLarga(s.fecha_fin)}. Tienes hasta 3 días para renovarlo.`,
            enlace: 'Renovar',
        }
    }
    if (s.es_prueba && s.vigente) {
        return {
            tono: dias <= 3 ? 'fuerte' : 'suave',
            texto: dias <= 0
                ? 'Tu prueba vence hoy.'
                : `Prueba gratuita: te ${dias === 1 ? 'queda 1 día' : `quedan ${dias} días`}.`,
            enlace: 'Ver planes',
        }
    }
    if (!s.es_prueba && s.vigente && dias <= 7) {
        return {
            tono: 'suave',
            texto: `Tu plan${s.plan ? ` ${s.plan}` : ''} vence el ${fechaLarga(s.fecha_fin)}.`,
            enlace: 'Renovar',
        }
    }
    return null
})

const CLASES_AVISO = {
    suave: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    fuerte: 'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-200',
    rojo: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300',
}

// ---- aviso de correo por confirmar (gracia de 3 dias) ----
const avisoCorreo = computed(() => {
    const u = usuario.value
    if (!u || u.correo_verificado || u.dias_para_verificar === null || u.dias_para_verificar === undefined) return null
    const dias = u.dias_para_verificar
    return {
        texto: `Confirma tu correo: te enviamos un enlace a tu bandeja. ${dias <= 0 ? 'Hoy es el último día.' : `Te quedan ${dias} días.`}`,
    }
})

const reenviandoCorreo = ref(false)
function reenviarCorreo() {
    reenviandoCorreo.value = true
    router.post('/verificar-correo/reenviar', {}, {
        preserveScroll: true,
        onFinish: () => { reenviandoCorreo.value = false },
    })
}

// toast para mensajes flash (exito o error)
const toast = ref(null) // { mensaje, tipo: 'exito' | 'error' }
let toastTimer = null
watch(
    () => page.props,
    (props) => {
        const exito = props.flash?.success
        const error = props.flash?.error
        if (!exito && !error) return
        toast.value = exito ? { mensaje: exito, tipo: 'exito' } : { mensaje: error, tipo: 'error' }
        clearTimeout(toastTimer)
        toastTimer = setTimeout(() => (toast.value = null), 4000)
    },
    { immediate: true },
)
</script>

<template>
    <Head v-if="titulo" :title="titulo" />
    <div class="min-h-screen bg-stone-100 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <!-- Overlay móvil -->
        <div v-if="abiertoMovil" class="fixed inset-0 z-30 bg-neutral-950/60 lg:hidden" @click="abiertoMovil = false" />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-stone-200 bg-white transition-all duration-200 lg:translate-x-0 dark:border-neutral-800 dark:bg-neutral-900"
            :class="[
                colapsado ? 'lg:w-[4.5rem]' : 'lg:w-64',
                abiertoMovil ? 'w-64 translate-x-0' : 'w-64 -translate-x-full',
            ]">
            <!-- Logo y nombre de la empresa -->
            <div class="flex h-16 shrink-0 items-center gap-3 px-4">
                <div class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl" :class="empresa?.logo_url
                    ? 'border border-stone-200 bg-white dark:border-neutral-700'
                    : 'bg-neutral-900 font-bold text-emerald-400 dark:bg-emerald-500 dark:text-white'"
                    :title="colapsado && !abiertoMovil ? nombreEmpresa : undefined">
                    <img v-if="empresa?.logo_url" :src="empresa.logo_url" :alt="nombreEmpresa"
                        class="size-full object-contain" />
                    <template v-else>{{ inicialEmpresa }}</template>
                </div>
                <div v-if="!colapsado || abiertoMovil" class="min-w-0">
                    <span class="block truncate text-lg leading-tight font-semibold tracking-tight">
                        {{ nombreEmpresa }}
                    </span>
                    <span v-if="sucursalActiva"
                        class="flex items-center gap-1 text-[11px] text-neutral-500 dark:text-neutral-400"
                        :title="sucursalActiva.caja ? `Operando en ${sucursalActiva.nombre} (${sucursalActiva.caja})` : `Sucursal ${sucursalActiva.nombre}`">
                        <MapPin class="size-3 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <span class="truncate">
                            {{ sucursalActiva.nombre }}<template v-if="sucursalActiva.caja"> · {{ sucursalActiva.caja
                                }}</template>
                        </span>
                    </span>
                </div>
                <button
                    class="ml-auto rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 lg:hidden dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                    @click="abiertoMovil = false">
                    <X class="size-5" />
                </button>
            </div>

            <!-- Navegación -->
            <nav class="nav-scroll flex-1 space-y-3 overflow-y-auto px-3 py-3">
                <div v-for="grupo in menu" :key="grupo.seccion">
                    <p v-if="!colapsado || abiertoMovil"
                        class="mb-1 px-2.5 text-[10px] font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                        {{ grupo.seccion }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in grupo.items" :key="item.href">
                            <Link :href="item.href"
                                class="group flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors"
                                :class="esActivo(item)
                                        ? 'bg-neutral-900 text-white dark:bg-emerald-500 dark:text-white'
                                        : 'text-neutral-600 hover:bg-stone-100 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
                                    " :title="colapsado && !abiertoMovil ? item.label : undefined"
                                @click="abiertoMovil = false">
                                <component :is="item.icon" class="size-4.5 shrink-0"
                                    :class="esActivo(item) ? 'text-emerald-400 dark:text-white' : ''" />
                                <span v-if="!colapsado || abiertoMovil" class="truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Colapsar (solo escritorio) -->
            <button
                class="mx-3 mb-2 hidden h-9 shrink-0 items-center justify-center gap-2 rounded-xl text-sm text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 lg:flex dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                @click="colapsado = !colapsado">
                <ChevronsRight v-if="colapsado" class="size-5" />
                <template v-else>
                    <ChevronsLeft class="size-5" />
                    <span>Colapsar</span>
                </template>
            </button>
        </aside>

        <!-- Contenido -->
        <div class="flex min-h-screen flex-col transition-all duration-200"
            :class="colapsado ? 'lg:pl-[4.5rem]' : 'lg:pl-64'">
            <!-- Barra superior -->
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-3 bg-white px-4 backdrop-blur-md sm:px-6 dark:bg-[#171717]">
                <button
                    class="grid size-10 place-items-center rounded-xl border border-stone-200 bg-white text-neutral-600 hover:text-neutral-900 lg:hidden dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100"
                    @click="abiertoMovil = true">
                    <Menu class="size-5" />
                </button>

                <h1 class="truncate text-xl font-semibold tracking-tight">{{ titulo }}</h1>

                <div class="ml-auto flex items-center gap-2">
                    <!-- Estado de facturación electrónica -->
                    <component
                        :is="puede('empresa.gestionar') ? Link : 'span'"
                        v-if="empresa?.facturacion_electronica"
                        :href="puede('empresa.gestionar') ? '/empresa' : undefined"
                        class="hidden h-10 items-center gap-1.5 rounded-xl px-3 text-xs font-bold tracking-wide uppercase sm:flex"
                        :class="empresa.entorno_sunat === 'produccion'
                            ? 'bg-emerald-600 text-white'
                            : 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'"
                        :title="empresa.entorno_sunat === 'produccion'
                            ? 'Facturación electrónica activa (SUNAT real)'
                            : 'Facturación electrónica en entorno de pruebas'"
                    >
                        <Zap class="size-3.5" />
                        {{ empresa.entorno_sunat === 'produccion' ? 'SUNAT' : 'SUNAT · Beta' }}
                    </component>

                    <!-- Selector de sucursal -->
                    <div v-if="mostrarSelector" ref="selectorRef" class="relative">
                        <button
                            class="flex h-10 items-center gap-2 rounded-xl border border-stone-200 bg-white px-3 text-sm font-medium text-neutral-600 transition-colors hover:border-stone-300 hover:text-neutral-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-neutral-700 dark:hover:text-neutral-100"
                            title="Cambiar de sucursal" @click="selectorAbierto = !selectorAbierto">
                            <Store class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <span class="hidden max-w-44 truncate sm:block">{{ etiquetaSelector }}</span>
                            <ChevronDown class="hidden size-4 shrink-0 text-neutral-400 sm:block" />
                        </button>

                        <div v-if="selectorAbierto"
                            class="absolute right-0 mt-2 w-60 overflow-hidden rounded-2xl border border-stone-200 bg-white py-1 shadow-lg shadow-neutral-900/5 dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-black/30">
                            <p
                                class="px-4 pt-2.5 pb-1.5 text-[11px] font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                                Viendo datos de
                            </p>
                            <button v-if="puedeVerTodas"
                                class="flex w-full items-center justify-between px-4 py-2 text-sm hover:bg-stone-50 dark:hover:bg-neutral-800"
                                @click="cambiarSucursal(null)">
                                Todas las sucursales
                                <CheckCircle2 v-if="!sucursalConsultaId"
                                    class="size-4 text-emerald-600 dark:text-emerald-400" />
                            </button>
                            <button v-for="s in sucursalesAccesibles" :key="s.id"
                                class="flex w-full items-center justify-between px-4 py-2 text-sm hover:bg-stone-50 dark:hover:bg-neutral-800"
                                @click="cambiarSucursal(s.id)">
                                <span class="truncate">{{ s.nombre }}</span>
                                <CheckCircle2 v-if="sucursalConsultaId === s.id"
                                    class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            </button>
                        </div>
                    </div>

                    <button
                        class="grid size-10 place-items-center rounded-xl border border-stone-200 bg-white text-neutral-600 transition-colors hover:text-neutral-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100"
                        :title="esOscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'" @click="alternarTema()">
                        <Sun v-if="esOscuro" class="size-5" />
                        <Moon v-else class="size-5" />
                    </button>
                    <div ref="notiRef" class="relative">
                        <button
                            class="relative grid size-10 place-items-center rounded-xl border border-stone-200 bg-white text-neutral-600 transition-colors hover:text-neutral-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100"
                            title="Notificaciones" @click="alternarNotificaciones">
                            <Bell class="size-5" />
                            <span v-if="notificaciones.total > 0"
                                class="absolute -top-1 -right-1 grid h-4.5 min-w-4.5 place-items-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">
                                {{ notificaciones.total > 99 ? '99+' : notificaciones.total }}
                            </span>
                        </button>

                        <div v-if="notiAbierto"
                            class="absolute right-0 mt-2 w-80 overflow-hidden rounded-2xl border border-stone-200 bg-white py-1 shadow-lg shadow-neutral-900/5 dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-black/30">
                            <p
                                class="border-b border-stone-100 px-4 py-2.5 text-sm font-semibold tracking-tight dark:border-neutral-800">
                                Notificaciones
                            </p>
                            <div v-if="!notificaciones.items.length"
                                class="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">
                                <CheckCircle2 class="mx-auto mb-2 size-7 text-emerald-500" />
                                Todo en orden, sin pendientes.
                            </div>
                            <Link v-for="item in notificaciones.items" :key="item.clave" :href="item.url"
                                class="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800"
                                @click="notiAbierto = false">
                                <div
                                    class="grid size-9 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                                    <component :is="ICONOS_NOTIFICACION[item.clave] ?? Bell" class="size-4.5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ item.titulo }}</p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ item.detalle }}</p>
                                </div>
                            </Link>
                        </div>
                    </div>

                    <!-- Menú de usuario -->
                    <div ref="menuUsuarioRef" class="relative">
                        <button
                            class="flex h-10 items-center gap-2 rounded-xl border border-stone-200 bg-white p-1 transition-colors hover:border-stone-300 sm:pr-3 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-700"
                            @click="menuUsuarioAbierto = !menuUsuarioAbierto">
                            <div
                                class="grid size-8 place-items-center rounded-full bg-emerald-500 text-sm font-bold text-white">
                                {{ (usuario?.nombre_completo ?? 'U').charAt(0).toUpperCase() }}
                            </div>
                            <span class="hidden max-w-40 truncate text-sm font-medium sm:block">
                                {{ usuario?.nombre_completo ?? 'Usuario' }}
                            </span>
                            <ChevronDown class="hidden size-4 text-neutral-400 sm:block" />
                        </button>

                        <div v-if="menuUsuarioAbierto"
                            class="absolute right-0 mt-2 w-56 overflow-hidden rounded-2xl border border-stone-200 bg-white py-1 shadow-lg shadow-neutral-900/5 dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-black/30">
                            <div class="border-b border-stone-100 px-4 py-2.5 dark:border-neutral-800">
                                <p class="truncate text-sm font-medium">
                                    {{ usuario?.nombre_completo ?? 'Usuario' }}
                                </p>
                                <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ usuario?.email ??
                                    '' }}
                                </p>
                            </div>
                            <Link href="/logout" method="post" as="button"
                                class="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">
                                <LogOut class="size-4" />
                                Cerrar sesión
                            </Link>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Página -->
            <main class="flex-1 p-4 sm:p-6">
                <!-- Aviso de suscripción -->
                <div v-if="avisoSuscripcion"
                    class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border px-4 py-2.5 text-sm"
                    :class="CLASES_AVISO[avisoSuscripcion.tono]">
                    <Sparkles v-if="avisoSuscripcion.tono === 'suave' && suscripcion?.es_prueba" class="size-4 shrink-0" />
                    <TriangleAlert v-else class="size-4 shrink-0" />
                    <span class="font-medium">{{ avisoSuscripcion.texto }}</span>
                    <Link v-if="!page.url.startsWith('/suscripcion')" href="/suscripcion"
                        class="ml-auto font-semibold underline underline-offset-2 hover:no-underline">
                        {{ avisoSuscripcion.enlace }}
                    </Link>
                </div>

                <!-- Aviso de correo por confirmar -->
                <div v-if="avisoCorreo"
                    class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border px-4 py-2.5 text-sm"
                    :class="CLASES_AVISO.suave">
                    <Mail class="size-4 shrink-0" />
                    <span class="font-medium">{{ avisoCorreo.texto }}</span>
                    <button type="button" :disabled="reenviandoCorreo"
                        class="ml-auto font-semibold underline underline-offset-2 hover:no-underline disabled:cursor-not-allowed disabled:opacity-60"
                        @click="reenviarCorreo">
                        {{ reenviandoCorreo ? 'Enviando...' : 'Reenviar' }}
                    </button>
                </div>
                <slot />
            </main>
        </div>

        <!-- Diálogo de confirmación global -->
        <DialogoConfirmacion />

        <!-- Toast de éxito -->
        <Transition enter-active-class="transition duration-200" enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-200" leave-to-class="translate-y-2 opacity-0">
            <div v-if="toast"
                class="fixed right-4 bottom-4 z-50 flex items-center gap-2.5 rounded-xl border border-stone-200 bg-white py-3 pr-5 pl-4 shadow-lg shadow-neutral-900/5 dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-black/30">
                <CheckCircle2 v-if="toast.tipo === 'exito'" class="size-5 text-emerald-600 dark:text-emerald-400" />
                <XCircle v-else class="size-5 text-red-600 dark:text-red-400" />
                <span class="text-sm font-medium">{{ toast.mensaje }}</span>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
/* scroll del menu lateral: invisible hasta pasar el cursor */
.nav-scroll {
    scrollbar-width: thin;
    scrollbar-color: transparent transparent;
}

.nav-scroll:hover {
    scrollbar-color: rgb(163 163 163 / 0.4) transparent;
}
</style>
