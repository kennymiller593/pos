<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useDark } from '@vueuse/core'
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js'
import { Bar, Doughnut } from 'vue-chartjs'
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    CalendarClock,
    CalendarRange,
    HandCoins,
    ReceiptText,
    Store,
    TrendingUp,
    Wallet,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Tooltip, Legend)

const props = defineProps({
    hoy: { type: Object, required: true },
    mes: { type: Object, default: null },
    serie: { type: Array, required: true },
    serieMeses: { type: Array, default: () => [] },
    serieDias: { type: Array, default: () => [] },
    topProductos: { type: Array, required: true },
    mediosPago: { type: Array, required: true },
    ventasSucursales: { type: Array, default: () => [] },
    ultimasVentas: { type: Array, default: () => [] },
    pendientes: { type: Object, required: true },
})

const soles = (n) => `S/ ${Number(n ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const esOscuro = useDark()

// paletas categoricas validadas (accesibilidad y daltonismo) por modo
const PALETA = computed(() => (esOscuro.value
    ? ['#059669', '#0284c7', '#8b5cf6', '#d97706', '#e11d48']
    : ['#10B981', '#4F46E5', '#8b5cf6', '#f59e0b', '#f43f5e']))

const colorTexto = computed(() => (esOscuro.value ? '#a3a3a3' : '#64748B'))
const colorGrilla = computed(() => (esOscuro.value ? 'rgba(255,255,255,0.06)' : '#E2E8F0'))
const superficie = computed(() => (esOscuro.value ? '#171717' : '#ffffff'))

const estiloTooltip = computed(() => ({
    backgroundColor: esOscuro.value ? '#262626' : '#0F172A',
    titleColor: '#fafafa',
    bodyColor: '#d4d4d4',
    padding: 10,
    cornerRadius: 10,
    displayColors: false,
}))

// ---- ventas de los ultimos 14 dias (barras, una sola serie) ----
const etiquetaDia = (iso) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('es-PE', { day: '2-digit', month: 'short' })

const datosSerie = computed(() => ({
    labels: props.serie.map((p) => etiquetaDia(p.fecha)),
    datasets: [{
        data: props.serie.map((p) => p.total),
        backgroundColor: PALETA.value[0],
        borderRadius: 4,
        borderSkipped: false,
        categoryPercentage: 0.72,
        maxBarThickness: 34,
    }],
}))

const opcionesSerie = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            ...estiloTooltip.value,
            callbacks: { label: (ctx) => ` ${soles(ctx.parsed.y)}` },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: colorTexto.value, font: { size: 11 }, maxRotation: 0, autoSkipPadding: 12 },
        },
        y: {
            grid: { color: colorGrilla.value },
            border: { display: false },
            ticks: { color: colorTexto.value, font: { size: 11 }, maxTicksLimit: 5, callback: (v) => `S/ ${v}` },
            beginAtZero: true,
        },
    },
}))

// ---- ventas por mes (12 ultimos, barras) ----
const etiquetaMes = (ym) =>
    new Date(`${ym}-01T00:00:00`).toLocaleDateString('es-PE', { month: 'short', year: '2-digit' })

const datosMeses = computed(() => ({
    labels: props.serieMeses.map((p) => etiquetaMes(p.mes)),
    datasets: [{
        data: props.serieMeses.map((p) => p.total),
        backgroundColor: PALETA.value[0],
        borderRadius: 4,
        borderSkipped: false,
        categoryPercentage: 0.72,
        maxBarThickness: 40,
    }],
}))

const opcionesMeses = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            ...estiloTooltip.value,
            callbacks: { label: (ctx) => ` ${soles(ctx.parsed.y)}` },
        },
    },
    scales: opcionesSerie.value.scales,
}))

// ---- promedio de venta por dia de la semana (barras) ----
const DIAS_SEMANA = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

const datosDias = computed(() => ({
    labels: DIAS_SEMANA,
    datasets: [{
        data: props.serieDias.map((p) => p.promedio),
        backgroundColor: PALETA.value[1],
        borderRadius: 4,
        borderSkipped: false,
        categoryPercentage: 0.72,
        maxBarThickness: 40,
    }],
}))

const opcionesDias = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            ...estiloTooltip.value,
            callbacks: {
                label: (ctx) => [
                    ` Promedio: ${soles(ctx.parsed.y)}`,
                    ` Total 90 días: ${soles(props.serieDias[ctx.dataIndex]?.total)}`,
                ],
            },
        },
    },
    scales: opcionesSerie.value.scales,
}))

// ---- ventas por medio de pago (dona) ----
const datosMedios = computed(() => ({
    labels: props.mediosPago.map((m) => m.nombre),
    datasets: [{
        data: props.mediosPago.map((m) => m.total),
        backgroundColor: PALETA.value.slice(0, props.mediosPago.length),
        borderColor: superficie.value,
        borderWidth: 2,
        hoverOffset: 6,
    }],
}))

const opcionesMedios = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '66%',
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                color: colorTexto.value,
                usePointStyle: true,
                pointStyle: 'circle',
                boxWidth: 8,
                boxHeight: 8,
                padding: 14,
                font: { size: 12 },
            },
        },
        tooltip: {
            ...estiloTooltip.value,
            callbacks: { label: (ctx) => ` ${ctx.label}: ${soles(ctx.parsed)}` },
        },
    },
}))

// ---- barras HTML con etiquetas directas ----
const maxTop = computed(() => Math.max(...props.topProductos.map((p) => p.total), 1))
const maxSucursal = computed(() => Math.max(...props.ventasSucursales.map((s) => s.mes), 1))

// sin permiso de finanzas el backend manda por_pagar = null: se omite ese renglon
const PENDIENTES = computed(() => [
    { label: 'Por cobrar', valor: soles(props.pendientes.por_cobrar), url: '/cuentas-por-cobrar', icon: HandCoins, alerta: props.pendientes.por_cobrar > 0 },
    props.pendientes.por_pagar != null
        ? { label: 'Por pagar', valor: soles(props.pendientes.por_pagar), url: '/cuentas-por-pagar', icon: Banknote, alerta: props.pendientes.por_pagar > 0 }
        : null,
    { label: 'Stock bajo', valor: `${props.pendientes.stock_bajo} prod.`, url: '/stock?bajos=1', icon: AlertTriangle, alerta: props.pendientes.stock_bajo > 0 },
    { label: 'Por vencer', valor: `${props.pendientes.lotes_por_vencer} lotes`, url: '/stock?vencen=1', icon: CalendarClock, alerta: props.pendientes.lotes_por_vencer > 0 },
].filter(Boolean))

const esHoy = (iso) => iso === new Date().toISOString().slice(0, 10)
</script>

<template>
    <AppLayout titulo="Inicio">
        <div class="-m-4 min-h-[calc(100vh-4rem)] bg-[#F8FAFC] p-4 text-[#0F172A] sm:-m-6 sm:p-6 dark:bg-transparent dark:text-inherit">
        <!-- Tarjetas de resumen -->
        <div class="grid gap-4 sm:grid-cols-2" :class="mes ? 'xl:grid-cols-4' : 'xl:grid-cols-3'">
            <!-- Ventas de hoy -->
            <div class="rounded-2xl bg-[#4F46E5] p-5 text-white">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-indigo-50">Ventas de hoy</p>
                    <div class="grid size-9 place-items-center rounded-full bg-white/15">
                        <TrendingUp class="size-5" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <p class="text-3xl font-bold tracking-tight">{{ soles(hoy.total) }}</p>
                    <span
                        v-if="hoy.variacion !== null"
                        class="inline-flex items-center gap-0.5 rounded-full bg-white/15 px-1.5 py-0.5 text-xs font-semibold"
                        title="Comparado con ayer"
                    >
                        <ArrowUpRight v-if="hoy.variacion >= 0" class="size-3" />
                        <ArrowDownRight v-else class="size-3" />
                        {{ Math.abs(hoy.variacion) }}%
                    </span>
                </div>
                <p v-if="hoy.margen != null" class="mt-1 text-sm text-indigo-100">
                    Margen: {{ soles(hoy.margen) }}<template v-if="hoy.margen_porcentaje != null"> · {{ hoy.margen_porcentaje }}%</template>
                </p>
            </div>

            <!-- Este mes -->
            <div v-if="mes" class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-[#64748B] dark:text-neutral-400">Este mes</p>
                    <div class="grid size-9 place-items-center rounded-full bg-[#F8FAFC] text-[#64748B] dark:bg-neutral-800 dark:text-neutral-300">
                        <CalendarRange class="size-5" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <p class="text-3xl font-bold tracking-tight">{{ soles(mes.total) }}</p>
                    <span
                        v-if="mes.variacion !== null"
                        class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="mes.variacion >= 0
                            ? 'bg-[#10B981]/15 text-[#047857] dark:bg-emerald-500/15 dark:text-emerald-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'"
                        title="Comparado con el mismo tramo del mes pasado"
                    >
                        <ArrowUpRight v-if="mes.variacion >= 0" class="size-3" />
                        <ArrowDownRight v-else class="size-3" />
                        {{ Math.abs(mes.variacion) }}%
                    </span>
                </div>
                <p class="mt-1 text-sm text-[#64748B] dark:text-neutral-400">
                    Margen: <span class="font-semibold text-[#047857] dark:text-emerald-400">{{ soles(mes.margen) }}</span><template v-if="mes.margen_porcentaje != null"> · {{ mes.margen_porcentaje }}%</template>
                </p>
                <p v-if="mes.variacion !== null" class="mt-0.5 text-xs text-[#94A3B8] dark:text-neutral-500">vs. mismo tramo del mes pasado</p>
            </div>

            <!-- Tickets -->
            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-[#64748B] dark:text-neutral-400">Tickets de hoy</p>
                    <div class="grid size-9 place-items-center rounded-full bg-[#F8FAFC] text-[#64748B] dark:bg-neutral-800 dark:text-neutral-300">
                        <ReceiptText class="size-5" />
                    </div>
                </div>
                <p class="mt-3 text-3xl font-bold tracking-tight">{{ hoy.tickets }}</p>
                <p class="mt-1 text-sm text-[#64748B] dark:text-neutral-400">Ticket promedio {{ soles(hoy.promedio) }}</p>
            </div>

            <!-- Pendientes -->
            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <p class="px-1 text-sm font-medium text-[#64748B] dark:text-neutral-400">Pendientes</p>
                <div class="mt-2 space-y-0.5">
                    <Link
                        v-for="p in PENDIENTES"
                        :key="p.label"
                        :href="p.url"
                        class="flex items-center justify-between rounded-lg px-1.5 py-1 text-sm transition-colors hover:bg-[#F8FAFC] dark:hover:bg-neutral-800"
                    >
                        <span class="flex items-center gap-2 text-[#64748B] dark:text-neutral-300">
                            <component :is="p.icon" class="size-3.5" :class="p.alerta ? 'text-amber-500' : 'text-neutral-300 dark:text-neutral-600'" />
                            {{ p.label }}
                        </span>
                        <span class="font-semibold" :class="p.alerta ? '' : 'text-[#64748B] dark:text-neutral-500'">{{ p.valor }}</span>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Gráficas -->
        <div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
            <!-- Ventas 14 días -->
            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-5 xl:col-span-2 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 class="font-semibold tracking-tight">Ventas de los últimos 14 días</h2>
                <div class="mt-4 h-64">
                    <Bar :data="datosSerie" :options="opcionesSerie" />
                </div>
            </div>

            <!-- Medios de pago -->
            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 class="font-semibold tracking-tight">Ventas por medio de pago</h2>
                <p class="text-xs text-[#64748B] dark:text-neutral-500">Últimos 7 días</p>
                <div v-if="mediosPago.length" class="mt-4 h-64">
                    <Doughnut :data="datosMedios" :options="opcionesMedios" />
                </div>
                <div v-else class="mt-4 grid h-64 place-items-center text-sm text-[#64748B] dark:text-neutral-500">
                    <div class="text-center">
                        <Wallet class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                        Sin ventas cobradas esta semana.
                    </div>
                </div>
            </div>
        </div>

        <!-- Tendencia anual y estacionalidad semanal -->
        <div class="mt-4 grid items-start gap-4 xl:grid-cols-2">
            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 class="font-semibold tracking-tight">Ventas por mes</h2>
                <p class="text-xs text-[#64748B] dark:text-neutral-500">Últimos 12 meses</p>
                <div class="mt-4 h-64">
                    <Bar :data="datosMeses" :options="opcionesMeses" />
                </div>
            </div>

            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 class="font-semibold tracking-tight">Venta promedio por día de la semana</h2>
                <p class="text-xs text-[#64748B] dark:text-neutral-500">Últimos 90 días · promedio por día con ventas</p>
                <div class="mt-4 h-64">
                    <Bar :data="datosDias" :options="opcionesDias" />
                </div>
            </div>
        </div>

        <!-- Sucursales / top productos / últimas ventas -->
        <div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
            <!-- Ventas por sucursal -->
            <div
                v-if="ventasSucursales.length"
                class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"
            >
                <h2 class="font-semibold tracking-tight">Ventas por sucursal</h2>
                <p class="text-xs text-[#64748B] dark:text-neutral-500">Este mes · todas tus sedes</p>
                <div class="mt-4 space-y-4">
                    <div v-for="s in ventasSucursales" :key="s.nombre">
                        <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                            <span class="flex min-w-0 items-center gap-1.5 font-medium">
                                <Store class="size-3.5 shrink-0 text-[#64748B]" />
                                <span class="truncate">{{ s.nombre }}</span>
                            </span>
                            <span class="shrink-0 text-[#64748B] dark:text-neutral-400">
                                hoy {{ soles(s.hoy) }} ·
                                <span class="font-semibold text-[#0F172A] dark:text-neutral-100">{{ soles(s.mes) }}</span>
                            </span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-[#F8FAFC] dark:bg-neutral-800">
                            <div
                                class="h-full rounded-full bg-[#4F46E5] transition-all duration-500 dark:bg-sky-600"
                                :style="{ width: `${(s.mes / maxSucursal) * 100}%` }"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top productos -->
            <div
                class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"
                :class="ventasSucursales.length ? '' : 'xl:col-span-2'"
            >
                <h2 class="font-semibold tracking-tight">Productos más vendidos</h2>
                <p class="text-xs text-[#64748B] dark:text-neutral-500">Últimos 30 días</p>

                <div v-if="topProductos.length" class="mt-4 space-y-3">
                    <div v-for="(p, i) in topProductos" :key="p.nombre">
                        <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                            <span class="truncate font-medium">{{ i + 1 }}. {{ p.nombre }}</span>
                            <span class="shrink-0 text-[#64748B] dark:text-neutral-400">
                                {{ Number(p.cantidad).toLocaleString('es-PE', { maximumFractionDigits: 3 }) }} vend. ·
                                <span class="font-semibold text-[#0F172A] dark:text-neutral-100">{{ soles(p.total) }}</span>
                            </span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-[#F8FAFC] dark:bg-neutral-800">
                            <div
                                class="h-full rounded-full bg-[#10B981] transition-all duration-500 dark:bg-emerald-600"
                                :style="{ width: `${(p.total / maxTop) * 100}%` }"
                            />
                        </div>
                    </div>
                </div>
                <p v-else class="mt-6 pb-2 text-center text-sm text-[#64748B] dark:text-neutral-500">
                    Aún no hay ventas registradas en los últimos 30 días.
                </p>
            </div>

            <!-- Últimas ventas -->
            <div class="rounded-2xl border border-[#E2E8F0] bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-semibold tracking-tight">Últimas ventas</h2>
                    <Link href="/comprobantes" class="text-xs font-medium text-[#4F46E5] hover:underline dark:text-emerald-400">
                        Ver todas
                    </Link>
                </div>
                <div v-if="ultimasVentas.length" class="mt-3 divide-y divide-stone-100 dark:divide-neutral-800">
                    <div v-for="v in ultimasVentas" :key="v.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ v.cliente }}
                                <span v-if="v.es_credito" class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Crédito</span>
                            </p>
                            <p class="text-xs text-[#64748B] dark:text-neutral-400">
                                <span class="font-mono">{{ v.numero }}</span>
                                · {{ esHoy(v.fecha) ? v.hora : etiquetaDia(v.fecha) }}<template v-if="v.sucursal && ventasSucursales.length"> · {{ v.sucursal }}</template>
                            </p>
                        </div>
                        <p class="shrink-0 font-semibold" :class="v.estado === 'anulado' ? 'text-[#64748B] line-through dark:text-neutral-500' : ''">
                            {{ soles(v.total) }}
                        </p>
                    </div>
                </div>
                <p v-else class="mt-6 pb-2 text-center text-sm text-[#64748B] dark:text-neutral-500">
                    Todavía no hay ventas. ¡Abre caja y registra la primera!
                </p>
            </div>
        </div>
        </div>
    </AppLayout>
</template>
