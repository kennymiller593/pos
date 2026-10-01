<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { ChevronRight, FileText, Info, LoaderCircle, Minus, Package, Plus, RotateCcw, ScanBarcode, Search, Tag, Trash2, UserRound, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { ayudaDocumento, esSinDocumento } from '@/composables/documentoIdentidad'
import { puedeEscanear } from '@/composables/escaner'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    productos: { type: Array, required: true },
    tiposDocumento: { type: Array, default: () => [] },
    validezDias: { type: Number, default: 7 },
    // al editar: { id, codigo }; null al crear o duplicar
    cotizacion: { type: Object, default: null },
    // contenido con que arranca el formulario (edición o duplicado)
    inicial: { type: Object, default: null },
})

const { puede } = usePermisos()
const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const redondear = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100

// fecha local (no UTC): de noche, toISOString() ya devuelve el día siguiente
function fechaLocal(masDias = 0) {
    const d = new Date()
    d.setDate(d.getDate() + masDias)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const esEdicion = computed(() => !!props.cotizacion)

// ================= cabecera =================
const form = useForm({
    cliente_id: props.inicial?.cliente?.id ?? null,
    fecha_emision: props.inicial?.fecha_emision ?? fechaLocal(),
    valida_hasta: props.inicial?.valida_hasta ?? fechaLocal(props.validezDias),
    tiempo_entrega: props.inicial?.tiempo_entrega ?? '',
    direccion_envio: props.inicial?.direccion_envio ?? '',
    es_credito: props.inicial?.es_credito ?? false,
    observaciones: props.inicial?.observaciones ?? '',
    items: [],
})

const diasValidez = computed(() => {
    if (!form.fecha_emision || !form.valida_hasta) return null
    return Math.round((new Date(`${form.valida_hasta}T00:00:00`) - new Date(`${form.fecha_emision}T00:00:00`)) / 86400000)
})

// ================= cliente =================
const clienteSeleccionado = ref(props.inicial?.cliente ?? null)
const buscarCliente = ref('')
const resultadosCliente = ref([])

watchDebounced(buscarCliente, async (texto) => {
    if (!texto.trim()) {
        resultadosCliente.value = []
        return
    }
    try {
        const r = await fetch(`/cotizaciones/clientes?buscar=${encodeURIComponent(texto)}`, { headers: { Accept: 'application/json' } })
        resultadosCliente.value = r.ok ? await r.json() : []
    } catch {
        resultadosCliente.value = []
    }
}, { debounce: 300 })

function elegirCliente(cliente) {
    clienteSeleccionado.value = cliente
    form.cliente_id = cliente.id
    buscarCliente.value = ''
    resultadosCliente.value = []
    // la dirección del cliente se propone como dirección de envío
    if (!form.direccion_envio.trim() && cliente.direccion) form.direccion_envio = cliente.direccion
}

function quitarCliente() {
    clienteSeleccionado.value = null
    form.cliente_id = null
}

// --- creación rápida de cliente ---
const modalCliente = ref(false)
const nuevoCliente = ref({ tipo_documento_codigo: '1', numero_documento: '', nombre: '', telefono: '' })
const erroresCliente = ref({})
const guardandoCliente = ref(false)
const consultandoDoc = ref(false)

function abrirModalCliente() {
    const texto = buscarCliente.value.trim()
    nuevoCliente.value = {
        tipo_documento_codigo: /^\d{11}$/.test(texto) ? '6' : '1',
        numero_documento: /^\d+$/.test(texto) ? texto : '',
        nombre: '',
        telefono: '',
    }
    erroresCliente.value = {}
    resultadosCliente.value = []
    modalCliente.value = true
    consultarDocumentoCliente()
}

// "Sin documento" no lleva número
watch(() => nuevoCliente.value.tipo_documento_codigo, (tipo) => {
    if (esSinDocumento(tipo)) nuevoCliente.value.numero_documento = ''
})

async function consultarDocumentoCliente() {
    const numero = nuevoCliente.value.numero_documento.trim()
    const tipo = nuevoCliente.value.tipo_documento_codigo
    const esDni = tipo === '1' && /^\d{8}$/.test(numero)
    const esRuc = tipo === '6' && /^\d{11}$/.test(numero)
    if ((!esDni && !esRuc) || consultandoDoc.value) return

    consultandoDoc.value = true
    try {
        const r = await fetch(esDni ? `/consultas/dni/${numero}` : `/consultas/ruc/${numero}`, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (r.ok) {
            if (esDni && datos.nombre_completo) nuevoCliente.value.nombre = datos.nombre_completo
            if (esRuc && datos.razon_social) nuevoCliente.value.nombre = datos.razon_social
        }
    } catch {
        // consulta opcional: se puede completar a mano
    } finally {
        consultandoDoc.value = false
    }
}

async function guardarCliente() {
    guardandoCliente.value = true
    erroresCliente.value = {}
    try {
        const r = await fetch('/cotizaciones/clientes', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({
                tipo_documento_codigo: nuevoCliente.value.tipo_documento_codigo,
                numero_documento: nuevoCliente.value.numero_documento || null,
                nombre: nuevoCliente.value.nombre,
                telefono: nuevoCliente.value.telefono || null,
            }),
        })
        const datos = await r.json()
        if (!r.ok) {
            erroresCliente.value = datos.errors ?? { nombre: [datos.message ?? 'No se pudo guardar.'] }
            return
        }
        elegirCliente(datos)
        modalCliente.value = false
    } finally {
        guardandoCliente.value = false
    }
}

// ================= productos =================
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

// filas: { producto, presentacion_id, cantidad, precio ('' = precio de lista), descuento, conDescuento }
const filas = ref([])

function agregarProducto(producto, presentacion = null) {
    const def = presentacion ?? producto.presentaciones.find((p) => p.es_default) ?? producto.presentaciones[0]
    let fila = filas.value.find((f) => f.presentacion_id === def.id)
    if (fila) {
        fila.cantidad = Number(fila.cantidad) + 1
    } else {
        fila = { producto, presentacion_id: def.id, cantidad: 1, precio: '', descuento: '', conDescuento: false }
        filas.value.push(fila)
    }
    buscarProducto.value = ''
    return fila
}

const presentacionDe = (fila) => fila.producto.presentaciones.find((p) => p.id === fila.presentacion_id)

// botones − / +: de uno en uno, sin bajar del mínimo (para quitar el producto está el tacho)
// (una cantidad menor a 1, p. ej. 0.5 kg, se escribe en el campo)
const cantidadMinima = () => 1
function cambiarCantidad(fila, delta) {
    const nueva = Math.round((Number(fila.cantidad || 0) + delta) * 1000) / 1000
    if (nueva > 0) fila.cantidad = nueva
}

// mismas reglas que el POS: precio mayorista automático desde la cantidad mínima
const esMayorista = (fila) => {
    const p = presentacionDe(fila)
    return Number(p.cantidad_mayorista) > 0 && p.precio_mayorista !== null && Number(fila.cantidad || 0) >= Number(p.cantidad_mayorista)
}
const precioLista = (fila) => (esMayorista(fila) ? Number(presentacionDe(fila).precio_mayorista) : Number(presentacionDe(fila).precio_venta))
const precioManual = (fila) => {
    if (!puede('pos.precio_manual')) return null
    const valor = Number(fila.precio)
    return fila.precio !== '' && fila.precio !== null && !Number.isNaN(valor) && valor > 0 ? valor : null
}
const precioUnitario = (fila) => precioManual(fila) ?? precioLista(fila)
const precioCambiado = (fila) => precioManual(fila) !== null && Math.abs(precioManual(fila) - precioLista(fila)) >= 0.005

// el campo muestra el precio vigente; mientras se escribe, lo tecleado tal cual
const precioEnEdicion = ref({ fila: null, texto: '' })
const textoPrecio = (fila) => (precioEnEdicion.value.fila === fila
    ? precioEnEdicion.value.texto
    : precioManual(fila) !== null ? String(fila.precio) : precioLista(fila).toFixed(2))

function empezarEdicionPrecio(fila, evento) {
    precioEnEdicion.value = { fila, texto: precioManual(fila) !== null ? String(fila.precio) : precioLista(fila).toFixed(2) }
    evento.target.select()
}

function escribirPrecio(fila, texto) {
    precioEnEdicion.value.texto = texto
    fila.precio = texto.replace(',', '.').trim() // teclados de celular con coma decimal
}

function terminarEdicionPrecio(fila) {
    precioEnEdicion.value = { fila: null, texto: '' }
    if (!precioCambiado(fila)) fila.precio = '' // igual al de lista: vuelve a seguirlo
}

// al cambiar de presentación el precio escrito ya no aplica
function alCambiarPresentacion(fila) {
    fila.precio = ''
}

const brutoFila = (fila) => redondear(precioUnitario(fila) * Number(fila.cantidad || 0))
const descuentoFila = (fila) => redondear(Number(fila.descuento || 0))
const totalFila = (fila) => Math.max(0, redondear(brutoFila(fila) - descuentoFila(fila)))
const descuentoInvalido = (fila) => descuentoFila(fila) > 0 && descuentoFila(fila) >= brutoFila(fila)

// totales con el mismo cálculo del servidor (IGV incluido en el precio)
const totales = computed(() => {
    const t = { gravado: 0, exonerado: 0, inafecto: 0, igv: 0, descuentos: 0, total: 0 }
    for (const fila of filas.value) {
        const total = totalFila(fila)
        t.descuentos += descuentoFila(fila)
        t.total += total
        if (fila.producto.afectacion === 'gravado') {
            const base = redondear(total / 1.18)
            t.gravado += base
            t.igv += redondear(total - base)
        } else {
            t[fila.producto.afectacion] += total
        }
    }
    for (const k of Object.keys(t)) t[k] = redondear(t[k])
    return t
})

const unidades = computed(() => filas.value.reduce((n, f) => n + Number(f.cantidad || 0), 0))

// ---- contenido inicial (editar o duplicar) ----
const itemsDescartados = ref(0)
for (const item of props.inicial?.items ?? []) {
    const producto = props.productos.find((p) => p.presentaciones.some((pres) => pres.id === item.presentacion_id))
    if (!producto) {
        itemsDescartados.value++ // el producto se eliminó o se desactivó después de cotizar
        continue
    }
    const fila = {
        producto,
        presentacion_id: item.presentacion_id,
        cantidad: item.cantidad,
        precio: '',
        descuento: item.descuento > 0 ? item.descuento : '',
        conDescuento: item.descuento > 0,
    }
    if (Math.abs(item.precio_unitario - precioLista(fila)) >= 0.005) fila.precio = String(item.precio_unitario)
    filas.value.push(fila)
}

// ---- escáner con la cámara (celulares) ----
const escanerAbierto = ref(false)
const ultimoEscaneado = ref(null)

function leerCodigoCamara(codigo) {
    const encontrado = buscarPorCodigo(codigo)
    if (!encontrado) return { ok: false, mensaje: `Código ${codigo} no está registrado en tus productos.` }
    const fila = agregarProducto(encontrado.producto, encontrado.presentacion)
    ultimoEscaneado.value = fila.presentacion_id
    nextTick(() => document.getElementById(`escaner-fila-${fila.presentacion_id}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    return { ok: true, mensaje: `${encontrado.producto.nombre} agregado`, detalle: soles(precioUnitario(fila)) }
}

// ================= guardar =================
const motivoNoGuardar = computed(() => {
    if (!filas.value.length) return ''
    if (filas.value.some((f) => !(Number(f.cantidad) > 0))) return 'Hay productos con cantidad 0.'
    if (filas.value.some((f) => !f.producto.permite_fraccion && !Number.isInteger(Number(f.cantidad)))) return 'Hay productos que no se venden por fracción.'
    if (filas.value.some(descuentoInvalido)) return 'Un descuento iguala o supera el importe de su línea.'
    if (!form.fecha_emision || !form.valida_hasta) return 'Completa las fechas.'
    if (diasValidez.value < 0) return 'La validez no puede ser anterior a la fecha de emisión.'
    return ''
})

const puedeGuardar = computed(() => filas.value.length > 0 && !motivoNoGuardar.value)

function guardar() {
    form.items = filas.value.map((f) => ({
        presentacion_id: f.presentacion_id,
        cantidad: Number(f.cantidad),
        precio_unitario: precioCambiado(f) ? precioManual(f) : null,
        descuento: descuentoFila(f) > 0 ? descuentoFila(f) : 0,
    }))

    form.transform((data) => ({
        ...data,
        tiempo_entrega: data.tiempo_entrega.trim() || null,
        direccion_envio: data.direccion_envio.trim() || null,
        observaciones: data.observaciones.trim() || null,
    }))

    if (esEdicion.value) form.put(`/cotizaciones/${props.cotizacion.id}`)
    else form.post('/cotizaciones')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseCelda =
    'h-9 rounded-lg border border-stone-200 bg-white px-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950'
// campo compuesto (− cantidad +, "S/ precio"): un solo borde que se ilumina al enfocar cualquiera de sus partes
const claseGrupo =
    'flex items-center overflow-hidden rounded-xl border border-stone-200 bg-white transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/20 dark:border-neutral-700 dark:bg-neutral-950'
const claseBotonPaso =
    'grid h-full shrink-0 place-items-center text-[#64748B] transition-colors hover:bg-slate-100 hover:text-[#0F172A] active:bg-slate-200 disabled:pointer-events-none disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
const claseNumeroGrupo =
    'h-full w-full min-w-0 flex-1 bg-transparent text-center text-sm font-medium tabular-nums [appearance:textfield] placeholder:font-normal placeholder:text-neutral-400 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none'
const claseSoloLectura = '!bg-stone-50 dark:!bg-neutral-900'
const claseTarjeta = 'rounded-2xl border border-[#E2E8F0] bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900'
const claseTituloSeccion = 'mb-5 flex items-center gap-2.5 font-semibold tracking-tight'
const claseNumero = 'grid size-6 place-items-center rounded-lg bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
const clasePrecio = (fila) => (precioCambiado(fila)
    ? '!border-amber-400 text-amber-700 dark:!border-amber-500 dark:text-amber-400'
    : '')
</script>

<template>
    <AppLayout :titulo="esEdicion ? `Editar ${cotizacion.codigo}` : 'Nueva cotización'">
        <div>
            <!-- ============ Encabezado ============ -->
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <nav class="mb-2 flex items-center gap-1.5 text-xs text-[#64748B] dark:text-neutral-400">
                        <Link href="/dashboard" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Inicio</Link>
                        <ChevronRight class="size-3.5" />
                        <Link href="/cotizaciones" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Cotizaciones</Link>
                        <ChevronRight class="size-3.5" />
                        <span class="text-[#0F172A] dark:text-neutral-200">{{ esEdicion ? cotizacion.codigo : 'Nueva cotización' }}</span>
                    </nav>
                    <div class="flex items-center gap-3">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <FileText class="size-6" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-2xl font-bold tracking-tight">{{ esEdicion ? `Editar cotización ${cotizacion.codigo}` : 'Nueva cotización' }}</h1>
                            <p class="text-sm text-[#64748B] dark:text-neutral-400">
                                Una cotización no mueve stock ni caja: cuando el cliente acepta, la conviertes en venta desde el POS.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="hidden shrink-0 gap-2 lg:flex">
                    <Link
                        href="/cotizaciones"
                        class="inline-flex h-11 items-center gap-2 rounded-xl border border-[#E2E8F0] bg-white px-4 text-sm font-medium hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        <X class="size-4" />
                        Cancelar
                    </Link>
                </div>
            </div>

            <p
                v-if="itemsDescartados"
                class="mb-4 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
            >
                <Info class="mt-0.5 size-4 shrink-0" />
                {{ itemsDescartados === 1 ? 'Un producto de la cotización original ya no está' : `${itemsDescartados} productos de la cotización original ya no están` }}
                en tu catálogo y no se cargó.
            </p>

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div class="min-w-0 space-y-6">
                    <!-- ============ 1. Cliente y condiciones ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">1</span> Cliente y condiciones</h2>

                        <div class="grid gap-4 md:grid-cols-2">
                            <!-- Cliente -->
                            <div class="md:col-span-2">
                                <label :class="claseLabel">Cliente <span class="font-normal text-[#94A3B8]">(opcional)</span></label>
                                <div v-if="clienteSeleccionado" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-emerald-600 dark:bg-neutral-900 dark:text-emerald-400">
                                        <UserRound class="size-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold">{{ clienteSeleccionado.nombre }}</p>
                                        <p class="truncate text-xs text-[#64748B] dark:text-neutral-400">
                                            {{ clienteSeleccionado.numero_documento ? `Doc.: ${clienteSeleccionado.numero_documento}` : 'Sin documento' }}
                                            <template v-if="clienteSeleccionado.direccion"> · {{ clienteSeleccionado.direccion }}</template>
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded-lg p-1.5 text-[#64748B] hover:bg-white hover:text-red-600 dark:hover:bg-neutral-900 dark:hover:text-red-400"
                                        title="Cambiar cliente"
                                        @click="quitarCliente"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>
                                <div v-else class="flex gap-2">
                                    <div class="relative flex-1">
                                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                        <input
                                            v-model="buscarCliente"
                                            type="text"
                                            placeholder="Buscar por nombre, DNI o RUC..."
                                            :class="[claseInput, 'pl-9']"
                                            @keydown.enter.prevent
                                        />
                                        <div
                                            v-if="resultadosCliente.length"
                                            class="absolute top-11 right-0 left-0 z-20 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                                        >
                                            <button
                                                v-for="c in resultadosCliente"
                                                :key="c.id"
                                                type="button"
                                                class="block w-full px-3.5 py-2.5 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-700"
                                                @click="elegirCliente(c)"
                                            >
                                                <span class="font-medium">{{ c.nombre }}</span>
                                                <span v-if="c.numero_documento" class="ml-2 text-xs text-neutral-500 dark:text-neutral-400">{{ c.numero_documento }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        v-if="puede('clientes.gestionar')"
                                        type="button"
                                        class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl border border-stone-300 px-3 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        @click="abrirModalCliente"
                                    >
                                        <Plus class="size-4" />
                                        <span class="hidden sm:inline">Nuevo cliente</span>
                                        <span class="sm:hidden">Nuevo</span>
                                    </button>
                                </div>
                                <p v-if="form.errors.cliente_id" :class="claseError">{{ form.errors.cliente_id }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="claseLabel" for="fecha_emision">Fecha de emisión *</label>
                                    <input id="fecha_emision" v-model="form.fecha_emision" type="date" :class="claseInput" />
                                    <p v-if="form.errors.fecha_emision" :class="claseError">{{ form.errors.fecha_emision }}</p>
                                </div>
                                <div>
                                    <label :class="claseLabel" for="valida_hasta">Válida hasta *</label>
                                    <input id="valida_hasta" v-model="form.valida_hasta" type="date" :min="form.fecha_emision" :class="claseInput" />
                                    <p v-if="form.errors.valida_hasta" :class="claseError">{{ form.errors.valida_hasta }}</p>
                                    <p v-else-if="diasValidez !== null && diasValidez >= 0" class="mt-1 text-xs text-[#94A3B8]">
                                        {{ diasValidez === 0 ? 'Solo por hoy' : `${diasValidez} ${diasValidez === 1 ? 'día' : 'días'} de validez` }}
                                    </p>
                                </div>
                            </div>

                            <!-- Término de pago: informativo, el cobro se define al vender -->
                            <div>
                                <label :class="claseLabel">Término de pago</label>
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

                            <div>
                                <label :class="claseLabel" for="tiempo_entrega">Tiempo de entrega</label>
                                <input id="tiempo_entrega" v-model="form.tiempo_entrega" type="text" maxlength="100" :class="claseInput" placeholder="Ej. 2 días hábiles, inmediata..." />
                                <p v-if="form.errors.tiempo_entrega" :class="claseError">{{ form.errors.tiempo_entrega }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="direccion_envio">Dirección de envío</label>
                                <input id="direccion_envio" v-model="form.direccion_envio" type="text" maxlength="250" :class="claseInput" placeholder="Dónde se entrega el pedido" />
                                <p v-if="form.errors.direccion_envio" :class="claseError">{{ form.errors.direccion_envio }}</p>
                            </div>

                            <div class="md:col-span-2">
                                <label :class="claseLabel" for="observaciones">Observaciones <span class="font-normal text-[#94A3B8]">(salen en el PDF)</span></label>
                                <textarea
                                    id="observaciones"
                                    v-model="form.observaciones"
                                    rows="2"
                                    maxlength="1000"
                                    :class="[claseInput, 'h-auto py-2']"
                                    placeholder="Ej. Incluye instalación. Precios sujetos a stock."
                                />
                                <p v-if="form.errors.observaciones" :class="claseError">{{ form.errors.observaciones }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- ============ 2. Productos ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">2</span> Productos a cotizar</h2>

                        <!-- celular: una tarjeta por producto -->
                        <div v-if="filas.length" class="space-y-3 md:hidden">
                            <div
                                v-for="(fila, i) in filas"
                                :key="fila.presentacion_id"
                                class="rounded-2xl border border-[#E2E8F0] p-3 dark:border-neutral-800"
                            >
                                <div class="flex items-start gap-3">
                                    <div class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-[#F8FAFC] text-[#CBD5E1] dark:bg-neutral-800 dark:text-neutral-600">
                                        <img v-if="fila.producto.imagen_url" :src="fila.producto.imagen_url" :alt="fila.producto.nombre" class="size-full bg-white object-contain" />
                                        <Package v-else class="size-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm leading-snug font-semibold">{{ fila.producto.nombre }}</p>
                                        <p class="text-xs text-[#94A3B8]">
                                            <span class="font-mono">{{ fila.producto.codigo_interno }}</span>
                                            <template v-if="fila.producto.presentaciones.length === 1"> · {{ presentacionDe(fila).nombre }}</template>
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="-mt-1 -mr-1 grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        :aria-label="`Quitar ${fila.producto.nombre}`"
                                        @click="filas.splice(i, 1)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>

                                <select
                                    v-if="fila.producto.presentaciones.length > 1"
                                    v-model="fila.presentacion_id"
                                    aria-label="Presentación"
                                    :class="[claseCelda, 'mt-3 h-10 w-full text-base']"
                                    @change="alCambiarPresentacion(fila)"
                                >
                                    <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">
                                        {{ pres.nombre }} · {{ soles(pres.precio_venta) }}
                                    </option>
                                </select>

                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <div>
                                        <span class="mb-1 block text-xs text-[#64748B] dark:text-neutral-400">Cantidad</span>
                                        <div :class="[claseGrupo, 'h-11']">
                                            <button type="button" :class="[claseBotonPaso, 'w-11']" aria-label="Restar" :disabled="Number(fila.cantidad) <= cantidadMinima(fila)" @click="cambiarCantidad(fila, -1)">
                                                <Minus class="size-4" />
                                            </button>
                                            <input
                                                v-model="fila.cantidad"
                                                type="number"
                                                inputmode="decimal"
                                                :step="fila.producto.permite_fraccion ? '0.001' : '1'"
                                                min="0"
                                                aria-label="Cantidad"
                                                :class="[claseNumeroGrupo, 'text-base']"
                                                @focus="$event.target.select()"
                                            />
                                            <button type="button" :class="[claseBotonPaso, 'w-11']" aria-label="Sumar" @click="cambiarCantidad(fila, 1)">
                                                <Plus class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="mb-1 flex items-center justify-between text-xs text-[#64748B] dark:text-neutral-400">
                                            Precio unit.
                                            <button v-if="precioCambiado(fila)" type="button" class="font-medium text-amber-600 dark:text-amber-400" @click="fila.precio = ''">Volver a lista</button>
                                        </span>
                                        <div :class="[claseGrupo, 'h-11 px-3', clasePrecio(fila), !puede('pos.precio_manual') && claseSoloLectura]">
                                            <span class="text-sm text-[#94A3B8]">S/</span>
                                            <input
                                                :value="textoPrecio(fila)"
                                                type="text"
                                                inputmode="decimal"
                                                autocomplete="off"
                                                aria-label="Precio unitario"
                                                :readonly="!puede('pos.precio_manual')"
                                                :class="[claseNumeroGrupo, 'text-right text-base']"
                                                @focus="puede('pos.precio_manual') && empezarEdicionPrecio(fila, $event)"
                                                @input="escribirPrecio(fila, $event.target.value)"
                                                @blur="terminarEdicionPrecio(fila)"
                                                @keydown.enter.prevent="$event.target.blur()"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-3 border-t border-[#F1F5F9] pt-3 dark:border-neutral-800">
                                    <button
                                        v-if="!fila.conDescuento && !(descuentoFila(fila) > 0)"
                                        type="button"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2 text-sm font-medium text-[#64748B] hover:bg-slate-50 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                        @click="fila.conDescuento = true"
                                    >
                                        <Tag class="size-4" />
                                        Descuento
                                    </button>
                                    <div v-else :class="[claseGrupo, 'h-9 w-32 px-2.5', descuentoInvalido(fila) && '!border-red-400']">
                                        <span class="text-xs whitespace-nowrap text-amber-600 dark:text-amber-400">− S/</span>
                                        <input
                                            v-model="fila.descuento"
                                            type="number"
                                            inputmode="decimal"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                            aria-label="Descuento"
                                            :class="[claseNumeroGrupo, 'text-right']"
                                        />
                                    </div>
                                    <div class="text-right">
                                        <span v-if="esMayorista(fila) && !precioCambiado(fila)" class="mr-1.5 rounded-full bg-emerald-100 px-2 py-0.5 align-middle text-[11px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">Mayorista</span>
                                        <span class="align-middle text-base font-bold tabular-nums">{{ soles(totalFila(fila)) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PC y tablet: tabla -->
                        <div v-if="filas.length" class="@container -mx-5 hidden overflow-x-auto sm:-mx-6 md:block">
                            <table class="w-full min-w-[44rem] text-left text-sm">
                                <thead class="border-y border-[#E2E8F0] bg-slate-50 text-xs text-[#64748B] dark:border-neutral-800 dark:bg-neutral-950/50 dark:text-neutral-400">
                                    <tr>
                                        <th class="py-2.5 pl-5 font-semibold sm:pl-6">Producto</th>
                                        <th class="px-2 py-2.5 text-center font-semibold">Cantidad</th>
                                        <th class="px-2 py-2.5 text-right font-semibold">Precio unit.</th>
                                        <th class="px-2 py-2.5 text-right font-semibold">Descuento</th>
                                        <th class="px-2 py-2.5 text-right font-semibold">Importe</th>
                                        <th class="w-12 py-2.5 pr-5 sm:pr-6" />
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#F1F5F9] dark:divide-neutral-800">
                                    <tr v-for="(fila, i) in filas" :key="fila.presentacion_id" class="group transition-colors hover:bg-slate-50/60 dark:hover:bg-neutral-800/30">
                                        <td class="py-3 pl-5 sm:pl-6">
                                            <div class="flex min-w-48 items-center gap-3">
                                                <div class="relative grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-[#F8FAFC] text-[#CBD5E1] dark:bg-neutral-800 dark:text-neutral-600">
                                                    <img v-if="fila.producto.imagen_url" :src="fila.producto.imagen_url" :alt="fila.producto.nombre" class="size-full bg-white object-contain" />
                                                    <Package v-else class="size-5" />
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="leading-snug font-semibold">{{ fila.producto.nombre }}</p>
                                                    <p class="text-xs text-[#94A3B8]">
                                                        <span class="font-mono">{{ fila.producto.codigo_interno }}</span>
                                                        <template v-if="fila.producto.presentaciones.length === 1"> · {{ presentacionDe(fila).nombre }}</template>
                                                        <span v-if="fila.producto.afectacion !== 'gravado'" class="capitalize"> · {{ fila.producto.afectacion }}</span>
                                                    </p>
                                                    <!-- varias presentaciones (unidad, caja, saco...): se elige aquí mismo -->
                                                    <select
                                                        v-if="fila.producto.presentaciones.length > 1"
                                                        v-model="fila.presentacion_id"
                                                        aria-label="Presentación"
                                                        class="mt-1 h-7 max-w-full rounded-lg border border-stone-200 bg-white pr-6 pl-2 text-xs font-medium focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                                        @change="alCambiarPresentacion(fila)"
                                                    >
                                                        <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">{{ pres.nombre }} · {{ soles(pres.precio_venta) }}</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-2 py-3">
                                            <div :class="[claseGrupo, 'mx-auto h-10 w-28']">
                                                <button type="button" :class="[claseBotonPaso, 'w-9']" aria-label="Restar" title="Restar" :disabled="Number(fila.cantidad) <= cantidadMinima(fila)" @click="cambiarCantidad(fila, -1)">
                                                    <Minus class="size-3.5" />
                                                </button>
                                                <input
                                                    v-model="fila.cantidad"
                                                    type="number"
                                                    :step="fila.producto.permite_fraccion ? '0.001' : '1'"
                                                    min="0"
                                                    aria-label="Cantidad"
                                                    :class="claseNumeroGrupo"
                                                    @focus="$event.target.select()"
                                                />
                                                <button type="button" :class="[claseBotonPaso, 'w-9']" aria-label="Sumar" title="Sumar" @click="cambiarCantidad(fila, 1)">
                                                    <Plus class="size-3.5" />
                                                </button>
                                            </div>
                                        </td>
                                        <td class="px-2 py-3">
                                            <div class="flex items-center justify-end gap-1">
                                                <button
                                                    v-if="precioCambiado(fila)"
                                                    type="button"
                                                    class="grid size-7 place-items-center rounded-lg text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/40"
                                                    :title="`Volver al precio de lista (${soles(precioLista(fila))})`"
                                                    aria-label="Volver al precio de lista"
                                                    @click="fila.precio = ''"
                                                >
                                                    <RotateCcw class="size-3.5" />
                                                </button>
                                                <div
                                                    :class="[claseGrupo, 'h-10 w-24 px-2.5', clasePrecio(fila), !puede('pos.precio_manual') && claseSoloLectura]"
                                                    :title="puede('pos.precio_manual') ? 'Escribe otro precio para esta cotización' : 'Tu rol cotiza al precio de lista'"
                                                >
                                                    <span class="text-xs text-[#94A3B8]">S/</span>
                                                    <input
                                                        :value="textoPrecio(fila)"
                                                        type="text"
                                                        inputmode="decimal"
                                                        autocomplete="off"
                                                        aria-label="Precio unitario"
                                                        :readonly="!puede('pos.precio_manual')"
                                                        :class="[claseNumeroGrupo, 'text-right']"
                                                        @focus="puede('pos.precio_manual') && empezarEdicionPrecio(fila, $event)"
                                                        @input="escribirPrecio(fila, $event.target.value)"
                                                        @blur="terminarEdicionPrecio(fila)"
                                                        @keydown.enter.prevent="$event.target.blur()"
                                                    />
                                                </div>
                                            </div>
                                            <p v-if="esMayorista(fila) && !precioCambiado(fila)" class="mt-0.5 text-right text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Precio mayorista</p>
                                        </td>
                                        <td class="px-2 py-3">
                                            <div :class="[claseGrupo, 'ml-auto h-10 w-24 px-2.5', descuentoInvalido(fila) && '!border-red-400']">
                                                <span class="text-xs text-[#94A3B8]">S/</span>
                                                <input
                                                    v-model="fila.descuento"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0.00"
                                                    aria-label="Descuento"
                                                    :class="[claseNumeroGrupo, 'text-right']"
                                                />
                                            </div>
                                        </td>
                                        <td class="px-2 py-3 text-right text-base font-bold whitespace-nowrap tabular-nums">{{ soles(totalFila(fila)) }}</td>
                                        <td class="py-3 pr-5 text-right sm:pr-6">
                                            <button
                                                type="button"
                                                class="inline-grid size-9 place-items-center rounded-lg text-neutral-400 transition-colors hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                                title="Quitar"
                                                :aria-label="`Quitar ${fila.producto.nombre}`"
                                                @click="filas.splice(i, 1)"
                                            >
                                                <Trash2 class="size-4" />
                                            </button>
                                        </td>
                                    </tr>
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
                                    <span class="min-w-0 flex-1 truncate font-medium">{{ p.nombre }}</span>
                                    <span class="shrink-0 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ p.codigo_interno }}</span>
                                    <span class="w-20 shrink-0 text-right text-sm font-semibold tabular-nums">
                                        {{ soles((p.presentaciones.find((x) => x.es_default) ?? p.presentaciones[0]).precio_venta) }}
                                    </span>
                                </button>
                            </div>
                        </div>

                        <EscanerCamara
                            v-if="puedeEscanear"
                            :abierto="escanerAbierto"
                            :al-leer="leerCodigoCamara"
                            @cerrar="escanerAbierto = false"
                        >
                            <!-- los productos cotizados quedan a la vista bajo la cámara mientras se escanea -->
                            <div class="mb-2 flex items-center gap-2 px-1">
                                <p class="text-base font-semibold">Productos a cotizar</p>
                                <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    {{ filas.length }} {{ filas.length === 1 ? 'item' : 'items' }}
                                </span>
                            </div>
                            <p v-if="!filas.length" class="rounded-2xl border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                Aún no hay productos.<br />Apunta la cámara a un código de barras.
                            </p>
                            <div class="space-y-2">
                                <div
                                    v-for="(fila, i) in filas"
                                    :id="`escaner-fila-${fila.presentacion_id}`"
                                    :key="fila.presentacion_id"
                                    class="flex items-center gap-3 rounded-2xl border bg-white p-3 transition-colors dark:bg-neutral-900"
                                    :class="ultimoEscaneado === fila.presentacion_id
                                        ? 'border-emerald-400 ring-2 ring-emerald-400/25'
                                        : 'border-stone-200 dark:border-neutral-800'"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold">{{ fila.producto.nombre }}</p>
                                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ soles(precioUnitario(fila)) }} c/u · {{ presentacionDe(fila)?.nombre }}</p>
                                        <div class="mt-1 flex items-center gap-1">
                                            <button
                                                type="button"
                                                class="grid size-8 place-items-center rounded-lg border border-stone-200 disabled:opacity-40 dark:border-neutral-700"
                                                aria-label="Restar uno"
                                                :disabled="Number(fila.cantidad) <= 1"
                                                @click="fila.cantidad = Number(fila.cantidad) - 1"
                                            >
                                                <Minus class="size-3.5" />
                                            </button>
                                            <span class="min-w-8 text-center text-sm font-semibold">{{ fila.cantidad }}</span>
                                            <button
                                                type="button"
                                                class="grid size-8 place-items-center rounded-lg border border-stone-200 dark:border-neutral-700"
                                                aria-label="Sumar uno"
                                                @click="fila.cantidad = Number(fila.cantidad) + 1"
                                            >
                                                <Plus class="size-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1.5 self-stretch">
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-lg text-red-500 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                            :aria-label="`Quitar ${fila.producto.nombre}`"
                                            @click="filas.splice(i, 1)"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                        <p class="mt-auto text-base font-bold tabular-nums">{{ soles(totalFila(fila)) }}</p>
                                    </div>
                                </div>
                            </div>

                            <template #pie="{ cerrar }">
                                <div class="flex items-center gap-2">
                                    <div class="min-w-0 flex-1 px-1">
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Total · {{ unidades }} {{ unidades === 1 ? 'unidad' : 'unidades' }}</p>
                                        <p class="text-lg leading-tight font-bold tracking-tight">{{ soles(totales.total) }}</p>
                                    </div>
                                    <button type="button" class="h-11 shrink-0 rounded-xl bg-emerald-600 px-8 text-sm font-semibold text-white" @click="cerrar">
                                        Listo
                                    </button>
                                </div>
                            </template>
                        </EscanerCamara>

                        <p v-if="form.errors.items" :class="claseError">{{ form.errors.items }}</p>

                        <p class="mt-4 flex items-start gap-2 rounded-xl bg-indigo-50 px-3 py-2.5 text-xs text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300">
                            <Tag class="mt-0.5 size-4 shrink-0" />
                            <span>
                                Los precios incluyen IGV.
                                <template v-if="puede('pos.precio_manual')">Puedes escribir un precio especial para este cliente: se respetará al vender mientras la cotización siga vigente.</template>
                                <template v-else>Tu rol cotiza al precio de lista.</template>
                            </span>
                        </p>
                    </section>
                </div>

                <!-- ============ Resumen ============ -->
                <aside class="space-y-4 lg:sticky lg:top-20">
                    <section :class="claseTarjeta">
                        <h2 class="mb-4 font-semibold tracking-tight">Resumen</h2>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Productos</dt>
                                <dd class="font-medium tabular-nums">{{ filas.length }}</dd>
                            </div>
                            <div v-if="totales.gravado > 0" class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Op. gravada</dt>
                                <dd class="font-medium tabular-nums">{{ soles(totales.gravado) }}</dd>
                            </div>
                            <div v-if="totales.exonerado > 0" class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Op. exonerada</dt>
                                <dd class="font-medium tabular-nums">{{ soles(totales.exonerado) }}</dd>
                            </div>
                            <div v-if="totales.inafecto > 0" class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">Op. inafecta</dt>
                                <dd class="font-medium tabular-nums">{{ soles(totales.inafecto) }}</dd>
                            </div>
                            <div v-if="totales.gravado > 0" class="flex justify-between">
                                <dt class="text-[#64748B] dark:text-neutral-400">IGV (18%)</dt>
                                <dd class="font-medium tabular-nums">{{ soles(totales.igv) }}</dd>
                            </div>
                            <div v-if="totales.descuentos > 0" class="flex justify-between text-amber-700 dark:text-amber-400">
                                <dt>Descuentos incluidos</dt>
                                <dd class="font-medium tabular-nums">−{{ soles(totales.descuentos) }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex items-baseline justify-between border-t border-[#E2E8F0] pt-4 dark:border-neutral-800">
                            <span class="text-base font-semibold">Total</span>
                            <span class="text-2xl font-bold tracking-tight tabular-nums">{{ soles(totales.total) }}</span>
                        </div>

                        <div class="mt-4 space-y-2 rounded-xl bg-slate-50 p-3 text-xs text-[#475569] dark:bg-neutral-950/60 dark:text-neutral-300">
                            <p class="flex items-start gap-2">
                                <Info class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                No descuenta stock ni entra a caja. Al guardar podrás enviarla por WhatsApp o correo.
                            </p>
                        </div>

                        <p v-if="motivoNoGuardar" class="mt-3 text-xs font-medium text-amber-700 dark:text-amber-400">{{ motivoNoGuardar }}</p>

                        <button
                            type="button"
                            :disabled="!puedeGuardar || form.processing"
                            class="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="guardar"
                        >
                            {{ form.processing ? 'Guardando...' : esEdicion ? 'Guardar cambios' : 'Guardar cotización' }}
                        </button>
                        <Link
                            href="/cotizaciones"
                            class="mt-2 flex h-10 items-center justify-center rounded-xl text-sm font-medium text-[#64748B] hover:bg-slate-50 lg:hidden dark:text-neutral-400 dark:hover:bg-neutral-800"
                        >
                            Cancelar
                        </Link>
                    </section>
                </aside>
            </div>
        </div>

        <!-- Modal nuevo cliente -->
        <Teleport to="body">
            <div v-if="modalCliente" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalCliente = false" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarCliente"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Nuevo cliente</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalCliente = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="claseLabel" for="nc_tipo">Documento</label>
                                <select id="nc_tipo" v-model="nuevoCliente.tipo_documento_codigo" :class="claseInput">
                                    <option v-for="t in tiposDocumento" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="claseLabel" for="nc_numero">Número</label>
                                <div class="relative">
                                    <input
                                        id="nc_numero"
                                        v-model="nuevoCliente.numero_documento"
                                        type="text"
                                        maxlength="15"
                                        :disabled="esSinDocumento(nuevoCliente.tipo_documento_codigo)"
                                        :class="[claseInput, 'pr-10 disabled:cursor-not-allowed disabled:bg-stone-100 dark:disabled:bg-neutral-800']"
                                        @blur="consultarDocumentoCliente"
                                    />
                                    <button
                                        type="button"
                                        class="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                        title="Buscar en RENIEC / SUNAT"
                                        :disabled="consultandoDoc || esSinDocumento(nuevoCliente.tipo_documento_codigo)"
                                        @click="consultarDocumentoCliente"
                                    >
                                        <LoaderCircle v-if="consultandoDoc" class="size-4 animate-spin" />
                                        <Search v-else class="size-4" />
                                    </button>
                                </div>
                                <p v-if="ayudaDocumento(nuevoCliente.tipo_documento_codigo)" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                    {{ ayudaDocumento(nuevoCliente.tipo_documento_codigo) }}
                                </p>
                                <p v-if="erroresCliente.numero_documento" :class="claseError">{{ erroresCliente.numero_documento[0] }}</p>
                            </div>
                        </div>
                        <div>
                            <label :class="claseLabel" for="nc_nombre">Nombre / Razón social *</label>
                            <input id="nc_nombre" v-model="nuevoCliente.nombre" type="text" :class="claseInput" />
                            <p v-if="erroresCliente.nombre" :class="claseError">{{ erroresCliente.nombre[0] }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="nc_telefono">Teléfono</label>
                            <input id="nc_telefono" v-model="nuevoCliente.telefono" type="text" :class="claseInput" placeholder="999 999 999" />
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalCliente = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="guardandoCliente"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ guardandoCliente ? 'Guardando...' : 'Guardar y usar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
