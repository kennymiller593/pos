<script setup>
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { AlertCircle, Banknote, CalendarClock, Search, Truck, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    cuentas: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    resumen: { type: Object, required: true },
    mediosPago: { type: Array, required: true },
    cajaAbierta: { type: Boolean, default: false },
})

const { puede } = usePermisos()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const fecha = (f) => f ? new Date(`${String(f).slice(0, 10)}T00:00:00`).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const saldoDe = (c) => Number(c.monto_total) - Number(c.monto_pagado)
const estaVencida = (c) => c.estado !== 'pagado' && c.fecha_vencimiento && String(c.fecha_vencimiento).slice(0, 10) < new Date().toISOString().slice(0, 10)

const ESTADOS = {
    pendiente: { label: 'Pendiente', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    parcial: { label: 'Parcial', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
    pagado: { label: 'Pagado', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
}

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')
const estado = ref(props.filtros.estado ?? '')

function aplicarFiltros() {
    router.get('/cuentas-por-pagar', {
        buscar: buscar.value || undefined,
        estado: estado.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch(estado, aplicarFiltros)

// ---- pago ----
const cuentaPagar = ref(null)
const formPago = useForm({
    monto: '',
    medio_pago_codigo: 'efectivo',
    referencia: '',
})

function abrirPago(cuenta) {
    cuentaPagar.value = cuenta
    formPago.clearErrors()
    formPago.monto = saldoDe(cuenta).toFixed(2)
    formPago.medio_pago_codigo = 'efectivo'
    formPago.referencia = ''
}

function pagar() {
    formPago.transform((data) => ({
        ...data,
        referencia: data.referencia || null,
    })).post(`/cuentas-por-pagar/${cuentaPagar.value.id}/pagar`, {
        preserveScroll: true,
        onSuccess: () => (cuentaPagar.value = null),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Cuentas por pagar">
        <!-- Resumen -->
        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl bg-rose-600 p-4 text-white">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-rose-100">Total por pagar</p>
                    <Truck class="size-4 text-rose-200" />
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight">{{ soles(resumen.por_pagar) }}</p>
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Deudas pendientes</p>
                    <AlertCircle class="size-4 text-neutral-400" />
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight">{{ resumen.cuentas_pendientes }}</p>
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Vencidas</p>
                    <CalendarClock class="size-4" :class="resumen.vencidas > 0 ? 'text-red-500' : 'text-neutral-400'" />
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight" :class="resumen.vencidas > 0 ? 'text-red-600 dark:text-red-400' : ''">
                    {{ resumen.vencidas }}
                </p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por proveedor o RUC..."
                    class="h-10 w-full rounded-xl border border-stone-200 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500"
                />
            </div>
            <select
                v-model="estado"
                class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900"
            >
                <option value="">Todas</option>
                <option value="pendiente">Pendientes</option>
                <option value="parcial">Parciales</option>
                <option value="pagado">Pagadas</option>
            </select>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Proveedor</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Compra</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Vence</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Total</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Pagado</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Saldo</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!cuentas.data.length">
                            <td colspan="8" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <Truck class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                No hay deudas con proveedores. Las compras al crédito aparecerán aquí.
                            </td>
                        </tr>
                        <tr v-for="c in cuentas.data" :key="c.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ c.proveedor?.razon_social ?? '—' }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ c.proveedor?.ruc ? `RUC ${c.proveedor.ruc}` : '' }}{{ c.proveedor?.telefono ? ` · ${c.proveedor.telefono}` : '' }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-mono text-xs">{{ c.compra?.serie_numero ?? 'Sin comprobante' }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ fecha(c.compra?.fecha) }}</p>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span :class="estaVencida(c) ? 'font-semibold text-red-600 dark:text-red-400' : 'text-neutral-600 dark:text-neutral-300'">
                                    {{ fecha(c.fecha_vencimiento) }}
                                </span>
                                <span
                                    v-if="estaVencida(c)"
                                    class="ml-1.5 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400"
                                >
                                    Vencida
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ soles(c.monto_total) }}</td>
                            <td class="px-4 py-3 text-right text-neutral-600 dark:text-neutral-300">{{ soles(c.monto_pagado) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" :class="saldoDe(c) > 0 ? 'text-rose-600 dark:text-rose-400' : ''">
                                {{ soles(saldoDe(c)) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS[c.estado]?.clase">
                                    {{ ESTADOS[c.estado]?.label ?? c.estado }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="puede('cuentas_pagar.pagar') && c.estado !== 'pagado'"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                    @click="abrirPago(c)"
                                >
                                    <Banknote class="size-3.5" />
                                    Pagar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="cuentas.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ cuentas.from }}–{{ cuentas.to }} de {{ cuentas.total }} cuentas</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in cuentas.links" :key="i">
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

        <!-- Modal pago -->
        <Teleport to="body">
            <div v-if="cuentaPagar" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="cuentaPagar = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="pagar"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold tracking-tight">Pagar a proveedor</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ cuentaPagar.proveedor?.razon_social }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="cuentaPagar = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="rounded-xl bg-stone-50 p-4 text-sm dark:bg-neutral-950">
                        <div class="flex justify-between">
                            <span class="text-neutral-500 dark:text-neutral-400">Saldo pendiente</span>
                            <span class="font-semibold text-rose-600 dark:text-rose-400">{{ soles(saldoDe(cuentaPagar)) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label :class="claseLabel" for="pago_monto">Monto a pagar (S/) *</label>
                            <input
                                id="pago_monto"
                                v-model="formPago.monto"
                                type="number"
                                step="0.01"
                                min="0.01"
                                :max="saldoDe(cuentaPagar)"
                                :class="claseInput"
                                autofocus
                            />
                            <p v-if="formPago.errors.monto" :class="claseError">{{ formPago.errors.monto }}</p>
                        </div>
                        <div>
                            <p :class="claseLabel">Medio de pago *</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="m in mediosPago"
                                    :key="m.codigo"
                                    type="button"
                                    class="rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="formPago.medio_pago_codigo === m.codigo
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    @click="formPago.medio_pago_codigo = m.codigo"
                                >
                                    {{ m.nombre }}
                                </button>
                            </div>
                            <p
                                v-if="formPago.medio_pago_codigo === 'efectivo' && !cajaAbierta"
                                class="mt-1.5 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"
                            >
                                <AlertCircle class="size-3.5 shrink-0" />
                                El efectivo sale del cajón: necesitas <Link href="/caja" class="font-semibold underline">abrir caja</Link>.
                            </p>
                        </div>
                        <div v-if="mediosPago.find((m) => m.codigo === formPago.medio_pago_codigo)?.requiere_referencia">
                            <label :class="claseLabel" for="pago_ref">N° de operación / referencia</label>
                            <input id="pago_ref" v-model="formPago.referencia" type="text" :class="claseInput" />
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="cuentaPagar = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formPago.processing || (formPago.medio_pago_codigo === 'efectivo' && !cajaAbierta)"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formPago.processing ? 'Registrando...' : 'Registrar pago' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
