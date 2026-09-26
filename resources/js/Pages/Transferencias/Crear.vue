<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, ArrowLeftRight, ArrowRight, Package, ScanBarcode, Search, Trash2 } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { puedeEscanear } from '@/composables/escaner'

const props = defineProps({
    origen: { type: Object, default: null },
    destinos: { type: Array, required: true },
    productos: { type: Array, required: true },
})

const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const form = useForm({
    sucursal_destino_id: props.destinos[0]?.id ?? '',
    observacion: '',
    items: [],
})

// ---- items ----
const buscarProducto = ref('')

const resultados = computed(() => {
    const texto = buscarProducto.value.trim().toLowerCase()
    if (!texto) return []
    return props.productos
        .filter((p) =>
            p.nombre.toLowerCase().includes(texto)
            || p.codigo_interno.toLowerCase().includes(texto)
            || p.codigos.some((c) => c.codigo === texto))
        .slice(0, 8)
})

// producto con ese código de barras y cuántas unidades base representa (una caja x25 = 25)
function buscarPorCodigo(codigo) {
    for (const producto of props.productos) {
        const c = producto.codigos.find((x) => x.codigo === codigo)
        if (c) return { producto, unidades: c.factor || 1 }
    }
    return null
}

// pistola lectora (o Enter al escribir): el código exacto se agrega directo
function alPresionarEnter() {
    const texto = buscarProducto.value.trim()
    if (!texto) return
    const encontrado = buscarPorCodigo(texto)
    if (encontrado) return agregar(encontrado.producto, encontrado.unidades)
    if (resultados.value.length === 1) agregar(resultados.value[0])
}

// ---- escáner con la cámara (celulares) ----
const escanerAbierto = ref(false)

function leerCodigoCamara(codigo) {
    const encontrado = buscarPorCodigo(codigo)
    if (!encontrado) return { ok: false, mensaje: `Código ${codigo} no está registrado en tus productos.` }
    const fila = agregar(encontrado.producto, encontrado.unidades)
    const aviso = excedeStock(fila) ? ` (solo hay ${cantidad(fila.producto.stock)} disp.)` : ''
    return { ok: !aviso, mensaje: `${encontrado.producto.nombre} · ${cantidad(fila.cantidad)} a transferir${aviso}` }
}

const filas = ref([]) // { producto, cantidad }

function agregar(producto, unidades = 1) {
    let fila = filas.value.find((f) => f.producto.id === producto.id)
    if (fila) {
        fila.cantidad = Number(fila.cantidad) + unidades
    } else {
        fila = { producto, cantidad: unidades }
        filas.value.push(fila)
    }
    buscarProducto.value = ''
    return fila
}

function excedeStock(fila) {
    return Number(fila.cantidad || 0) > fila.producto.stock
}

const puedeEnviar = computed(() =>
    filas.value.length > 0 &&
    form.sucursal_destino_id &&
    filas.value.every((f) => Number(f.cantidad) > 0 && !excedeStock(f)),
)

