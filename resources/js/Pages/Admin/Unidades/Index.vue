<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Pencil, Plus, Ruler, Search, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    unidades: { type: Array, default: () => [] },
})

const buscar = ref('')
const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const filtradas = computed(() => {
    const q = normalizar(buscar.value.trim())
    return q ? props.unidades.filter((u) => normalizar(`${u.codigo} ${u.nombre} ${u.descripcion_sunat}`).includes(q)) : props.unidades
})

// ---- alta / edición ----
const modal = ref(false)
const editando = ref(null) // unidad en edición o null = nueva
const form = useForm({ codigo: '', nombre: '', descripcion_sunat: '', permite_decimales: false, activo: true })

function abrir(u = null) {
    editando.value = u
    form.clearErrors()
    form.codigo = u?.codigo ?? ''
    form.nombre = u?.nombre ?? ''
    form.descripcion_sunat = u?.descripcion_sunat ?? ''
    form.permite_decimales = u?.permite_decimales ?? false
    form.activo = u?.activo ?? true
    modal.value = true
}

function guardar() {
    const opciones = { preserveScroll: true, onSuccess: () => (modal.value = false) }
    form.transform((d) => ({ ...d, codigo: d.codigo.trim().toUpperCase(), descripcion_sunat: d.descripcion_sunat.trim().toUpperCase() }))
    editando.value ? form.put(`/admin/unidades/${editando.value.codigo}`, opciones) : form.post('/admin/unidades', opciones)
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Unidades de medida">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Unidades que las empresas eligen al registrar productos. El código debe existir en el
                <a href="https://cpe.sunat.gob.pe/sites/default/files/inline-files/anexoV-340-2017.pdf" target="_blank" rel="noopener" class="font-medium text-emerald-700 underline dark:text-emerald-400">catálogo 03 de SUNAT</a>;
                con un código inválido SUNAT rechaza la boleta.
            </p>
            <button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700" @click="abrir()">
                <Plus class="size-4" />
                Nueva unidad
            </button>
        </div>

        <div class="relative mb-4 max-w-md">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
            <input v-model="buscar" type="text" placeholder="Buscar por código o nombre..." :class="[claseInput, 'pl-9']" />
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold tracking-wider">Código</th>
                            <th class="px-4 py-3 font-semibold tracking-wider">Descripción SUNAT</th>
                            <th class="px-4 py-3 font-semibold tracking-wider">Nombre corto</th>
                            <th class="px-4 py-3 text-center font-semibold tracking-wider">Decimales</th>
                            <th class="px-4 py-3 text-right font-semibold tracking-wider">En uso</th>
                            <th class="px-4 py-3 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!filtradas.length">
                            <td colspan="7" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400"><div class="sticky left-4 max-w-[calc(100cqw-2rem)]">Sin unidades.</div></td>
                        </tr>
                        <tr v-for="u in filtradas" :key="u.codigo" :class="!u.activo ? 'opacity-60' : ''">
                            <td class="px-4 py-3 font-mono font-semibold">{{ u.codigo }}</td>
                            <td class="px-4 py-3 font-medium">{{ u.descripcion_sunat }}</td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ u.nombre }}</td>
                            <td class="px-4 py-3 text-center text-neutral-600 dark:text-neutral-300">{{ u.permite_decimales ? 'Sí' : 'No' }}</td>
                            <td class="px-4 py-3 text-right text-neutral-600 tabular-nums dark:text-neutral-300">{{ u.en_uso ? `${u.en_uso} prod.` : '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="u.activo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'">
                                    {{ u.activo ? 'Activa' : 'Desactivada' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100" title="Editar" @click="abrir(u)">
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
                <form class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" @submit.prevent="guardar">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="inline-flex items-center gap-2 font-semibold tracking-tight"><Ruler class="size-5 text-emerald-600" /> {{ editando ? `Editar ${editando.codigo}` : 'Nueva unidad de medida' }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800" @click="modal = false"><X class="size-5" /></button>
                    </div>
                    <div class="space-y-4">
                        <div v-if="!editando">
                            <label :class="claseLabel" for="u_codigo">Código SUNAT *</label>
                            <input id="u_codigo" v-model="form.codigo" type="text" maxlength="5" :class="[claseInput, 'font-mono uppercase']" placeholder="Ej. NIU, KGM, BX" />
                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Del catálogo 03 de SUNAT. Es lo que viaja en la boleta o factura electrónica.</p>
                            <p v-if="form.errors.codigo" :class="claseError">{{ form.errors.codigo }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="u_desc">Descripción SUNAT *</label>
                            <input id="u_desc" v-model="form.descripcion_sunat" type="text" maxlength="80" :class="[claseInput, 'uppercase']" placeholder="Ej. UNIDAD (BIENES)" />
                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Se muestra en mayúsculas junto al código al elegir la unidad.</p>
                            <p v-if="form.errors.descripcion_sunat" :class="claseError">{{ form.errors.descripcion_sunat }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="u_nombre">Nombre corto *</label>
                            <input id="u_nombre" v-model="form.nombre" type="text" maxlength="80" :class="claseInput" placeholder="Ej. Unidad, Kilogramo, Caja" />
                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Con este se arman los nombres de presentación ("Caja x12") y se importa desde Excel.</p>
                            <p v-if="form.errors.nombre" :class="claseError">{{ form.errors.nombre }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.permite_decimales" type="checkbox" class="size-4 rounded accent-emerald-600" />
                            Permite decimales (1.5 kg, 0.25 m…)
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.activo" type="checkbox" class="size-4 rounded accent-emerald-600" :disabled="editando?.codigo === 'NIU'" />
                            Activa (se ofrece al registrar productos)
                        </label>
                        <p v-if="form.errors.activo" :class="claseError">{{ form.errors.activo }}</p>
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
