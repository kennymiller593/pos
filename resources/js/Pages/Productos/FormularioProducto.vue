<script setup>
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { ImagePlus, LoaderCircle, Package, Plus, Trash2, X } from '@lucide/vue'
import { usePermisos } from '@/composables/permisos'
import { optimizarImagenProducto } from '@/composables/imagenProducto'

const props = defineProps({
    abierto: { type: Boolean, default: false },
    producto: { type: Object, default: null },
    catalogos: { type: Object, required: true },
})

const emit = defineEmits(['cerrar'])

// Nuevo RUS: no discrimina IGV, sus productos van como exonerados (el servidor tambien lo fuerza)
const page = usePage()
const esRus = computed(() => page.props.auth?.user?.empresa?.regimen_tributario === 'RUS')

// al editar, los precios de las presentaciones existentes solo los cambia quien tiene productos.precios
const { puede } = usePermisos()
const preciosBloqueados = computed(() => !!props.producto && !puede('productos.precios'))
const precioBloqueado = (pres) => preciosBloqueados.value && pres.id !== null

const form = useForm({
    codigo_interno: '',
    nombre: '',
    categoria_id: '',
    marca_id: '',
    unidad_base_codigo: '',
    tipo_afectacion_codigo: '',
    permite_fraccion: false,
    controla_lote: false,
    controla_stock: true,
    stock_minimo: '',
    activo: true,
    imagen: null,
    imagen_eliminar: false,
    presentaciones: [],
})

// ---- imagen ----
const inputImagen = ref(null)
const optimizando = ref(false)

const vistaPrevia = computed(() => {
    if (form.imagen) return URL.createObjectURL(form.imagen)
    if (props.producto?.imagen_url && !form.imagen_eliminar) return props.producto.imagen_url
    return null
})

// la foto se ajusta a 4:3 y máx. 800x600 antes de subirla, para que todas se vean parejas
async function seleccionarImagen(evento) {
    const archivo = evento.target.files?.[0]
    if (!archivo) return
    optimizando.value = true
    try {
        form.imagen = await optimizarImagenProducto(archivo)
        form.imagen_eliminar = false
        form.clearErrors('imagen')
    } finally {
        optimizando.value = false
    }
}

function quitarImagen() {
    form.imagen = null
    form.imagen_eliminar = !!props.producto?.imagen_url
    if (inputImagen.value) inputImagen.value.value = ''
}

function presentacionNueva(esDefault = false) {
    return {
        id: null,
        nombre: esDefault ? 'Unidad' : '',
        unidad_codigo: form.unidad_base_codigo || unidadPorDefecto(),
        factor_conversion: 1,
        precio_venta: '',
        precio_mayorista: '',
        cantidad_mayorista: '',
        codigo_barras: '',
        es_default: esDefault,
    }
}

// un producto nuevo arranca en "Unidad" (NIU, catalogo SUNAT); si no existiera, la primera de la lista
const unidadPorDefecto = () =>
    props.catalogos.unidades.find((u) => u.codigo === 'NIU')?.codigo ?? props.catalogos.unidades[0]?.codigo ?? ''

function cargar() {
    const p = props.producto
    form.clearErrors()
    form.codigo_interno = p?.codigo_interno ?? ''
    form.nombre = p?.nombre ?? ''
    form.categoria_id = p?.categoria_id ?? ''
    form.marca_id = p?.marca_id ?? ''
    form.unidad_base_codigo = p?.unidad_base_codigo ?? unidadPorDefecto()
    form.tipo_afectacion_codigo = p?.tipo_afectacion_codigo ?? (esRus.value ? '20' : props.catalogos.tiposAfectacion[0]?.codigo ?? '')
    form.permite_fraccion = p?.permite_fraccion ?? false
    form.controla_lote = p?.controla_lote ?? false
    form.controla_stock = p?.controla_stock ?? true
    form.stock_minimo = p?.stock_minimo ?? ''
    form.activo = p?.activo ?? true
    form.imagen = null
    form.imagen_eliminar = false
    if (inputImagen.value) inputImagen.value.value = ''
    form.presentaciones = p?.presentaciones?.length
        ? p.presentaciones.map((x) => ({
              id: x.id,
              nombre: x.nombre,
              unidad_codigo: x.unidad_codigo,
              factor_conversion: Number(x.factor_conversion),
              precio_venta: Number(x.precio_venta),
              precio_mayorista: x.precio_mayorista === null ? '' : Number(x.precio_mayorista),
              cantidad_mayorista: x.cantidad_mayorista === null ? '' : Number(x.cantidad_mayorista),
              codigo_barras: x.codigo_barras ?? '',
              es_default: x.es_default,
          }))
        : [presentacionNueva(true)]
}

watch(() => props.abierto, (abierto) => abierto && cargar())

function agregarPresentacion() {
    form.presentaciones.push(presentacionNueva(form.presentaciones.length === 0))
}

function quitarPresentacion(indice) {
    const eraDefault = form.presentaciones[indice].es_default
    form.presentaciones.splice(indice, 1)
    if (eraDefault && form.presentaciones.length) {
        form.presentaciones[0].es_default = true
    }
}

