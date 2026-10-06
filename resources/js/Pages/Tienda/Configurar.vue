<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import {
    Check,
    Copy,
    ExternalLink,
    Eye,
    EyeOff,
    Globe,
    ImageOff,
    Package,
    Pencil,
    Search,
    Star,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    // { slug, sugerencia, publicada, config, dominio, url, colores: { nombre: '#hex' } }
    tienda: { type: Object, required: true },
    resumen: { type: Object, required: true },
    maxDestacados: { type: Number, default: 8 },
    productos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({ buscar: '', ver: 'todos' }) },
})

const soles = (n) => `S/ ${Number(n ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

// ---- configuración ----
const form = useForm({
    slug: props.tienda.sugerencia ?? '',
    publicada: props.tienda.publicada,
    descripcion: props.tienda.config.descripcion ?? '',
    color: props.tienda.config.color,
    mostrar_precios: props.tienda.config.mostrar_precios,
    mostrar_stock: props.tienda.config.mostrar_stock,
    whatsapp: props.tienda.config.whatsapp ?? '',
    telefono: props.tienda.config.telefono ?? '',
    email: props.tienda.config.email ?? '',
    direccion: props.tienda.config.direccion ?? '',
    horario: props.tienda.config.horario ?? '',
    facebook: props.tienda.config.facebook ?? '',
    instagram: props.tienda.config.instagram ?? '',
    tiktok: props.tienda.config.tiktok ?? '',
})

// la dirección solo admite minúsculas, números y guiones: se corrige mientras se escribe
function limpiarSlug() {
    form.slug = form.slug
        .toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-{2,}/g, '-')
        .replace(/^-/, '')
        .slice(0, 40)
}

const direccionCompleta = computed(() => `${form.slug || 'tu-negocio'}.${props.tienda.dominio}`)
// cambiar la dirección de una tienda ya publicada rompe los enlaces que el dueño ya compartió
const cambiaDireccion = computed(() => props.tienda.publicada && props.tienda.slug && form.slug !== props.tienda.slug)

function guardar() {
    form.put('/tienda-en-linea', {
        preserveScroll: true,
        // lo guardado pasa a ser el punto de partida ("cambios sin guardar" se apaga)
        onSuccess: () => form.defaults(),
        // si no se pudo publicar (falta un contacto, dirección ocupada...), el estado vuelve al real
        onError: () => (form.publicada = props.tienda.publicada),
    })
}

function publicar(valor) {
    form.publicada = valor
    guardar()
}

const copiado = ref(false)
async function copiar() {
    try {
        await navigator.clipboard.writeText(props.tienda.url)
        copiado.value = true
        setTimeout(() => (copiado.value = false), 1800)
    } catch {
        // sin permiso de portapapeles: el enlace sigue visible para copiarlo a mano
    }
}

const COLORES = { esmeralda: 'Esmeralda', azul: 'Azul', indigo: 'Índigo', rojo: 'Rojo', naranja: 'Naranja', grafito: 'Grafito' }

// ---- productos de la tienda ----
const buscar = ref(props.filtros.buscar ?? '')
const ver = ref(props.filtros.ver ?? 'todos')
const VISTAS = [
    { valor: 'todos', label: 'Todos' },
    { valor: 'destacados', label: 'Destacados' },
    { valor: 'ocultos', label: 'Ocultos' },
]

function filtrar() {
    router.get('/tienda-en-linea', {
        buscar: buscar.value || undefined,
        ver: ver.value !== 'todos' ? ver.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['productos', 'filtros'] })
}
watchDebounced(buscar, filtrar, { debounce: 350 })

function verVista(valor) {
    ver.value = valor
    filtrar()
}

const guardando = ref(null) // id del producto que se está cambiando
function cambiar(producto, datos, alTerminar = null) {
    guardando.value = producto.id
    router.patch(`/tienda-en-linea/productos/${producto.id}`, datos, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => alTerminar?.(),
        onFinish: () => (guardando.value = null),
    })
}

// descripción del producto (sale en su página de la tienda)
const editando = ref(null)
const formDescripcion = useForm({ descripcion: '' })

function editarDescripcion(producto) {
    editando.value = producto
    formDescripcion.clearErrors()
    formDescripcion.descripcion = producto.descripcion ?? ''
}

function guardarDescripcion() {
    formDescripcion.patch(`/tienda-en-linea/productos/${editando.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    })
}

