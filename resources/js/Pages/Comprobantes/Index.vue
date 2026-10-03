<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { onClickOutside, useEventListener } from '@vueuse/core'
import {
    Ban,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronsUpDown,
    ChevronUp,
    Clock3,
    CloudUpload,
    EllipsisVertical,
    Eye,
    FileCode2,
    FileText,
    LoaderCircle,
    Mail,
    Navigation,
    Printer,
    ReceiptText,
    RefreshCw,
    Search,
    ShieldCheck,
    Undo2,
    UserRound,
    Users,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import DetalleComprobante from './DetalleComprobante.vue'
import {
    AYUDA_REEMITIR,
    MOTIVOS_NC,
    TIPOS,
    badgeSunat,
    bajaPendiente,
    esElectronico,
    numero,
    puedeReemitir,
    puedeReenviar,
    soles,
} from './comun'
import { usePermisos } from '@/composables/permisos'
import { useImpresion } from '@/composables/impresion'

const { imprimirTicket } = useImpresion()

const props = defineProps({
    comprobantes: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    orden: { type: Object, default: () => ({ columna: 'fecha', dir: 'desc' }) },
    mediosPago: { type: Array, default: () => [] },
    unidades: { type: Object, default: () => ({}) },
})

const { puede } = usePermisos()
const page = usePage()
const esRus = computed(() => page.props.auth?.user?.empresa?.regimen_tributario === 'RUS')
const facturacionElectronica = computed(() => !!page.props.auth?.user?.empresa?.facturacion_electronica)

const momento = (c) => new Date(`${c.fecha_emision.slice(0, 10)}T${c.hora_emision}`)
const fecha = (c) => momento(c).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
const hora = (c) => momento(c).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })

// ---- presentación ----
const ESTILO_OK = 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
const ESTILO_ERROR = 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-400'
const ESTILOS_TIPO = {
    '00': 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    '01': 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    '03': 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
}
const estiloTipo = (c) => ({
    texto: TIPOS[c.tipo_comprobante_codigo] ?? c.tipo_comprobante_codigo,
    clase: ESTILOS_TIPO[c.tipo_comprobante_codigo] ?? 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300',
})
const inicial = (nombre) => (nombre ?? '?').trim().charAt(0).toUpperCase() || '?'
const primerNombre = (nombre) => (nombre ?? '—').trim().split(/\s+/)[0]
// cada cliente con un color fijo (derivado de su nombre), para reconocerlo de un vistazo
const COLORES_AVATAR = [
    'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
    'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
    'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
    'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
]
const colorAvatar = (nombre) => COLORES_AVATAR[[...nombre].reduce((h, ch) => (h * 31 + ch.charCodeAt(0)) % 997, 7) % COLORES_AVATAR.length]
const claseAccion =
    'grid size-10 place-items-center rounded-xl border border-[#E2E8F0] bg-white text-[#475569] transition-colors hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-emerald-700 dark:hover:bg-emerald-950/40 dark:hover:text-emerald-400'

// ---- orden por columna ----
const COLUMNAS = [
    { id: 'numero', titulo: 'Número' },
    { id: 'tipo', titulo: 'Tipo' },
    { id: 'fecha', titulo: 'Fecha' },
    { id: 'cliente', titulo: 'Cliente' },
    { id: 'total', titulo: 'Total', clase: 'text-right' },
    { id: 'estado', titulo: 'Estado' },
    { id: 'sunat', titulo: 'SUNAT' },
    { id: 'vendedor', titulo: 'Vendedor' },
]
const orden = ref({ ...props.orden })

function ordenarPor(columna) {
    // la primera vez, montos y fechas de mayor a menor; textos de la A a la Z
    const dir = orden.value.columna === columna
        ? (orden.value.dir === 'asc' ? 'desc' : 'asc')
        : (['fecha', 'total'].includes(columna) ? 'desc' : 'asc')
    orden.value = { columna, dir }
    aplicarFiltros()
}

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')
const desde = ref(props.filtros.desde ?? '')
const hasta = ref(props.filtros.hasta ?? '')
const tipo = ref(props.filtros.tipo ?? '')
const estado = ref(props.filtros.estado ?? '')
const sunat = ref(props.filtros.sunat ?? '')

