<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useStorage } from '@vueuse/core'
import { Barcode, ChevronRight, Info, LoaderCircle, Minus, Package, Plus, Printer, ScanBarcode, Sparkles, Trash2, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import CodigoBarras from '@/Components/CodigoBarras.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { puedeEscanear } from '@/composables/escaner'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    productos: { type: Array, required: true },
    // { origen, items: [{ presentacion_id, copias }] } cuando se llega desde Productos o desde una compra
    inicial: { type: Object, default: () => ({ origen: null, items: [] }) },
    empresaNombre: { type: String, default: '' },
})

const { puede } = usePermisos()
const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const MAX_ETIQUETAS = 500

// ================= configuración (se recuerda en este navegador) =================
const PAPELES = [
    { id: '80', titulo: 'Ticketera 80 mm', detalle: 'Rollo de ticket normal' },
    { id: '58', titulo: 'Ticketera 58 mm', detalle: 'Rollo de ticket angosto' },
    { id: 'adhesiva', titulo: 'Etiqueta adhesiva', detalle: 'Impresora de etiquetas' },
]

const config = useStorage('etiquetas-config', {
    papel: '80',
    ancho: 50, // etiqueta adhesiva (mm)
    alto: 30,
    precio: true,
    negocio: false,
}, localStorage, { mergeDefaults: true })

const esAdhesiva = computed(() => config.value.papel === 'adhesiva')
const anchoMm = computed(() => (esAdhesiva.value ? Math.min(110, Math.max(25, Number(config.value.ancho) || 50)) : Number(config.value.papel)))
const altoMm = computed(() => Math.min(150, Math.max(15, Number(config.value.alto) || 30)))
// en etiquetas chicas todo se reduce para que entre sin cortarse
const compacta = computed(() => anchoMm.value < 45 || (esAdhesiva.value && altoMm.value < 28))

// ================= productos =================
const buscar = ref('')

const resultados = computed(() => {
    const texto = buscar.value.trim().toLowerCase()
    if (!texto) return []
    return props.productos
        .filter((p) =>
            p.nombre.toLowerCase().includes(texto)
            || p.codigo_interno.toLowerCase().includes(texto)
            || p.presentaciones.some((pres) => pres.codigo_barras === texto))
        .slice(0, 8)
})

function buscarPorCodigo(codigo) {
    for (const producto of props.productos) {
        const presentacion = producto.presentaciones.find((p) => p.codigo_barras === codigo)
        if (presentacion) return { producto, presentacion }
    }
    return null
}

function alPresionarEnter() {
    const texto = buscar.value.trim()
    if (!texto) return
    const encontrado = buscarPorCodigo(texto)
    if (encontrado) return agregar(encontrado.producto, encontrado.presentacion)
    if (resultados.value.length === 1) agregar(resultados.value[0])
}

// filas: { producto, presentacion_id, copias }
const filas = ref([])

function agregar(producto, presentacion = null, copias = 1) {
    const def = presentacion ?? producto.presentaciones.find((p) => p.es_default) ?? producto.presentaciones[0]
    let fila = filas.value.find((f) => f.presentacion_id === def.id)
    if (fila) {
        fila.copias = Number(fila.copias) + copias
    } else {
        fila = { producto, presentacion_id: def.id, copias }
        filas.value.push(fila)
    }
    buscar.value = ''
    return fila
}

const presentacionDe = (fila) => fila.producto.presentaciones.find((p) => p.id === fila.presentacion_id)
const codigoDe = (fila) => (presentacionDe(fila)?.codigo_barras ?? '').trim()
const copiasDe = (fila) => Math.max(0, Math.floor(Number(fila.copias) || 0))

function cambiarCopias(fila, delta) {
    const nueva = copiasDe(fila) + delta
    if (nueva >= 1) fila.copias = nueva
}

// lo que llega desde Productos o desde una compra
for (const item of props.inicial?.items ?? []) {
    const producto = props.productos.find((p) => p.presentaciones.some((pres) => pres.id === item.presentacion_id))
    if (producto) agregar(producto, producto.presentaciones.find((pres) => pres.id === item.presentacion_id), item.copias)
}

