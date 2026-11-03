<script setup>
import { computed, onMounted, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Info, X } from '@lucide/vue'

// Adelanto de un pedido: entra como ingreso a la caja abierta y no genera comprobante.
const props = defineProps({
    // objeto de la lista: { id, codigo, cliente_nombre, total, total_adelantos, saldo, ... }
    pedido: { type: Object, required: true },
    mediosPago: { type: Array, default: () => [] },
})

const emit = defineEmits(['close'])

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const redondear = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100

const saldo = computed(() => redondear(props.pedido.saldo ?? Math.max(0, Number(props.pedido.total) - Number(props.pedido.total_adelantos))))

const form = useForm({
    monto: '',
    medio_pago_codigo: props.mediosPago.some((m) => m.codigo === 'efectivo') ? 'efectivo' : (props.mediosPago[0]?.codigo ?? ''),
    referencia: '',
})

const medioElegido = computed(() => props.mediosPago.find((m) => m.codigo === form.medio_pago_codigo) ?? null)
const requiereReferencia = computed(() => !!medioElegido.value?.requiere_referencia)

const monto = computed(() => {
    const v = Number(String(form.monto).replace(',', '.'))
    return Number.isFinite(v) ? redondear(v) : 0
})
const superaSaldo = computed(() => monto.value > saldo.value + 0.004)
const faltaReferencia = computed(() => requiereReferencia.value && !form.referencia.trim())
const puedeRegistrar = computed(() => monto.value > 0 && !superaSaldo.value && !faltaReferencia.value && !form.processing)

const campoMonto = ref(null)
onMounted(() => campoMonto.value?.focus())

function registrar() {
    if (!puedeRegistrar.value) return
    form.transform((data) => ({
        monto: monto.value,
        medio_pago_codigo: data.medio_pago_codigo,
        referencia: data.referencia.trim() || null,
    })).post(`/pedidos/${props.pedido.id}/adelantos`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4" data-modal-adelanto @keydown.esc="emit('close')">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('close')" />
            <form
                class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                @submit.prevent="registrar"
            >
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="font-semibold tracking-tight">Recibir adelanto</h3>
                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ pedido.codigo }} · {{ pedido.cliente_nombre }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <dl class="space-y-1.5 rounded-xl bg-stone-50 p-4 text-sm dark:bg-neutral-950">
                    <div class="flex justify-between">
                        <dt class="text-neutral-500 dark:text-neutral-400">Total del pedido</dt>
                        <dd class="font-medium tabular-nums">{{ soles(pedido.total) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-neutral-500 dark:text-neutral-400">Ya adelantado</dt>
                        <dd class="font-medium tabular-nums">{{ soles(pedido.total_adelantos) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-200 pt-1.5 dark:border-neutral-800">
                        <dt class="font-semibold">Saldo</dt>
                        <dd class="font-semibold text-amber-600 tabular-nums dark:text-amber-400" data-adelanto-saldo>{{ soles(saldo) }}</dd>
                    </div>
                </dl>

                <div class="mt-4 space-y-4">
                    <div>
                        <label :class="claseLabel" for="adelanto_monto">Monto del adelanto *</label>
                        <div
                            class="flex h-11 items-center overflow-hidden rounded-xl border bg-white px-3 transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/20 dark:bg-neutral-950"
                            :class="superaSaldo ? 'border-red-400 dark:border-red-500' : 'border-stone-300 dark:border-neutral-700'"
                        >
                            <span class="text-sm text-[#94A3B8]">S/</span>
                            <input
                                id="adelanto_monto"
                                ref="campoMonto"
                                v-model="form.monto"
                                type="number"
                                inputmode="decimal"
                                step="0.01"
                                min="0.01"
                                :max="saldo"
                                placeholder="0.00"
                                class="h-full w-full min-w-0 flex-1 bg-transparent text-right text-base font-semibold tabular-nums [appearance:textfield] placeholder:font-normal placeholder:text-neutral-400 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                data-adelanto-monto
                                @focus="$event.target.select()"
                            />
                        </div>
                        <p v-if="form.errors.monto" :class="claseError">{{ form.errors.monto }}</p>
                        <p v-else-if="superaSaldo" :class="claseError" data-adelanto-supera>El adelanto supera el saldo ({{ soles(saldo) }}).</p>
                        <button
                            v-else-if="saldo > 0 && monto !== saldo"
                            type="button"
                            class="mt-1 text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                            data-adelanto-todo
                            @click="form.monto = saldo.toFixed(2)"
                        >
                            Adelantar todo el saldo ({{ soles(saldo) }})
                        </button>
                    </div>

                    <div>
                        <p :class="claseLabel">¿Cómo pagó? *</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="m in mediosPago"
                                :key="m.codigo"
                                type="button"
                                class="rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors"
                                :class="form.medio_pago_codigo === m.codigo
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                :data-adelanto-medio="m.codigo"
                                @click="form.medio_pago_codigo = m.codigo"
                            >
                                {{ m.nombre }}
                            </button>
                        </div>
                        <p v-if="form.errors.medio_pago_codigo" :class="claseError">{{ form.errors.medio_pago_codigo }}</p>
                    </div>

                    <div v-if="requiereReferencia">
                        <label :class="claseLabel" for="adelanto_ref">N° de operación / referencia *</label>
                        <input
                            id="adelanto_ref"
                            v-model="form.referencia"
                            type="text"
                            maxlength="100"
                            :class="claseInput"
                            placeholder="Ej. 123456"
                            data-adelanto-referencia
                        />
                        <p v-if="form.errors.referencia" :class="claseError">{{ form.errors.referencia }}</p>
                        <p v-else-if="faltaReferencia" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{{ medioElegido?.nombre }} necesita el número de operación.</p>
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="!puedeRegistrar"
                    class="mt-5 inline-flex h-11 w-full items-center justify-center rounded-xl bg-emerald-600 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    data-adelanto-confirmar
                >
                    {{ form.processing ? 'Registrando...' : monto > 0 && !superaSaldo ? `Registrar adelanto de ${soles(monto)}` : 'Registrar adelanto' }}
                </button>
                <p class="mt-3 flex items-start gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                    <Info class="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    Entra como ingreso a tu caja abierta. No genera comprobante: la boleta sale al entregar.
                </p>
            </form>
        </div>
    </Teleport>
</template>
