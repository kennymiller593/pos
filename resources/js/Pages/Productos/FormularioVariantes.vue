<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { ArrowLeft, ArrowRight, Check, Info, Layers, MapPin, Minus, Plus, RotateCcw, ScanBarcode, Trash2, X } from '@lucide/vue'
import SelectorBuscable from '@/Components/SelectorBuscable.vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermisos } from '@/composables/permisos'
import { useSucursales } from '@/composables/sucursales'

const props = defineProps({
    // null al crear; al editar: { id, nombre, categoria_id, marca_id, atributos[{ nombre, valores[] }], omitidas[[valor, ...]],
    // unidad_base_codigo, tipo_afectacion_codigo, precio_base,
    // variantes[{ id, codigo_interno, nombre, valores[], activo, precio_venta, codigo_barras, stock }] }
    modelo: { type: Object, default: null },
    catalogos: { type: Object, required: true },
})

const MAX_ATRIBUTOS = 3
const MAX_COMBINACIONES = 200
const SUGERIDOS = ['Talla', 'Color', 'Capacidad', 'Material']
const TALLAS = ['S', 'M', 'L', 'XL']

const page = usePage()
const { puede } = usePermisos()
const { viendoTodas, accesibles, consultaId, nombreDe } = useSucursales()

const editando = computed(() => !!props.modelo)
// Nuevo RUS: no discrimina IGV, sus productos van como exonerados (el servidor también lo fuerza)
const esRus = computed(() => page.props.auth?.user?.empresa?.regimen_tributario === 'RUS')
// los precios de las variantes que ya existen solo los cambia quien tiene productos.precios
const preciosBloqueados = computed(() => editando.value && !puede('productos.precios'))
const puedeStock = computed(() => puede('stock.ajustar'))

const form = useForm({
    nombre: props.modelo?.nombre ?? '',
    categoria_id: props.modelo?.categoria_id ?? '',
    marca_id: props.modelo?.marca_id ?? '',
    unidad_base_codigo: props.modelo?.unidad_base_codigo
        ?? (props.catalogos.unidades.some((u) => u.codigo === 'NIU') ? 'NIU' : props.catalogos.unidades[0]?.codigo ?? ''),
    tipo_afectacion_codigo: props.modelo?.tipo_afectacion_codigo
        ?? (esRus.value ? '20' : props.catalogos.tiposAfectacion[0]?.codigo ?? ''),
    precio_base: props.modelo?.precio_base ?? '',
    costo: '',
    sucursal_id: '',
    atributos: props.modelo
        ? props.modelo.atributos.map((a) => ({ nombre: a.nombre, valores: [...a.valores] }))
        : [{ nombre: 'Talla', valores: [] }],
    variantes: [],
})

const opcionesUnidad = computed(() => props.catalogos.unidades.map((u) => ({
    valor: u.codigo,
    texto: `${u.codigo} - ${u.descripcion_sunat ?? u.nombre.toUpperCase()}`,
    detalle: u.permite_decimales ? 'decimales' : '',
})))
const textoUnidad = computed(() => opcionesUnidad.value.find((o) => o.valor === form.unidad_base_codigo)?.texto ?? form.unidad_base_codigo)

// ---- características: chips de opciones (Agregar, Enter o coma agrega; × quita) ----
const minusculas = (t) => String(t ?? '').trim().toLowerCase()
const clave = (valores) => valores.map(minusculas).join('\u001f')
const borradores = ref(form.atributos.map(() => ''))

function agregarAtributo() {
    if (form.atributos.length >= MAX_ATRIBUTOS) return
    const usados = form.atributos.map((a) => minusculas(a.nombre))
    form.atributos.push({ nombre: SUGERIDOS.find((s) => !usados.includes(minusculas(s))) ?? '', valores: [] })
    borradores.value.push('')
}

function quitarAtributo(i) {
    form.atributos.splice(i, 1)
    borradores.value.splice(i, 1)
}

// acepta "S, M, L" de un tirón; ignora vacíos y repetidos (sin distinguir mayúsculas)
function agregarValores(i, texto = borradores.value[i]) {
    const atributo = form.atributos[i]
    for (const parte of String(texto ?? '').split(',')) {
        const valor = parte.trim().slice(0, 30)
        if (valor && !atributo.valores.some((v) => minusculas(v) === minusculas(valor))) {
            atributo.valores.push(valor)
        }
    }
    borradores.value[i] = ''
}

// botón "Agregar": lo mismo que Enter, y el foco se queda en el campo para seguir escribiendo
function agregarYSeguir(i) {
    agregarValores(i)
    document.getElementById(`valor-${i}`)?.focus()
}

function alEscribirValor(i) {
    if (borradores.value[i].includes(',')) agregarValores(i)
}

function alBorrarEnVacio(i) {
    if (borradores.value[i] === '' && !editando.value) form.atributos[i].valores.pop()
}

const nombreRepetido = (i) => {
    const nombre = minusculas(form.atributos[i].nombre)
    return nombre !== '' && form.atributos.findIndex((a) => minusculas(a.nombre) === nombre) !== i
}
// nombres sugeridos que aún no se usan en otra característica
const sugerenciasPara = (i) => SUGERIDOS.filter((s) => !form.atributos.some((a, j) => j !== i && minusculas(a.nombre) === minusculas(s)))

// ---- variantes: se arman solas con las opciones, en el orden en que se escribieron ----
const cartesiano = (atributos) => atributos.reduce((parciales, a) => parciales.flatMap((p) => a.valores.map((v) => [...p, v])), [[]])
const combinaciones = computed(() => (form.atributos.length && form.atributos.every((a) => a.valores.length) ? cartesiano(form.atributos) : []))
const totalCombinaciones = computed(() => form.atributos.reduce((n, a) => n * Math.max(a.valores.length, 1), 1))
const demasiadas = computed(() => totalCombinaciones.value > MAX_COMBINACIONES)

