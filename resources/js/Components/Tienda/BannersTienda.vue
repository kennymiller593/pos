<script setup>
import { ref } from 'vue'
import { ArrowDown, ArrowUp, ImagePlus, LoaderCircle, Trash2 } from '@lucide/vue'
import { claseAyuda, claseError, claseInput, claseLabel, claseTarjeta } from './clases'

const props = defineProps({
    // el formulario de la tienda: aquí se editan form.banners
    form: { type: Object, required: true },
    categorias: { type: Array, default: () => [] },
    max: { type: Number, default: 5 },
})

const DESTINOS = [
    { valor: 'ninguno', nombre: 'A ningún lado (solo imagen)' },
    { valor: 'catalogo', nombre: 'A todo el catálogo' },
    { valor: 'categoria', nombre: 'A una categoría' },
    { valor: 'whatsapp', nombre: 'A tu WhatsApp' },
    { valor: 'url', nombre: 'A otro enlace' },
]

const selector = ref(null)
const reemplazando = ref(null) // clave del banner cuya imagen se está cambiando; null = banner nuevo
const preparando = ref(false)
const aviso = ref('')

/**
 * Las fotos del celular pesan varios MB: se reducen aquí mismo a lo que cabe en una pantalla grande,
 * así subir cinco banners no demora ni choca con el límite del servidor.
 */
async function reducir(archivo) {
    try {
        const imagen = await createImageBitmap(archivo)
        const factor = Math.min(1, 1920 / imagen.width)
        if (factor === 1 && archivo.size < 900 * 1024) return archivo

        const lienzo = document.createElement('canvas')
        lienzo.width = Math.round(imagen.width * factor)
        lienzo.height = Math.round(imagen.height * factor)
        const dibujo = lienzo.getContext('2d')
        dibujo.fillStyle = '#fff'
        dibujo.fillRect(0, 0, lienzo.width, lienzo.height)
        dibujo.drawImage(imagen, 0, 0, lienzo.width, lienzo.height)

        const reducida = await new Promise((listo) => lienzo.toBlob(listo, 'image/jpeg', 0.86))
        return reducida ? new File([reducida], archivo.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : archivo
    } catch {
        // un navegador que no puede leerla la manda tal cual: el servidor la valida igual
        return archivo
    }
}

function abrirSelector(clave = null) {
    reemplazando.value = clave
    selector.value?.click()
}

async function elegir(evento) {
    const original = evento.target.files?.[0]
    evento.target.value = ''
    if (!original) return

    aviso.value = ''
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(original.type)) {
        aviso.value = 'Formatos permitidos: JPG, PNG o WEBP.'
        return
    }

    preparando.value = true
    const archivo = await reducir(original)
    preparando.value = false

    if (archivo.size > 6 * 1024 * 1024) {
        aviso.value = 'La imagen no debe pesar más de 6 MB.'
        return
    }

    const vista = URL.createObjectURL(archivo)
    const actual = props.form.banners.find((b) => b.clave === reemplazando.value)

    if (actual) {
        if (actual.vista) URL.revokeObjectURL(actual.vista)
        Object.assign(actual, { archivo, vista, imagen: null })
    } else if (props.form.banners.length < props.max) {
        props.form.banners.push({ clave: Math.random().toString(36).slice(2), imagen: null, archivo, vista, titulo: '', destino: 'ninguno', categoria: '', url: '' })
    }
    props.form.clearErrors()
}

function quitar(indice) {
    const [banner] = props.form.banners.splice(indice, 1)
    if (banner?.vista) URL.revokeObjectURL(banner.vista)
    props.form.clearErrors()
}

function mover(indice, pasos) {
    const destino = indice + pasos
    if (destino < 0 || destino >= props.form.banners.length) return
    const lista = props.form.banners
    ;[lista[indice], lista[destino]] = [lista[destino], lista[indice]]
    props.form.clearErrors()
}

const error = (indice, campo) => props.form.errors[`banners.${indice}.${campo}`]
</script>

