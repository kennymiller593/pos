<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { ArrowDown, ArrowUp, ImagePlus, LoaderCircle, Tag, Trash2 } from '@lucide/vue'
import { claseTarjeta } from './clases'

const props = defineProps({
    // [{ id, nombre, productos, imagen, imagen_propia, ordenada }] en el orden en que salen en la tienda
    categorias: { type: Array, required: true },
})

// copia local para mover filas al instante; el servidor confirma y la lista se vuelve a leer
const lista = ref([...props.categorias])
watch(() => props.categorias, (v) => (lista.value = [...v]))

const guardandoOrden = ref(false)
function mover(i, paso) {
    const destino = i + paso
    if (destino < 0 || destino >= lista.value.length) return
    const copia = [...lista.value]
    ;[copia[i], copia[destino]] = [copia[destino], copia[i]]
    lista.value = copia
    guardandoOrden.value = true
    router.patch('/tienda-en-linea/categorias/orden', { orden: copia.map((c) => c.id) }, {
        preserveScroll: true,
        preserveState: true,
        only: ['tienda', 'flash'],
        onFinish: () => (guardandoOrden.value = false),
    })
}

// foto propia de la categoría: se sube al elegirla
const subiendo = ref(null)
const errorFoto = ref('')
const inputs = {}
function elegirFoto(c) {
    inputs[c.id]?.click()
}
function subirFoto(c, evento) {
    const archivo = evento.target.files?.[0]
    evento.target.value = ''
    if (!archivo) return
    subiendo.value = c.id
    errorFoto.value = ''
    router.post(`/tienda-en-linea/categorias/${c.id}/imagen`, { imagen: archivo }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        only: ['tienda', 'flash', 'errors'],
        onError: (e) => (errorFoto.value = e.imagen ?? 'No se pudo subir la foto.'),
        onFinish: () => (subiendo.value = null),
    })
}
function quitarFoto(c) {
    subiendo.value = c.id
    router.delete(`/tienda-en-linea/categorias/${c.id}/imagen`, {
        preserveScroll: true,
        preserveState: true,
        only: ['tienda', 'flash'],
        onFinish: () => (subiendo.value = null),
    })
}
</script>

<template>
    <section :class="[claseTarjeta, 'overflow-hidden']" aria-label="Categorías de la tienda" data-categorias-tienda>
        <div class="border-b border-stone-200 p-5 dark:border-neutral-800">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <div>
                    <h2 class="font-semibold tracking-tight">Categorías de la tienda</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        En este orden salen en el menú, la portada y los filtros. Súbele una foto a cada una; sin foto propia se usa la de uno de sus productos.
                    </p>
                </div>
                <p class="flex items-center gap-1.5 text-sm text-neutral-500 dark:text-neutral-400">
                    <LoaderCircle v-if="guardandoOrden" class="size-4 animate-spin" />
                    {{ guardandoOrden ? 'Guardando orden...' : `${lista.length} categorías con productos` }}
                </p>
            </div>
            <p v-if="errorFoto" class="mt-3 rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ errorFoto }}</p>
        </div>

        <p v-if="!lista.length" class="px-5 py-12 text-center text-sm text-neutral-500 dark:text-neutral-400">
            <Tag class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
            Aún no hay categorías con productos visibles en la tienda.
        </p>

        <ol class="divide-y divide-stone-100 dark:divide-neutral-800">
            <li v-for="(c, i) in lista" :key="c.id" class="flex items-center gap-3 px-5 py-3" :class="subiendo === c.id ? 'opacity-60' : ''" :data-categoria="c.id">
                <span class="w-5 shrink-0 text-center text-xs font-semibold text-neutral-400 tabular-nums">{{ i + 1 }}</span>

                <div class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-full border border-stone-200 bg-white dark:border-neutral-700">
                    <img v-if="c.imagen" :src="c.imagen" :alt="c.nombre" class="size-full object-contain p-1" loading="lazy" />
                    <Tag v-else class="size-5 text-neutral-300" />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ c.nombre }}</p>
                    <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                        {{ c.productos }} producto{{ c.productos === 1 ? '' : 's' }}
                        <template v-if="c.imagen_propia"> · foto propia</template>
                        <template v-else-if="c.imagen"> · foto de un producto</template>
                        <template v-else> · sin foto</template>
                    </p>
                </div>

                <input :ref="(el) => (inputs[c.id] = el)" type="file" accept="image/*" class="hidden" :aria-label="`Foto de ${c.nombre}`" @change="subirFoto(c, $event)" />
                <button
                    type="button"
                    class="hidden h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-neutral-600 hover:bg-stone-100 hover:text-neutral-900 sm:inline-flex dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                    :disabled="subiendo === c.id"
                    data-subir-foto
                    @click="elegirFoto(c)"
                >
                    <LoaderCircle v-if="subiendo === c.id" class="size-3.5 animate-spin" />
                    <ImagePlus v-else class="size-3.5" />
                    {{ c.imagen_propia ? 'Cambiar foto' : 'Subir foto' }}
                </button>
                <button
                    type="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg text-neutral-500 hover:bg-stone-100 sm:hidden dark:text-neutral-400 dark:hover:bg-neutral-800"
                    :aria-label="`Subir foto de ${c.nombre}`"
                    :disabled="subiendo === c.id"
                    @click="elegirFoto(c)"
                >
                    <ImagePlus class="size-4" />
                </button>
                <button
                    v-if="c.imagen_propia"
                    type="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                    :aria-label="`Quitar la foto de ${c.nombre}`"
                    title="Quitar la foto propia"
                    :disabled="subiendo === c.id"
                    data-quitar-foto
                    @click="quitarFoto(c)"
                >
                    <Trash2 class="size-4" />
                </button>

                <div class="flex shrink-0 flex-col gap-0.5 sm:flex-row">
                    <button type="button" class="grid size-8 place-items-center rounded-lg text-neutral-500 hover:bg-stone-100 disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800" :disabled="i === 0 || guardandoOrden" :aria-label="`Subir ${c.nombre}`" data-subir @click="mover(i, -1)">
                        <ArrowUp class="size-4" />
                    </button>
                    <button type="button" class="grid size-8 place-items-center rounded-lg text-neutral-500 hover:bg-stone-100 disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800" :disabled="i === lista.length - 1 || guardandoOrden" :aria-label="`Bajar ${c.nombre}`" data-bajar @click="mover(i, 1)">
                        <ArrowDown class="size-4" />
                    </button>
                </div>
            </li>
        </ol>
    </section>
</template>
