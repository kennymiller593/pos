<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Bug, CalendarDays, ChevronDown, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    errores: { type: Object, required: true },
    // { empresa, tipo, desde, hasta } con lo que ya está aplicado
    filtros: { type: Object, default: () => ({}) },
    // { js: 'Error de JavaScript', ... }
    tipos: { type: Object, default: () => ({}) },
    // [{ id, nombre }] solo las que tienen errores
    empresas: { type: Array, default: () => [] },
})

const CLASES = {
    js: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
    red: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    respuesta: 'bg-purple-100 text-purple-800 dark:bg-purple-500/15 dark:text-purple-300',
    negocio: 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
}

const fechaHora = (f) => new Date(f).toLocaleString('es-PE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const expandido = ref(null)

// ---- filtros ----
const empresa = ref(props.filtros.empresa ?? '')
const tipo = ref(props.filtros.tipo ?? '')
const desde = ref(props.filtros.desde ?? '')
const hasta = ref(props.filtros.hasta ?? '')
const hayFiltros = computed(() => !!(empresa.value || tipo.value || desde.value || hasta.value))

function aplicarFiltros() {
    router.get('/admin/errores', {
        empresa: empresa.value || undefined,
        tipo: tipo.value || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}
function limpiarFiltros() {
    empresa.value = ''
    tipo.value = ''
    desde.value = ''
    hasta.value = ''
}
watch([empresa, tipo, desde, hasta], aplicarFiltros)

const claseInput =
    'h-10 rounded-xl border border-[#E2E8F0] bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'
</script>

<template>
    <AppLayout titulo="Errores del cliente">
        <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">
            Lo que falló en el navegador de los usuarios y no llegó a ningún otro log: errores de JavaScript, envíos sin respuesta,
            respuestas ajenas al sistema (bloqueos, sesión vencida) y rechazos del propio sistema.
        </p>

        <div class="mb-4 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
            <select v-model="empresa" aria-label="Empresa" :class="[claseInput, 'col-span-2 sm:w-64']" data-filtro-empresa>
                <option value="">Todas las empresas</option>
                <option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
            </select>
            <select v-model="tipo" aria-label="Tipo" :class="[claseInput, 'col-span-2 sm:w-56']">
                <option value="">Todos los tipos</option>
                <option v-for="(nombre, codigo) in tipos" :key="codigo" :value="codigo">{{ nombre }}</option>
            </select>
            <div class="col-span-2 flex items-center gap-1.5 rounded-xl border border-[#E2E8F0] bg-white pr-1.5 pl-3 dark:border-neutral-800 dark:bg-neutral-900">
                <CalendarDays class="size-4 shrink-0 text-[#94A3B8]" />
                <input v-model="desde" type="date" :max="hasta || undefined" aria-label="Desde" class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
                <span class="text-xs text-[#94A3B8]">a</span>
                <input v-model="hasta" type="date" :min="desde || undefined" aria-label="Hasta" class="h-10 min-w-0 flex-1 bg-transparent text-sm focus:outline-none sm:w-34 sm:flex-none" />
            </div>
            <button v-if="hayFiltros" type="button" class="col-span-2 inline-flex h-10 items-center justify-center gap-1.5 rounded-xl px-3 text-sm font-medium text-[#64748B] hover:bg-slate-100 sm:col-span-1 dark:text-neutral-400 dark:hover:bg-neutral-800" @click="limpiarFiltros">
                <X class="size-4" />
                Limpiar
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="w-8 px-2 py-3" />
                            <th class="px-3 py-3 font-semibold tracking-wider">Cuándo</th>
                            <th class="px-3 py-3 font-semibold tracking-wider">Empresa · usuario</th>
                            <th class="px-3 py-3 font-semibold tracking-wider">Tipo</th>
                            <th class="px-3 py-3 font-semibold tracking-wider">Qué pasó</th>
                            <th class="px-3 py-3 font-semibold tracking-wider">Dónde</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!errores.data.length">
                            <td colspan="6" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <Bug class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    {{ hayFiltros ? 'No hay errores con esos filtros.' : 'Ningún navegador ha avisado errores.' }}
                                </div>
                            </td>
                        </tr>
                        <template v-for="e in errores.data" :key="e.id">
                            <tr class="cursor-pointer align-top transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50" data-error @click="expandido = expandido === e.id ? null : e.id">
                                <td class="px-2 py-3 text-center"><ChevronDown class="size-4 text-neutral-400 transition-transform" :class="expandido === e.id ? 'rotate-180' : ''" /></td>
                                <td class="px-3 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ fechaHora(e.creado_en) }}</td>
                                <td class="px-3 py-3">
                                    <span class="block font-medium">{{ e.empresa }}</span>
                                    <span class="block text-xs text-neutral-500 dark:text-neutral-400">{{ e.usuario }}</span>
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="CLASES[e.tipo]">{{ tipos[e.tipo] ?? e.tipo }}</span>
                                </td>
                                <td class="max-w-md px-3 py-3"><span class="line-clamp-2">{{ e.mensaje }}</span></td>
                                <td class="px-3 py-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                    <span class="block">{{ e.pantalla ?? '—' }}</span>
                                    <span v-if="e.accion" class="block text-emerald-700 dark:text-emerald-400">{{ e.accion }}</span>
                                </td>
                            </tr>
                            <tr v-if="expandido === e.id">
                                <td colspan="6" class="bg-stone-50 px-6 py-4 dark:bg-neutral-950/50">
                                    <p class="text-sm whitespace-pre-wrap">{{ e.mensaje }}</p>
                                    <pre v-if="e.detalle" class="mt-3 max-h-72 overflow-auto rounded-xl bg-white p-3 font-mono text-xs text-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">{{ JSON.stringify(e.detalle, null, 2) }}</pre>
                                    <p class="mt-3 text-xs text-neutral-400 dark:text-neutral-500">{{ e.navegador }} · IP {{ e.ip ?? '—' }}</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div v-if="errores.data.length" class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400">
                <span>Mostrando {{ errores.from }}–{{ errores.to }} de {{ errores.total }} errores</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in errores.links" :key="i">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3.5 py-1.5" :class="link.active ? 'border-emerald-600 bg-emerald-600 font-semibold text-white' : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'" v-html="link.label" />
                        <span v-else class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
