<script setup>
// Últimas compras y ventas de un producto, en una ventana con dos pestañas (la usan Productos y el POS).
import { onMounted, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ShoppingCart, Truck, X } from '@lucide/vue'

const props = defineProps({
    // { id, nombre }
    producto: { type: Object, required: true },
    // pestaña con la que se abre
    inicial: { type: String, default: 'compras' },
})
const emit = defineEmits(['cerrar'])

const pestana = ref(props.inicial)
const cargando = ref(true)
const datos = ref({ puede: { compras: true, ventas: true }, compras: [], ventas: [] })

onMounted(async () => {
    try {
        const r = await fetch(`/productos/${props.producto.id}/historial`, { headers: { Accept: 'application/json' } })
        if (r.ok) datos.value = await r.json()
        // si no tiene permiso para la pestaña pedida, se abre la otra
        if (!datos.value.puede[pestana.value]) pestana.value = pestana.value === 'compras' ? 'ventas' : 'compras'
    } finally {
        cargando.value = false
    }
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
function fechaCorta(valor) {
    const iso = String(valor ?? '').slice(0, 10)
    const [a, m, d] = iso.split('-').map(Number)
    return a ? new Date(a, m - 1, d).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

const PESTANAS = [
    { id: 'compras', titulo: 'Compras', icono: Truck },
    { id: 'ventas', titulo: 'Ventas', icono: ShoppingCart },
]
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4" data-historial-producto>
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <div class="relative w-full min-w-0 max-w-3xl rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4 sm:px-6 dark:border-neutral-800">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold tracking-tight">{{ producto.nombre }}</h3>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Últimos movimientos del producto</p>
                    </div>
                    <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="emit('cerrar')">
                        <X class="size-5" />
                    </button>
                </div>

                <!-- pestañas -->
                <div class="flex gap-1 border-b border-stone-200 px-5 pt-3 sm:px-6 dark:border-neutral-800" role="tablist">
                    <button
                        v-for="t in PESTANAS"
                        :key="t.id"
                        v-show="datos.puede[t.id]"
                        type="button"
                        role="tab"
                        :aria-selected="pestana === t.id"
                        class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                        :class="pestana === t.id
                            ? 'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-400'
                            : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100'"
                        @click="pestana = t.id"
                    >
                        <component :is="t.icono" class="size-4" />
                        {{ t.titulo }}
                        <span v-if="!cargando" class="rounded-full bg-stone-100 px-1.5 text-xs text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ datos[t.id].length }}</span>
                    </button>
                </div>

                <div class="max-h-[65vh] overflow-x-auto overflow-y-auto px-5 py-4 sm:px-6">
                    <p v-if="cargando" class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">Cargando...</p>

                    <!-- compras -->
                    <template v-else-if="pestana === 'compras'">
                        <p v-if="!datos.compras.length" class="py-8 text-center text-sm text-neutral-400 dark:text-neutral-500">Sin compras registradas para este producto.</p>
                        <table v-else class="w-full text-left text-sm" data-tabla-compras>
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pr-3 pb-2 font-semibold tracking-wider">Fecha</th>
                                    <th class="px-3 pb-2 font-semibold tracking-wider">Documento</th>
                                    <th class="px-3 pb-2 font-semibold tracking-wider">Proveedor</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Stock restante</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Cantidad</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Precio compra</th>
                                    <th class="pb-2 pl-3 text-right font-semibold tracking-wider">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="c in datos.compras" :key="c.id" :class="c.anulada ? 'text-neutral-400 line-through dark:text-neutral-500' : ''">
                                    <td class="py-2.5 pr-3 whitespace-nowrap">{{ fechaCorta(c.fecha) }}</td>
                                    <td class="px-3 py-2.5 font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ c.documento ?? '—' }}<span v-if="c.anulada" class="ml-1 font-sans text-red-600 no-underline">anulada</span></td>
                                    <td class="max-w-40 truncate px-3 py-2.5 text-neutral-600 dark:text-neutral-300">{{ c.proveedor ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-right font-medium" :class="c.stock_restante !== null && c.stock_restante <= 0 ? 'text-red-600 dark:text-red-400' : ''">
                                        {{ c.stock_restante !== null ? cantidad(c.stock_restante) : '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right">
                                        {{ cantidad(c.cantidad) }}
                                        <span v-if="c.presentacion && c.presentacion !== 'Unidad'" class="text-xs text-neutral-500 dark:text-neutral-400">{{ c.presentacion }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-neutral-600 dark:text-neutral-300">{{ soles(c.costo_unitario) }}</td>
                                    <td class="py-2.5 pl-3 text-right font-semibold">{{ soles(c.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <!-- ventas -->
                    <template v-else>
                        <p v-if="!datos.ventas.length" class="py-8 text-center text-sm text-neutral-400 dark:text-neutral-500">Sin ventas registradas para este producto.</p>
                        <table v-else class="w-full text-left text-sm" data-tabla-ventas>
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pr-3 pb-2 font-semibold tracking-wider">Fecha</th>
                                    <th class="px-3 pb-2 font-semibold tracking-wider">Documento</th>
                                    <th class="px-3 pb-2 font-semibold tracking-wider">Cliente</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Cantidad</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Precio</th>
                                    <th class="pb-2 pl-3 text-right font-semibold tracking-wider">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="v in datos.ventas" :key="v.id" :class="v.anulada ? 'text-neutral-400 line-through dark:text-neutral-500' : ''">
                                    <td class="py-2.5 pr-3 whitespace-nowrap">{{ fechaCorta(v.fecha) }}</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        <Link :href="`/comprobantes?buscar=${encodeURIComponent(v.documento)}`" class="font-mono text-xs text-emerald-700 hover:underline dark:text-emerald-400">{{ v.documento }}</Link>
                                        <span class="block text-[11px] text-neutral-400">{{ v.tipo }}<span v-if="v.anulada" class="ml-1 text-red-600">anulada</span></span>
                                    </td>
                                    <td class="max-w-48 truncate px-3 py-2.5 text-neutral-600 dark:text-neutral-300">{{ v.cliente }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        {{ cantidad(v.cantidad) }}
                                        <span v-if="v.presentacion && v.presentacion !== 'Unidad'" class="text-xs text-neutral-500 dark:text-neutral-400">{{ v.presentacion }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-neutral-600 dark:text-neutral-300">{{ soles(v.precio_unitario) }}</td>
                                    <td class="py-2.5 pl-3 text-right font-semibold">{{ soles(v.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>
