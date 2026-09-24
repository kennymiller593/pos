<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Banknote,
    Clock,
    Lock,
    LockOpen,
    Plus,
    ShoppingCart,
    Wallet,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    cajas: { type: Array, required: true },
    apertura: { type: Object, default: null },
    historial: { type: Object, required: true },
})

const { confirmar } = useConfirmar()
const { puede } = usePermisos()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
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
const formAbrir = useForm({
    caja_id: props.cajas.find((c) => !c.ocupada_por)?.id ?? '',
    monto_inicial: '',
})

function abrir() {
    formAbrir.post('/caja/abrir', { preserveScroll: true })
}

// ---- movimientos ----
const modalMovimiento = ref(false)
const formMovimiento = useForm({
    tipo: 'ingreso',
    concepto: '',
    monto: '',
})

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
const formCierre = useForm({
    monto_cierre: '',
})

const diferencia = computed(() => {
    if (formCierre.monto_cierre === '') return null
    return Number(formCierre.monto_cierre) - (props.apertura?.resumen.esperado ?? 0)
})

async function cerrarCaja() {
    const confirmado = await confirmar({
        titulo: 'Cerrar caja',
        mensaje: 'Se cerrará el turno actual y no podrás registrar más ventas ni movimientos en él.',
        textoConfirmar: 'Cerrar caja',
        peligro: true,
    })
    if (confirmado) {
        formCierre.post('/caja/cerrar', {
            preserveScroll: true,
            onSuccess: () => {
                modalCierre.value = false
                formCierre.reset()
            },
        })
    }
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Caja">
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
                        <select id="caja" v-model="formAbrir.caja_id" :class="claseInput">
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
                <div class="flex gap-2">
                    <button
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="modalMovimiento = true"
                    >
                        <Plus class="size-4" />
                        Movimiento
                    </button>
                    <button
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-red-700"
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

            <!-- Movimientos -->
            <div class="mt-4 overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div class="border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <h2 class="font-semibold tracking-tight">Movimientos del turno</h2>
                </div>
                <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                    <li v-if="!apertura.movimientos.length" class="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        Sin movimientos manuales en este turno.
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
        <div class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900" :class="!apertura ? 'mx-auto max-w-5xl' : ''">
            <div class="border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                <h2 class="font-semibold tracking-tight">Historial de turnos</h2>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Arqueos de caja cerrados</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold tracking-wider">Turno</th>
                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Inicial</th>
                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Ventas efec.</th>
                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Ingresos</th>
                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Egresos</th>
                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Esperado</th>
                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Contado</th>
                            <th class="px-4 py-3 text-center font-semibold tracking-wider">Arqueo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!historial.data.length">
                            <td colspan="8" class="px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                                Aún no hay turnos cerrados.
                            </td>
                        </tr>
                        <tr v-for="t in historial.data" :key="t.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ t.caja }} · {{ t.usuario ?? '—' }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ fechaHora(t.abierta_en) }} → {{ fechaHora(t.cerrada_en) }}
                                </p>
                            </td>
                            <td class="px-3 py-3 text-right text-neutral-600 dark:text-neutral-300">{{ soles(t.monto_inicial) }}</td>
                            <td class="px-3 py-3 text-right text-neutral-600 dark:text-neutral-300">{{ soles(t.ventas_efectivo) }}</td>
                            <td class="px-3 py-3 text-right text-emerald-600 dark:text-emerald-400">{{ t.ingresos > 0 ? soles(t.ingresos) : '—' }}</td>
                            <td class="px-3 py-3 text-right text-red-600 dark:text-red-400">{{ t.egresos > 0 ? soles(t.egresos) : '—' }}</td>
                            <td class="px-3 py-3 text-right font-medium">{{ soles(t.monto_sistema) }}</td>
                            <td class="px-3 py-3 text-right font-medium">{{ soles(t.monto_cierre) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="estadoDiferencia(t.diferencia).clase">
                                    {{ estadoDiferencia(t.diferencia).texto }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

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
                            <label :class="claseLabel" for="monto">Monto (S/) *</label>
                            <input id="monto" v-model="formMovimiento.monto" type="number" step="0.01" min="0.01" :class="claseInput" placeholder="0.00" />
                            <p v-if="formMovimiento.errors.monto" :class="claseError">{{ formMovimiento.errors.monto }}</p>
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

        <!-- Modal: cerrar caja (arqueo) -->
        <Teleport to="body">
            <div v-if="modalCierre && apertura" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalCierre = false" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="cerrarCaja"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Cerrar caja · Arqueo</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalCierre = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="rounded-xl bg-stone-50 p-4 text-sm dark:bg-neutral-950">
                        <div class="flex justify-between">
                            <span class="text-neutral-500 dark:text-neutral-400">Efectivo esperado</span>
                            <span class="font-semibold">{{ soles(apertura.resumen.esperado) }}</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label :class="claseLabel" for="monto_cierre">Efectivo contado en el cajón *</label>
                        <input
                            id="monto_cierre"
                            v-model="formCierre.monto_cierre"
                            type="number"
                            step="0.01"
                            min="0"
                            :class="claseInput"
                            placeholder="0.00"
                            autofocus
                        />
                        <p v-if="formCierre.errors.monto_cierre" :class="claseError">{{ formCierre.errors.monto_cierre }}</p>
                    </div>

                    <p
                        v-if="diferencia !== null"
                        class="mt-3 rounded-xl px-4 py-2.5 text-sm font-medium"
                        :class="diferencia === 0
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                            : diferencia > 0
                                ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
                                : 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300'"
                    >
                        {{ diferencia === 0
                            ? '✓ Caja cuadrada'
                            : diferencia > 0
                                ? `Sobran ${soles(diferencia)}`
                                : `Faltan ${soles(Math.abs(diferencia))}` }}
                    </p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalCierre = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formCierre.processing"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formCierre.processing ? 'Cerrando...' : 'Cerrar caja' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
