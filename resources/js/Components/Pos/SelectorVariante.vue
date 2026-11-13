<script setup>
// POS: cuadro talla × color de un modelo con variantes. Tocar una celda agrega 1 unidad de esa variante al
// carrito y el cuadro sigue abierto (para vender "2 M rojos y 1 L azul" de corrido); el contador de cada celda
// es lo que ya hay en el carrito. En el celular sube desde abajo, como una hoja.
import { computed } from 'vue'
import { useEventListener } from '@vueuse/core'
import { Layers, X } from '@lucide/vue'
import RejillaVariantes from '@/Components/Variantes/RejillaVariantes.vue'

const props = defineProps({
    // { nombre, modelo: { atributos }, variantes: [producto del POS], precioMin, precioIgual }
    tarjeta: { type: Object, required: true },
    // { [id del producto]: unidades que ya están en el carrito }
    enCarrito: { type: Object, default: () => ({}) },
    // aviso del POS cuando una variante no entra (sin stock): se ve aquí porque el cuadro tapa el aviso flotante
    aviso: { type: String, default: null },
    // hay otro cuadro abierto encima (el selector de series / IMEI): Esc cierra ese, no este
    enPausa: { type: Boolean, default: false },
})
const emit = defineEmits(['agregar', 'cerrar'])

// Esc cierra el cuadro esté donde esté el foco (queda en el buscador del POS, fuera del cuadro)
useEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && !props.enPausa) emit('cerrar')
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const precioDe = (variante) => (variante.presentaciones.find((p) => p.es_default) ?? variante.presentaciones[0])?.precio_venta

// misma regla que el POS: lo que controla stock y no tiene, no se vende. Con serie / IMEI la celda sigue
// abierta mientras haya unidades (al tocarla se eligen o se cambian en el selector de series)
const bloqueada = (variante) => {
    if (!variante.controla_stock) return false
    if (variante.controla_serie) return Number(variante.stock) <= 0
    return Number(variante.stock) - Number(props.enCarrito[variante.id] ?? 0) <= 0
}
const stockDe = (variante) => (variante.controla_stock ? Number(variante.stock) : null)
const notaDe = (variante) => (props.tarjeta.precioIgual ? '' : soles(precioDe(variante)))

const unidades = computed(() => props.tarjeta.variantes.reduce((n, v) => n + Number(props.enCarrito[v.id] ?? 0), 0))
const caracteristicas = computed(() => (props.tarjeta.modelo.atributos ?? []).map((a) => a.nombre).join(' × '))
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" data-selector-variante :data-modelo="tarjeta.nombre" role="dialog" aria-modal="true" :aria-label="`Variantes de ${tarjeta.nombre}`">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <div class="relative flex max-h-[88dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl sm:w-auto sm:max-w-[min(42rem,calc(100vw-2rem))] sm:min-w-96 sm:rounded-2xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                <!-- cabecera -->
                <div class="flex items-start gap-3 border-b border-stone-200 px-4 py-3.5 sm:px-5 dark:border-neutral-800">
                    <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <img v-if="tarjeta.imagen_url" :src="tarjeta.imagen_url" :alt="tarjeta.nombre" class="size-full bg-white object-contain" />
                        <Layers v-else class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-base leading-tight font-semibold tracking-tight" data-selector-modelo>{{ tarjeta.nombre }}</h3>
                        <p class="truncate text-sm text-neutral-500 dark:text-neutral-400">
                            <span class="font-semibold text-emerald-700 dark:text-emerald-400" data-selector-precio>{{ tarjeta.precioIgual ? '' : 'desde ' }}{{ soles(tarjeta.precioMin) }}</span>
                            · {{ caracteristicas }}
                        </p>
                    </div>
                    <button type="button" class="-mt-1 -mr-1.5 grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="emit('cerrar')">
                        <X class="size-5" />
                    </button>
                </div>

                <!-- rejilla -->
                <div class="min-h-0 flex-1 px-4 pt-3 sm:px-5">
                    <RejillaVariantes
                        modo="tocar"
                        :atributos="tarjeta.modelo.atributos"
                        :variantes="tarjeta.variantes"
                        :contadores="enCarrito"
                        :stock-de="stockDe"
                        :bloqueada="bloqueada"
                        :nota-de="notaDe"
                        clase-scroll="max-h-[calc(88dvh-13.5rem)] sm:max-h-[56dvh]"
                        @tocar="emit('agregar', $event)"
                    />
                    <p class="mt-1.5 text-[11px] text-neutral-400 dark:text-neutral-500">Toca una celda para agregar 1 unidad. El número es el stock disponible.</p>
                </div>

                <!-- pie -->
                <div class="flex items-center gap-3 border-t border-stone-200 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5 dark:border-neutral-800">
                    <p v-if="aviso" class="min-w-0 flex-1 text-xs font-medium text-red-600 dark:text-red-400" data-selector-aviso>{{ aviso }}</p>
                    <p v-else class="min-w-0 flex-1 text-sm text-neutral-500 dark:text-neutral-400" data-selector-contador>
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ unidades }}</span>
                        {{ unidades === 1 ? 'unidad en el carrito' : 'unidades en el carrito' }}
                    </p>
                    <button type="button" class="h-11 shrink-0 rounded-xl bg-emerald-600 px-6 text-sm font-semibold text-white transition-colors hover:bg-emerald-700" data-selector-listo @click="emit('cerrar')">
                        Listo
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
