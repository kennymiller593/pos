<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { CornerDownRight, Pencil, Plus, Tag, Tags, Trash2, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    categorias: { type: Array, required: true },
    marcas: { type: Array, required: true },
})

const { confirmar } = useConfirmar()

// ---- modal (crear/editar categoria o marca) ----
const modal = ref(null) // { tipo: 'categoria' | 'marca', item: Object | null }

const form = useForm({
    nombre: '',
    padre_id: '',
})

function abrir(tipo, item = null) {
    modal.value = { tipo, item }
    form.clearErrors()
    form.nombre = item?.nombre ?? ''
    form.padre_id = item?.padre_id ?? ''
}

function cerrar() {
    modal.value = null
}

function guardar() {
    const { tipo, item } = modal.value
    const base = tipo === 'categoria' ? '/categorias' : '/marcas'

    const opciones = { preserveScroll: true, onSuccess: cerrar }
    const transformar = (data) =>
        tipo === 'categoria' ? { nombre: data.nombre, padre_id: data.padre_id || null } : { nombre: data.nombre }

    if (item) {
        form.transform(transformar).put(`${base}/${item.id}`, opciones)
    } else {
        form.transform(transformar).post(base, opciones)
    }
}

async function eliminar(tipo, item) {
    const etiqueta = tipo === 'categoria' ? 'la categoría' : 'la marca'
    const confirmado = await confirmar({
        titulo: `Eliminar ${etiqueta}`,
        mensaje: `Se eliminará "${item.nombre}" definitivamente.`,
        textoConfirmar: 'Eliminar',
        peligro: true,
    })

    if (confirmado) {
        router.delete(`/${tipo === 'categoria' ? 'categorias' : 'marcas'}/${item.id}`, { preserveScroll: true })
    }
}

// las subcategorias no pueden ser padre de nadie mas alla de un nivel visual,
// pero el unico filtro duro en el cliente es no elegirse a si misma (el servidor valida ciclos)
function opcionesPadre(itemEditando) {
    return props.categorias.filter((c) => c.id !== itemEditando?.id)
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
</script>

<template>
    <AppLayout titulo="Categorías y marcas">
        <div class="grid items-start gap-4 lg:grid-cols-2">
            <!-- Categorías -->
            <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <div class="flex items-center gap-3">
                        <div class="grid size-9 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <Tags class="size-5" />
                        </div>
                        <div>
                            <h2 class="font-semibold tracking-tight">Categorías</h2>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ categorias.length }} registradas</p>
                        </div>
                    </div>
                    <button
                        class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 text-sm font-semibold text-white hover:bg-emerald-700"
                        @click="abrir('categoria')"
                    >
                        <Plus class="size-4" />
                        Agregar
                    </button>
                </div>

                <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                    <li v-if="!categorias.length" class="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        Aún no tienes categorías.
                    </li>
                    <li
                        v-for="c in categorias"
                        :key="c.id"
                        class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                    >
                        <div class="min-w-0">
                            <p class="flex items-center gap-1.5 truncate text-sm font-medium">
                                <CornerDownRight v-if="c.padre" class="size-3.5 shrink-0 text-neutral-400" />
                                {{ c.nombre }}
                            </p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                <template v-if="c.padre">en {{ c.padre.nombre }} · </template>
                                {{ c.productos_count }} producto{{ c.productos_count === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <button
                                class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                title="Editar"
                                @click="abrir('categoria', c)"
                            >
                                <Pencil class="size-4" />
                            </button>
                            <button
                                class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                title="Eliminar"
                                @click="eliminar('categoria', c)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Marcas -->
            <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <div class="flex items-center gap-3">
                        <div class="grid size-9 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <Tag class="size-5" />
                        </div>
                        <div>
                            <h2 class="font-semibold tracking-tight">Marcas</h2>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ marcas.length }} registradas</p>
                        </div>
                    </div>
                    <button
                        class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 text-sm font-semibold text-white hover:bg-emerald-700"
                        @click="abrir('marca')"
                    >
                        <Plus class="size-4" />
                        Agregar
                    </button>
                </div>

                <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                    <li v-if="!marcas.length" class="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        Aún no tienes marcas.
                    </li>
                    <li
                        v-for="m in marcas"
                        :key="m.id"
                        class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ m.nombre }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ m.productos_count }} producto{{ m.productos_count === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <button
                                class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                title="Editar"
                                @click="abrir('marca', m)"
                            >
                                <Pencil class="size-4" />
                            </button>
                            <button
                                class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                title="Eliminar"
                                @click="eliminar('marca', m)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Modal crear/editar -->
        <Teleport to="body">
            <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="cerrar" />

                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardar"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">
                            {{ modal.item ? 'Editar' : 'Nueva' }} {{ modal.tipo === 'categoria' ? 'categoría' : 'marca' }}
                        </h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="cerrar"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="nombre">Nombre *</label>
                            <input id="nombre" v-model="form.nombre" type="text" :class="claseInput" autofocus />
                            <p v-if="form.errors.nombre" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.nombre }}</p>
                        </div>

                        <div v-if="modal.tipo === 'categoria'">
                            <label class="mb-1 block text-sm font-medium" for="padre">Categoría padre</label>
                            <select id="padre" v-model="form.padre_id" :class="claseInput">
                                <option value="">Ninguna (nivel principal)</option>
                                <option v-for="c in opcionesPadre(modal.item)" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                            <p v-if="form.errors.padre_id" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.padre_id }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="cerrar"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ form.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
