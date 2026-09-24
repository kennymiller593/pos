<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Download, Eye, Plus, Truck, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    compras: { type: Object, required: true },
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const fecha = (f) => new Date(`${f}T00:00:00`).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const compraVer = ref(null)
</script>

<template>
    <AppLayout titulo="Compras">
        <div class="mb-4 flex justify-end">
            <Link
                href="/compras/crear"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                <Plus class="size-4" />
                Nueva compra
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Fecha</th>
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
                            <td colspan="8" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <Truck class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                Aún no registras compras.
                                <Link href="/compras/crear" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                    Registra la primera
                                </Link>
                            </td>
                        </tr>
                        <tr
                            v-for="c in compras.data"
                            :key="c.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            @click="compraVer = c"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">{{ fecha(c.fecha) }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ c.proveedor?.razon_social ?? 'Sin proveedor' }}</span>
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
                                <tr v-for="d in compraVer.detalles" :key="d.id">
                                    <td class="py-2.5 pr-2">
                                        {{ d.producto?.nombre ?? '—' }}
                                        <span v-if="d.presentacion && d.presentacion.nombre !== 'Unidad'" class="text-xs text-neutral-500 dark:text-neutral-400">
                                            ({{ d.presentacion.nombre }})
                                        </span>
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
                        <div class="flex gap-2">
                            <button
                                class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="compraVer = null"
                            >
                                Cerrar
                            </button>
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
    </AppLayout>
</template>
