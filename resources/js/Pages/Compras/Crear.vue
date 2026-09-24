<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { ArrowLeft, LoaderCircle, MapPin, Package, Plus, Search, Trash2, Truck, UserRound, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

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
        .filter((p) => p.nombre.toLowerCase().includes(texto) || p.codigo_interno.toLowerCase().includes(texto))
        .slice(0, 8)
})

// filas: { producto, presentacion_id, cantidad, costo_unitario }
const filas = ref([])

function agregarProducto(producto) {
    const def = producto.presentaciones.find((p) => p.es_default) ?? producto.presentaciones[0]
    const existente = filas.value.find((f) => f.presentacion_id === def.id)
    if (existente) {
        existente.cantidad = Number(existente.cantidad) + 1
    } else {
        filas.value.push({
            producto,
            presentacion_id: def.id,
            cantidad: 1,
            costo_unitario: '',
            numero_lote: '',
            fecha_vencimiento: '',
        })
    }
    buscarProducto.value = ''
}

function presentacionDe(fila) {
    return fila.producto.presentaciones.find((p) => p.id === fila.presentacion_id)
}

function subtotal(fila) {
    return Number(fila.cantidad || 0) * Number(fila.costo_unitario || 0)
}

const total = computed(() => filas.value.reduce((suma, f) => suma + subtotal(f), 0))

