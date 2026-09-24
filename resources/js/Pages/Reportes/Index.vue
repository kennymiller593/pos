<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Download, FileText } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    filtros: { type: Object, required: true },
    datos: { type: Object, required: true },
    productos: { type: Array, required: true },
})

const TABS = [
    { valor: 'ventas', label: 'Ventas' },
    { valor: 'libro', label: 'Registro de ventas (para el contador)' },
    { valor: 'margen', label: 'Margen por producto' },
    { valor: 'kardex', label: 'Kardex' },
]

const tipo = ref(props.filtros.tipo)
const desde = ref(props.filtros.desde)
const hasta = ref(props.filtros.hasta)
const productoId = ref(props.filtros.producto_id ?? '')

function aplicar() {
    router.get('/reportes', {
        tipo: tipo.value,
        desde: desde.value,
        hasta: hasta.value,
        producto_id: tipo.value === 'kardex' ? (productoId.value || undefined) : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watch([tipo, desde, hasta, productoId], aplicar)

const urlExportar = computed(() => {
    const parametros = new URLSearchParams({
        tipo: tipo.value,
        desde: desde.value,
        hasta: hasta.value,
    })
    if (tipo.value === 'kardex' && productoId.value) parametros.set('producto_id', productoId.value)
    return (formato) => `/reportes/exportar?${parametros.toString()}&formato=${formato}`
})

const puedeExportar = computed(() => props.datos.filas.length > 0)

const claseInput =
    'h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'
</script>

<template>
    <AppLayout titulo="Reportes">
        <!-- Tabs -->
        <div class="mb-4 flex flex-wrap gap-2">
            <button
                v-for="tab in TABS"
                :key="tab.valor"
                class="rounded-xl px-4 py-2 text-sm font-medium transition-colors"
                :class="tipo === tab.valor
                    ? 'bg-neutral-900 text-white dark:bg-emerald-500'
                    : 'border border-stone-200 bg-white text-neutral-600 hover:bg-stone-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                @click="tipo = tab.valor"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- Filtros + exportar -->
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <label class="text-sm text-neutral-500 dark:text-neutral-400">Del</label>
                <input v-model="desde" type="date" :class="claseInput" />
                <label class="text-sm text-neutral-500 dark:text-neutral-400">al</label>
                <input v-model="hasta" type="date" :class="claseInput" />

                <select v-if="tipo === 'kardex'" v-model="productoId" :class="[claseInput, 'max-w-72']">
                    <option value="">Elige un producto...</option>
                    <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.nombre }} ({{ p.codigo_interno }})</option>
                </select>
            </div>

            <div class="flex gap-2">
                <a
                    :href="puedeExportar ? urlExportar('csv') : undefined"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium transition-colors dark:border-neutral-700"
                    :class="puedeExportar ? 'hover:bg-stone-50 dark:hover:bg-neutral-800' : 'cursor-not-allowed opacity-50'"
                >
                    <Download class="size-4" />
                    Excel (CSV)
                </a>
                <a
                    :href="puedeExportar ? urlExportar('pdf') : undefined"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition-colors"
                    :class="puedeExportar ? 'hover:bg-emerald-700' : 'cursor-not-allowed opacity-50'"
                >
                    <FileText class="size-4" />
                    PDF
                </a>
            </div>
        </div>

        <!-- Resumen -->
        <div v-if="datos.resumen.length" class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="celda in datos.resumen"
                :key="celda.etiqueta"
                class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"
            >
                <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">{{ celda.etiqueta }}</p>
                <p class="mt-1.5 text-xl font-bold tracking-tight">{{ celda.valor }}</p>
            </div>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th v-for="col in datos.columnas" :key="col" class="px-4 py-3.5 font-semibold tracking-wider whitespace-nowrap">
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!datos.filas.length">
                            <td :colspan="datos.columnas.length" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                {{ tipo === 'kardex' && !productoId ? 'Elige un producto para ver su kardex.' : 'Sin datos en el rango seleccionado.' }}
                            </td>
                        </tr>
                        <tr v-for="(fila, i) in datos.filas" :key="i" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td v-for="(celda, j) in fila" :key="j" class="px-4 py-2.5 whitespace-nowrap" :class="j === 0 ? '' : 'text-neutral-600 dark:text-neutral-300'">
                                {{ celda }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="datos.filas.length" class="border-t border-stone-200 px-4 py-3 text-xs text-neutral-400 dark:border-neutral-800 dark:text-neutral-500">
                {{ datos.filas.length }} registro{{ datos.filas.length === 1 ? '' : 's' }}
                <template v-if="datos.contexto"> · {{ datos.contexto }}</template>
            </p>
        </div>
    </AppLayout>
</template>
