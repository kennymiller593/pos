<script setup>
import { computed, ref, watch } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    CheckCircle2,
    History,
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
import ListaMovimientos from '@/Components/Caja/ListaMovimientos.vue'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    cajas: { type: Array, required: true },
    mediosPago: { type: Array, default: () => [] },
    apertura: { type: Object, default: null },
    denominaciones: { type: Array, default: () => ['200', '100', '50', '20', '10', '5', '2', '1', '0.50', '0.20', '0.10'] },
})


const { imprimirTicket } = useImpresion()
const { puede } = usePermisos()

const formatoSoles = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// negativos con el signo delante: −S/ 1,359.18
const soles = (n) => { const v = Number(n ?? 0); return `${v < 0 ? '−' : ''}S/ ${formatoSoles.format(Math.abs(v))}` }
const hora = (fecha) => new Date(fecha).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })

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

// lista del turno abierto: el mismo shape que /caja/movimientos
const listaTurno = computed(() => props.apertura?.movimientos ?? { entradas: [], salidas: [], resumen: { entro: 0, entro_por_medio: [], salio: 0, salio_por_medio: [], saldo: 0, movimientos: 0 } })

const claseBotonSec =
    'inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 sm:flex-none dark:border-neutral-700 dark:hover:bg-neutral-800'

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
            <div class="mb-3 flex justify-end">
                <Link href="/caja/historial" :class="claseBotonSec" data-ver-historial>
                    <History class="size-4" />
                    Historial de turnos
                </Link>
            </div>
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
                <!-- en celular: Historial a lo ancho y debajo Movimiento · Cerrar caja, sin partir el texto -->
                <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    <Link href="/caja/historial" :class="claseBotonSec" class="col-span-2 whitespace-nowrap" data-ver-historial>
                        <History class="size-4" />
                        Historial de turnos
                    </Link>
                    <button
                        type="button"
                        :class="claseBotonSec"
                        data-nuevo-movimiento
                        @click="modalMovimiento = true"
                    >
                        <Plus class="size-4" />
                        Movimiento
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-red-700 sm:flex-none"
                        data-cerrar-caja
                        @click="modalCierre = true"
                    >
                        <Lock class="size-4" />
                        Cerrar caja
                    </button>
                </div>
            </div>

            <!-- Tarjetas de resumen -->
            <!-- en celular, de dos en dos (la de efectivo esperado a lo ancho) para no alargar la página -->
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
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
                <div class="col-span-2 rounded-2xl bg-emerald-600 p-4 text-white xl:col-span-1">
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


            <!-- Todo lo que entró y salió en el turno, fila por fila (como el sistema anterior) -->
            <section class="mt-6" data-lista-turno>
                <div class="mb-1 flex items-end justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-semibold tracking-tight">Movimientos del turno</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Lo que entró y salió por todos los medios desde que abriste la caja. Toca el ojo para ver el detalle.</p>
                    </div>
                </div>
                <ListaMovimientos
                    plegable
                    eliminable
                    :entradas="listaTurno.entradas"
                    :salidas="listaTurno.salidas"
                    :resumen="listaTurno.resumen"
                    :mostrar-resumen="false"
                    texto-vacio="en este turno"
                >
                    <template #pie>
                        <div class="mt-4 flex flex-col gap-2 border-t border-stone-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800">
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                Efectivo esperado en el cajón: <b class="whitespace-nowrap text-neutral-900 tabular-nums dark:text-neutral-100">{{ soles(apertura.resumen.esperado) }}</b>
                            </p>
                            <button
                                type="button"
                                class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-red-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-red-700 sm:w-auto"
                                data-cerrar-caja-pie
                                @click="modalCierre = true"
                            >
                                <Lock class="size-4" />
                                Cerrar caja
                            </button>
                        </div>
                    </template>
                </ListaMovimientos>
            </section>
        </template>

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
