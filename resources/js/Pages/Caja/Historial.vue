<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ArrowLeft, Eye, Printer, Wallet } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useImpresion } from '@/composables/impresion'

const props = defineProps({
    historial: { type: Object, required: true },
    suma: { type: Object, default: null },
    filtros: { type: Object, default: () => ({ desde: null, hasta: null }) },
    cajaAbierta: { type: Object, default: null },
})

const { imprimirTicket } = useImpresion()

const formatoSoles = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// negativos con el signo delante: −S/ 1,359.18
const soles = (n) => { const v = Number(n ?? 0); return `${v < 0 ? '−' : ''}S/ ${formatoSoles.format(Math.abs(v))}` }
const fechaHora = (iso) => {
    const f = new Date(iso)
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' +
        f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

function estadoDiferencia(diferencia) {
    if (Math.abs(diferencia) < 0.005)
        return { texto: 'Cuadrada', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
    if (diferencia > 0)
        return { texto: `Sobró ${soles(diferencia)}`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: `Faltó ${soles(-diferencia)}`, clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
}

// ---- rango de fechas y suma (como el sistema anterior) ----
const rango = ref({ desde: props.filtros?.desde ?? '', hasta: props.filtros?.hasta ?? '' })
watch(() => props.filtros, (f) => { rango.value = { desde: f?.desde ?? '', hasta: f?.hasta ?? '' } })
const isoLocal = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
function filtrarHistorial(valores = rango.value) {
    rango.value = { ...valores }
    router.get('/caja/historial', { desde: rango.value.desde || undefined, hasta: rango.value.hasta || undefined }, { preserveState: true, replace: true })
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

const claseBotonSec =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800'
</script>

<template>
    <AppLayout titulo="Historial de turnos">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <Link href="/caja" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400" data-volver-caja>
                    <ArrowLeft class="size-3.5" /> Volver a caja
                </Link>
                <h1 class="text-lg font-semibold tracking-tight">Historial de turnos</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Turnos cerrados: lo que entró, lo que salió y cómo cuadró el cajón.</p>
            </div>
            <Link
                v-if="cajaAbierta"
                :href="`/caja/movimientos?turno=${cajaAbierta.id}`"
                :class="claseBotonSec"
                class="w-full sm:w-auto"
                data-mi-turno
            >
                <Wallet class="size-4" />
                Mi turno actual
            </Link>
        </div>

        <!-- ============ Turnos cerrados ============ -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-semibold tracking-tight">Turnos cerrados</h2>
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
    </AppLayout>
</template>
