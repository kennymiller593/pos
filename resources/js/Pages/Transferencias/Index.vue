<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ArrowLeftRight, ArrowRight, Ban, CheckCircle2, ChevronDown, Plus } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'

defineProps({
    transferencias: { type: Object, required: true },
})

const { puede } = usePermisos()

const { confirmar } = useConfirmar()
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const ESTADOS = {
    pendiente: { label: 'Pendiente', clase: 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300' },
    en_transito: { label: 'En tránsito', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    recibida: { label: 'Recibida', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    anulada: { label: 'Anulada', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' },
}

function fechaHora(iso) {
    const f = new Date(iso)
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' +
        f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

const expandido = ref(null)

async function recibir(t) {
    const confirmado = await confirmar({
        titulo: 'Recibir transferencia',
        mensaje: `Se sumará la mercadería al stock de ${t.sucursal_destino?.nombre}.`,
        textoConfirmar: 'Recibir',
    })
    if (confirmado) {
        router.post(`/transferencias/${t.id}/recibir`, {}, { preserveScroll: true })
    }
}

async function anular(t) {
    const confirmado = await confirmar({
        titulo: 'Anular transferencia',
        mensaje: `La mercadería volverá al stock de ${t.sucursal_origen?.nombre}.`,
        textoConfirmar: 'Anular',
        peligro: true,
    })
    if (confirmado) {
        router.post(`/transferencias/${t.id}/anular`, {}, { preserveScroll: true })
    }
}
</script>

<template>
    <AppLayout titulo="Transferencias">
        <div v-if="puede('transferencias.gestionar')" class="mb-4 flex justify-end">
            <Link
                href="/transferencias/crear"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                <Plus class="size-4" />
                Nueva transferencia
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="w-8 px-2 py-3.5" />
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Fecha</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Ruta</th>
                            <th class="px-3 py-3.5 text-center font-semibold tracking-wider">Ítems</th>
                            <th class="px-3 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Enviada por</th>
                            <th class="px-3 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!transferencias.data.length">
                            <td colspan="7" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <ArrowLeftRight class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                Aún no hay transferencias entre sucursales.
                            </td>
                        </tr>
                        <template v-for="t in transferencias.data" :key="t.id">
                            <tr
                                class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                                @click="expandido = expandido === t.id ? null : t.id"
                            >
                                <td class="px-2 py-3 text-center">
                                    <ChevronDown
                                        class="size-4 text-neutral-400 transition-transform"
                                        :class="expandido === t.id ? 'rotate-180' : ''"
                                    />
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ fechaHora(t.creado_en) }}</td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center gap-1.5 font-medium">
                                        {{ t.sucursal_origen?.nombre }}
                                        <ArrowRight class="size-3.5 text-neutral-400" />
                                        {{ t.sucursal_destino?.nombre }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-center text-neutral-600 dark:text-neutral-300">{{ t.detalles.length }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS[t.estado]?.clase">
                                        {{ ESTADOS[t.estado]?.label ?? t.estado }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-neutral-600 dark:text-neutral-300">{{ t.usuario?.nombre_completo ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex justify-end gap-1.5">
                                        <button
                                            v-if="puede('transferencias.gestionar') && ['pendiente', 'en_transito'].includes(t.estado)"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                            @click.stop="recibir(t)"
                                        >
                                            <CheckCircle2 class="size-3.5" />
                                            Recibir
                                        </button>
                                        <button
                                            v-if="puede('transferencias.anular') && ['pendiente', 'en_transito'].includes(t.estado)"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                            @click.stop="anular(t)"
                                        >
                                            <Ban class="size-3.5" />
                                            Anular
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="expandido === t.id">
                                <td colspan="7" class="bg-stone-50 px-6 py-4 dark:bg-neutral-950/50">
                                    <p class="mb-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Productos</p>
                                    <div class="space-y-1.5">
                                        <div v-for="d in t.detalles" :key="d.id" class="flex items-center justify-between gap-3 text-sm">
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span class="truncate">{{ d.producto?.nombre ?? '—' }}</span>
                                                <span
                                                    v-if="d.lote"
                                                    class="shrink-0 rounded-full bg-stone-200 px-2 py-0.5 font-mono text-[11px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                                    :title="d.lote.fecha_vencimiento ? `Vence: ${d.lote.fecha_vencimiento.slice(0, 10)}` : undefined"
                                                >
                                                    Lote {{ d.lote.numero_lote }}
                                                </span>
                                            </span>
                                            <span class="font-medium">{{ cantidad(d.cantidad) }} unid.</span>
                                        </div>
                                    </div>
                                    <p v-if="t.observacion" class="mt-3 text-sm text-neutral-500 dark:text-neutral-400">
                                        Observación: {{ t.observacion }}
                                    </p>
                                    <p v-if="t.recibida_en" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                        Recibida el {{ fechaHora(t.recibida_en) }}
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="transferencias.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ transferencias.from }}–{{ transferencias.to }} de {{ transferencias.total }} transferencias</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in transferencias.links" :key="i">
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
    </AppLayout>
</template>
