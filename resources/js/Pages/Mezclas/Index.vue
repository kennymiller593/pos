<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Ban, CalendarDays, Download, Eye, FlaskConical, Plus, Search, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermisos } from '@/composables/permisos'
import { useSucursales } from '@/composables/sucursales'

const props = defineProps({
    mezclas: { type: Object, required: true },
    // { buscar, desde, hasta } con lo que ya está aplicado
    filtros: { type: Object, default: () => ({}) },
})

const { puede } = usePermisos()
const { multisucursal } = useSucursales()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
const fechaHora = (f) => (f
    ? new Date(f).toLocaleString('es-PE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—')
// cantidad con su presentación: "5 sacos" / "12"
const cantidadCon = (n, presentacion) => `${cantidad(n)}${presentacion && presentacion !== 'Unidad' ? ` ${presentacion}` : ''}`
const verCostos = computed(() => props.mezclas.data.some((m) => m.costo_total != null))

const mezclaVer = ref(null)

// ---- filtros: producto (obtenido o insumo) o código, y rango de fechas ----
const buscar = ref(props.filtros.buscar ?? '')
const desde = ref(props.filtros.desde ?? '')
const hasta = ref(props.filtros.hasta ?? '')
const hayFiltros = computed(() => !!(buscar.value.trim() || desde.value || hasta.value))

function aplicarFiltros() {
    router.get('/mezclas', {
        buscar: buscar.value.trim() || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}
function limpiarFiltros() {
    buscar.value = ''
    desde.value = ''
    hasta.value = ''
}
watch([desde, hasta], aplicarFiltros)
let pausa = null
watch(buscar, () => {
    clearTimeout(pausa)
    pausa = setTimeout(aplicarFiltros, 350)
})

const claseInput =
    'h-10 rounded-xl border border-[#E2E8F0] bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'

// ---- anulación ----
const mezclaAnular = ref(null)
const formAnular = useForm({ motivo: '' })

function abrirAnulacion(m) {
    mezclaAnular.value = m
    formAnular.clearErrors()
    formAnular.motivo = ''
}

function anular() {
    formAnular.post(`/mezclas/${mezclaAnular.value.id}/anular`, {
        preserveScroll: true,
        onSuccess: () => {
            mezclaAnular.value = null
            mezclaVer.value = null
        },
    })
}
</script>

<template>
    <AppLayout titulo="Mezclas">
        <!-- Filtros y acción principal -->
        <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-1 sm:flex-wrap sm:items-center">
                <div class="relative col-span-2 sm:min-w-56 sm:flex-1" data-filtro-buscar>
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#94A3B8]" />
                    <input
                        v-model="buscar"
                        type="search"
                        maxlength="60"
                        placeholder="Buscar producto, insumo o código"
                        aria-label="Buscar producto, insumo o código"
                        :class="[claseInput, 'w-full pl-9']"
                    />
                </div>
                <div class="col-span-2 flex items-center gap-1.5 rounded-xl border border-[#E2E8F0] bg-white pr-1.5 pl-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <CalendarDays class="size-4 shrink-0 text-[#94A3B8]" />
                    <input v-model="desde" type="date" :max="hasta || undefined" aria-label="Desde" title="Desde" class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                    <span class="text-xs text-[#94A3B8]">a</span>
                    <input v-model="hasta" type="date" :min="desde || undefined" aria-label="Hasta" title="Hasta" class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                </div>
                <button
                    v-if="hayFiltros"
                    type="button"
                    class="col-span-2 inline-flex h-10 items-center justify-center gap-1.5 rounded-xl px-3 text-sm font-medium text-[#64748B] hover:bg-slate-100 sm:col-span-1 dark:text-neutral-400 dark:hover:bg-neutral-800"
                    @click="limpiarFiltros"
                >
                    <X class="size-4" />
                    Limpiar
                </button>
            </div>
            <Link
                v-if="puede('mezclas.gestionar')"
                href="/mezclas/crear"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                <Plus class="size-4" />
                Preparar mezcla
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Código</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Fecha</th>
                            <th v-if="multisucursal" class="px-4 py-3.5 font-semibold tracking-wider">Sucursal</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Producto obtenido</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Insumos</th>
                            <th v-if="verCostos" class="px-4 py-3.5 text-right font-semibold tracking-wider">Costo total</th>
                            <th v-if="verCostos" class="px-4 py-3.5 text-right font-semibold tracking-wider">Costo unit.</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Preparada por</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!mezclas.data.length">
                            <td :colspan="9" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <FlaskConical class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    <template v-if="hayFiltros">
                                        No hay mezclas con esos filtros.
                                        <button type="button" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400" @click="limpiarFiltros">Ver todas</button>
                                    </template>
                                    <template v-else>
                                        Aún no preparas mezclas.
                                        <Link v-if="puede('mezclas.gestionar')" href="/mezclas/crear" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                            Prepara la primera
                                        </Link>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="m in mezclas.data"
                            :key="m.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            :class="m.estado === 'anulada' ? 'opacity-60' : ''"
                            @click="mezclaVer = m"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-semibold whitespace-nowrap">
                                {{ m.codigo }}
                                <span
                                    v-if="m.estado === 'anulada'"
                                    class="ml-1.5 inline-flex rounded-full bg-red-100 px-2 py-0.5 font-sans text-xs font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400"
                                    :title="m.motivo_anulacion ?? ''"
                                >
                                    Anulada
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ fechaHora(m.creado_en) }}</td>
                            <td v-if="multisucursal" class="px-4 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ m.sucursal?.nombre ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ m.producto?.nombre ?? '—' }}</span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ cantidadCon(m.cantidad, m.presentacion) }}<template v-if="m.lote"> · lote {{ m.lote.numero_lote }}</template>
                                </span>
                                <!-- insumo buscado -->
                                <ul v-if="m.insumos.some((i) => i.coincide)" class="mt-1.5 space-y-1" data-coincidencias>
                                    <li
                                        v-for="i in m.insumos.filter((i) => i.coincide)"
                                        :key="i.id"
                                        class="w-fit max-w-80 rounded-lg bg-emerald-50 px-2 py-1 text-xs text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200"
                                    >
                                        Insumo: <span class="font-semibold">{{ i.producto?.nombre ?? '—' }}</span> · {{ cantidadCon(i.cantidad, i.presentacion) }}
                                    </li>
                                </ul>
                            </td>
                            <td class="px-4 py-3 text-center text-neutral-600 dark:text-neutral-300">{{ m.insumos.length }}</td>
                            <td v-if="verCostos" class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ soles(m.costo_total) }}</td>
                            <td v-if="verCostos" class="px-4 py-3 text-right whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ soles(m.costo_unitario) }}</td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ m.usuario?.nombre_completo ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <button
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Ver detalle"
                                        @click.stop="mezclaVer = m"
                                    >
                                        <Eye class="size-4" />
                                    </button>
                                    <a
                                        :href="`/mezclas/${m.id}/pdf`"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-emerald-600 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                        title="Descargar PDF"
                                        @click.stop
                                    >
                                        <Download class="size-4" />
                                    </a>
                                    <button
                                        v-if="m.estado === 'registrada' && puede('mezclas.anular')"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        title="Anular mezcla"
                                        @click.stop="abrirAnulacion(m)"
                                    >
                                        <Ban class="size-3.5" />
                                        Anular
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="mezclas.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ mezclas.from }}–{{ mezclas.to }} de {{ mezclas.total }} mezclas</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in mezclas.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-lg border px-3.5 py-1.5"
                            :class="link.active
                                ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal detalle -->
        <Teleport to="body">
            <div v-if="mezclaVer" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="mezclaVer = null" />
                <div class="relative w-full max-w-lg rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div>
                            <h3 class="font-semibold tracking-tight">Mezcla {{ mezclaVer.codigo }} · {{ fechaHora(mezclaVer.creado_en) }}</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ mezclaVer.sucursal?.nombre }} · {{ mezclaVer.usuario?.nombre_completo ?? '—' }}
                            </p>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="mezclaVer = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div
                        v-if="mezclaVer.estado === 'anulada'"
                        class="mx-6 mt-4 rounded-xl bg-red-100 px-3 py-2 text-xs text-red-800 dark:bg-red-500/15 dark:text-red-300"
                    >
                        Anulada el {{ fechaHora(mezclaVer.anulada_en) }}
                        por {{ mezclaVer.anulada_por?.nombre_completo ?? '—' }}<template v-if="mezclaVer.motivo_anulacion">: {{ mezclaVer.motivo_anulacion }}</template>
                    </div>

                    <div class="max-h-[55vh] overflow-y-auto px-6 py-4">
                        <!-- lo obtenido -->
                        <div class="flex items-center gap-3 rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
                            <div class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-white text-emerald-600 dark:bg-neutral-900 dark:text-emerald-400">
                                <img v-if="mezclaVer.producto?.imagen_url" :src="mezclaVer.producto.imagen_url" :alt="mezclaVer.producto.nombre" class="size-full bg-white object-contain" />
                                <FlaskConical v-else class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-emerald-700 dark:text-emerald-300">Se obtuvo</p>
                                <p class="truncate text-sm font-semibold">{{ mezclaVer.producto?.nombre ?? '—' }}</p>
                                <p class="text-xs text-neutral-600 dark:text-neutral-300">
                                    {{ cantidadCon(mezclaVer.cantidad, mezclaVer.presentacion) }}<template v-if="mezclaVer.cantidad_base !== mezclaVer.cantidad"> ({{ cantidad(mezclaVer.cantidad_base) }} unid.)</template>
                                    <template v-if="mezclaVer.lote"> · lote {{ mezclaVer.lote.numero_lote }}<template v-if="mezclaVer.lote.fecha_vencimiento">, vence {{ mezclaVer.lote.fecha_vencimiento.slice(0, 10).split('-').reverse().join('/') }}</template></template>
                                </p>
                            </div>
                            <div v-if="mezclaVer.costo_unitario != null" class="text-right">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Costo unit.</p>
                                <p class="text-sm font-bold">{{ soles(mezclaVer.costo_unitario) }}</p>
                            </div>
                        </div>

                        <p class="mt-4 mb-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Insumos usados</p>
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pb-2 font-semibold tracking-wider">Insumo</th>
                                    <th class="pb-2 text-center font-semibold tracking-wider">Cant.</th>
                                    <th v-if="mezclaVer.costo_total != null" class="pb-2 text-right font-semibold tracking-wider">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="i in mezclaVer.insumos" :key="i.id">
                                    <td class="py-2.5 pr-2">{{ i.producto?.nombre ?? '—' }}</td>
                                    <td class="py-2.5 text-center whitespace-nowrap">{{ cantidadCon(i.cantidad, i.presentacion) }}</td>
                                    <td v-if="mezclaVer.costo_total != null" class="py-2.5 text-right font-medium">{{ soles(i.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-if="mezclaVer.costo_total != null" class="mt-3 space-y-1 border-t border-stone-200 pt-3 text-sm dark:border-neutral-800">
                            <p class="flex justify-between text-neutral-600 dark:text-neutral-300"><span>Insumos</span><span>{{ soles(mezclaVer.costo_insumos) }}</span></p>
                            <p v-if="mezclaVer.otros_costos > 0" class="flex justify-between text-neutral-600 dark:text-neutral-300"><span>Otros costos</span><span>{{ soles(mezclaVer.otros_costos) }}</span></p>
                            <p class="flex justify-between font-semibold"><span>Costo total</span><span>{{ soles(mezclaVer.costo_total) }}</span></p>
                        </div>
                        <p v-if="mezclaVer.observacion" class="mt-3 text-sm text-neutral-500 dark:text-neutral-400">Observación: {{ mezclaVer.observacion }}</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <button
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="mezclaVer = null"
                        >
                            Cerrar
                        </button>
                        <button
                            v-if="mezclaVer.estado === 'registrada' && puede('mezclas.anular')"
                            class="inline-flex items-center gap-2 rounded-xl border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                            @click="abrirAnulacion(mezclaVer)"
                        >
                            <Ban class="size-4" />
                            Anular
                        </button>
                        <a
                            :href="`/mezclas/${mezclaVer.id}/pdf`"
                            class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                        >
                            <Download class="size-4" />
                            Descargar PDF
                        </a>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal anulación -->
        <Teleport to="body">
            <div v-if="mezclaAnular" class="fixed inset-0 z-[60] grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="mezclaAnular = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="anular"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Anular mezcla {{ mezclaAnular.codigo }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="mezclaAnular = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Los insumos volverán al stock y se retirará lo obtenido.
                        Solo es posible si nada de lo obtenido se vendió o movió.
                    </p>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="motivo-mezcla">Motivo *</label>
                        <input
                            id="motivo-mezcla"
                            v-model="formAnular.motivo"
                            type="text"
                            required
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            placeholder="Ej. cantidades mal registradas"
                            autofocus
                        />
                        <p v-if="formAnular.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formAnular.errors.motivo }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="mezclaAnular = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formAnular.processing || !formAnular.motivo.trim()"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formAnular.processing ? 'Anulando...' : 'Anular mezcla' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
