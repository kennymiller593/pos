<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    CheckCircle2,
    Eye,
    Printer,
    Clock,
    Lock,
    LockOpen,
    Plus,
    ShoppingCart,
    Wallet,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useImpresion } from '@/composables/impresion'
import CierreCajaModal from './CierreCajaModal.vue'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    cajas: { type: Array, required: true },
    mediosPago: { type: Array, default: () => [] },
    apertura: { type: Object, default: null },
    historial: { type: Object, required: true },
    suma: { type: Object, default: null },
    filtros: { type: Object, default: () => ({ desde: null, hasta: null }) },
    denominaciones: { type: Array, default: () => ['200', '100', '50', '20', '10', '5', '2', '1', '0.50', '0.20', '0.10'] },
})

const { imprimirTicket } = useImpresion()
const { puede } = usePermisos()

const formatoSoles = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// negativos con el signo delante: −S/ 1,359.18
const soles = (n) => { const v = Number(n ?? 0); return `${v < 0 ? '−' : ''}S/ ${formatoSoles.format(Math.abs(v))}` }
const hora = (fecha) => new Date(fecha).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
const fechaHora = (iso) => {
    const f = new Date(iso)
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' +
        f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

const NOMBRES_MEDIO_PAGO = { yape: 'Yape', tarjeta: 'Tarjeta', transferencia: 'Transferencia', plin: 'Plin' }
const nombreMedioPago = (codigo) =>
    NOMBRES_MEDIO_PAGO[codigo] ?? (codigo ? codigo.charAt(0).toUpperCase() + codigo.slice(1) : '')

function estadoDiferencia(diferencia) {
    if (Math.abs(diferencia) < 0.005)
        return { texto: 'Cuadrada', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
    if (diferencia > 0)
        return { texto: `Sobró ${soles(diferencia)}`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: `Faltó ${soles(-diferencia)}`, clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
}

// ---- abrir caja ----
// la primera caja libre queda elegida; el monto inicial arranca en 0 (lo normal es abrir sin sencillo y escribirlo solo si hay)
const cajaLibre = () => props.cajas.find((c) => !c.ocupada_por)?.id ?? ''
const formAbrir = useForm({
    caja_id: cajaLibre(),
    monto_inicial: '0',
})
// si cambia la lista (otra sucursal en el selector, alguien abrió la caja elegida) se vuelve a elegir una libre
watch(() => props.cajas, (cajas) => {
    if (!cajas.some((c) => c.id === formAbrir.caja_id && !c.ocupada_por)) formAbrir.caja_id = cajaLibre()
})
const unaSolaCaja = computed(() => props.cajas.length === 1)

function abrir() {
    formAbrir.post('/caja/abrir', { preserveScroll: true })
}

// ---- movimientos ----
const modalMovimiento = ref(false)
const formMovimiento = useForm({
    tipo: 'ingreso',
    concepto: '',
    monto: '',
    medio_pago_codigo: 'efectivo',
    referencia: '',
})
// el medio elegido pide número de operación (Yape, Plin, transferencia...)
const medioMovimiento = computed(() => props.mediosPago.find((m) => m.codigo === formMovimiento.medio_pago_codigo) ?? null)

function guardarMovimiento() {
    formMovimiento.post('/caja/movimientos', {
        preserveScroll: true,
        onSuccess: () => {
            modalMovimiento.value = false
            formMovimiento.reset()
        },
    })
}

// ---- cierre ----
const modalCierre = ref(false)
const ticketCierre = ref(null)

function alCerrarCaja(ticket) {
    modalCierre.value = false
    if (ticket) ticketCierre.value = ticket
}

// si el componente del modal se desmonta antes de emitir (apertura pasa a null), tomamos el ticket del flash
const page = usePage()
watch(() => page.props.flash?.ticket, (url) => {
    if (url) alCerrarCaja(url)
})

// ---- cuadre por medio de pago (turno abierto) ----
const COLUMNAS_CUADRE = [
    { clave: 'inicial', titulo: 'Inicial' },
    { clave: 'ventas', titulo: 'Ventas' },
    { clave: 'cobros', titulo: 'Cobros' },
    { clave: 'ingresos', titulo: 'Ingresos' },
    { clave: 'egresos', titulo: 'Egresos/devol.', resta: true },
    { clave: 'pagos_proveedor', titulo: 'Pagos prov.', resta: true },
]
const mediosTurno = computed(() => props.apertura?.medios ?? [])
const columnasCuadre = computed(() =>
    COLUMNAS_CUADRE.filter((c) => mediosTurno.value.some((m) => Math.abs(Number(m[c.clave] ?? 0)) >= 0.005)))
const totalCuadre = (clave) => mediosTurno.value.reduce((s, m) => s + Number(m[clave] ?? 0), 0)

// ---- historial: rango de fechas y suma (como el sistema anterior) ----
const rango = ref({ desde: props.filtros?.desde ?? '', hasta: props.filtros?.hasta ?? '' })
const isoLocal = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
function filtrarHistorial(valores = rango.value) {
    rango.value = { ...valores }
    router.get('/caja', { desde: rango.value.desde || undefined, hasta: rango.value.hasta || undefined }, { preserveScroll: true, preserveState: true, only: ['historial', 'suma', 'filtros'] })
}
function rangoRapido(tipo) {
    const hoy = new Date()
    if (tipo === 'mes') return filtrarHistorial({ desde: isoLocal(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: isoLocal(hoy) })
    if (tipo === 'semana') return filtrarHistorial({ desde: isoLocal(new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - 6)), hasta: isoLocal(hoy) })
    return filtrarHistorial({ desde: '', hasta: '' })
}
const hayRango = computed(() => !!(props.filtros?.desde || props.filtros?.hasta))

// ---- historial ----
// saldo del turno (entró − salió por todos los medios); si el dato aún no viene, el total del turno
const saldoTurno = (t) => t.saldo ?? t.total_turno ?? 0
const sumaSaldo = computed(() => props.suma?.saldo ?? props.suma?.total_turno ?? 0)
const claseSaldo = (n) => (Number(n) < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400')

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Caja">
        <!-- Aviso de cierre con ticket -->
        <div
            v-if="ticketCierre"
            class="mb-4 flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 sm:flex-row sm:items-center sm:justify-between dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            <p class="inline-flex items-center gap-2 font-medium">
                <CheckCircle2 class="size-5 shrink-0 text-[#10B981]" />
                Caja cerrada correctamente. Puedes imprimir el ticket de cierre.
            </p>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 dark:bg-emerald-600 dark:hover:bg-emerald-700"
                    @click="imprimirTicket(ticketCierre)"
                >
                    <Printer class="size-4" />
                    Imprimir cierre
                </button>
                <button
                    type="button"
                    class="rounded-xl p-2 text-emerald-700 hover:bg-emerald-100 dark:text-emerald-300 dark:hover:bg-emerald-500/15"
                    aria-label="Cerrar aviso"
                    @click="ticketCierre = null"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>

        <!-- ============ SIN CAJA ABIERTA ============ -->
        <div v-if="!apertura" class="mx-auto max-w-md">
            <form
                class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900"
                @submit.prevent="abrir"
            >
                <div class="mb-5 flex items-center gap-3">
                    <div class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <LockOpen class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">Abrir caja</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Inicia tu turno para poder vender.</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label :class="claseLabel" for="caja">Caja *</label>
                        <!-- con una sola caja no hay nada que elegir: se muestra y listo -->
                        <p v-if="!cajas.length" class="rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" data-sin-cajas>
                            No hay cajas activas en tu sucursal. Pídele al administrador que cree una en Configuración.
                        </p>
                        <div v-else-if="unaSolaCaja" class="flex h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-medium dark:border-neutral-700 dark:bg-neutral-950" data-caja-unica>
                            {{ cajas[0].nombre }}{{ cajas[0].sucursal ? ` · ${cajas[0].sucursal}` : '' }}<span v-if="cajas[0].ocupada_por" class="ml-1 font-normal text-slate-500 dark:text-neutral-400"> — en uso por {{ cajas[0].ocupada_por }}</span>
                        </div>
                        <select v-else id="caja" v-model="formAbrir.caja_id" :class="claseInput">
                            <option value="" disabled>Elige una caja</option>
                            <option v-for="c in cajas" :key="c.id" :value="c.id" :disabled="!!c.ocupada_por">
                                {{ c.nombre }}{{ c.sucursal ? ` · ${c.sucursal}` : '' }}{{ c.ocupada_por ? ` — en uso por ${c.ocupada_por}` : '' }}
                            </option>
                        </select>
                        <p v-if="formAbrir.errors.caja_id" :class="claseError">{{ formAbrir.errors.caja_id }}</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="monto_inicial">Monto inicial (efectivo en el cajón) *</label>
                        <input
                            id="monto_inicial"
                            v-model="formAbrir.monto_inicial"
                            type="number"
                            step="0.01"
                            min="0"
                            :class="claseInput"
                            placeholder="0.00"
                            @focus="$event.target.select()"
                        />
                        <p v-if="formAbrir.errors.monto_inicial" :class="claseError">{{ formAbrir.errors.monto_inicial }}</p>
                    </div>
                    <button
                        type="submit"
                        :disabled="formAbrir.processing"
                        class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <LockOpen class="size-4" />
                        {{ formAbrir.processing ? 'Abriendo...' : 'Abrir caja' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- ============ CAJA ABIERTA ============ -->
        <template v-else>
            <!-- Encabezado del turno -->
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">
                        <span class="size-1.5 rounded-full bg-emerald-600 dark:bg-emerald-400" />
                        {{ apertura.caja }} abierta
                    </span>
                    <span class="inline-flex items-center gap-1"><Clock class="size-4" /> desde las {{ hora(apertura.abierta_en) }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="`/caja/movimientos?turno=${apertura.id}`"
                        class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 sm:flex-none dark:border-neutral-700 dark:hover:bg-neutral-800"
                        data-ver-movimientos
                    >
                        <Eye class="size-4" />
                        Ver movimientos
                    </Link>
                    <button
                        class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 sm:flex-none dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="modalMovimiento = true"
                    >
                        <Plus class="size-4" />
                        Movimiento
                    </button>
                    <button
                        class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-red-700 sm:flex-none"
                        @click="modalCierre = true"
                    >
                        <Lock class="size-4" />
                        Cerrar caja
                    </button>
                </div>
            </div>

            <!-- Tarjetas de resumen -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Monto inicial</p>
                        <Wallet class="size-4 text-neutral-400" />
                    </div>
                    <p class="mt-2 text-xl font-bold tracking-tight">{{ soles(apertura.monto_inicial) }}</p>
                </div>
                <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Ventas en efectivo</p>
                        <ShoppingCart class="size-4 text-neutral-400" />
                    </div>
                    <p class="mt-2 text-xl font-bold tracking-tight">{{ soles(apertura.resumen.ventas_efectivo) }}</p>
                </div>
                <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Ingresos</p>
                        <ArrowUpCircle class="size-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">{{ soles(apertura.resumen.ingresos) }}</p>
                </div>
                <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Egresos</p>
                        <ArrowDownCircle class="size-4 text-red-500" />
                    </div>
                    <p class="mt-2 text-xl font-bold tracking-tight text-red-600 dark:text-red-400">{{ soles(apertura.resumen.egresos) }}</p>
                    <p v-if="apertura.resumen.egresos_otros_medios > 0" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        + {{ soles(apertura.resumen.egresos_otros_medios) }} devueltos por otros medios (no afectan el efectivo)
                    </p>
                </div>
                <div class="rounded-2xl bg-emerald-600 p-4 text-white">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-emerald-50">Efectivo esperado</p>
                        <Banknote class="size-4 text-emerald-200" />
                    </div>
                    <p class="mt-2 text-xl font-bold tracking-tight">{{ soles(apertura.resumen.esperado) }}</p>
                </div>
            </div>

            <!-- Cuadre por medio de pago -->
            <div
                v-if="mediosTurno.length"
                class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900"
            >
                <div class="border-b border-slate-200 px-5 py-4 dark:border-neutral-800">
                    <h2 class="font-semibold tracking-tight text-slate-900 dark:text-neutral-100">Cuadre por medio de pago</h2>
                    <p class="text-xs text-slate-500 dark:text-neutral-400">Lo que debería haber en cada medio al cerrar el turno</p>
                </div>
                <div class="@container relative overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-left text-sm">
                        <thead class="border-b border-slate-200 text-xs text-slate-500 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="px-4 py-3 font-semibold tracking-wider">Medio</th>
                                <th v-for="c in columnasCuadre" :key="c.clave" class="px-3 py-3 text-right font-semibold tracking-wider whitespace-nowrap">
                                    {{ c.titulo }}
                                </th>
                                <th class="px-4 py-3 text-right font-semibold tracking-wider">Esperado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
                            <tr v-for="m in mediosTurno" :key="m.codigo">
                                <td class="px-4 py-3 font-medium whitespace-nowrap text-slate-900 dark:text-neutral-100">{{ m.nombre }}</td>
                                <td
                                    v-for="c in columnasCuadre"
                                    :key="c.clave"
                                    class="px-3 py-3 text-right whitespace-nowrap tabular-nums"
                                    :class="Number(m[c.clave] ?? 0) === 0
                                        ? 'text-slate-300 dark:text-neutral-600'
                                        : c.resta ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-neutral-300'"
                                >
                                    <template v-if="Number(m[c.clave] ?? 0) === 0">—</template>
                                    <template v-else>{{ c.resta ? '− ' : '' }}{{ soles(m[c.clave]) }}</template>
                                </td>
                                <td class="px-4 py-3 text-right font-bold whitespace-nowrap tabular-nums text-slate-900 dark:text-neutral-100">{{ soles(m.esperado) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50 dark:border-neutral-800 dark:bg-neutral-950/50">
                            <tr>
                                <td class="px-4 py-3 font-semibold text-slate-900 dark:text-neutral-100">Total</td>
                                <td
                                    v-for="c in columnasCuadre"
                                    :key="c.clave"
                                    class="px-3 py-3 text-right font-semibold whitespace-nowrap tabular-nums"
                                    :class="c.resta ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-neutral-200'"
                                >
                                    {{ c.resta ? '− ' : '' }}{{ soles(totalCuadre(c.clave)) }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold whitespace-nowrap tabular-nums text-indigo-600 dark:text-emerald-400">{{ soles(totalCuadre('esperado')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Movimientos -->
            <div class="mt-4 overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between gap-3 border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <h2 class="font-semibold tracking-tight">Movimientos del turno</h2>
                    <Link
                        :href="`/caja/movimientos?turno=${apertura.id}`"
                        class="text-sm font-medium whitespace-nowrap text-emerald-700 hover:underline dark:text-emerald-400"
                        data-ver-todo-turno
                    >
                        Ver todo con detalle →
                    </Link>
                </div>
                <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                    <li v-if="!apertura.movimientos.length" class="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        Sin movimientos en este turno.
                    </li>
                    <li v-for="m in apertura.movimientos" :key="m.id" class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="grid size-9 shrink-0 place-items-center rounded-xl"
                                :class="m.tipo === 'ingreso'
                                    ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
                                    : 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400'"
                            >
                                <ArrowUpCircle v-if="m.tipo === 'ingreso'" class="size-5" />
                                <ArrowDownCircle v-else class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-medium">{{ m.concepto }}</span>
                                    <span v-if="m.origen && m.origen !== 'manual'" class="inline-flex shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ m.origen === 'compra' ? 'Compra' : 'Cuenta por pagar' }}</span>
                                    <span
                                        v-if="m.medio_pago_codigo && m.medio_pago_codigo !== 'efectivo'"
                                        class="inline-flex shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                    >
                                        {{ nombreMedioPago(m.medio_pago_codigo) }}<template v-if="m.referencia"> ({{ m.referencia }})</template>
                                    </span>
                                </p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ hora(m.creado_en) }}<template v-if="m.usuario"> · {{ m.usuario.nombre_completo }}</template>
                                </p>
                            </div>
                        </div>
                        <p
                            class="shrink-0 text-sm font-semibold"
                            :class="m.tipo === 'ingreso' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'"
                        >
                            {{ m.tipo === 'ingreso' ? '+' : '−' }} {{ soles(m.monto) }}
                        </p>
                    </li>
                </ul>
            </div>
        </template>

        <!-- ============ Historial de turnos ============ -->
        <div class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-semibold tracking-tight">Historial de turnos</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Arqueos de caja cerrados</p>
                    </div>
                    <form class="flex flex-wrap items-end gap-2" data-filtro-historial @submit.prevent="filtrarHistorial()">
                        <label class="text-xs text-neutral-500 dark:text-neutral-400">
                            Desde
                            <input v-model="rango.desde" type="date" :max="rango.hasta || undefined" class="mt-0.5 block h-9 rounded-xl border border-stone-300 bg-white px-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100" />
                        </label>
                        <label class="text-xs text-neutral-500 dark:text-neutral-400">
                            Hasta
                            <input v-model="rango.hasta" type="date" :min="rango.desde || undefined" class="mt-0.5 block h-9 rounded-xl border border-stone-300 bg-white px-2 text-sm text-neutral-900 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100" />
                        </label>
                        <button type="submit" class="h-9 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Ver</button>
                        <div class="flex gap-1">
                            <button type="button" class="h-9 rounded-xl border border-stone-200 px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="rangoRapido('semana')">7 días</button>
                            <button type="button" class="h-9 rounded-xl border border-stone-200 px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="rangoRapido('mes')">Este mes</button>
                            <button v-if="hayRango" type="button" class="h-9 rounded-xl px-3 text-xs font-medium text-neutral-500 hover:bg-stone-50 dark:hover:bg-neutral-800" @click="rangoRapido('todo')">Todo</button>
                        </div>
                    </form>
                </div>
                <!-- en celular la fila Suma va arriba: lo que entró y el saldo del rango -->
                <div v-if="suma && suma.turnos > 0" class="mt-3 grid grid-cols-2 gap-2 sm:hidden" data-suma-movil>
                    <div class="rounded-xl bg-stone-50 px-3 py-2 dark:bg-neutral-950">
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Saldo del rango · {{ suma.turnos }} {{ suma.turnos === 1 ? 'turno' : 'turnos' }}</p>
                        <p class="text-base font-bold" :class="claseSaldo(sumaSaldo)">{{ soles(sumaSaldo) }}</p>
                    </div>
                    <div class="rounded-xl bg-stone-50 px-3 py-2 dark:bg-neutral-950">
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Entró</p>
                        <p class="text-base font-bold text-emerald-600 dark:text-emerald-400">{{ suma.entro != null ? soles(suma.entro) : '—' }}</p>
                    </div>
                </div>
            </div>

            <p v-if="!historial.data.length" class="px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                {{ hayRango ? 'No hay turnos cerrados en ese rango de fechas.' : 'Aún no hay turnos cerrados.' }}
            </p>

            <template v-else>
                <!-- PC: tabla -->
                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full text-left text-sm" data-tabla-historial>
                        <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="px-4 py-3 font-semibold tracking-wider">Turno</th>
                                <th class="px-3 py-3 text-right font-semibold tracking-wider">Entró</th>
                                <th class="px-3 py-3 text-right font-semibold tracking-wider">Salió</th>
                                <th class="px-3 py-3 text-right font-semibold tracking-wider whitespace-nowrap" title="Lo que entró menos lo que salió por todos los medios, sin el monto inicial">Saldo del turno</th>
                                <th class="px-3 py-3 text-right font-semibold tracking-wider whitespace-nowrap">Efectivo al cerrar</th>
                                <th class="px-3 py-3 text-center font-semibold tracking-wider">Estado</th>
                                <th class="px-4 py-3 text-right font-semibold tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-for="t in historial.data" :key="t.id" class="transition-colors hover:bg-slate-50 dark:hover:bg-neutral-800/50" :data-turno="t.id">
                                <td class="px-4 py-3">
                                    <p class="font-medium whitespace-nowrap">{{ t.caja }} · {{ t.usuario ?? '—' }}</p>
                                    <p class="text-xs whitespace-nowrap text-neutral-500 dark:text-neutral-400">{{ fechaHora(t.abierta_en) }} → {{ fechaHora(t.cerrada_en) }}</p>
                                    <p v-if="t.observaciones" class="mt-0.5 max-w-xs truncate text-xs text-slate-600 italic dark:text-neutral-300" :title="t.observaciones">{{ t.observaciones }}</p>
                                </td>
                                <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums text-emerald-600 dark:text-emerald-400">{{ t.entro != null ? soles(t.entro) : '—' }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums text-red-600 dark:text-red-400">{{ t.salio != null ? soles(t.salio) : '—' }}</td>
                                <td class="px-3 py-3 text-right font-semibold whitespace-nowrap tabular-nums" :class="claseSaldo(saldoTurno(t))">{{ soles(saldoTurno(t)) }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums">
                                    <p class="font-medium">{{ soles(t.monto_cierre) }}</p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">abrió con {{ soles(t.monto_inicial) }}</p>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="estadoDiferencia(Number(t.diferencia_total ?? t.diferencia ?? 0)).clase">
                                        {{ estadoDiferencia(Number(t.diferencia_total ?? t.diferencia ?? 0)).texto }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Link
                                            :href="`/caja/movimientos?turno=${t.id}`"
                                            class="rounded-lg p-1.5 text-slate-500 hover:bg-emerald-50 hover:text-emerald-700 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                            aria-label="Ver movimientos del turno"
                                            title="Ver lo que entró y salió en el turno"
                                            :data-ver-turno="t.id"
                                        >
                                            <Eye class="size-4" />
                                        </Link>
                                        <button
                                            type="button"
                                            class="rounded-lg p-1.5 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                            aria-label="Imprimir ticket de cierre"
                                            title="Imprimir ticket de cierre"
                                            @click="imprimirTicket(`/caja/turnos/${t.id}/ticket`)"
                                        >
                                            <Printer class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <!-- Suma de todos los turnos del rango (no solo de esta página), como el sistema anterior -->
                        <tfoot v-if="suma && suma.turnos > 0" class="border-t-2 border-stone-200 bg-stone-50 font-semibold dark:border-neutral-700 dark:bg-neutral-950" data-suma-historial>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    Suma
                                    <span class="block text-xs font-normal text-neutral-500 dark:text-neutral-400">{{ suma.turnos }} {{ suma.turnos === 1 ? 'turno' : 'turnos' }}{{ hayRango ? '' : ' · todo el historial' }}</span>
                                </td>
                                <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums text-emerald-600 dark:text-emerald-400">{{ suma.entro != null ? soles(suma.entro) : '—' }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums text-red-600 dark:text-red-400">{{ suma.salio != null ? soles(suma.salio) : '—' }}</td>
                                <td class="px-3 py-3 text-right text-base whitespace-nowrap tabular-nums" :class="claseSaldo(sumaSaldo)">{{ soles(sumaSaldo) }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums">{{ soles(suma.contado) }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="estadoDiferencia(Number(suma.diferencia ?? 0)).clase">{{ estadoDiferencia(Number(suma.diferencia ?? 0)).texto }}</span>
                                </td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Celular: una tarjeta por turno -->
                <ul class="divide-y divide-stone-100 sm:hidden dark:divide-neutral-800" data-historial-movil>
                    <li v-for="t in historial.data" :key="t.id" class="px-4 py-3" :data-turno="t.id">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold break-words">{{ t.caja }} · {{ t.usuario ?? '—' }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ fechaHora(t.abierta_en) }} → {{ fechaHora(t.cerrada_en) }}</p>
                            </div>
                            <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="estadoDiferencia(Number(t.diferencia_total ?? t.diferencia ?? 0)).clase">
                                {{ estadoDiferencia(Number(t.diferencia_total ?? t.diferencia ?? 0)).texto }}
                            </span>
                        </div>
                        <p v-if="t.observaciones" class="mt-1 text-xs break-words text-slate-600 italic dark:text-neutral-300">{{ t.observaciones }}</p>
                        <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                            <div class="min-w-0 rounded-xl bg-stone-50 px-2 py-1.5 dark:bg-neutral-950">
                                <dt class="text-neutral-500 dark:text-neutral-400">Entró</dt>
                                <dd class="text-sm font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ t.entro != null ? soles(t.entro) : '—' }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-stone-50 px-2 py-1.5 dark:bg-neutral-950">
                                <dt class="text-neutral-500 dark:text-neutral-400">Salió</dt>
                                <dd class="text-sm font-semibold tabular-nums text-red-600 dark:text-red-400">{{ t.salio != null ? soles(t.salio) : '—' }}</dd>
                            </div>
                            <div class="min-w-0 rounded-xl bg-stone-50 px-2 py-1.5 dark:bg-neutral-950">
                                <dt class="text-neutral-500 dark:text-neutral-400">Saldo</dt>
                                <dd class="text-sm font-bold tabular-nums" :class="claseSaldo(saldoTurno(t))">{{ soles(saldoTurno(t)) }}</dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                            Efectivo al cerrar <b class="text-neutral-800 tabular-nums dark:text-neutral-200">{{ soles(t.monto_cierre) }}</b> · abrió con {{ soles(t.monto_inicial) }}
                        </p>
                        <div class="mt-2 flex gap-2">
                            <Link
                                :href="`/caja/movimientos?turno=${t.id}`"
                                class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-xl border border-stone-300 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                :data-ver-turno="t.id"
                            >
                                <Eye class="size-4" />
                                Ver movimientos
                            </Link>
                            <button
                                type="button"
                                class="inline-flex size-9 items-center justify-center rounded-xl border border-stone-300 text-slate-500 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                aria-label="Imprimir ticket de cierre"
                                @click="imprimirTicket(`/caja/turnos/${t.id}/ticket`)"
                            >
                                <Printer class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>
            </template>

            <!-- Paginación -->
            <div
                v-if="historial.data.length && historial.last_page > 1"
                class="flex flex-wrap justify-end gap-1.5 border-t border-stone-200 px-4 py-3 dark:border-neutral-800"
            >
                <template v-for="(link, i) in historial.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded-lg border px-3.5 py-1.5 text-sm"
                        :class="link.active
                            ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                            : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>

        <!-- Modal: registrar movimiento -->
        <Teleport to="body">
            <div v-if="modalMovimiento" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalMovimiento = false" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarMovimiento"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Registrar movimiento</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalMovimiento = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div class="grid gap-2" :class="puede('caja.egresos') ? 'grid-cols-2' : 'grid-cols-1'">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border text-sm font-medium transition-colors"
                                :class="formMovimiento.tipo === 'ingreso'
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                @click="formMovimiento.tipo = 'ingreso'"
                            >
                                <ArrowUpCircle class="size-4" />
                                Ingreso
                            </button>
                            <button
                                v-if="puede('caja.egresos')"
                                type="button"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border text-sm font-medium transition-colors"
                                :class="formMovimiento.tipo === 'egreso'
                                    ? 'border-red-600 bg-red-600 text-white'
                                    : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                @click="formMovimiento.tipo = 'egreso'"
                            >
                                <ArrowDownCircle class="size-4" />
                                Egreso
                            </button>
                        </div>
                        <div>
                            <label :class="claseLabel" for="concepto">Concepto *</label>
                            <input
                                id="concepto"
                                v-model="formMovimiento.concepto"
                                type="text"
                                :class="claseInput"
                                :placeholder="formMovimiento.tipo === 'ingreso' ? 'Sencillo adicional' : 'Compra de bolsas'"
                            />
                            <p v-if="formMovimiento.errors.concepto" :class="claseError">{{ formMovimiento.errors.concepto }}</p>
                        </div>
                        <div>
                            <p :class="claseLabel">{{ formMovimiento.tipo === 'ingreso' ? '¿Por dónde entró el dinero?' : '¿Por dónde salió el dinero?' }} *</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="m in mediosPago"
                                    :key="m.codigo"
                                    type="button"
                                    class="rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="formMovimiento.medio_pago_codigo === m.codigo
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    :data-movimiento-medio="m.codigo"
                                    @click="formMovimiento.medio_pago_codigo = m.codigo"
                                >
                                    {{ m.nombre }}
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ formMovimiento.medio_pago_codigo === 'efectivo' ? 'Cuenta en el efectivo del cajón.' : `Cuenta en ${medioMovimiento?.nombre ?? 'ese medio'}, no en el efectivo del cajón.` }}
                            </p>
                            <p v-if="formMovimiento.errors.medio_pago_codigo" :class="claseError">{{ formMovimiento.errors.medio_pago_codigo }}</p>
                        </div>
                        <div v-if="formMovimiento.medio_pago_codigo !== 'efectivo'">
                            <label :class="claseLabel" for="mov_referencia">N° de operación / referencia <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <input id="mov_referencia" v-model="formMovimiento.referencia" type="text" maxlength="100" :class="claseInput" placeholder="Ej. 123456" data-movimiento-referencia />
                        </div>
                        <div>
                            <label :class="claseLabel" for="monto">Monto (S/) *</label>
                            <input id="monto" v-model="formMovimiento.monto" type="number" step="0.01" min="0.01" :class="claseInput" placeholder="0.00" />
                            <p v-if="formMovimiento.errors.monto" :class="claseError">{{ formMovimiento.errors.monto }}</p>
                            <!-- un egreso mayor al efectivo no se bloquea (el dinero salió igual): se avisa que el cierre quedará en negativo -->
                            <p
                                v-else-if="formMovimiento.tipo === 'egreso' && formMovimiento.medio_pago_codigo === 'efectivo' && apertura && Number(formMovimiento.monto) > Number(apertura.resumen.esperado) + 0.005"
                                class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                                data-aviso-negativo
                            >
                                En caja hay {{ soles(apertura.resumen.esperado) }} en efectivo: con este egreso el esperado quedará en negativo. Se registra igual.
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalMovimiento = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formMovimiento.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formMovimiento.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal: cerrar caja (resumen del turno prellenado, como el sistema anterior) -->
        <CierreCajaModal
            v-if="apertura"
            :abierto="modalCierre"
            :apertura="apertura"
            :denominaciones="denominaciones"
            @cerrar="modalCierre = false"
            @cerrado="alCerrarCaja"
        />
    </AppLayout>
</template>
