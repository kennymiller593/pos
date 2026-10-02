<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import {
    Building2, Bus, CarFront, ChevronRight, Info, LoaderCircle, MapPin, Minus, Navigation, Package, Plus, ScanBarcode, Search, Trash2, TriangleAlert,
    Truck, UserRound, X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import BuscadorUbigeo from '@/Components/BuscadorUbigeo.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { ayudaDocumento, esSinDocumento } from '@/composables/documentoIdentidad'
import { puedeEscanear } from '@/composables/escaner'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    productos: { type: Array, required: true },
    // sucursal desde la que sale la mercadería: { id, nombre, direccion, lugar, completa }
    partida: { type: Object, default: null },
    sucursales: { type: Array, default: () => [] },
    tiposDocumento: { type: Array, default: () => [] },
    // [{ codigo, nombre }] del catálogo 20 de SUNAT
    motivos: { type: Array, required: true },
    // vehículos, conductores y transportistas usados antes
    sugerencias: { type: Object, default: () => ({ vehiculos: [], conductores: [], transportistas: [] }) },
    // "Generar guía" desde una venta o una transferencia
    inicial: { type: Object, default: null },
    envioSunat: { type: Object, default: () => ({ activo: false, falta_credenciales: false }) },
})

const { puede } = usePermisos()