// variantes que ya existen, por su combinación
const existentes = new Map((props.modelo?.variantes ?? []).map((v) => [clave(v.valores), v]))
// las que estaban en la definición al abrir: una inactiva de esas se deja quitada; una que vuelve al re-agregar su opción, se reactiva
const clavesIniciales = new Set(props.modelo ? cartesiano(props.modelo.atributos).map(clave) : [])
// las que se decidió no tener (el servidor las recuerda): siguen quitadas hasta que se vuelvan a incluir
const omitidasGuardadas = new Set((props.modelo?.omitidas ?? []).map(clave))

// lo escrito en cada fila se guarda por combinación: agregar o quitar una opción no borra lo ya llenado
// (un Map normal con un objeto reactivo por fila: crearlo al armar las filas no dispara nada)
const detalles = new Map()
function detalleDe(k) {
    if (!detalles.has(k)) {
        const existente = existentes.get(k)
        detalles.set(k, reactive({
            precio_venta: existente?.precio_venta ?? '',
            codigo_barras: existente?.codigo_barras ?? '',
            stock_inicial: '',
            omitir: existente ? !existente.activo && clavesIniciales.has(k) : omitidasGuardadas.has(k),
            barrasAbierto: false, // en celular el código de barras va plegado hasta que se pide
        }))
    }
    return detalles.get(k)
}

const filas = computed(() => (demasiadas.value ? [] : combinaciones.value).map((valores) => {
    const k = clave(valores)
    return { clave: k, valores, existente: existentes.get(k) ?? null, detalle: detalleDe(k) }
}))
const incluidas = computed(() => filas.value.filter((f) => !f.detalle.omitir))
const nuevas = computed(() => incluidas.value.filter((f) => !f.existente))
// variantes a la venta que este guardado va a retirar (se quitó su opción o su fila)
const porRetirar = computed(() => {
    const quedan = new Set(incluidas.value.map((f) => f.clave))
    return (props.modelo?.variantes ?? []).filter((v) => v.activo && !quedan.has(clave(v.valores)))
})

// qué dice la segunda línea de cada fila (al crear, una variante normal no lleva ninguna)
function notaDe(fila) {
    if (fila.detalle.omitir) {
        if (fila.existente) return fila.existente.activo ? 'Se retirará de la venta' : 'Inactiva'
        return editando.value ? 'No creada' : 'No se creará'
    }
    if (fila.existente) return fila.existente.codigo_interno + (fila.existente.activo ? '' : ' · se reactivará')
    return editando.value ? 'Nueva' : ''
}

const precioBloqueado = (fila) => preciosBloqueados.value && !!fila.existente
const precioBaseValido = computed(() => Number(form.precio_base) > 0)

function aplicarPrecioATodas() {
    if (!precioBaseValido.value) return
    for (const fila of incluidas.value) {
        if (precioBloqueado(fila)) continue
        // las nuevas siguen al precio base (campo vacío); las que ya existen lo reciben escrito
        fila.detalle.precio_venta = fila.existente ? Number(form.precio_base) : ''
    }
}
const hayPreciosDistintos = computed(() => incluidas.value.some((f) => !precioBloqueado(f)
    && f.detalle.precio_venta !== '' && Math.abs(Number(f.detalle.precio_venta) - Number(form.precio_base)) >= 0.005))

// ---- código de barras: en celular se abre a pedido; con valor o con error se muestra siempre ----
const barrasVisible = (fila, i) => fila.detalle.barrasAbierto || !!fila.detalle.codigo_barras || !!errorFila(i, 'codigo_barras')
function abrirBarras(fila, i) {
    fila.detalle.barrasAbierto = true
    nextTick(() => document.getElementById(`barras-${i}`)?.focus())
}

// ---- stock inicial (solo variantes nuevas, si puede ajustar stock) ----
function cambiarStock(fila, delta) {
    const nuevo = Math.max(0, Math.round(Number(fila.detalle.stock_inicial || 0)) + delta)
    fila.detalle.stock_inicial = nuevo === 0 ? '' : nuevo
}
const stockTotal = computed(() => (puedeStock.value ? nuevas.value.reduce((s, f) => s + Math.max(0, Math.round(Number(f.detalle.stock_inicial || 0))), 0) : 0))
const faltaCosto = computed(() => stockTotal.value > 0 && form.costo === '')
const sucursalDestino = computed(() => (viendoTodas.value ? null : nombreDe(consultaId.value) ?? accesibles.value[0]?.nombre ?? null))
if (viendoTodas.value) form.sucursal_id = accesibles.value[0]?.id ?? ''

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
const contarVariantes = (n) => `${n} ${n === 1 ? 'variante' : 'variantes'}`

// ---- pestañas: 1 Modelo · 2 En qué varía · 3 Variantes ----
const PESTANAS = [
    { clave: 'modelo', corto: 'Modelo', largo: 'Modelo' },
    { clave: 'varia', corto: 'Varía en', largo: 'En qué varía' },
    { clave: 'variantes', corto: 'Variantes', largo: 'Variantes' },
]
// al editar se viene a tocar las variantes: se abre ahí
const pestana = ref(props.modelo ? 'variantes' : 'modelo')
const indicePestana = computed(() => PESTANAS.findIndex((t) => t.clave === pestana.value))
const esUltima = computed(() => indicePestana.value === PESTANAS.length - 1)

function irA(claveP) {
    // lo que quedó a medio escribir en una característica también cuenta
    borradores.value.forEach((texto, i) => texto.trim() && agregarValores(i))
    pestana.value = claveP
    if (typeof window !== 'undefined') window.scrollTo({ top: 0 })
}
const siguiente = () => !esUltima.value && irA(PESTANAS[indicePestana.value + 1].clave)
const atras = () => indicePestana.value > 0 && irA(PESTANAS[indicePestana.value - 1].clave)

