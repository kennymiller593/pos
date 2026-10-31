<script setup>
// Ventana con los productos de una venta (lo que se compró). La usan Comprobantes y Cuentas por cobrar.
// El slot por defecto es el pie (pagos, SUNAT, acciones...).
import { X } from '@lucide/vue'

const props = defineProps({
    titulo: { type: String, required: true },
    // [{ id, descripcion, unidad_codigo, cantidad, precio_unitario, total }]
    detalles: { type: Array, default: () => [] },
    total: { type: [Number, String], default: 0 },
    // código de unidad -> nombre (NIU -> Unidad)
    unidades: { type: Object, default: () => ({}) },
    cargando: { type: Boolean, default: false },
})
const emit = defineEmits(['cerrar'])

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const nombreUnidad = (codigo) => props.unidades[(codigo ?? '').trim()] ?? (codigo ?? '').trim()
const cantidadTexto = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-3 sm:p-4" data-detalle-productos>
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <div class="relative w-full max-w-3xl min-w-0 rounded-2xl border border-[#E2E8F0] bg-white text-[#0F172A] shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between gap-3 border-b border-[#E2E8F0] px-5 py-4 dark:border-neutral-800">
                    <h3 class="font-semibold tracking-tight">{{ titulo }}</h3>
                    <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-slate-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="emit('cerrar')">
                        <X class="size-5" />
                    </button>
                </div>

                <p v-if="cargando" class="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">Cargando...</p>
                <template v-else>
                    <!-- celular: una fila por producto -->
                    <div class="scroll-fino max-h-[70vh] divide-y divide-[#F1F5F9] overflow-y-auto sm:hidden dark:divide-neutral-800">
                        <div v-for="(d, i) in detalles" :key="d.id" class="flex gap-3 px-5 py-3 text-sm">
                            <span class="w-5 shrink-0 text-[#94A3B8] tabular-nums">{{ i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ d.descripcion }}</p>
                                <p class="text-xs text-[#64748B] dark:text-neutral-400">
                                    {{ cantidadTexto(d.cantidad) }} {{ nombreUnidad(d.unidad_codigo) }} × {{ soles(d.precio_unitario) }}
                                </p>
                            </div>
                            <span class="shrink-0 font-semibold tabular-nums">{{ soles(d.total) }}</span>
                        </div>
                        <div class="flex justify-between px-5 py-3">
                            <span class="font-semibold">Total</span>
                            <span class="text-base font-bold tabular-nums">{{ soles(total) }}</span>
                        </div>
                    </div>
                    <div class="hidden max-h-[70vh] overflow-auto sm:block">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 border-b border-[#E2E8F0] bg-slate-50 text-[11px] text-[#64748B] uppercase dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-400">
                                <tr>
                                    <th class="w-12 py-2.5 pl-5 font-semibold tracking-wider">Ítem</th>
                                    <th class="px-3 py-2.5 font-semibold tracking-wider">Producto</th>
                                    <th class="px-3 py-2.5 font-semibold tracking-wider">Unidad</th>
                                    <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Cantidad</th>
                                    <th class="px-3 py-2.5 text-right font-semibold tracking-wider">P. unitario</th>
                                    <th class="py-2.5 pr-5 text-right font-semibold tracking-wider">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#F1F5F9] dark:divide-neutral-800">
                                <tr v-for="(d, i) in detalles" :key="d.id">
                                    <td class="py-3 pl-5 text-[#94A3B8] tabular-nums">{{ i + 1 }}</td>
                                    <td class="px-3 py-3 font-medium">{{ d.descripcion }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap text-[#475569] dark:text-neutral-300">{{ nombreUnidad(d.unidad_codigo) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ cantidadTexto(d.cantidad) }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums">{{ soles(d.precio_unitario) }}</td>
                                    <td class="py-3 pr-5 text-right font-semibold whitespace-nowrap tabular-nums">{{ soles(d.total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-[#E2E8F0] dark:border-neutral-800">
                                <tr>
                                    <td colspan="5" class="py-3 pl-5 text-right font-semibold">Total</td>
                                    <td class="py-3 pr-5 text-right text-base font-bold whitespace-nowrap tabular-nums">{{ soles(total) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <slot />
                </template>
            </div>
        </div>
    </Teleport>
</template>
