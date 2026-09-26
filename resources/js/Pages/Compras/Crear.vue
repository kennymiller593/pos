<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { Boxes, Building2, ChevronRight, HandCoins, Info, LoaderCircle, MapPin, Package, Plus, ScanBarcode, Search, Trash2, Truck, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { puedeEscanear } from '@/composables/escaner'

const props = defineProps({
    productos: { type: Array, required: true },
    tiposComprobante: { type: Array, required: true },
    sucursalDestino: { type: String, default: null },
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`

// ================= cabecera =================
const form = useForm({
    proveedor_id: null,
    tipo_comprobante_codigo: '',
    serie_numero: '',
    fecha: new Date().toISOString().slice(0, 10),
    es_credito: false,
    fecha_vencimiento: '',
    items: [],
})

// ================= proveedor =================
const proveedorSeleccionado = ref(null)
const buscarProveedor = ref('')
const resultadosProveedor = ref([])

watchDebounced(buscarProveedor, async (texto) => {
    if (!texto.trim()) {
        resultadosProveedor.value = []
        return
    }
    try {
        const r = await fetch(`/proveedores/buscar?buscar=${encodeURIComponent(texto)}`, { headers: { Accept: 'application/json' } })
        resultadosProveedor.value = r.ok ? await r.json() : []
    } catch {
        resultadosProveedor.value = []
    }
}, { debounce: 300 })

function elegirProveedor(p) {
    proveedorSeleccionado.value = p
    form.proveedor_id = p.id
    buscarProveedor.value = ''
    resultadosProveedor.value = []
}

function quitarProveedor() {
    proveedorSeleccionado.value = null
    form.proveedor_id = null
}

// --- modal nuevo proveedor ---
const modalProveedor = ref(false)
const nuevoProveedor = ref({ razon_social: '', ruc: '', contacto: '', telefono: '' })
const erroresProveedor = ref({})
const guardandoProveedor = ref(false)
const consultandoRucProv = ref(false)

async function consultarRucProveedor() {
    const ruc = nuevoProveedor.value.ruc.trim()
    if (!/^\d{11}$/.test(ruc) || consultandoRucProv.value) return
    consultandoRucProv.value = true
    try {
        const r = await fetch(`/consultas/ruc/${ruc}`, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (r.ok && datos.razon_social) nuevoProveedor.value.razon_social = datos.razon_social
    } catch {
        // consulta opcional: se puede completar a mano
    } finally {
        consultandoRucProv.value = false
    }
}

async function guardarProveedor() {
    guardandoProveedor.value = true
    erroresProveedor.value = {}
    try {
        const r = await fetch('/proveedores', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({
                razon_social: nuevoProveedor.value.razon_social,
                ruc: nuevoProveedor.value.ruc || null,
                contacto: nuevoProveedor.value.contacto || null,
                telefono: nuevoProveedor.value.telefono || null,
            }),
        })
        const datos = await r.json()
        if (!r.ok) {
            erroresProveedor.value = datos.errors ?? { razon_social: [datos.message ?? 'No se pudo guardar.'] }
            return
        }
        elegirProveedor(datos)
        modalProveedor.value = false
        nuevoProveedor.value = { razon_social: '', ruc: '', contacto: '', telefono: '' }
    } finally {
        guardandoProveedor.value = false
    }
}

// ================= items =================
const buscarProducto = ref('')

const resultadosProducto = computed(() => {
    const texto = buscarProducto.value.trim().toLowerCase()
    if (!texto) return []
    return props.productos
        .filter((p) =>
            p.nombre.toLowerCase().includes(texto)
            || p.codigo_interno.toLowerCase().includes(texto)
            || p.presentaciones.some((pres) => pres.codigo_barras === texto))
        .slice(0, 8)
})

// producto y presentación con ese código de barras exacto (o null)
function buscarPorCodigo(codigo) {
    for (const producto of props.productos) {
        const presentacion = producto.presentaciones.find((p) => p.codigo_barras === codigo)
        if (presentacion) return { producto, presentacion }
    }
    return null
}

// pistola lectora (o Enter al escribir): el código exacto se agrega directo
function alPresionarEnter() {
    const texto = buscarProducto.value.trim()
    if (!texto) return
    const encontrado = buscarPorCodigo(texto)
    if (encontrado) return agregarProducto(encontrado.producto, encontrado.presentacion)
    if (resultadosProducto.value.length === 1) agregarProducto(resultadosProducto.value[0])
}

// ---- escáner con la cámara (celulares) ----
const escanerAbierto = ref(false)

function leerCodigoCamara(codigo) {
    const encontrado = buscarPorCodigo(codigo)
    if (!encontrado) return { ok: false, mensaje: `Código ${codigo} no está registrado en tus productos.` }
    const fila = agregarProducto(encontrado.producto, encontrado.presentacion)
    const pres = encontrado.presentacion.nombre !== 'Unidad' ? ` (${encontrado.presentacion.nombre})` : ''
    return { ok: true, mensaje: `${encontrado.producto.nombre}${pres} · ${fila.cantidad} en la compra` }
}

// filas: { producto, presentacion_id, cantidad, costo_unitario }
const filas = ref([])

// con código de barras entra la presentación escaneada (p. ej. la caja); si no, la principal
function agregarProducto(producto, presentacion = null) {
    const def = presentacion ?? producto.presentaciones.find((p) => p.es_default) ?? producto.presentaciones[0]
    let fila = filas.value.find((f) => f.presentacion_id === def.id)
    if (fila) {
        fila.cantidad = Number(fila.cantidad) + 1
    } else {
        fila = {
            producto,
            presentacion_id: def.id,
            cantidad: 1,
            costo_unitario: '',
            numero_lote: '',
            fecha_vencimiento: '',
        }
        filas.value.push(fila)
    }
    buscarProducto.value = ''
    return fila
}

function presentacionDe(fila) {
    return fila.producto.presentaciones.find((p) => p.id === fila.presentacion_id)
}

function subtotal(fila) {
    return Number(fila.cantidad || 0) * Number(fila.costo_unitario || 0)
}

const total = computed(() => Math.round(filas.value.reduce((suma, f) => suma + subtotal(f), 0) * 100) / 100)
const unidades = computed(() => filas.value.reduce((n, f) => n + Number(f.cantidad || 0), 0))

const puedeGuardar = computed(() =>
    filas.value.length > 0 &&
    filas.value.every((f) =>
        Number(f.cantidad) > 0 &&
        f.costo_unitario !== '' &&
        Number(f.costo_unitario) >= 0 &&
        (!f.producto.controla_lote || f.numero_lote.trim() !== ''),
    ),
)

// por qué todavía no se puede registrar (se muestra en el resumen)
const motivoNoGuardar = computed(() => {
    if (!filas.value.length) return ''
    const sinCosto = filas.value.filter((f) => f.costo_unitario === '').length
    if (sinCosto) return `Falta el costo unitario de ${sinCosto} ${sinCosto === 1 ? 'producto' : 'productos'}.`
    if (filas.value.some((f) => !(Number(f.cantidad) > 0))) return 'Hay productos con cantidad 0.'
    if (filas.value.some((f) => f.producto.controla_lote && f.numero_lote.trim() === '')) return 'Falta el número de lote en algunos productos.'
    return ''
})

function guardar() {
    form.items = filas.value.map((f) => ({
        presentacion_id: f.presentacion_id,
        cantidad: Number(f.cantidad),
        costo_unitario: Number(f.costo_unitario),
        numero_lote: f.numero_lote.trim() || null,
        fecha_vencimiento: f.fecha_vencimiento || null,
    }))

    form.transform((data) => ({
        ...data,
        tipo_comprobante_codigo: data.tipo_comprobante_codigo || null,
        serie_numero: data.tipo_comprobante_codigo ? (data.serie_numero || null) : null,
        fecha_vencimiento: data.es_credito ? (data.fecha_vencimiento || null) : null,
    })).post('/compras')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseCelda =
    'h-9 rounded-lg border border-stone-200 bg-white px-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950'
const claseTarjeta = 'rounded-2xl border border-[#E2E8F0] bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900'
const claseTituloSeccion = 'mb-5 flex items-center gap-2.5 font-semibold tracking-tight'
const claseNumero = 'grid size-6 place-items-center rounded-lg bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
</script>

<template>
    <AppLayout titulo="Nueva compra">
        <div>
            <!-- ============ Encabezado ============ -->
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <nav class="mb-2 flex items-center gap-1.5 text-xs text-[#64748B] dark:text-neutral-400">
                        <Link href="/dashboard" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Inicio</Link>
                        <ChevronRight class="size-3.5" />
                        <Link href="/compras" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Compras</Link>
                        <ChevronRight class="size-3.5" />
                        <span class="text-[#0F172A] dark:text-neutral-200">Registrar compra</span>
                    </nav>
                    <div class="flex items-center gap-3">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <Truck class="size-6" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-2xl font-bold tracking-tight">Registrar compra</h1>
                            <p class="text-sm text-[#64748B] dark:text-neutral-400">
                                Registra la mercadería que compras a tu proveedor: el stock y el costo se actualizan al guardar.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="hidden shrink-0 gap-2 lg:flex">
                    <Link
                        href="/compras"
                        class="inline-flex h-11 items-center gap-2 rounded-xl border border-[#E2E8F0] bg-white px-4 text-sm font-medium hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        <X class="size-4" />
                        Cancelar
                    </Link>
                   
                </div>
            </div>

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div class="min-w-0 space-y-6">
                    <!-- ============ 1. Datos del proveedor ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">1</span> Datos del proveedor</h2>

                        <div class="grid gap-4 md:grid-cols-2">
                            <!-- Proveedor -->
                            <div>
                                <label :class="claseLabel">Proveedor <span class="font-normal text-[#94A3B8]">(opcional al contado)</span></label>
                                <div v-if="proveedorSeleccionado" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-emerald-600 dark:bg-neutral-900 dark:text-emerald-400">
                                        <Building2 class="size-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold">{{ proveedorSeleccionado.razon_social }}</p>
                                        <p class="text-xs text-[#64748B] dark:text-neutral-400">
                                            {{ proveedorSeleccionado.ruc ? `RUC: ${proveedorSeleccionado.ruc}` : 'Sin RUC' }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded-lg p-1.5 text-[#64748B] hover:bg-white hover:text-red-600 dark:hover:bg-neutral-900 dark:hover:text-red-400"
                                        title="Cambiar proveedor"
                                        @click="quitarProveedor"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>
                                <div v-else class="flex gap-2">
                                    <div class="relative flex-1">
                                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                        <input
                                            v-model="buscarProveedor"
                                            type="text"
                                            placeholder="Buscar por RUC o razón social..."
                                            :class="[claseInput, 'pl-9']"
                                        />
                                        <div
                                            v-if="resultadosProveedor.length"
                                            class="absolute top-11 right-0 left-0 z-20 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                                        >
                                            <button
                                                v-for="p in resultadosProveedor"
                                                :key="p.id"
                                                type="button"
                                                class="block w-full px-3.5 py-2.5 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-700"
                                                @click="elegirProveedor(p)"
                                            >
                                                <span class="font-medium">{{ p.razon_social }}</span>
                                                <span v-if="p.ruc" class="ml-2 text-xs text-neutral-500 dark:text-neutral-400">{{ p.ruc }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl border border-stone-300 px-3 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        @click="modalProveedor = true"
                                    >
                                        <Plus class="size-4" />
                                        <span class="hidden sm:inline">Nuevo proveedor</span>
                                        <span class="sm:hidden">Nuevo</span>
                                    </button>
                                </div>
                                <p v-if="form.errors.proveedor_id" :class="claseError">{{ form.errors.proveedor_id }}</p>
                            </div>

                            <!-- Comprobante del proveedor -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="claseLabel" for="tipo_doc">Tipo de comprobante</label>
                                    <select id="tipo_doc" v-model="form.tipo_comprobante_codigo" :class="claseInput">
                                        <option value="">Sin documento</option>
                                        <option v-for="t in tiposComprobante" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="claseLabel" for="serie_numero">Serie - Número</label>
                                    <input
                                        id="serie_numero"
                                        v-model="form.serie_numero"
                                        type="text"
                                        maxlength="20"
                                        :disabled="!form.tipo_comprobante_codigo"
                                        :class="[claseInput, 'disabled:bg-stone-50 disabled:text-neutral-400 dark:disabled:bg-neutral-900']"
                                        placeholder="F001-000123"
                                    />
                                </div>
                            </div>

                            <div>
                                <label :class="claseLabel" for="fecha">Fecha de emisión *</label>
                                <input id="fecha" v-model="form.fecha" type="date" :class="claseInput" />
                                <p v-if="form.errors.fecha" :class="claseError">{{ form.errors.fecha }}</p>
                            </div>

                            <!-- Condición de pago -->
                            <div>
                                <label :class="claseLabel">Condición de pago</label>
                                <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1 dark:bg-neutral-800">
                                    <button
                                        v-for="c in [{ v: false, t: 'Contado' }, { v: true, t: 'Crédito' }]"
                                        :key="c.t"
                                        type="button"
                                        class="h-8 rounded-lg text-sm font-medium transition-colors"
                                        :class="form.es_credito === c.v
                                            ? 'bg-white text-[#0F172A] shadow-sm dark:bg-neutral-950 dark:text-neutral-100'
                                            : 'text-[#64748B] hover:text-[#0F172A] dark:text-neutral-400 dark:hover:text-neutral-100'"
                                        @click="form.es_credito = c.v"
                                    >
                                        {{ c.t }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="form.es_credito" class="md:col-span-2">
                                <div class="grid items-end gap-3 rounded-xl bg-amber-50 p-3 sm:grid-cols-[1fr_auto] dark:bg-amber-950/30">
                                    <div>
                                        <label :class="claseLabel" for="fecha_vencimiento">Fecha de pago acordada</label>
                                        <input id="fecha_vencimiento" v-model="form.fecha_vencimiento" type="date" :min="form.fecha" :class="claseInput" />
                                        <p v-if="form.errors.fecha_vencimiento" :class="claseError">{{ form.errors.fecha_vencimiento }}</p>
                                    </div>
                                    <p class="text-xs text-amber-800 sm:max-w-56 sm:pb-2.5 dark:text-amber-300">
                                        La deuda quedará en <strong>Cuentas por pagar</strong>.<template v-if="!proveedorSeleccionado"> Elige el proveedor.</template>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============ 2. Detalle de la compra ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">2</span> Detalle de la compra</h2>

                        <!-- celular: una tarjeta por producto, con todos los campos a la vista -->
                        <div v-if="filas.length" class="space-y-3 md:hidden">
                            <div
                                v-for="(fila, i) in filas"
                                :key="fila.presentacion_id"
                                class="rounded-xl border border-[#E2E8F0] p-3 dark:border-neutral-800"
                            >
                                <div class="flex items-start gap-2">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-md bg-slate-100 text-[11px] font-semibold text-[#64748B] dark:bg-neutral-800 dark:text-neutral-400">{{ i + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium">{{ fila.producto.nombre }}</p>
                                        <p class="font-mono text-xs text-[#94A3B8]">{{ fila.producto.codigo_interno }}</p>
                                        <p v-if="!fila.producto.controla_stock" class="text-xs text-amber-600 dark:text-amber-400">No controla stock: no genera inventario</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="grid size-8 shrink-0 place-items-center rounded-lg border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900/60 dark:hover:bg-red-950/40"
                                        title="Quitar"
                                        @click="filas.splice(i, 1)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <label class="col-span-2 block">
                                        <span class="mb-1 block text-xs text-[#64748B] dark:text-neutral-400">Presentación</span>
                                        <select v-model="fila.presentacion_id" :class="[claseCelda, 'w-full text-base']">
                                            <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">
                                                {{ pres.nombre }} (x{{ pres.factor_conversion }})
                                            </option>
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-[#64748B] dark:text-neutral-400">Cantidad</span>
                                        <input
                                            v-model="fila.cantidad"
                                            type="number"
                                            inputmode="decimal"
                                            :step="fila.producto.permite_fraccion ? '0.001' : '1'"
                                            min="0"
                                            :class="[claseCelda, 'h-10 w-full text-right text-base']"
                                        />
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-[#64748B] dark:text-neutral-400">Costo unit. (S/)</span>
                                        <input
                                            v-model="fila.costo_unitario"
                                            type="number"
                                            inputmode="decimal"
                                            step="0.000001"
                                            min="0"
                                            placeholder="0.00"
                                            :class="[claseCelda, 'h-10 w-full text-right text-base', fila.costo_unitario === '' ? '!border-amber-300 dark:!border-amber-700' : '']"
                                        />
                                    </label>
                                </div>
                                <div v-if="fila.producto.controla_lote" class="mt-2 grid grid-cols-2 gap-2 rounded-lg bg-amber-50 p-2 dark:bg-amber-950/30">
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-semibold text-amber-700 dark:text-amber-400">Lote *</span>
                                        <input v-model="fila.numero_lote" type="text" maxlength="50" placeholder="N° de lote" :class="[claseCelda, 'w-full']" />
                                    </label>
                                    <label class="block">
                                        <span class="mb-1 block text-xs text-neutral-500 dark:text-neutral-400">Vence</span>
                                        <input v-model="fila.fecha_vencimiento" type="date" :class="[claseCelda, 'w-full']" />
                                    </label>
                                </div>
                                <div class="mt-3 flex items-center justify-between border-t border-[#F1F5F9] pt-2 text-sm dark:border-neutral-800">
                                    <span class="text-[#64748B] dark:text-neutral-400">Subtotal</span>
                                    <span class="font-semibold tabular-nums">{{ soles(subtotal(fila)) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- PC y tablet: tabla -->
                        <div v-if="filas.length" class="@container -mx-5 hidden overflow-x-auto sm:-mx-6 md:block">
                            <table class="w-full min-w-[40rem] text-left text-sm">
                                <thead class="border-y border-[#E2E8F0] bg-slate-50 text-xs text-[#64748B] dark:border-neutral-800 dark:bg-neutral-950/50 dark:text-neutral-400">
                                    <tr>
                                        <th class="w-10 py-2.5 pl-5 font-semibold sm:pl-6">#</th>
                                        <th class="px-3 py-2.5 font-semibold">Producto</th>
                                        <th class="px-3 py-2.5 font-semibold">Presentación</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">Cant.</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">Costo unit. (S/)</th>
                                        <th class="px-3 py-2.5 text-right font-semibold">Subtotal</th>
                                        <th class="py-2.5 pr-5 text-right font-semibold sm:pr-6">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#F1F5F9] dark:divide-neutral-800">
                                    <template v-for="(fila, i) in filas" :key="fila.presentacion_id">
                                        <tr>
                                            <td class="py-3 pl-5 text-[#94A3B8] sm:pl-6">{{ i + 1 }}</td>
                                            <td class="px-3 py-3">
                                                <p class="font-medium">{{ fila.producto.nombre }}</p>
                                                <p class="font-mono text-xs text-[#94A3B8]">{{ fila.producto.codigo_interno }}</p>
                                                <p v-if="!fila.producto.controla_stock" class="text-xs text-amber-600 dark:text-amber-400">
                                                    No controla stock: no genera inventario
                                                </p>
                                            </td>
                                            <td class="px-3 py-3">
                                                <select v-model="fila.presentacion_id" :class="claseCelda">
                                                    <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">
                                                        {{ pres.nombre }} (x{{ pres.factor_conversion }})
                                                    </option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-model="fila.cantidad"
                                                    type="number"
                                                    :step="fila.producto.permite_fraccion ? '0.001' : '1'"
                                                    min="0"
                                                    :class="[claseCelda, 'ml-auto block w-20 text-right']"
                                                />
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-model="fila.costo_unitario"
                                                    type="number"
                                                    step="0.000001"
                                                    min="0"
                                                    placeholder="0.00"
                                                    :class="[claseCelda, 'ml-auto block w-28 text-right', fila.costo_unitario === '' ? '!border-amber-300 dark:!border-amber-700' : '']"
                                                />
                                            </td>
                                            <td class="px-3 py-3 text-right font-semibold whitespace-nowrap tabular-nums">{{ soles(subtotal(fila)) }}</td>
                                            <td class="py-3 pr-5 text-right sm:pr-6">
                                                <button
                                                    type="button"
                                                    class="inline-grid size-8 place-items-center rounded-lg border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900/60 dark:hover:bg-red-950/40"
                                                    title="Quitar"
                                                    @click="filas.splice(i, 1)"
                                                >
                                                    <Trash2 class="size-4" />
                                                </button>
                                            </td>
                                        </tr>

                                        <!-- Lote y vencimiento (productos que controlan lote) -->
                                        <tr v-if="fila.producto.controla_lote" class="!border-t-0">
                                            <td />
                                            <td colspan="6" class="px-3 pt-0 pb-3 sm:pr-6">
                                                <div class="flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 px-3 py-2 dark:bg-amber-950/30">
                                                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-400">Lote *</span>
                                                    <input
                                                        v-model="fila.numero_lote"
                                                        type="text"
                                                        maxlength="50"
                                                        placeholder="N° de lote"
                                                        class="h-8 w-36 rounded-lg border bg-white px-2 text-sm focus:outline-none dark:bg-neutral-950"
                                                        :class="fila.numero_lote.trim() === ''
                                                            ? 'border-amber-400 focus:border-amber-500 dark:border-amber-700'
                                                            : 'border-stone-200 focus:border-emerald-500 dark:border-neutral-700'"
                                                    />
                                                    <span class="text-xs text-neutral-500 dark:text-neutral-400">Vence</span>
                                                    <input
                                                        v-model="fila.fecha_vencimiento"
                                                        type="date"
                                                        class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Agregar producto: buscar, pistola lectora o cámara -->
                        <div class="relative" :class="filas.length ? 'mt-4' : ''">
                            <div
                                v-if="!filas.length"
                                class="mb-3 rounded-xl border border-dashed border-[#CBD5E1] px-4 py-8 text-center dark:border-neutral-700"
                            >
                                <Package class="mx-auto mb-2 size-8 text-[#CBD5E1] dark:text-neutral-600" />
                                <p class="text-sm font-medium">Aún no agregas productos</p>
                                <p class="text-xs text-[#64748B] dark:text-neutral-400">Búscalos por nombre o escanea su código de barras.</p>
                            </div>
                            <div class="relative">
                                <Plus class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-emerald-600" />
                                <input
                                    v-model="buscarProducto"
                                    type="text"
                                    placeholder="Agregar producto: busca o escanea (nombre, código o código de barras)..."
                                    :class="[claseInput, 'h-11 border-emerald-300 pl-10 dark:border-emerald-900', puedeEscanear ? 'pr-12' : '']"
                                    @keydown.enter.prevent="alPresionarEnter"
                                />
                                <button
                                    v-if="puedeEscanear"
                                    type="button"
                                    class="absolute top-1/2 right-1.5 grid size-9 -translate-y-1/2 place-items-center rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 dark:bg-emerald-600 dark:hover:bg-emerald-700"
                                    aria-label="Escanear con la cámara"
                                    title="Escanear con la cámara"
                                    @click="escanerAbierto = true"
                                >
                                    <ScanBarcode class="size-5" />
                                </button>
                            </div>
                            <div
                                v-if="resultadosProducto.length"
                                class="absolute top-full right-0 left-0 z-20 mt-1 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <button
                                    v-for="p in resultadosProducto"
                                    :key="p.id"
                                    type="button"
                                    class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-700"
                                    @click="agregarProducto(p)"
                                >
                                    <Package class="size-4 shrink-0 text-neutral-400" />
                                    <span class="font-medium">{{ p.nombre }}</span>
                                    <span class="ml-auto font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ p.codigo_interno }}</span>
                                </button>
                            </div>
                        </div>

                        <EscanerCamara
                            v-if="puedeEscanear"
                            :abierto="escanerAbierto"
                            :al-leer="leerCodigoCamara"
                            :resumen="filas.length ? `${filas.length} ${filas.length === 1 ? 'producto' : 'productos'} en la compra` : ''"
                            @cerrar="escanerAbierto = false"
                        />

                        <p v-if="form.errors.items" :class="claseError">{{ form.errors.items }}</p>

                        <p class="mt-4 flex items-start gap-2 rounded-xl bg-indigo-50 px-3 py-2.5 text-xs text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300">
                            <Info class="mt-0.5 size-4 shrink-0" />
                            Escribe el costo tal como figura en el comprobante del proveedor: con él se calcula el costo FIFO y la ganancia de cada venta.
                        </p>
                    </section>
                </div>

                <!-- ============ Resumen ============ -->
                <aside class="space-y-4 lg:sticky lg:top-20">
                    <section :class="claseTarjeta">
                        <h2 class="mb-4 font-semibold tracking-tight">Resumen de la compra</h2>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Productos</dt>
                                <dd class="font-medium tabular-nums">{{ filas.length }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Unidades</dt>
                                <dd class="font-medium tabular-nums">{{ unidades }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Condición</dt>
                                <dd class="font-medium">{{ form.es_credito ? 'Crédito' : 'Contado' }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex items-baseline justify-between border-t border-[#E2E8F0] pt-4 dark:border-neutral-800">
                            <span class="text-base font-semibold">Total</span>
                            <span class="text-2xl font-bold tracking-tight tabular-nums">{{ soles(total) }}</span>
                        </div>

                        <div class="mt-4 space-y-2 rounded-xl bg-slate-50 p-3 text-xs text-[#475569] dark:bg-neutral-950/60 dark:text-neutral-300">
                            <p v-if="sucursalDestino" class="flex items-center gap-2" title="La sucursal se cambia con el selector de la barra superior">
                                <MapPin class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>Ingresa a: <strong>{{ sucursalDestino }}</strong></span>
                            </p>
                            <p class="flex items-center gap-2">
                                <Boxes class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                El stock y el costo se actualizan al guardar.
                            </p>
                            <p v-if="form.es_credito" class="flex items-center gap-2">
                                <HandCoins class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                Quedará como deuda en Cuentas por pagar.
                            </p>
                        </div>

                        <p v-if="motivoNoGuardar" class="mt-3 text-xs font-medium text-amber-700 dark:text-amber-400">{{ motivoNoGuardar }}</p>

                        <button
                            type="button"
                            :disabled="!puedeGuardar || form.processing"
                            class="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="guardar"
                        >
                            {{ form.processing ? 'Registrando...' : 'Registrar compra' }}
                        </button>
                        <Link
                            href="/compras"
                            class="mt-2 flex h-10 items-center justify-center rounded-xl text-sm font-medium text-[#64748B] hover:bg-slate-50 lg:hidden dark:text-neutral-400 dark:hover:bg-neutral-800"
                        >
                            Cancelar
                        </Link>
                    </section>
                </aside>
            </div>
        </div>

        <!-- Modal nuevo proveedor -->
        <Teleport to="body">
            <div v-if="modalProveedor" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalProveedor = false" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarProveedor"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Nuevo proveedor</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalProveedor = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label :class="claseLabel" for="prov_ruc">RUC</label>
                            <div class="relative">
                                <input
                                    id="prov_ruc"
                                    v-model="nuevoProveedor.ruc"
                                    type="text"
                                    maxlength="11"
                                    inputmode="numeric"
                                    :class="[claseInput, 'pr-11']"
                                    placeholder="20123456789"
                                    @blur="consultarRucProveedor"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                    title="Buscar en SUNAT"
                                    :disabled="consultandoRucProv"
                                    @click="consultarRucProveedor"
                                >
                                    <LoaderCircle v-if="consultandoRucProv" class="size-4 animate-spin" />
                                    <Search v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="erroresProveedor.ruc" :class="claseError">{{ erroresProveedor.ruc[0] }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="prov_razon">Razón social *</label>
                            <input id="prov_razon" v-model="nuevoProveedor.razon_social" type="text" :class="claseInput" />
                            <p v-if="erroresProveedor.razon_social" :class="claseError">{{ erroresProveedor.razon_social[0] }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="claseLabel" for="prov_contacto">Contacto</label>
                                <input id="prov_contacto" v-model="nuevoProveedor.contacto" type="text" :class="claseInput" />
                            </div>
                            <div>
                                <label :class="claseLabel" for="prov_telefono">Teléfono</label>
                                <input id="prov_telefono" v-model="nuevoProveedor.telefono" type="text" :class="claseInput" />
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalProveedor = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="guardandoProveedor"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ guardandoProveedor ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