// ---- qué falta para guardar: el texto, en qué pestaña está y a qué campo llevar el foco ----
const problema = computed(() => {
    if (!form.nombre.trim()) return { texto: 'Escribe el nombre del modelo.', pestana: 'modelo', foco: 'nombre' }
    if (!precioBaseValido.value) return { texto: 'Indica el precio de venta.', pestana: 'modelo', foco: 'precio_base' }
    if (form.atributos.some((a) => !a.nombre.trim())) return { texto: 'Ponle nombre a cada característica.', pestana: 'varia' }
    if (form.atributos.some((_, i) => nombreRepetido(i))) return { texto: 'Hay una característica repetida.', pestana: 'varia' }
    if (form.atributos.some((a) => !a.valores.length)) return { texto: 'Agrega al menos una opción a cada característica.', pestana: 'varia' }
    if (demasiadas.value) return { texto: `Son ${totalCombinaciones.value} variantes y el máximo es ${MAX_COMBINACIONES}.`, pestana: 'varia' }
    if (!editando.value && !incluidas.value.length) return { texto: 'Deja al menos una variante.', pestana: 'variantes' }
    if (incluidas.value.some((f) => f.detalle.precio_venta !== '' && !(Number(f.detalle.precio_venta) > 0))) return { texto: 'Hay un precio en 0.', pestana: 'variantes' }
    if (faltaCosto.value) return { texto: 'Falta el costo: cargaste stock inicial.', pestana: 'modelo', foco: 'costo' }
    return null
})
const motivoNoGuardar = computed(() => problema.value?.texto ?? '')
const puedeGuardar = computed(() => !problema.value)
const textoGuardar = computed(() => {
    if (form.processing) return 'Guardando...'
    if (editando.value) return 'Guardar cambios'
    const n = incluidas.value.length
    return n ? `Crear ${contarVariantes(n)}` : 'Crear modelo'
})

// lleva a lo que falta: su pestaña y, si se sabe, el campo
function irAlProblema() {
    const p = problema.value
    if (!p) return
    irA(p.pestana)
    if (p.foco) nextTick(() => document.getElementById(p.foco)?.focus())
}

// ---- errores: en qué pestaña cae cada uno (punto rojo en la pestaña y salto a la primera) ----
const CAMPOS_MODELO = ['nombre', 'categoria_id', 'marca_id', 'unidad_base_codigo', 'tipo_afectacion_codigo', 'precio_base', 'costo', 'sucursal_id']
const pestanaDeError = (campo) => (campo.startsWith('variantes') ? 'variantes' : campo.startsWith('atributos') ? 'varia' : CAMPOS_MODELO.includes(campo) ? 'modelo' : null)
const intentoGuardar = ref(false)
const pestanasConError = computed(() => {
    const conError = new Set(Object.keys(form.errors).map(pestanaDeError).filter(Boolean))
    if (intentoGuardar.value && problema.value) conError.add(problema.value.pestana)
    return conError
})

// los errores del servidor llegan por posición ("variantes.3.codigo_barras"): se muestran en su fila
const errorFila = (i, campo) => form.errors[`variantes.${i}.${campo}`]
const hayErroresEnFilas = computed(() => Object.keys(form.errors).some((k) => k.startsWith('variantes.')))
// al cambiar la definición las posiciones ya no corresponden
watch(() => filas.value.map((f) => f.clave).join('|'), () => {
    for (const k of Object.keys(form.errors)) {
        if (k.startsWith('variantes.')) form.clearErrors(k)
    }
})

function guardar() {
    if (form.processing) return
    // lo que quedó a medio escribir en una característica también cuenta
    borradores.value.forEach((texto, i) => texto.trim() && agregarValores(i))
    intentoGuardar.value = true
    if (!puedeGuardar.value) return irAlProblema()

    form.variantes = filas.value.map((f) => ({
        valores: f.valores,
        precio_venta: f.detalle.precio_venta === '' ? null : Number(f.detalle.precio_venta),
        codigo_barras: String(f.detalle.codigo_barras ?? '').trim() || null,
        stock_inicial: !f.existente && puedeStock.value && Number(f.detalle.stock_inicial) > 0 ? Math.round(Number(f.detalle.stock_inicial)) : null,
        omitir: f.detalle.omitir,
    }))

    const transformar = (data) => ({
        ...data,
        nombre: data.nombre.trim(),
        categoria_id: data.categoria_id || null,
        marca_id: data.marca_id || null,
        precio_base: Number(data.precio_base),
        costo: data.costo === '' ? null : Number(data.costo),
        sucursal_id: stockTotal.value > 0 && viendoTodas.value ? data.sucursal_id || null : null,
    })
    const opciones = {
        preserveScroll: true,
        // el servidor rechazó algo: se salta a la primera pestaña que tiene un error
        onError: (errores) => {
            const conError = Object.keys(errores).map(pestanaDeError).filter(Boolean)
            const primera = PESTANAS.find((t) => conError.includes(t.clave))
            if (primera && !conError.includes(pestana.value)) irA(primera.clave)
        },
    }

    if (editando.value) {
        form.transform(transformar).put(`/productos/modelos/${props.modelo.id}`, opciones)
    } else {
        form.transform(transformar).post('/productos/modelos', opciones)
    }
}

// en celular la barra fija lleva "Siguiente" mientras se crea y aún no se llega a la última pestaña;
// al editar siempre lleva "Guardar cambios" (se entra a cambiar una cosa y guardar)
const barraSiguiente = computed(() => !editando.value && !esUltima.value)

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseBloqueado = 'cursor-not-allowed !bg-stone-100 text-neutral-500 dark:!bg-neutral-800 dark:text-neutral-400'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseAyuda = 'mt-1 text-xs text-neutral-500 dark:text-neutral-400'
// campo compuesto (− cantidad +, "S/ precio"): un solo borde que se ilumina al enfocar cualquiera de sus partes
const claseGrupo =
    'flex h-10 items-center overflow-hidden rounded-xl border border-stone-300 bg-white transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/20 dark:border-neutral-700 dark:bg-neutral-950'
