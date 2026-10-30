<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, Check, FlaskConical, Info, MapPin, Minus, Package, Plus, Search, Trash2, Wand2, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    // [{ id, nombre, codigo_interno, controla_lote, permite_fraccion, imagen_url, stock, costo, presentaciones[{ id, nombre, factor_conversion, precio_venta, codigo_barras, es_default }] }]
    productos: { type: Array, required: true },
    // { producto_id: { rinde_base, otros_costos, insumos[{ producto_id, presentacion_id, cantidad_base }] } }
    recetas: { type: Object, default: () => ({}) },
    siguienteCodigo: { type: String, required: true },
    sucursalNombre: { type: String, default: null },
    // viendo "todas las sucursales": se elige aquí dónde se prepara
    sucursales: { type: Array, default: () => [] },
    sucursalId: { type: String, default: null },
    verCostos: { type: Boolean, default: false },
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
const redondear = (n) => Math.round(n * 1000) / 1000

const form = useForm({
    sucursal_id: props.sucursalId,
    producto_id: null,
    presentacion_id: null,
    cantidad: 1,
    numero_lote: '',
    fecha_vencimiento: '',
    otros_costos: '',
    observacion: '',
    guardar_formula: false,
    insumos: [],
})

// ---- búsqueda (nombre, código interno o código de barras; sin tildes ni mayúsculas) ----
const normalizar = (t) => String(t ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
function buscarEn(texto, excluirIds = []) {
    const t = normalizar(texto.trim())
    if (!t) return []
    return props.productos
        .filter((p) => !excluirIds.includes(p.id))
        .filter((p) => normalizar(p.nombre).includes(t) || normalizar(p.codigo_interno).includes(t) || p.presentaciones.some((pr) => pr.codigo_barras === texto.trim()))
        .slice(0, 8)
}

// ---- 1. producto obtenido ----
const buscarProducto = ref('')
const producto = ref(null)
const resultadosProducto = computed(() => buscarEn(buscarProducto.value, filas.value.map((f) => f.producto.id)))
const presentacionProducto = computed(() => producto.value?.presentaciones.find((p) => p.id === form.presentacion_id) ?? null)
const factorProducto = computed(() => presentacionProducto.value?.factor_conversion ?? 1)
// en unidad base: lo que entra al stock
const cantidadBase = computed(() => redondear(Number(form.cantidad || 0) * factorProducto.value))
const receta = computed(() => (producto.value ? props.recetas[producto.value.id] ?? null : null))

function elegirProducto(p) {
    producto.value = p
    form.producto_id = p.id
    form.presentacion_id = (p.presentaciones.find((x) => x.es_default) ?? p.presentaciones[0]).id
    form.numero_lote = p.controla_lote ? props.siguienteCodigo : ''
    form.fecha_vencimiento = ''
    buscarProducto.value = ''
    // si ya tiene fórmula guardada, propone la tanda guardada y la aplica de inmediato
    const r = props.recetas[p.id]
    if (r) {
        const factor = p.presentaciones.find((x) => x.id === form.presentacion_id)?.factor_conversion ?? 1
        form.cantidad = redondear(r.rinde_base / factor) || 1
        aplicarFormula()
    }
}
function quitarProducto() {
    producto.value = null
    form.producto_id = null
    form.presentacion_id = null
    usandoFormula.value = false
}

// ---- 2. insumos ----
const buscarInsumo = ref('')
const filas = ref([]) // { producto, presentacion_id, cantidad }
const resultadosInsumo = computed(() => buscarEn(buscarInsumo.value, [producto.value?.id, ...filas.value.map((f) => f.producto.id)].filter(Boolean)))

function agregarInsumo(p, presentacionId = null, cant = 1) {
    let fila = filas.value.find((f) => f.producto.id === p.id)
    if (fila) {
        fila.cantidad = redondear(Number(fila.cantidad) + cant)
    } else {
        fila = { producto: p, presentacion_id: presentacionId ?? (p.presentaciones.find((x) => x.es_default) ?? p.presentaciones[0]).id, cantidad: cant }
        filas.value.push(fila)
    }
    buscarInsumo.value = ''
    return fila
}
const presentacionDe = (fila) => fila.producto.presentaciones.find((p) => p.id === fila.presentacion_id) ?? fila.producto.presentaciones[0]
const factorDe = (fila) => presentacionDe(fila)?.factor_conversion ?? 1
const baseDe = (fila) => redondear(Number(fila.cantidad || 0) * factorDe(fila))
const excedeStock = (fila) => baseDe(fila) > fila.producto.stock + 0.0005
// costo estimado del insumo: el promedio de lo que queda en stock (el real sale del FIFO al confirmar)
const costoDe = (fila) => (fila.producto.costo == null ? null : baseDe(fila) * fila.producto.costo)

function cambiarCantidad(fila, delta) {
    const nueva = redondear(Number(fila.cantidad || 0) + delta)
    if (nueva > 0) fila.cantidad = nueva
}
function cambiarCantidadProducto(delta) {
    const nueva = redondear(Number(form.cantidad || 0) + delta)
    if (nueva > 0) form.cantidad = nueva
}

// ---- fórmula guardada: con la cantidad a obtener, los insumos se calculan solos ----
const usandoFormula = ref(false)
let aplicando = false // los cambios que hace la propia fórmula no cuentan como edición a mano
function aplicarFormula() {
    const r = receta.value
    if (!r || !r.rinde_base) return
    const escala = cantidadBase.value / r.rinde_base
    aplicando = true
    filas.value = r.insumos.flatMap((i) => {
        const p = props.productos.find((x) => x.id === i.producto_id)
        if (!p) return []
        const pres = p.presentaciones.find((x) => x.id === i.presentacion_id) ?? p.presentaciones.find((x) => x.es_default) ?? p.presentaciones[0]
        return [{ producto: p, presentacion_id: pres.id, cantidad: redondear((i.cantidad_base * escala) / pres.factor_conversion) }]
    })
    form.otros_costos = r.otros_costos > 0 ? redondear(r.otros_costos * escala) : ''
    usandoFormula.value = true
    nextTick(() => (aplicando = false))
}
// mientras se usa la fórmula, cambiar la cantidad a obtener recalcula los insumos
watch(() => [form.cantidad, form.presentacion_id], () => { if (usandoFormula.value) aplicarFormula() })
// tocar los insumos a mano desactiva el recálculo automático (la fórmula se puede volver a aplicar)
watch(filas, () => { if (!aplicando) usandoFormula.value = false }, { deep: true })

// ---- resumen ----
const costoInsumos = computed(() => (props.verCostos ? filas.value.reduce((s, f) => s + (costoDe(f) ?? 0), 0) : null))
const otrosCostos = computed(() => Number(form.otros_costos || 0))
const costoTotal = computed(() => (costoInsumos.value == null ? null : costoInsumos.value + otrosCostos.value))
const costoUnitario = computed(() => (costoTotal.value == null || cantidadBase.value <= 0 ? null : costoTotal.value / cantidadBase.value))
// costo por presentación elegida vs. su precio de venta
const costoPorPresentacion = computed(() => (costoUnitario.value == null ? null : costoUnitario.value * factorProducto.value))
const margen = computed(() => {
    const precio = presentacionProducto.value?.precio_venta
    if (!precio || costoPorPresentacion.value == null) return null
    return ((precio - costoPorPresentacion.value) / precio) * 100
})

const motivoNoGuardar = computed(() => {
    if (!producto.value) return 'Elige el producto que se obtiene.'
    if (!(Number(form.cantidad) > 0)) return 'Indica cuánto se obtiene.'
    if (producto.value.controla_lote && !form.numero_lote.trim()) return 'Indica el número de lote de la tanda.'
    if (!filas.value.length) return 'Agrega al menos un insumo.'
    if (filas.value.some((f) => !(Number(f.cantidad) > 0))) return 'Hay insumos con cantidad 0.'
    const sinStock = filas.value.find(excedeStock)
    if (sinStock) return `No hay suficiente stock de ${sinStock.producto.nombre}.`
    return ''
})
const puedeGuardar = computed(() => motivoNoGuardar.value === '')

function guardar() {
    // con el selector en una sucursal, siempre la vigente (no la que tenía el formulario al abrirse)
    if (!props.sucursales.length) form.sucursal_id = props.sucursalId
    form.insumos = filas.value.map((f) => ({ producto_id: f.producto.id, presentacion_id: f.presentacion_id, cantidad: Number(f.cantidad) }))
    form.transform((data) => ({
        ...data,
        cantidad: Number(data.cantidad),
        numero_lote: data.numero_lote.trim() || null,
        fecha_vencimiento: data.fecha_vencimiento || null,
        otros_costos: data.otros_costos === '' ? 0 : Number(data.otros_costos),
        observacion: data.observacion.trim() || null,
    })).post('/mezclas')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseGrupo =
    'flex h-10 items-center overflow-hidden rounded-xl border border-stone-300 bg-white transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/20 dark:border-neutral-700 dark:bg-neutral-950'
const claseBotonPaso =
    'grid h-full w-10 shrink-0 place-items-center text-[#64748B] transition-colors hover:bg-slate-100 hover:text-[#0F172A] active:bg-slate-200 disabled:pointer-events-none disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
const claseNumeroGrupo =
    'h-full w-full min-w-0 flex-1 bg-transparent text-center text-sm font-medium tabular-nums [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900'
const claseTitulo = 'mb-4 flex items-center gap-2.5 font-semibold tracking-tight'
const claseNumero = 'grid size-6 place-items-center rounded-lg bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
const claseResultados = 'absolute top-12 right-0 left-0 z-10 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800'
const claseResultado = 'flex w-full items-center gap-3 px-3.5 py-2.5 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-700'
</script>

<template>
    <AppLayout titulo="Preparar mezcla">
        <Link
            href="/mezclas"
            class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100"
        >
            <ArrowLeft class="size-4" />
            Volver a mezclas
        </Link>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="min-w-0 space-y-6">
                <!-- ============ 1. Producto obtenido ============ -->
                <section :class="claseTarjeta">
                    <h2 :class="claseTitulo"><span :class="claseNumero">1</span> ¿Qué se obtiene?</h2>

                    <div v-if="!producto" class="relative">
                        <!-- centrado en el campo (h-11), no en todo el bloque: debajo va el texto de ayuda -->
                        <Search class="pointer-events-none absolute top-[1.375rem] left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="buscarProducto"
                            type="text"
                            placeholder="Busca el producto que se obtiene (nombre o código)..."
                            :class="[claseInput, 'h-11 pl-10']"
                            data-buscar-producto
                            @keydown.enter.prevent="resultadosProducto.length === 1 && elegirProducto(resultadosProducto[0])"
                        />
                        <div v-if="resultadosProducto.length" :class="claseResultados">
                            <button v-for="p in resultadosProducto" :key="p.id" type="button" :class="claseResultado" @click="elegirProducto(p)">
                                <span class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-lg bg-[#F8FAFC] text-neutral-400 dark:bg-neutral-900">
                                    <img v-if="p.imagen_url" :src="p.imagen_url" :alt="p.nombre" loading="lazy" class="size-full bg-white object-contain" />
                                    <Package v-else class="size-4" />
                                </span>
                                <span class="min-w-0 flex-1 truncate font-medium">{{ p.nombre }}</span>
                                <span v-if="recetas[p.id]" class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">Con fórmula</span>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                            El producto que se obtiene debe existir en <Link href="/productos" class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">Productos</Link>, con su precio de venta.
                        </p>
                        <p v-if="form.errors.producto_id" :class="claseError">{{ form.errors.producto_id }}</p>
                    </div>

                    <div v-else>
                        <div class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20" data-producto-elegido>
                            <div class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-white text-emerald-600 dark:bg-neutral-900 dark:text-emerald-400">
                                <img v-if="producto.imagen_url" :src="producto.imagen_url" :alt="producto.nombre" class="size-full bg-white object-contain" />
                                <FlaskConical v-else class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ producto.nombre }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Stock actual: {{ cantidad(producto.stock) }}<template v-if="presentacionProducto?.precio_venta"> · se vende a {{ soles(presentacionProducto.precio_venta) }}</template></p>
                            </div>
                            <button type="button" class="grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-white hover:text-neutral-700 dark:hover:bg-neutral-900" aria-label="Cambiar producto" @click="quitarProducto">
                                <X class="size-4" />
                            </button>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label :class="claseLabel">Cantidad que se obtiene *</label>
                                <div class="flex gap-2">
                                    <div :class="[claseGrupo, 'flex-1']">
                                        <button type="button" :class="claseBotonPaso" aria-label="Restar" :disabled="Number(form.cantidad) <= 1" @click="cambiarCantidadProducto(-1)"><Minus class="size-4" /></button>
                                        <input v-model="form.cantidad" type="number" inputmode="decimal" :step="producto.permite_fraccion ? '0.001' : '1'" min="0" aria-label="Cantidad obtenida" :class="claseNumeroGrupo" data-cantidad-producto />
                                        <button type="button" :class="claseBotonPaso" aria-label="Sumar" @click="cambiarCantidadProducto(1)"><Plus class="size-4" /></button>
                                    </div>
                                    <select v-if="producto.presentaciones.length > 1" v-model="form.presentacion_id" aria-label="Presentación" :class="[claseInput, 'w-auto max-w-40']">
                                        <option v-for="pres in producto.presentaciones" :key="pres.id" :value="pres.id">{{ pres.nombre }} (x{{ pres.factor_conversion }})</option>
                                    </select>
                                </div>
                                <p v-if="factorProducto !== 1" class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">= {{ cantidad(cantidadBase) }} unidades al stock</p>
                                <p v-if="form.errors.cantidad" :class="claseError">{{ form.errors.cantidad }}</p>
                            </div>
                            <template v-if="producto.controla_lote">
                                <div>
                                    <label :class="claseLabel" for="lote">N° de lote de la tanda *</label>
                                    <input id="lote" v-model="form.numero_lote" type="text" maxlength="50" :class="claseInput" />
                                </div>
                                <div>
                                    <label :class="claseLabel" for="vence">Vencimiento <span class="font-normal text-neutral-400">(opcional)</span></label>
                                    <input id="vence" v-model="form.fecha_vencimiento" type="date" :class="claseInput" />
                                </div>
                            </template>
                        </div>

                        <div v-if="receta" class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-stone-50 px-3 py-2.5 text-sm dark:bg-neutral-950" data-formula>
                            <Wand2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <span v-if="usandoFormula" class="flex-1">Fórmula guardada aplicada: cambia la cantidad y los insumos se recalculan.</span>
                            <span v-else class="flex-1">Este producto tiene una fórmula guardada.</span>
                            <button v-if="!usandoFormula" type="button" class="font-semibold text-emerald-600 hover:underline dark:text-emerald-400" @click="aplicarFormula">Aplicar fórmula</button>
                        </div>
                    </div>
                </section>

                <!-- ============ 2. Insumos ============ -->
                <section :class="claseTarjeta">
                    <h2 :class="claseTitulo"><span :class="claseNumero">2</span> ¿Qué insumos se usan?</h2>

                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="buscarInsumo"
                            type="text"
                            placeholder="Agregar insumo: busca por nombre, código o código de barras..."
                            :class="[claseInput, 'h-11 pl-10']"
                            data-buscar-insumo
                            @keydown.enter.prevent="resultadosInsumo.length === 1 && agregarInsumo(resultadosInsumo[0])"
                        />
                        <div v-if="resultadosInsumo.length" :class="claseResultados">
                            <button v-for="p in resultadosInsumo" :key="p.id" type="button" :class="claseResultado" @click="agregarInsumo(p)">
                                <span class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-lg bg-[#F8FAFC] text-neutral-400 dark:bg-neutral-900">
                                    <img v-if="p.imagen_url" :src="p.imagen_url" :alt="p.nombre" loading="lazy" class="size-full bg-white object-contain" />
                                    <Package v-else class="size-4" />
                                </span>
                                <span class="min-w-0 flex-1 truncate font-medium">{{ p.nombre }}</span>
                                <span class="shrink-0 text-xs text-neutral-500 dark:text-neutral-400">{{ cantidad(p.stock) }} disp.</span>
                            </button>
                        </div>
                    </div>
                    <p v-if="form.errors.insumos" :class="claseError">{{ form.errors.insumos }}</p>

                    <div v-if="filas.length" class="mt-4 space-y-2">
                        <div
                            v-for="(fila, i) in filas"
                            :key="fila.producto.id"
                            class="rounded-xl bg-stone-50 p-3 dark:bg-neutral-950"
                            data-insumo
                        >
                            <div class="flex items-start gap-3">
                                <div class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-white text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                                    <img v-if="fila.producto.imagen_url" :src="fila.producto.imagen_url" :alt="fila.producto.nombre" class="size-full bg-white object-contain" />
                                    <Package v-else class="size-5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ fila.producto.nombre }}</p>
                                    <p class="text-xs" :class="excedeStock(fila) ? 'font-medium text-red-600 dark:text-red-400' : 'text-neutral-500 dark:text-neutral-400'">
                                        {{ cantidad(fila.producto.stock) }} disponibles<template v-if="factorDe(fila) !== 1"> · usa {{ cantidad(baseDe(fila)) }} unidades</template>
                                        <template v-if="verCostos && costoDe(fila) != null"> · aprox. {{ soles(costoDe(fila)) }}</template>
                                    </p>
                                </div>
                                <button type="button" class="-mt-1 -mr-1 grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400" :aria-label="`Quitar ${fila.producto.nombre}`" @click="filas.splice(i, 1)">
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                            <div class="mt-2.5 flex gap-2">
                                <div :class="[claseGrupo, 'w-44', excedeStock(fila) && '!border-red-400']">
                                    <button type="button" :class="claseBotonPaso" aria-label="Restar" :disabled="Number(fila.cantidad) <= 1" @click="cambiarCantidad(fila, -1)"><Minus class="size-4" /></button>
                                    <input v-model="fila.cantidad" type="number" inputmode="decimal" :step="fila.producto.permite_fraccion ? '0.001' : '1'" min="0" aria-label="Cantidad" :class="claseNumeroGrupo" />
                                    <button type="button" :class="claseBotonPaso" aria-label="Sumar" @click="cambiarCantidad(fila, 1)"><Plus class="size-4" /></button>
                                </div>
                                <select v-if="fila.producto.presentaciones.length > 1" v-model="fila.presentacion_id" aria-label="Presentación" :class="[claseInput, 'min-w-0 flex-1 sm:max-w-48']">
                                    <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">{{ pres.nombre }} (x{{ pres.factor_conversion }})</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-6 text-center text-sm text-neutral-500 dark:text-neutral-400">Aún no agregas insumos.</p>
                </section>

                <!-- ============ 3. Otros datos ============ -->
                <section :class="claseTarjeta">
                    <h2 :class="claseTitulo"><span :class="claseNumero">3</span> Otros datos</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" for="otros">Otros costos <span class="font-normal text-neutral-400">(envases, mano de obra; opcional)</span></label>
                            <div :class="claseGrupo">
                                <span class="pl-3 text-sm text-neutral-500">S/</span>
                                <input id="otros" v-model="form.otros_costos" type="number" inputmode="decimal" step="0.01" min="0" placeholder="0.00" :class="[claseNumeroGrupo, 'pr-3 text-left']" />
                            </div>
                        </div>
                        <div>
                            <label :class="claseLabel" for="observacion">Observación <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <input id="observacion" v-model="form.observacion" type="text" maxlength="200" :class="claseInput" placeholder="Ej. tanda para la campaña de papa" />
                        </div>
                    </div>
                </section>
            </div>

            <!-- ============ Resumen ============ -->
            <aside class="rounded-2xl border border-stone-200 bg-white p-5 lg:sticky lg:top-20 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 class="font-semibold tracking-tight">Resumen</h2>
                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Código: <strong>{{ siguienteCodigo }}</strong></p>

                <label v-if="sucursales.length" class="mt-3 flex items-center gap-2 text-sm">
                    <MapPin class="size-4 shrink-0 text-neutral-400" />
                    <select v-model="form.sucursal_id" class="h-9 min-w-0 flex-1 rounded-lg border border-stone-300 bg-white px-2 text-sm font-semibold focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950" data-mezcla-sucursal>
                        <option v-for="s in sucursales" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </label>
                <p v-else-if="sucursalNombre" class="mt-3 flex items-center gap-2 text-sm" title="La sucursal se cambia con el selector de la barra superior">
                    <MapPin class="size-4 shrink-0 text-neutral-400" />
                    <span>Se prepara en <strong>{{ sucursalNombre }}</strong></span>
                </p>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-neutral-500 dark:text-neutral-400">Insumos</dt><dd class="font-medium">{{ filas.length }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500 dark:text-neutral-400">Se obtiene</dt><dd class="font-medium">{{ producto ? `${cantidad(form.cantidad)} ${presentacionProducto?.nombre !== 'Unidad' ? presentacionProducto?.nombre ?? '' : ''}`.trim() : '—' }}</dd></div>
                    <template v-if="verCostos">
                        <div class="flex justify-between"><dt class="text-neutral-500 dark:text-neutral-400">Costo de insumos</dt><dd class="font-medium">{{ costoInsumos != null ? soles(costoInsumos) : '—' }}</dd></div>
                        <div v-if="otrosCostos > 0" class="flex justify-between"><dt class="text-neutral-500 dark:text-neutral-400">Otros costos</dt><dd class="font-medium">{{ soles(otrosCostos) }}</dd></div>
                        <div class="flex justify-between border-t border-stone-200 pt-2 dark:border-neutral-800"><dt class="font-medium">Costo total</dt><dd class="text-lg font-bold">{{ costoTotal != null ? soles(costoTotal) : '—' }}</dd></div>
                        <div v-if="costoPorPresentacion != null && producto" class="flex justify-between"><dt class="text-neutral-500 dark:text-neutral-400">Costo por {{ presentacionProducto?.nombre?.toLowerCase() ?? 'unidad' }}</dt><dd class="font-semibold" data-costo-unitario>{{ soles(costoPorPresentacion) }}</dd></div>
                        <div v-if="margen != null" class="flex justify-between"><dt class="text-neutral-500 dark:text-neutral-400">Margen sobre {{ soles(presentacionProducto.precio_venta) }}</dt><dd class="font-semibold" :class="margen < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">{{ margen.toFixed(0) }} %</dd></div>
                    </template>
                </dl>
                <p v-if="verCostos" class="mt-2 flex items-start gap-1.5 text-xs text-neutral-400 dark:text-neutral-500">
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    Costos estimados con el promedio del stock; al confirmar se usa el costo real de cada lote (FIFO).
                </p>

                <label class="mt-4 flex cursor-pointer items-start gap-2.5 rounded-xl border border-stone-200 p-3 text-sm has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50 dark:border-neutral-800 dark:has-[:checked]:border-emerald-600 dark:has-[:checked]:bg-emerald-950/20">
                    <input v-model="form.guardar_formula" type="checkbox" class="mt-0.5 size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" data-guardar-formula />
                    <span>
                        <span class="block font-medium">Guardar como fórmula</span>
                        <span class="block text-xs text-neutral-500 dark:text-neutral-400">La próxima vez solo indicas cuánto preparar y los insumos se calculan solos.</span>
                    </span>
                </label>

                <p v-if="motivoNoGuardar" class="mt-3 text-xs text-amber-600 dark:text-amber-400">{{ motivoNoGuardar }}</p>
                <button
                    :disabled="!puedeGuardar || form.processing"
                    class="mt-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    data-registrar-mezcla
                    @click="guardar"
                >
                    <Check class="size-5" />
                    {{ form.processing ? 'Registrando...' : 'Registrar mezcla' }}
                </button>
                <p class="mt-2 text-center text-xs text-neutral-400 dark:text-neutral-500">Los insumos salen del stock y entra lo obtenido. No es una venta ni una compra.</p>
            </aside>
        </div>
    </AppLayout>
</template>