const puedeGuardar = computed(() =>
    filas.value.length > 0 &&
    filas.value.every((f) =>
        Number(f.cantidad) > 0 &&
        f.costo_unitario !== '' &&
        Number(f.costo_unitario) >= 0 &&
        (!f.producto.controla_lote || f.numero_lote.trim() !== ''),
    ),
)

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
        serie_numero: data.serie_numero || null,
        fecha_vencimiento: data.es_credito ? (data.fecha_vencimiento || null) : null,
    })).post('/compras')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Nueva compra">
        <div class="mx-auto max-w-4xl">
            <Link
                href="/compras"
                class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100"
            >
                <ArrowLeft class="size-4" />
                Volver a compras
            </Link>

            <!-- Cabecera -->
            <div class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="mb-5 flex items-center gap-3">
                    <div class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <Truck class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold tracking-tight">Datos de la compra</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">El stock y el costo se actualizan al guardar.</p>
                    </div>
                    <div
                        v-if="sucursalDestino"
                        class="flex shrink-0 items-center gap-1.5 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                        title="La sucursal se cambia con el selector de la barra superior"
                    >
                        <MapPin class="size-4 shrink-0" />
                        <span class="truncate">Ingresa a: <strong>{{ sucursalDestino }}</strong></span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <!-- Proveedor -->
                    <div class="sm:col-span-2">
                        <label :class="claseLabel">Proveedor</label>
                        <div v-if="proveedorSeleccionado" class="flex h-10 items-center justify-between rounded-xl border border-stone-300 bg-stone-50 px-3 dark:border-neutral-700 dark:bg-neutral-950">
                            <div class="flex min-w-0 items-center gap-2 text-sm">
                                <UserRound class="size-4 shrink-0 text-neutral-400" />
                                <span class="truncate font-medium">{{ proveedorSeleccionado.razon_social }}</span>
                                <span v-if="proveedorSeleccionado.ruc" class="text-xs text-neutral-500">{{ proveedorSeleccionado.ruc }}</span>
                            </div>
                            <button type="button" class="rounded-lg p-1 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200" @click="quitarProveedor">
                                <X class="size-4" />
                            </button>
                        </div>
                        <div v-else class="flex gap-2">
                            <div class="relative flex-1">
                                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                <input
                                    v-model="buscarProveedor"
                                    type="text"
                                    placeholder="Buscar por razón social o RUC (opcional)"
                                    :class="[claseInput, 'pl-9']"
                                />
                                <div
                                    v-if="resultadosProveedor.length"
                                    class="absolute top-11 right-0 left-0 z-10 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
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
                                class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl border border-stone-300 px-3.5 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="modalProveedor = true"
                            >
                                <Plus class="size-4" />
                                Nuevo
                            </button>
                        </div>
                    </div>

                    <div>
                        <label :class="claseLabel" for="fecha">Fecha *</label>
                        <input id="fecha" v-model="form.fecha" type="date" :class="claseInput" />
                        <p v-if="form.errors.fecha" :class="claseError">{{ form.errors.fecha }}</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="tipo_doc">Tipo de documento</label>
                        <select id="tipo_doc" v-model="form.tipo_comprobante_codigo" :class="claseInput">
                            <option value="">Sin documento</option>
                            <option v-for="t in tiposComprobante" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                        </select>
                    </div>
                    <div>
                        <label :class="claseLabel" for="serie_numero">Serie y número</label>
                        <input id="serie_numero" v-model="form.serie_numero" type="text" :class="claseInput" placeholder="F001-000123" />
                    </div>
                    <div class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.es_credito" type="checkbox" class="size-4 rounded accent-emerald-600" />
                            Compra al crédito
                        </label>
                    </div>
                    <div v-if="form.es_credito">
                        <label :class="claseLabel" for="fecha_vencimiento">Fecha de pago acordada</label>
                        <input id="fecha_vencimiento" v-model="form.fecha_vencimiento" type="date" :min="form.fecha" :class="claseInput" />
                        <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                            La deuda aparecerá en Cuentas por pagar.
                        </p>
                        <p v-if="form.errors.fecha_vencimiento" :class="claseError">{{ form.errors.fecha_vencimiento }}</p>
                        <p v-if="form.errors.proveedor_id" :class="claseError">{{ form.errors.proveedor_id }}</p>
                    </div>
                </div>
            </div>

            <!-- Productos -->
            <div class="mt-4 rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 class="mb-4 font-semibold tracking-tight">Productos</h2>

                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                    <input
                        v-model="buscarProducto"
                        type="text"
                        placeholder="Busca un producto por nombre o código para agregarlo..."
                        :class="[claseInput, 'h-11 pl-10']"
                    />
                    <div
                        v-if="resultadosProducto.length"
                        class="absolute top-12 right-0 left-0 z-10 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
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

                <p v-if="form.errors.items" :class="claseError">{{ form.errors.items }}</p>

                <!-- Filas -->
                <div v-if="filas.length" class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="py-2.5 pr-3 font-semibold tracking-wider">Producto</th>
                                <th class="px-3 py-2.5 font-semibold tracking-wider">Presentación</th>
                                <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Cantidad</th>
                                <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Costo unit. (S/)</th>
                                <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Subtotal</th>
                                <th class="py-2.5 pl-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <template v-for="(fila, i) in filas" :key="fila.presentacion_id">
                            <tr>
                                <td class="py-3 pr-3">
                                    <p class="font-medium">{{ fila.producto.nombre }}</p>
                                    <p v-if="!fila.producto.controla_stock" class="text-xs text-amber-600 dark:text-amber-400">
                                        No controla stock: no genera inventario
                                    </p>
                                </td>
                                <td class="px-3 py-3">
                                    <select
                                        v-model="fila.presentacion_id"
                                        class="h-9 rounded-lg border border-stone-200 bg-white px-2 text-xs focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                    >
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
                                        class="h-9 w-24 rounded-lg border border-stone-200 bg-white text-right text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                    />
                                </td>
                                <td class="px-3 py-3">
                                    <input
                                        v-model="fila.costo_unitario"
                                        type="number"
                                        step="0.000001"
                                        min="0"
                                        placeholder="0.00"
                                        class="h-9 w-28 rounded-lg border border-stone-200 bg-white text-right text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                    />
                                </td>
                                <td class="px-3 py-3 text-right font-semibold whitespace-nowrap">{{ soles(subtotal(fila)) }}</td>
                                <td class="py-3 pl-3">
                                    <button
                                        type="button"
                                        class="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        @click="filas.splice(i, 1)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </td>
                            </tr>

                            <!-- Lote y vencimiento (productos que controlan lote) -->
                            <tr v-if="fila.producto.controla_lote" class="!border-t-0">
                                <td colspan="6" class="pt-0 pb-3">
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
                <p v-else class="mt-6 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    Aún no agregas productos a la compra.
                </p>
            </div>

            <!-- Total y guardar -->
            <div class="mt-4 flex items-center justify-between rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Total de la compra</p>
                    <p class="text-2xl font-bold tracking-tight">{{ soles(total) }}</p>
                </div>
                <button
                    :disabled="!puedeGuardar || form.processing"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="guardar"
                >
                    {{ form.processing ? 'Registrando...' : 'Registrar compra' }}
                </button>
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