const sinCodigo = computed(() => filas.value.filter((f) => !codigoDe(f)))
const totalEtiquetas = computed(() => filas.value.reduce((n, f) => n + (codigoDe(f) ? copiasDe(f) : 0), 0))

// ---- generar código para las presentaciones que no tienen ----
const generando = ref(false)
const errorGenerar = ref('')

async function generarCodigos(lista) {
    if (generando.value || !lista.length) return
    generando.value = true
    errorGenerar.value = ''
    try {
        const r = await fetch('/productos/codigos-barras/asignar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ presentacion_ids: lista.map((f) => f.presentacion_id) }),
        })
        if (!r.ok) throw new Error()
        const { codigos } = await r.json()
        for (const fila of lista) {
            const pres = presentacionDe(fila)
            if (pres && codigos[pres.id]) pres.codigo_barras = codigos[pres.id]
        }
    } catch {
        errorGenerar.value = 'No se pudo generar el código. Intenta de nuevo.'
    } finally {
        generando.value = false
    }
}

// ---- escáner con la cámara (celulares) ----
const escanerAbierto = ref(false)
function leerCodigoCamara(codigo) {
    const encontrado = buscarPorCodigo(codigo)
    if (!encontrado) return { ok: false, mensaje: `Código ${codigo} no está registrado en tus productos.` }
    const fila = agregar(encontrado.producto, encontrado.presentacion)
    return { ok: true, mensaje: `${encontrado.producto.nombre}`, detalle: `x${fila.copias}` }
}

// ================= impresión =================
// una entrada por etiqueta física, en el orden de la lista
const etiquetas = computed(() => filas.value.flatMap((fila) => {
    const pres = presentacionDe(fila)
    const codigo = codigoDe(fila)
    if (!pres || !codigo) return []
    const datos = {
        nombre: fila.producto.nombre + (fila.producto.presentaciones.length > 1 ? ` · ${pres.nombre}` : ''),
        precio: soles(pres.precio_venta),
        codigo,
    }
    return Array.from({ length: copiasDe(fila) }, (_, i) => ({ ...datos, clave: `${pres.id}-${i}` }))
}))

const muestra = computed(() => etiquetas.value[0] ?? { nombre: 'Nombre del producto', precio: 'S/ 0.00', codigo: '2000000000008', clave: 'muestra' })

// El tamaño del papel va en una regla @page que solo existe mientras esta pantalla está abierta.
// Ticketera: tira continua (alto automático). Adhesiva: una página por etiqueta, del tamaño exacto.
let estiloPagina = null
onMounted(() => {
    estiloPagina = document.createElement('style')
    document.head.appendChild(estiloPagina)
    watchEffect(() => {
        estiloPagina.textContent = `
            @page { size: ${anchoMm.value}mm ${esAdhesiva.value ? `${altoMm.value}mm` : 'auto'}; margin: 0; }
            @media print {
                html, body { background: #fff !important; }
                body > *:not(#hoja-etiquetas) { display: none !important; }
                #hoja-etiquetas { display: block !important; }
            }`
    })
})
onBeforeUnmount(() => estiloPagina?.remove())

