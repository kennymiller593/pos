<script setup>
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ScrollText } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    registros: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    acciones: { type: Array, required: true },
    usuarios: { type: Array, required: true },
})

const COLORES_ACCION = {
    'comprobante.anulado': 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
    'venta.precio_modificado': 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    'producto.precio_actualizado': 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    'usuario.creado': 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    'usuario.actualizado': 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
    'caja.cierre_con_diferencia': 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
}

const fechaHora = (v) => {
    const f = new Date(v)
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' +
        f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

// ---- filtros ----
const accion = ref(props.filtros.accion ?? '')
const usuarioId = ref(props.filtros.usuario_id ?? '')
const desde = ref(props.filtros.desde ?? '')
const hasta = ref(props.filtros.hasta ?? '')

watch([accion, usuarioId, desde, hasta], () => {
    router.get('/auditoria', {
        accion: accion.value || undefined,
        usuario_id: usuarioId.value || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
})

const claseInput =
    'h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'
</script>

<template>
    <AppLayout titulo="Auditoría">
        <!-- Filtros -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <select v-model="accion" :class="claseInput">
                <option value="">Todas las acciones</option>
                <option v-for="a in acciones" :key="a.valor" :value="a.valor">{{ a.label }}</option>
            </select>
            <select v-model="usuarioId" :class="claseInput">
                <option value="">Todos los usuarios</option>
                <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.nombre_completo }}</option>
            </select>
            <label class="text-sm text-neutral-500 dark:text-neutral-400">Del</label>
            <input v-model="desde" type="date" :class="claseInput" />
            <label class="text-sm text-neutral-500 dark:text-neutral-400">al</label>
            <input v-model="hasta" type="date" :class="claseInput" />
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider whitespace-nowrap">Fecha</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Usuario</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Acción</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Detalle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!registros.data.length">
                            <td colspan="4" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <ScrollText class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    Sin eventos registrados. Aquí quedará constancia de anulaciones,
                                    cambios de precio, cierres con diferencia y cambios de usuarios.
                                </div>
                            </td>
                        </tr>
                        <tr v-for="r in registros.data" :key="r.id" class="align-top transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-500 dark:text-neutral-400">{{ fechaHora(r.fecha) }}</td>
                            <td class="px-4 py-3 font-medium whitespace-nowrap">{{ r.usuario }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="COLORES_ACCION[r.accion] ?? 'bg-stone-100 text-neutral-600'">
                                    {{ r.accion_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ r.descripcion }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="registros.data.length && registros.last_page > 1"
                class="flex flex-wrap justify-end gap-1.5 border-t border-stone-200 px-4 py-3 dark:border-neutral-800"
            >
                <template v-for="(link, i) in registros.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        preserve-state
                        class="rounded-lg border px-3.5 py-1.5 text-sm"
                        :class="link.active
                            ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                            : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
