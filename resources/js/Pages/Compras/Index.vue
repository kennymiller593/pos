<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Ban, Barcode, CalendarDays, Download, Eye, Plus, Search, Truck, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import SelectorBuscable from '@/Components/SelectorBuscable.vue'
import { usePermisos } from '@/composables/permisos'
import { useSucursales } from '@/composables/sucursales'

const props = defineProps({
    compras: { type: Object, required: true },
    // { desde, hasta, proveedor } con lo que ya está aplicado
    filtros: { type: Object, default: () => ({}) },
    // [{ id, razon_social }]
    proveedores: { type: Array, default: () => [] },
})

const { puede } = usePermisos()
const { multisucursal } = useSucursales()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const fecha = (f) => (f ? new Date(`${String(f).slice(0, 10)}T00:00:00`) : null)?.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const compraVer = ref(null)

// ---- filtros: producto / lote / N° de comprobante, rango de fechas de la compra y proveedor ----
const buscar = ref(props.filtros.buscar ?? '')
const desde = ref(props.filtros.desde ?? '')
const hasta = ref(props.filtros.hasta ?? '')
const proveedor = ref(props.filtros.proveedor ?? '')
const hayFiltros = computed(() => !!(buscar.value.trim() || desde.value || hasta.value || proveedor.value))
// líneas de la compra que coinciden con lo buscado (las marca el servidor)
const coincidencias = (c) => c.detalles.filter((d) => d.coincide)
const opcionesProveedor = computed(() => [
    { valor: '', texto: 'Todos los proveedores' },
    ...props.proveedores.map((pr) => ({ valor: pr.id, texto: pr.razon_social })),
])

