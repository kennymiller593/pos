<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ArrowLeft, Lock, Wallet } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import ListaMovimientos from '@/Components/Caja/ListaMovimientos.vue'

const props = defineProps({
    entradas: { type: Array, default: () => [] },
    salidas: { type: Array, default: () => [] },
    resumen: { type: Object, required: true },
    filtros: { type: Object, default: () => ({ desde: null, hasta: null, turno: null }) },
    turno: { type: Object, default: null },
    cajaAbierta: { type: Object, default: null },
})

const formatoSoles = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// negativos con el signo delante: −S/ 1,359.18
const soles = (n) => { const v = Number(n ?? 0); return `${v < 0 ? '−' : ''}S/ ${formatoSoles.format(Math.abs(v))}` }
const dosDigitos = (n) => String(n).padStart(2, '0')
// dd/mm/aaaa hh:mm
const fechaLarga = (iso) => {
    if (!iso) return ''
    const f = new Date(iso)
    return `${dosDigitos(f.getDate())}/${dosDigitos(f.getMonth() + 1)}/${f.getFullYear()} ${dosDigitos(f.getHours())}:${dosDigitos(f.getMinutes())}`
}

// ---- filtro por fechas ----
const rango = ref({ desde: props.filtros?.desde ?? '', hasta: props.filtros?.hasta ?? '' })
watch(() => props.filtros, (f) => { rango.value = { desde: f?.desde ?? '', hasta: f?.hasta ?? '' } })
const isoLocal = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
function filtrar(valores = rango.value) {
    rango.value = { ...valores }
    router.get('/caja/movimientos', { desde: rango.value.desde || undefined, hasta: rango.value.hasta || undefined }, { preserveState: true, replace: true })
}
function rangoRapido(tipo) {
    const hoy = new Date()
    if (tipo === 'mes') return filtrar({ desde: isoLocal(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: isoLocal(hoy) })
    if (tipo === 'semana') return filtrar({ desde: isoLocal(new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - 6)), hasta: isoLocal(hoy) })
    return filtrar({ desde: isoLocal(hoy), hasta: isoLocal(hoy) })
}
const textoRango = computed(() => {
    const f = (s) => (s ? s.split('-').reverse().join('/') : '')
    if (!props.filtros?.desde) return ''
    return props.filtros.desde === props.filtros.hasta ? f(props.filtros.desde) : `${f(props.filtros.desde)} al ${f(props.filtros.hasta)}`
})

