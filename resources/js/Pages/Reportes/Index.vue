<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useDark } from '@vueuse/core'
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip } from 'chart.js'
import { Bar } from 'vue-chartjs'
import { BookOpenText, Boxes, Download, FileText, Info, ShoppingCart, TrendingUp, Users, Wallet } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip)

const props = defineProps({
    filtros: { type: Object, required: true },
    datos: { type: Object, required: true },
    productos: { type: Array, required: true },
    // [{ grupo, reportes: [{ valor, label, descripcion, sin_fechas?, producto?, agrupar? }] }]
    catalogo: { type: Array, required: true },
})

const ICONOS = { Ventas: ShoppingCart, Ganancias: TrendingUp, Clientes: Users, Caja: Wallet, Inventario: Boxes, Contabilidad: BookOpenText }

const tipo = ref(props.filtros.tipo)
const desde = ref(props.filtros.desde)
const hasta = ref(props.filtros.hasta)
const productoId = ref(props.filtros.producto_id ?? '')
// día, semana o mes elegido a mano; vacío = lo decide el largo del rango
const agruparElegido = ref('')

const grupoActivo = computed(() => props.catalogo.find((g) => g.reportes.some((r) => r.valor === tipo.value)) ?? props.catalogo[0])
const reporte = computed(() => grupoActivo.value.reportes.find((r) => r.valor === tipo.value) ?? grupoActivo.value.reportes[0])

function elegirGrupo(grupo) {
    if (grupo.grupo !== grupoActivo.value.grupo) tipo.value = grupo.reportes[0].valor
}

function parametros() {
    return {
        tipo: tipo.value,
        desde: desde.value,
        hasta: hasta.value,
        producto_id: reporte.value.producto ? (productoId.value || undefined) : undefined,
        agrupar: reporte.value.agrupar ? (agruparElegido.value || undefined) : undefined,
    }
}

function aplicar() {
    router.get('/reportes', parametros(), { preserveState: true, preserveScroll: true, replace: true })
}

watch([tipo, desde, hasta, productoId, agruparElegido], aplicar)

// ---- rangos rápidos ----
const iso = (fecha) => `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`
const haceDias = (dias) => { const f = new Date(); f.setDate(f.getDate() - dias); return f }

const RANGOS = computed(() => {
    const hoy = new Date()
    return [
        { label: 'Hoy', desde: iso(hoy), hasta: iso(hoy) },
        { label: '7 días', desde: iso(haceDias(6)), hasta: iso(hoy) },
        { label: 'Este mes', desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: iso(hoy) },
        { label: 'Mes pasado', desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1)), hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 0)) },
        { label: 'Este año', desde: iso(new Date(hoy.getFullYear(), 0, 1)), hasta: iso(hoy) },
    ]
})

function usarRango(rango) {
    agruparElegido.value = ''
    desde.value = rango.desde
    hasta.value = rango.hasta
}

// ---- exportar ----
const urlExportar = computed(() => {
    const consulta = new URLSearchParams()
    for (const [clave, valor] of Object.entries({ ...parametros(), agrupar: reporte.value.agrupar ? props.filtros.agrupar : undefined })) {
        if (valor) consulta.set(clave, valor)
    }
    return (formato) => `/reportes/exportar?${consulta.toString()}&formato=${formato}`
})

const puedeExportar = computed(() => props.datos.filas.length > 0)

const mensajeVacio = computed(() => {
    if (reporte.value.producto && !productoId.value) return 'Elige un producto para ver su kardex.'
    if (reporte.value.sin_fechas) return 'Nadie te debe: no hay cuentas por cobrar pendientes.'
    return 'Sin datos en el rango seleccionado.'
})

const numericas = computed(() => new Set(props.datos.numericas ?? []))

