<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { StorageSerializers, useMediaQuery, useResizeObserver, useStorage, watchDebounced } from '@vueuse/core'
import {
    Banknote,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    ExternalLink,
    History,
    LoaderCircle,
    LockOpen,
    Mail,
    MessageCircle,
    Minus,
    Package,
    Plus,
    Printer,
    Search,
    ShoppingCart,
    Tag,
    RotateCcw,
    ScanBarcode,
    Trash2,
    UserRound,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { usePermisos } from '@/composables/permisos'
import { useImpresion } from '@/composables/impresion'
import { ayudaDocumento, esSinDocumento } from '@/composables/documentoIdentidad'

const props = defineProps({
    apertura: { type: Object, default: null },
    productos: { type: Array, default: () => [] },
    categorias: { type: Array, default: () => [] },
    mediosPago: { type: Array, default: () => [] },
    tiposDocumento: { type: Array, default: () => [] },
    facturacionElectronica: { type: Boolean, default: false },
})

const page = usePage()
// el Nuevo RUS solo emite boletas (nunca facturas)
const esRus = computed(() => page.props.auth?.user?.empresa?.regimen_tributario === 'RUS')
const { puede } = usePermisos()
const { modo: modoImpresion, imprimirTicket } = useImpresion()
const ayudaImpresion = ref(false)
const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`

// ================= catálogo =================
const buscar = ref('')
const categoriaActiva = ref(null)

const productosFiltrados = computed(() => {
    const texto = buscar.value.trim().toLowerCase()
    return props.productos.filter((p) => {
        if (categoriaActiva.value && p.categoria_id !== categoriaActiva.value) return false
        if (!texto) return true
        return (
            p.nombre.toLowerCase().includes(texto) ||
            p.codigo_interno.toLowerCase().includes(texto) ||
            p.presentaciones.some((pres) => pres.codigo_barras === texto)
        )
    })
})

function presentacionDefault(producto) {
    return producto.presentaciones.find((p) => p.es_default) ?? producto.presentaciones[0]
}

// producto y presentación con ese código de barras exacto (o null)
function buscarPorCodigo(codigo) {
    for (const producto of props.productos) {
        const presentacion = producto.presentaciones.find((p) => p.codigo_barras === codigo)
        if (presentacion) return { producto, presentacion }
    }
    return null
}

// escaneo de código de barras: Enter en el buscador (pistola lectora o teclado)
function alPresionarEnter() {
    const texto = buscar.value.trim()
    if (!texto) return
    const encontrado = buscarPorCodigo(texto)
    if (encontrado) {
        agregar(encontrado.producto, encontrado.presentacion)
        buscar.value = ''
        return
    }
    // sin código exacto: si el filtro deja un solo producto, agrégalo
    if (productosFiltrados.value.length === 1) {
        agregar(productosFiltrados.value[0])
        buscar.value = ''
    }
}

// ================= carrito =================
const carrito = ref([]) // { producto, presentacion, cantidad, descuento, conDescuento }

// persistencia: el carrito y el cliente sobreviven recargas y navegacion
const empresaId = page.props.auth?.user?.empresa?.id ?? 'sin-empresa'
const carritoGuardado = useStorage(`pos-carrito-${empresaId}`, [])
const clienteGuardado = useStorage(`pos-cliente-${empresaId}`, null, localStorage, { serializer: StorageSerializers.object })

// aviso flotante cuando se intenta vender sin stock
const avisoSinStock = ref(null)
let avisoTimer = null
function avisarSinStock(mensaje) {
    avisoSinStock.value = mensaje
    clearTimeout(avisoTimer)
    avisoTimer = setTimeout(() => (avisoSinStock.value = null), 2500)
}

function agregar(producto, presentacion = null) {
    presentacion = presentacion ?? presentacionDefault(producto)

    // no dejar pasar al carrito mas de lo que hay en stock (en unidades base)
    if (producto.controla_stock) {
        const enCarrito = carrito.value
            .filter((i) => i.producto.id === producto.id)
            .reduce((suma, i) => suma + Number(i.cantidad || 0) * Number(i.presentacion.factor_conversion), 0)

        if (enCarrito + Number(presentacion.factor_conversion) > producto.stock) {
            avisarSinStock(producto.stock <= 0
                ? `"${producto.nombre}" no tiene stock.`
                : `No hay más stock de "${producto.nombre}" (${producto.stock} disp. y ya tienes ${enCarrito} en el carrito).`)
            return false
        }
    }

    const existente = carrito.value.find((i) => i.presentacion.id === presentacion.id)
    if (existente) {
        existente.cantidad = Number(existente.cantidad) + 1
    } else {
        carrito.value.push({ producto, presentacion, cantidad: 1, precio: '', descuento: '', conDescuento: false })
    }
    return true
}

// ================= escáner con la cámara (celulares y tablets) =================
const escanerAbierto = ref(false)
// solo en pantallas táctiles con cámara: en la PC se usa la pistola lectora o el teclado
const puedeEscanear = typeof window !== 'undefined'
    && window.matchMedia?.('(pointer: coarse)').matches
    && !!navigator.mediaDevices?.getUserMedia

const resumenEscaner = computed(() => {
    if (!carrito.value.length) return ''
    const unidades = carrito.value.reduce((n, i) => n + Number(i.cantidad || 0), 0)
    return `${unidades} ${unidades === 1 ? 'unidad' : 'unidades'} · ${soles(total.value)}`
})

function leerCodigoCamara(codigo) {
    const encontrado = buscarPorCodigo(codigo)
    if (!encontrado) return { ok: false, mensaje: `Código ${codigo} no está registrado en tus productos.` }

    const { producto, presentacion } = encontrado
    if (!agregar(producto, presentacion)) return { ok: false, mensaje: avisoSinStock.value }

    const cantidad = carrito.value.find((i) => i.presentacion.id === presentacion.id)?.cantidad ?? 1
    const nombre = producto.nombre + (presentacion.nombre !== 'Unidad' ? ` (${presentacion.nombre})` : '')
    return { ok: true, mensaje: `${nombre} · ${cantidad} en el carrito` }
}

function quitar(indice) {
    carrito.value.splice(indice, 1)
}

function cambiarPresentacion(item, presentacionId) {
    const nueva = item.producto.presentaciones.find((p) => p.id === presentacionId)
    if (!nueva) return
    const existente = carrito.value.find((i) => i !== item && i.presentacion.id === nueva.id)
    if (existente) {
        existente.cantidad = Number(existente.cantidad) + Number(item.cantidad)
        quitar(carrito.value.indexOf(item))
    } else {
        item.presentacion = nueva
    }
}

// precio mayorista automatico: al llegar a la cantidad minima, la linea completa usa ese precio
const esMayorista = (item) => {
    const p = item.presentacion
    return Number(p.cantidad_mayorista) > 0
        && p.precio_mayorista !== null
        && Number(item.cantidad || 0) >= Number(p.cantidad_mayorista)
}
// precio de lista segun cantidad (normal o mayorista)
const precioLista = (item) => (esMayorista(item) ? Number(item.presentacion.precio_mayorista) : Number(item.presentacion.precio_venta))
// el cajero puede escribir un precio distinto en la linea; vacio = precio de lista
const precioManual = (item) => {
    // sin permiso de precio manual siempre se cobra el precio de lista
    if (!puede('pos.precio_manual')) return null
    const valor = Number(item.precio)
    return item.precio !== '' && item.precio !== null && !Number.isNaN(valor) && valor > 0 ? valor : null
}
const precioUnitario = (item) => precioManual(item) ?? precioLista(item)

// el input muestra siempre el precio vigente; si el cajero escribe el mismo
// precio de lista, se vuelve automatico de nuevo (sigue al mayorista, etc.)
function normalizarPrecio(item) {
    const manual = precioManual(item)
    if (manual !== null && Math.abs(manual - precioLista(item)) < 0.005) {
        item.precio = ''
    }
}

// Mientras el cajero escribe, el input muestra lo que teclea tal cual: si no, al borrar
// o escribir "0" (para llegar a 0.30) volvia de golpe al precio de lista y no se podia editar.
const precioEnEdicion = ref({ item: null, texto: '' })
const textoPrecio = (item) => (precioManual(item) !== null ? String(item.precio) : precioLista(item).toFixed(2))

function empezarEdicionPrecio(item, evento) {
    precioEnEdicion.value = { item, texto: textoPrecio(item) }
    evento.target.select() // tocar el campo selecciona el precio: se escribe el nuevo directo
}

function escribirPrecio(item, texto) {
    precioEnEdicion.value.texto = texto
    item.precio = texto.replace(',', '.').trim() // teclados de celular con coma decimal
}

function terminarEdicionPrecio(item) {
    precioEnEdicion.value = { item: null, texto: '' }
    normalizarPrecio(item)
}

const brutoItem = (item) => precioUnitario(item) * Number(item.cantidad || 0)
const descuentoItem = (item) => Number(item.descuento || 0)
const subtotalItem = (item) => Math.max(0, brutoItem(item) - descuentoItem(item))

const descuentoInvalido = (item) => descuentoItem(item) > 0 && descuentoItem(item) >= brutoItem(item)
const hayDescuentosInvalidos = computed(() => carrito.value.some(descuentoInvalido))

const totalDescuentos = computed(() => carrito.value.reduce((suma, i) => suma + (descuentoInvalido(i) ? 0 : descuentoItem(i)), 0))
const total = computed(() => carrito.value.reduce((suma, i) => suma + subtotalItem(i), 0))

// unidades base requeridas por producto (para validar stock en cliente)
function faltaStock(item) {
    if (!item.producto.controla_stock) return false
    const requerido = carrito.value
        .filter((i) => i.producto.id === item.producto.id)
        .reduce((suma, i) => suma + Number(i.cantidad || 0) * Number(i.presentacion.factor_conversion), 0)
    return requerido > item.producto.stock
}

const hayFaltantes = computed(() => carrito.value.some(faltaStock))

// ================= historial de producto =================
const historialProducto = ref(null)
const historialDatos = ref({ compras: [] })
const cargandoHistorial = ref(false)

async function verHistorial(producto) {
    historialProducto.value = producto
    historialDatos.value = { compras: [] }
    cargandoHistorial.value = true
    try {
        const r = await fetch(`/pos/productos/${producto.id}/historial`, { headers: { Accept: 'application/json' } })
        if (r.ok) historialDatos.value = await r.json()
    } finally {
        cargandoHistorial.value = false
    }
}

function fechaCorta(valor) {
    const iso = String(valor)
    const fecha = iso.length === 10 ? new Date(`${iso}T00:00:00`) : new Date(iso)
    return fecha.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' })
}

// rehidratar el carrito guardado con los datos frescos de productos
// (si un producto/presentacion ya no existe o esta inactivo, se descarta)
if (props.productos.length && carritoGuardado.value.length) {
    for (const guardado of carritoGuardado.value) {
        for (const producto of props.productos) {
            const presentacion = producto.presentaciones.find((p) => p.id === guardado.presentacion_id)
            if (presentacion) {
                carrito.value.push({
                    producto,
                    presentacion,
                    cantidad: guardado.cantidad,
                    precio: guardado.precio ?? '',
                    descuento: guardado.descuento ?? '',
                    conDescuento: !!guardado.conDescuento,
                })
                break
            }
        }
    }
}

// guardar cada cambio del carrito (solo lo minimo: ids y cantidades)
watch(carrito, (items) => {
    carritoGuardado.value = items.map((i) => ({
        presentacion_id: i.presentacion.id,
        cantidad: i.cantidad,
        precio: i.precio,
        descuento: i.descuento,
        conDescuento: i.conDescuento,
    }))
}, { deep: true })

// ================= cliente =================
const clienteSeleccionado = ref(null)
const buscarCliente = ref('')
const resultadosCliente = ref([])

watchDebounced(buscarCliente, async (texto) => {
    if (!texto.trim()) {
        resultadosCliente.value = []
        return
    }
    try {
        const r = await fetch(`/pos/clientes?buscar=${encodeURIComponent(texto)}`, { headers: { Accept: 'application/json' } })
        resultadosCliente.value = r.ok ? await r.json() : []
    } catch {
        resultadosCliente.value = []
    }
}, { debounce: 300 })

function elegirCliente(cliente) {
    clienteSeleccionado.value = cliente
    buscarCliente.value = ''
    resultadosCliente.value = []
}

// el cliente elegido tambien sobrevive recargas
if (clienteGuardado.value && props.productos.length) {
    clienteSeleccionado.value = clienteGuardado.value
}
watch(clienteSeleccionado, (cliente) => (clienteGuardado.value = cliente))

// --- creacion rapida de cliente ---
const modalCliente = ref(false)
const nuevoCliente = ref({ tipo_documento_codigo: '1', numero_documento: '', nombre: '', telefono: '', limite_credito: '' })
const erroresCliente = ref({})
const guardandoCliente = ref(false)
const consultandoDoc = ref(false)

function abrirModalCliente() {
    nuevoCliente.value = {
        tipo_documento_codigo: '1',
        numero_documento: buscarCliente.value.trim().match(/^\d+$/) ? buscarCliente.value.trim() : '',
        nombre: '',
        telefono: '',
        limite_credito: '',
    }
    erroresCliente.value = {}
    resultadosCliente.value = []
    modalCliente.value = true
}

// "Sin documento" no lleva numero
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
        const url = esDni ? `/consultas/dni/${numero}` : `/consultas/ruc/${numero}`
        const r = await fetch(url, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (r.ok) {
            if (esDni && datos.nombre_completo) nuevoCliente.value.nombre = datos.nombre_completo
            if (esRuc && datos.razon_social) nuevoCliente.value.nombre = datos.razon_social
        }
    } catch {
        // consulta opcional
    } finally {
        consultandoDoc.value = false
    }
}

async function guardarCliente() {
    guardandoCliente.value = true
    erroresCliente.value = {}
    try {
        const r = await fetch('/pos/clientes', {
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
                limite_credito: nuevoCliente.value.limite_credito === '' ? 0 : Number(nuevoCliente.value.limite_credito),
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

// ================= cobro =================
const modalCobro = ref(false)
const tipoComprobante = ref('00')
const condicion = ref('contado') // contado | credito
const recibido = ref('')
const procesando = ref(false)

// lineas de pago: permite mixtos ("20 en efectivo y 15 por Yape")
const pagos = ref([])

const medioDe = (codigo) => props.mediosPago.find((m) => m.codigo === codigo)
const totalPagado = computed(() => pagos.value.reduce((suma, p) => suma + Number(p.monto || 0), 0))
const restante = computed(() => Math.round((total.value - totalPagado.value) * 100) / 100)

// modo rapido: un solo pago en efectivo -> recibido + vuelto
const esEfectivoSimple = computed(() => pagos.value.length === 1 && pagos.value[0].medio_pago_codigo === 'efectivo')

const vuelto = computed(() => {
    if (!esEfectivoSimple.value || recibido.value === '') return null
    return Number(recibido.value) - total.value
})

function agregarPago() {
    const usados = pagos.value.map((p) => p.medio_pago_codigo)
    const libre = props.mediosPago.find((m) => !usados.includes(m.codigo))
    pagos.value.push({
        medio_pago_codigo: libre?.codigo ?? 'efectivo',
        monto: restante.value > 0 ? restante.value : '',
        referencia: '',
    })
}

function quitarPago(indice) {
    pagos.value.splice(indice, 1)
    if (esEfectivoSimple.value) {
        pagos.value[0].monto = Number(total.value.toFixed(2))
        recibido.value = ''
    }
}

function lineaValida(pago) {
    if (!(Number(pago.monto) > 0)) return false
    if (medioDe(pago.medio_pago_codigo)?.requiere_referencia && !pago.referencia.trim()) return false
    return true
}

// credito disponible del cliente seleccionado
const creditoDisponible = computed(() => {
    const c = clienteSeleccionado.value
    if (!c) return null
    return Math.max(0, Number(c.limite_credito ?? 0) - Number(c.deuda ?? 0))
})

const puedeFiar = computed(() =>
    clienteSeleccionado.value && Number(clienteSeleccionado.value.limite_credito ?? 0) > 0,
)

// la factura solo puede emitirse a un cliente con RUC
const facturaSinRuc = computed(() =>
    tipoComprobante.value === '01' && clienteSeleccionado.value?.tipo_documento_codigo !== '6',
)

const puedeCobrar = computed(() => {
    if (procesando.value || !carrito.value.length || hayDescuentosInvalidos.value) return false
    if (facturaSinRuc.value) return false
    if (condicion.value === 'credito') {
        return puedeFiar.value && total.value <= creditoDisponible.value + 0.001
    }
    if (esEfectivoSimple.value) return recibido.value !== '' && Number(recibido.value) >= total.value - 0.001
    return pagos.value.length > 0 && pagos.value.every(lineaValida) && Math.abs(restante.value) < 0.005
})

function abrirCobro() {
    if (!carrito.value.length || hayFaltantes.value || hayDescuentosInvalidos.value) return
    recibido.value = ''
    condicion.value = 'contado'
    pagos.value = [{ medio_pago_codigo: 'efectivo', monto: Number(total.value.toFixed(2)), referencia: '' }]
    modalCobro.value = true
}

const ventaExitosa = ref(null) // { mensaje, ticket, venta }
// los navegadores de celular no muestran un PDF dentro de la página (solo un botón "Abrir"):
// ahí no se carga la vista previa y se ofrece abrirlo en otra pestaña
const pantallaChica = useMediaQuery('(max-width: 767px)')
const formCorreoVenta = useForm({ email: '', guardar_en_cliente: false })
const correoVentaEnviado = ref(false)
const inputBuscar = ref(null)

// ---- datos de la venta para el modal ----
const datosVenta = computed(() => ventaExitosa.value?.venta ?? null)
const idVenta = computed(() => datosVenta.value?.id ?? idComprobanteVenta())

// ---- vista previa PDF ----
const formatosPdf = [
    { v: 'ticket', label: 'Ticket' },
    { v: 'a4', label: 'A4' },
    { v: 'a5', label: 'A5' },
]
const formatoPdf = ref('ticket')
const cargandoPdf = ref(true)
const urlPdf = computed(() => (idVenta.value ? `/comprobantes/${idVenta.value}/${formatoPdf.value}` : null))
watch(urlPdf, () => (cargandoPdf.value = true))

function abrirPdfEnPestana() {
    if (urlPdf.value) window.open(urlPdf.value, '_blank', 'noopener')
}

// ---- estado SUNAT (sondeo cada 2 s, máx ~30 s) ----
const ESTADOS_FINALES = ['aceptado', 'observado', 'rechazado', 'baja_pendiente', 'baja']
const estadoSunat = ref({ estado: null, mensaje: null })
const sondeoTerminado = ref(false)
let timerSondeo = null
let sondeoToken = 0

function detenerSondeo() {
    sondeoToken++
    if (timerSondeo) clearTimeout(timerSondeo)
    timerSondeo = null
}

function iniciarSondeo(id) {
    detenerSondeo()
    const token = sondeoToken
    const inicio = Date.now()
    estadoSunat.value = { estado: null, mensaje: null }
    sondeoTerminado.value = false

    const consultar = async () => {
        try {
            const r = await fetch(`/comprobantes/${id}/estado-sunat`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            if (token !== sondeoToken) return
            if (r.ok) {
                const data = await r.json()
                if (token !== sondeoToken) return
                estadoSunat.value = { estado: data.estado ?? null, mensaje: data.mensaje ?? null }
                if (ESTADOS_FINALES.includes(data.estado)) {
                    sondeoTerminado.value = true
                    return
                }
            }
        } catch {
            if (token !== sondeoToken) return
        }
        if (Date.now() - inicio >= 30000) {
            sondeoTerminado.value = true
            return
        }
        timerSondeo = setTimeout(consultar, 2000)
    }
    consultar()
}

const badgeSunat = computed(() => {
    const { estado, mensaje } = estadoSunat.value
    if (estado === 'aceptado') return { texto: 'Aceptado', clase: 'bg-[#10B981]/10 text-[#047857] ring-[#10B981]/30 dark:text-emerald-400' }
    if (estado === 'observado') return { texto: 'Observado', clase: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-900' }
    if (estado === 'rechazado') return { texto: 'Rechazado', clase: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-400 dark:ring-red-900', detalle: mensaje }
    if (estado === 'baja_pendiente') return { texto: 'Baja pendiente', clase: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-900' }
    if (estado === 'baja') return { texto: 'De baja', clase: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-400 dark:ring-red-900' }
    if (!sondeoTerminado.value) return { texto: 'Enviando…', clase: 'bg-slate-100 text-[#64748B] ring-[#E2E8F0] dark:bg-neutral-800 dark:text-neutral-400 dark:ring-neutral-700', spinner: true }
    return {
        texto: 'Pendiente',
        clase: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-900',
        detalle: 'Se reintentará automáticamente.',
    }
})

// ---- WhatsApp ----
const telefonoVenta = ref('')
const errorTelefono = ref('')

function limpiarTelefono(t) {
    let d = String(t ?? '').replace(/\D/g, '')
    if (d.length === 11 && d.startsWith('51')) d = d.slice(2)
    return d
}

function enviarWhatsapp() {
    errorTelefono.value = ''
    const numero = limpiarTelefono(telefonoVenta.value)
    if (!/^9\d{8}$/.test(numero)) {
        errorTelefono.value = 'Número de celular no válido'
        return
    }
    const v = datosVenta.value ?? {}
    const tipo = String(v.tipo ?? 'comprobante').toLowerCase()
    const texto = `Hola, gracias por tu compra en ${v.empresa ?? ''}. Aquí está tu ${tipo} ${v.numero ?? ''} por S/ ${Number(v.total ?? 0).toFixed(2)}: ${v.enlace_publico ?? ''}`
    window.open(`https://wa.me/51${numero}?text=${encodeURIComponent(texto)}`, '_blank', 'noopener')
}