// ---- turno: cuadre del cajón ----
const efectivoTurno = computed(() => props.turno?.medios?.find((m) => m.codigo === 'efectivo') ?? null)
const cuadre = computed(() => {
    if (!props.turno) return null
    const e = efectivoTurno.value
    const inicial = Number(e?.inicial ?? props.turno.monto_inicial ?? 0)
    const entro = e ? Number(e.ventas ?? 0) + Number(e.cobros ?? 0) + Number(e.ingresos ?? 0) : Number(props.turno.resumen?.ventas_efectivo ?? 0) + Number(props.turno.resumen?.ingresos ?? 0)
    const salio = e ? Number(e.egresos ?? 0) + Number(e.pagos_proveedor ?? 0) : Number(props.turno.resumen?.egresos ?? 0)
    // turno cerrado: el cuadre que quedó guardado al cerrar (el mismo que muestra el historial)
    const cierre = props.turno.cierre
    const esperado = cierre ? Number(cierre.esperado) : Number(e?.esperado ?? props.turno.resumen?.esperado ?? inicial + entro - salio)
    const contado = cierre ? Number(cierre.contado) : null
    const diferencia = cierre ? Number(cierre.diferencia) : null
    return { inicial, entro, salio, esperado, contado, diferencia }
})
const estadoCuadre = computed(() => {
    const d = cuadre.value?.diferencia
    if (d === null || d === undefined) return null
    if (Math.abs(d) < 0.005) return { texto: 'Cuadrada', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
    if (d > 0) return { texto: `Sobran ${soles(d)}`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: `Faltan ${soles(-d)}`, clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
})
const entroMedio = (m) => Number(m.ventas ?? 0) + Number(m.cobros ?? 0) + Number(m.ingresos ?? 0)
const salioMedio = (m) => Number(m.egresos ?? 0) + Number(m.pagos_proveedor ?? 0)

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm text-neutral-900 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100'
const claseLabel = 'mb-1 block text-xs font-medium text-neutral-500 dark:text-neutral-400'
const claseChip = 'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap'
const claseBotonSec =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800'
</script>

<template>
    <AppLayout titulo="Movimientos de caja">
        <!-- ============ Cabecera: filtro por fechas o tarjeta del turno ============ -->
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div v-if="turno" class="min-w-0 rounded-2xl border border-stone-200 bg-white px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900" data-turno-movimientos>
                <Link href="/caja/movimientos" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400" data-ver-por-fechas>
                    <ArrowLeft class="size-3.5" /> Ver por fechas
                </Link>
                <p class="font-semibold tracking-tight break-words">
                    Turno de {{ turno.cajero ?? '—' }} · {{ turno.caja }}
                </p>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ fechaLarga(turno.abierta_en) }} →
                    <span v-if="turno.cerrada_en">{{ fechaLarga(turno.cerrada_en) }}</span>
                    <span v-else :class="[claseChip, 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300']">abierto</span>
                </p>
                <p v-if="turno.observaciones" class="mt-1 text-xs text-slate-600 italic dark:text-neutral-300">{{ turno.observaciones }}</p>
            </div>

            <form
                v-else
                class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-end"
                data-filtro-movimientos
                @submit.prevent="filtrar()"
            >
                <label class="min-w-0">
                    <span :class="claseLabel">Desde</span>
                    <input v-model="rango.desde" type="date" :max="rango.hasta || undefined" :class="claseInput" class="sm:w-40" data-filtro-desde />
                </label>
                <label class="min-w-0">
                    <span :class="claseLabel">Hasta</span>
                    <input v-model="rango.hasta" type="date" :min="rango.desde || undefined" :class="claseInput" class="sm:w-40" data-filtro-hasta />
                </label>
                <button type="submit" class="col-span-2 h-10 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700 sm:col-span-1" data-filtro-ver>Ver</button>
                <div class="col-span-2 grid grid-cols-3 gap-1 sm:flex">
                    <button type="button" class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800" data-rango="hoy" @click="rangoRapido('hoy')">Hoy</button>
                    <button type="button" class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800" data-rango="semana" @click="rangoRapido('semana')">7 días</button>
                    <button type="button" class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800" data-rango="mes" @click="rangoRapido('mes')">Este mes</button>
                </div>
            </form>

            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="cajaAbierta && turno?.id !== cajaAbierta.id"
                    :href="`/caja/movimientos?turno=${cajaAbierta.id}`"
                    :class="claseBotonSec"
                    class="flex-1 sm:flex-none"
                    data-mi-turno
                >
                    <Wallet class="size-4" />
                    Mi turno actual
                </Link>
                <Link href="/caja" :class="claseBotonSec" class="flex-1 sm:flex-none">
                    <ArrowLeft class="size-4" />
                    Volver a caja
                </Link>
            </div>
        </div>

        <ListaMovimientos
            :entradas="entradas"
            :salidas="salidas"
            :resumen="resumen"
            :texto-vacio="turno ? 'en este turno' : 'en este rango'"
            :mostrar-caja="!turno"
            :texto-rango="turno ? '' : textoRango"
        >
            <template #pie>
                <template v-if="turno && cuadre">
                    <div class="mt-4 rounded-xl border border-stone-200 bg-stone-50 p-3 sm:p-4 dark:border-neutral-800 dark:bg-neutral-950" data-cuadre-cajon>
                        <h3 class="mb-2 text-sm font-semibold">Cuadre del cajón (efectivo)</h3>
                        <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                            <span>Abrió con <b class="whitespace-nowrap tabular-nums">{{ soles(cuadre.inicial) }}</b></span>
                            <span class="text-neutral-400">·</span>
                            <span>entró en efectivo <b class="whitespace-nowrap text-emerald-600 tabular-nums dark:text-emerald-400">{{ soles(cuadre.entro) }}</b></span>
                            <span class="text-neutral-400">·</span>
                            <span>salió en efectivo <b class="whitespace-nowrap text-red-600 tabular-nums dark:text-red-400">{{ soles(cuadre.salio) }}</b></span>
                            <span class="text-neutral-400">=</span>
                            <span>debía haber <b class="whitespace-nowrap tabular-nums" data-cuadre-esperado>{{ soles(cuadre.esperado) }}</b></span>
                        </p>
                        <p v-if="turno.cerrada_en" class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <span>Se contó <b class="whitespace-nowrap tabular-nums">{{ soles(cuadre.contado) }}</b></span>
                            <span class="text-neutral-400">→</span>
                            <span v-if="estadoCuadre" :class="[claseChip, estadoCuadre.clase, 'px-2.5 py-1 text-xs']" data-cuadre-estado>{{ estadoCuadre.texto }}</span>
                        </p>
                        <div v-else class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span :class="[claseChip, 'w-fit bg-emerald-100 px-2.5 py-1 text-xs text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300']">Turno abierto</span>
                            <Link
                                href="/caja"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-red-700"
                                data-ir-cerrar-caja
                            >
                                <Lock class="size-4" />
                                Ir a cerrar caja
                            </Link>
                        </div>
                    </div>

                    <div v-if="turno.medios?.length" class="mt-4" data-turno-medios>
                        <h3 class="mb-1.5 text-sm font-semibold">Por medio de pago</h3>
                        <table class="w-full text-sm">
                            <thead class="text-xs text-slate-500 dark:text-neutral-500">
                                <tr>
                                    <th class="py-1.5 pr-2 text-left font-medium">Medio</th>
                                    <th class="px-2 py-1.5 text-right font-medium">Entró</th>
                                    <th class="px-2 py-1.5 text-right font-medium">Salió</th>
                                    <th class="py-1.5 pl-2 text-right font-medium">Esperado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="m in turno.medios" :key="m.codigo">
                                    <td class="py-1.5 pr-2 font-medium">{{ m.nombre }}</td>
                                    <td class="px-2 py-1.5 text-right whitespace-nowrap tabular-nums" :class="entroMedio(m) ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-300 dark:text-neutral-600'">{{ entroMedio(m) ? soles(entroMedio(m)) : '—' }}</td>
                                    <td class="px-2 py-1.5 text-right whitespace-nowrap tabular-nums" :class="salioMedio(m) ? 'text-red-600 dark:text-red-400' : 'text-neutral-300 dark:text-neutral-600'">{{ salioMedio(m) ? soles(salioMedio(m)) : '—' }}</td>
                                    <td class="py-1.5 pl-2 text-right font-semibold whitespace-nowrap tabular-nums">{{ soles(m.esperado) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">El esperado de efectivo incluye el monto con que se abrió la caja.</p>
                    </div>
                </template>
            </template>
        </ListaMovimientos>
    </AppLayout>
</template>
