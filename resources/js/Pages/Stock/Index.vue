<script setup>
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    Boxes,
    History,
    Package,
    Search,
    SlidersHorizontal,
    Wallet,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    productos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    resumen: { type: Object, required: true },
    sucursal: { type: String, default: null },
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const TIPOS = {
    compra: { label: 'Compra', entrada: true },
    devolucion: { label: 'Devolución (anulación)', entrada: true },
    ajuste: { label: 'Ajuste (entrada)', entrada: true },
    transferencia_entrada: { label: 'Transferencia entrada', entrada: true },
    venta: { label: 'Venta', entrada: false },
    merma: { label: 'Merma / salida', entrada: false },
    transferencia_salida: { label: 'Transferencia salida', entrada: false },
}

function estadoDe(p) {
    const stock = Number(p.stock ?? 0)
    if (stock <= 0) return { label: 'Agotado', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
    if (Number(p.stock_minimo) > 0 && stock <= Number(p.stock_minimo))
        return { label: 'Bajo', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { label: 'OK', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
}

function costoPromedio(p) {
    const stock = Number(p.stock ?? 0)
    return stock > 0 ? Number(p.valor_inventario) / stock : 0
}

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')
const soloBajos = ref(!!props.filtros.bajos)
const soloPorVencer = ref(!!props.filtros.vencen)

function aplicarFiltros() {
    router.get('/stock', {
        buscar: buscar.value || undefined,
        bajos: soloBajos.value ? 1 : undefined,
        vencen: soloPorVencer.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch([soloBajos, soloPorVencer], aplicarFiltros)

// ---- kardex ----
const kardexProducto = ref(null)
const kardexDatos = ref({ lotes: [], movimientos: [] })
const cargandoKardex = ref(false)

async function verKardex(producto) {
    kardexProducto.value = producto
    kardexDatos.value = { lotes: [], movimientos: [] }
    cargandoKardex.value = true
    try {
        const r = await fetch(`/stock/${producto.id}/kardex`, { headers: { Accept: 'application/json' } })
        if (r.ok) kardexDatos.value = await r.json()
    } finally {
        cargandoKardex.value = false
    }
}

function estadoLote(lote) {
    if (!lote.fecha_vencimiento) return { texto: 'Sin vencimiento', clase: 'bg-stone-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' }
    const hoy = new Date(new Date().toDateString())
    const vence = new Date(`${lote.fecha_vencimiento}T00:00:00`)
    const dias = Math.round((vence - hoy) / 86400000)
    if (dias < 0) return { texto: 'Vencido', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
    if (dias <= 30) return { texto: `Vence en ${dias} día${dias === 1 ? '' : 's'}`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: `Vence ${vence.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })}`, clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
}

function fechaHora(iso) {
    const f = new Date(iso)
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' +
        f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

// ---- ajuste ----
const ajusteProducto = ref(null)
const formAjuste = useForm({
    direccion: 'entrada',
    cantidad: '',
    costo_unitario: '',
    numero_lote: '',
    fecha_vencimiento: '',
})

function abrirAjuste(producto) {
    ajusteProducto.value = producto
    formAjuste.clearErrors()
    formAjuste.reset()
}

function guardarAjuste() {
    formAjuste.transform((data) => ({
        ...data,
        costo_unitario: data.direccion === 'entrada' ? data.costo_unitario : null,
        numero_lote: data.direccion === 'entrada' ? (data.numero_lote.trim() || null) : null,
        fecha_vencimiento: data.direccion === 'entrada' ? (data.fecha_vencimiento || null) : null,
    })).post(`/stock/${ajusteProducto.value.id}/ajustar`, {
        preserveScroll: true,
        onSuccess: () => (ajusteProducto.value = null),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Stock">
        <!-- Resumen -->
        <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Productos con inventario</p>
                    <Boxes class="size-4 text-neutral-400" />
                </div>
                <p class="mt-2 text-xl font-bold tracking-tight">{{ resumen.productos }}</p>
                <p v-if="sucursal" class="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">Sucursal {{ sucursal }}</p>
            </div>
            <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Valor del inventario (costo)</p>
                    <Wallet class="size-4 text-neutral-400" />
                </div>
                <p class="mt-2 text-xl font-bold tracking-tight">{{ soles(resumen.valor_total) }}</p>
                <p class="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">Según capas FIFO vigentes</p>
            </div>
            <button
                class="rounded-2xl border p-4 text-left transition-colors"
                :class="soloBajos
                    ? 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/40'
                    : 'border-stone-200 bg-white hover:border-amber-300 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-amber-700'"
                @click="soloBajos = !soloBajos"
            >
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Stock bajo o agotado</p>
                    <AlertTriangle class="size-4 text-amber-500" />
                </div>
                <p class="mt-2 text-xl font-bold tracking-tight text-amber-600 dark:text-amber-400">{{ resumen.bajos }}</p>
                <p class="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">
                    {{ soloBajos ? 'Mostrando solo estos · clic para ver todos' : 'Clic para filtrar' }}
                </p>
            </button>
            <button
                class="rounded-2xl border p-4 text-left transition-colors"
                :class="soloPorVencer
                    ? 'border-red-400 bg-red-50 dark:border-red-600 dark:bg-red-950/40'
                    : 'border-stone-200 bg-white hover:border-red-300 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-red-700'"
                @click="soloPorVencer = !soloPorVencer"
            >
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Por vencer (30 días)</p>
                    <History class="size-4 text-red-500" />
                </div>
                <p class="mt-2 text-xl font-bold tracking-tight text-red-600 dark:text-red-400">{{ resumen.por_vencer }}</p>
                <p class="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">
                    {{ soloPorVencer ? 'Mostrando solo estos · clic para ver todos' : 'Productos con lotes próximos a vencer' }}
                </p>
            </button>
        </div>

        <!-- Buscador -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por nombre o código..."
                    class="h-10 w-full rounded-xl border border-stone-200 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500"
                />
            </div>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Producto</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Categoría</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Stock</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Mínimo</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Costo prom.</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Valorizado</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!productos.data.length">
                            <td colspan="8" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <Boxes class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                {{ soloBajos ? 'Nada con stock bajo. ¡Buen inventario!' : 'No hay productos que controlen stock.' }}
                            </td>
                        </tr>
                        <tr v-for="p in productos.data" :key="p.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="grid size-9 shrink-0 place-items-center rounded-xl bg-stone-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                                        <Package class="size-5" />
                                    </div>
                                    <div>
                                        <p class="font-medium">{{ p.nombre }}</p>
                                        <p class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ p.codigo_interno }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ p.categoria?.nombre ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold">
                                {{ cantidad(p.stock) }}
                                <span class="text-xs font-normal text-neutral-400">{{ p.unidad_base?.nombre ?? '' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-neutral-600 dark:text-neutral-300">{{ cantidad(p.stock_minimo) }}</td>
                            <td class="px-4 py-3 text-right text-neutral-600 dark:text-neutral-300">{{ soles(costoPromedio(p)) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ soles(p.valor_inventario) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoDe(p).clase">
                                    {{ estadoDe(p).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <button
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Ver kardex"
                                        @click="verKardex(p)"
                                    >
                                        <History class="size-4" />
                                    </button>
                                    <button
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-emerald-600 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                        title="Ajustar stock"
                                        @click="abrirAjuste(p)"
                                    >
                                        <SlidersHorizontal class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="productos.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ productos.from }}–{{ productos.to }} de {{ productos.total }} productos</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in productos.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            preserve-state
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

        <!-- Modal kardex -->
        <Teleport to="body">
            <div v-if="kardexProducto" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="kardexProducto = null" />
                <div class="relative w-full max-w-lg rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div>
                            <h3 class="font-semibold tracking-tight">Kardex · {{ kardexProducto.nombre }}</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Últimos 30 movimientos</p>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="kardexProducto = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>
                    <div class="max-h-[60vh] overflow-y-auto">
                        <p v-if="cargandoKardex" class="px-6 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">Cargando...</p>
                        <template v-else>

                        <!-- Lotes activos -->
                        <div v-if="kardexDatos.lotes.length" class="border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                            <p class="mb-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Lotes activos</p>
                            <ul class="space-y-1.5">
                                <li v-for="l in kardexDatos.lotes" :key="l.id" class="flex items-center justify-between gap-3 text-sm">
                                    <span class="font-mono text-xs font-medium">{{ l.numero_lote }}</span>
                                    <span class="ml-auto text-neutral-500 dark:text-neutral-400">{{ cantidad(l.restante) }} unid.</span>
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap" :class="estadoLote(l).clase">
                                        {{ estadoLote(l).texto }}
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <p v-if="!kardexDatos.movimientos.length" class="px-6 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            Sin movimientos registrados.
                        </p>
                        <ul v-else class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <li v-for="m in kardexDatos.movimientos" :key="m.id" class="flex items-center justify-between gap-3 px-6 py-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="grid size-9 shrink-0 place-items-center rounded-xl"
                                        :class="TIPOS[m.tipo]?.entrada
                                            ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
                                            : 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400'"
                                    >
                                        <ArrowUpCircle v-if="TIPOS[m.tipo]?.entrada" class="size-5" />
                                        <ArrowDownCircle v-else class="size-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ TIPOS[m.tipo]?.label ?? m.tipo }}</p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ fechaHora(m.creado_en) }}<template v-if="m.usuario"> · {{ m.usuario.nombre_completo }}</template>
                                        </p>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p
                                        class="text-sm font-semibold"
                                        :class="TIPOS[m.tipo]?.entrada ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'"
                                    >
                                        {{ TIPOS[m.tipo]?.entrada ? '+' : '−' }}{{ cantidad(m.cantidad) }}
                                    </p>
                                    <p v-if="m.costo_unitario" class="text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ soles(m.costo_unitario) }} c/u
                                    </p>
                                </div>
                            </li>
                        </ul>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal ajuste -->
        <Teleport to="body">
            <div v-if="ajusteProducto" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="ajusteProducto = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarAjuste"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold tracking-tight">
                                Ajustar stock<template v-if="sucursal"> · {{ sucursal }}</template>
                            </h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ ajusteProducto.nombre }} · actual: {{ cantidad(ajusteProducto.stock) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="ajusteProducto = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border text-sm font-medium transition-colors"
                                :class="formAjuste.direccion === 'entrada'
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                @click="formAjuste.direccion = 'entrada'"
                            >
                                <ArrowUpCircle class="size-4" />
                                Entrada
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border text-sm font-medium transition-colors"
                                :class="formAjuste.direccion === 'salida'
                                    ? 'border-red-600 bg-red-600 text-white'
                                    : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                @click="formAjuste.direccion = 'salida'"
                            >
                                <ArrowDownCircle class="size-4" />
                                Salida / merma
                            </button>
                        </div>

                        <div>
                            <label :class="claseLabel" for="aj_cantidad">Cantidad (unidades base) *</label>
                            <input
                                id="aj_cantidad"
                                v-model="formAjuste.cantidad"
                                type="number"
                                :step="ajusteProducto.permite_fraccion ? '0.001' : '1'"
                                min="0"
                                :class="claseInput"
                                placeholder="0"
                            />
                            <p v-if="formAjuste.errors.cantidad" :class="claseError">{{ formAjuste.errors.cantidad }}</p>
                        </div>

                        <div v-if="formAjuste.direccion === 'entrada'">
                            <label :class="claseLabel" for="aj_costo">Costo unitario (S/) *</label>
                            <input
                                id="aj_costo"
                                v-model="formAjuste.costo_unitario"
                                type="number"
                                step="0.000001"
                                min="0"
                                :class="claseInput"
                                placeholder="0.00"
                            />
                            <p v-if="formAjuste.errors.costo_unitario" :class="claseError">{{ formAjuste.errors.costo_unitario }}</p>
                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                Crea una capa de costo para mantener el FIFO correcto.
                            </p>
                        </div>

                        <div v-if="formAjuste.direccion === 'entrada' && ajusteProducto.controla_lote" class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="claseLabel" for="aj_lote">N° de lote *</label>
                                <input id="aj_lote" v-model="formAjuste.numero_lote" type="text" maxlength="50" :class="claseInput" placeholder="L-2026-001" />
                            </div>
                            <div>
                                <label :class="claseLabel" for="aj_vence">Vence</label>
                                <input id="aj_vence" v-model="formAjuste.fecha_vencimiento" type="date" :class="claseInput" />
                            </div>
                        </div>
                        <p v-else class="text-xs text-neutral-400 dark:text-neutral-500">
                            La salida consume las capas de costo más antiguas (FIFO), igual que una venta.
                        </p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="ajusteProducto = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formAjuste.processing"
                            class="rounded-xl px-5 py-2 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                            :class="formAjuste.direccion === 'entrada' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'"
                        >
                            {{ formAjuste.processing ? 'Guardando...' : 'Registrar ajuste' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
