<script setup>
// Lista de lo que entró y salió de caja (fila por fila, con su medio de pago), con el pie
// "Entró − Salió = Saldo" y el modal de detalle (el "ojo"). La usan /caja (turno abierto)
// y /caja/movimientos (por rango o por turno).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowDownCircle, ArrowUpCircle, Banknote, ChevronDown, ClipboardList, Eye, FileText, HandCoins, Loader2, Printer, RotateCcw, Scale, Trash2, Truck, X } from '@lucide/vue'
import { router } from '@inertiajs/vue3'
import { useImpresion } from '@/composables/impresion'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    entradas: { type: Array, default: () => [] },
    salidas: { type: Array, default: () => [] },
    resumen: { type: Object, required: true },
    // tarjetas Entró / Salió / Saldo arriba de los bloques
    mostrarResumen: { type: Boolean, default: true },
    // "en este turno" / "en este rango"
    textoVacio: { type: String, default: 'en este turno' },
    // en la vista por rango se muestra a qué caja pertenece cada fila
    mostrarCaja: { type: Boolean, default: false },
    // texto del rango de fechas para la tarjeta de saldo (opcional)
    textoRango: { type: String, default: '' },
    // acordeón: Entró y Salió empiezan cerrados y se abren al tocar su cabecera
    plegable: { type: Boolean, default: false },
    // turno abierto: los ingresos/egresos registrados a mano se pueden eliminar
    eliminable: { type: Boolean, default: false },
})

const { confirmar } = useConfirmar()
const { puede } = usePermisos()
// solo los manuales (no adelantos ni devoluciones, que nacen de otra operación); un egreso exige caja.egresos
const sePuedeEliminar = (f) => props.eliminable && ['ingreso', 'egreso'].includes(f.tipo) && (f.tipo === 'ingreso' || puede('caja.egresos'))
async function eliminar(f) {
    const ok = await confirmar({
        titulo: `Eliminar ${f.tipo === 'ingreso' ? 'ingreso' : 'egreso'} de ${soles(f.monto)}`,
        mensaje: `"${f.detalle}" desaparecerá de la caja y el efectivo esperado se ajustará.`,
        textoConfirmar: 'Eliminar',
        peligro: true,
    })
    if (ok) router.delete(`/caja/movimientos/${f.detalle_id}`, { preserveScroll: true })
}