// al abrir / cerrar el modal
watch(ventaExitosa, (v) => {
    detenerSondeo()
    if (!v) return
    formatoPdf.value = 'ticket'
    cargandoPdf.value = true
    errorTelefono.value = ''
    telefonoVenta.value = limpiarTelefono(v.venta?.cliente_telefono)
    const id = v.venta?.id ?? idComprobanteVenta()
    if (v.venta?.electronico && id) iniciarSondeo(id)
})
onBeforeUnmount(detenerSondeo)

function nuevaVenta() {
    ventaExitosa.value = null
    nextTick(() => inputBuscar.value?.focus())
}

// En modo directa el ticket sale solo al registrar la venta (una vez por venta)
let ticketAutoImpreso = null
watch(ventaExitosa, (v) => {
    if (!v?.ticket || modoImpresion.value !== 'directa') return
    if (ticketAutoImpreso === v.ticket) return
    ticketAutoImpreso = v.ticket
    imprimirTicket(v.ticket)
})

function idComprobanteVenta() {
    // los ids son UUID
    return ventaExitosa.value?.ticket?.match(/\/comprobantes\/([^/]+)\/ticket/)?.[1] ?? null
}

function enviarCorreoVenta() {
    const id = idComprobanteVenta()
    if (!id) return
    formCorreoVenta.post(`/comprobantes/${id}/correo`, {
        preserveScroll: true,
        onSuccess: () => {
            if (page.props.flash?.error) return
            correoVentaEnviado.value = true
        },
    })
}

