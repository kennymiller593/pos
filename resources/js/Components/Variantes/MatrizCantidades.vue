<script setup>
// Matriz de cantidades de un modelo con variantes (compras y transferencias): la rejilla talla × color con un
// stepper − / + por celda. Al confirmar devuelve { cantidades: { [id de la variante]: n }, costo } y la pantalla
// agrega una fila normal por cada variante. En el celular sube desde abajo, como una hoja.
import { computed, ref } from 'vue'
import { useEventListener } from '@vueuse/core'
import { Layers, X } from '@lucide/vue'
import RejillaVariantes from '@/Components/Variantes/RejillaVariantes.vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
    // { nombre, modelo: { atributos }, variantes: [producto] } (lo que arma agruparPorModelo)
    grupo: { type: Object, required: true },
    titulo: { type: String, default: 'Cantidades por variante' },
    textoConfirmar: { type: String, default: 'Agregar' },
    // compras: campo de costo unitario común a todas las variantes
    conCosto: { type: Boolean, default: false },
    costoInicial: { type: [Number, String], default: '' },
    // (variante) => stock que se muestra bajo la celda (null = no se muestra)
    stockDe: { type: Function, default: null },
    // (variante) => tope de la celda (transferencias: lo que queda en el origen)
    maximoDe: { type: Function, default: null },
    // texto corto bajo la rejilla
    ayuda: { type: String, default: '' },
})
const emit = defineEmits(['confirmar', 'cerrar'])

// Esc cierra la matriz esté donde esté el foco
useEventListener('keydown', (evento) => {
    if (evento.key === 'Escape') emit('cerrar')
})

const cantidades = ref({})
const costo = ref(props.costoInicial ?? '')

const unidades = computed(() => Object.values(cantidades.value).reduce((n, c) => n + Number(c || 0), 0))
const variantesElegidas = computed(() => Object.keys(cantidades.value).length)
const costoValido = computed(() => costo.value === '' || costo.value === null || Number(costo.value) >= 0)
const total = computed(() => (props.conCosto && costo.value !== '' && costo.value !== null ? unidades.value * Number(costo.value || 0) : null))
const bloqueada = (variante) => (props.maximoDe ? Number(props.maximoDe(variante)) <= 0 : false)
const soles = (n) => `S/ ${Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

function confirmar() {
    if (!unidades.value || !costoValido.value) return
    emit('confirmar', {
        cantidades: { ...cantidades.value },
        costo: props.conCosto && costo.value !== '' && costo.value !== null ? Number(costo.value) : null,
    })
}
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" data-matriz-variantes :data-modelo="grupo.nombre" role="dialog" aria-modal="true" :aria-label="`${titulo}: ${grupo.nombre}`" v-bind="$attrs">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <div class="relative flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl sm:w-auto sm:max-w-[min(54rem,calc(100vw-2rem))] sm:min-w-[26rem] sm:rounded-2xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                <!-- cabecera -->
                <div class="flex items-start gap-3 border-b border-stone-200 px-4 py-3.5 sm:px-5 dark:border-neutral-800">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <Layers class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base leading-tight font-semibold tracking-tight">{{ titulo }}</h3>
                        <p class="truncate text-sm text-neutral-500 dark:text-neutral-400" data-matriz-modelo>{{ grupo.nombre }} · {{ grupo.variantes.length }} variantes</p>
                    </div>
                    <button type="button" class="-mt-1 -mr-1.5 grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="emit('cerrar')">
                        <X class="size-5" />
                    </button>
                </div>

                <!-- costo unitario común (compras) -->
                <div v-if="conCosto" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-stone-200 px-4 py-2.5 sm:px-5 dark:border-neutral-800">
                    <label for="matriz_costo" class="text-sm font-medium">Costo unitario</label>
                    <div
                        class="flex h-10 w-36 items-center rounded-xl border bg-white px-3 focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/30 dark:bg-neutral-950"
                        :class="costoValido ? 'border-stone-300 dark:border-neutral-700' : 'border-red-400 dark:border-red-600'"
                    >
                        <span class="mr-1.5 text-sm text-neutral-400">S/</span>
                        <input
                            id="matriz_costo"
                            v-model="costo"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            class="w-full min-w-0 [appearance:textfield] bg-transparent text-right text-sm font-semibold tabular-nums placeholder:font-normal placeholder:text-neutral-400 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                            data-matriz-costo
                            @focus="$event.target.select()"
                        />
                    </div>
                    <p class="min-w-0 flex-1 basis-40 text-xs text-neutral-500 dark:text-neutral-400">
                        {{ costo === '' || costo === null ? 'Vacío: cada variante toma su último costo.' : 'El mismo para todas; luego puedes cambiarlo por fila.' }}
                    </p>
                </div>

                <!-- rejilla -->
                <div class="min-h-0 flex-1 px-4 pt-3 sm:px-5">
                    <RejillaVariantes
                        v-model:cantidades="cantidades"
                        modo="cantidades"
                        :atributos="grupo.modelo.atributos"
                        :variantes="grupo.variantes"
                        :stock-de="stockDe"
                        :maximo-de="maximoDe"
                        :bloqueada="bloqueada"
                        :clase-scroll="conCosto ? 'max-h-[calc(90dvh-17.5rem)] sm:max-h-[52dvh]' : 'max-h-[calc(90dvh-14rem)] sm:max-h-[56dvh]'"
                    />
                    <p v-if="ayuda" class="mt-1.5 text-[11px] text-neutral-400 dark:text-neutral-500">{{ ayuda }}</p>
                </div>

                <!-- pie -->
                <div class="mt-2 flex items-center gap-3 border-t border-stone-200 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5 dark:border-neutral-800">
                    <p class="min-w-0 flex-1 text-sm leading-tight text-neutral-500 dark:text-neutral-400" data-matriz-resumen>
                        <span class="font-semibold text-neutral-900 tabular-nums dark:text-neutral-100" data-matriz-unidades>{{ unidades }}</span>
                        {{ unidades === 1 ? 'unidad' : 'unidades' }}<template v-if="variantesElegidas"> en {{ variantesElegidas }} {{ variantesElegidas === 1 ? 'variante' : 'variantes' }}</template>
                        <span v-if="total !== null && unidades" class="block text-xs tabular-nums" data-matriz-total>{{ soles(total) }}</span>
                    </p>
                    <button type="button" class="hidden h-11 shrink-0 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 sm:block dark:border-neutral-700 dark:hover:bg-neutral-800" @click="emit('cerrar')">Cancelar</button>
                    <button
                        type="button"
                        class="h-11 shrink-0 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!unidades || !costoValido"
                        data-matriz-confirmar
                        @click="confirmar"
                    >
                        {{ textoConfirmar }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
