<script setup>
import { computed, onMounted, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { AlertTriangle, X } from '@lucide/vue'

// Anular un pedido: si tenía adelanto, se decide si se devuelve (sale de la caja abierta) o no.
const props = defineProps({
    pedido: { type: Object, required: true },
    mediosPago: { type: Array, default: () => [] },
    cajaAbierta: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`

const adelantos = computed(() => Number(props.pedido.total_adelantos ?? 0))
const tieneAdelanto = computed(() => adelantos.value > 0)

// por defecto se devuelve por el medio del único adelanto; con varios distintos, en efectivo
function medioInicial() {
    const codigos = [...new Set((props.pedido.adelantos ?? []).map((a) => a.medio_codigo).filter(Boolean))]
    const candidato = codigos.length === 1 ? codigos[0] : 'efectivo'
    if (props.mediosPago.some((m) => m.codigo === candidato)) return candidato
    return props.mediosPago[0]?.codigo ?? 'efectivo'
}

const form = useForm({
    motivo: '',
    devolver_adelanto: tieneAdelanto.value,
    medio_devolucion: medioInicial(),
})

const sinCajaParaDevolver = computed(() => tieneAdelanto.value && form.devolver_adelanto && !props.cajaAbierta)
const puedeAnular = computed(() => form.motivo.trim().length > 0 && !sinCajaParaDevolver.value && !form.processing)

const campoMotivo = ref(null)
onMounted(() => campoMotivo.value?.focus())

function anular() {
    if (!puedeAnular.value) return
    form.transform((data) => ({
        motivo: data.motivo.trim(),
        devolver_adelanto: tieneAdelanto.value && data.devolver_adelanto,
        medio_devolucion: tieneAdelanto.value && data.devolver_adelanto ? data.medio_devolucion : null,
    })).post(`/pedidos/${props.pedido.id}/anular`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseOpcion = (activa) => [
    'flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm transition-colors',
    activa ? 'border-emerald-500 bg-emerald-50/60 dark:border-emerald-600 dark:bg-emerald-950/20' : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800',
]
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4" data-modal-anulacion @keydown.esc="emit('close')">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('close')" />
            <form
                class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                @submit.prevent="anular"
            >
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="font-semibold tracking-tight">Anular {{ pedido.codigo }}</h3>
                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ pedido.cliente_nombre }} · {{ soles(pedido.total) }}</p>
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

                <div class="space-y-4">
                    <div>
                        <label :class="claseLabel" for="anular_motivo">¿Por qué se anula? *</label>
                        <input
                            id="anular_motivo"
                            ref="campoMotivo"
                            v-model="form.motivo"
                            type="text"
                            maxlength="250"
                            :class="claseInput"
                            placeholder="Ej. el cliente ya no lo necesita"
                            data-anular-motivo
                        />
                        <p v-if="form.errors.motivo" :class="claseError">{{ form.errors.motivo }}</p>
                    </div>

                    <div v-if="tieneAdelanto">
                        <p :class="claseLabel">El cliente adelantó {{ soles(adelantos) }}</p>
                        <div class="space-y-2">
                            <label :class="claseOpcion(form.devolver_adelanto)">
                                <input v-model="form.devolver_adelanto" type="radio" :value="true" class="mt-0.5 size-4 accent-emerald-600" data-anular-devolver="si" />
                                <span>
                                    <span class="block font-medium">Devolver {{ soles(adelantos) }} al cliente</span>
                                    <span class="block text-xs text-neutral-500 dark:text-neutral-400">Sale de tu caja como egreso.</span>
                                </span>
                            </label>
                            <label :class="claseOpcion(!form.devolver_adelanto)">
                                <input v-model="form.devolver_adelanto" type="radio" :value="false" class="mt-0.5 size-4 accent-emerald-600" data-anular-devolver="no" />
                                <span>
                                    <span class="block font-medium">No devolver</span>
                                    <span class="block text-xs text-neutral-500 dark:text-neutral-400">El adelanto queda registrado como no devuelto.</span>
                                </span>
                            </label>
                        </div>

                        <div v-if="form.devolver_adelanto" class="mt-3">
                            <p :class="claseLabel">¿Por qué medio se devuelve?</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="m in mediosPago"
                                    :key="m.codigo"
                                    type="button"
                                    class="rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="form.medio_devolucion === m.codigo
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    :data-anular-medio="m.codigo"
                                    @click="form.medio_devolucion = m.codigo"
                                >
                                    {{ m.nombre }}
                                </button>
                            </div>
                            <p v-if="form.errors.medio_devolucion" :class="claseError">{{ form.errors.medio_devolucion }}</p>
                            <p
                                v-if="sinCajaParaDevolver"
                                class="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                                data-anular-sin-caja
                            >
                                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                                <span>
                                    Necesitas una caja abierta para devolver el dinero.
                                    <Link href="/caja" class="font-semibold underline">Abrir caja</Link>
                                    o elige "No devolver".
                                </span>
                            </p>
                        </div>
                    </div>
                    <p v-else class="text-xs text-neutral-500 dark:text-neutral-400">
                        El pedido quedará anulado y ya no se podrá entregar ni editar. No tiene adelantos, así que la caja no se toca.
                    </p>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="emit('close')"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="!puedeAnular"
                        class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        data-anular-confirmar
                    >
                        {{ form.processing ? 'Anulando...' : 'Anular pedido' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