function cobrar() {
    if (!puedeCobrar.value) return
    procesando.value = true

    const esCredito = condicion.value === 'credito'

    router.post('/pos/ventas', {
        tipo_comprobante_codigo: tipoComprobante.value,
        cliente_id: clienteSeleccionado.value?.id ?? null,
        es_credito: esCredito,
        items: carrito.value.map((i) => ({
            presentacion_id: i.presentacion.id,
            cantidad: Number(i.cantidad),
            precio_unitario: precioManual(i),
            descuento: descuentoItem(i) > 0 ? Number(descuentoItem(i).toFixed(2)) : 0,
        })),
        pagos: esCredito
            ? []
            : esEfectivoSimple.value
                ? [{ medio_pago_codigo: 'efectivo', monto: Number(total.value.toFixed(2)), referencia: null }]
                : pagos.value.map((p) => ({
                    medio_pago_codigo: p.medio_pago_codigo,
                    monto: Number(Number(p.monto).toFixed(2)),
                    referencia: p.referencia.trim() || null,
                })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            // solo limpiamos si la venta fue aceptada (flash success)
            if (page.props.flash?.success) {
                const venta = page.props.flash.venta ?? null
                const emailCliente = venta?.cliente_email ?? clienteSeleccionado.value?.email ?? ''
                carrito.value = []
                clienteSeleccionado.value = null
                modalCobro.value = false
                formCorreoVenta.clearErrors()
                formCorreoVenta.email = emailCliente
                formCorreoVenta.guardar_en_cliente = false
                correoVentaEnviado.value = false
                ventaExitosa.value = {
                    mensaje: page.props.flash.success,
                    ticket: page.props.flash.ticket ?? null,
                    venta,
                }
            }
        },
        onFinish: () => (procesando.value = false),
    })
}