function enviar() {
    form.items = filas.value.map((f) => ({
        producto_id: f.producto.id,
        cantidad: Number(f.cantidad),
    }))

    form.transform((data) => ({
        ...data,
        observacion: data.observacion || null,
    })).post('/transferencias')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Nueva transferencia">
        <div>
            <Link
                href="/transferencias"
                class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100"
            >
                <ArrowLeft class="size-4" />
                Volver a transferencias
            </Link>

            <!-- Sin destinos posibles -->
            <div
                v-if="!destinos.length"
                class="rounded-2xl border border-stone-200 bg-white p-8 text-center dark:border-neutral-800 dark:bg-neutral-900"
            >
                <ArrowLeftRight class="mx-auto mb-3 size-8 text-neutral-300 dark:text-neutral-600" />
                <h2 class="font-semibold tracking-tight">Necesitas al menos dos sucursales activas</h2>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    Crea otra sucursal en
                    <Link href="/sucursales" class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">Configuración → Sucursales</Link>
                    para poder transferir mercadería.
                </p>
            </div>

            <template v-else>
                <!-- Ruta -->
                <div class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label :class="claseLabel">Desde</label>
                            <div class="flex h-10 items-center rounded-xl border border-stone-200 bg-stone-100 px-3 text-sm font-medium text-neutral-600 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ origen?.nombre ?? '—' }}
                            </div>
                        </div>
                        <ArrowRight class="mx-auto size-5 shrink-0 text-neutral-400 sm:mb-2.5" />
                        <div class="flex-1">
                            <label :class="claseLabel" for="destino">Hacia *</label>
                            <select id="destino" v-model="form.sucursal_destino_id" :class="claseInput">
                                <option v-for="d in destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                            </select>
                            <p v-if="form.errors.sucursal_destino_id" :class="claseError">{{ form.errors.sucursal_destino_id }}</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label :class="claseLabel" for="observacion">Observación</label>
                        <input
                            id="observacion"
                            v-model="form.observacion"
                            type="text"
                            maxlength="200"
                            :class="claseInput"
                            placeholder="Ej. reposición semanal"
                        />
                    </div>
                </div>

                <!-- Productos -->
                <div class="mt-4 rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="mb-4 font-semibold tracking-tight">Productos a transferir</h2>

                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="buscarProducto"
                            type="text"
                            placeholder="Busca o escanea un producto (nombre, código o código de barras)..."
                            :class="[claseInput, 'h-11 pl-10', puedeEscanear ? 'pr-12' : '']"
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
                        <div
                            v-if="resultados.length"
                            class="absolute top-12 right-0 left-0 z-10 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <button
                                v-for="p in resultados"
                                :key="p.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-700"
                                @click="agregar(p)"
                            >
                                <Package class="size-4 shrink-0 text-neutral-400" />
                                <span class="font-medium">{{ p.nombre }}</span>
                                <span class="ml-auto text-xs text-neutral-500 dark:text-neutral-400">{{ cantidad(p.stock) }} disp.</span>
                            </button>
                        </div>
                    </div>

                    <EscanerCamara
                        v-if="puedeEscanear"
                        :abierto="escanerAbierto"
                        :al-leer="leerCodigoCamara"
                        :resumen="filas.length ? `${filas.length} ${filas.length === 1 ? 'producto' : 'productos'} a transferir` : ''"
                        @cerrar="escanerAbierto = false"
                    />

                    <p v-if="form.errors.items" :class="claseError">{{ form.errors.items }}</p>

                    <div v-if="filas.length" class="mt-4 space-y-2">
                        <div
                            v-for="(fila, i) in filas"
                            :key="fila.producto.id"
                            class="flex items-center gap-3 rounded-xl bg-stone-50 px-4 py-3 dark:bg-neutral-950"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ fila.producto.nombre }}</p>
                                <p class="text-xs" :class="excedeStock(fila) ? 'font-medium text-red-600 dark:text-red-400' : 'text-neutral-500 dark:text-neutral-400'">
                                    {{ cantidad(fila.producto.stock) }} disponibles en {{ origen?.nombre }}
                                </p>
                            </div>
                            <input
                                v-model="fila.cantidad"
                                type="number"
                                :step="fila.producto.permite_fraccion ? '0.001' : '1'"
                                min="0"
                                class="h-9 w-24 rounded-lg border border-stone-200 bg-white text-center text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900"
                                :class="excedeStock(fila) ? 'border-red-400 dark:border-red-600' : ''"
                            />
                            <button
                                type="button"
                                class="rounded-lg p-2 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                @click="filas.splice(i, 1)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="mt-6 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        Aún no agregas productos.
                    </p>
                </div>

                <!-- Enviar -->
                <div class="mt-4 flex justify-end">
                    <button
                        :disabled="!puedeEnviar || form.processing"
                        class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 text-base font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="enviar"
                    >
                        <ArrowLeftRight class="size-5" />
                        {{ form.processing ? 'Enviando...' : 'Enviar transferencia' }}
                    </button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
