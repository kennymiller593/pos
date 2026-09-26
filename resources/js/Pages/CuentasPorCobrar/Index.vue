<script setup>
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { AlertCircle, Banknote, HandCoins, Search, X } from '@lucide/vue'
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
const numero = (c) => c.comprobante ? `${c.comprobante.serie}-${String(c.comprobante.correlativo).padStart(6, '0')}` : '—'
const fecha = (f) => f ? new Date(`${f.slice(0, 10)}T00:00:00`).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const saldoDe = (c) => Number(c.monto_total) - Number(c.monto_pagado)

const ESTADOS = {
    pendiente: { label: 'Pendiente', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    parcial: { label: 'Parcial', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
    pagado: { label: 'Pagado', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
}

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')
const estado = ref(props.filtros.estado ?? '')

function aplicarFiltros() {
    router.get('/cuentas-por-cobrar', {
        buscar: buscar.value || undefined,
        estado: estado.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch(estado, aplicarFiltros)

// ---- cobro ----
const cuentaCobrar = ref(null)
const formCobro = useForm({
    monto: '',
    medio_pago_codigo: 'efectivo',
    referencia: '',
})

function abrirCobro(cuenta) {
    cuentaCobrar.value = cuenta
    formCobro.clearErrors()
    formCobro.monto = saldoDe(cuenta).toFixed(2)
    formCobro.medio_pago_codigo = 'efectivo'
    formCobro.referencia = ''
}

function cobrar() {
    formCobro.transform((data) => ({
        ...data,
        referencia: data.referencia || null,
    })).post(`/cuentas-por-cobrar/${cuentaCobrar.value.id}/cobrar`, {
        preserveScroll: true,
        onSuccess: () => (cuentaCobrar.value = null),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Cuentas por cobrar">
        <!-- Resumen -->
        <div class="mb-4 grid gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-amber-500 p-4 text-white">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-amber-50">Total por cobrar</p>
                    <HandCoins class="size-4 text-amber-100" />
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight">{{ soles(resumen.por_cobrar) }}</p>
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Cuentas pendientes</p>
                    <AlertCircle class="size-4 text-neutral-400" />
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight">{{ resumen.cuentas_pendientes }}</p>
            </div>
        </div>

        <!-- Aviso caja -->
        <div
            v-if="!cajaAbierta"
            class="mb-4 flex items-center gap-2.5 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
        >
            <AlertCircle class="size-4 shrink-0" />
            <span>
                Para registrar cobros necesitas
                <Link href="/caja" class="font-semibold underline">abrir caja</Link>
                (el dinero cobrado entra al arqueo).
            </span>
        </div>

        <!-- Filtros -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por cliente o documento..."
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
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Cliente</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Venta</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Fecha</th>
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
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <HandCoins class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay cuentas por cobrar. Las ventas al crédito del POS aparecerán aquí.
                                </div>
                            </td>
                        </tr>
                        <tr v-for="c in cuentas.data" :key="c.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ c.cliente?.nombre ?? '—' }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ c.cliente?.numero_documento ?? '' }}{{ c.cliente?.telefono ? ` · ${c.cliente.telefono}` : '' }}
                                </p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ numero(c) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ fecha(c.comprobante?.fecha_emision) }}</td>
                            <td class="px-4 py-3 text-right">{{ soles(c.monto_total) }}</td>
                            <td class="px-4 py-3 text-right text-neutral-600 dark:text-neutral-300">{{ soles(c.monto_pagado) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" :class="saldoDe(c) > 0 ? 'text-amber-600 dark:text-amber-400' : ''">
                                {{ soles(saldoDe(c)) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS[c.estado]?.clase">
                                    {{ ESTADOS[c.estado]?.label ?? c.estado }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="puede('cuentas_cobrar.cobrar') && c.estado !== 'pagado'"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!cajaAbierta"
                                    :title="!cajaAbierta ? 'Abre caja para cobrar' : undefined"
                                    @click="abrirCobro(c)"
                                >
                                    <Banknote class="size-3.5" />
                                    Cobrar
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

        <!-- Modal cobro -->
        <Teleport to="body">
            <div v-if="cuentaCobrar" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="cuentaCobrar = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="cobrar"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold tracking-tight">Registrar cobro</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ cuentaCobrar.cliente?.nombre }} · {{ numero(cuentaCobrar) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="cuentaCobrar = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="rounded-xl bg-stone-50 p-4 text-sm dark:bg-neutral-950">
                        <div class="flex justify-between">
                            <span class="text-neutral-500 dark:text-neutral-400">Saldo pendiente</span>
                            <span class="font-semibold text-amber-600 dark:text-amber-400">{{ soles(saldoDe(cuentaCobrar)) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label :class="claseLabel" for="cobro_monto">Monto a cobrar (S/) *</label>
                            <input
                                id="cobro_monto"
                                v-model="formCobro.monto"
                                type="number"
                                step="0.01"
                                min="0.01"
                                :max="saldoDe(cuentaCobrar)"
                                :class="claseInput"
                                autofocus
                            />
                            <p v-if="formCobro.errors.monto" :class="claseError">{{ formCobro.errors.monto }}</p>
                        </div>
                        <div>
                            <p :class="claseLabel">Medio de pago *</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="m in mediosPago"
                                    :key="m.codigo"
                                    type="button"
                                    class="rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="formCobro.medio_pago_codigo === m.codigo
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    @click="formCobro.medio_pago_codigo = m.codigo"
                                >
                                    {{ m.nombre }}
                                </button>
                            </div>
                        </div>
                        <div v-if="mediosPago.find((m) => m.codigo === formCobro.medio_pago_codigo)?.requiere_referencia">
                            <label :class="claseLabel" for="cobro_ref">N° de operación / referencia</label>
                            <input id="cobro_ref" v-model="formCobro.referencia" type="text" :class="claseInput" />
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="cuentaCobrar = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formCobro.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formCobro.processing ? 'Registrando...' : 'Registrar cobro' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
