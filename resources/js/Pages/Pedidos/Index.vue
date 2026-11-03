<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { AlertCircle, Ban, CalendarClock, CheckCircle2, ClipboardList, Eye, HandCoins, MessageCircle, Pencil, Plus, Printer, Search, ShoppingCart, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { useImpresion } from '@/composables/impresion'
import { usePermisos } from '@/composables/permisos'
import { useSucursales } from '@/composables/sucursales'
import ModalAdelanto from './ModalAdelanto.vue'
import ModalAnulacion from './ModalAnulacion.vue'

const props = defineProps({
    pedidos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    conteos: { type: Object, required: true },
    empresaNombre: { type: String, default: '' },
    mediosPago: { type: Array, default: () => [] },
    cajaAbierta: { type: Boolean, default: false },
    cajaSucursalId: { type: String, default: null },
})

const { puede } = usePermisos()
const { multisucursal } = useSucursales()
const { confirmar } = useConfirmar()
const { imprimirTicket } = useImpresion()

// "Orden de pedido" térmica (igual que el ticket de venta): sirve para preparar o para entregarle al cliente
const imprimirOrden = (p) => imprimirTicket(`/pedidos/${p.id}/ticket`)

const soles =(n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
const fechaLocal = (f) => (f ? new Date(`${String(f).slice(0, 10)}T00:00:00`) : null)
// "mié 03 nov" para la entrega; con el año solo si no es el actual
const fechaCorta = (f) => {
    const d = fechaLocal(f)
    if (!d) return '—'
    const conAnio = d.getFullYear() !== new Date().getFullYear()
    return d.toLocaleDateString('es-PE', { weekday: 'short', day: '2-digit', month: 'short', ...(conAnio ? { year: 'numeric' } : {}) }).replace(/\.,?/g, '')
}
const fechaLarga = (f) => fechaLocal(f)?.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) ?? '—'
const fechaHora = (iso) => (iso ? new Date(iso).toLocaleString('es-PE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
const ddmm = (f) => (f ? `${String(f).slice(8, 10)}/${String(f).slice(5, 7)}` : '')

// ---- pestañas ----
const PESTANAS = [
    { clave: 'atrasados', texto: 'Atrasados', vacio: 'Sin pedidos atrasados. Todo al día.' },
    { clave: 'hoy', texto: 'Hoy', vacio: 'No hay pedidos para hoy.' },
    { clave: 'manana', texto: 'Mañana', vacio: 'No hay pedidos para mañana.' },
    { clave: 'proximos', texto: 'Próximos', vacio: 'No hay pedidos programados para los próximos días.' },
    { clave: 'historial', texto: 'Historial', vacio: 'Aún no hay pedidos entregados ni anulados.' },
]

const pestana = ref(props.filtros.pestana ?? 'hoy')
const buscar = ref(props.filtros.buscar ?? '')

function navegar() {
    router.get('/pedidos', {
        pestana: pestana.value,
        buscar: buscar.value.trim() || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watch(pestana, navegar)
watchDebounced(buscar, navegar, { debounce: 350 })

const textoVacio = computed(() => {
    if (buscar.value.trim()) return `No hay pedidos que coincidan con "${buscar.value.trim()}" en esta pestaña.`
    return PESTANAS.find((p) => p.clave === pestana.value)?.vacio ?? 'No hay pedidos.'
})

// ---- estado ----
const ESTADOS = {
    pendiente: { texto: 'Pendiente', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
    listo: { texto: 'Listo', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    atrasado: { texto: 'Atrasado', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' },
    entregado: { texto: 'Entregado', clase: 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300' },
    anulado: { texto: 'Anulado', clase: 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400/80' },
}
// atrasado manda sobre pendiente/listo
const estadoDe = (p) => (p.atrasado && (p.estado === 'pendiente' || p.estado === 'listo') ? ESTADOS.atrasado : ESTADOS[p.estado])
const abierto = (p) => p.estado === 'pendiente' || p.estado === 'listo'

// "2 × Plantín lechuga millar, 1 × Urea saco"
const resumenProductos = (p) => p.detalles.map((d) => `${cantidad(d.cantidad)} × ${d.descripcion}`).join(', ')

// ---- adelanto: exige caja abierta en la sucursal del pedido ----
const deOtraSucursal = (p) => !!props.cajaSucursalId && !!p.sucursal_id && p.sucursal_id !== props.cajaSucursalId
const puedeAdelantar = (p) => puede('pedidos.gestionar') && abierto(p) && Number(p.saldo) > 0
const motivoSinAdelanto = (p) => {
    if (!props.cajaAbierta) return 'Abre caja para recibir adelantos'
    if (deOtraSucursal(p)) return 'Este pedido es de otra sucursal'
    return ''
}

const adelantoDe = ref(null)
const anularDe = ref(null)
const ver = ref(null)

// ---- marcar listo ----
async function marcarListo(p) {
    const ok = await confirmar({
        titulo: `Marcar ${p.codigo} como listo`,
        mensaje: `El pedido de ${p.cliente_nombre} quedará listo para recoger. Luego puedes avisarle por WhatsApp.`,
        textoConfirmar: 'Marcar listo',
    })
    if (!ok) return
    router.post(`/pedidos/${p.id}/listo`, {}, { preserveScroll: true, onSuccess: () => (ver.value = null) })
}

// ---- WhatsApp ----
const enviar = ref(null)
const telefono = ref('')
const errorTelefono = ref('')

function limpiarTelefono(t) {
    let d = String(t ?? '').replace(/\D/g, '')
    if (d.length === 11 && d.startsWith('51')) d = d.slice(2)
    return d
}

const mensajeWhatsapp = (p) => {
    if (p.estado === 'listo') {
        return `Hola ${p.cliente_nombre}, tu pedido ${p.codigo} de ${props.empresaNombre} ya está listo para recoger. Total ${soles(p.total)}, adelanto ${soles(p.total_adelantos)}, saldo a pagar ${soles(p.saldo)}.`
    }
    const hora = p.hora_entrega ? ` a las ${p.hora_entrega}` : ''
    return `Hola ${p.cliente_nombre}, tu pedido ${p.codigo} de ${props.empresaNombre} está programado para el ${ddmm(p.fecha_entrega)}${hora}. Saldo ${soles(p.saldo)}.`
}

function abrirWhatsapp(p) {
    const numero = limpiarTelefono(p.cliente_telefono)
    // con celular válido se abre directo; si no, se pide el número
    if (/^9\d{8}$/.test(numero)) {
        window.open(`https://wa.me/51${numero}?text=${encodeURIComponent(mensajeWhatsapp(p))}`, '_blank', 'noopener')
        return
    }
    enviar.value = p
    telefono.value = numero
    errorTelefono.value = ''
}

function enviarWhatsapp() {
    errorTelefono.value = ''
    const numero = limpiarTelefono(telefono.value)
    if (!/^9\d{8}$/.test(numero)) {
        errorTelefono.value = 'Número de celular no válido'
        return
    }
    window.open(`https://wa.me/51${numero}?text=${encodeURIComponent(mensajeWhatsapp(enviar.value))}`, '_blank', 'noopener')
    enviar.value = null
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseIcono = 'rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
const claseBotonSec = 'inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-stone-300 px-3 text-xs font-semibold hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800'
const claseBotonPri = 'inline-flex h-9 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50'
const claseChip = 'inline-flex rounded-lg px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap'
</script>

<template>
    <AppLayout titulo="Pedidos">
        <!-- Buscador y acción principal -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por cliente, número de pedido o celular..."
                    :class="[claseInput, 'pl-9 pr-9']"
                    data-pedidos-buscar
                />
                <button
                    v-if="buscar"
                    type="button"
                    class="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800"
                    aria-label="Limpiar búsqueda"
                    @click="buscar = ''"
                >
                    <X class="size-4" />
                </button>
            </div>
            <Link
                v-if="puede('pedidos.gestionar')"
                href="/pedidos/crear"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                data-pedidos-nuevo
            >
                <Plus class="size-4" />
                Nuevo pedido
            </Link>
        </div>

        <!-- Aviso caja: sin caja no se reciben adelantos -->
        <div
            v-if="!cajaAbierta && puede('pedidos.gestionar')"
            class="mb-4 flex items-center gap-2.5 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
            data-pedidos-aviso-caja
        >
            <AlertCircle class="size-4 shrink-0" />
            <span>
                Para recibir adelantos necesitas
                <Link href="/caja" class="font-semibold underline">abrir caja</Link>
                (el dinero entra al arqueo del turno).
            </span>
        </div>

        <!-- Pestañas -->
        <div class="scroll-fino -mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="flex w-max min-w-full gap-1 border-b border-stone-200 dark:border-neutral-800" role="tablist">
                <button
                    v-for="t in PESTANAS"
                    :key="t.clave"
                    type="button"
                    role="tab"
                    :aria-selected="pestana === t.clave"
                    class="-mb-px inline-flex h-11 items-center gap-2 border-b-2 px-3 text-sm font-medium whitespace-nowrap transition-colors"
                    :class="pestana === t.clave
                        ? 'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-300'
                        : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100'"
                    :data-pestana="t.clave"
                    @click="pestana = t.clave"
                >
                    {{ t.texto }}
                    <span
                        v-if="conteos[t.clave] > 0"
                        class="inline-flex min-w-5 items-center justify-center rounded-lg px-1.5 py-0.5 text-[11px] font-bold tabular-nums"
                        :class="t.clave === 'atrasados'
                            ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
                            : pestana === t.clave ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'"
                        :data-pestana-conteo="t.clave"
                    >
                        {{ conteos[t.clave] }}
                    </span>
                </button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <!-- vacío -->
            <div v-if="!pedidos.data.length" class="px-4 py-14 text-center text-sm text-neutral-500 dark:text-neutral-400" data-pedidos-vacio>
                <ClipboardList class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                <p>{{ textoVacio }}</p>
                <Link v-if="puede('pedidos.gestionar') && !buscar" href="/pedidos/crear" class="mt-1 inline-block font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                    Registrar un pedido
                </Link>
            </div>

            <!-- celular: tarjetas -->
            <div v-else class="divide-y divide-stone-100 sm:hidden dark:divide-neutral-800" data-pedidos-tarjetas>
                <article
                    v-for="p in pedidos.data"
                    :key="p.id"
                    class="p-4"
                    :class="p.estado === 'anulado' ? 'opacity-60' : ''"
                    :data-pedido="p.id"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ p.cliente_nombre }}</p>
                            <p class="font-mono text-[11px] text-neutral-500 dark:text-neutral-400">
                                {{ p.codigo }}<template v-if="p.cliente_telefono"> · {{ p.cliente_telefono }}</template>
                                <template v-if="multisucursal && p.sucursal"> · {{ p.sucursal }}</template>
                            </p>
                        </div>
                        <span :class="[claseChip, estadoDe(p).clase]">{{ estadoDe(p).texto }}</span>
                    </div>

                    <p class="mt-2 flex items-center gap-1.5 text-sm" :class="p.atrasado ? 'font-semibold text-red-600 dark:text-red-400' : 'text-neutral-700 dark:text-neutral-200'">
                        <CalendarClock class="size-4 shrink-0" />
                        {{ fechaCorta(p.fecha_entrega) }}<template v-if="p.hora_entrega"> · {{ p.hora_entrega }}</template>
                    </p>
                    <p class="mt-1 line-clamp-2 text-xs text-neutral-500 dark:text-neutral-400">{{ resumenProductos(p) }}</p>
                    <p v-if="p.comprobante" class="mt-1 font-mono text-[11px] text-neutral-500 dark:text-neutral-400">Venta {{ p.comprobante }}</p>

                    <div class="mt-3 grid grid-cols-3 gap-2 rounded-xl bg-stone-50 p-2.5 text-center dark:bg-neutral-950">
                        <div>
                            <p class="text-[10px] text-neutral-500 uppercase dark:text-neutral-400">Total</p>
                            <p class="text-sm font-semibold tabular-nums">{{ soles(p.total) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-neutral-500 uppercase dark:text-neutral-400">Adelanto</p>
                            <p class="text-sm font-semibold text-emerald-700 tabular-nums dark:text-emerald-400">{{ soles(p.total_adelantos) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-neutral-500 uppercase dark:text-neutral-400">Saldo</p>
                            <p class="text-sm font-semibold tabular-nums" :class="abierto(p) && p.saldo > 0 ? 'text-amber-600 dark:text-amber-400' : ''">{{ soles(abierto(p) ? p.saldo : 0) }}</p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <Link
                            v-if="abierto(p) && puede('pos.vender')"
                            :href="`/pos?pedido=${p.id}`"
                            :class="claseBotonPri"
                            data-accion-entregar
                        >
                            <ShoppingCart class="size-3.5" />
                            Entregar · Saldo {{ soles(p.saldo) }}
                        </Link>
                        <button
                            v-if="puedeAdelantar(p)"
                            type="button"
                            :class="claseBotonSec"
                            :disabled="!!motivoSinAdelanto(p)"
                            :title="motivoSinAdelanto(p) || 'Registrar un adelanto'"
                            data-accion-adelanto
                            @click="adelantoDe = p"
                        >
                            <HandCoins class="size-3.5" />
                            Adelanto
                        </button>
                        <button
                            v-if="p.estado === 'pendiente' && puede('pedidos.gestionar')"
                            type="button"
                            :class="claseBotonSec"
                            data-accion-listo
                            @click="marcarListo(p)"
                        >
                            <CheckCircle2 class="size-3.5" />
                            Marcar listo
                        </button>
                        <button v-if="abierto(p)" type="button" :class="claseBotonSec" data-accion-whatsapp @click="abrirWhatsapp(p)">
                            <MessageCircle class="size-3.5" />
                            WhatsApp
                        </button>
                        <Link v-if="p.estado === 'pendiente' && puede('pedidos.gestionar')" :href="`/pedidos/${p.id}/editar`" :class="claseBotonSec" data-accion-editar>
                            <Pencil class="size-3.5" />
                            Editar
                        </Link>
                        <button type="button" :class="claseBotonSec" data-accion-detalle @click="ver = p">
                            <Eye class="size-3.5" />
                            Detalle
                        </button>
                        <button v-if="p.estado !== 'anulado'" type="button" :class="claseBotonSec" title="Imprimir la orden de pedido" data-accion-ticket @click="imprimirOrden(p)">
                            <Printer class="size-3.5" />
                            Imprimir
                        </button>
                        <button
                            v-if="abierto(p) && puede('pedidos.anular')"
                            type="button"
                            :class="[claseBotonSec, '!border-red-200 text-red-600 hover:!bg-red-50 dark:!border-red-900/60 dark:text-red-400 dark:hover:!bg-red-950/40']"
                            data-accion-anular
                            @click="anularDe = p"
                        >
                            <Ban class="size-3.5" />
                            Anular
                        </button>
                    </div>
                </article>
            </div>

            <!-- PC: tabla -->
            <div v-if="pedidos.data.length" class="@container relative hidden overflow-x-auto sm:block">
                <table class="w-full text-left text-sm" data-pedidos-tabla>
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-3 py-3.5 pl-4 font-semibold tracking-wider">Pedido</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Entrega</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Cliente y productos</th>
                            <th v-if="multisucursal" class="px-3 py-3.5 font-semibold tracking-wider">Sucursal</th>
                            <th class="px-3 py-3.5 text-right font-semibold tracking-wider">Total</th>
                            <th class="px-3 py-3.5 text-right font-semibold tracking-wider">Adelanto</th>
                            <th class="px-3 py-3.5 text-right font-semibold tracking-wider">Saldo</th>
                            <th class="px-3 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-3 py-3.5 pr-4 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr
                            v-for="p in pedidos.data"
                            :key="p.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            :class="p.estado === 'anulado' ? 'opacity-60' : ''"
                            :data-pedido="p.id"
                            @click="ver = p"
                        >
                            <td class="px-3 py-3 pl-4 whitespace-nowrap">
                                <p class="font-mono text-xs font-semibold">{{ p.codigo }}</p>
                                <p class="text-[11px] text-neutral-500 dark:text-neutral-400">{{ fechaLarga(p.fecha_pedido) }}</p>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <p class="font-medium" :class="p.atrasado ? 'text-red-600 dark:text-red-400' : ''">{{ fechaCorta(p.fecha_entrega) }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ p.hora_entrega ?? 'Sin hora' }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <p class="max-w-[15rem] truncate font-medium">{{ p.cliente_nombre }}</p>
                                <p class="max-w-[15rem] truncate text-xs text-neutral-500 dark:text-neutral-400" :title="resumenProductos(p)">
                                    <span v-if="p.cliente_telefono" class="tabular-nums">{{ p.cliente_telefono }} · </span>{{ resumenProductos(p) }}
                                </p>
                            </td>
                            <td v-if="multisucursal" class="px-3 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ p.sucursal ?? '—' }}</td>
                            <td class="px-3 py-3 text-right font-semibold whitespace-nowrap tabular-nums">{{ soles(p.total) }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap text-emerald-700 tabular-nums dark:text-emerald-400">{{ p.total_adelantos > 0 ? soles(p.total_adelantos) : '—' }}</td>
                            <td class="px-3 py-3 text-right font-semibold whitespace-nowrap tabular-nums" :class="abierto(p) && p.saldo > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-500 dark:text-neutral-400'">
                                {{ abierto(p) ? soles(p.saldo) : '—' }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span :class="[claseChip, estadoDe(p).clase]">{{ estadoDe(p).texto }}</span>
                                <p v-if="p.comprobante" class="mt-0.5 font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ p.comprobante }}</p>
                            </td>
                            <td class="px-3 py-3 pr-4">
                                <div class="flex items-center justify-end gap-0.5">
                                    <Link
                                        v-if="abierto(p) && puede('pos.vender')"
                                        :href="`/pos?pedido=${p.id}`"
                                        class="mr-1 inline-flex h-8 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-semibold whitespace-nowrap text-white hover:bg-emerald-700"
                                        :title="`Cargarlo en el POS y cobrar el saldo de ${soles(p.saldo)}`"
                                        data-accion-entregar
                                        @click.stop
                                    >
                                        <ShoppingCart class="size-3.5" />
                                        Entregar
                                    </Link>
                                    <button
                                        v-if="puedeAdelantar(p)"
                                        type="button"
                                        :class="claseIcono"
                                        :disabled="!!motivoSinAdelanto(p)"
                                        :title="motivoSinAdelanto(p) || 'Registrar un adelanto'"
                                        aria-label="Adelanto"
                                        data-accion-adelanto
                                        @click.stop="adelantoDe = p"
                                    >
                                        <HandCoins class="size-4" />
                                    </button>
                                    <button
                                        v-if="p.estado === 'pendiente' && puede('pedidos.gestionar')"
                                        type="button"
                                        :class="claseIcono"
                                        title="Marcar listo para recoger"
                                        aria-label="Marcar listo"
                                        data-accion-listo
                                        @click.stop="marcarListo(p)"
                                    >
                                        <CheckCircle2 class="size-4" />
                                    </button>
                                    <button v-if="abierto(p)" type="button" :class="claseIcono" title="Avisar por WhatsApp" aria-label="WhatsApp" data-accion-whatsapp @click.stop="abrirWhatsapp(p)">
                                        <MessageCircle class="size-4" />
                                    </button>
                                    <Link v-if="p.estado === 'pendiente' && puede('pedidos.gestionar')" :href="`/pedidos/${p.id}/editar`" :class="claseIcono" title="Editar" aria-label="Editar" data-accion-editar @click.stop>
                                        <Pencil class="size-4" />
                                    </Link>
                                    <button type="button" :class="claseIcono" title="Ver detalle" aria-label="Ver detalle" data-accion-detalle @click.stop="ver = p">
                                        <Eye class="size-4" />
                                    </button>
                                    <button
                                        v-if="p.estado !== 'anulado'"
                                        type="button"
                                        :class="claseIcono"
                                        title="Orden de pedido (imprimir)"
                                        aria-label="Orden de pedido"
                                        data-accion-ticket
                                        @click.stop="imprimirOrden(p)"
                                    >
                                        <Printer class="size-4" />
                                    </button>
                                    <button
                                        v-if="abierto(p) && puede('pedidos.anular')"
                                        type="button"
                                        class="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        title="Anular"
                                        aria-label="Anular"
                                        data-accion-anular
                                        @click.stop="anularDe = p"
                                    >
                                        <Ban class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="pedidos.data.length && pedidos.last_page > 1"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ pedidos.from }}–{{ pedidos.to }} de {{ pedidos.total }} pedidos</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in pedidos.links" :key="i">
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
                        <span v-else class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal detalle -->
        <Teleport to="body">
            <div v-if="ver" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4" data-modal-detalle @keydown.esc="ver = null">
                <div class="fixed inset-0 bg-neutral-950/60" @click="ver = null" />
                <div class="relative w-full min-w-0 max-w-xl overflow-hidden rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4 sm:px-6 dark:border-neutral-800">
                        <div class="min-w-0">
                            <h3 class="flex flex-wrap items-center gap-2 font-semibold tracking-tight">
                                Pedido {{ ver.codigo }}
                                <span :class="[claseChip, estadoDe(ver).clase]">{{ estadoDe(ver).texto }}</span>
                            </h3>
                            <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                {{ ver.cliente_nombre }}<template v-if="ver.cliente_telefono"> · {{ ver.cliente_telefono }}</template> · pedido el {{ fechaLarga(ver.fecha_pedido) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            aria-label="Cerrar"
                            @click="ver = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="scroll-fino max-h-[60vh] overflow-y-auto px-5 py-4 sm:px-6">
                        <dl class="mb-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div>
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Entrega</dt>
                                <dd class="font-medium" :class="ver.atrasado ? 'text-red-600 dark:text-red-400' : ''">
                                    {{ fechaLarga(ver.fecha_entrega) }}<template v-if="ver.hora_entrega"> · {{ ver.hora_entrega }}</template>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Registró</dt>
                                <dd class="font-medium">{{ ver.vendedor ?? '—' }}<template v-if="multisucursal && ver.sucursal"> · {{ ver.sucursal }}</template></dd>
                            </div>
                            <div v-if="ver.observaciones" class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Observaciones</dt>
                                <dd class="whitespace-pre-line">{{ ver.observaciones }}</dd>
                            </div>
                            <div v-if="ver.comprobante" class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Se entregó con</dt>
                                <dd class="font-mono font-medium">{{ ver.comprobante }}</dd>
                            </div>
                            <div v-if="ver.estado === 'anulado'" class="col-span-2 rounded-xl bg-red-50 px-3 py-2 text-red-800 dark:bg-red-500/10 dark:text-red-300">
                                <dt class="text-xs font-semibold">Anulado</dt>
                                <dd>
                                    {{ ver.motivo_anulacion || 'Sin motivo registrado' }}
                                    <template v-if="ver.total_adelantos > 0">
                                        · {{ ver.devolucion_monto > 0 ? `Se devolvieron ${soles(ver.devolucion_monto)}` : `Adelanto de ${soles(ver.total_adelantos)} no devuelto` }}
                                    </template>
                                </dd>
                            </div>
                        </dl>

                        <table class="w-full text-left text-sm" data-detalle-productos>
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pb-2 font-semibold tracking-wider">Producto</th>
                                    <th class="pb-2 text-center font-semibold tracking-wider">Cant.</th>
                                    <th class="hidden pb-2 text-right font-semibold tracking-wider sm:table-cell">P. unit.</th>
                                    <th class="pb-2 text-right font-semibold tracking-wider">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="d in ver.detalles" :key="d.id">
                                    <td class="py-2.5 pr-2">
                                        {{ d.descripcion }}
                                        <span class="block text-xs text-neutral-500 sm:hidden dark:text-neutral-400">{{ soles(d.precio_unitario) }} c/u</span>
                                        <span v-if="d.descuento > 0" class="block text-xs text-amber-600 dark:text-amber-400">Descuento −{{ soles(d.descuento) }}</span>
                                    </td>
                                    <td class="py-2.5 text-center whitespace-nowrap">{{ cantidad(d.cantidad) }} <span class="text-xs text-neutral-400">{{ d.unidad_codigo }}</span></td>
                                    <td class="hidden py-2.5 text-right whitespace-nowrap text-neutral-600 sm:table-cell dark:text-neutral-300">{{ soles(d.precio_unitario) }}</td>
                                    <td class="py-2.5 text-right font-medium whitespace-nowrap">{{ soles(d.total) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="ver.adelantos.length" class="mt-4">
                            <p class="mb-2 text-xs font-semibold text-neutral-400 uppercase dark:text-neutral-500">Adelantos</p>
                            <ul class="divide-y divide-stone-100 rounded-xl border border-stone-200 text-sm dark:divide-neutral-800 dark:border-neutral-800" data-detalle-adelantos>
                                <li v-for="a in ver.adelantos" :key="a.id" class="flex items-center justify-between gap-3 px-3 py-2">
                                    <div class="min-w-0">
                                        <p class="font-medium">{{ a.medio }}<span v-if="a.referencia" class="ml-1 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ a.referencia }}</span></p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ fechaHora(a.fecha) }}<template v-if="a.usuario"> · {{ a.usuario }}</template></p>
                                    </div>
                                    <span class="font-semibold text-emerald-700 tabular-nums dark:text-emerald-400">{{ soles(a.monto) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-stone-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-neutral-800">
                        <div class="flex flex-wrap gap-5">
                            <div>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Total</p>
                                <p class="text-xl font-bold tracking-tight tabular-nums">{{ soles(ver.total) }}</p>
                            </div>
                            <div v-if="ver.total_adelantos > 0">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Adelanto</p>
                                <p class="text-xl font-bold tracking-tight text-emerald-700 tabular-nums dark:text-emerald-400">{{ soles(ver.total_adelantos) }}</p>
                            </div>
                            <div v-if="abierto(ver)">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Saldo</p>
                                <p class="text-xl font-bold tracking-tight text-amber-600 tabular-nums dark:text-amber-400">{{ soles(ver.saldo) }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="ver.estado !== 'anulado'" type="button" :class="claseBotonSec" data-accion-ticket @click="imprimirOrden(ver)">
                                <Printer class="size-3.5" />
                                Imprimir orden
                            </button>
                            <button
                                v-if="puedeAdelantar(ver)"
                                type="button"
                                :class="claseBotonSec"
                                :disabled="!!motivoSinAdelanto(ver)"
                                :title="motivoSinAdelanto(ver) || undefined"
                                data-accion-adelanto
                                @click="adelantoDe = ver; ver = null"
                            >
                                <HandCoins class="size-3.5" />
                                Adelanto
                            </button>
                            <button v-if="ver.estado === 'pendiente' && puede('pedidos.gestionar')" type="button" :class="claseBotonSec" data-accion-listo @click="marcarListo(ver)">
                                <CheckCircle2 class="size-3.5" />
                                Marcar listo
                            </button>
                            <Link v-if="abierto(ver) && puede('pos.vender')" :href="`/pos?pedido=${ver.id}`" :class="claseBotonPri" data-accion-entregar>
                                <ShoppingCart class="size-3.5" />
                                Entregar
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal WhatsApp (cuando el cliente no tiene celular registrado) -->
        <Teleport to="body">
            <div v-if="enviar" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4" data-modal-whatsapp @keydown.esc="enviar = null">
                <div class="fixed inset-0 bg-neutral-950/60" @click="enviar = null" />
                <div class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Avisar por WhatsApp · {{ enviar.codigo }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            aria-label="Cerrar"
                            @click="enviar = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <label class="mb-1 block text-sm font-medium" for="pedido-telefono">Celular de {{ enviar.cliente_nombre }}</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-neutral-400">+51</span>
                            <input
                                id="pedido-telefono"
                                v-model="telefono"
                                type="tel"
                                inputmode="numeric"
                                maxlength="11"
                                placeholder="999 999 999"
                                :class="[claseInput, 'pl-11']"
                                data-whatsapp-telefono
                                @keydown.enter.prevent="enviarWhatsapp"
                            />
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700"
                            data-whatsapp-enviar
                            @click="enviarWhatsapp"
                        >
                            <MessageCircle class="size-4" />
                            Enviar
                        </button>
                    </div>
                    <p v-if="errorTelefono" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ errorTelefono }}</p>
                    <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">El cliente no tiene celular registrado. Escríbelo y se abre WhatsApp con el mensaje listo.</p>
                    <p class="mt-3 rounded-xl bg-stone-50 p-3 text-xs text-neutral-600 dark:bg-neutral-950 dark:text-neutral-300">{{ mensajeWhatsapp(enviar) }}</p>
                </div>
            </div>
        </Teleport>

        <ModalAdelanto v-if="adelantoDe" :pedido="adelantoDe" :medios-pago="mediosPago" @close="adelantoDe = null" />
        <ModalAnulacion v-if="anularDe" :pedido="anularDe" :medios-pago="mediosPago" :caja-abierta="cajaAbierta" @close="anularDe = null" />
    </AppLayout>
</template>