// ---- gráfico ----
const esOscuro = useDark()
const soles = (n) => `S/ ${Number(n ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const grafico = computed(() => props.datos.grafico ?? null)
const colorTexto = computed(() => (esOscuro.value ? '#a3a3a3' : '#64748B'))
const colorGrilla = computed(() => (esOscuro.value ? 'rgba(255,255,255,0.06)' : '#E2E8F0'))
// los nombres largos (productos, clientes) se recortan en el eje; el completo sale al pasar el cursor
const recortar = (texto, largo = 26) => (String(texto).length > largo ? `${String(texto).slice(0, largo - 1)}…` : texto)

const datosGrafico = computed(() => ({
    labels: grafico.value?.etiquetas ?? [],
    datasets: [{
        data: grafico.value?.valores ?? [],
        backgroundColor: (ctx) => ((ctx.raw ?? 0) < 0 ? '#f43f5e' : (esOscuro.value ? '#059669' : '#10B981')),
        borderRadius: 4,
        borderSkipped: false,
        categoryPercentage: 0.72,
        maxBarThickness: grafico.value?.horizontal ? 22 : 40,
    }],
}))

const opcionesGrafico = computed(() => {
    const horizontal = !!grafico.value?.horizontal
    const ejeValores = {
        grid: { color: colorGrilla.value },
        border: { display: false },
        beginAtZero: true,
        ticks: { color: colorTexto.value, font: { size: 11 }, maxTicksLimit: 6, callback: (v) => `S/ ${Number(v).toLocaleString('es-PE')}` },
    }
    const ejeNombres = {
        grid: { display: false },
        border: { display: false },
        ticks: {
            color: colorTexto.value,
            font: { size: 11 },
            maxRotation: 0,
            autoSkipPadding: 10,
            autoSkip: !horizontal,
            callback(valor) { return recortar(this.getLabelForValue(valor), horizontal ? 26 : 12) },
        },
    }
    return {
        indexAxis: horizontal ? 'y' : 'x',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: esOscuro.value ? '#262626' : '#0F172A',
                titleColor: '#fafafa',
                bodyColor: '#d4d4d4',
                padding: 10,
                cornerRadius: 10,
                displayColors: false,
                callbacks: { label: (ctx) => ` ${soles(horizontal ? ctx.parsed.x : ctx.parsed.y)}` },
            },
        },
        scales: horizontal ? { x: ejeValores, y: ejeNombres } : { x: ejeNombres, y: ejeValores },
    }
})

// en horizontal la altura crece con la cantidad de barras
const altoGrafico = computed(() => (grafico.value?.horizontal ? `${Math.max(120, grafico.value.etiquetas.length * 34 + 44)}px` : '16rem'))

const claseInput =
    'h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'
</script>

<template>
    <AppLayout titulo="Reportes">
        <!-- Qué reporte: primero el tema, luego el reporte -->
        <div class="mb-4 overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="flex gap-1 overflow-x-auto border-b border-stone-200 p-2 dark:border-neutral-800" role="tablist" aria-label="Tema del reporte">
                <button
                    v-for="grupo in catalogo"
                    :key="grupo.grupo"
                    type="button"
                    role="tab"
                    :aria-selected="grupo.grupo === grupoActivo.grupo"
                    class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-colors"
                    :class="grupo.grupo === grupoActivo.grupo
                        ? 'bg-neutral-900 text-white dark:bg-emerald-500'
                        : 'text-neutral-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                    @click="elegirGrupo(grupo)"
                >
                    <component :is="ICONOS[grupo.grupo] ?? FileText" class="size-4" />
                    {{ grupo.grupo }}
                </button>
            </div>

            <div class="p-3">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="r in grupoActivo.reportes"
                        :key="r.valor"
                        type="button"
                        :aria-pressed="r.valor === tipo"
                        class="rounded-xl border px-3.5 py-2 text-sm font-medium transition-colors"
                        :class="r.valor === tipo
                            ? 'border-emerald-600 bg-emerald-50 text-emerald-800 dark:border-emerald-500 dark:bg-emerald-500/15 dark:text-emerald-300'
                            : 'border-stone-200 text-neutral-600 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        @click="tipo = r.valor"
                    >
                        {{ r.label }}
                    </button>
                </div>
                <p class="mt-2.5 px-0.5 text-sm text-neutral-500 dark:text-neutral-400">{{ reporte.descripcion }}</p>
            </div>
        </div>

        <!-- Filtros + exportar -->
        <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="!reporte.sin_fechas">
                    <label for="reporte-desde" class="text-sm text-neutral-500 dark:text-neutral-400">Del</label>
                    <input id="reporte-desde" v-model="desde" type="date" :class="claseInput" />
                    <label for="reporte-hasta" class="text-sm text-neutral-500 dark:text-neutral-400">al</label>
                    <input id="reporte-hasta" v-model="hasta" type="date" :class="claseInput" />

                    <div class="flex max-w-full gap-1 overflow-x-auto">
                        <button
                            v-for="rango in RANGOS"
                            :key="rango.label"
                            type="button"
                            class="h-10 shrink-0 rounded-xl px-3 text-sm font-medium whitespace-nowrap transition-colors"
                            :class="desde === rango.desde && hasta === rango.hasta
                                ? 'bg-stone-200 text-neutral-900 dark:bg-neutral-700 dark:text-white'
                                : 'text-neutral-500 hover:bg-stone-200/60 dark:text-neutral-400 dark:hover:bg-neutral-800'"
                            @click="usarRango(rango)"
                        >
                            {{ rango.label }}
                        </button>
                    </div>
                </template>
                <p v-else class="text-sm font-medium text-neutral-600 dark:text-neutral-300">{{ datos.periodo }}</p>

                <select v-if="reporte.producto" v-model="productoId" :class="[claseInput, 'max-w-72']" aria-label="Producto">
                    <option value="">Elige un producto...</option>
                    <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.nombre }} ({{ p.codigo_interno }})</option>
                </select>

                <select
                    v-if="reporte.agrupar"
                    :value="filtros.agrupar"
                    :class="claseInput"
                    aria-label="Agrupar por"
                    @change="agruparElegido = $event.target.value"
                >
                    <option value="dia">Por día</option>
                    <option value="semana">Por semana</option>
                    <option value="mes">Por mes</option>
                </select>
            </div>

            <div class="flex gap-2">
                <a
                    :href="puedeExportar ? urlExportar('xlsx') : undefined"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium transition-colors dark:border-neutral-700"
                    :class="puedeExportar ? 'hover:bg-stone-50 dark:hover:bg-neutral-800' : 'cursor-not-allowed opacity-50'"
                >
                    <Download class="size-4" />
                    Excel
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
        <div
            v-if="datos.resumen.length"
            class="mb-4 grid grid-cols-2 gap-3 sm:gap-4"
            :class="datos.resumen.length >= 5 ? 'lg:grid-cols-3 2xl:grid-cols-5' : 'xl:grid-cols-4'"
        >
            <div
                v-for="celda in datos.resumen"
                :key="celda.etiqueta"
                class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"
            >
                <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">{{ celda.etiqueta }}</p>
                <p class="mt-1.5 truncate text-lg font-bold tracking-tight sm:text-xl" :title="celda.valor">{{ celda.valor }}</p>
            </div>
        </div>

        <!-- Gráfico -->
        <div v-if="grafico" class="mb-4 rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 class="font-semibold tracking-tight">{{ grafico.titulo }}</h2>
            <div class="mt-4" :style="{ height: altoGrafico }">
                <Bar :data="datosGrafico" :options="opcionesGrafico" />
            </div>
        </div>

        <!-- Nota -->
        <p v-if="datos.nota" class="mb-4 flex items-start gap-2 px-1 text-sm text-neutral-500 dark:text-neutral-400">
            <Info class="mt-0.5 size-4 shrink-0" />
            {{ datos.nota }}
        </p>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th
                                v-for="(col, j) in datos.columnas"
                                :key="col"
                                class="px-4 py-3.5 font-semibold tracking-wider whitespace-nowrap"
                                :class="numericas.has(j) ? 'text-right' : ''"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!datos.filas.length">
                            <td :colspan="datos.columnas.length" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                {{ mensajeVacio }}
                            </td>
                        </tr>
                        <tr v-for="(fila, i) in datos.filas" :key="i" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td
                                v-for="(celda, j) in fila"
                                :key="j"
                                class="px-4 py-2.5 whitespace-nowrap"
                                :class="[j === 0 ? 'font-medium' : 'text-neutral-600 dark:text-neutral-300', numericas.has(j) ? 'text-right tabular-nums' : '']"
                            >
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