// fecha local (no UTC): de noche, toISOString() ya devuelve el día siguiente
function fechaLocal() {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = useForm({
    motivo_codigo: props.inicial?.motivo_codigo ?? '01',
    motivo_descripcion: '',
    fecha_traslado: fechaLocal(),
    peso_bruto: '',
    bultos: '',
    cliente_id: props.inicial?.cliente?.id ?? null,
    llegada_ubigeo: '',
    llegada_direccion: props.inicial?.llegada_direccion ?? '',
    sucursal_destino_id: props.inicial?.sucursal_destino_id ?? '',
    modalidad: '02',
    transportista_ruc: '',
    transportista_nombre: '',
    transportista_mtc: '',
    vehiculo_menor: false,
    vehiculo_placa: '',
    conductor_tipo_doc: '1',
    conductor_numero_doc: '',
    conductor_nombres: '',
    conductor_apellidos: '',
    conductor_licencia: '',
    observaciones: '',
    comprobante_id: props.inicial?.comprobante_id ?? null,
    transferencia_id: props.inicial?.transferencia_id ?? null,
    items: [],
})

const entreLocales = computed(() => form.motivo_codigo === '04')
const destinos = computed(() => props.sucursales.filter((s) => s.id !== props.partida?.id))
const destinoElegido = computed(() => props.sucursales.find((s) => s.id === form.sucursal_destino_id) ?? null)

const nombreMotivo = (codigo) => (codigo === '04' ? 'Entre mis locales' : props.motivos.find((m) => m.codigo === codigo)?.nombre ?? '')

const MOTIVOS_AYUDA = {
    '01': 'Entregas mercadería que vendiste a un cliente.',
    '04': 'Mueves mercadería entre tus propios locales.',
    13: 'Otro motivo: devolución, muestra, consignación...',
}

// ================= destinatario =================
const clienteSeleccionado = ref(props.inicial?.cliente ?? null)
const buscarCliente = ref('')
const resultadosCliente = ref([])
const clienteSinDocumento = computed(() => !!clienteSeleccionado.value && (!clienteSeleccionado.value.numero_documento || esSinDocumento(clienteSeleccionado.value.tipo_documento_codigo)))

watchDebounced(buscarCliente, async (texto) => {
    if (!texto.trim()) {
        resultadosCliente.value = []
        return
    }
    try {
        const r = await fetch(`/guias/clientes?buscar=${encodeURIComponent(texto)}`, { headers: { Accept: 'application/json' } })
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
    if (!form.llegada_direccion.trim() && cliente.direccion) form.llegada_direccion = cliente.direccion
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
        const r = await fetch('/guias/clientes', {
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

// ================= transporte =================
const exigeVehiculo = computed(() => form.modalidad === '02' && !form.vehiculo_menor)
const placaLimpia = computed(() => form.vehiculo_placa.replace(/[^A-Za-z0-9]/g, '').toUpperCase())
const licenciaLimpia = computed(() => form.conductor_licencia.replace(/[^A-Za-z0-9]/g, '').toUpperCase())

function usarConductor(c) {
    form.conductor_tipo_doc = c.tipo_doc
    form.conductor_numero_doc = c.numero_doc
    form.conductor_nombres = c.nombres ?? ''
    form.conductor_apellidos = c.apellidos ?? ''
    form.conductor_licencia = c.licencia ?? ''
}

function usarTransportista(t) {
    form.transportista_ruc = t.ruc
    form.transportista_nombre = t.nombre ?? ''
    form.transportista_mtc = t.mtc ?? ''
}

const consultandoConductor = ref(false)
async function consultarConductor() {
    const numero = form.conductor_numero_doc.trim()
    if (form.conductor_tipo_doc !== '1' || !/^\d{8}$/.test(numero) || consultandoConductor.value) return
    consultandoConductor.value = true
    try {
        const r = await fetch(`/consultas/dni/${numero}`, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (r.ok) {
            // solo completa lo que está vacío: no pisa lo que el usuario ya escribió
            if (datos.nombres && !form.conductor_nombres.trim()) form.conductor_nombres = datos.nombres
            if (datos.apellidos && !form.conductor_apellidos.trim()) form.conductor_apellidos = datos.apellidos
        }
    } catch {
        // consulta opcional
    } finally {
        consultandoConductor.value = false
    }
}

const consultandoTransportista = ref(false)
async function consultarTransportista() {
    const ruc = form.transportista_ruc.trim()
    if (!/^\d{11}$/.test(ruc) || consultandoTransportista.value) return
    consultandoTransportista.value = true
    try {
        const r = await fetch(`/consultas/ruc/${ruc}`, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (r.ok && datos.razon_social && !form.transportista_nombre.trim()) form.transportista_nombre = datos.razon_social
    } catch {
        // consulta opcional
    } finally {
        consultandoTransportista.value = false
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

// filas: { producto, presentacion_id, cantidad }
const filas = ref([])

function agregarProducto(producto, presentacion = null) {
    const def = presentacion ?? producto.presentaciones.find((p) => p.es_default) ?? producto.presentaciones[0]
    let fila = filas.value.find((f) => f.presentacion_id === def.id)
    if (fila) {
        fila.cantidad = Number(fila.cantidad) + 1
    } else {
        fila = { producto, presentacion_id: def.id, cantidad: 1 }
        filas.value.push(fila)
    }
    buscarProducto.value = ''
    return fila
}

const presentacionDe = (fila) => fila.producto.presentaciones.find((p) => p.id === fila.presentacion_id)

// botones − / +: de uno en uno (una cantidad menor a 1, p. ej. 0.5 kg, se escribe en el campo)
function cambiarCantidad(fila, delta) {
    const nueva = Math.round((Number(fila.cantidad || 0) + delta) * 1000) / 1000
    if (nueva > 0) fila.cantidad = nueva
}

const unidades = computed(() => filas.value.reduce((n, f) => n + Number(f.cantidad || 0), 0))

// ---- productos que llegan de la venta o la transferencia ----
const itemsDescartados = ref(0)
for (const item of props.inicial?.items ?? []) {
    const producto = props.productos.find((p) => p.presentaciones.some((pres) => pres.id === item.presentacion_id))
    if (!producto) {
        itemsDescartados.value++
        continue
    }
    filas.value.push({ producto, presentacion_id: item.presentacion_id, cantidad: item.cantidad })
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
    return { ok: true, mensaje: `${encontrado.producto.nombre} agregado`, detalle: `x${fila.cantidad}` }
}

// al cambiar de motivo se limpia lo que ya no aplica
watch(() => form.motivo_codigo, (motivo) => {
    if (motivo === '04') {
        quitarCliente()
    } else {
        form.sucursal_destino_id = ''
        form.transferencia_id = null
    }
    if (motivo !== '01') form.comprobante_id = null
})

// ================= guardar =================
// primer dato que falta (se muestra en el resumen en vez de dejar el botón apagado sin explicación)
const motivoNoGuardar = computed(() => {
    if (!props.partida?.completa) return 'Completa la dirección y el distrito de tu sucursal.'
    if (form.motivo_codigo === '13' && !form.motivo_descripcion.trim()) return 'Describe el motivo del traslado.'
    if (!form.fecha_traslado) return 'Indica la fecha de inicio del traslado.'
    if (!(Number(form.peso_bruto) > 0)) return 'Indica el peso bruto total en kilos.'
    if (entreLocales.value) {
        if (!form.sucursal_destino_id) return 'Elige la sucursal de destino.'
        if (destinoElegido.value && !destinoElegido.value.completa) return 'La sucursal de destino no tiene dirección o distrito.'
    } else {
        if (!form.cliente_id) return 'Elige el destinatario.'
        if (clienteSinDocumento.value) return 'El destinatario necesita DNI o RUC.'
        if (!form.llegada_direccion.trim()) return 'Escribe la dirección de llegada.'
        if (!form.llegada_ubigeo) return 'Elige el distrito de llegada.'
    }
    if (form.modalidad === '01') {
        if (!/^\d{11}$/.test(form.transportista_ruc.trim())) return 'Ingresa el RUC del transportista (11 dígitos).'
        if (!form.transportista_nombre.trim()) return 'Ingresa la razón social del transportista.'
    } else if (exigeVehiculo.value) {
        if (!/^[A-Z0-9]{6,8}$/.test(placaLimpia.value)) return 'Ingresa la placa del vehículo.'
        if (!form.conductor_numero_doc.trim()) return 'Ingresa el documento del conductor.'
        if (!form.conductor_nombres.trim() || !form.conductor_apellidos.trim()) return 'Completa nombres y apellidos del conductor.'
        if (!/^[A-Z0-9]{9,10}$/.test(licenciaLimpia.value)) return 'Ingresa la licencia de conducir (9 o 10 caracteres).'
    }
    if (!filas.value.length) return 'Agrega al menos un producto.'
    if (filas.value.some((f) => !(Number(f.cantidad) > 0))) return 'Hay productos con cantidad 0.'
    return ''
})

function guardar() {
    form.items = filas.value.map((f) => ({ presentacion_id: f.presentacion_id, cantidad: Number(f.cantidad) }))

    form.transform((data) => ({
        ...data,
        motivo_descripcion: data.motivo_codigo === '13' ? data.motivo_descripcion.trim() : null,
        peso_bruto: Number(data.peso_bruto),
        bultos: data.bultos === '' ? null : Number(data.bultos),
        llegada_ubigeo: data.llegada_ubigeo || null,
        llegada_direccion: data.llegada_direccion.trim() || null,
        sucursal_destino_id: data.sucursal_destino_id || null,
        observaciones: data.observaciones.trim() || null,
        vehiculo_placa: placaLimpia.value || null,
        conductor_licencia: licenciaLimpia.value || null,
        conductor_numero_doc: data.conductor_numero_doc.trim() || null,
    })).post('/guias')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseTarjeta = 'rounded-2xl border border-[#E2E8F0] bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900'
const claseTituloSeccion = 'mb-5 flex items-center gap-2.5 font-semibold tracking-tight'
const claseNumero = 'grid size-6 place-items-center rounded-lg bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
// campo compuesto (− cantidad +, "peso KG"): un solo borde que se ilumina al enfocar cualquiera de sus partes
const claseGrupo =
    'flex items-center overflow-hidden rounded-xl border border-stone-300 bg-white transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/30 dark:border-neutral-700 dark:bg-neutral-950'
const claseBotonPaso =
    'grid h-full shrink-0 place-items-center text-[#64748B] transition-colors hover:bg-slate-100 hover:text-[#0F172A] active:bg-slate-200 disabled:pointer-events-none disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
const claseNumeroGrupo =
    'h-full w-full min-w-0 flex-1 bg-transparent text-center text-sm font-medium tabular-nums [appearance:textfield] placeholder:font-normal placeholder:text-neutral-400 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none'
const claseOpcion = (activa) => [
    'flex flex-1 items-start gap-3 rounded-xl border p-3 text-left transition-colors',
    activa
        ? 'border-emerald-500 bg-emerald-50/70 ring-1 ring-emerald-500 dark:bg-emerald-950/30'
        : 'border-stone-200 hover:border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800/60',
]
const claseChip =
    'inline-flex h-8 max-w-full items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 text-xs font-medium text-[#475569] transition-colors hover:border-emerald-400 hover:text-emerald-700 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300 dark:hover:border-emerald-600 dark:hover:text-emerald-400'
</script>

<template>
    <AppLayout titulo="Nueva guía de remisión">
        <div>
            <!-- ============ Encabezado ============ -->
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <nav class="mb-2 flex items-center gap-1.5 text-xs text-[#64748B] dark:text-neutral-400">
                        <Link href="/dashboard" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Inicio</Link>
                        <ChevronRight class="size-3.5" />
                        <Link href="/guias" class="hover:text-[#0F172A] dark:hover:text-neutral-100">Guías de remisión</Link>
                        <ChevronRight class="size-3.5" />
                        <span class="text-[#0F172A] dark:text-neutral-200">Nueva guía</span>
                    </nav>
                    <div class="flex items-center gap-3">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <Navigation class="size-6" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-2xl font-bold tracking-tight">Nueva guía de remisión</h1>
                            <p class="text-sm text-[#64748B] dark:text-neutral-400">
                                Sustenta el traslado de la mercadería ante SUNAT. No mueve stock: eso lo hacen la venta y la transferencia.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="hidden shrink-0 gap-2 lg:flex">
                    <Link
                        href="/guias"
                        class="inline-flex h-11 items-center gap-2 rounded-xl border border-[#E2E8F0] bg-white px-4 text-sm font-medium hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        <X class="size-4" />
                        Cancelar
                    </Link>
                </div>
            </div>

            <p v-if="inicial?.origen" class="mb-4 flex items-start gap-2 rounded-xl bg-sky-50 px-3 py-2.5 text-sm text-sky-900 dark:bg-sky-500/10 dark:text-sky-200">
                <Info class="mt-0.5 size-4 shrink-0" />
                <span>
                    Guía generada desde: <strong>{{ inicial.origen }}</strong>. Revisa los datos y completa el transporte.
                    <template v-if="itemsDescartados"> {{ itemsDescartados }} producto(s) ya no están activos y no se cargaron.</template>
                </span>
            </p>

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div class="min-w-0 space-y-6">
                    <!-- ============ 1. Traslado ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">1</span> ¿Por qué se traslada?</h2>

                        <div class="grid gap-2 sm:grid-cols-3">
                            <button
                                v-for="m in motivos"
                                :key="m.codigo"
                                type="button"
                                :class="claseOpcion(form.motivo_codigo === m.codigo)"
                                :aria-pressed="form.motivo_codigo === m.codigo"
                                @click="form.motivo_codigo = m.codigo"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ nombreMotivo(m.codigo) }}</span>
                                    <span class="block text-xs text-[#64748B] dark:text-neutral-400">{{ MOTIVOS_AYUDA[m.codigo] }}</span>
                                </span>
                            </button>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                            <div v-if="form.motivo_codigo === '13'" class="sm:col-span-2 md:col-span-3">
                                <label :class="claseLabel" for="motivo_descripcion">Describe el motivo *</label>
                                <input id="motivo_descripcion" v-model="form.motivo_descripcion" type="text" maxlength="100" :class="claseInput" placeholder="Ej. Devolución de mercadería al proveedor" />
                                <p v-if="form.errors.motivo_descripcion" :class="claseError">{{ form.errors.motivo_descripcion }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="fecha_traslado">Inicio del traslado *</label>
                                <input id="fecha_traslado" v-model="form.fecha_traslado" type="date" :min="fechaLocal()" :class="claseInput" />
                                <p v-if="form.errors.fecha_traslado" :class="claseError">{{ form.errors.fecha_traslado }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="peso_bruto">Peso bruto total *</label>
                                <div :class="[claseGrupo, 'h-10 px-3']">
                                    <input
                                        id="peso_bruto"
                                        v-model="form.peso_bruto"
                                        type="number"
                                        inputmode="decimal"
                                        step="0.001"
                                        min="0"
                                        placeholder="0.00"
                                        :class="[claseNumeroGrupo, 'text-left']"
                                    />
                                    <span class="text-xs font-medium text-[#94A3B8]">KG</span>
                                </div>
                                <p v-if="form.errors.peso_bruto" :class="claseError">{{ form.errors.peso_bruto }}</p>
                                <p v-else class="mt-1 text-xs text-[#94A3B8]">De toda la carga, con su embalaje.</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="bultos">Bultos <span class="font-normal text-[#94A3B8]">(opcional)</span></label>
                                <input id="bultos" v-model="form.bultos" type="number" inputmode="numeric" step="1" min="1" :class="claseInput" placeholder="Ej. 4 cajas" />
                                <p v-if="form.errors.bultos" :class="claseError">{{ form.errors.bultos }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- ============ 2. Ruta ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">2</span> ¿De dónde a dónde?</h2>

                        <div class="grid gap-4 md:grid-cols-2">
                            <!-- Partida: siempre la sucursal -->
                            <div>
                                <p :class="claseLabel">Punto de partida</p>
                                <div class="flex items-start gap-3 rounded-xl border border-stone-200 bg-stone-50/70 p-3 dark:border-neutral-700 dark:bg-neutral-950/50">
                                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-emerald-600 dark:bg-neutral-900 dark:text-emerald-400">
                                        <MapPin class="size-5" />
                                    </div>
                                    <div class="min-w-0 flex-1 text-sm">
                                        <p class="font-semibold">{{ partida?.nombre ?? 'Sin sucursal' }}</p>
                                        <template v-if="partida?.completa">
                                            <p class="text-[#475569] dark:text-neutral-300">{{ partida.direccion }}</p>
                                            <p class="text-xs text-[#94A3B8]">{{ partida.lugar }}</p>
                                        </template>
                                        <p v-else class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                            Falta la dirección o el distrito de esta sucursal.
                                            <Link v-if="puede('sucursales.gestionar')" href="/sucursales" class="underline">Completar en Sucursales</Link>
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-[#94A3B8]">La sucursal se cambia con el selector de la barra superior.</p>
                            </div>

                            <!-- Llegada entre locales -->
                            <div v-if="entreLocales">
                                <label :class="claseLabel" for="sucursal_destino">Sucursal de destino *</label>
                                <select id="sucursal_destino" v-model="form.sucursal_destino_id" :class="claseInput">
                                    <option value="" disabled>Elige el local de llegada</option>
                                    <option v-for="s in destinos" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                                </select>
                                <p v-if="form.errors.sucursal_destino_id" :class="claseError">{{ form.errors.sucursal_destino_id }}</p>
                                <p v-else-if="!destinos.length" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">Solo tienes una sucursal: no hay a dónde trasladar.</p>
                                <div v-else-if="destinoElegido" class="mt-2 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-neutral-950/60">
                                    <template v-if="destinoElegido.completa">
                                        <p class="text-[#475569] dark:text-neutral-300">{{ destinoElegido.direccion }}</p>
                                        <p class="text-xs text-[#94A3B8]">{{ destinoElegido.lugar }}</p>
                                    </template>
                                    <p v-else class="text-xs font-medium text-amber-700 dark:text-amber-400">Esa sucursal no tiene dirección o distrito. Complétalos en Sucursales.</p>
                                </div>
                            </div>

                            <!-- Destinatario (cliente) -->
                            <div v-else>
                                <label :class="claseLabel">Destinatario *</label>
                                <div v-if="clienteSeleccionado" class="flex items-center gap-3 rounded-xl border p-3" :class="clienteSinDocumento ? 'border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/20' : 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900/60 dark:bg-emerald-950/20'">
                                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-emerald-600 dark:bg-neutral-900 dark:text-emerald-400">
                                        <UserRound class="size-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold">{{ clienteSeleccionado.nombre }}</p>
                                        <p v-if="clienteSinDocumento" class="text-xs font-medium text-amber-700 dark:text-amber-400">Sin DNI ni RUC: SUNAT no acepta la guía. Elige otro o edítalo en Clientes.</p>
                                        <p v-else class="text-xs text-[#64748B] dark:text-neutral-400">Doc.: {{ clienteSeleccionado.numero_documento }}</p>
                                    </div>
                                    <button type="button" class="rounded-lg p-1.5 text-[#64748B] hover:bg-white hover:text-red-600 dark:hover:bg-neutral-900 dark:hover:text-red-400" title="Cambiar destinatario" @click="quitarCliente">
                                        <X class="size-4" />
                                    </button>
                                </div>
                                <div v-else class="flex gap-2">
                                    <div class="relative flex-1">
                                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                        <input v-model="buscarCliente" type="text" placeholder="Buscar por nombre, DNI o RUC..." :class="[claseInput, 'pl-9']" @keydown.enter.prevent />
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
                                        Nuevo
                                    </button>
                                </div>
                                <p v-if="form.errors.cliente_id" :class="claseError">{{ form.errors.cliente_id }}</p>
                            </div>

                            <template v-if="!entreLocales">
                                <div>
                                    <label :class="claseLabel" for="llegada_direccion">Dirección de llegada *</label>
                                    <input id="llegada_direccion" v-model="form.llegada_direccion" type="text" maxlength="250" :class="claseInput" placeholder="Calle, número, referencia" />
                                    <p v-if="form.errors.llegada_direccion" :class="claseError">{{ form.errors.llegada_direccion }}</p>
                                </div>
                                <div>
                                    <label :class="claseLabel">Distrito de llegada *</label>
                                    <BuscadorUbigeo v-model="form.llegada_ubigeo" placeholder="Busca el distrito..." />
                                    <p v-if="form.errors.llegada_ubigeo" :class="claseError">{{ form.errors.llegada_ubigeo }}</p>
                                </div>
                            </template>
                        </div>
                    </section>

                    <!-- ============ 3. Transporte ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">3</span> ¿Quién lo lleva?</h2>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button type="button" :class="claseOpcion(form.modalidad === '02')" :aria-pressed="form.modalidad === '02'" @click="form.modalidad = '02'">
                                <CarFront class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>
                                    <span class="block text-sm font-semibold">Transporte privado</span>
                                    <span class="block text-xs text-[#64748B] dark:text-neutral-400">Lo llevo yo, con mi vehículo o el del cliente.</span>
                                </span>
                            </button>
                            <button type="button" :class="claseOpcion(form.modalidad === '01')" :aria-pressed="form.modalidad === '01'" @click="form.modalidad = '01'">
                                <Bus class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span>
                                    <span class="block text-sm font-semibold">Transporte público</span>
                                    <span class="block text-xs text-[#64748B] dark:text-neutral-400">Lo lleva una empresa de transportes o courier.</span>
                                </span>
                            </button>
                        </div>

                        <!-- Privado -->
                        <div v-if="form.modalidad === '02'" class="mt-4 space-y-4">
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl bg-slate-50 px-3 py-2.5 text-sm dark:bg-neutral-950/60">
                                <input v-model="form.vehiculo_menor" type="checkbox" class="mt-0.5 size-4 accent-emerald-600" />
                                <span>
                                    <span class="font-medium">Es un vehículo menor</span>
                                    <span class="block text-xs text-[#64748B] dark:text-neutral-400">Moto, mototaxi o auto (categoría M1 o L): SUNAT no exige placa ni conductor.</span>
                                </span>
                            </label>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label :class="claseLabel" for="placa">Placa del vehículo {{ exigeVehiculo ? '*' : '' }}</label>
                                    <input id="placa" v-model="form.vehiculo_placa" type="text" maxlength="9" autocomplete="off" :class="[claseInput, 'font-mono uppercase']" placeholder="ABC123" />
                                    <p v-if="form.errors.vehiculo_placa" :class="claseError">{{ form.errors.vehiculo_placa }}</p>
                                    <div v-if="sugerencias.vehiculos.length" class="mt-2 flex flex-wrap gap-1.5">
                                        <button v-for="placa in sugerencias.vehiculos" :key="placa" type="button" :class="[claseChip, 'font-mono']" @click="form.vehiculo_placa = placa">
                                            <Truck class="size-3.5" />
                                            {{ placa }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 text-sm font-semibold">Conductor {{ exigeVehiculo ? '' : '(opcional)' }}</p>
                                <div v-if="sugerencias.conductores.length" class="mb-3 flex flex-wrap gap-1.5">
                                    <button v-for="c in sugerencias.conductores" :key="c.numero_doc" type="button" :class="claseChip" @click="usarConductor(c)">
                                        <UserRound class="size-3.5 shrink-0" />
                                        <span class="truncate">{{ c.nombres }} {{ c.apellidos }}</span>
                                    </button>
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label :class="claseLabel" for="conductor_doc">DNI del conductor {{ exigeVehiculo ? '*' : '' }}</label>
                                        <div class="relative">
                                            <input
                                                id="conductor_doc"
                                                v-model="form.conductor_numero_doc"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="15"
                                                :class="[claseInput, 'pr-10']"
                                                placeholder="12345678"
                                                @blur="consultarConductor"
                                            />
                                            <button
                                                type="button"
                                                class="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                                title="Buscar en RENIEC"
                                                :disabled="consultandoConductor"
                                                @click="consultarConductor"
                                            >
                                                <LoaderCircle v-if="consultandoConductor" class="size-4 animate-spin" />
                                                <Search v-else class="size-4" />
                                            </button>
                                        </div>
                                        <p v-if="form.errors.conductor_numero_doc" :class="claseError">{{ form.errors.conductor_numero_doc }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel" for="licencia">Licencia de conducir {{ exigeVehiculo ? '*' : '' }}</label>
                                        <input id="licencia" v-model="form.conductor_licencia" type="text" maxlength="12" autocomplete="off" :class="[claseInput, 'font-mono uppercase']" placeholder="Q12345678" />
                                        <p v-if="form.errors.conductor_licencia" :class="claseError">{{ form.errors.conductor_licencia }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel" for="conductor_nombres">Nombres {{ exigeVehiculo ? '*' : '' }}</label>
                                        <input id="conductor_nombres" v-model="form.conductor_nombres" type="text" maxlength="100" :class="claseInput" />
                                        <p v-if="form.errors.conductor_nombres" :class="claseError">{{ form.errors.conductor_nombres }}</p>
                                    </div>
                                    <div>
                                        <label :class="claseLabel" for="conductor_apellidos">Apellidos {{ exigeVehiculo ? '*' : '' }}</label>
                                        <input id="conductor_apellidos" v-model="form.conductor_apellidos" type="text" maxlength="100" :class="claseInput" />
                                        <p v-if="form.errors.conductor_apellidos" :class="claseError">{{ form.errors.conductor_apellidos }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Público -->
                        <div v-else class="mt-4">
                            <div v-if="sugerencias.transportistas.length" class="mb-3 flex flex-wrap gap-1.5">
                                <button v-for="t in sugerencias.transportistas" :key="t.ruc" type="button" :class="claseChip" @click="usarTransportista(t)">
                                    <Building2 class="size-3.5 shrink-0" />
                                    <span class="truncate">{{ t.nombre }}</span>
                                </button>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label :class="claseLabel" for="transportista_ruc">RUC del transportista *</label>
                                    <div class="relative">
                                        <input
                                            id="transportista_ruc"
                                            v-model="form.transportista_ruc"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="11"
                                            :class="[claseInput, 'pr-10']"
                                            placeholder="20123456789"
                                            @blur="consultarTransportista"
                                        />
                                        <button
                                            type="button"
                                            class="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                            title="Buscar en SUNAT"
                                            :disabled="consultandoTransportista"
                                            @click="consultarTransportista"
                                        >
                                            <LoaderCircle v-if="consultandoTransportista" class="size-4 animate-spin" />
                                            <Search v-else class="size-4" />
                                        </button>
                                    </div>
                                    <p v-if="form.errors.transportista_ruc" :class="claseError">{{ form.errors.transportista_ruc }}</p>
                                </div>
                                <div>
                                    <label :class="claseLabel" for="transportista_mtc">Registro MTC <span class="font-normal text-[#94A3B8]">(opcional)</span></label>
                                    <input id="transportista_mtc" v-model="form.transportista_mtc" type="text" maxlength="20" :class="claseInput" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label :class="claseLabel" for="transportista_nombre">Razón social *</label>
                                    <input id="transportista_nombre" v-model="form.transportista_nombre" type="text" maxlength="200" :class="claseInput" />
                                    <p v-if="form.errors.transportista_nombre" :class="claseError">{{ form.errors.transportista_nombre }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============ 4. Productos ============ -->
                    <section :class="claseTarjeta">
                        <h2 :class="claseTituloSeccion"><span :class="claseNumero">4</span> ¿Qué se traslada?</h2>

                        <div v-if="filas.length" class="-mx-5 divide-y divide-[#F1F5F9] border-y border-[#E2E8F0] sm:-mx-6 dark:divide-neutral-800 dark:border-neutral-800">
                            <div v-for="(fila, i) in filas" :key="fila.presentacion_id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                                <div class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-[#F8FAFC] text-[#CBD5E1] dark:bg-neutral-800 dark:text-neutral-600">
                                    <img v-if="fila.producto.imagen_url" :src="fila.producto.imagen_url" :alt="fila.producto.nombre" class="size-full bg-white object-contain" />
                                    <Package v-else class="size-5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm leading-snug font-semibold">{{ fila.producto.nombre }}</p>
                                    <p class="text-xs text-[#94A3B8]">
                                        <span class="font-mono">{{ fila.producto.codigo_interno }}</span>
                                        <template v-if="fila.producto.presentaciones.length === 1"> · {{ presentacionDe(fila)?.nombre }}</template>
                                    </p>
                                    <select
                                        v-if="fila.producto.presentaciones.length > 1"
                                        v-model="fila.presentacion_id"
                                        aria-label="Presentación"
                                        class="mt-1 h-7 max-w-full rounded-lg border border-stone-200 bg-white pr-6 pl-2 text-xs font-medium focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                    >
                                        <option v-for="pres in fila.producto.presentaciones" :key="pres.id" :value="pres.id">{{ pres.nombre }}</option>
                                    </select>
                                </div>
                                <div :class="[claseGrupo, '!border-stone-200 dark:!border-neutral-700 h-10 w-28 shrink-0 sm:w-32']">
                                    <button type="button" :class="[claseBotonPaso, 'w-9']" aria-label="Restar" :disabled="Number(fila.cantidad) <= 1" @click="cambiarCantidad(fila, -1)">
                                        <Minus class="size-3.5" />
                                    </button>
                                    <input
                                        v-model="fila.cantidad"
                                        type="number"
                                        inputmode="decimal"
                                        :step="fila.producto.permite_fraccion ? '0.001' : '1'"
                                        min="0"
                                        aria-label="Cantidad"
                                        :class="claseNumeroGrupo"
                                        @focus="$event.target.select()"
                                    />
                                    <button type="button" :class="[claseBotonPaso, 'w-9']" aria-label="Sumar" @click="cambiarCantidad(fila, 1)">
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

                        <!-- Agregar producto: buscar, pistola lectora o cámara -->
                        <div class="relative" :class="filas.length ? 'mt-4' : ''">
                            <div v-if="!filas.length" class="mb-3 rounded-xl border border-dashed border-[#CBD5E1] px-4 py-8 text-center dark:border-neutral-700">
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
                                </button>
                            </div>
                        </div>

                        <EscanerCamara v-if="puedeEscanear" :abierto="escanerAbierto" :al-leer="leerCodigoCamara" @cerrar="escanerAbierto = false">
                            <div class="mb-2 flex items-center gap-2 px-1">
                                <p class="text-base font-semibold">Productos a trasladar</p>
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
                                    :class="ultimoEscaneado === fila.presentacion_id ? 'border-emerald-400 ring-2 ring-emerald-400/25' : 'border-stone-200 dark:border-neutral-800'"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold">{{ fila.producto.nombre }}</p>
                                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ presentacionDe(fila)?.nombre }}</p>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="grid size-8 place-items-center rounded-lg border border-stone-200 disabled:opacity-40 dark:border-neutral-700" aria-label="Restar uno" :disabled="Number(fila.cantidad) <= 1" @click="cambiarCantidad(fila, -1)">
                                            <Minus class="size-3.5" />
                                        </button>
                                        <span class="min-w-8 text-center text-sm font-semibold">{{ fila.cantidad }}</span>
                                        <button type="button" class="grid size-8 place-items-center rounded-lg border border-stone-200 dark:border-neutral-700" aria-label="Sumar uno" @click="cambiarCantidad(fila, 1)">
                                            <Plus class="size-3.5" />
                                        </button>
                                    </div>
                                    <button type="button" class="grid size-8 place-items-center rounded-lg text-red-500 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40" :aria-label="`Quitar ${fila.producto.nombre}`" @click="filas.splice(i, 1)">
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </div>
                            <template #pie="{ cerrar }">
                                <div class="flex items-center gap-2">
                                    <div class="min-w-0 flex-1 px-1">
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Productos</p>
                                        <p class="text-lg leading-tight font-bold tracking-tight">{{ filas.length }} · {{ unidades }} {{ unidades === 1 ? 'unidad' : 'unidades' }}</p>
                                    </div>
                                    <button type="button" class="h-11 shrink-0 rounded-xl bg-emerald-600 px-8 text-sm font-semibold text-white" @click="cerrar">Listo</button>
                                </div>
                            </template>
                        </EscanerCamara>

                        <p v-if="form.errors.items" :class="claseError">{{ form.errors.items }}</p>

                        <div class="mt-4">
                            <label :class="claseLabel" for="observaciones">Observaciones <span class="font-normal text-[#94A3B8]">(opcional)</span></label>
                            <input id="observaciones" v-model="form.observaciones" type="text" maxlength="250" :class="claseInput" placeholder="Ej. Mercadería frágil" />
                        </div>
                    </section>
                </div>

                <!-- ============ Resumen ============ -->
                <aside class="space-y-4 lg:sticky lg:top-20">
                    <section :class="claseTarjeta">
                        <h2 class="mb-4 font-semibold tracking-tight">Resumen de la guía</h2>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="shrink-0 text-[#64748B] dark:text-neutral-400">Motivo</dt>
                                <dd class="text-right font-medium">{{ nombreMotivo(form.motivo_codigo) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="shrink-0 text-[#64748B] dark:text-neutral-400">Destino</dt>
                                <dd class="truncate text-right font-medium">{{ entreLocales ? (destinoElegido?.nombre ?? '—') : (clienteSeleccionado?.nombre ?? '—') }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="shrink-0 text-[#64748B] dark:text-neutral-400">Transporte</dt>
                                <dd class="text-right font-medium">
                                    {{ form.modalidad === '01' ? 'Público' : 'Privado' }}<template v-if="form.modalidad === '02' && placaLimpia"> · {{ placaLimpia }}</template>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="shrink-0 text-[#64748B] dark:text-neutral-400">Productos</dt>
                                <dd class="font-medium tabular-nums">{{ filas.length }} · {{ unidades }} unid.</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex items-baseline justify-between border-t border-[#E2E8F0] pt-4 dark:border-neutral-800">
                            <span class="text-base font-semibold">Peso bruto</span>
                            <span class="text-2xl font-bold tracking-tight tabular-nums">{{ Number(form.peso_bruto || 0).toLocaleString('es-PE', { maximumFractionDigits: 3 }) }} <span class="text-sm font-semibold text-[#94A3B8]">KG</span></span>
                        </div>

                        <div
                            class="mt-4 flex items-start gap-2 rounded-xl p-3 text-xs"
                            :class="envioSunat.activo && !envioSunat.falta_credenciales
                                ? 'bg-slate-50 text-[#475569] dark:bg-neutral-950/60 dark:text-neutral-300'
                                : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'"
                        >
                            <TriangleAlert v-if="!envioSunat.activo || envioSunat.falta_credenciales" class="mt-0.5 size-4 shrink-0" />
                            <Info v-else class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <span v-if="!envioSunat.activo">
                                La facturación electrónica está apagada: la guía se guardará pero <strong>no se enviará a SUNAT</strong> hasta activarla en Empresa.
                            </span>
                            <span v-else-if="envioSunat.falta_credenciales">
                                Faltan las credenciales de la API de guías (Client ID y Secret) en Empresa: la guía quedará pendiente de envío.
                            </span>
                            <span v-else>
                                Al emitir se envía a SUNAT{{ envioSunat.entorno === 'produccion' ? '' : ' (ambiente de pruebas)' }}. Una guía aceptada no se edita: se anula y se emite otra.
                            </span>
                        </div>

                        <p v-if="motivoNoGuardar" class="mt-3 text-xs font-medium text-amber-700 dark:text-amber-400">{{ motivoNoGuardar }}</p>

                        <button
                            type="button"
                            :disabled="!!motivoNoGuardar || form.processing"
                            class="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="guardar"
                        >
                            {{ form.processing ? 'Emitiendo...' : 'Emitir guía' }}
                        </button>
                        <Link
                            href="/guias"
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
                        <h3 class="font-semibold tracking-tight">Nuevo destinatario</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" @click="modalCliente = false">
                            <X class="size-5" />
                        </button>
                    </div>
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="claseLabel" for="nc_tipo">Documento</label>
                                <select id="nc_tipo" v-model="nuevoCliente.tipo_documento_codigo" :class="claseInput">
                                    <option v-for="t in tiposDocumento.filter((t) => !esSinDocumento(t.codigo))" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="claseLabel" for="nc_numero">Número *</label>
                                <div class="relative">
                                    <input id="nc_numero" v-model="nuevoCliente.numero_documento" type="text" maxlength="15" :class="[claseInput, 'pr-10']" @blur="consultarDocumentoCliente" />
                                    <button
                                        type="button"
                                        class="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                        title="Buscar en RENIEC / SUNAT"
                                        :disabled="consultandoDoc"
                                        @click="consultarDocumentoCliente"
                                    >
                                        <LoaderCircle v-if="consultandoDoc" class="size-4 animate-spin" />
                                        <Search v-else class="size-4" />
                                    </button>
                                </div>
                                <p v-if="ayudaDocumento(nuevoCliente.tipo_documento_codigo)" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{{ ayudaDocumento(nuevoCliente.tipo_documento_codigo) }}</p>
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
                        <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="modalCliente = false">Cancelar</button>
                        <button type="submit" :disabled="guardandoCliente" class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ guardandoCliente ? 'Guardando...' : 'Guardar y usar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