function aplicarFiltros() {
    router.get('/comprobantes', {
        buscar: buscar.value || undefined,
        tipo: tipo.value || undefined,
        estado: estado.value || undefined,
        sunat: sunat.value || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
        orden: orden.value.columna !== 'fecha' || orden.value.dir !== 'desc' ? orden.value.columna : undefined,
        dir: orden.value.columna !== 'fecha' || orden.value.dir !== 'desc' ? orden.value.dir : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

const hayFiltros = computed(() => !!(buscar.value || tipo.value || estado.value || sunat.value || desde.value || hasta.value))
function limpiarFiltros() {
    desde.value = ''
    hasta.value = ''
    buscar.value = ''
    tipo.value = ''
    estado.value = ''
    sunat.value = ''
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch([tipo, estado, sunat, desde, hasta], aplicarFiltros)

// ---- detalle expandible ----
const expandido = ref(null)
const alternarDetalle = (c) => (expandido.value = expandido.value === c.id ? null : c.id)

// ---- detalle de productos en un modal ----
const verId = ref(null)
const ver = computed(() => props.comprobantes.data.find((c) => c.id === verId.value) ?? null)
const verDetalle = (c) => (verId.value = c.id)
const nombreUnidad = (codigo) => props.unidades[(codigo ?? '').trim()] ?? (codigo ?? '').trim()
const cantidadTexto = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const puedeGuia = (c) => puede('guias.gestionar') && c.estado === 'emitido' && ['00', '01', '03'].includes(c.tipo_comprobante_codigo)
const puedeAnular = (c) => puede('comprobantes.anular') && c.estado === 'emitido' && c.tipo_comprobante_codigo !== '07' && !bajaPendiente(c)

// algo espera una acción del usuario (se marca con un punto en el botón de más acciones)
const requiereAtencion = (c) => puede('comprobantes.sunat') && (puedeReenviar(c) || puedeReemitir(c) || bajaPendiente(c))

// ---- menú de más acciones (fuera de la tabla, para que el desplazamiento horizontal no lo corte) ----
const menu = ref(null) // { c, top, left, arriba }
const panelMenu = ref(null)

function abrirMenu(c, evento) {
    if (menu.value?.c.id === c.id) return (menu.value = null)
    const r = evento.currentTarget.getBoundingClientRect()
    const arriba = r.bottom + 320 > window.innerHeight && r.top > 320
    abiertoEn = Date.now()
    menu.value = { c, left: Math.max(8, r.right - 256), top: arriba ? r.top - 6 : r.bottom + 6, arriba }
}

function accion(fn) {
    const c = menu.value?.c
    menu.value = null
    if (c) fn(c)
}

onClickOutside(panelMenu, () => (menu.value = null))
// al desplazar la página el menú quedaría flotando fuera de su fila: se cierra (salvo el
// desplazamiento que el propio navegador hace justo al tocar el botón)
let abiertoEn = 0
useEventListener(window, 'scroll', () => Date.now() - abiertoEn > 400 && (menu.value = null), { capture: true, passive: true })
useEventListener(window, 'resize', () => (menu.value = null))
// Esc cierra primero el menú y, si no hay menú abierto, el detalle
useEventListener(document, 'keydown', (e) => {
    if (e.key !== 'Escape') return
    if (menu.value) menu.value = null
    else verId.value = null
})

// ---- envio a SUNAT ----

const enviandoSunat = ref(null)

function enviarSunat(c) {
    enviandoSunat.value = c.id
    router.post(`/comprobantes/${c.id}/sunat`, {}, {
        preserveScroll: true,
        onFinish: () => (enviandoSunat.value = null),
    })
}

function reemitir(c) {
    enviandoSunat.value = c.id
    router.post(`/comprobantes/${c.id}/reemitir`, {}, {
        preserveScroll: true,
        onFinish: () => (enviandoSunat.value = null),
    })
}

// ---- convertir nota de venta en boleta/factura ----
const puedeConvertir = (c) =>
    facturacionElectronica.value && puede('comprobantes.convertir') && c.tipo_comprobante_codigo === '00' && c.estado === 'emitido'

const comprobanteConvertir = ref(null)
const tipoConversion = ref('03')
const clienteConversion = ref(null)
const buscarClienteConv = ref('')
const resultadosClienteConv = ref([])
const buscandoClienteConv = ref(false)
const erroresConversion = ref({})
const convirtiendo = ref(false)
const ticketConversion = ref(null) // { mensaje, ticket }

function abrirConversion(c) {
    comprobanteConvertir.value = c
    tipoConversion.value = !esRus.value && c.cliente_tipo_doc?.trim() === '6' ? '01' : '03'
    clienteConversion.value = c.cliente_id
        ? {
            id: c.cliente_id,
            nombre: c.cliente_nombre,
            tipo_documento_codigo: c.cliente_tipo_doc ?? null,
            numero_documento: c.cliente_numero_doc ?? null,
        }
        : null
    buscarClienteConv.value = ''
    resultadosClienteConv.value = []
    erroresConversion.value = {}
}

watchDebounced(buscarClienteConv, async (texto) => {
    if (!texto.trim()) {
        resultadosClienteConv.value = []
        return
    }
    buscandoClienteConv.value = true
    try {
        const r = await fetch(`/pos/clientes?buscar=${encodeURIComponent(texto)}`, { headers: { Accept: 'application/json' } })
        resultadosClienteConv.value = r.ok ? await r.json() : []
    } catch {
        resultadosClienteConv.value = []
    } finally {
        buscandoClienteConv.value = false
    }
}, { debounce: 300 })

function elegirClienteConversion(cliente) {
    clienteConversion.value = cliente
    buscarClienteConv.value = ''
    resultadosClienteConv.value = []
}

function convertir() {
    const c = comprobanteConvertir.value
    if (!c) return
    convirtiendo.value = true
    erroresConversion.value = {}
    router.post(`/comprobantes/${c.id}/convertir`, {
        tipo: tipoConversion.value,
        cliente_id: clienteConversion.value?.id ?? null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            // un flash error tambien llega como "success" de Inertia: solo cerramos si hubo exito
            if (page.props.flash?.error) return
            comprobanteConvertir.value = null
            if (page.props.flash?.ticket) {
                ticketConversion.value = {
                    mensaje: page.props.flash.success ?? 'Comprobante emitido.',
                    ticket: page.props.flash.ticket,
                }
            }
        },
        onError: (errores) => (erroresConversion.value = errores),
        onFinish: () => (convirtiendo.value = false),
    })
}

// ---- nota de credito ----
const comprobanteNota = ref(null)
const formNota = useForm({ motivo: '06', items: [], medio_pago_codigo: 'efectivo', referencia: '' })
const cantidadesNota = ref({}) // detalle_id -> cantidad a devolver

const puedeNotaCredito = (c) =>
    puede('comprobantes.nota_credito') && esElectronico(c) && c.estado === 'emitido' && ['aceptado', 'observado'].includes(c.sunat?.estado)

function medioPagoPorDefecto(c) {
    if (!c.pagos?.length) return 'efectivo'
    return c.pagos.reduce((mayor, p) => (Number(p.monto) > Number(mayor.monto) ? p : mayor), c.pagos[0]).medio_pago_codigo
}

const medioSeleccionadoNota = computed(() => props.mediosPago.find((m) => m.codigo === formNota.medio_pago_codigo))

function abrirNotaCredito(c) {
    comprobanteNota.value = c
    formNota.clearErrors()
    formNota.motivo = '06'
    formNota.referencia = ''
    formNota.medio_pago_codigo = medioPagoPorDefecto(c)
    cantidadesNota.value = Object.fromEntries(c.detalles.map((d) => [d.id, '']))
}

const totalNota = computed(() => {
    const c = comprobanteNota.value
    if (!c) return 0
    if (formNota.motivo !== '07') return Number(c.total)
    return c.detalles.reduce((suma, d) => {
        const cant = Number(cantidadesNota.value[d.id])
        if (!(cant > 0)) return suma
        return suma + (Number(d.total) * Math.min(cant, Number(d.cantidad))) / Number(d.cantidad)
    }, 0)
})

function emitirNota() {
    formNota.items = formNota.motivo === '07'
        ? Object.entries(cantidadesNota.value)
            .filter(([, cant]) => Number(cant) > 0)
            .map(([detalle_id, cantidad]) => ({ detalle_id, cantidad: Number(cantidad) }))
        : []

    formNota.post(`/comprobantes/${comprobanteNota.value.id}/nota-credito`, {
        preserveScroll: true,
        onSuccess: () => (comprobanteNota.value = null),
    })
}

// ---- envio por correo ----
const comprobanteCorreo = ref(null)
const formCorreo = useForm({ email: '', guardar_en_cliente: true })

function abrirCorreo(c) {
    comprobanteCorreo.value = c
    formCorreo.clearErrors()
    formCorreo.email = c.cliente?.email ?? ''
    formCorreo.guardar_en_cliente = !!(c.cliente_id && !c.cliente?.email)
}

function enviarCorreo() {
    formCorreo.post(`/comprobantes/${comprobanteCorreo.value.id}/correo`, {
        preserveScroll: true,
        onSuccess: () => {
            if (page.props.flash?.error) return
            comprobanteCorreo.value = null
        },
    })
}

// ---- anulacion ----
const comprobanteAnular = ref(null)
const formAnular = useForm({ motivo: '' })

function abrirAnulacion(c) {
    comprobanteAnular.value = c
    formAnular.clearErrors()
    formAnular.motivo = ''
}

function anular() {
    formAnular.post(`/comprobantes/${comprobanteAnular.value.id}/anular`, {
        preserveScroll: true,
        onSuccess: () => (comprobanteAnular.value = null),
    })
}

const claseItemMenu =
    'flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left font-medium transition-colors hover:bg-slate-50 dark:hover:bg-neutral-800'
const claseInput =
    'h-10 rounded-xl border border-[#E2E8F0] bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500'
</script>

<template>
    <AppLayout titulo="Comprobantes">
        <!-- Filtros -->
        <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="relative w-full lg:max-w-sm">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="buscar" type="text" placeholder="Buscar por número, cliente o documento..." :class="[claseInput, 'w-full pl-10']" />
            </div>
            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-1 sm:flex-wrap sm:items-center">
                <!-- rango de fechas de emision -->
                <div class="col-span-2 flex items-center gap-1.5 rounded-xl border border-[#E2E8F0] bg-white pr-1.5 pl-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <CalendarDays class="size-4 shrink-0 text-[#94A3B8]" />
                    <input v-model="desde" type="date" :max="hasta || undefined" aria-label="Desde" title="Desde" class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                    <span class="text-xs text-[#94A3B8]">a</span>
                    <input v-model="hasta" type="date" :min="desde || undefined" aria-label="Hasta" title="Hasta" class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                </div>
                <select v-model="tipo" :class="claseInput" aria-label="Tipo">
                    <option value="">Todos los tipos</option>
                    <option value="00">Notas de venta</option>
                    <option value="03">Boletas</option>
                    <option value="01">Facturas</option>
                </select>
                <select v-model="estado" :class="claseInput" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <option value="emitido">Emitidos</option>
                    <option value="anulado">Anulados</option>
                </select>
                <select v-model="sunat" :class="[claseInput, 'col-span-2 sm:col-span-1']" aria-label="Estado en SUNAT">
                    <option value="">SUNAT: todos</option>
                    <option value="pendiente">Sin aceptar (pendientes y rechazados)</option>
                    <option value="aceptado">Aceptados</option>
                    <option value="observado">Observados</option>
                    <option value="rechazado">Rechazados</option>
                    <option value="baja_pendiente">Baja en proceso</option>
                    <option value="baja">Dados de baja</option>
                </select>
                <button
                    v-if="hayFiltros"
                    type="button"
                    class="col-span-2 inline-flex h-10 items-center justify-center gap-1.5 rounded-xl px-3 text-sm font-medium text-[#64748B] hover:bg-slate-100 sm:col-span-1 dark:text-neutral-400 dark:hover:bg-neutral-800"
                    @click="limpiarFiltros"
                >
                    <X class="size-4" />
                    Limpiar
                </button>
            </div>
        </div>

        <!-- Aviso tras convertir una nota de venta -->
        <div
            v-if="ticketConversion"
            class="mb-4 flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-emerald-500/20 dark:bg-emerald-500/10"
        >
            <div class="flex items-center gap-2 text-emerald-800 dark:text-emerald-300">
                <CheckCircle2 class="size-5 shrink-0" />
                <span>{{ ticketConversion.mensaje }}</span>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex h-9 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                    @click="imprimirTicket(ticketConversion.ticket)"
                >
                    <Printer class="size-4" />
                    Imprimir ticket
                </button>
                <button
                    class="rounded-lg p-1.5 text-emerald-700 hover:bg-emerald-100 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                    title="Cerrar"
                    @click="ticketConversion = null"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>

        <!-- Tabla (PC) y tarjetas (celular) -->
        <div class="overflow-hidden rounded-2xl border border-[#E2E8F0] bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container relative hidden overflow-x-auto md:block">
                <table class="w-full min-w-[68rem] text-left text-sm">
                    <thead class="border-b border-[#E2E8F0] bg-slate-50/70 text-[11px] text-[#64748B] uppercase dark:border-neutral-800 dark:bg-neutral-950/40 dark:text-neutral-400">
                        <tr>
                            <th class="w-10 py-3 pl-4" />
                            <th v-for="col in COLUMNAS" :key="col.id" class="px-3 py-3" :class="col.clase">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 font-semibold tracking-wider uppercase transition-colors hover:text-[#0F172A] dark:hover:text-neutral-100"
                                    :class="orden.columna === col.id ? 'text-[#0F172A] dark:text-neutral-100' : ''"
                                    :aria-label="`Ordenar por ${col.titulo}`"
                                    @click="ordenarPor(col.id)"
                                >
                                    {{ col.titulo }}
                                    <ChevronUp v-if="orden.columna === col.id && orden.dir === 'asc'" class="size-3.5" />
                                    <ChevronDown v-else-if="orden.columna === col.id" class="size-3.5" />
                                    <ChevronsUpDown v-else class="size-3.5 opacity-60" />
                                </button>
                            </th>
                            <th class="py-3 pr-4 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F1F5F9] dark:divide-neutral-800">
                        <tr v-if="!comprobantes.data.length">
                            <td colspan="10" class="px-4 py-14 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <ReceiptText class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay comprobantes que mostrar.
                                </div>
                            </td>
                        </tr>
                        <template v-for="c in comprobantes.data" :key="c.id">
                            <tr
                                class="cursor-pointer transition-colors hover:bg-slate-50/80 dark:hover:bg-neutral-800/40"
                                :class="[expandido === c.id ? 'bg-slate-50/80 dark:bg-neutral-800/40' : '', c.estado === 'anulado' ? 'opacity-70' : '']"
                                @click="verDetalle(c)"
                            >
                                <td class="py-4 pl-4">
                                    <button
                                        type="button"
                                        class="grid size-7 place-items-center rounded-lg hover:bg-slate-200/70 dark:hover:bg-neutral-700"
                                        :aria-label="expandido === c.id ? 'Ocultar pagos y estado SUNAT' : 'Ver pagos y estado SUNAT'"
                                        :title="expandido === c.id ? 'Ocultar pagos y estado SUNAT' : 'Ver pagos y estado SUNAT'"
                                        @click.stop="alternarDetalle(c)"
                                    >
                                        <ChevronDown class="size-4 text-[#64748B] transition-transform" :class="expandido === c.id ? 'rotate-180' : ''" />
                                    </button>
                                </td>
                                <td class="px-3 py-4">
                                    <p class="font-semibold whitespace-nowrap text-[#0F172A] dark:text-neutral-100">{{ numero(c) }}</p>
                                    <p v-if="c.notas?.length" class="text-xs whitespace-nowrap text-amber-600 dark:text-amber-400">
                                        {{ c.notas.length }} nota{{ c.notas.length > 1 ? 's' : '' }} de crédito
                                    </p>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="estiloTipo(c).clase">
                                        <FileText class="size-3.5" />
                                        {{ estiloTipo(c).texto }}
                                    </span>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap">
                                    <p class="flex items-center gap-2 text-[#0F172A] dark:text-neutral-100"><CalendarDays class="size-4 text-[#94A3B8]" />{{ fecha(c) }}</p>
                                    <p class="mt-0.5 flex items-center gap-2 text-xs text-[#64748B] dark:text-neutral-400"><Clock3 class="size-4 text-[#94A3B8]" />{{ hora(c) }}</p>
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full text-sm font-semibold" :class="c.cliente_nombre ? colorAvatar(c.cliente_nombre) : 'bg-slate-100 text-[#64748B] dark:bg-neutral-800 dark:text-neutral-400'">
                                            <template v-if="c.cliente_nombre">{{ inicial(c.cliente_nombre) }}</template>
                                            <Users v-else class="size-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="max-w-56 truncate font-medium text-[#0F172A] dark:text-neutral-100" :title="c.cliente_nombre ?? ''">{{ c.cliente_nombre ?? 'Público general' }}</p>
                                            <p class="text-xs text-[#94A3B8]">{{ c.cliente_numero_doc || '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right text-base font-bold whitespace-nowrap text-[#0F172A] tabular-nums dark:text-neutral-100">{{ soles(c.total) }}</td>
                                <td class="px-3 py-4">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="c.estado === 'emitido' ? ESTILO_OK : ESTILO_ERROR">
                                        <span class="size-1.5 rounded-full bg-current" />
                                        {{ c.estado === 'emitido' ? 'Emitido' : 'Anulado' }}
                                    </span>
                                </td>
                                <td class="px-3 py-4">
                                    <button
                                        v-if="esElectronico(c) && puede('comprobantes.sunat') && (puedeReenviar(c) || bajaPendiente(c))"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap ring-1 ring-transparent transition hover:ring-current disabled:opacity-60"
                                        :class="badgeSunat(c)[1]"
                                        :disabled="enviandoSunat === c.id"
                                        :title="(bajaPendiente(c) ? 'Consultar la baja en SUNAT' : 'Enviar a SUNAT') + (c.sunat?.mensaje_sunat ? ` · ${c.sunat.mensaje_sunat}` : '')"
                                        @click.stop="enviarSunat(c)"
                                    >
                                        <LoaderCircle v-if="enviandoSunat === c.id" class="size-3 animate-spin" />
                                        <CloudUpload v-else class="size-3" />
                                        {{ badgeSunat(c)[0] }}
                                    </button>
                                    <span
                                        v-else-if="esElectronico(c)"
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                        :class="badgeSunat(c)[1]"
                                        :title="c.sunat?.mensaje_sunat ?? ''"
                                    >
                                        <span class="size-1.5 rounded-full bg-current" />
                                        {{ badgeSunat(c)[0] }}
                                    </span>
                                    <span v-else class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-[#94A3B8] dark:bg-neutral-800 dark:text-neutral-500" title="Las notas de venta no van a SUNAT">No aplica</span>
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex items-center gap-2.5">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-[#475569] dark:bg-neutral-800 dark:text-neutral-300">{{ inicial(c.usuario?.nombre_completo) }}</span>
                                        <span class="max-w-32 truncate text-[#475569] dark:text-neutral-300">{{ primerNombre(c.usuario?.nombre_completo) }}</span>
                                    </div>
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" :class="claseAccion" title="Imprimir ticket" aria-label="Imprimir ticket" @click.stop="imprimirTicket(`/comprobantes/${c.id}/ticket`)">
                                            <Printer class="size-4" />
                                        </button>
                                        <a :href="`/comprobantes/${c.id}/a4`" target="_blank" rel="noopener" :class="claseAccion" title="PDF en A4" aria-label="PDF en A4" @click.stop>
                                            <FileText class="size-4" />
                                        </a>
                                        <button type="button" :class="claseAccion" title="Enviar por correo" aria-label="Enviar por correo" @click.stop="abrirCorreo(c)">
                                            <Mail class="size-4" />
                                        </button>
                                        <Link
                                            v-if="puedeGuia(c)"
                                            :href="`/guias/crear?comprobante=${c.id}`"
                                            :class="claseAccion"
                                            title="Generar guía de remisión para entregar esta venta"
                                            aria-label="Generar guía de remisión"
                                            @click.stop
                                        >
                                            <Navigation class="size-4" />
                                        </Link>
                                        <span v-else class="size-10" aria-hidden="true" />
                                        <span class="mx-1 h-7 w-px bg-[#E2E8F0] dark:bg-neutral-700" />
                                        <button
                                            type="button"
                                            class="relative grid size-9 place-items-center rounded-xl text-[#475569] transition-colors hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            :class="menu?.c.id === c.id ? 'bg-slate-100 dark:bg-neutral-800' : ''"
                                            title="Más acciones"
                                            aria-label="Más acciones"
                                            @click.stop="abrirMenu(c, $event)"
                                        >
                                            <EllipsisVertical class="size-5" />
                                            <span v-if="requiereAtencion(c)" class="absolute top-1.5 right-1.5 size-2 rounded-full bg-amber-500 ring-2 ring-white dark:ring-neutral-900" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Detalle expandido -->
                            <tr v-if="expandido === c.id">
                                <td colspan="10" class="bg-slate-50/80 px-6 pt-1 pb-5 dark:bg-neutral-950/40">
                                    <DetalleComprobante :c="c" :enviando-sunat="enviandoSunat" @enviar-sunat="enviarSunat" @reemitir="reemitir" @correo="abrirCorreo" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Celular: tarjetas -->
            <div class="divide-y divide-[#F1F5F9] md:hidden dark:divide-neutral-800">
                <p v-if="!comprobantes.data.length" class="px-4 py-12 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    <ReceiptText class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                    No hay comprobantes que mostrar.
                </p>
                <div v-for="c in comprobantes.data" :key="c.id" class="p-4" :class="c.estado === 'anulado' ? 'opacity-70' : ''">
                    <div class="flex items-start justify-between gap-3" @click="verDetalle(c)">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-[#0F172A] dark:text-neutral-100">{{ numero(c) }}</span>
                                <span class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[11px] font-semibold" :class="estiloTipo(c).clase">{{ estiloTipo(c).texto }}</span>
                            </div>
                            <p class="mt-1 truncate text-sm text-[#475569] dark:text-neutral-300">{{ c.cliente_nombre ?? 'Público general' }}</p>
                            <p class="text-xs text-[#94A3B8]">{{ fecha(c) }} · {{ hora(c) }} · {{ primerNombre(c.usuario?.nombre_completo) }}</p>
                        </div>
                        <p class="shrink-0 text-lg font-bold text-[#0F172A] tabular-nums dark:text-neutral-100">{{ soles(c.total) }}</p>
                    </div>
                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="c.estado === 'emitido' ? ESTILO_OK : ESTILO_ERROR">
                            <span class="size-1.5 rounded-full bg-current" />{{ c.estado === 'emitido' ? 'Emitido' : 'Anulado' }}
                        </span>
                        <span v-if="esElectronico(c)" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="badgeSunat(c)[1]">
                            <span class="size-1.5 rounded-full bg-current" />SUNAT: {{ badgeSunat(c)[0] }}
                        </span>
                        <span v-if="c.notas?.length" class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ c.notas.length }} N. crédito</span>
                    </div>
                    <div class="mt-3 flex items-center gap-1.5">
                        <button type="button" :class="claseAccion" aria-label="Imprimir ticket" @click="imprimirTicket(`/comprobantes/${c.id}/ticket`)"><Printer class="size-4" /></button>
                        <a :href="`/comprobantes/${c.id}/a4`" target="_blank" rel="noopener" :class="claseAccion" aria-label="PDF en A4"><FileText class="size-4" /></a>
                        <button type="button" :class="claseAccion" aria-label="Enviar por correo" @click="abrirCorreo(c)"><Mail class="size-4" /></button>
                        <Link v-if="puedeGuia(c)" :href="`/guias/crear?comprobante=${c.id}`" :class="claseAccion" aria-label="Generar guía de remisión"><Navigation class="size-4" /></Link>
                        <button
                            type="button"
                            class="ml-auto inline-flex h-10 items-center gap-1 rounded-xl px-3 text-sm font-medium text-[#475569] hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="alternarDetalle(c)"
                        >
                            Más info
                            <ChevronDown class="size-4 transition-transform" :class="expandido === c.id ? 'rotate-180' : ''" />
                        </button>
                        <button type="button" class="relative grid size-10 place-items-center rounded-xl text-[#475569] hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800" aria-label="Más acciones" @click.stop="abrirMenu(c, $event)">
                            <EllipsisVertical class="size-5" />
                            <span v-if="requiereAtencion(c)" class="absolute top-2 right-2 size-2 rounded-full bg-amber-500" />
                        </button>
                    </div>
                    <div v-if="expandido === c.id" class="mt-3 rounded-xl bg-slate-50 p-3 dark:bg-neutral-950/50">
                        <DetalleComprobante :c="c" :enviando-sunat="enviandoSunat" @enviar-sunat="enviarSunat" @reemitir="reemitir" @correo="abrirCorreo" />
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div
                v-if="comprobantes.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ comprobantes.from }}–{{ comprobantes.to }} de {{ comprobantes.total }} comprobantes</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in comprobantes.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            preserve-state
                            class="rounded-lg border px-3.5 py-1.5"
                            :class="link.active
                                ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal de detalle: solo los productos -->
        <Teleport to="body">
            <div v-if="ver" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-3 sm:p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="verId = null" />
                <div class="relative w-full max-w-3xl min-w-0 rounded-2xl border border-[#E2E8F0] bg-white text-[#0F172A] shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" role="dialog" aria-modal="true">
                    <div class="flex items-center justify-between gap-3 border-b border-[#E2E8F0] px-5 py-4 dark:border-neutral-800">
                        <h3 class="font-semibold tracking-tight">Detalle de {{ numero(ver) }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-slate-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="verId = null">
                            <X class="size-5" />
                        </button>
                    </div>
                    <!-- celular: una fila por producto -->
                    <div class="max-h-[70vh] divide-y divide-[#F1F5F9] overflow-y-auto sm:hidden dark:divide-neutral-800">
                        <div v-for="(d, i) in ver.detalles" :key="d.id" class="flex gap-3 px-5 py-3 text-sm">
                            <span class="w-5 shrink-0 text-[#94A3B8] tabular-nums">{{ i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ d.descripcion }}</p>
                                <p class="text-xs text-[#64748B] dark:text-neutral-400">
                                    {{ cantidadTexto(d.cantidad) }} {{ nombreUnidad(d.unidad_codigo) }} × {{ soles(d.precio_unitario) }}
                                </p>
                            </div>
                            <span class="shrink-0 font-semibold tabular-nums">{{ soles(d.total) }}</span>
                        </div>
                        <div class="flex justify-between px-5 py-3">
                            <span class="font-semibold">Total</span>
                            <span class="text-base font-bold tabular-nums">{{ soles(ver.total) }}</span>
                        </div>
                    </div>
                    <div class="hidden max-h-[70vh] overflow-auto sm:block">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 border-b border-[#E2E8F0] bg-slate-50 text-[11px] text-[#64748B] uppercase dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-400">
                                <tr>
                                    <th class="w-12 py-2.5 pl-5 font-semibold tracking-wider">Ítem</th>
                                    <th class="px-3 py-2.5 font-semibold tracking-wider">Producto</th>
                                    <th class="px-3 py-2.5 font-semibold tracking-wider">Unidad</th>
                                    <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Cantidad</th>
                                    <th class="px-3 py-2.5 text-right font-semibold tracking-wider">P. unitario</th>
                                    <th class="py-2.5 pr-5 text-right font-semibold tracking-wider">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F1F5F9] dark:divide-neutral-800">
                                <tr v-for="(d, i) in ver.detalles" :key="d.id">
                                    <td class="py-3 pl-5 text-[#94A3B8] tabular-nums">{{ i + 1 }}</td>
                                    <td class="px-3 py-3 font-medium">{{ d.descripcion }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap text-[#475569] dark:text-neutral-300">{{ nombreUnidad(d.unidad_codigo) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ cantidadTexto(d.cantidad) }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums">{{ soles(d.precio_unitario) }}</td>
                                    <td class="py-3 pr-5 text-right font-semibold whitespace-nowrap tabular-nums">{{ soles(d.total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-[#E2E8F0] dark:border-neutral-800">
                                <tr>
                                    <td colspan="5" class="py-3 pl-5 text-right font-semibold">Total</td>
                                    <td class="py-3 pr-5 text-right text-base font-bold whitespace-nowrap tabular-nums">{{ soles(ver.total) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Menú de más acciones -->
        <Teleport to="body">
            <div
                v-if="menu"
                ref="panelMenu"
                class="fixed z-40 w-64 overflow-hidden rounded-2xl border border-[#E2E8F0] bg-white p-1.5 text-sm text-[#0F172A] shadow-xl shadow-slate-900/10 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                :style="{ left: `${menu.left}px`, top: `${menu.top}px`, transform: menu.arriba ? 'translateY(-100%)' : undefined }"
                role="menu"
            >
                <p class="px-2.5 pt-1.5 pb-1 text-[11px] font-semibold tracking-wider text-[#94A3B8] uppercase">{{ numero(menu.c) }}</p>
                <button type="button" :class="claseItemMenu" role="menuitem" @click="accion(verDetalle)">
                    <Eye class="size-4 text-[#64748B]" />
                    Ver detalle
                </button>
                <button v-if="puedeConvertir(menu.c)" type="button" :class="claseItemMenu" role="menuitem" @click="accion(abrirConversion)">
                    <ReceiptText class="size-4 text-emerald-600" />
                    Emitir boleta o factura
                </button>
                <button v-if="puede('comprobantes.sunat') && puedeReemitir(menu.c)" type="button" :class="claseItemMenu" role="menuitem" :title="AYUDA_REEMITIR" @click="accion(reemitir)">
                    <RefreshCw class="size-4 text-emerald-600" />
                    Corregir y reenviar a SUNAT
                </button>
                <button v-if="puede('comprobantes.sunat') && (puedeReenviar(menu.c) || bajaPendiente(menu.c))" type="button" :class="claseItemMenu" role="menuitem" @click="accion(enviarSunat)">
                    <CloudUpload class="size-4 text-emerald-600" />
                    {{ bajaPendiente(menu.c) ? 'Consultar la baja en SUNAT' : 'Enviar a SUNAT' }}
                </button>
                <button v-if="puedeNotaCredito(menu.c)" type="button" :class="claseItemMenu" role="menuitem" @click="accion(abrirNotaCredito)">
                    <Undo2 class="size-4 text-amber-600" />
                    Emitir nota de crédito
                </button>
                <a v-if="menu.c.sunat?.xml_url" :href="`/comprobantes/${menu.c.id}/xml`" :class="claseItemMenu" role="menuitem" @click="menu = null">
                    <FileCode2 class="size-4 text-[#64748B]" />
                    Descargar XML
                </a>
                <a v-if="menu.c.sunat?.cdr_url" :href="`/comprobantes/${menu.c.id}/cdr`" :class="claseItemMenu" role="menuitem" @click="menu = null">
                    <ShieldCheck class="size-4 text-[#64748B]" />
                    Descargar CDR
                </a>
                <template v-if="puedeAnular(menu.c)">
                    <div class="my-1 h-px bg-[#F1F5F9] dark:bg-neutral-800" />
                    <button type="button" :class="[claseItemMenu, '!text-red-600 hover:!bg-red-50 dark:!text-red-400 dark:hover:!bg-red-950/40']" role="menuitem" @click="accion(abrirAnulacion)">
                        <Ban class="size-4" />
                        Anular
                    </button>
                </template>
            </div>
        </Teleport>

        <!-- Modal nota de crédito -->
        <Teleport to="body">
            <div v-if="comprobanteNota" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="comprobanteNota = null" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="emitirNota"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Nota de crédito sobre {{ numero(comprobanteNota) }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="comprobanteNota = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <label class="mb-1 block text-sm font-medium" for="motivo-nc">Motivo *</label>
                    <select
                        id="motivo-nc"
                        v-model="formNota.motivo"
                        class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <option v-for="m in MOTIVOS_NC" :key="m.codigo" :value="m.codigo">{{ m.nombre }}</option>
                    </select>
                    <p v-if="formNota.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formNota.errors.motivo }}</p>

                    <!-- items para devolucion parcial -->
                    <div v-if="formNota.motivo === '07'" class="mt-4">
                        <p class="mb-2 text-sm font-medium">Productos a devolver</p>
                        <div class="max-h-48 space-y-2 overflow-y-auto pr-1">
                            <div
                                v-for="d in comprobanteNota.detalles"
                                :key="d.id"
                                class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 px-3 py-2 text-sm dark:border-neutral-800"
                            >
                                <div class="min-w-0">
                                    <p class="truncate">{{ d.descripcion }}</p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                        Vendido: {{ Number(d.cantidad) }} × S/ {{ Number(d.precio_unitario).toFixed(2) }}
                                    </p>
                                </div>
                                <input
                                    v-model="cantidadesNota[d.id]"
                                    type="number"
                                    min="0"
                                    :max="Number(d.cantidad)"
                                    step="any"
                                    placeholder="0"
                                    class="h-9 w-20 rounded-lg border border-stone-300 bg-white px-2 text-right text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                />
                            </div>
                        </div>
                        <p v-if="formNota.errors.items" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formNota.errors.items }}</p>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="medio-nc">Devolver por *</label>
                        <select
                            id="medio-nc"
                            v-model="formNota.medio_pago_codigo"
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option v-for="mp in mediosPago" :key="mp.codigo" :value="mp.codigo">{{ mp.nombre }}</option>
                        </select>
                        <p v-if="formNota.errors.medio_pago_codigo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formNota.errors.medio_pago_codigo }}</p>
                    </div>

                    <div v-if="medioSeleccionadoNota?.requiere_referencia" class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="referencia-nc">Referencia</label>
                        <input
                            id="referencia-nc"
                            v-model="formNota.referencia"
                            type="text"
                            placeholder="Nº de operación"
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                        />
                        <p v-if="formNota.errors.referencia" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formNota.errors.referencia }}</p>
                    </div>

                    <div class="mt-4 flex items-center justify-between rounded-xl bg-stone-100 px-4 py-2.5 text-sm dark:bg-neutral-800">
                        <span class="text-neutral-500 dark:text-neutral-400">Total a acreditar</span>
                        <span class="font-semibold">{{ soles(totalNota) }}</span>
                    </div>

                    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                        Se repone el stock de lo devuelto. Si la venta fue al crédito, se descuenta primero de la deuda del cliente;
                        el resto se devuelve por el medio elegido (solo el efectivo sale de tu caja abierta).
                        La nota se envía a SUNAT automáticamente.
                    </p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="comprobanteNota = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formNota.processing || totalNota <= 0"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formNota.processing ? 'Emitiendo...' : 'Emitir nota de crédito' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal convertir nota de venta -->
        <Teleport to="body">
            <div v-if="comprobanteConvertir" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="comprobanteConvertir = null" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="convertir"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold tracking-tight">Emitir comprobante electrónico</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                A partir de la nota de venta {{ numero(comprobanteConvertir) }} · {{ soles(comprobanteConvertir.total) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="comprobanteConvertir = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <!-- Tipo -->
                    <p class="mb-1 text-sm font-medium">Tipo *</p>
                    <div class="grid gap-2" :class="esRus ? 'grid-cols-1' : 'grid-cols-2'">
                        <button
                            v-for="t in esRus ? [{ codigo: '03', nombre: 'Boleta' }] : [{ codigo: '03', nombre: 'Boleta' }, { codigo: '01', nombre: 'Factura' }]"
                            :key="t.codigo"
                            type="button"
                            class="h-10 rounded-xl border text-sm font-medium transition-colors"
                            :class="tipoConversion === t.codigo
                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            @click="tipoConversion = t.codigo"
                        >
                            {{ t.nombre }}
                        </button>
                    </div>
                    <p v-if="erroresConversion.tipo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ erroresConversion.tipo }}</p>

                    <!-- Cliente -->
                    <p class="mt-4 mb-1 text-sm font-medium">Cliente</p>
                    <div
                        v-if="clienteConversion"
                        class="flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-500/20 dark:bg-emerald-500/10"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <UserRound class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ clienteConversion.nombre }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ clienteConversion.numero_documento ?? 'Sin documento' }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-emerald-100 hover:text-neutral-700 dark:hover:bg-emerald-950/40 dark:hover:text-neutral-200"
                            title="Quitar cliente"
                            @click="clienteConversion = null"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <div v-else>
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                v-model="buscarClienteConv"
                                type="text"
                                placeholder="Buscar por nombre, DNI o RUC..."
                                class="h-10 w-full rounded-xl border border-stone-300 bg-white pr-9 pl-9 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            />
                            <LoaderCircle v-if="buscandoClienteConv" class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-neutral-400" />
                        </div>
                        <div
                            v-if="resultadosClienteConv.length"
                            class="mt-1 max-h-48 divide-y divide-stone-100 overflow-y-auto rounded-xl border border-stone-200 dark:divide-neutral-800 dark:border-neutral-800"
                        >
                            <button
                                v-for="cli in resultadosClienteConv"
                                :key="cli.id"
                                type="button"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-800"
                                @click="elegirClienteConversion(cli)"
                            >
                                {{ cli.nombre }}
                                <span v-if="cli.numero_documento" class="ml-2 text-xs text-neutral-500 dark:text-neutral-400">{{ cli.numero_documento }}</span>
                            </button>
                        </div>
                        <p
                            v-else-if="buscarClienteConv.trim() && !buscandoClienteConv"
                            class="mt-1 text-xs text-neutral-500 dark:text-neutral-400"
                        >
                            Sin resultados. Registra al cliente desde Clientes.
                        </p>
                        <p v-if="tipoConversion === '03'" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            Sin cliente: la boleta se emite a público general.
                        </p>
                    </div>
                    <p v-if="erroresConversion.cliente_id" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ erroresConversion.cliente_id }}</p>

                    <!-- Ayuda -->
                    <p
                        v-if="tipoConversion === '01'"
                        class="mt-4 rounded-xl bg-stone-100 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                    >
                        La factura necesita un cliente con RUC.
                    </p>
                    <p
                        v-else-if="Number(comprobanteConvertir.total) >= 700"
                        class="mt-4 rounded-xl bg-amber-100 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
                    >
                        Las boletas desde S/ 700 deben identificar al cliente con su documento.
                    </p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="comprobanteConvertir = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="convirtiendo || (tipoConversion === '01' && !clienteConversion)"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ convirtiendo ? 'Emitiendo...' : 'Emitir' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal anulación -->
        <Teleport to="body">
            <div v-if="comprobanteAnular" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="comprobanteAnular = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="anular"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Anular {{ numero(comprobanteAnular) }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="comprobanteAnular = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Se repondrá el stock vendido y los pagos dejarán de contar en caja. Esta acción no se puede deshacer.
                    </p>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="motivo">Motivo *</label>
                        <input
                            id="motivo"
                            v-model="formAnular.motivo"
                            type="text"
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            placeholder="Ej. error de digitación"
                            autofocus
                        />
                        <p v-if="formAnular.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formAnular.errors.motivo }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="comprobanteAnular = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formAnular.processing"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formAnular.processing ? 'Anulando...' : 'Anular comprobante' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal enviar por correo -->
        <Teleport to="body">
            <div v-if="comprobanteCorreo" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="comprobanteCorreo = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="enviarCorreo"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Enviar {{ numero(comprobanteCorreo) }} por correo</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="comprobanteCorreo = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <label class="mb-1 block text-sm font-medium" for="email-correo">Correo *</label>
                    <input
                        id="email-correo"
                        v-model="formCorreo.email"
                        type="email"
                        placeholder="cliente@correo.com"
                        autofocus
                        class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                    />
                    <p v-if="formCorreo.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formCorreo.errors.email }}</p>

                    <label
                        v-if="comprobanteCorreo.cliente_id && !comprobanteCorreo.cliente?.email"
                        class="mt-3 flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="formCorreo.guardar_en_cliente"
                            type="checkbox"
                            class="size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-700"
                        />
                        Guardar este correo en la ficha del cliente
                    </label>

                    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                        Se adjunta el PDF y, si fue enviado a SUNAT, el XML firmado.
                    </p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="comprobanteCorreo = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formCorreo.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formCorreo.processing ? 'Enviando...' : 'Enviar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