const claseBotonPaso =
    'grid h-full w-10 shrink-0 place-items-center text-[#64748B] transition-colors hover:bg-slate-100 hover:text-[#0F172A] active:bg-slate-200 disabled:pointer-events-none disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
const claseNumeroGrupo =
    'h-full w-full min-w-0 flex-1 bg-transparent text-center text-sm font-medium tabular-nums [appearance:textfield] placeholder:font-normal placeholder:text-neutral-400 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900'
const claseTitulo = 'flex items-center gap-2.5 font-semibold tracking-tight'
const claseEncabezado = 'text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500'
// en PC cada fila es una línea de la tabla; en celular, tres renglones separados por una línea fina
const claseColumnas = 'md:grid md:grid-cols-[minmax(0,1.3fr)_8.5rem_minmax(0,1fr)_9rem_2.5rem] md:items-center md:gap-3'
const claseSecundario =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 text-sm font-medium transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800'
const clasePrimario =
    'inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50'
</script>

<template>
    <AppLayout titulo="Modelo">
        <Link
            :href="editando ? `/productos?grupo_id=${modelo.id}` : '/productos'"
            class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100"
        >
            <ArrowLeft class="size-4" />
            Volver a productos
        </Link>

        <!-- Pestañas: las tres caben en una fila también en celular -->
        <div class="mb-4 flex border-b border-stone-200 dark:border-neutral-800" role="tablist" aria-label="Pasos del modelo">
            <button
                v-for="(t, n) in PESTANAS"
                :key="t.clave"
                type="button"
                role="tab"
                :aria-selected="pestana === t.clave"
                class="-mb-px inline-flex h-11 min-w-0 flex-1 items-center justify-center gap-1.5 border-b-2 px-1.5 text-sm font-medium whitespace-nowrap transition-colors sm:flex-none sm:gap-2 sm:px-4"
                :class="pestana === t.clave
                    ? 'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-300'
                    : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100'"
                :data-pestana="t.clave"
                @click="irA(t.clave)"
            >
                <span class="hidden sm:inline">{{ n + 1 }}</span>
                <span class="truncate sm:hidden">{{ t.corto }}</span>
                <span class="hidden sm:inline">{{ t.largo }}</span>
                <span v-if="t.clave === 'variantes' && filas.length" class="shrink-0 font-normal" data-pestana-total>({{ incluidas.length }})</span>
                <span v-if="pestanasConError.has(t.clave)" class="size-2 shrink-0 rounded-full bg-red-500" aria-label="Tiene errores" data-pestana-error />
            </button>
        </div>

        <!-- pb en celular: la barra fija no debe tapar lo último -->
        <form class="grid items-start gap-6 pb-24 lg:grid-cols-[minmax(0,1fr)_320px] lg:pb-0" data-formulario-variantes @submit.prevent="guardar">
            <div class="min-w-0">
                <!-- ============ 1. El modelo ============ -->
                <section v-show="pestana === 'modelo'" :class="claseTarjeta" role="tabpanel" data-panel="modelo">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="nombre">Nombre del modelo *</label>
                            <input id="nombre" v-model="form.nombre" type="text" maxlength="160" :class="claseInput" placeholder="Ej. Polo básico cuello redondo" data-modelo-nombre />
                            <p v-if="form.errors.nombre" :class="claseError">{{ form.errors.nombre }}</p>
                            <p v-else :class="claseAyuda">Cada variante se llamará así más sus opciones: «{{ form.nombre.trim() || 'Polo básico' }} {{ (filas[0]?.valores ?? ['M', 'Rojo']).join(' ') }}».</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="categoria">Categoría</label>
                            <select id="categoria" v-model="form.categoria_id" :class="claseInput">
                                <option value="">Sin categoría</option>
                                <option v-for="c in catalogos.categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                            <p v-if="form.errors.categoria_id" :class="claseError">{{ form.errors.categoria_id }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="marca">Marca</label>
                            <select id="marca" v-model="form.marca_id" :class="claseInput">
                                <option value="">Sin marca</option>
                                <option v-for="m in catalogos.marcas" :key="m.id" :value="m.id">{{ m.nombre }}</option>
                            </select>
                            <p v-if="form.errors.marca_id" :class="claseError">{{ form.errors.marca_id }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="unidad_base">Se vende por *</label>
                            <!-- la unidad queda fija al crear: cambiarla con stock de por medio descuadra el inventario -->
                            <input v-if="editando" id="unidad_base" type="text" :value="textoUnidad" disabled :class="[claseInput, claseBloqueado]" />
                            <SelectorBuscable v-else id="unidad_base" v-model="form.unidad_base_codigo" :opciones="opcionesUnidad" :clase-input="claseInput" placeholder="Elige la unidad" />
                            <p v-if="form.errors.unidad_base_codigo" :class="claseError">{{ form.errors.unidad_base_codigo }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="afectacion">Afectación IGV *</label>
                            <select id="afectacion" v-model="form.tipo_afectacion_codigo" :class="claseInput">
                                <option v-for="t in catalogos.tiposAfectacion" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                            </select>
                            <p v-if="esRus" :class="claseAyuda">Nuevo RUS: se vende sin IGV (exonerado).</p>
                            <p v-if="form.errors.tipo_afectacion_codigo" :class="claseError">{{ form.errors.tipo_afectacion_codigo }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="precio_base">Precio de venta *</label>
                            <div :class="[claseGrupo, 'px-3']">
                                <span class="text-sm text-neutral-500">S/</span>
                                <input id="precio_base" v-model="form.precio_base" type="number" inputmode="decimal" step="0.01" min="0" placeholder="0.00" :class="[claseNumeroGrupo, 'pl-2 text-left']" data-precio-base />
                            </div>
                            <p v-if="form.errors.precio_base" :class="claseError">{{ form.errors.precio_base }}</p>
                            <p v-else :class="claseAyuda">{{ editando ? 'El que toman las variantes que agregues.' : 'El mismo para todas; en Variantes puedes cambiar el de alguna.' }}</p>
                        </div>
                        <div v-if="puedeStock">
                            <label :class="claseLabel" for="costo">Costo de compra <span class="font-normal text-neutral-400">(si cargas stock)</span></label>
                            <div :class="[claseGrupo, 'px-3', (faltaCosto || form.errors.costo) && '!border-red-400']">
                                <span class="text-sm text-neutral-500">S/</span>
                                <input id="costo" v-model="form.costo" type="number" inputmode="decimal" step="0.01" min="0" placeholder="0.00" :class="[claseNumeroGrupo, 'pl-2 text-left']" data-costo />
                            </div>
                            <p v-if="form.errors.costo" :class="claseError">{{ form.errors.costo }}</p>
                            <p v-else-if="faltaCosto" :class="claseError" data-falta-costo>Falta el costo: cargaste stock inicial.</p>
                            <p v-else :class="claseAyuda">Lo que te costó cada unidad del stock inicial.</p>
                        </div>
                    </div>
                </section>

                <!-- ============ 2. En qué varía ============ -->
                <section v-show="pestana === 'varia'" :class="claseTarjeta" role="tabpanel" data-panel="varia">
                    <h2 :class="claseTitulo">¿En qué varía?</h2>
                    <p class="mt-1 mb-4 text-sm text-neutral-500 dark:text-neutral-400">
                        Escribe cada opción (S, M, L...) y toca Agregar. También puedes separarlas con comas. Salen en el orden en que las escribes.
                    </p>
                    <p v-if="form.errors.atributos" :class="[claseError, 'mb-3']" data-error-atributos>{{ form.errors.atributos }}</p>

                    <div class="space-y-3">
                        <div
                            v-for="(atributo, i) in form.atributos"
                            :key="i"
                            class="rounded-xl border border-stone-200 p-3 sm:p-4 dark:border-neutral-800"
                            data-atributo
                        >
                            <div class="flex items-end justify-between gap-2">
                                <div class="min-w-0 flex-1 sm:max-w-56">
                                    <label :class="claseLabel" :for="`atributo-${i}`">Característica</label>
                                    <input
                                        :id="`atributo-${i}`"
                                        v-model="atributo.nombre"
                                        type="text"
                                        maxlength="30"
                                        autocomplete="off"
                                        placeholder="Talla, Color..."
                                        :disabled="editando"
                                        :class="[claseInput, editando && claseBloqueado, nombreRepetido(i) && '!border-red-400']"
                                        data-atributo-nombre
                                    />
                                </div>
                                <button
                                    v-if="!editando && form.atributos.length > 1"
                                    type="button"
                                    class="grid size-10 shrink-0 place-items-center rounded-xl text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                    :aria-label="`Quitar la característica ${atributo.nombre || i + 1}`"
                                    title="Quitar característica"
                                    data-quitar-atributo
                                    @click="quitarAtributo(i)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                            <p v-if="nombreRepetido(i)" :class="claseError">Esta característica está repetida.</p>
                            <p v-else-if="form.errors[`atributos.${i}.nombre`]" :class="claseError">{{ form.errors[`atributos.${i}.nombre`] }}</p>
                            <!-- nombres sugeridos (solo al crear): al tocar uno, rellena el campo -->
                            <p v-if="!editando && sugerenciasPara(i).length" class="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400" data-sugerencias>
                                Sugerencias:
                                <template v-for="(s, j) in sugerenciasPara(i)" :key="s">
                                    <span v-if="j"> · </span>
                                    <button type="button" class="py-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400" @click="atributo.nombre = s">{{ s }}</button>
                                </template>
                            </p>

                            <!-- opciones: las etiquetas y el campo comparten un mismo recuadro -->
                            <label :class="[claseLabel, 'mt-3']" :for="`valor-${i}`">Opciones</label>
                            <div
                                class="flex min-h-11 flex-wrap items-center gap-1.5 rounded-xl border border-stone-300 bg-white p-1.5 transition-colors focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/20 dark:border-neutral-700 dark:bg-neutral-950"
                            >
                                <span
                                    v-for="(valor, j) in atributo.valores"
                                    :key="valor"
                                    class="inline-flex min-h-9 max-w-full items-center gap-0.5 rounded-lg bg-emerald-50 pr-0.5 pl-2.5 text-sm font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
                                    data-valor-chip
                                >
                                    <span class="truncate">{{ valor }}</span>
                                    <button
                                        type="button"
                                        class="grid size-8 shrink-0 place-items-center rounded-md text-emerald-700/70 hover:bg-emerald-100 hover:text-emerald-900 sm:size-6 dark:text-emerald-300/70 dark:hover:bg-emerald-500/20 dark:hover:text-emerald-100"
                                        :aria-label="`Quitar ${valor}`"
                                        @click="atributo.valores.splice(j, 1)"
                                    >
                                        <X class="size-3.5" />
                                    </button>
                                </span>
                                <div class="flex min-w-36 flex-1 items-center gap-1.5">
                                    <input
                                        :id="`valor-${i}`"
                                        v-model="borradores[i]"
                                        type="text"
                                        maxlength="120"
                                        enterkeyhint="done"
                                        autocomplete="off"
                                        :placeholder="atributo.valores.length ? 'Otra opción...' : 'Ej. S, M, L'"
                                        class="h-8 min-w-0 flex-1 bg-transparent px-1.5 text-sm placeholder-neutral-400 focus:outline-none dark:placeholder-neutral-500"
                                        data-valor-atributo
                                        @keydown.enter.prevent="agregarValores(i)"
                                        @keydown.backspace="alBorrarEnVacio(i)"
                                        @input="alEscribirValor(i)"
                                        @blur="agregarValores(i)"
                                    />
                                    <!-- pointerdown.prevent: el campo no pierde el foco al tocar el botón -->
                                    <button
                                        v-if="borradores[i].trim()"
                                        type="button"
                                        class="h-8 shrink-0 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                        data-agregar-valor
                                        @pointerdown.prevent
                                        @mousedown.prevent
                                        @click="agregarYSeguir(i)"
                                    >
                                        Agregar
                                    </button>
                                </div>
                            </div>
                            <p v-if="form.errors[`atributos.${i}.valores`]" :class="claseError">{{ form.errors[`atributos.${i}.valores`] }}</p>
                            <button
                                v-if="!atributo.valores.length && minusculas(atributo.nombre) === 'talla'"
                                type="button"
                                class="mt-2 py-1 text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400"
                                data-usar-tallas
                                @click="agregarValores(i, TALLAS.join(','))"
                            >
                                Usar {{ TALLAS.join(', ') }}
                            </button>
                        </div>
                    </div>

                    <button
                        v-if="!editando && form.atributos.length < MAX_ATRIBUTOS"
                        type="button"
                        class="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-stone-300 text-sm font-medium text-neutral-600 transition-colors hover:border-emerald-500 hover:text-emerald-700 sm:w-auto sm:px-4 dark:border-neutral-700 dark:text-neutral-300 dark:hover:border-emerald-500 dark:hover:text-emerald-400"
                        data-agregar-atributo
                        @click="agregarAtributo"
                    >
                        <Plus class="size-4" />
                        Agregar otra característica
                    </button>
                    <p v-if="editando" class="mt-3 flex items-start gap-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        En un modelo ya creado se agregan o quitan opciones; para otras características crea un modelo nuevo.
                    </p>
                    <p v-if="demasiadas" class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                        Son {{ totalCombinaciones }} variantes y el máximo es {{ MAX_COMBINACIONES }}. Quita algunas opciones o divide el modelo en dos.
                    </p>
                </section>

                <!-- ============ 3. Variantes ============ -->
                <section v-show="pestana === 'variantes'" :class="claseTarjeta" role="tabpanel" data-panel="variantes">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 :class="claseTitulo">
                            Variantes
                            <span v-if="filas.length" class="text-sm font-normal text-neutral-500 dark:text-neutral-400">({{ incluidas.length }})</span>
                        </h2>
                        <button
                            v-if="filas.length && hayPreciosDistintos"
                            type="button"
                            :class="[claseSecundario, 'disabled:cursor-not-allowed disabled:opacity-50']"
                            :disabled="!precioBaseValido"
                            data-aplicar-precio
                            @click="aplicarPrecioATodas"
                        >
                            Aplicar {{ precioBaseValido ? soles(form.precio_base) : 'el precio' }} a todas
                        </button>
                    </div>

                    <p v-if="demasiadas" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200" data-demasiadas>
                        Son {{ totalCombinaciones }} variantes y el máximo es {{ MAX_COMBINACIONES }}. Quita algunas opciones o divide el modelo en dos.
                    </p>
                    <div v-else-if="!filas.length" class="py-6 text-center text-sm text-neutral-500 dark:text-neutral-400" data-sin-variantes>
                        <p>Agrega opciones y aquí aparecerá una fila por cada variante.</p>
                        <button type="button" class="mt-2 py-1 font-semibold text-emerald-600 hover:underline dark:text-emerald-400" @click="irA('varia')">Ir a «En qué varía»</button>
                    </div>

                    <template v-else>
                        <p v-if="hayErroresEnFilas" :class="[claseError, 'mb-3']">Revisa las filas marcadas en rojo.</p>
                        <p v-if="preciosBloqueados" class="mb-3 flex items-start gap-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            Tu rol no cambia precios de variantes que ya existen; sí puedes agregar nuevas y sus códigos de barras.
                        </p>

                        <!-- encabezado en PC: una columna por dato, alineada como su contenido -->
                        <div :class="[claseColumnas, claseEncabezado, 'hidden border-b border-stone-200 pb-2 dark:border-neutral-800']">
                            <span>Variante</span>
                            <span class="text-right">Precio</span>
                            <span>Código de barras</span>
                            <span class="text-center">{{ puedeStock ? 'Stock inicial' : 'Stock' }}</span>
                            <span />
                        </div>
                        <!-- encabezado en celular: las etiquetas una sola vez, sobre las dos columnas de cada fila -->
                        <div :class="[claseEncabezado, 'grid grid-cols-2 gap-3 border-b border-stone-200 pb-2 md:hidden dark:border-neutral-800']">
                            <span>Precio</span>
                            <span>{{ puedeStock ? 'Stock inicial' : 'Stock' }}</span>
                        </div>

                        <div class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <div
                                v-for="(fila, i) in filas"
                                :key="fila.clave"
                                class="py-3 md:py-2.5"
                                :class="claseColumnas"
                                data-variante-fila
                                :data-variante-omitida="fila.detalle.omitir ? '' : undefined"
                            >
                                <!-- renglón 1: la variante y su papelera (en PC la papelera va a la última columna) -->
                                <div class="flex min-w-0 items-center justify-between gap-2 md:contents">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold" :class="fila.detalle.omitir && 'text-neutral-400 line-through dark:text-neutral-500'" data-variante-nombre>
                                            {{ fila.valores.join(' · ') }}
                                        </p>
                                        <p
                                            v-if="notaDe(fila)"
                                            class="text-xs"
                                            :class="notaDe(fila) === 'Nueva' ? 'font-medium text-emerald-600 dark:text-emerald-400' : 'text-neutral-500 dark:text-neutral-400'"
                                            data-variante-nota
                                        >
                                            {{ notaDe(fila) }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="-mr-2 grid size-10 shrink-0 place-items-center rounded-xl text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 md:col-start-5 md:row-start-1 md:mr-0 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                        :title="fila.detalle.omitir ? 'Volver a incluir' : 'Quitar esta variante'"
                                        :aria-label="fila.detalle.omitir ? `Volver a incluir ${fila.valores.join(' ')}` : `Quitar ${fila.valores.join(' ')}`"
                                        data-variante-omitir
                                        @click="fila.detalle.omitir = !fila.detalle.omitir"
                                    >
                                        <RotateCcw v-if="fila.detalle.omitir" class="size-4" />
                                        <Trash2 v-else class="size-4" />
                                    </button>
                                </div>

                                <!-- renglón 2: precio y stock; renglón 3: código de barras (plegado en celular) -->
                                <div v-if="!fila.detalle.omitir" class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 md:contents">
                                    <div :class="[claseGrupo, 'min-w-0 px-2.5', precioBloqueado(fila) && claseBloqueado, errorFila(i, 'precio_venta') && '!border-red-400']">
                                        <span class="text-sm text-neutral-500">S/</span>
                                        <input
                                            :id="`precio-${i}`"
                                            v-model="fila.detalle.precio_venta"
                                            type="number"
                                            inputmode="decimal"
                                            step="0.01"
                                            min="0"
                                            :placeholder="precioBaseValido ? Number(form.precio_base).toFixed(2) : '0.00'"
                                            :readonly="precioBloqueado(fila)"
                                            :aria-label="`Precio de ${fila.valores.join(' ')}`"
                                            :class="[claseNumeroGrupo, 'text-right placeholder:!font-medium placeholder:!text-neutral-600 dark:placeholder:!text-neutral-300', precioBloqueado(fila) && 'cursor-not-allowed']"
                                            data-variante-precio
                                        />
                                    </div>

                                    <!-- stock: inicial (variante nueva) o el que ya tiene -->
                                    <div class="min-w-0 md:col-start-4 md:row-start-1">
                                        <div v-if="!fila.existente && puedeStock" :class="[claseGrupo, errorFila(i, 'stock_inicial') && '!border-red-400']">
                                            <button type="button" :class="[claseBotonPaso, 'w-9']" aria-label="Restar" :disabled="!(Number(fila.detalle.stock_inicial) > 0)" @click="cambiarStock(fila, -1)"><Minus class="size-4" /></button>
                                            <input
                                                :id="`stock-${i}`"
                                                v-model="fila.detalle.stock_inicial"
                                                type="number"
                                                inputmode="numeric"
                                                step="1"
                                                min="0"
                                                placeholder="0"
                                                :aria-label="`Stock inicial de ${fila.valores.join(' ')}`"
                                                :class="claseNumeroGrupo"
                                                data-variante-stock
                                            />
                                            <button type="button" :class="[claseBotonPaso, 'w-9']" aria-label="Sumar" @click="cambiarStock(fila, 1)"><Plus class="size-4" /></button>
                                        </div>
                                        <p v-else class="flex h-10 items-center text-sm text-neutral-600 md:justify-center dark:text-neutral-300" data-variante-stock-actual>
                                            {{ fila.existente ? `${cantidad(fila.existente.stock)} en stock` : '—' }}
                                        </p>
                                    </div>

                                    <div class="col-span-2 min-w-0 md:col-span-1 md:col-start-3 md:row-start-1">
                                        <button
                                            v-if="!barrasVisible(fila, i)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 py-1 text-xs font-semibold text-emerald-600 hover:underline md:hidden dark:text-emerald-400"
                                            data-variante-abrir-barras
                                            @click="abrirBarras(fila, i)"
                                        >
                                            <ScanBarcode class="size-4" />
                                            Agregar código de barras
                                        </button>
                                        <input
                                            :id="`barras-${i}`"
                                            v-model="fila.detalle.codigo_barras"
                                            type="text"
                                            maxlength="50"
                                            inputmode="numeric"
                                            autocomplete="off"
                                            placeholder="Código de barras"
                                            :aria-label="`Código de barras de ${fila.valores.join(' ')}`"
                                            :class="[claseInput, errorFila(i, 'codigo_barras') && '!border-red-400', !barrasVisible(fila, i) && 'hidden md:block']"
                                            data-variante-barras
                                            @keydown.enter.prevent
                                        />
                                    </div>
                                </div>

                                <p
                                    v-if="errorFila(i, 'precio_venta') || errorFila(i, 'codigo_barras') || errorFila(i, 'stock_inicial')"
                                    :class="[claseError, 'md:col-span-5']"
                                    data-variante-error
                                >
                                    {{ errorFila(i, 'precio_venta') || errorFila(i, 'codigo_barras') || errorFila(i, 'stock_inicial') }}
                                </p>
                            </div>
                        </div>
                    </template>
                </section>

                <!-- ============ Atrás / Siguiente ============ -->
                <!-- en celular, mientras se crea, "Siguiente" ya está en la barra fija: aquí solo se muestra en PC -->
                <div class="mt-4 flex items-center justify-between gap-3">
                    <button v-if="indicePestana > 0" type="button" :class="claseSecundario" data-atras @click="atras">
                        <ArrowLeft class="size-4" />
                        Atrás
                    </button>
                    <span v-else />
                    <button
                        v-if="!esUltima"
                        type="button"
                        :class="[editando ? claseSecundario : [clasePrimario, 'h-10 px-5 text-sm'], barraSiguiente && '!hidden lg:!inline-flex']"
                        data-siguiente
                        @click="siguiente"
                    >
                        Siguiente
                        <ArrowRight class="size-4" />
                    </button>
                </div>
            </div>

            <!-- ============ Resumen (en PC lleva el botón de guardar; en celular lo lleva la barra fija) ============ -->
            <aside class="rounded-2xl border border-stone-200 bg-white p-5 lg:sticky lg:top-20 dark:border-neutral-800 dark:bg-neutral-900" data-resumen-modelo>
                <h2 class="flex items-center gap-2 font-semibold tracking-tight">
                    <Layers class="size-4 text-emerald-600 dark:text-emerald-400" />
                    Resumen
                </h2>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-neutral-500 dark:text-neutral-400">Variantes</dt>
                        <dd class="font-medium" data-total-variantes>{{ incluidas.length }}</dd>
                    </div>
                    <div v-if="editando" class="flex justify-between gap-3">
                        <dt class="text-neutral-500 dark:text-neutral-400">Nuevas</dt>
                        <dd class="font-medium">{{ nuevas.length }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-neutral-500 dark:text-neutral-400">Precio de venta</dt>
                        <dd class="font-medium">{{ precioBaseValido ? soles(form.precio_base) : '—' }}</dd>
                    </div>
                    <div v-if="stockTotal > 0" class="flex justify-between gap-3">
                        <dt class="text-neutral-500 dark:text-neutral-400">Stock inicial</dt>
                        <dd class="font-medium" data-total-stock>{{ cantidad(stockTotal) }} {{ stockTotal === 1 ? 'unidad' : 'unidades' }}</dd>
                    </div>
                </dl>

                <template v-if="stockTotal > 0">
                    <label v-if="viendoTodas" class="mt-3 flex items-center gap-2 text-sm">
                        <MapPin class="size-4 shrink-0 text-neutral-400" />
                        <span class="shrink-0">Entra a</span>
                        <select v-model="form.sucursal_id" class="h-10 min-w-0 flex-1 rounded-xl border border-stone-300 bg-white px-2 text-sm font-semibold focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950" data-sucursal-inicial>
                            <option v-for="s in accesibles" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                        </select>
                    </label>
                    <p v-else-if="sucursalDestino" class="mt-3 flex items-center gap-2 text-sm">
                        <MapPin class="size-4 shrink-0 text-neutral-400" />
                        <span>El stock entra a <strong>{{ sucursalDestino }}</strong></span>
                    </p>
                </template>

                <p
                    v-if="porRetirar.length"
                    class="mt-4 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
                    data-aviso-retiro
                >
                    {{ porRetirar.length === 1 ? 'Se retirará 1 variante' : `Se retirarán ${porRetirar.length} variantes` }} de la venta
                    ({{ porRetirar.slice(0, 3).map((v) => v.valores.join(' ')).join(', ') }}{{ porRetirar.length > 3 ? '...' : '' }}).
                    Las que ya tienen ventas, compras o stock quedan inactivas con su historial; las demás se eliminan.
                </p>

                <!-- solo PC: en celular el motivo y el botón están en la barra fija -->
                <div class="hidden lg:block">
                    <button
                        v-if="motivoNoGuardar"
                        type="button"
                        class="mt-4 block text-left text-xs text-amber-700 hover:underline dark:text-amber-400"
                        data-motivo-no-guardar
                        @click="irAlProblema"
                    >
                        {{ motivoNoGuardar }}
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        :aria-disabled="!puedeGuardar"
                        :class="[clasePrimario, 'mt-3 h-12 w-full text-base', !puedeGuardar && 'opacity-50']"
                        data-guardar-modelo
                    >
                        <Check class="size-5" />
                        {{ textoGuardar }}
                    </button>
                </div>
                <p class="mt-2 text-center text-xs text-neutral-400 dark:text-neutral-500">
                    Cada variante queda como un producto más: se vende, se compra y lleva su stock por separado.
                </p>
            </aside>

            <!-- ============ Celular: barra fija con el resumen y el único botón principal ============ -->
            <div
                class="fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white px-4 pt-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom))] lg:hidden dark:border-neutral-800 dark:bg-neutral-900"
                data-barra-movil
            >
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold" data-barra-total>{{ contarVariantes(incluidas.length) }}</p>
                        <!-- lo que falta para guardar es tocable: lleva a su pestaña y a su campo -->
                        <button
                            v-if="motivoNoGuardar && (!barraSiguiente || intentoGuardar)"
                            type="button"
                            class="block max-w-full truncate text-left text-xs font-medium text-red-600 underline decoration-dotted underline-offset-2 dark:text-red-400"
                            data-barra-motivo
                            @click="irAlProblema"
                        >
                            {{ motivoNoGuardar }}
                        </button>
                        <p v-else class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                            <template v-if="precioBaseValido">{{ soles(form.precio_base) }} c/u</template>
                            <template v-else>Sin precio aún</template>
                            <template v-if="stockTotal > 0"> · {{ cantidad(stockTotal) }} {{ stockTotal === 1 ? 'unidad' : 'unidades' }}</template>
                        </p>
                    </div>
                    <button v-if="barraSiguiente" type="button" :class="[clasePrimario, 'h-11 shrink-0 px-5 text-sm']" data-siguiente @click="siguiente">
                        Siguiente
                        <ArrowRight class="size-4" />
                    </button>
                    <button
                        v-else
                        type="submit"
                        :disabled="form.processing"
                        :aria-disabled="!puedeGuardar"
                        :class="[clasePrimario, 'h-11 shrink-0 px-4 text-sm', !puedeGuardar && 'opacity-50']"
                        data-guardar-modelo
                    >
                        <Check class="size-4" />
                        {{ textoGuardar }}
                    </button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