async function imprimir() {
    if (!totalEtiquetas.value || totalEtiquetas.value > MAX_ETIQUETAS) return
    await nextTick()
    window.print()
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseTarjeta = 'rounded-2xl border border-[#E2E8F0] bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900'
const claseGrupo =
    'flex items-center overflow-hidden rounded-xl border border-stone-200 bg-white transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/20 dark:border-neutral-700 dark:bg-neutral-950'
const claseBotonPaso =
    'grid h-full w-9 shrink-0 place-items-center text-[#64748B] transition-colors hover:bg-slate-100 hover:text-[#0F172A] active:bg-slate-200 disabled:pointer-events-none disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
const claseNumeroGrupo =
    'h-full w-full min-w-0 flex-1 bg-transparent text-center text-sm font-medium tabular-nums [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none'
const claseOpcion = (activa) => [
    'flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors',
    activa
        ? 'border-emerald-500 bg-emerald-50/70 ring-1 ring-emerald-500 dark:bg-emerald-950/30'
        : 'border-stone-200 hover:border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800/60',
]
</script>

<template>
    <AppLayout titulo="Etiquetas con código de barras">
        <div>
            <!-- ============ Encabezado ============ -->
            <div class="mb-6">
                <nav class="mb-2 flex items-center gap-1.5 text-xs text-[#64748B] dark:text-neutral-400">
                    <Link href="/dashboard" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Inicio</Link>
                    <ChevronRight class="size-3.5" />
                    <Link href="/productos" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Productos</Link>
                    <ChevronRight class="size-3.5" />
                    <span class="text-[#0F172A] dark:text-neutral-200">Etiquetas</span>
                </nav>
                <div class="flex items-center gap-3">
                    <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <Barcode class="size-6" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold tracking-tight">Etiquetas con código de barras</h1>
                        <p class="text-sm text-[#64748B] dark:text-neutral-400">
                            Elige los productos y cuántas etiquetas de cada uno; salen por tu ticketera o impresora de etiquetas.
                        </p>
                    </div>
                </div>
            </div>

            <p v-if="inicial.origen" class="mb-4 flex items-start gap-2 rounded-xl bg-sky-50 px-3 py-2.5 text-sm text-sky-900 dark:bg-sky-500/10 dark:text-sky-200">
                <Info class="mt-0.5 size-4 shrink-0" />
                <span>Productos de: <strong>{{ inicial.origen }}</strong>. Se propone una etiqueta por unidad comprada; ajusta las cantidades si quieres.</span>
            </p>

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <!-- ============ Productos ============ -->
                <section :class="[claseTarjeta, 'min-w-0']">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="font-semibold tracking-tight">Productos a etiquetar</h2>
                        <button
                            v-if="filas.length"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-neutral-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                            @click="filas = []"
                        >
                            <X class="size-4" />
                            Quitar todos
                        </button>
                    </div>

                    <!-- Sin código: se puede generar uno interno -->
                    <div
                        v-if="sinCodigo.length"
                        class="mb-4 flex flex-col gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        <span>
                            {{ sinCodigo.length === 1 ? 'Un producto no tiene' : `${sinCodigo.length} productos no tienen` }} código de barras: no se imprimirá su etiqueta.
                        </span>
                        <button
                            v-if="puede('productos.gestionar')"
                            type="button"
                            class="inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-amber-600 px-3 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60"
                            :disabled="generando"
                            @click="generarCodigos(sinCodigo)"
                        >
                            <LoaderCircle v-if="generando" class="size-4 animate-spin" />
                            <Sparkles v-else class="size-4" />
                            Generar {{ sinCodigo.length === 1 ? 'su código' : 'sus códigos' }}
                        </button>
                    </div>
                    <p v-if="errorGenerar" class="mb-3 text-xs font-medium text-red-600 dark:text-red-400">{{ errorGenerar }}</p>

                    <div v-if="filas.length" class="-mx-5 divide-y divide-[#F1F5F9] border-y border-[#E2E8F0] sm:-mx-6 dark:divide-neutral-800 dark:border-neutral-800">
                        <div v-for="(fila, i) in filas" :key="fila.presentacion_id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3 sm:flex-nowrap sm:px-6">
                            <div class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-[#F8FAFC] text-[#CBD5E1] dark:bg-neutral-800 dark:text-neutral-600">
                                <img v-if="fila.producto.imagen_url" :src="fila.producto.imagen_url" :alt="fila.producto.nombre" class="size-full bg-white object-contain" />
                                <Package v-else class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1 basis-40">
                                <p class="text-sm leading-snug font-semibold">{{ fila.producto.nombre }}</p>
                                <p class="text-xs text-[#94A3B8]">
                                    <template v-if="fila.producto.presentaciones.length === 1">{{ presentacionDe(fila)?.nombre }} · </template>{{ soles(presentacionDe(fila)?.precio_venta) }}
                                </p>
                                <select
                                    v-if="fila.producto.presentaciones.length > 1"
                                    v-model="fila.presentacion_id"
                                    aria-label="Presentación"
                                    class="mt-1 h-7 max-w-full rounded-lg border border-stone-200 bg-white pr-6 pl-2 text-xs font-medium focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                >
                                    <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">{{ pres.nombre }}</option>
                                </select>
                                <p v-if="codigoDe(fila)" class="mt-0.5 font-mono text-xs text-[#64748B] dark:text-neutral-400">{{ codigoDe(fila) }}</p>
                                <p v-else class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">Sin código de barras</p>
                            </div>
                            <div class="ml-auto flex items-center gap-1">
                                <div :class="[claseGrupo, 'h-10 w-28 sm:w-32']" :title="codigoDe(fila) ? 'Etiquetas a imprimir' : 'Genera el código primero'">
                                    <button type="button" :class="claseBotonPaso" aria-label="Restar" :disabled="copiasDe(fila) <= 1" @click="cambiarCopias(fila, -1)">
                                        <Minus class="size-3.5" />
                                    </button>
                                    <input v-model="fila.copias" type="number" inputmode="numeric" min="1" step="1" aria-label="Cantidad de etiquetas" :class="claseNumeroGrupo" @focus="$event.target.select()" />
                                    <button type="button" :class="claseBotonPaso" aria-label="Sumar" @click="cambiarCopias(fila, 1)">
                                        <Plus class="size-3.5" />
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 transition-colors hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                    :aria-label="`Quitar ${fila.producto.nombre}`"
                                    title="Quitar"
                                    @click="filas.splice(i, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Agregar: buscar, pistola lectora o cámara -->
                    <div class="relative" :class="filas.length ? 'mt-4' : ''">
                        <div v-if="!filas.length" class="mb-3 rounded-xl border border-dashed border-[#CBD5E1] px-4 py-8 text-center dark:border-neutral-700">
                            <Barcode class="mx-auto mb-2 size-8 text-[#CBD5E1] dark:text-neutral-600" />
                            <p class="text-sm font-medium">Aún no eliges productos</p>
                            <p class="text-xs text-[#64748B] dark:text-neutral-400">Búscalos por nombre o código. Si alguno no tiene código de barras, aquí mismo se lo generas.</p>
                        </div>
                        <div class="relative">
                            <Plus class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-emerald-600" />
                            <input
                                v-model="buscar"
                                type="text"
                                placeholder="Agregar producto: busca por nombre o código..."
                                :class="[claseInput, 'h-11 border-emerald-300 pl-10 dark:border-emerald-900', puedeEscanear ? 'pr-12' : '']"
                                @keydown.enter.prevent="alPresionarEnter"
                            />
                            <button
                                v-if="puedeEscanear"
                                type="button"
                                class="absolute top-1/2 right-1.5 grid size-9 -translate-y-1/2 place-items-center rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 dark:bg-emerald-600 dark:hover:bg-emerald-700"
                                aria-label="Escanear con la cámara"
                                @click="escanerAbierto = true"
                            >
                                <ScanBarcode class="size-5" />
                            </button>
                        </div>
                        <div
                            v-if="resultados.length"
                            class="absolute top-full right-0 left-0 z-20 mt-1 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <button
                                v-for="p in resultados"
                                :key="p.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-700"
                                @click="agregar(p)"
                            >
                                <Package class="size-4 shrink-0 text-neutral-400" />
                                <span class="min-w-0 flex-1 truncate font-medium">{{ p.nombre }}</span>
                                <span class="shrink-0 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ p.codigo_interno }}</span>
                            </button>
                        </div>
                    </div>

                    <EscanerCamara v-if="puedeEscanear" :abierto="escanerAbierto" :al-leer="leerCodigoCamara" titulo="Escanear productos" @cerrar="escanerAbierto = false" />
                </section>

                <!-- ============ Configuración y vista previa ============ -->
                <aside class="space-y-4 lg:sticky lg:top-20">
                    <section :class="claseTarjeta">
                        <h2 class="mb-3 font-semibold tracking-tight">¿En qué papel imprimes?</h2>
                        <div class="space-y-2">
                            <button v-for="p in PAPELES" :key="p.id" type="button" :class="claseOpcion(config.papel === p.id)" :aria-pressed="config.papel === p.id" @click="config.papel = p.id">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ p.titulo }}</span>
                                    <span class="block text-xs text-[#64748B] dark:text-neutral-400">{{ p.detalle }}</span>
                                </span>
                            </button>
                        </div>

                        <div v-if="esAdhesiva" class="mt-3 grid grid-cols-2 gap-3">
                            <label class="block">
                                <span class="mb-1 block text-xs text-[#64748B] dark:text-neutral-400">Ancho (mm)</span>
                                <input v-model="config.ancho" type="number" inputmode="numeric" min="25" max="110" step="1" :class="claseInput" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-[#64748B] dark:text-neutral-400">Alto (mm)</span>
                                <input v-model="config.alto" type="number" inputmode="numeric" min="15" max="150" step="1" :class="claseInput" />
                            </label>
                            <p class="col-span-2 text-xs text-[#94A3B8]">Las medidas de una etiqueta, tal como figuran en el rollo (ej. 50 × 30).</p>
                        </div>

                        <div class="mt-4 space-y-2 border-t border-[#E2E8F0] pt-4 dark:border-neutral-800">
                            <label class="flex cursor-pointer items-center justify-between gap-3 text-sm">
                                <span>
                                    <span class="font-medium">Mostrar el precio</span>
                                    <span class="block text-xs text-[#64748B] dark:text-neutral-400">Si cambias el precio, tendrás que reimprimir.</span>
                                </span>
                                <input v-model="config.precio" type="checkbox" class="size-5 shrink-0 accent-emerald-600" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-3 text-sm">
                                <span class="font-medium">Mostrar el nombre del negocio</span>
                                <input v-model="config.negocio" type="checkbox" class="size-5 shrink-0 accent-emerald-600" />
                            </label>
                        </div>
                    </section>

                    <section :class="claseTarjeta">
                        <h2 class="mb-3 font-semibold tracking-tight">Así saldrá</h2>
                        <!-- vista previa a escala: 1 mm = 3 px -->
                        <div class="grid place-items-center overflow-hidden rounded-xl bg-slate-100 p-4 dark:bg-neutral-950">
                            <div
                                class="etiqueta bg-white text-black shadow-md"
                                :class="{ 'etiqueta--compacta': compacta, 'etiqueta--rollo': !esAdhesiva }"
                                :style="{ '--mm': '3px', width: `calc(${anchoMm} * var(--mm))`, height: esAdhesiva ? `calc(${altoMm} * var(--mm))` : 'auto' }"
                            >
                                <p v-if="config.negocio" class="etiqueta__negocio">{{ empresaNombre }}</p>
                                <p class="etiqueta__nombre">{{ muestra.nombre }}</p>
                                <p v-if="config.precio" class="etiqueta__precio">{{ muestra.precio }}</p>
                                <div class="etiqueta__barras"><CodigoBarras :valor="muestra.codigo" /></div>
                                <p class="etiqueta__codigo">{{ muestra.codigo }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-center text-xs text-[#94A3B8]">
                            {{ anchoMm }} mm de ancho{{ esAdhesiva ? ` × ${altoMm} mm de alto` : ', en tira continua' }}
                        </p>

                        <p v-if="totalEtiquetas > MAX_ETIQUETAS" class="mt-3 text-xs font-medium text-amber-700 dark:text-amber-400">
                            Son {{ totalEtiquetas }} etiquetas: imprime como máximo {{ MAX_ETIQUETAS }} por vez.
                        </p>

                        <button
                            type="button"
                            :disabled="!totalEtiquetas || totalEtiquetas > MAX_ETIQUETAS"
                            class="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="imprimir"
                        >
                            <Printer class="size-5" />
                            {{ totalEtiquetas ? `Imprimir ${totalEtiquetas} ${totalEtiquetas === 1 ? 'etiqueta' : 'etiquetas'}` : 'Imprimir etiquetas' }}
                        </button>
                        <p class="mt-3 flex items-start gap-2 text-xs text-[#64748B] dark:text-neutral-400">
                            <Info class="mt-0.5 size-4 shrink-0" />
                            En la ventana de impresión elige tu ticketera, márgenes "Ninguno" y escala 100 %. Imprime una de prueba y léela con tu lector antes de imprimir muchas.
                        </p>
                    </section>
                </aside>
            </div>
        </div>

        <!-- Hoja que realmente se imprime (oculta en pantalla) -->
        <Teleport to="body">
            <div id="hoja-etiquetas" :class="esAdhesiva ? 'hoja--adhesiva' : 'hoja--rollo'">
                <div
                    v-for="e in etiquetas"
                    :key="e.clave"
                    class="etiqueta"
                    :class="{ 'etiqueta--compacta': compacta, 'etiqueta--rollo': !esAdhesiva }"
                    :style="{ '--mm': '1mm', width: `${anchoMm}mm`, height: esAdhesiva ? `${altoMm}mm` : 'auto' }"
                >
                    <p v-if="config.negocio" class="etiqueta__negocio">{{ empresaNombre }}</p>
                    <p class="etiqueta__nombre">{{ e.nombre }}</p>
                    <p v-if="config.precio" class="etiqueta__precio">{{ e.precio }}</p>
                    <div class="etiqueta__barras"><CodigoBarras :valor="e.codigo" /></div>
                    <p class="etiqueta__codigo">{{ e.codigo }}</p>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
/* Las medidas van en múltiplos de --mm: 1mm al imprimir, 3px en la vista previa (misma etiqueta, a escala). */
#hoja-etiquetas { display: none; }

.etiqueta {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: calc(0.6 * var(--mm));
    padding: calc(2 * var(--mm)) calc(3 * var(--mm));
    overflow: hidden;
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1.15;
}
.etiqueta p { margin: 0; max-width: 100%; }
.etiqueta__negocio { font-size: calc(2.4 * var(--mm)); letter-spacing: 0.02em; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.etiqueta__nombre { font-size: calc(3.1 * var(--mm)); font-weight: 700; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; }
.etiqueta__precio { font-size: calc(5 * var(--mm)); font-weight: 800; line-height: 1; }
/* el ancho se limita para que cada barra no pase de ~0,45 mm: más ancho no se lee mejor */
.etiqueta__barras { width: 100%; max-width: calc(52 * var(--mm)); height: calc(11 * var(--mm)); flex-shrink: 0; }
.etiqueta__codigo { font-family: 'Courier New', monospace; font-size: calc(2.8 * var(--mm)); letter-spacing: 0.08em; line-height: 1; }

.etiqueta--compacta { padding: calc(1.2 * var(--mm)) calc(2 * var(--mm)); gap: calc(0.4 * var(--mm)); }
.etiqueta--compacta .etiqueta__nombre { font-size: calc(2.6 * var(--mm)); }
.etiqueta--compacta .etiqueta__precio { font-size: calc(4 * var(--mm)); }
.etiqueta--compacta .etiqueta__barras { height: calc(8 * var(--mm)); }
.etiqueta--compacta .etiqueta__codigo { font-size: calc(2.4 * var(--mm)); }

/* ticketera: el cabezal no imprime los ~5 mm de cada borde del rollo (58 mm -> 48 útiles, 80 mm -> 72) */
.etiqueta--rollo { padding-left: calc(5 * var(--mm)); padding-right: calc(5 * var(--mm)); }
.etiqueta--rollo.etiqueta--compacta { padding-left: calc(5 * var(--mm)); padding-right: calc(5 * var(--mm)); }

/* ticketera: tira continua, con una línea punteada para cortar entre etiquetas */
.hoja--rollo .etiqueta { border-bottom: 0.3mm dashed #000; padding-top: 3mm; padding-bottom: 3mm; break-inside: avoid; }
.hoja--rollo .etiqueta:last-child { border-bottom: 0; }
/* impresora de etiquetas: cada etiqueta es una página del tamaño exacto */
.hoja--adhesiva .etiqueta { break-after: page; }
.hoja--adhesiva .etiqueta:last-child { break-after: auto; }
</style>
