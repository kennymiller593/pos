<script setup>
// Series / IMEI: buscador de un equipo (dónde está o a quién se vendió, con su historia) y, debajo, el listado
// de unidades de la sucursal elegida. La búsqueda exacta mira toda la empresa.
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Camera, Package, ReceiptText, ScanBarcode, Search, Store, Truck, UserRound, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { puedeEscanear } from '@/composables/escaner'
import { useSucursales } from '@/composables/sucursales'

const props = defineProps({
    series: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    // la serie buscada y las fichas halladas (normalmente una)
    numero: { type: String, default: '' },
    fichas: { type: Array, default: () => [] },
    estados: { type: Object, required: true },
    productos: { type: Array, default: () => [] },
})

const { multisucursal } = useSucursales()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const fecha = (iso) => (iso ? new Date(String(iso).length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
const fechaHora = (iso) => (iso
    ? new Date(iso).toLocaleString('es-PE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—')

const CLASE_ESTADO = {
    disponible: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    vendida: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    en_transito: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    baja: 'bg-stone-200 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
}
// color del punto de cada paso de la historia: entra (verde), sale vendida (azul), sale por otra razón (gris)
const ENTRA = ['compra', 'ajuste_entrada', 'regularizacion', 'venta_anulada', 'devolucion', 'transferencia_entrada', 'transferencia_anulada']
const clasePunto = (tipo) => (tipo === 'venta' ? 'bg-sky-500' : ENTRA.includes(tipo) ? 'bg-emerald-500' : 'bg-neutral-400')

// hora de la venta ("21:23:05") con el mismo formato que la historia: 09:23 p. m.
const horaCorta = (hora) => (hora ? new Date(`2000-01-01T${String(hora).slice(0, 5)}:00`).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }) : '')

// ---- buscador ----
const texto = ref(props.numero)
const estado = ref(props.filtros.estado ?? '')
const producto = ref(props.filtros.producto ?? '')
const camaraAbierta = ref(false)

function ir(numero = texto.value) {
    router.get('/series', {
        numero: String(numero ?? '').trim() || undefined,
        estado: estado.value || undefined,
        producto: producto.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

function buscarSerie(numero) {
    texto.value = numero
    ir(numero)
    if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' })
}

function limpiarBusqueda() {
    texto.value = ''
    ir('')
}

function leerCamara(codigo) {
    texto.value = codigo
    ir(codigo)
    return { ok: true, mensaje: `Buscando ${codigo}` }
}

watch([estado, producto], () => ir(props.numero))
watch(() => props.numero, (n) => (texto.value = n))

const buscado = computed(() => props.numero.replace(/\s+/g, '').toUpperCase())
const hayFiltros = computed(() => !!(estado.value || producto.value))

const claseInput =
    'h-10 rounded-xl border border-[#E2E8F0] bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'
const claseDato = 'text-xs font-semibold tracking-wider text-neutral-400 uppercase'
</script>

<template>
    <AppLayout titulo="Series / IMEI">
        <!-- Buscador grande -->
        <form :class="[claseTarjeta, 'mb-4 p-4 sm:p-5']" @submit.prevent="ir()">
            <label for="buscar_serie" class="mb-2 block text-sm font-semibold tracking-tight">Busca un equipo por su serie o IMEI</label>
            <div class="flex gap-2">
                <div class="relative min-w-0 flex-1">
                    <ScanBarcode class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-neutral-400" />
                    <input
                        id="buscar_serie"
                        v-model="texto"
                        type="text"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        maxlength="60"
                        placeholder="Escanea o escribe la serie / IMEI completo"
                        class="h-12 w-full rounded-xl border border-stone-300 bg-white pr-10 pl-11 font-mono text-base placeholder:font-sans placeholder:text-sm placeholder:text-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                        data-buscar-serie
                    />
                    <button v-if="texto" type="button" class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800" aria-label="Limpiar búsqueda" data-buscar-serie-limpiar @click="limpiarBusqueda">
                        <X class="size-4" />
                    </button>
                </div>
                <button v-if="puedeEscanear" type="button" class="grid size-12 shrink-0 place-items-center rounded-xl border border-stone-300 text-neutral-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800" aria-label="Escanear con la cámara" @click="camaraAbierta = true">
                    <Camera class="size-5" />
                </button>
                <button type="submit" class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 sm:px-6" data-buscar-serie-enviar>
                    <Search class="size-4" />
                    <span class="hidden sm:inline">Buscar</span>
                </button>
            </div>
            <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">Busca en todas las sucursales. Verás dónde está el equipo o a quién se vendió, con qué comprobante y cuándo.</p>
        </form>

        <!-- Resultado de la búsqueda -->
        <div v-if="buscado && !fichas.length" :class="[claseTarjeta, 'mb-4 px-4 py-8 text-center']" data-serie-no-encontrada>
            <ScanBarcode class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
            <p class="text-sm font-medium">No hay ningún equipo con la serie <span class="font-mono">{{ buscado }}</span></p>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Escríbela completa, tal como figura en la caja o en el equipo.</p>
        </div>

        <div v-for="ficha in fichas" :key="ficha.id" :class="[claseTarjeta, 'mb-4 overflow-hidden']" data-serie-ficha :data-serie-estado="ficha.estado">
            <!-- cabecera: el equipo y su estado -->
            <div class="flex flex-wrap items-center gap-3 border-b border-stone-200 p-4 sm:p-5 dark:border-neutral-800">
                <div class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-xl bg-stone-50 text-neutral-300 dark:bg-neutral-950 dark:text-neutral-600">
                    <img v-if="ficha.producto.imagen_url" :src="ficha.producto.imagen_url" :alt="ficha.producto.nombre" class="size-full object-contain" />
                    <Package v-else class="size-6" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base leading-snug font-bold tracking-tight" data-serie-producto>{{ ficha.producto.nombre }}</h2>
                    <p class="mt-0.5 font-mono text-sm text-neutral-600 dark:text-neutral-300">
                        <span class="break-all">{{ ficha.numero_serie }}</span>
                        <!-- el 2.º IMEI baja entero a su propia línea si no entra al lado -->
                        <span v-if="ficha.serie_alterna" class="block text-neutral-400 sm:ml-2 sm:inline-block" data-serie-alterna-ficha>2.º {{ ficha.serie_alterna }}</span>
                    </p>
                </div>
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap" :class="CLASE_ESTADO[ficha.estado]" data-serie-estado-texto>{{ estados[ficha.estado] ?? ficha.estado }}</span>
            </div>

            <div class="grid gap-x-6 gap-y-5 p-4 sm:p-5 lg:grid-cols-2">
                <!-- datos -->
                <div class="grid content-start gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                    <!-- venta -->
                    <div v-if="ficha.venta" class="rounded-xl bg-sky-50 p-3 sm:col-span-2 lg:col-span-1 xl:col-span-2 dark:bg-sky-950/30" data-serie-venta>
                        <p :class="[claseDato, 'mb-1.5 flex items-center gap-1.5 !text-sky-700 dark:!text-sky-300']"><UserRound class="size-3.5" /> Vendido a</p>
                        <p class="text-sm font-semibold" data-serie-cliente>{{ ficha.venta.cliente }}<span v-if="ficha.venta.cliente_documento" class="font-normal text-neutral-500 dark:text-neutral-400"> · {{ ficha.venta.cliente_documento }}</span></p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-neutral-600 dark:text-neutral-300">
                            <ReceiptText class="size-3.5 shrink-0 text-neutral-400" />
                            <span data-serie-comprobante>{{ ficha.venta.tipo }} {{ ficha.venta.numero }}</span>
                            <!-- sin punto separador suelto: si la fecha baja de línea no queda nada colgando -->
                            <span class="whitespace-nowrap text-neutral-500 dark:text-neutral-400">{{ fecha(ficha.venta.fecha) }}{{ ficha.venta.hora ? ` · ${horaCorta(ficha.venta.hora)}` : '' }}</span>
                            <a v-if="ficha.venta.ticket" :href="ficha.venta.ticket" target="_blank" rel="noopener" class="font-medium text-emerald-700 hover:underline dark:text-emerald-400">Ver comprobante</a>
                        </p>
                    </div>

                    <div>
                        <p :class="[claseDato, 'mb-1 flex items-center gap-1.5']"><Store class="size-3.5" /> {{ ficha.estado === 'disponible' ? 'Está en' : (ficha.estado === 'en_transito' ? 'Salió de' : 'Última sucursal') }}</p>
                        <p class="text-sm font-medium" data-serie-sucursal>{{ ficha.sucursal ?? '—' }}</p>
                        <p v-if="ficha.destino" class="mt-0.5 text-sm text-amber-700 dark:text-amber-400" data-serie-destino>En camino a: <span class="font-medium">{{ ficha.destino }}</span></p>
                        <p v-if="ficha.costo_unitario !== null" class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">Costo de ingreso {{ soles(ficha.costo_unitario) }}</p>
                    </div>

                    <div data-serie-compra>
                        <p :class="[claseDato, 'mb-1 flex items-center gap-1.5']"><Truck class="size-3.5" /> Ingresó con</p>
                        <p v-if="!ficha.ver_compra" class="text-sm text-neutral-500 dark:text-neutral-400" data-serie-compra-oculta>Solo quien ve Compras ve este dato.</p>
                        <template v-else-if="ficha.compra">
                            <p class="text-sm font-medium">Compra {{ ficha.compra.documento ?? 'sin documento' }}<span v-if="ficha.compra.anulada" class="ml-1 text-xs font-normal text-red-600 dark:text-red-400">(anulada)</span></p>
                            <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ fecha(ficha.compra.fecha) }}<template v-if="ficha.compra.proveedor"> · {{ ficha.compra.proveedor }}</template></p>
                        </template>
                        <p v-else class="text-sm font-medium">Ajuste de stock</p>
                    </div>
                </div>

                <!-- línea de tiempo -->
                <div data-serie-historial>
                    <p :class="[claseDato, 'mb-2']">Historia del equipo</p>
                    <ol class="scroll-fino max-h-64 space-y-0 overflow-y-auto pr-1">
                        <li v-for="(paso, i) in ficha.historial" :key="paso.id" class="relative flex gap-3 pb-3 last:pb-0" :data-serie-paso="paso.tipo">
                            <span v-if="i < ficha.historial.length - 1" class="absolute top-3 left-[5px] h-full w-px bg-stone-200 dark:bg-neutral-700" />
                            <span class="relative mt-1.5 size-[11px] shrink-0 rounded-full ring-2 ring-white dark:ring-neutral-900" :class="clasePunto(paso.tipo)" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm leading-snug font-medium">{{ paso.titulo }}<span v-if="paso.detalle" class="font-normal text-neutral-600 dark:text-neutral-300"> · {{ paso.detalle }}</span></p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ fechaHora(paso.fecha) }}<template v-if="paso.sucursal && multisucursal"> · {{ paso.sucursal }}</template><template v-if="paso.usuario"> · {{ paso.usuario }}</template>
                                </p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Listado de unidades -->
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
            <h2 class="text-sm font-semibold tracking-tight sm:mr-auto">Unidades registradas</h2>
            <div class="grid grid-cols-2 gap-2 sm:flex">
                <select v-model="estado" aria-label="Estado" :class="[claseInput, 'w-full sm:w-40']" data-filtro-estado>
                    <option value="">Todos los estados</option>
                    <option v-for="(nombre, codigo) in estados" :key="codigo" :value="codigo">{{ nombre }}</option>
                </select>
                <select v-model="producto" aria-label="Producto" :class="[claseInput, 'w-full min-w-0 sm:w-56']" data-filtro-producto>
                    <option value="">Todos los productos</option>
                    <option v-for="p in productos" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                </select>
            </div>
        </div>

        <div :class="[claseTarjeta, 'overflow-hidden']" data-series-listado>
            <p v-if="!series.data.length" class="px-4 py-12 text-center text-sm text-neutral-500 dark:text-neutral-400">
                <ScanBarcode class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                <template v-if="hayFiltros">Ninguna unidad coincide con el filtro.</template>
                <template v-else>Aún no hay unidades con serie.<br />Marca un producto como "Controla serie / IMEI" y regístralas al comprar o con un ajuste de stock.</template>
            </p>

            <!-- celular: tarjetas -->
            <ul v-if="series.data.length" class="divide-y divide-stone-100 md:hidden dark:divide-neutral-800">
                <li v-for="s in series.data" :key="s.id">
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-stone-50 dark:hover:bg-neutral-800/40" :data-serie-fila="s.numero_serie" @click="buscarSerie(s.numero_serie)">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-sm font-medium">{{ s.numero_serie }}</p>
                            <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ s.producto }}<template v-if="multisucursal && s.sucursal"> · {{ s.sucursal }}</template></p>
                        </div>
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="CLASE_ESTADO[s.estado]">{{ estados[s.estado] ?? s.estado }}</span>
                    </button>
                </li>
            </ul>

            <!-- PC: tabla -->
            <div v-if="series.data.length" class="hidden overflow-x-auto md:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Serie / IMEI</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Producto</th>
                            <th v-if="multisucursal" class="px-4 py-3.5 font-semibold tracking-wider">Sucursal</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Último movimiento</th>
                            <th class="w-24 px-4 py-3.5" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-for="s in series.data" :key="s.id" class="hover:bg-stone-50/60 dark:hover:bg-neutral-800/30" :data-serie-fila="s.numero_serie">
                            <td class="px-4 py-3 font-mono text-sm font-medium">
                                {{ s.numero_serie }}<span v-if="s.serie_alterna" class="block text-[11px] font-normal text-neutral-400">2.º {{ s.serie_alterna }}</span>
                            </td>
                            <td class="px-4 py-3">{{ s.producto }}</td>
                            <td v-if="multisucursal" class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ s.sucursal }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap" :class="CLASE_ESTADO[s.estado]">{{ estados[s.estado] ?? s.estado }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-500 dark:text-neutral-400">{{ fechaHora(s.actualizado_en) }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" class="rounded-lg px-2.5 py-1.5 text-sm font-medium whitespace-nowrap text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40" data-serie-ver @click="buscarSerie(s.numero_serie)">Ver ficha</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="series.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ series.from }}–{{ series.to }} de {{ series.total }} unidades</span>
                <div class="flex flex-wrap justify-center gap-1.5">
                    <template v-for="(link, i) in series.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-lg border px-3.5 py-1.5"
                            :class="link.active
                                ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            v-html="link.label"
                        />
                        <span v-else class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>

        <EscanerCamara v-if="puedeEscanear" :abierto="camaraAbierta" :al-leer="leerCamara" una-lectura titulo="Escanear serie / IMEI" @cerrar="camaraAbierta = false" />
    </AppLayout>
</template>
