<script setup>
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { FileSpreadsheet, Package, Pencil, Plus, Search, Trash2 } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'
import FormularioProducto from './FormularioProducto.vue'
import ImportarProductos from './ImportarProductos.vue'

const props = defineProps({
    productos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    catalogos: { type: Object, required: true },
})

const buscar = ref(props.filtros.buscar ?? '')
const categoriaId = ref(props.filtros.categoria_id ?? '')
const estado = ref(props.filtros.estado ?? '')

function aplicarFiltros() {
    router.get(
        '/productos',
        {
            buscar: buscar.value || undefined,
            categoria_id: categoriaId.value || undefined,
            estado: estado.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch([categoriaId, estado], aplicarFiltros)

const modalAbierto = ref(false)
const productoEditar = ref(null)
const importarAbierto = ref(false)

function nuevo() {
    productoEditar.value = null
    modalAbierto.value = true
}

function editar(producto) {
    productoEditar.value = producto
    modalAbierto.value = true
}

const { confirmar } = useConfirmar()
const { puede } = usePermisos()

async function eliminar(producto) {
    const confirmado = await confirmar({
        titulo: 'Eliminar producto',
        mensaje: `"${producto.nombre}" dejará de aparecer en el sistema. Esta acción se puede revertir desde la base de datos.`,
        textoConfirmar: 'Eliminar',
        peligro: true,
    })

    if (confirmado) {
        router.delete(`/productos/${producto.id}`, { preserveScroll: true })
    }
}

function presentacionDefault(producto) {
    return producto.presentaciones?.find((p) => p.es_default) ?? producto.presentaciones?.[0] ?? null
}

function precio(producto) {
    const def = presentacionDefault(producto)
    return def ? `S/ ${Number(def.precio_venta).toFixed(2)}` : '—'
}

function claseStock(producto) {
    if (!producto.controla_stock) return ''
    const stock = Number(producto.stock_total ?? 0)
    if (stock <= 0) return 'text-red-600 dark:text-red-400'
    if (stock <= Number(producto.stock_minimo ?? 0)) return 'text-amber-600 dark:text-amber-400'
    return ''
}
</script>

<template>
    <AppLayout titulo="Productos">
        <!-- Barra de acciones -->
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-1 flex-col gap-3 sm:flex-row">
                <div class="relative w-full sm:max-w-xs">
                    <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                    <input
                        v-model="buscar"
                        type="text"
                        placeholder="Buscar por nombre o código..."
                        class="h-10 w-full rounded-xl border border-stone-200 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500"
                    />
                </div>
                <select
                    v-model="categoriaId"
                    class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <option value="">Todas las categorías</option>
                    <option v-for="c in catalogos.categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                </select>
                <select
                    v-model="estado"
                    class="h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <option value="">Todos</option>
                    <option value="activo">Activos</option>
                    <option value="inactivo">Inactivos</option>
                </select>
            </div>
            <div v-if="puede('productos.gestionar')" class="flex flex-col gap-2 sm:flex-row">
                <button
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 text-sm font-medium transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    @click="importarAbierto = true"
                >
                    <FileSpreadsheet class="size-4" />
                    Importar Excel
                </button>
                <button
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                    @click="nuevo"
                >
                    <Plus class="size-4" />
                    Nuevo producto
                </button>
            </div>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Código</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Producto</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Categoría</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Unidad</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Precio</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Stock</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!productos.data.length">
                            <td colspan="8" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <Package class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay productos que mostrar.
                                    <button v-if="puede('productos.gestionar')" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400" @click="nuevo">
                                        Crea el primero
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="p in productos.data"
                            :key="p.id"
                            class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                        >
                            <td class="px-4 py-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ p.codigo_interno }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl bg-stone-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                                        <img v-if="p.imagen_url" :src="p.imagen_url" alt="" class="size-full bg-white object-contain" />
                                        <Package v-else class="size-5" />
                                    </div>
                                    <div>
                                        <p class="font-medium">{{ p.nombre }}</p>
                                        <p v-if="p.marca" class="text-xs text-neutral-500 dark:text-neutral-400">{{ p.marca.nombre }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ p.categoria?.nombre ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ p.unidad_base?.nombre ?? p.unidad_base_codigo }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ precio(p) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" :class="claseStock(p)">
                                {{ p.controla_stock ? Number(p.stock_total ?? 0) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="p.activo
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                        : 'bg-stone-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'"
                                >
                                    {{ p.activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <button
                                        v-if="puede('productos.gestionar')"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Editar"
                                        @click="editar(p)"
                                    >
                                        <Pencil class="size-4" />
                                    </button>
                                    <button
                                        v-if="puede('productos.eliminar')"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        title="Eliminar"
                                        @click="eliminar(p)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pie / paginación -->
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

        <FormularioProducto
            :abierto="modalAbierto"
            :producto="productoEditar"
            :catalogos="catalogos"
            @cerrar="modalAbierto = false"
        />

        <ImportarProductos
            v-if="puede('productos.gestionar')"
            :abierto="importarAbierto"
            @cerrar="importarAbierto = false"
        />
    </AppLayout>
</template>