// ícono de cada fila según qué fue (venta, cobro, compra...)
const ICONOS = { venta: FileText, cobro: HandCoins, ingreso: ArrowUpCircle, adelanto: ClipboardList, egreso: ArrowDownCircle, devolucion: RotateCcw, compra: Truck, pago_proveedor: Banknote }
const iconoDe = (f) => ICONOS[f.tipo] ?? FileText
const claseIcono = (clave) => (clave === 'entradas'
    ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400'
    : 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400')

const abiertos = ref({ entradas: !props.plegable, salidas: !props.plegable })
const alternar = (clave) => { if (props.plegable) abiertos.value[clave] = !abiertos.value[clave] }

const { imprimirTicket } = useImpresion()
const formatoSoles = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// negativos con el signo delante: −S/ 1,359.18
const soles = (n) => { const v = Number(n ?? 0); return `${v < 0 ? '−' : ''}S/ ${formatoSoles.format(Math.abs(v))}` }
const dosDigitos = (n) => String(n).padStart(2, '0')
// dd/mm hh:mm
const fechaCorta = (iso) => {
    if (!iso) return ''
    const f = new Date(iso)
    return `${dosDigitos(f.getDate())}/${dosDigitos(f.getMonth() + 1)} ${dosDigitos(f.getHours())}:${dosDigitos(f.getMinutes())}`
}
// dd/mm/aaaa hh:mm
const fechaLarga = (iso) => {
    if (!iso) return ''
    const f = new Date(iso)
    return `${dosDigitos(f.getDate())}/${dosDigitos(f.getMonth() + 1)}/${f.getFullYear()} ${dosDigitos(f.getHours())}:${dosDigitos(f.getMinutes())}`
}
const cantidad = (n) => new Intl.NumberFormat('es-PE', { maximumFractionDigits: 3 }).format(Number(n ?? 0))
const claseSaldo = (n) => (Number(n) < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400')
// el detalle trae el código SUNAT de la unidad: los más comunes con su nombre corto
const UNIDADES = { NIU: 'und.', ZZ: 'serv.', KGM: 'kg', GRM: 'g', LTR: 'L', MLT: 'ml', MTR: 'm', BX: 'caja', PK: 'paq.', BG: 'bolsa', BO: 'botella', SA: 'saco', DZN: 'docena', GLL: 'galón' }
const unidad = (codigo) => UNIDADES[(codigo ?? '').trim().toUpperCase()] ?? (codigo ?? '').trim()

// chip del medio de pago: efectivo gris, los digitales con un color suave cada uno
const COLOR_MEDIO = {
    efectivo: 'bg-stone-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    yape: 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
    plin: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    transferencia: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    tarjeta: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
}
const claseMedio = (codigo) => COLOR_MEDIO[codigo] ?? 'bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300'
const desglose = (filas) => (filas ?? []).map((m) => `${m.medio} ${soles(m.monto)}`).join(' · ')

// ---- bloques ----
const bloques = computed(() => [
    { clave: 'entradas', titulo: 'Entró', filas: props.entradas, total: props.resumen.entro, signo: '+', vacio: `No entró dinero ${props.textoVacio}.`, suma: 'Suma entró' },
    { clave: 'salidas', titulo: 'Salió', filas: props.salidas, total: props.resumen.salio, signo: '−', vacio: `No salió dinero ${props.textoVacio}.`, suma: 'Suma salió' },
])
const claseMonto = (clave) => (clave === 'entradas' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400')

// ---- detalle (el "ojo") ----
const detalle = ref(null) // { fila, cargando, error, datos }
let pedido = 0
async function verDetalle(fila) {
    const id = ++pedido
    detalle.value = { fila, cargando: true, error: null, datos: null }
    try {
        const r = await fetch(`/caja/movimientos/detalle?tipo=${encodeURIComponent(fila.detalle_tipo)}&id=${encodeURIComponent(fila.detalle_id)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
        if (id !== pedido || !detalle.value) return
        if (!r.ok) throw new Error(r.status === 404 ? 'No encontramos este movimiento o no tienes acceso a él.' : 'No se pudo cargar el detalle. Inténtalo otra vez.')
        detalle.value = { fila, cargando: false, error: null, datos: await r.json() }
    } catch (e) {
        if (id !== pedido || !detalle.value) return
        detalle.value = { fila, cargando: false, error: e?.message && !e.message.includes('fetch') ? e.message : 'No se pudo cargar el detalle. Revisa tu conexión.', datos: null }
    }
}
function cerrarDetalle() {
    pedido++
    detalle.value = null
}
const alPresionarTecla = (e) => { if (e.key === 'Escape' && detalle.value) cerrarDetalle() }
onMounted(() => window.addEventListener('keydown', alPresionarTecla))
onBeforeUnmount(() => window.removeEventListener('keydown', alPresionarTecla))

const esSalida = computed(() => detalle.value?.fila && props.salidas.some((s) => s.clave === detalle.value.fila.clave))
const d = computed(() => detalle.value?.datos ?? null)

const claseChip = 'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap'
const claseBotonSec =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800'
</script>

<template>
    <div>
        <!-- ============ Resumen ============ -->
        <div v-if="mostrarResumen" class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4" data-resumen-movimientos>
            <div class="min-w-0 rounded-2xl border border-stone-200 bg-white p-3 sm:p-4 dark:border-neutral-800 dark:bg-neutral-900" data-resumen-entro>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Entró</p>
                    <ArrowUpCircle class="size-4 shrink-0 text-emerald-500" />
                </div>
                <p class="mt-1 text-lg font-bold tracking-tight tabular-nums text-emerald-600 sm:text-xl dark:text-emerald-400">{{ soles(resumen.entro) }}</p>
                <p v-if="resumen.entro_por_medio?.length" class="mt-1 text-xs leading-snug text-neutral-500 dark:text-neutral-400">{{ desglose(resumen.entro_por_medio) }}</p>
            </div>
            <div class="min-w-0 rounded-2xl border border-stone-200 bg-white p-3 sm:p-4 dark:border-neutral-800 dark:bg-neutral-900" data-resumen-salio>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Salió</p>
                    <ArrowDownCircle class="size-4 shrink-0 text-red-500" />
                </div>
                <p class="mt-1 text-lg font-bold tracking-tight tabular-nums text-red-600 sm:text-xl dark:text-red-400">{{ soles(resumen.salio) }}</p>
                <p v-if="resumen.salio_por_medio?.length" class="mt-1 text-xs leading-snug text-neutral-500 dark:text-neutral-400">{{ desglose(resumen.salio_por_medio) }}</p>
            </div>
            <div
                class="col-span-2 min-w-0 rounded-2xl p-3 sm:col-span-1 sm:p-4"
                :class="Number(resumen.saldo) < 0 ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white'"
                data-resumen-saldo
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm opacity-90">Saldo</p>
                    <Scale class="size-4 shrink-0 opacity-80" />
                </div>
                <p class="mt-1 text-lg font-bold tracking-tight tabular-nums sm:text-xl">{{ soles(resumen.saldo) }}</p>
                <p class="mt-1 text-xs opacity-80">entró − salió · {{ resumen.movimientos }} {{ resumen.movimientos === 1 ? 'movimiento' : 'movimientos' }}<template v-if="textoRango"> · {{ textoRango }}</template></p>
            </div>
        </div>

        <!-- ============ Bloques ENTRÓ / SALIÓ ============ -->
        <section
            v-for="b in bloques"
            :key="b.clave"
            class="mt-4 overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900"
            :data-bloque-entradas="b.clave === 'entradas' ? '' : undefined"
            :data-bloque-salidas="b.clave === 'salidas' ? '' : undefined"
        >
            <component
                :is="plegable ? 'button' : 'div'"
                :type="plegable ? 'button' : undefined"
                class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left sm:px-5"
                :class="[
                    b.clave === 'entradas'
                        ? 'border-emerald-100 bg-emerald-50/70 dark:border-emerald-500/20 dark:bg-emerald-500/10'
                        : 'border-red-100 bg-red-50/70 dark:border-red-500/20 dark:bg-red-500/10',
                    abiertos[b.clave] ? 'border-b' : '',
                    plegable ? 'cursor-pointer' : '',
                ]"
                :aria-expanded="plegable ? abiertos[b.clave] : undefined"
                :data-acordeon="plegable ? b.clave : undefined"
                @click="alternar(b.clave)"
            >
                <h2 class="inline-flex items-center gap-2 font-semibold tracking-tight" :class="claseMonto(b.clave)">
                    <ChevronDown v-if="plegable" class="size-4 transition-transform" :class="{ '-rotate-90': !abiertos[b.clave] }" />
                    <ArrowUpCircle v-if="b.clave === 'entradas'" class="size-5" />
                    <ArrowDownCircle v-else class="size-5" />
                    {{ b.titulo }}
                    <span class="text-xs font-normal text-neutral-500 dark:text-neutral-400">· {{ b.filas.length }}</span>
                </h2>
                <p class="text-sm font-bold tabular-nums whitespace-nowrap" :class="claseMonto(b.clave)">{{ b.signo }} {{ soles(b.total) }}</p>
            </component>

            <template v-if="abiertos[b.clave]">
            <p v-if="!b.filas.length" class="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400" data-vacio>{{ b.vacio }}</p>

            <template v-else>
                <!-- PC: tabla -->
                <div class="hidden sm:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="px-5 py-2.5 font-semibold tracking-wider">Movimiento</th>
                                <th class="px-3 py-2.5 font-semibold tracking-wider whitespace-nowrap">Fecha y hora</th>
                                <th class="px-3 py-2.5 font-semibold tracking-wider">Medio</th>
                                <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Monto</th>
                                <th class="w-14 px-4 py-2.5 text-center font-semibold tracking-wider">Ver</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-for="f in b.filas" :key="f.clave" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50" :data-fila-movimiento="f.clave">
                                <td class="w-full max-w-0 px-5 py-1.5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-full" :class="claseIcono(b.clave)"><component :is="iconoDe(f)" class="size-4" /></span>
                                        <div class="min-w-0">
                                            <p class="text-[13px] leading-tight font-semibold">{{ f.titulo }}</p>
                                            <p class="truncate text-xs leading-tight text-neutral-500 dark:text-neutral-400" :title="f.detalle">
                                                {{ f.detalle }}<template v-if="f.usuario"> · {{ f.usuario }}</template><template v-if="mostrarCaja && f.turno?.caja"> · {{ f.turno.caja }}</template>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap tabular-nums text-neutral-600 dark:text-neutral-300">{{ fechaCorta(f.fecha) }}</td>
                                <td class="px-3 py-1.5">
                                    <span :class="[claseChip, claseMedio(f.medio_codigo)]">{{ f.medio }}</span>
                                    <p v-if="f.referencia" class="text-[11px] leading-tight whitespace-nowrap text-neutral-500 dark:text-neutral-400">op. {{ f.referencia }}</p>
                                </td>
                                <td class="px-3 py-1.5 text-right font-semibold whitespace-nowrap tabular-nums" :class="claseMonto(b.clave)">{{ b.signo }} {{ soles(f.monto) }}</td>
                                <td class="px-4 py-1.5 text-center whitespace-nowrap">
                                    <button
                                        v-if="sePuedeEliminar(f)"
                                        type="button"
                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:text-neutral-500 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        title="Eliminar"
                                        :aria-label="`Eliminar ${f.titulo}`"
                                        :data-eliminar-movimiento="f.clave"
                                        @click="eliminar(f)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg p-1.5 text-slate-500 hover:bg-emerald-50 hover:text-emerald-700 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                        :aria-label="`Ver detalle de ${f.titulo}`"
                                        title="Ver detalle"
                                        :data-ver-movimiento="f.clave"
                                        @click="verDetalle(f)"
                                    >
                                        <Eye class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-950">
                            <tr>
                                <td colspan="3" class="px-5 py-3 font-bold">{{ b.suma }}</td>
                                <td class="px-3 py-3 text-right text-base font-bold whitespace-nowrap tabular-nums" :class="claseMonto(b.clave)" :data-suma="b.clave">{{ b.signo }} {{ soles(b.total) }}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Celular: tarjetas -->
                <ul class="divide-y divide-stone-100 sm:hidden dark:divide-neutral-800">
                    <li v-for="f in b.filas" :key="f.clave" class="flex items-center">
                        <button
                            type="button"
                            class="flex w-full min-w-0 items-center justify-between gap-3 px-4 py-2 text-left active:bg-stone-50 dark:active:bg-neutral-800/60"
                            :data-ver-movimiento="f.clave"
                            @click="verDetalle(f)"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-full" :class="claseIcono(b.clave)"><component :is="iconoDe(f)" class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] leading-tight font-semibold">{{ f.titulo }}</p>
                                <p class="truncate text-xs leading-tight text-neutral-500 dark:text-neutral-400">{{ f.detalle }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-neutral-500 dark:text-neutral-400">
                                    <span class="tabular-nums">{{ fechaCorta(f.fecha) }}</span>
                                    <span :class="[claseChip, claseMedio(f.medio_codigo)]">{{ f.medio }}</span>
                                    <span v-if="f.referencia" class="break-all">op. {{ f.referencia }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span class="text-sm font-bold whitespace-nowrap tabular-nums" :class="claseMonto(b.clave)">{{ b.signo }} {{ soles(f.monto) }}</span>
                                <Eye class="size-4 text-neutral-400" />
                            </div>
                        </button>
                        <button
                            v-if="sePuedeEliminar(f)"
                            type="button"
                            class="mr-2 shrink-0 rounded-lg p-2 text-slate-400 active:bg-red-50 active:text-red-600"
                            :aria-label="`Eliminar ${f.titulo}`"
                            :data-eliminar-movimiento="f.clave"
                            @click="eliminar(f)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                    <li class="flex items-center justify-between gap-3 bg-stone-50 px-4 py-3 dark:bg-neutral-950">
                        <span class="text-sm font-bold">{{ b.suma }}</span>
                        <span class="text-base font-bold whitespace-nowrap tabular-nums" :class="claseMonto(b.clave)">{{ b.signo }} {{ soles(b.total) }}</span>
                    </li>
                </ul>
            </template>
            </template>
        </section>

        <!-- ============ Pie: Entró − Salió = Saldo ============ -->
        <div class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 sm:p-5 dark:border-neutral-800 dark:bg-neutral-900" data-saldo-movimientos>
            <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm sm:text-base">
                <span>Entró <span class="font-semibold whitespace-nowrap text-emerald-600 tabular-nums dark:text-emerald-400">{{ soles(resumen.entro) }}</span></span>
                <span class="text-neutral-400">−</span>
                <span>Salió <span class="font-semibold whitespace-nowrap text-red-600 tabular-nums dark:text-red-400">{{ soles(resumen.salio) }}</span></span>
                <span class="text-neutral-400">=</span>
                <span class="text-base font-bold sm:text-lg">Saldo <span class="whitespace-nowrap tabular-nums" :class="claseSaldo(resumen.saldo)">{{ soles(resumen.saldo) }}</span></span>
            </p>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Por todos los medios (efectivo, Yape, Plin, transferencia...), sin contar el monto con que se abrió la caja.</p>

            <!-- debajo del saldo: lo que cada pantalla quiera añadir (cuadre del cajón, botón de cierre...) -->
            <slot name="pie" />
        </div>

        <!-- ============ Modal: detalle de un movimiento ============ -->
        <Teleport to="body">
            <div v-if="detalle" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" data-modal-detalle>
                <div class="fixed inset-0 bg-neutral-950/60" @click="cerrarDetalle" />
                <div
                    class="relative flex max-h-[92dvh] w-full min-w-0 max-w-xl flex-col overflow-hidden rounded-t-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl sm:rounded-2xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    role="dialog"
                    aria-modal="true"
                >
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                        <div class="min-w-0">
                            <h3 class="font-semibold tracking-tight break-words">{{ d?.titulo ?? detalle.fila.titulo }}</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ fechaLarga(d?.fecha ?? detalle.fila.fecha) }}</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            aria-label="Cerrar"
                            @click="cerrarDetalle"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="scroll-fino min-w-0 flex-1 overflow-y-auto px-5 py-4">
                        <div v-if="detalle.cargando" class="flex flex-col items-center gap-2 py-12 text-sm text-neutral-500 dark:text-neutral-400" data-detalle-cargando>
                            <Loader2 class="size-6 animate-spin text-emerald-600" />
                            Cargando detalle...
                        </div>
                        <div v-else-if="detalle.error" class="rounded-xl bg-red-50 px-4 py-6 text-center text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300" data-detalle-error>
                            {{ detalle.error }}
                            <button type="button" class="mt-3 block w-full text-xs font-semibold underline" @click="verDetalle(detalle.fila)">Reintentar</button>
                        </div>

                        <!-- venta -->
                        <div v-else-if="d?.tipo === 'venta'" class="space-y-4" data-detalle-venta>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <div class="col-span-2 min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Cliente</dt><dd class="font-medium break-words">{{ d.cliente }}</dd></div>
                                <div><dt class="text-xs text-neutral-500 dark:text-neutral-400">Condición</dt><dd class="font-medium">{{ d.condicion }}</dd></div>
                                <div class="min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Vendió</dt><dd class="font-medium break-words">{{ d.usuario ?? '—' }}</dd></div>
                            </dl>
                            <table class="w-full text-sm" data-detalle-items>
                                <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                    <tr>
                                        <th class="py-1.5 pr-2 text-left font-semibold">Producto</th>
                                        <th class="px-2 py-1.5 text-right font-semibold">Precio</th>
                                        <th class="py-1.5 pl-2 text-right font-semibold">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                    <tr v-for="(it, i) in d.items" :key="i">
                                        <td class="py-2 pr-2">
                                            <p class="break-words">{{ it.descripcion }}</p>
                                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ cantidad(it.cantidad) }}{{ it.unidad ? ` ${unidad(it.unidad)}` : '' }}</p>
                                        </td>
                                        <td class="px-2 py-2 text-right align-top whitespace-nowrap tabular-nums">{{ soles(it.precio) }}</td>
                                        <td class="py-2 pl-2 text-right align-top font-medium whitespace-nowrap tabular-nums">{{ soles(it.total) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t-2 border-stone-200 dark:border-neutral-700">
                                    <tr>
                                        <td colspan="2" class="py-2 pr-2 font-bold">Total</td>
                                        <td class="py-2 pl-2 text-right text-base font-bold whitespace-nowrap tabular-nums">{{ soles(d.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div v-if="d.pagos?.length">
                                <h4 class="mb-1.5 text-sm font-semibold">Cómo se pagó</h4>
                                <ul class="space-y-1.5 text-sm">
                                    <li v-for="(p, i) in d.pagos" :key="i" class="flex items-center justify-between gap-3 rounded-xl bg-stone-50 px-3 py-2 dark:bg-neutral-800/60">
                                        <span class="min-w-0">{{ p.medio }}<span v-if="p.referencia" class="text-xs break-all text-neutral-500 dark:text-neutral-400"> · op. {{ p.referencia }}</span></span>
                                        <span class="font-semibold whitespace-nowrap tabular-nums">{{ soles(p.monto) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- compra -->
                        <div v-else-if="d?.tipo === 'compra'" class="space-y-4" data-detalle-compra>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <div class="col-span-2 min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Proveedor</dt><dd class="font-medium break-words">{{ d.proveedor }}</dd></div>
                                <div class="min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Registró</dt><dd class="font-medium break-words">{{ d.usuario ?? '—' }}</dd></div>
                            </dl>
                            <table class="w-full text-sm" data-detalle-items>
                                <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                    <tr>
                                        <th class="py-1.5 pr-2 text-left font-semibold">Producto</th>
                                        <th class="px-2 py-1.5 text-right font-semibold">Costo</th>
                                        <th class="py-1.5 pl-2 text-right font-semibold">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                    <tr v-for="(it, i) in d.items" :key="i">
                                        <td class="py-2 pr-2">
                                            <p class="break-words">{{ it.descripcion }}</p>
                                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ cantidad(it.cantidad) }}</p>
                                        </td>
                                        <td class="px-2 py-2 text-right align-top whitespace-nowrap tabular-nums">{{ soles(it.precio) }}</td>
                                        <td class="py-2 pl-2 text-right align-top font-medium whitespace-nowrap tabular-nums">{{ soles(it.total) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t-2 border-stone-200 dark:border-neutral-700">
                                    <tr>
                                        <td colspan="2" class="py-2 pr-2 font-bold">Total</td>
                                        <td class="py-2 pl-2 text-right text-base font-bold whitespace-nowrap tabular-nums">{{ soles(d.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div v-if="d.pagos?.length">
                                <h4 class="mb-1.5 text-sm font-semibold">Cómo se pagó</h4>
                                <ul class="space-y-1.5 text-sm">
                                    <li v-for="(p, i) in d.pagos" :key="i" class="flex items-center justify-between gap-3 rounded-xl bg-stone-50 px-3 py-2 dark:bg-neutral-800/60">
                                        <span class="min-w-0">{{ p.medio }}<span v-if="p.referencia" class="text-xs break-all text-neutral-500 dark:text-neutral-400"> · op. {{ p.referencia }}</span></span>
                                        <span class="font-semibold whitespace-nowrap tabular-nums text-red-600 dark:text-red-400">− {{ soles(p.monto) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- cobro / pago a proveedor -->
                        <div v-else-if="d?.tipo === 'cobro' || d?.tipo === 'pago_proveedor'" class="space-y-4" :data-detalle-deuda="d.tipo">
                            <p class="text-2xl font-bold tabular-nums" :class="d.tipo === 'cobro' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                {{ d.tipo === 'cobro' ? '+' : '−' }} {{ soles(d.monto) }}
                            </p>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <div class="col-span-2 min-w-0">
                                    <dt class="text-xs text-neutral-500 dark:text-neutral-400">{{ d.tipo === 'cobro' ? 'Cliente' : 'Proveedor' }}</dt>
                                    <dd class="font-medium break-words">{{ (d.tipo === 'cobro' ? d.cliente : d.proveedor) ?? '—' }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs text-neutral-500 dark:text-neutral-400">{{ d.tipo === 'cobro' ? 'Venta' : 'Compra' }}</dt>
                                    <dd class="font-mono font-medium break-words">{{ (d.tipo === 'cobro' ? d.venta : d.compra) ?? '—' }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs text-neutral-500 dark:text-neutral-400">Medio</dt>
                                    <dd class="font-medium">{{ d.medio }}<span v-if="d.referencia" class="block text-xs break-all text-neutral-500 dark:text-neutral-400">op. {{ d.referencia }}</span></dd>
                                </div>
                                <div class="col-span-2 min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Registró</dt><dd class="font-medium break-words">{{ d.usuario ?? '—' }}</dd></div>
                            </dl>
                            <div v-if="d.deuda_total > 0" class="rounded-xl bg-stone-50 px-3 py-2.5 text-sm dark:bg-neutral-800/60">
                                <p>Deuda: pagado <b class="tabular-nums">{{ soles(d.deuda_pagada) }}</b> de <b class="tabular-nums">{{ soles(d.deuda_total) }}</b></p>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                                    <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, (d.deuda_pagada / d.deuda_total) * 100)}%` }" />
                                </div>
                            </div>
                        </div>

                        <!-- ingreso / egreso manual -->
                        <div v-else-if="d?.tipo === 'movimiento'" class="space-y-4" data-detalle-movimiento>
                            <p class="text-lg font-semibold break-words">{{ d.concepto }}</p>
                            <p class="text-2xl font-bold tabular-nums" :class="esSalida ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">
                                {{ esSalida ? '−' : '+' }} {{ soles(d.monto) }}
                            </p>
                            <p v-if="d.pedido" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" data-detalle-pedido>
                                Pedido <b>{{ d.pedido.codigo }}</b> · {{ d.pedido.cliente }}
                            </p>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <div class="min-w-0">
                                    <dt class="text-xs text-neutral-500 dark:text-neutral-400">Medio</dt>
                                    <dd class="font-medium">{{ d.medio }}<span v-if="d.referencia" class="block text-xs break-all text-neutral-500 dark:text-neutral-400">op. {{ d.referencia }}</span></dd>
                                </div>
                                <div class="min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Caja</dt><dd class="font-medium break-words">{{ d.caja ?? '—' }}</dd></div>
                                <div class="col-span-2 min-w-0"><dt class="text-xs text-neutral-500 dark:text-neutral-400">Registró</dt><dd class="font-medium break-words">{{ d.usuario ?? '—' }}</dd></div>
                            </dl>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-stone-200 px-5 py-3 dark:border-neutral-800">
                        <button
                            v-if="d?.tipo === 'venta' && d.ticket"
                            type="button"
                            class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 sm:flex-none"
                            data-imprimir-ticket
                            @click="imprimirTicket(d.ticket)"
                        >
                            <Printer class="size-4" />
                            Imprimir ticket
                        </button>
                        <a
                            v-if="d?.tipo === 'compra' && d.pdf"
                            :href="d.pdf"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 sm:flex-none"
                            data-ver-pdf
                        >
                            <FileText class="size-4" />
                            Ver PDF
                        </a>
                        <button type="button" :class="claseBotonSec" class="flex-1 sm:flex-none" data-cerrar-detalle @click="cerrarDetalle">Cerrar</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
