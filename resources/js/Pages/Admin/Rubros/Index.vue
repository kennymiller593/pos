<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Pencil, Plus, Tags, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    rubros: { type: Array, default: () => [] },
})

const modal = ref(false)
const editando = ref(null)
const form = useForm({ nombre: '', activo: true })

function abrir(r = null) {
    editando.value = r
    form.clearErrors()
    form.nombre = r?.nombre ?? ''
    form.activo = r?.activo ?? true
    modal.value = true
}

function guardar() {
    const opciones = { preserveScroll: true, onSuccess: () => (modal.value = false) }
    editando.value ? form.put(`/admin/rubros/${editando.value.codigo}`, opciones) : form.post('/admin/rubros', opciones)
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Rubros">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Tipos de negocio que se ofrecen al registrar una empresa. Un rubro desactivado deja de ofrecerse; las empresas que ya lo tienen no cambian.
            </p>
            <button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700" @click="abrir()">
                <Plus class="size-4" />
                Nuevo rubro
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold tracking-wider">Rubro</th>
                            <th class="px-4 py-3 font-semibold tracking-wider">Código</th>
                            <th class="px-4 py-3 text-right font-semibold tracking-wider">Empresas</th>
                            <th class="px-4 py-3 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!rubros.length">
                            <td colspan="5" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400"><div class="sticky left-4 max-w-[calc(100cqw-2rem)]">Sin rubros.</div></td>
                        </tr>
                        <tr v-for="r in rubros" :key="r.codigo" :class="!r.activo ? 'opacity-60' : ''">
                            <td class="px-4 py-3 font-medium">{{ r.nombre }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ r.codigo }}</td>
                            <td class="px-4 py-3 text-right text-neutral-600 tabular-nums dark:text-neutral-300">{{ r.empresas || '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="r.activo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'">
                                    {{ r.activo ? 'Activo' : 'Desactivado' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100" title="Editar" @click="abrir(r)">
                                    <Pencil class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modal = false" />
                <form class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" @submit.prevent="guardar">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="inline-flex items-center gap-2 font-semibold tracking-tight"><Tags class="size-5 text-emerald-600" /> {{ editando ? 'Editar rubro' : 'Nuevo rubro' }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800" @click="modal = false"><X class="size-5" /></button>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label :class="claseLabel" for="r_nombre">Nombre *</label>
                            <input id="r_nombre" v-model="form.nombre" type="text" maxlength="80" :class="claseInput" placeholder="Ej. Panadería" />
                            <p v-if="form.errors.nombre" :class="claseError">{{ form.errors.nombre }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.activo" type="checkbox" class="size-4 rounded accent-emerald-600" />
                            Activo (se ofrece al registrar empresas)
                        </label>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="modal = false">Cancelar</button>
                        <button type="submit" :disabled="form.processing" class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                            {{ form.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
