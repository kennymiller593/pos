<script setup>
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { Building2, CalendarPlus, CheckCircle2, Search } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    empresas: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    totales: { type: Object, default: () => ({ empresas: 0, activas: 0, registradas_mes: 0 }) },
})

// ---- filtros ----
const buscar = ref(props.filtros?.buscar ?? '')
const estado = ref(props.filtros?.estado ?? '')

function aplicarFiltros() {
    router.get('/admin/empresas', {
        buscar: buscar.value || undefined,
        estado: estado.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch(estado, aplicarFiltros)

// ---- formato ----
// 'YYYY-MM-DD' como fecha local (evita el desfase de un dia por UTC)
function aFecha(iso) {
    if (!iso) return null
    const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number)
    return new Date(a, m - 1, d)
}
function ddmm(iso) {
    const f = aFecha(iso)
    return f ? f.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit' }) : ''
}
function fechaCorta(iso) {
    const f = aFecha(iso)
    return f ? f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}
const numero = (n) => Number(n ?? 0).toLocaleString('es-PE')

const AMBAR = 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
const VERDE = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
const ROJO = 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
const GRIS = 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'

function badgePlan(s) {
    if (!s || s.estado === 'sin_plan') return { texto: 'Sin plan', clase: ROJO }
    if (s.estado === 'vencida') return { texto: s.es_prueba ? 'Prueba vencida' : 'Vencida', clase: ROJO }
    if (s.estado === 'en_gracia') return { texto: `${s.plan ?? 'Plan'} · en gracia`, clase: AMBAR }
    if (s.es_prueba) return { texto: 'Prueba', clase: AMBAR }
    return { texto: s.plan ?? 'Plan', clase: VERDE }
}

function detallePlan(s) {
    if (!s?.fecha_fin) return ''
    const d = s.dias_restantes
    if (d !== null && d !== undefined && d < 0) {
        const n = Math.abs(d)
        return `venció hace ${n} día${n === 1 ? '' : 's'}`
    }
    if (s.estado === 'vencida') return `venció ${ddmm(s.fecha_fin)}`
    return `vence ${ddmm(s.fecha_fin)}`
}

function badgeSunat(e) {
    if (!e.facturacion_electronica) return { texto: '—', clase: '' }
    return e.entorno_sunat === 'produccion'
        ? { texto: 'producción', clase: VERDE }
        : { texto: 'beta', clase: AMBAR }
}

function abrir(e) {
    router.visit(`/admin/empresas/${e.id}`)
}

const tarjetas = [
    { clave: 'empresas', label: 'Empresas registradas', icon: Building2 },
    { clave: 'activas', label: 'Empresas activas', icon: CheckCircle2 },
    { clave: 'registradas_mes', label: 'Registradas este mes', icon: CalendarPlus },
]

const claseInput =
    'h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500'
</script>

<template>
    <AppLayout titulo="Empresas">
        <!-- Totales -->
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div
                v-for="t in tarjetas"
                :key="t.clave"
                class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"
            >
                <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                    <component :is="t.icon" class="size-5" />
                </div>
                <div>
                    <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">{{ t.label }}</p>
                    <p class="text-2xl font-semibold tracking-tight tabular-nums">{{ numero(totales?.[t.clave]) }}</p>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por razón social o RUC..."
                    :class="[claseInput, 'w-full pl-10']"
                />
            </div>
            <select v-model="estado" :class="claseInput">
                <option value="">Todas</option>
                <option value="prueba">En prueba</option>
                <option value="activa">Con plan</option>
                <option value="vencida">Vencidas</option>
                <option value="inactiva">Desactivadas</option>
            </select>
            <p class="text-sm text-neutral-500 sm:ml-auto sm:self-center dark:text-neutral-400">
                {{ numero(empresas.total) }} empresa{{ empresas.total === 1 ? '' : 's' }}
            </p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Empresa</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Plan</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Uso</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">SUNAT</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Alta</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!empresas.data.length">
                            <td colspan="6" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <Building2 class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay empresas que mostrar.
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="e in empresas.data"
                            :key="e.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            @click="abrir(e)"
                        >
                            <td class="px-4 py-3">
                                <Link :href="`/admin/empresas/${e.id}`" class="font-medium hover:underline" @click.stop>
                                    {{ e.razon_social }}
                                </Link>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    <span class="tabular-nums">RUC {{ e.ruc }}</span>
                                    <template v-if="e.nombre_comercial"> · {{ e.nombre_comercial }}</template>
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                    :class="badgePlan(e.suscripcion).clase"
                                >
                                    {{ badgePlan(e.suscripcion).texto }}
                                </span>
                                <p v-if="detallePlan(e.suscripcion)" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ detallePlan(e.suscripcion) }}
                                </p>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-600 tabular-nums dark:text-neutral-300">
                                <span :title="`${e.usuarios} usuarios`">{{ numero(e.usuarios) }} usu.</span>
                                · <span :title="`${e.sucursales} sucursales`">{{ numero(e.sucursales) }} suc.</span>
                                · <span :title="`${e.comprobantes_mes} comprobantes este mes`">{{ numero(e.comprobantes_mes) }} comp./mes</span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="e.facturacion_electronica"
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="badgeSunat(e).clase"
                                >
                                    {{ badgeSunat(e).texto }}
                                </span>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">
                                {{ fechaCorta(e.creado_en) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="e.activo ? VERDE : GRIS"
                                >
                                    {{ e.activo ? 'Activa' : 'Desactivada' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="empresas.data.length && empresas.links?.length > 3"
                class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 dark:border-neutral-800"
            >
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ empresas.from }}–{{ empresas.to }} de {{ empresas.total }}
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in empresas.links" :key="i">
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
        </div>
    </AppLayout>
</template>