function aplicarFiltros() {
    router.get('/compras', {
        buscar: buscar.value.trim() || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
        proveedor: proveedor.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}
function limpiarFiltros() {
    buscar.value = ''
    desde.value = ''
    hasta.value = ''
    proveedor.value = ''
}
watch([desde, hasta, proveedor], aplicarFiltros)
// al escribir se espera a que termine para no consultar con cada letra
let pausa = null
watch(buscar, () => {
    clearTimeout(pausa)
    pausa = setTimeout(aplicarFiltros, 350)
})

const claseInput =
    'h-10 rounded-xl border border-[#E2E8F0] bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'

const fechaHora = (f) => (f
    ? new Date(f).toLocaleString('es-PE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—')

// ---- anulacion ----
const compraAnular = ref(null)
const formAnular = useForm({ motivo: '' })

function abrirAnulacion(c) {
    compraAnular.value = c
    formAnular.clearErrors()
    formAnular.motivo = ''
}

function anular() {
    formAnular.post(`/compras/${compraAnular.value.id}/anular`, {
        preserveScroll: true,
        onSuccess: () => {
            compraAnular.value = null
            compraVer.value = null
        },
    })
}
</script>

<template>
    <AppLayout titulo="Compras">
        <!-- Filtros y acción principal -->
        <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-1 sm:flex-wrap sm:items-center">
                <!-- producto, lote o N° de comprobante -->
                <div class="relative col-span-2 sm:min-w-56 sm:flex-1" data-filtro-buscar>
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#94A3B8]" />
                    <input
                        v-model="buscar"
                        type="search"
                        maxlength="60"
                        placeholder="Buscar producto, lote o N° de comprobante"
                        aria-label="Buscar producto, lote o N° de comprobante"
                        :class="[claseInput, 'w-full pl-9']"
                    />
                </div>
                <!-- rango de fechas de la compra -->
                <div class="col-span-2 flex items-center gap-1.5 rounded-xl border border-[#E2E8F0] bg-white pr-1.5 pl-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <CalendarDays class="size-4 shrink-0 text-[#94A3B8]" />
                    <input v-model="desde" type="date" :max="hasta || undefined" aria-label="Desde" title="Desde" data-filtro-desde class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                    <span class="text-xs text-[#94A3B8]">a</span>
                    <input v-model="hasta" type="date" :min="desde || undefined" aria-label="Hasta" title="Hasta" data-filtro-hasta class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                </div>
                <div class="col-span-2 sm:col-span-1 sm:w-72" data-filtro-proveedor>
                    <SelectorBuscable v-model="proveedor" :opciones="opcionesProveedor" :clase-input="claseInput + ' w-full'" placeholder="Todos los proveedores" />
                </div>
                <button
                    v-if="hayFiltros"
                    type="button"
                    class="col-span-2 inline-flex h-10 items-center justify-center gap-1.5 rounded-xl px-3 text-sm font-medium text-[#64748B] hover:bg-slate-100 sm:col-span-1 dark:text-neutral-400 dark:hover:bg-neutral-800"
                    data-filtros-limpiar
                    @click="limpiarFiltros"
                >
                    <X class="size-4" />
                    Limpiar
                </button>
            </div>
            <Link
                v-if="puede('compras.gestionar')"
                href="/compras/crear"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                <Plus class="size-4" />
                Nueva compra
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Fecha</th>
                            <th v-if="multisucursal" class="px-4 py-3.5 font-semibold tracking-wider">Sucursal</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Proveedor</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Documento</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Ítems</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Total</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Condición</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Registrada por</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!compras.data.length">
                            <td :colspan="multisucursal ? 9 : 8" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <Truck class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    <template v-if="hayFiltros">
                                        No hay compras con esos filtros.
                                        <button type="button" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400" @click="limpiarFiltros">Ver todas</button>
                                    </template>
                                    <template v-else>
                                        Aún no registras compras.
                                        <Link v-if="puede('compras.gestionar')" href="/compras/crear" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                            Registra la primera
                                        </Link>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="c in compras.data"
                            :key="c.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            :class="c.estado === 'anulada' ? 'opacity-60' : ''"
                            @click="compraVer = c"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">{{ fecha(c.fecha) }}</td>
                            <td v-if="multisucursal" class="px-4 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ c.sucursal?.nombre ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ c.proveedor?.razon_social ?? 'Sin proveedor' }}</span>
                                <span
                                    v-if="c.estado === 'anulada'"
                                    class="ml-2 inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400"
                                    :title="c.motivo_anulacion ?? ''"
                                >
                                    Anulada
                                </span>
                                <!-- lo buscado dentro de esta compra -->
                                <ul v-if="coincidencias(c).length" class="mt-1.5 space-y-1" data-coincidencias>
                                    <li
                                        v-for="d in coincidencias(c)"
                                        :key="d.id"
                                        class="w-fit max-w-80 rounded-lg bg-emerald-50 px-2 py-1 text-xs text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200"
                                    >
                                        <span class="font-semibold">{{ d.producto?.nombre ?? '—' }}</span>
                                        · {{ cantidad(d.cantidad) }}{{ d.presentacion && d.presentacion.nombre !== 'Unidad' ? ` ${d.presentacion.nombre}` : '' }}
                                        × {{ soles(d.costo_unitario) }}{{ d.lote ? ` · lote ${d.lote.numero_lote}` : '' }}
                                    </li>
                                </ul>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ c.serie_numero ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-neutral-600 dark:text-neutral-300">{{ c.detalles.length }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ soles(c.total) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="c.es_credito
                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
                                        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'"
                                >
                                    {{ c.es_credito ? 'Crédito' : 'Contado' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ c.usuario?.nombre_completo ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <button
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Ver detalle"
                                        @click.stop="compraVer = c"
                                    >
                                        <Eye class="size-4" />
                                    </button>
                                    <a
                                        :href="`/compras/${c.id}/pdf`"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-emerald-600 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                        title="Descargar PDF"
                                        @click.stop
                                    >
                                        <Download class="size-4" />
                                    </a>
                                    <button
                                        v-if="c.estado === 'registrada' && puede('compras.anular')"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        title="Anular compra"
                                        @click.stop="abrirAnulacion(c)"
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
                v-if="compras.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ compras.from }}–{{ compras.to }} de {{ compras.total }} compras</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in compras.links" :key="i">
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

        <!-- Modal detalle de compra -->
        <Teleport to="body">
            <div v-if="compraVer" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="compraVer = null" />
                <div class="relative w-full max-w-lg rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <!-- Encabezado -->
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div>
                            <h3 class="font-semibold tracking-tight">
                                Compra · {{ fecha(compraVer.fecha) }}
                            </h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ compraVer.proveedor?.razon_social ?? 'Sin proveedor' }}
                                <template v-if="compraVer.serie_numero"> · {{ compraVer.serie_numero }}</template>
                                · {{ compraVer.es_credito ? 'Crédito' : 'Contado' }}
                            </p>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="compraVer = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div
                        v-if="compraVer.estado === 'anulada'"
                        class="mx-6 mt-4 rounded-xl bg-red-100 px-3 py-2 text-xs text-red-800 dark:bg-red-500/15 dark:text-red-300"
                    >
                        Anulada el {{ fechaHora(compraVer.anulada_en) }}
                        por {{ compraVer.anulada_por?.nombre_completo ?? '—' }}<template v-if="compraVer.motivo_anulacion">: {{ compraVer.motivo_anulacion }}</template>
                    </div>

                    <!-- Items -->
                    <div class="max-h-[50vh] overflow-y-auto px-6 py-4">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pb-2 font-semibold tracking-wider">Producto</th>
                                    <th class="pb-2 text-center font-semibold tracking-wider">Cant.</th>
                                    <th class="pb-2 text-right font-semibold tracking-wider">Costo unit.</th>
                                    <th class="pb-2 text-right font-semibold tracking-wider">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="d in compraVer.detalles" :key="d.id" :class="d.coincide && 'bg-emerald-50 dark:bg-emerald-500/10'">
                                    <td class="py-2.5 pr-2">
                                        {{ d.producto?.nombre ?? '—' }}
                                        <span v-if="d.presentacion && d.presentacion.nombre !== 'Unidad'" class="text-xs text-neutral-500 dark:text-neutral-400">
                                            ({{ d.presentacion.nombre }})
                                        </span>
                                        <span v-if="d.lote" class="block text-xs text-neutral-500 dark:text-neutral-400">Lote {{ d.lote.numero_lote }}</span>
                                    </td>
                                    <td class="py-2.5 text-center">{{ cantidad(d.cantidad) }}</td>
                                    <td class="py-2.5 text-right text-neutral-600 dark:text-neutral-300">{{ soles(d.costo_unitario) }}</td>
                                    <td class="py-2.5 text-right font-medium">{{ soles(d.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pie -->
                    <div class="flex items-center justify-between gap-3 border-t border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Total de la compra</p>
                            <p class="text-xl font-bold tracking-tight">{{ soles(compraVer.total) }}</p>
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button
                                class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="compraVer = null"
                            >
                                Cerrar
                            </button>
                            <Link
                                v-if="compraVer.estado === 'registrada' && puede('productos.ver')"
                                :href="`/productos/etiquetas?compra=${compraVer.id}`"
                                class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                title="Imprimir etiquetas con código de barras de lo que entró"
                            >
                                <Barcode class="size-4" />
                                Etiquetas
                            </Link>
                            <a
                                :href="`/compras/${compraVer.id}/pdf`"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                            >
                                <Download class="size-4" />
                                Descargar PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal anulación -->
        <Teleport to="body">
            <div v-if="compraAnular" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="compraAnular = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="anular"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">
                            Anular compra<template v-if="compraAnular.serie_numero"> {{ compraAnular.serie_numero }}</template>
                        </h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="compraAnular = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Se retirará del stock la mercadería de esta compra y se eliminará la deuda con el proveedor.
                        Solo es posible si nada de esa mercadería se vendió o movió y la deuda no tiene pagos.
                    </p>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="motivo-compra">Motivo *</label>
                        <input
                            id="motivo-compra"
                            v-model="formAnular.motivo"
                            type="text"
                            required
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            placeholder="Ej. compra registrada por error"
                            autofocus
                        />
                        <p v-if="formAnular.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formAnular.errors.motivo }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="compraAnular = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formAnular.processing || !formAnular.motivo.trim()"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formAnular.processing ? 'Anulando...' : 'Anular compra' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