<template>
    <section :class="[claseTarjeta, 'p-5']" aria-labelledby="titulo-banners">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="titulo-banners" class="font-semibold tracking-tight">Banners</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    Imágenes de tus promociones, campañas o novedades. Salen en la portada, debajo de la presentación, y se deslizan si hay más de una.
                </p>
            </div>
            <button
                v-if="form.banners.length && form.banners.length < max"
                type="button"
                :disabled="preparando"
                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-semibold transition-colors hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:hover:bg-neutral-800"
                @click="abrirSelector()"
            >
                <LoaderCircle v-if="preparando" class="size-4 animate-spin" />
                <ImagePlus v-else class="size-4" />
                Agregar banner
            </button>
        </div>

        <input ref="selector" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-label="Imagen del banner" @change="elegir" />
        <p v-if="aviso || form.errors.banners" :class="[claseError, 'mt-3']">{{ aviso || form.errors.banners }}</p>

        <!-- Sin banners -->
        <button
            v-if="!form.banners.length"
            type="button"
            :disabled="preparando"
            class="mt-4 flex aspect-[16/6] max-h-64 w-full flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed border-stone-300 text-sm text-neutral-500 transition-colors hover:border-emerald-500 hover:bg-emerald-50/50 hover:text-emerald-700 dark:border-neutral-700 dark:text-neutral-400 dark:hover:border-emerald-500 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300"
            data-banner-vacio
            @click="abrirSelector()"
        >
            <LoaderCircle v-if="preparando" class="size-7 animate-spin" />
            <ImagePlus v-else class="size-7" />
            <span class="font-semibold">Subir tu primer banner</span>
            <span class="text-xs">Horizontal, idealmente de 1600 × 600 px. Hasta {{ max }} banners.</span>
        </button>

        <ul v-else class="mt-4 space-y-4">
            <li
                v-for="(banner, i) in form.banners"
                :key="banner.clave"
                class="grid gap-4 rounded-2xl border border-stone-200 p-3 sm:p-4 lg:grid-cols-[minmax(0,22rem)_1fr] dark:border-neutral-800"
                data-banner
            >
                <!-- Imagen -->
                <div>
                    <div class="relative overflow-hidden rounded-xl border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                        <img :src="banner.vista ?? banner.imagen" :alt="banner.titulo || `Banner ${i + 1}`" class="aspect-[8/3] w-full object-cover" />
                        <div v-if="banner.titulo" class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-neutral-950/80 to-transparent px-3 pt-8 pb-2">
                            <p class="truncate text-sm font-semibold text-white">{{ banner.titulo }}</p>
                        </div>
                        <span class="absolute top-2 left-2 rounded-lg bg-white/95 px-2 py-0.5 text-xs font-semibold text-neutral-800 shadow">{{ i + 1 }}</span>
                    </div>
                    <p v-if="error(i, 'archivo')" :class="claseError">{{ error(i, 'archivo') }}</p>

                    <div class="mt-2 flex items-center gap-1.5">
                        <button
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-stone-300 px-3 text-xs font-semibold hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="abrirSelector(banner.clave)"
                        >
                            <ImagePlus class="size-3.5" />
                            Cambiar imagen
                        </button>
                        <span class="flex-1" />
                        <button
                            type="button"
                            :disabled="i === 0"
                            class="grid size-9 place-items-center rounded-lg border border-stone-300 hover:bg-stone-50 disabled:opacity-35 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            :aria-label="`Subir el banner ${i + 1}`"
                            title="Mostrar antes"
                            @click="mover(i, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            :disabled="i === form.banners.length - 1"
                            class="grid size-9 place-items-center rounded-lg border border-stone-300 hover:bg-stone-50 disabled:opacity-35 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            :aria-label="`Bajar el banner ${i + 1}`"
                            title="Mostrar después"
                            @click="mover(i, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="grid size-9 place-items-center rounded-lg border border-stone-300 text-red-600 hover:bg-red-50 dark:border-neutral-700 dark:text-red-400 dark:hover:bg-red-500/10"
                            :aria-label="`Quitar el banner ${i + 1}`"
                            title="Quitar"
                            @click="quitar(i)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <!-- Texto y enlace -->
                <div class="grid content-start gap-3">
                    <div>
                        <label :class="claseLabel" :for="`banner_titulo_${banner.clave}`">Texto sobre la imagen <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input :id="`banner_titulo_${banner.clave}`" v-model="banner.titulo" type="text" maxlength="80" :class="claseInput" placeholder="Campaña de siembra: 10% en fertilizantes" />
                        <p v-if="error(i, 'titulo')" :class="claseError">{{ error(i, 'titulo') }}</p>
                        <p v-else :class="claseAyuda">Déjalo vacío si tu imagen ya trae el texto.</p>
                    </div>
                    <div>
                        <label :class="claseLabel" :for="`banner_destino_${banner.clave}`">Al tocarlo lleva</label>
                        <select :id="`banner_destino_${banner.clave}`" v-model="banner.destino" :class="claseInput">
                            <option v-for="d in DESTINOS" :key="d.valor" :value="d.valor" :disabled="d.valor === 'categoria' && !categorias.length">{{ d.nombre }}</option>
                        </select>
                    </div>
                    <div v-if="banner.destino === 'categoria'">
                        <label :class="claseLabel" :for="`banner_categoria_${banner.clave}`">Categoría</label>
                        <select :id="`banner_categoria_${banner.clave}`" v-model="banner.categoria" :class="claseInput">
                            <option value="" disabled>Elige una categoría</option>
                            <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                        <p v-if="error(i, 'categoria')" :class="claseError">{{ error(i, 'categoria') }}</p>
                    </div>
                    <div v-else-if="banner.destino === 'url'">
                        <label :class="claseLabel" :for="`banner_url_${banner.clave}`">Enlace</label>
                        <input :id="`banner_url_${banner.clave}`" v-model="banner.url" type="url" inputmode="url" maxlength="300" :class="claseInput" placeholder="https://..." />
                        <p v-if="error(i, 'url')" :class="claseError">{{ error(i, 'url') }}</p>
                    </div>
                </div>
            </li>
        </ul>

        <p v-if="form.banners.length" :class="[claseAyuda, 'mt-3']">
            {{ form.banners.length }} de {{ max }}. Usa imágenes horizontales, idealmente de 1600 × 600 px: en la tienda se recortan a esa forma.
        </p>
    </section>
</template>
