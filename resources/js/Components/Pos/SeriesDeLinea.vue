<script setup>
// Línea del carrito de un producto que controla serie / IMEI: las unidades elegidas y el botón para cambiarlas.
import { ScanBarcode } from '@lucide/vue'

defineProps({
    // [{ id, numero_serie, serie_alterna }]
    series: { type: Array, default: () => [] },
    cantidad: { type: Number, default: 0 },
    textoFalta: { type: String, default: 'Falta elegir la unidad que entregas.' },
})
defineEmits(['elegir'])
</script>

<template>
    <div class="min-w-0" data-linea-series>
        <div class="flex flex-wrap items-center gap-1">
            <span
                v-for="serie in series"
                :key="serie.id"
                class="max-w-full truncate rounded-md bg-stone-100 px-1.5 py-0.5 font-mono text-[11px] text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                :title="serie.serie_alterna ? `2.º IMEI: ${serie.serie_alterna}` : undefined"
                data-serie-elegida
            >
                {{ serie.numero_serie }}
            </span>
            <button
                type="button"
                class="inline-flex h-7 items-center gap-1 rounded-lg border px-2 text-xs font-medium transition-colors"
                :class="series.length
                    ? 'border-stone-200 text-neutral-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'
                    : 'border-emerald-500 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'"
                data-elegir-series-abrir
                @click="$emit('elegir')"
            >
                <ScanBarcode class="size-3.5" />
                {{ series.length ? 'Cambiar' : 'Elegir serie / IMEI' }}
            </button>
        </div>
        <p v-if="!series.length || series.length !== cantidad" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400" data-falta-serie>
            {{ series.length ? `Elegiste ${series.length} de ${cantidad} unidades.` : textoFalta }}
        </p>
    </div>
</template>