function marcarDefault(indice) {
    form.presentaciones.forEach((p, i) => (p.es_default = i === indice))
}

function enviar() {
    const transformar = (data) => ({
        ...data,
        categoria_id: data.categoria_id || null,
        marca_id: data.marca_id || null,
        stock_minimo: data.stock_minimo === '' ? null : data.stock_minimo,
        presentaciones: data.presentaciones.map((p) => ({
            ...p,
            precio_mayorista: p.precio_mayorista === '' ? null : p.precio_mayorista,
            cantidad_mayorista: p.cantidad_mayorista === '' ? null : p.cantidad_mayorista,
            codigo_barras: p.codigo_barras || null,
        })),
    })

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('cerrar'),
    }

    if (props.producto) {
        // con archivos, Inertia debe enviar POST + _method para que llegue el multipart
        form.transform((data) => ({ ...transformar(data), _method: 'put' })).post(`/productos/${props.producto.id}`, opciones)
    } else {
        form.transform(transformar).post('/productos', opciones)
    }
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseBloqueado = 'cursor-not-allowed bg-stone-100 text-neutral-500 focus:border-stone-300 focus:ring-0 dark:bg-neutral-800 dark:text-neutral-400'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />

            <div class="relative mx-auto my-8 w-full max-w-3xl px-4">
                <form
                    class="rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="enviar"
                >
                    <!-- Encabezado -->
                    <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <h2 class="text-lg font-semibold tracking-tight">
                            {{ producto ? 'Editar producto' : 'Nuevo producto' }}
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="emit('cerrar')"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto px-6 py-5">
                        <!-- Imagen -->
                        <div class="mb-5 flex items-center gap-4">
                            <div class="grid aspect-[4/3] w-24 shrink-0 place-items-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-950">
                                <LoaderCircle v-if="optimizando" class="size-6 animate-spin text-neutral-400" />
                                <img v-else-if="vistaPrevia" :src="vistaPrevia" alt="" class="size-full object-contain" />
                                <Package v-else class="size-8 text-neutral-300 dark:text-neutral-600" />
                            </div>
                            <div>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-stone-300 px-3 py-1.5 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        :disabled="optimizando"
                                        @click="inputImagen?.click()"
                                    >
                                        <ImagePlus class="size-4" />
                                        {{ optimizando ? 'Optimizando…' : vistaPrevia ? 'Cambiar imagen' : 'Subir imagen' }}
                                    </button>
                                    <button
                                        v-if="vistaPrevia"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        @click="quitarImagen"
                                    >
                                        <Trash2 class="size-4" />
                                        Quitar
                                    </button>
                                </div>
                                <p class="mt-1.5 text-xs text-neutral-400 dark:text-neutral-500">JPG, PNG o WEBP (opcional) · se ajusta sola para verse bien en el POS</p>
                                <p v-if="form.errors.imagen" :class="claseError">{{ form.errors.imagen }}</p>
                            </div>
                            <input ref="inputImagen" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="seleccionarImagen" />
                        </div>

                        <!-- Datos generales -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="codigo_interno">Código interno{{ producto ? ' *' : '' }}</label>
                                <input
                                    v-if="producto"
                                    id="codigo_interno"
                                    v-model="form.codigo_interno"
                                    type="text"
                                    :class="claseInput"
                                />
                                <input
                                    v-else
                                    id="codigo_interno"
                                    :value="catalogos.siguienteCodigo"
                                    type="text"
                                    readonly
                                    :class="[claseInput, 'cursor-not-allowed bg-stone-100 font-mono dark:bg-neutral-800']"
                                />
                                <p v-if="!producto" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Se asigna automáticamente al guardar.</p>
                                <p v-if="form.errors.codigo_interno" :class="claseError">{{ form.errors.codigo_interno }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="nombre">Nombre *</label>
                                <input id="nombre" v-model="form.nombre" type="text" :class="claseInput" placeholder="Coca Cola 500ml" />
                                <p v-if="form.errors.nombre" :class="claseError">{{ form.errors.nombre }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="categoria">Categoría</label>
                                <select id="categoria" v-model="form.categoria_id" :class="claseInput">
                                    <option value="">Sin categoría</option>
                                    <option v-for="c in catalogos.categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="claseLabel" for="marca">Marca</label>
                                <select id="marca" v-model="form.marca_id" :class="claseInput">
                                    <option value="">Sin marca</option>
                                    <option v-for="m in catalogos.marcas" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="claseLabel" for="unidad_base">Unidad base *</label>
                                <select id="unidad_base" v-model="form.unidad_base_codigo" :class="claseInput">
                                    <option v-for="u in catalogos.unidades" :key="u.codigo" :value="u.codigo">{{ u.nombre }}</option>
                                </select>
                                <p v-if="form.errors.unidad_base_codigo" :class="claseError">{{ form.errors.unidad_base_codigo }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="afectacion">Afectación IGV *</label>
                                <select id="afectacion" v-model="form.tipo_afectacion_codigo" :class="claseInput">
                                    <option v-for="t in catalogos.tiposAfectacion" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                                </select>
                                <p v-if="esRus" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Nuevo RUS: se vende sin IGV (exonerado).</p>
                                <p v-if="form.errors.tipo_afectacion_codigo" :class="claseError">{{ form.errors.tipo_afectacion_codigo }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="stock_minimo">Stock mínimo</label>
                                <input id="stock_minimo" v-model="form.stock_minimo" type="number" step="0.001" min="0" :class="claseInput" placeholder="0" />
                            </div>
                            <div class="flex flex-wrap items-end gap-x-5 gap-y-2 pb-1">
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="form.controla_stock" type="checkbox" class="size-4 rounded accent-emerald-600" />
                                    Controla stock
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="form.controla_lote" type="checkbox" class="size-4 rounded accent-emerald-600" />
                                    Controla lote
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="form.permite_fraccion" type="checkbox" class="size-4 rounded accent-emerald-600" />
                                    Permite fracción
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="form.activo" type="checkbox" class="size-4 rounded accent-emerald-600" />
                                    Activo
                                </label>
                            </div>
                        </div>

                        <!-- Presentaciones -->
                        <div class="mt-6">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-sm font-semibold tracking-tight">Presentaciones y precios</h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                                    @click="agregarPresentacion"
                                >
                                    <Plus class="size-4" />
                                    Agregar
                                </button>
                            </div>
                            <p v-if="preciosBloqueados" class="mb-1 text-xs text-amber-700 dark:text-amber-400">
                                Solo un administrador puede cambiar precios.
                            </p>
                            <p v-if="form.errors.presentaciones" :class="claseError">{{ form.errors.presentaciones }}</p>

                            <div
                                v-for="(pres, i) in form.presentaciones"
                                :key="pres.id ?? `nueva-${i}`"
                                class="mt-3 rounded-xl border border-stone-200 p-4 dark:border-neutral-800"
                            >
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label :class="claseLabel">Nombre *</label>
                                        <input v-model="pres.nombre" type="text" :class="claseInput" placeholder="Unidad, Six pack..." />
                                        <p v-if="form.errors[`presentaciones.${i}.nombre`]" :class="claseError">{{ form.errors[`presentaciones.${i}.nombre`] }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel">Unidad *</label>
                                        <select v-model="pres.unidad_codigo" :class="claseInput">
                                            <option v-for="u in catalogos.unidades" :key="u.codigo" :value="u.codigo">{{ u.nombre }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="claseLabel">Factor (unid. base) *</label>
                                        <input v-model="pres.factor_conversion" type="number" step="0.0001" min="0" :class="claseInput" />
                                        <p v-if="form.errors[`presentaciones.${i}.factor_conversion`]" :class="claseError">{{ form.errors[`presentaciones.${i}.factor_conversion`] }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel">Precio venta (S/) *</label>
                                        <input
                                            v-model="pres.precio_venta"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :readonly="precioBloqueado(pres)"
                                            :class="[claseInput, precioBloqueado(pres) && claseBloqueado]"
                                        />
                                        <p v-if="form.errors[`presentaciones.${i}.precio_venta`]" :class="claseError">{{ form.errors[`presentaciones.${i}.precio_venta`] }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel">Precio mayorista (S/)</label>
                                        <input
                                            v-model="pres.precio_mayorista"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :readonly="precioBloqueado(pres)"
                                            :class="[claseInput, precioBloqueado(pres) && claseBloqueado]"
                                            placeholder="Opcional"
                                        />
                                        <p v-if="form.errors[`presentaciones.${i}.precio_mayorista`]" :class="claseError">{{ form.errors[`presentaciones.${i}.precio_mayorista`] }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel">Mayorista desde (cant.)</label>
                                        <input
                                            v-model="pres.cantidad_mayorista"
                                            type="number"
                                            step="1"
                                            min="1"
                                            :class="claseInput"
                                            placeholder="Ej. 12"
                                        />
                                        <p class="mt-1 text-[11px] text-neutral-400 dark:text-neutral-500">
                                            Con esa cantidad o más, el POS cobra el precio mayorista solo.
                                        </p>
                                        <p v-if="form.errors[`presentaciones.${i}.cantidad_mayorista`]" :class="claseError">{{ form.errors[`presentaciones.${i}.cantidad_mayorista`] }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel">Código de barras</label>
                                        <input v-model="pres.codigo_barras" type="text" :class="claseInput" />
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center justify-between">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="presentacion_default"
                                            :checked="pres.es_default"
                                            class="size-4 accent-emerald-600"
                                            @change="marcarDefault(i)"
                                        />
                                        Presentación por defecto
                                    </label>
                                    <button
                                        v-if="form.presentaciones.length > 1"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        @click="quitarPresentacion(i)"
                                    >
                                        <Trash2 class="size-4" />
                                        Quitar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pie -->
                    <div class="flex justify-end gap-2 border-t border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="emit('cerrar')"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing || optimizando"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ form.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