// ---- fila de categorias: flechas en vez de barra de desplazamiento ----
const filaCategorias = ref(null)
const puedeIzquierda = ref(false)
const puedeDerecha = ref(false)

function actualizarFlechas() {
    const el = filaCategorias.value
    if (!el) return
    puedeIzquierda.value = el.scrollLeft > 4
    puedeDerecha.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
}

function desplazarCategorias(direccion) {
    const el = filaCategorias.value
    if (!el) return
    el.scrollBy({ left: direccion * Math.max(200, el.clientWidth * 0.7), behavior: 'smooth' })
}

useResizeObserver(filaCategorias, actualizarFlechas)

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
</script>

<template>
    <AppLayout titulo="Punto de venta">
        <div class="-m-4 min-h-[calc(100vh-4rem)] bg-[#F8FAFC] p-4 sm:-m-6 sm:p-6 dark:bg-transparent">
        <!-- Sin caja abierta -->
        <div v-if="!apertura" class="mx-auto max-w-md rounded-2xl border border-stone-200 bg-white p-8 text-center dark:border-neutral-800 dark:bg-neutral-900">
            <div class="mx-auto mb-4 grid size-12 place-items-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                <LockOpen class="size-6" />
            </div>
            <h2 class="text-lg font-semibold tracking-tight">Necesitas abrir caja</h2>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                Para registrar ventas primero abre tu turno de caja con el efectivo inicial.
            </p>
            <Link
                href="/caja"
                class="mt-5 inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                Ir a caja
            </Link>
        </div>

        <!-- POS -->
        <div v-else class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_400px]">
            <!-- ============ Catálogo ============ -->
            <!-- min-w-0: la fila de categorias hace scroll propio en vez de ensanchar la pagina -->
            <div class="min-w-0">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                    <input
                        ref="inputBuscar"
                        v-model="buscar"
                        type="text"
                        placeholder="Buscar o escanear código de barras... (Enter agrega)"
                        :class="puedeEscanear ? 'pr-12' : 'pr-4'"
                        class="h-11 w-full rounded-xl border border-stone-200 bg-white pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500"
                        autofocus
                        @keyup.enter="alPresionarEnter"
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
                <EscanerCamara
                    v-if="puedeEscanear"
                    :abierto="escanerAbierto"
                    :al-leer="leerCodigoCamara"
                    :resumen="resumenEscaner"
                    @cerrar="escanerAbierto = false"
                />

                <!-- Chips de categorías -->
                <div class="relative mt-3">
                    <button
                        v-show="puedeIzquierda"
                        type="button"
                        class="absolute top-1/2 left-0 z-10 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-[#E2E8F0] bg-white text-[#64748B] shadow-md hover:text-[#4F46E5] dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:text-emerald-400"
                        title="Ver categorías anteriores"
                        @click="desplazarCategorias(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <!-- degradados para indicar que hay mas categorias a los lados -->
                    <div v-show="puedeIzquierda" class="pointer-events-none absolute inset-y-0 left-0 z-[5] w-12 bg-gradient-to-r from-[#F8FAFC] to-transparent dark:from-neutral-950" />
                    <div v-show="puedeDerecha" class="pointer-events-none absolute inset-y-0 right-0 z-[5] w-12 bg-gradient-to-l from-[#F8FAFC] to-transparent dark:from-neutral-950" />
                    <button
                        v-show="puedeDerecha"
                        type="button"
                        class="absolute top-1/2 right-0 z-10 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-[#E2E8F0] bg-white text-[#64748B] shadow-md hover:text-[#4F46E5] dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:text-emerald-400"
                        title="Ver más categorías"
                        @click="desplazarCategorias(1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                <div
                    ref="filaCategorias"
                    class="flex gap-2 overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    @scroll.passive="actualizarFlechas"
                >
                    <button
                        class="shrink-0 rounded-xl px-3.5 py-1.5 text-sm font-medium transition-colors"
                        :class="categoriaActiva === null
                            ? 'bg-[#4F46E5] text-white dark:bg-emerald-500'
                            : 'border border-[#E2E8F0] bg-white text-[#64748B] hover:bg-[#F1F5F9] hover:text-[#0F172A] dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        @click="categoriaActiva = null"
                    >
                        Todos
                    </button>
                    <button
                        v-for="c in categorias"
                        :key="c.id"
                        class="shrink-0 rounded-xl px-3.5 py-1.5 text-sm font-medium transition-colors"
                        :class="categoriaActiva === c.id
                            ? 'bg-[#4F46E5] text-white dark:bg-emerald-500'
                            : 'border border-[#E2E8F0] bg-white text-[#64748B] hover:bg-[#F1F5F9] hover:text-[#0F172A] dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        @click="categoriaActiva = categoriaActiva === c.id ? null : c.id"
                    >
                        {{ c.nombre }}
                    </button>
                </div>
                </div>

                <!-- Grilla de productos -->
                <div v-if="productosFiltrados.length" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-4">
                    <div
                        v-for="p in productosFiltrados"
                        :key="p.id"
                        role="button"
                        tabindex="0"
                        class="group overflow-hidden rounded-2xl border border-[#E2E8F0] bg-white text-left shadow-sm shadow-slate-900/[0.03] transition-all dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none"
                        :class="p.controla_stock && p.stock <= 0
                            ? 'cursor-not-allowed opacity-60'
                            : 'cursor-pointer hover:border-[#4F46E5] hover:shadow-md dark:hover:border-emerald-600'"
                        @click="agregar(p)"
                        @keyup.enter="agregar(p)"
                    >
                        <!-- Imagen -->
                        <div class="relative aspect-[4/3] w-full overflow-hidden bg-[#F8FAFC] dark:bg-neutral-800">
                            <img
                                v-if="p.imagen_url"
                                :src="p.imagen_url"
                                :alt="p.nombre"
                                loading="lazy"
                                class="size-full bg-white object-contain transition-transform duration-200 group-hover:scale-105"
                            />
                            <div v-else class="grid size-full place-items-center text-[#CBD5E1] dark:text-neutral-600">
                                <Package class="size-10" />
                            </div>
                            <span
                                v-if="p.controla_stock"
                                class="absolute top-2 right-2 rounded-full px-2 py-0.5 text-[11px] font-semibold shadow-sm"
                                :class="p.stock <= 0
                                    ? 'bg-red-600 text-white'
                                    : 'bg-white/90 text-neutral-600 backdrop-blur-sm dark:bg-neutral-900/80 dark:text-neutral-300'"
                            >
                                {{ p.stock }} disp.
                            </span>
                        </div>
                        <!-- Datos -->
                        <div class="p-3">
                            <p class="line-clamp-2 min-h-10 text-sm font-medium text-[#0F172A] dark:text-neutral-100">{{ p.nombre }}</p>
                            <div class="mt-1 flex items-center justify-between gap-2">
                                <p class="text-base font-bold tracking-tight text-emerald-700 dark:text-emerald-400">
                                    {{ soles(presentacionDefault(p)?.precio_venta) }}
                                </p>
                                <button
                                    v-if="puede('stock.costos')"
                                    class="grid size-7 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                    title="Últimas compras y ventas"
                                    @click.stop="verHistorial(p)"
                                >
                                    <History class="size-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    No se encontraron productos.
                </div>

                <!-- Aviso de stock agotado -->
                <Teleport to="body">
                    <Transition
                        enter-active-class="transition duration-150"
                        enter-from-class="translate-y-2 opacity-0"
                        leave-active-class="transition duration-150"
                        leave-to-class="translate-y-2 opacity-0"
                    >
                        <div
                            v-if="avisoSinStock"
                            class="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-medium text-white shadow-lg"
                        >
                            {{ avisoSinStock }}
                        </div>
                    </Transition>
                </Teleport>
            </div>

            <!-- ============ Carrito ============ -->
            <div class="rounded-2xl border border-stone-200 bg-white xl:sticky xl:top-20 dark:border-neutral-800 dark:bg-neutral-900">
                <!-- Cliente -->
                <div class="border-b border-stone-200 p-4 dark:border-neutral-800">
                    <div v-if="clienteSeleccionado" class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <div class="grid size-8 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <UserRound class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ clienteSeleccionado.nombre }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ clienteSeleccionado.numero_documento ?? 'Sin documento' }}</p>
                            </div>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="clienteSeleccionado = null"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <div v-else class="flex gap-2">
                        <div class="relative flex-1">
                            <UserRound class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                v-model="buscarCliente"
                                type="text"
                                placeholder="Cliente (opcional) — Público general"
                                class="h-10 w-full rounded-xl border border-stone-200 bg-stone-50 pr-3 pl-9 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:bg-white focus:outline-none dark:border-neutral-800 dark:bg-neutral-950 dark:placeholder-neutral-500 dark:focus:bg-neutral-950"
                            />
                            <div
                                v-if="resultadosCliente.length"
                                class="absolute top-11 right-0 left-0 z-10 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <button
                                    v-for="c in resultadosCliente"
                                    :key="c.id"
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
                            class="grid size-10 shrink-0 place-items-center rounded-xl border border-stone-200 text-neutral-500 hover:border-emerald-400 hover:text-emerald-600 dark:border-neutral-800 dark:text-neutral-400 dark:hover:border-emerald-600 dark:hover:text-emerald-400"
                            title="Nuevo cliente"
                            @click="abrirModalCliente"
                        >
                            <Plus class="size-4" />
                        </button>
                    </div>
                </div>

                <!-- Items -->
                <div class="max-h-[45vh] overflow-y-auto">
                    <div v-if="!carrito.length" class="px-4 py-12 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        <ShoppingCart class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                        El carrito está vacío.<br />Haz clic en un producto para agregarlo.
                    </div>
                    <div
                        v-for="(item, i) in carrito"
                        :key="item.presentacion.id"
                        class="border-b border-stone-100 p-3.5 last:border-0 dark:border-neutral-800"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="min-w-0 flex-1 text-sm leading-tight font-medium">{{ item.producto.nombre }}</p>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold">{{ soles(subtotalItem(item)) }}</p>
                                <p v-if="descuentoItem(item) > 0 && !descuentoInvalido(item)" class="text-[11px] text-amber-600 dark:text-amber-400">
                                    −{{ soles(descuentoItem(item)) }}
                                </p>
                            </div>
                            <button
                                class="shrink-0 rounded-lg p-1 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                @click="quitar(i)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                            <select
                                v-if="item.producto.presentaciones.length > 1"
                                :value="item.presentacion.id"
                                class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-xs focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                @change="cambiarPresentacion(item, $event.target.value)"
                            >
                                <option v-for="pres in item.producto.presentaciones" :key="pres.id" :value="pres.id">
                                    {{ pres.nombre }} · {{ soles(pres.precio_venta) }}
                                </option>
                            </select>
                            <span v-else class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ item.presentacion.nombre }}
                            </span>

                            <!-- Precio unitario editable (solo con permiso de precio manual) -->
                            <span v-if="!puede('pos.precio_manual')" class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ soles(precioLista(item)) }} c/u
                            </span>
                            <div v-else class="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                                <span>S/</span>
                                <input
                                    :value="precioEnEdicion.item === item ? precioEnEdicion.texto : textoPrecio(item)"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    class="h-9 w-20 rounded-lg border bg-white px-2 text-base focus:outline-none sm:h-7 sm:w-16 sm:px-1.5 sm:text-xs dark:bg-neutral-950"
                                    :class="precioManual(item)
                                        ? 'border-amber-400 font-semibold text-amber-700 dark:border-amber-500 dark:text-amber-400'
                                        : 'border-stone-200 focus:border-emerald-500 dark:border-neutral-700'"
                                    title="Precio unitario: escribe otro para cambiarlo en esta venta"
                                    @focus="empezarEdicionPrecio(item, $event)"
                                    @input="escribirPrecio(item, $event.target.value)"
                                    @blur="terminarEdicionPrecio(item)"
                                    @keyup.enter="$event.target.blur()"
                                />
                                <button
                                    v-if="precioManual(item)"
                                    class="grid size-6 place-items-center rounded-lg text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/40"
                                    :title="`Volver al precio de lista (${soles(precioLista(item))})`"
                                    @click="item.precio = ''"
                                >
                                    <RotateCcw class="size-3.5" />
                                </button>
                            </div>

                            <span
                                v-if="esMayorista(item) && !precioManual(item)"
                                class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300"
                                :title="`Desde ${item.presentacion.cantidad_mayorista} unid. paga ${soles(item.presentacion.precio_mayorista)} c/u`"
                            >
                                Mayorista
                            </span>

                            <div class="ml-auto flex items-center gap-1">
                                <button
                                    class="grid size-7 place-items-center rounded-lg border border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    @click="item.cantidad = Math.max(0.001, Number(item.cantidad) - 1)"
                                >
                                    <Minus class="size-3.5" />
                                </button>
                                <input
                                    v-model="item.cantidad"
                                    type="number"
                                    :step="item.producto.permite_fraccion ? '0.001' : '1'"
                                    min="0"
                                    class="h-7 w-16 rounded-lg border border-stone-200 bg-white text-center text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                />
                                <button
                                    class="grid size-7 place-items-center rounded-lg border border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    @click="item.cantidad = Number(item.cantidad) + 1"
                                >
                                    <Plus class="size-3.5" />
                                </button>
                                <button
                                    class="grid size-7 place-items-center rounded-lg transition-colors"
                                    :class="item.conDescuento || descuentoItem(item) > 0
                                        ? 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400'
                                        : 'text-neutral-400 hover:bg-stone-100 hover:text-amber-600 dark:hover:bg-neutral-800 dark:hover:text-amber-400'"
                                    title="Descuento"
                                    @click="item.conDescuento = !item.conDescuento"
                                >
                                    <Tag class="size-3.5" />
                                </button>
                            </div>
                        </div>
                        <div v-if="item.conDescuento || descuentoItem(item) > 0" class="mt-2 flex items-center gap-2">
                            <label class="text-xs text-neutral-500 dark:text-neutral-400">Descuento S/</label>
                            <input
                                v-model="item.descuento"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                class="h-7 w-24 rounded-lg border bg-white px-2 text-right text-sm focus:outline-none dark:bg-neutral-950"
                                :class="descuentoInvalido(item)
                                    ? 'border-red-400 focus:border-red-500 dark:border-red-600'
                                    : 'border-stone-200 focus:border-emerald-500 dark:border-neutral-700'"
                            />
                            <span v-if="descuentoInvalido(item)" class="text-xs font-medium text-red-600 dark:text-red-400">
                                No puede igualar o superar {{ soles(brutoItem(item)) }}
                            </span>
                        </div>
                        <p v-if="faltaStock(item)" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">
                            Stock insuficiente ({{ item.producto.stock }} disponibles en unidades base)
                        </p>
                    </div>
                </div>

                <!-- Total y cobrar -->
                <div class="border-t border-stone-200 p-4 dark:border-neutral-800">
                    <div v-if="totalDescuentos > 0" class="mb-1 flex items-baseline justify-between text-sm">
                        <span class="text-neutral-500 dark:text-neutral-400">Descuentos</span>
                        <span class="font-medium text-amber-600 dark:text-amber-400">−{{ soles(totalDescuentos) }}</span>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm text-neutral-500 dark:text-neutral-400">Total</span>
                        <span class="text-2xl font-bold tracking-tight">{{ soles(total) }}</span>
                    </div>
                    <p class="mt-0.5 text-right text-xs text-neutral-400 dark:text-neutral-500">IGV incluido</p>
                    <button
                        class="mt-3 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!carrito.length || hayFaltantes || hayDescuentosInvalidos"
                        @click="abrirCobro"
                    >
                        <Banknote class="size-5" />
                        Cobrar
                    </button>

                    <!-- Modo de impresión del ticket -->
                    <div class="mt-3 flex items-center justify-between gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                        <div
                            class="flex items-center gap-2"
                            title="Directa: imprime al instante en la impresora predeterminada (abre Chrome con --kiosk-printing para que no pida confirmación)"
                        >
                            <Printer class="size-3.5" />
                            <span>Impresión</span>
                            <div class="inline-flex rounded-lg border border-stone-200 p-0.5 dark:border-neutral-700">
                                <button
                                    v-for="op in [{ v: 'pdf', t: 'PDF' }, { v: 'directa', t: 'Directa' }]"
                                    :key="op.v"
                                    type="button"
                                    class="rounded-md px-2 py-0.5 font-medium transition-colors"
                                    :class="modoImpresion === op.v
                                        ? 'bg-emerald-600 text-white'
                                        : 'text-neutral-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="modoImpresion = op.v"
                                >
                                    {{ op.t }}
                                </button>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                            @click="ayudaImpresion = true"
                        >
                            <CircleHelp class="size-3.5" />
                            ¿Cómo configurar?
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ Modal venta exitosa ============ -->
        <Teleport to="body">
            <div v-if="ventaExitosa" class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="nuevaVenta" />
                <div class="relative flex max-h-[96vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl supports-[height:100dvh]:max-h-[96dvh] border border-[#E2E8F0] bg-white text-[#0F172A] shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <button
                        type="button"
                        class="absolute top-3 right-3 grid size-8 place-items-center rounded-xl text-[#64748B] hover:bg-slate-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                        title="Cerrar"
                        @click="nuevaVenta"
                    >
                        <X class="size-4" />
                    </button>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <!-- Cabecera -->
                        <div class="grid gap-4 border-b border-[#E2E8F0] px-5 py-4 pr-12 sm:px-6 md:grid-cols-2 dark:border-neutral-800">
                            <div class="flex items-start gap-3">
                                <div class="grid size-11 shrink-0 place-items-center rounded-full bg-[#10B981]/10 text-[#10B981] dark:bg-emerald-950/60 dark:text-emerald-400">
                                    <CheckCircle2 class="size-6" />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-lg font-semibold tracking-tight sm:text-xl">
                                        <template v-if="datosVenta">Venta exitosa: {{ datosVenta.tipo }} {{ datosVenta.numero }}</template>
                                        <template v-else>¡Venta registrada!</template>
                                    </h3>
                                    <p v-if="datosVenta" class="mt-0.5 text-2xl font-bold tracking-tight text-[#10B981] dark:text-emerald-400">{{ soles(datosVenta.total) }}</p>
                                    <p v-else class="mt-0.5 text-sm text-[#64748B] dark:text-neutral-400">{{ ventaExitosa.mensaje }}</p>
                                </div>
                            </div>
                            <div v-if="datosVenta" class="space-y-1.5 text-sm md:justify-self-end md:text-right">
                                <template v-if="datosVenta.electronico">
                                    <div class="flex flex-wrap items-center gap-2 md:justify-end">
                                        <span class="text-[#64748B] dark:text-neutral-400">Estado SUNAT:</span>
                                        <span class="inline-flex items-center gap-1.5 rounded-lg px-2 py-0.5 text-xs font-semibold ring-1" :class="badgeSunat.clase">
                                            <LoaderCircle v-if="badgeSunat.spinner" class="size-3.5 animate-spin" />
                                            {{ badgeSunat.texto }}
                                        </span>
                                    </div>
                                    <p v-if="badgeSunat.detalle" class="text-xs text-[#64748B] dark:text-neutral-400">{{ badgeSunat.detalle }}</p>
                                    <p>
                                        <span class="text-[#64748B] dark:text-neutral-400">Envío automático:</span>
                                        <span class="font-medium" :class="datosVenta.envio_automatico ? 'text-[#10B981] dark:text-emerald-400' : 'text-[#64748B] dark:text-neutral-400'">
                                            {{ datosVenta.envio_automatico ? 'Activado' : 'Desactivado' }}
                                        </span>
                                    </p>
                                </template>
                                
                            </div>
                        </div>

                        <!-- Vista previa -->
                        <div v-if="urlPdf" class="px-5 pt-3 sm:px-6">
                            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-[#E2E8F0] dark:border-neutral-800">
                                <div class="flex gap-1">
                                    <button
                                        v-for="f in formatosPdf"
                                        :key="f.v"
                                        type="button"
                                        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                                        :class="formatoPdf === f.v
                                            ? 'border-[#4F46E5] text-[#4F46E5] dark:border-indigo-400 dark:text-indigo-300'
                                            : 'border-transparent text-[#64748B] hover:text-[#0F172A] dark:text-neutral-400 dark:hover:text-neutral-100'"
                                        @click="formatoPdf = f.v"
                                    >
                                        {{ f.label }}
                                    </button>
                                </div>
                                <div class="flex items-center gap-2 pb-1.5">
                                    <button
                                        v-if="modoImpresion === 'directa' && ventaExitosa.ticket"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-[#E2E8F0] px-3 text-xs font-medium text-[#0F172A] hover:bg-slate-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="imprimirTicket(ventaExitosa.ticket)"
                                    >
                                        <Printer class="size-3.5" />
                                        Reimprimir ticket
                                    </button>
                                    <button
                                        v-if="!pantallaChica"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-[#E2E8F0] px-3 text-xs font-medium text-[#0F172A] hover:bg-slate-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="abrirPdfEnPestana"
                                    >
                                        <ExternalLink class="size-3.5" />
                                        Abrir en pestaña
                                    </button>
                                </div>
                            </div>
                            <button
                                v-if="pantallaChica"
                                type="button"
                                class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-[#E2E8F0] bg-slate-50 px-4 py-4 text-sm font-semibold text-[#4F46E5] hover:bg-slate-100 dark:border-neutral-800 dark:bg-neutral-950 dark:text-indigo-300"
                                @click="abrirPdfEnPestana"
                            >
                                <ExternalLink class="size-4" />
                                Ver {{ formatosPdf.find((f) => f.v === formatoPdf)?.label ?? 'comprobante' }} en PDF
                            </button>
                            <div v-else class="relative mt-3 h-[60vh] overflow-hidden rounded-xl border border-[#E2E8F0] bg-slate-50 dark:border-neutral-800 dark:bg-neutral-950">
                                <div v-if="cargandoPdf" class="absolute inset-0 grid place-items-center text-sm text-[#64748B] dark:text-neutral-400">
                                    <span class="inline-flex items-center gap-2">
                                        <LoaderCircle class="size-4 animate-spin" />
                                        Cargando vista previa…
                                    </span>
                                </div>
                                <iframe
                                    :key="urlPdf"
                                    :src="urlPdf"
                                    title="Vista previa del comprobante"
                                    class="relative size-full"
                                    :class="cargandoPdf ? 'opacity-0' : 'opacity-100'"
                                    @load="cargandoPdf = false"
                                />
                            </div>
                        </div>

                        <!-- Correo / WhatsApp -->
                        <div v-if="idVenta" class="grid gap-4 px-5 py-4 sm:px-6 md:grid-cols-2">
                            <form @submit.prevent="enviarCorreoVenta">
                                <label class="mb-1 block text-xs font-medium text-[#64748B] dark:text-neutral-400">Enviar por correo</label>
                                <div class="flex">
                                    <input
                                        v-model="formCorreoVenta.email"
                                        type="email"
                                        placeholder="cliente@correo.com"
                                        class="h-10 min-w-0 flex-1 rounded-l-xl border border-r-0 border-[#E2E8F0] bg-white px-3 text-sm placeholder-neutral-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                                        @input="correoVentaEnviado = false"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="formCorreoVenta.processing"
                                        class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-r-xl bg-[#4F46E5] px-4 text-sm font-semibold text-white transition-colors hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <LoaderCircle v-if="formCorreoVenta.processing" class="size-4 animate-spin" />
                                        <Mail v-else class="size-4" />
                                        Enviar
                                    </button>
                                </div>
                                <p v-if="formCorreoVenta.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formCorreoVenta.errors.email }}</p>
                                <p v-else-if="correoVentaEnviado" class="mt-1 text-xs font-medium text-[#10B981] dark:text-emerald-400">Enviado ✓</p>
                            </form>
                            <form v-if="datosVenta" @submit.prevent="enviarWhatsapp">
                                <label class="mb-1 block text-xs font-medium text-[#64748B] dark:text-neutral-400">Enviar por WhatsApp</label>
                                <div class="flex">
                                    <span class="inline-flex h-10 shrink-0 items-center rounded-l-xl border border-r-0 border-[#E2E8F0] bg-slate-50 px-3 text-sm text-[#64748B] dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">+51</span>
                                    <input
                                        v-model="telefonoVenta"
                                        type="tel"
                                        inputmode="numeric"
                                        maxlength="11"
                                        placeholder="987654321"
                                        class="h-10 min-w-0 flex-1 border border-r-0 border-[#E2E8F0] bg-white px-3 text-sm placeholder-neutral-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                                        @input="errorTelefono = ''"
                                    />
                                    <button
                                        type="submit"
                                        class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-r-xl bg-[#10B981] px-4 text-sm font-semibold text-white transition-colors hover:bg-emerald-600"
                                    >
                                        <MessageCircle class="size-4" />
                                        Enviar
                                    </button>
                                </div>
                                <p v-if="errorTelefono" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ errorTelefono }}</p>
                            </form>
                        </div>
                    </div>

                    <div class="flex shrink-0 justify-center border-t border-[#E2E8F0] px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-neutral-800">
                        <button
                            type="button"
                            class="inline-flex h-11 w-full items-center justify-center rounded-xl bg-[#4F46E5] px-6 sm:w-auto sm:min-w-48 text-sm font-semibold text-white transition-colors hover:bg-[#4338CA]"
                            @click="nuevaVenta"
                        >
                            Nueva venta
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ============ Modal ayuda impresión directa ============ -->
        <Teleport to="body">
            <div v-if="ayudaImpresion" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="ayudaImpresion = false" />
                <div class="relative w-full max-w-lg rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div class="flex items-center gap-2">
                            <Printer class="size-5 text-emerald-600 dark:text-emerald-400" />
                            <h3 class="font-semibold tracking-tight">Impresión directa</h3>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="ayudaImpresion = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>
                    <ol class="list-decimal space-y-3 px-6 py-5 pl-11 text-sm text-neutral-700 dark:text-neutral-300">
                        <li>Instala el driver de tu impresora térmica y márcala como predeterminada en Windows.</li>
                        <li>
                            Crea un acceso directo de Google Chrome y agrega al final del destino:
                            <code class="rounded bg-stone-100 px-1 py-0.5 text-xs dark:bg-neutral-800">--kiosk-printing</code>
                            (ejemplo:
                            <code class="rounded bg-stone-100 px-1 py-0.5 text-xs break-all dark:bg-neutral-800">"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing</code>).
                        </li>
                        <li>Abre el POS desde ese acceso directo y elige "Directa": cada venta se imprime sin preguntar.</li>
                        <li>En Sucursales → Cajas puedes elegir el ancho del papel (58 u 80 mm).</li>
                    </ol>
                    <div class="flex justify-end border-t border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <button
                            class="inline-flex h-10 items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                            @click="ayudaImpresion = false"
                        >
                            Entendido
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ============ Modal historial de producto ============ -->
        <Teleport to="body">
            <div v-if="historialProducto" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="historialProducto = null" />
                <div class="relative w-full max-w-3xl rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div>
                            <h3 class="font-semibold tracking-tight">{{ historialProducto.nombre }}</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Últimas compras</p>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="historialProducto = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="max-h-[65vh] overflow-x-auto overflow-y-auto px-6 py-4">
                        <p v-if="cargandoHistorial" class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            Cargando...
                        </p>
                        <p v-else-if="!historialDatos.compras.length" class="py-8 text-center text-sm text-neutral-400 dark:text-neutral-500">
                            Sin compras registradas para este producto.
                        </p>
                        <table v-else class="w-full text-left text-sm">
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pr-3 pb-2 font-semibold tracking-wider">Fecha</th>
                                    <th class="px-3 pb-2 font-semibold tracking-wider">Documento</th>
                                    <th class="px-3 pb-2 font-semibold tracking-wider">Proveedor</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Stock restante</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Cantidad</th>
                                    <th class="px-3 pb-2 text-right font-semibold tracking-wider">Precio compra</th>
                                    <th class="pb-2 pl-3 text-right font-semibold tracking-wider">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="c in historialDatos.compras" :key="c.id">
                                    <td class="py-2.5 pr-3 whitespace-nowrap">{{ fechaCorta(c.fecha) }}</td>
                                    <td class="px-3 py-2.5 font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ c.documento ?? '—' }}</td>
                                    <td class="max-w-40 truncate px-3 py-2.5 text-neutral-600 dark:text-neutral-300">{{ c.proveedor ?? '—' }}</td>
                                    <td
                                        class="px-3 py-2.5 text-right font-medium"
                                        :class="c.stock_restante !== null && c.stock_restante <= 0 ? 'text-red-600 dark:text-red-400' : ''"
                                    >
                                        {{ c.stock_restante !== null ? Number(c.stock_restante).toLocaleString('es-PE', { maximumFractionDigits: 3 }) : '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right">
                                        {{ Number(c.cantidad).toLocaleString('es-PE', { maximumFractionDigits: 3 }) }}
                                        <span v-if="c.presentacion && c.presentacion !== 'Unidad'" class="text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ c.presentacion }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-neutral-600 dark:text-neutral-300">{{ soles(c.costo_unitario) }}</td>
                                    <td class="py-2.5 pl-3 text-right font-semibold">{{ soles(c.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ============ Modal cliente rápido ============ -->
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
                                <label class="mb-1 block text-sm font-medium" for="nc_tipo">Documento</label>
                                <select id="nc_tipo" v-model="nuevoCliente.tipo_documento_codigo" :class="claseInput">
                                    <option v-for="t in tiposDocumento" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="nc_numero">Número</label>
                                <div class="relative">
                                    <input
                                        id="nc_numero"
                                        v-model="nuevoCliente.numero_documento"
                                        type="text"
                                        maxlength="15"
                                        :disabled="esSinDocumento(nuevoCliente.tipo_documento_codigo)"
                                        :class="[claseInput, 'pr-10 disabled:cursor-not-allowed disabled:bg-stone-100 dark:disabled:bg-neutral-800']"
                                        :placeholder="esSinDocumento(nuevoCliente.tipo_documento_codigo) ? '' : '12345678'"
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
                                <p v-if="erroresCliente.numero_documento" class="mt-1 text-xs text-red-600 dark:text-red-400">
                                    {{ erroresCliente.numero_documento[0] }}
                                </p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="nc_nombre">Nombre / Razón social *</label>
                            <input id="nc_nombre" v-model="nuevoCliente.nombre" type="text" :class="claseInput" placeholder="Juan Pérez" />
                            <p v-if="erroresCliente.nombre" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ erroresCliente.nombre[0] }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="nc_telefono">Teléfono</label>
                                <input id="nc_telefono" v-model="nuevoCliente.telefono" type="text" :class="claseInput" placeholder="999 999 999" />
                            </div>
                            <div v-if="puede('clientes.credito')">
                                <label class="mb-1 block text-sm font-medium" for="nc_limite">Límite crédito (S/)</label>
                                <input id="nc_limite" v-model="nuevoCliente.limite_credito" type="number" step="0.01" min="0" :class="claseInput" placeholder="0" />
                            </div>
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

        <!-- ============ Modal de cobro ============ -->
        <Teleport to="body">
            <div v-if="modalCobro" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalCobro = false" />
                <div class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold tracking-tight">Cobrar {{ soles(total) }}</h3>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalCobro = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <!-- Tipo de comprobante -->
                    <p class="mb-1.5 text-sm font-medium">Comprobante</p>
                    <div class="grid gap-2" :class="!facturacionElectronica ? 'grid-cols-1' : esRus ? 'grid-cols-2' : 'grid-cols-3'">
                        <button
                            class="h-10 rounded-xl border text-sm font-medium transition-colors"
                            :class="tipoComprobante === '00'
                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            @click="tipoComprobante = '00'"
                        >
                            Nota de venta
                        </button>
                        <button
                            v-if="facturacionElectronica"
                            class="h-10 rounded-xl border text-sm font-medium transition-colors"
                            :class="tipoComprobante === '03'
                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            @click="tipoComprobante = '03'"
                        >
                            Boleta
                        </button>
                        <button
                            v-if="facturacionElectronica && !esRus"
                            class="h-10 rounded-xl border text-sm font-medium transition-colors"
                            :class="tipoComprobante === '01'
                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            @click="tipoComprobante = '01'"
                        >
                            Factura
                        </button>
                    </div>
                    <p
                        v-if="facturaSinRuc"
                        class="mt-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400"
                    >
                        La factura necesita un cliente con RUC. Búscalo o créalo antes de cobrar.
                    </p>

                    <!-- Condición -->
                    <p class="mt-4 mb-1.5 text-sm font-medium">Condición</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            class="h-10 rounded-xl border text-sm font-medium transition-colors"
                            :class="condicion === 'contado'
                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            @click="condicion = 'contado'"
                        >
                            Contado
                        </button>
                        <button
                            class="h-10 rounded-xl border text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                            :class="condicion === 'credito'
                                ? 'border-amber-500 bg-amber-500 text-white'
                                : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            :disabled="!puedeFiar"
                            :title="!clienteSeleccionado ? 'Elige un cliente en el carrito' : !puedeFiar ? 'El cliente no tiene línea de crédito' : undefined"
                            @click="condicion = 'credito'"
                        >
                            Crédito (fiado)
                        </button>
                    </div>
                    <p v-if="!clienteSeleccionado" class="mt-1.5 text-xs text-neutral-400 dark:text-neutral-500">
                        Para fiar, primero elige un cliente en el carrito.
                    </p>

                    <!-- Crédito: resumen -->
                    <div v-if="condicion === 'credito'" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm dark:bg-amber-950/40">
                        <div class="flex justify-between">
                            <span class="text-neutral-600 dark:text-neutral-300">Crédito disponible de {{ clienteSeleccionado.nombre }}</span>
                            <span class="font-semibold">{{ soles(creditoDisponible) }}</span>
                        </div>
                        <p
                            class="mt-2 font-medium"
                            :class="total <= creditoDisponible + 0.001
                                ? 'text-amber-800 dark:text-amber-300'
                                : 'text-red-700 dark:text-red-400'"
                        >
                            {{ total <= creditoDisponible + 0.001
                                ? `El total de ${soles(total)} quedará como deuda del cliente.`
                                : `La venta excede el crédito disponible.` }}
                        </p>
                    </div>

                    <!-- Pagos (solo contado) -->
                    <template v-if="condicion === 'contado'">
                        <div class="mt-4 flex items-center justify-between">
                            <p class="text-sm font-medium">{{ esEfectivoSimple ? 'Medio de pago' : 'Pagos' }}</p>
                            <button
                                v-if="pagos.length < 4"
                                class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                                @click="agregarPago"
                            >
                                <Plus class="size-3.5" />
                                Agregar medio
                            </button>
                        </div>

                        <div class="mt-2 space-y-2">
                            <div
                                v-for="(p, i) in pagos"
                                :key="i"
                                class="rounded-xl border border-stone-200 p-3 dark:border-neutral-700"
                            >
                                <div class="flex gap-2">
                                    <select
                                        v-model="p.medio_pago_codigo"
                                        class="h-10 flex-1 rounded-xl border border-stone-300 bg-white px-3 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                    >
                                        <option v-for="m in mediosPago" :key="m.codigo" :value="m.codigo">{{ m.nombre }}</option>
                                    </select>
                                    <input
                                        v-model="p.monto"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="0.00"
                                        :disabled="esEfectivoSimple"
                                        class="h-10 w-28 rounded-xl border border-stone-300 bg-white px-3 text-right text-sm focus:border-emerald-500 focus:outline-none disabled:bg-stone-100 disabled:text-neutral-500 dark:border-neutral-700 dark:bg-neutral-950 dark:disabled:bg-neutral-800"
                                    />
                                    <button
                                        v-if="pagos.length > 1"
                                        class="grid size-10 shrink-0 place-items-center rounded-xl text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        title="Quitar"
                                        @click="quitarPago(i)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                                <input
                                    v-if="medioDe(p.medio_pago_codigo)?.requiere_referencia"
                                    v-model="p.referencia"
                                    type="text"
                                    placeholder="N° de operación / referencia"
                                    class="mt-2 h-9 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                />
                            </div>
                        </div>

                        <!-- descuadre (solo en modo mixto) -->
                        <p
                            v-if="!esEfectivoSimple && Math.abs(restante) >= 0.005"
                            class="mt-2 rounded-xl bg-red-100 px-4 py-2.5 text-sm font-semibold text-red-800 dark:bg-red-500/15 dark:text-red-300"
                        >
                            {{ restante > 0 ? `Falta asignar ${soles(restante)}` : `Sobran ${soles(-restante)}` }}
                        </p>
                        <p
                            v-else-if="!esEfectivoSimple && pagos.length > 1"
                            class="mt-2 rounded-xl bg-emerald-100 px-4 py-2.5 text-sm font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300"
                        >
                            ✓ Pagos completos
                        </p>
                    </template>

                    <!-- Efectivo simple: recibido y vuelto -->
                    <div v-if="condicion === 'contado' && esEfectivoSimple" class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="recibido">Monto recibido</label>
                        <input
                            id="recibido"
                            v-model="recibido"
                            type="number"
                            step="0.01"
                            min="0"
                            :class="claseInput"
                            :placeholder="total.toFixed(2)"
                            autofocus
                        />
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="monto in [total, Math.ceil(total / 10) * 10, Math.ceil(total / 20) * 20, Math.ceil(total / 50) * 50, Math.ceil(total / 100) * 100].filter((v, idx, arr) => arr.indexOf(v) === idx)"
                                :key="monto"
                                class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="recibido = monto.toFixed(2)"
                            >
                                {{ soles(monto) }}
                            </button>
                        </div>
                        <p
                            v-if="vuelto !== null"
                            class="mt-3 rounded-xl px-4 py-2.5 text-sm font-semibold"
                            :class="vuelto >= 0
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                : 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300'"
                        >
                            {{ vuelto >= 0 ? `Vuelto: ${soles(vuelto)}` : `Falta: ${soles(Math.abs(vuelto))}` }}
                        </p>
                    </div>

                    <button
                        class="mt-5 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl text-base font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="condicion === 'credito' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-emerald-600 hover:bg-emerald-700'"
                        :disabled="!puedeCobrar"
                        @click="cobrar"
                    >
                        {{ procesando
                            ? 'Registrando venta...'
                            : condicion === 'credito'
                                ? `Fiar · ${soles(total)}`
                                : `Confirmar venta · ${soles(total)}` }}
                    </button>
                </div>
            </div>
        </Teleport>
        </div>
    </AppLayout>
</template>