const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'
const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseAyuda = 'mt-1 text-xs text-neutral-400 dark:text-neutral-500'
const claseInterruptor =
    'relative h-6 w-11 shrink-0 rounded-full bg-stone-300 transition-colors peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-400/50 after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-5 dark:bg-neutral-700'
</script>

<template>
    <AppLayout titulo="Tienda en línea">
        <!-- Estado: publicada o no, y su dirección -->
        <div
            :class="[claseTarjeta, 'mb-4 flex flex-col gap-4 p-5 lg:flex-row lg:items-center', tienda.publicada ? 'border-emerald-300 dark:border-emerald-500/40' : '']"
            data-estado-tienda
        >
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <div
                    class="grid size-12 shrink-0 place-items-center rounded-2xl"
                    :class="tienda.publicada ? 'bg-emerald-600 text-white' : 'bg-stone-100 text-neutral-400 dark:bg-neutral-800'"
                >
                    <Globe class="size-6" />
                </div>
                <div class="min-w-0">
                    <p class="font-semibold tracking-tight">
                        {{ tienda.publicada ? 'Tu tienda está publicada' : 'Tu tienda aún no está publicada' }}
                    </p>
                    <p v-if="tienda.publicada" class="truncate text-sm text-emerald-700 dark:text-emerald-400">{{ tienda.url }}</p>
                    <p v-else class="text-sm text-neutral-500 dark:text-neutral-400">
                        Un catálogo en internet con tus productos, tu buscador y tus contactos. Complétala abajo y publícala.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <template v-if="tienda.publicada">
                    <button
                        type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="copiar"
                    >
                        <component :is="copiado ? Check : Copy" class="size-4" />
                        {{ copiado ? 'Copiado' : 'Copiar enlace' }}
                    </button>
                    <a
                        :href="tienda.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                    >
                        <ExternalLink class="size-4" />
                        Ver mi tienda
                    </a>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="inline-flex h-10 items-center rounded-xl px-3 text-sm font-medium text-neutral-500 hover:bg-stone-100 hover:text-neutral-900 disabled:opacity-60 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        @click="publicar(false)"
                    >
                        Dejar de publicar
                    </button>
                </template>
                <button
                    v-else
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-60"
                    @click="publicar(true)"
                >
                    <Globe class="size-4" />
                    Publicar tienda
                </button>
            </div>
        </div>

        <form class="grid items-start gap-4 xl:grid-cols-2" @submit.prevent="guardar">
            <!-- Dirección y presentación -->
            <section :class="[claseTarjeta, 'p-5']">
                <h2 class="font-semibold tracking-tight">Dirección y presentación</h2>

                <div class="mt-4">
                    <label :class="claseLabel" for="tienda_slug">Dirección de tu tienda</label>
                    <div class="flex h-10 items-stretch overflow-hidden rounded-xl border border-stone-300 bg-white focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/30 dark:border-neutral-700 dark:bg-neutral-950">
                        <input
                            id="tienda_slug"
                            v-model="form.slug"
                            type="text"
                            maxlength="40"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="tu-negocio"
                            class="min-w-0 flex-1 bg-transparent px-3 text-sm focus:outline-none"
                            @input="limpiarSlug"
                        />
                        <span class="flex shrink-0 items-center border-l border-stone-200 bg-stone-50 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">.{{ tienda.dominio }}</span>
                    </div>
                    <p v-if="form.errors.slug" :class="claseError">{{ form.errors.slug }}</p>
                    <p v-else-if="cambiaDireccion" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        Si la cambias, los enlaces que ya compartiste ({{ tienda.slug }}.{{ tienda.dominio }}) dejarán de funcionar.
                    </p>
                    <p v-else :class="claseAyuda">Tus clientes entrarán a {{ direccionCompleta }}</p>
                </div>

                <div class="mt-4">
                    <label :class="claseLabel" for="tienda_descripcion">Presentación <span class="font-normal text-neutral-400">(opcional)</span></label>
                    <textarea
                        id="tienda_descripcion"
                        v-model="form.descripcion"
                        rows="3"
                        maxlength="300"
                        placeholder="Qué vendes, dónde atiendes, si haces envíos..."
                        class="w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                    />
                    <p v-if="form.errors.descripcion" :class="claseError">{{ form.errors.descripcion }}</p>
                    <p v-else :class="claseAyuda">{{ form.descripcion.length }} de 300. Sale en la portada, debajo del nombre de tu negocio.</p>
                </div>

                <fieldset class="mt-4">
                    <legend :class="claseLabel">Color de tu tienda</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="(hex, nombre) in tienda.colores"
                            :key="nombre"
                            class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm font-medium transition-colors"
                            :class="form.color === nombre
                                ? 'border-neutral-900 bg-stone-50 dark:border-neutral-100 dark:bg-neutral-800'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-800 dark:hover:bg-neutral-800'"
                        >
                            <input v-model="form.color" type="radio" name="color" :value="nombre" class="sr-only" />
                            <span class="size-5 rounded-full ring-1 ring-black/10" :style="{ backgroundColor: hex }" />
                            {{ COLORES[nombre] ?? nombre }}
                        </label>
                    </div>
                </fieldset>

                <h3 class="mt-6 text-sm font-semibold tracking-tight">Qué se muestra</h3>
                <div class="mt-2 divide-y divide-stone-100 rounded-xl border border-stone-200 dark:divide-neutral-800 dark:border-neutral-800">
                    <label class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
                        <span>
                            <span class="block text-sm font-medium">Mostrar precios</span>
                            <span class="block text-xs text-neutral-500 dark:text-neutral-400">
                                {{ form.mostrar_precios ? 'Cualquiera puede ver tus precios.' : 'En lugar del precio dirá "Consultar precio".' }}
                            </span>
                        </span>
                        <input v-model="form.mostrar_precios" type="checkbox" class="peer sr-only" />
                        <span :class="claseInterruptor" aria-hidden="true" />
                    </label>
                    <label class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
                        <span>
                            <span class="block text-sm font-medium">Mostrar disponibilidad</span>
                            <span class="block text-xs text-neutral-500 dark:text-neutral-400">
                                Marca como "Agotado" lo que no tiene stock. Nunca muestra cuántas unidades hay.
                            </span>
                        </span>
                        <input v-model="form.mostrar_stock" type="checkbox" class="peer sr-only" />
                        <span :class="claseInterruptor" aria-hidden="true" />
                    </label>
                </div>
            </section>

            <!-- Contactos -->
            <section :class="[claseTarjeta, 'p-5']">
                <h2 class="font-semibold tracking-tight">Contactos</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Lo que llenes aparece en tu tienda. Los pedidos llegan a tu WhatsApp.</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="claseLabel" for="tienda_whatsapp">WhatsApp de pedidos</label>
                        <input id="tienda_whatsapp" v-model="form.whatsapp" type="tel" inputmode="tel" maxlength="20" :class="claseInput" placeholder="987 654 321" />
                        <p v-if="form.errors.whatsapp" :class="claseError">{{ form.errors.whatsapp }}</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="tienda_telefono">Teléfono <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_telefono" v-model="form.telefono" type="tel" inputmode="tel" maxlength="30" :class="claseInput" placeholder="(01) 234 5678" />
                        <p v-if="form.errors.telefono" :class="claseError">{{ form.errors.telefono }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="tienda_email">Correo <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_email" v-model="form.email" type="email" maxlength="150" :class="claseInput" placeholder="ventas@tunegocio.com" />
                        <p v-if="form.errors.email" :class="claseError">{{ form.errors.email }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="tienda_direccion">Dirección <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_direccion" v-model="form.direccion" type="text" maxlength="250" :class="claseInput" placeholder="Av. Principal 123, tu distrito" />
                        <p :class="claseAyuda">Sale con un enlace al mapa. Tus sucursales con dirección también aparecen.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="tienda_horario">Horario de atención <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_horario" v-model="form.horario" type="text" maxlength="200" :class="claseInput" placeholder="Lunes a sábado, 8 a. m. a 6 p. m." />
                    </div>
                    <div>
                        <label :class="claseLabel" for="tienda_facebook">Facebook <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_facebook" v-model="form.facebook" type="text" maxlength="150" :class="claseInput" placeholder="tunegocio" />
                    </div>
                    <div>
                        <label :class="claseLabel" for="tienda_instagram">Instagram <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_instagram" v-model="form.instagram" type="text" maxlength="150" :class="claseInput" placeholder="@tunegocio" />
                    </div>
                    <div>
                        <label :class="claseLabel" for="tienda_tiktok">TikTok <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <input id="tienda_tiktok" v-model="form.tiktok" type="text" maxlength="150" :class="claseInput" placeholder="@tunegocio" />
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3 border-t border-stone-200 pt-4 dark:border-neutral-800">
                    <span v-if="form.isDirty" class="text-xs text-neutral-500 dark:text-neutral-400">Tienes cambios sin guardar</span>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-10 items-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </section>
        </form>

        <!-- Productos de la tienda -->
        <section :class="[claseTarjeta, 'mt-4 overflow-hidden']" aria-label="Productos de la tienda">
            <div class="flex flex-col gap-3 border-b border-stone-200 p-5 dark:border-neutral-800">
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <div>
                        <h2 class="font-semibold tracking-tight">Productos de la tienda</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">
                            Todos tus productos activos aparecen. Oculta los que no quieras mostrar y marca con la estrella los destacados.
                        </p>
                    </div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300" data-resumen-tienda>
                        <span class="font-semibold">{{ resumen.visibles }}</span> visibles ·
                        <span class="font-semibold">{{ resumen.destacados }}</span> de {{ maxDestacados }} destacados
                        <template v-if="resumen.ocultos"> · <span class="font-semibold">{{ resumen.ocultos }}</span> ocultos</template>
                    </p>
                </div>

                <p v-if="resumen.sin_foto > 0" class="flex items-start gap-2 rounded-xl bg-amber-50 px-3.5 py-2.5 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <ImageOff class="mt-0.5 size-4 shrink-0" />
                    <span>
                        {{ resumen.sin_foto }} producto{{ resumen.sin_foto === 1 ? '' : 's' }} visible{{ resumen.sin_foto === 1 ? '' : 's' }} no tiene{{ resumen.sin_foto === 1 ? '' : 'n' }} foto.
                        Una tienda con fotos vende más: agrégalas en <Link href="/productos" class="font-semibold underline underline-offset-2">Productos</Link>.
                    </span>
                </p>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="relative w-full sm:max-w-xs">
                        <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="buscar"
                            type="search"
                            placeholder="Buscar producto..."
                            aria-label="Buscar producto"
                            class="h-10 w-full rounded-xl border border-stone-200 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-950 dark:placeholder-neutral-500"
                        />
                    </div>
                    <div class="flex gap-1 rounded-xl bg-stone-100 p-1 dark:bg-neutral-800" role="tablist" aria-label="Qué productos ver">
                        <button
                            v-for="vista in VISTAS"
                            :key="vista.valor"
                            type="button"
                            role="tab"
                            :aria-selected="ver === vista.valor"
                            class="h-8 flex-1 rounded-lg px-3.5 text-sm font-medium whitespace-nowrap transition-colors sm:flex-none"
                            :class="ver === vista.valor ? 'bg-white shadow-sm dark:bg-neutral-950' : 'text-neutral-500 dark:text-neutral-400'"
                            @click="verVista(vista.valor)"
                        >
                            {{ vista.label }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="!productos.data.length" class="px-5 py-12 text-center text-sm text-neutral-500 dark:text-neutral-400">
                <Package class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                {{ ver === 'destacados' ? 'Aún no destacas ningún producto. Mientras tanto, la tienda muestra los que más vendes.' : ver === 'ocultos' ? 'No tienes productos ocultos.' : 'No hay productos que mostrar.' }}
            </p>

            <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                <li
                    v-for="p in productos.data"
                    :key="p.id"
                    class="flex items-center gap-3 px-5 py-3 transition-opacity"
                    :class="guardando === p.id ? 'opacity-60' : ''"
                >
                    <div class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-xl border border-stone-200 bg-white dark:border-neutral-700" :class="p.en_tienda ? '' : 'opacity-40'">
                        <img v-if="p.imagen" :src="p.imagen" :alt="p.nombre" class="size-full object-contain" loading="lazy" />
                        <Package v-else class="size-5 text-neutral-300" />
                    </div>

                    <div class="min-w-0 flex-1" :class="p.en_tienda ? '' : 'opacity-50'">
                        <p class="truncate text-sm font-medium">{{ p.nombre }}</p>
                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                            {{ p.codigo }}<template v-if="p.categoria"> · {{ p.categoria }}</template>
                            <template v-if="p.precio !== null"> · {{ soles(p.precio) }}</template>
                            <span v-if="p.sin_presentacion" class="text-amber-600 dark:text-amber-400"> · sin presentación a la venta: no aparece</span>
                            <span v-else-if="!p.en_tienda"> · oculto</span>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="hidden h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-neutral-500 hover:bg-stone-100 hover:text-neutral-900 sm:inline-flex dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        :title="p.descripcion ? 'Editar la descripción' : 'Agregar una descripción para su página'"
                        @click="editarDescripcion(p)"
                    >
                        <Pencil class="size-3.5" />
                        {{ p.descripcion ? 'Descripción' : 'Agregar descripción' }}
                    </button>
                    <button
                        type="button"
                        class="grid size-9 shrink-0 place-items-center rounded-lg text-neutral-500 hover:bg-stone-100 sm:hidden dark:text-neutral-400 dark:hover:bg-neutral-800"
                        :aria-label="`Descripción de ${p.nombre}`"
                        @click="editarDescripcion(p)"
                    >
                        <Pencil class="size-4" />
                    </button>

                    <button
                        type="button"
                        class="grid size-9 shrink-0 place-items-center rounded-lg transition-colors hover:bg-amber-50 dark:hover:bg-amber-500/10"
                        :aria-pressed="p.destacado"
                        :aria-label="p.destacado ? `Quitar ${p.nombre} de destacados` : `Destacar ${p.nombre}`"
                        :title="p.destacado ? 'Quitar de destacados' : 'Destacar en la portada'"
                        :disabled="guardando === p.id"
                        @click="cambiar(p, { destacado: !p.destacado })"
                    >
                        <Star class="size-5" :class="p.destacado ? 'fill-amber-400 text-amber-400' : 'text-neutral-300 dark:text-neutral-600'" />
                    </button>

                    <button
                        type="button"
                        class="grid size-9 shrink-0 place-items-center rounded-lg transition-colors hover:bg-stone-100 dark:hover:bg-neutral-800"
                        :aria-pressed="p.en_tienda"
                        :aria-label="p.en_tienda ? `Ocultar ${p.nombre} de la tienda` : `Mostrar ${p.nombre} en la tienda`"
                        :title="p.en_tienda ? 'Visible en la tienda: clic para ocultar' : 'Oculto: clic para mostrar'"
                        :disabled="guardando === p.id"
                        @click="cambiar(p, { en_tienda: !p.en_tienda })"
                    >
                        <Eye v-if="p.en_tienda" class="size-5 text-emerald-600 dark:text-emerald-400" />
                        <EyeOff v-else class="size-5 text-neutral-400" />
                    </button>
                </li>
            </ul>

            <div
                v-if="productos.last_page > 1"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-5 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>{{ productos.from }}–{{ productos.to }} de {{ productos.total }}</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in productos.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            preserve-state
                            :only="['productos', 'filtros']"
                            class="rounded-lg border px-3.5 py-1.5"
                            :class="link.active
                                ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            v-html="link.label"
                        />
                        <span v-else class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700" v-html="link.label" />
                    </template>
                </div>
            </div>
        </section>

        <!-- Modal: descripción del producto -->
        <Teleport to="body">
            <div v-if="editando" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="editando = null" />
                <form
                    class="relative w-full max-w-lg rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarDescripcion"
                >
                    <div class="mb-1 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold tracking-tight">Descripción para la tienda</h3>
                            <p class="truncate text-sm text-neutral-500 dark:text-neutral-400">{{ editando.nombre }}</p>
                        </div>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="editando = null">
                            <X class="size-5" />
                        </button>
                    </div>

                    <label for="producto_descripcion" class="sr-only">Descripción</label>
                    <textarea
                        id="producto_descripcion"
                        v-model="formDescripcion.descripcion"
                        rows="7"
                        maxlength="1500"
                        placeholder="Para qué sirve, cómo se usa, qué incluye, medidas..."
                        class="mt-3 w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                    />
                    <p v-if="formDescripcion.errors.descripcion" :class="claseError">{{ formDescripcion.errors.descripcion }}</p>
                    <p v-else :class="claseAyuda">{{ formDescripcion.descripcion.length }} de 1500. Sale en la página del producto. Puedes dejarla vacía.</p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="editando = null">Cancelar</button>
                        <button type="submit" :disabled="formDescripcion.processing" class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ formDescripcion.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
