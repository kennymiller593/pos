<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { ArrowRight, Ban, Eye, FileCode2, FileDown, LoaderCircle, Navigation, Plus, RefreshCw, Search, ShieldCheck, TriangleAlert, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    guias: { type: Object, required: true },
    filtros: { type: Object, required: true },
    envioSunat: { type: Object, default: () => ({ activo: false, falta_credenciales: false }) },
})

const { puede } = usePermisos()

const fecha = (f) => (f ? new Date(`${String(f).slice(0, 10)}T00:00:00`) : null)?.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const ESTADOS = {
    anulada: { texto: 'Anulada', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' },
    aceptado: { texto: 'Aceptada', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    observado: { texto: 'Aceptada', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    en_proceso: { texto: 'En proceso', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
    pendiente: { texto: 'Por enviar', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    rechazado: { texto: 'Rechazada', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' },
}
const estadoDe = (g) => ESTADOS[g.estado === 'anulada' ? 'anulada' : g.estado_sunat] ?? ESTADOS.pendiente
const sinResolver = (g) => g.estado === 'emitida' && ['pendiente', 'en_proceso'].includes(g.estado_sunat)

// ---- filtros ----
const filtro = reactive({ ...props.filtros })

watchDebounced(filtro, () => {
    const parametros = Object.fromEntries(Object.entries(filtro).filter(([, v]) => v !== '' && v !== null))
    router.get('/guias', parametros, { preserveState: true, preserveScroll: true, replace: true })
}, { debounce: 350, deep: true })

const hayFiltros = () => Object.values(filtro).some((v) => v !== '' && v !== null)
function limpiarFiltros() {
    Object.assign(filtro, { buscar: '', estado: '', desde: '', hasta: '' })
}

// ---- detalle ----
const verId = ref(null)
// siempre la versión fresca: el estado SUNAT cambia mientras el detalle está abierto
const ver = computed(() => props.guias.data.find((g) => g.id === verId.value) ?? null)

// ---- SUNAT: el envío corre tras guardar, así que la lista se refresca sola un rato ----
let sondeo = null
let vueltas = 0
function sondear() {
    clearInterval(sondeo)
    vueltas = 0
    if (!props.envioSunat.activo) return
    sondeo = setInterval(() => {
        vueltas++
        const hayPendientes = props.guias.data.some((g) => g.estado === 'emitida' && (g.estado_sunat === 'en_proceso' || (g.estado_sunat === 'pendiente' && !g.sunat_mensaje)))
        if (!hayPendientes || vueltas > 8) return clearInterval(sondeo)
        router.reload({ only: ['guias'], preserveScroll: true })
    }, 4000)
}
onMounted(sondear)
onBeforeUnmount(() => clearInterval(sondeo))

const enviando = ref(null)
function enviarSunat(g) {
    enviando.value = g.id
    router.post(`/guias/${g.id}/sunat`, {}, {
        preserveScroll: true,
        onFinish: () => {
            enviando.value = null
            sondear()
        },
    })
}

// ---- anular ----
const guiaAnular = ref(null)
const formAnular = useForm({ motivo: '' })

function abrirAnulacion(g) {
    guiaAnular.value = g
    formAnular.clearErrors()
    formAnular.motivo = ''
}

function anular() {
    formAnular.post(`/guias/${guiaAnular.value.id}/anular`, {
        preserveScroll: true,
        onSuccess: () => (guiaAnular.value = null),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseIcono = 'rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
</script>

<template>
    <AppLayout titulo="Guías de remisión">
        <!-- Aviso: por qué las guías no están llegando a SUNAT -->
        <div
            v-if="!envioSunat.activo || envioSunat.falta_credenciales"
            class="mb-4 flex items-start gap-2.5 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <p>
                <template v-if="!envioSunat.activo">La facturación electrónica está apagada: las guías se guardan pero no se envían a SUNAT.</template>
                <template v-else>Faltan las credenciales de la API de guías (Client ID y Client Secret): las guías quedan pendientes de envío.</template>
                <Link v-if="puede('empresa.gestionar')" href="/empresa" class="ml-1 font-semibold underline">Configurar en Empresa</Link>
            </p>
        </div>

        <!-- Filtros y acción principal -->
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="grid flex-1 gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_10rem_9.5rem_9.5rem_auto]">
                <div class="relative sm:col-span-2 lg:col-span-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="filtro.buscar" type="text" placeholder="Buscar por destinatario, documento, placa o número..." :class="[claseInput, 'pl-9']" />
                </div>
                <select v-model="filtro.estado" :class="claseInput" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <option value="aceptado">Aceptadas</option>
                    <option value="en_proceso">En proceso</option>
                    <option value="pendiente">Por enviar</option>
                    <option value="rechazado">Rechazadas</option>
                    <option value="anulada">Anuladas</option>
                </select>
                <input v-model="filtro.desde" type="date" :class="claseInput" aria-label="Desde" title="Desde" />
                <input v-model="filtro.hasta" type="date" :class="claseInput" aria-label="Hasta" title="Hasta" />
                <button
                    v-if="hayFiltros()"
                    type="button"
                    class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl px-3 text-sm font-medium text-neutral-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                    @click="limpiarFiltros"
                >
                    <X class="size-4" />
                    Limpiar
                </button>
            </div>
            <Link
                v-if="puede('guias.gestionar')"
                href="/guias/crear"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                <Plus class="size-4" />
                Nueva guía
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Número</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Traslado</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Destinatario</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Motivo</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Transporte</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">SUNAT</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!guias.data.length">
                            <td colspan="7" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <Navigation class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    <template v-if="hayFiltros()">No hay guías con esos filtros.</template>
                                    <template v-else>
                                        Aún no emites guías de remisión.
                                        <Link v-if="puede('guias.gestionar')" href="/guias/crear" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400">Emite la primera</Link>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="g in guias.data"
                            :key="g.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            :class="g.estado === 'anulada' ? 'opacity-60' : ''"
                            @click="verId = g.id"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                <p class="font-mono text-xs font-semibold">{{ g.numero }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ fecha(g.fecha_emision) }}</p>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ fecha(g.fecha_traslado) }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ g.destinatario_nombre }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ g.destinatario_numero_doc }}</p>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                {{ g.motivo === 'Traslado entre establecimientos de la misma empresa' ? 'Entre mis locales' : g.motivo }}
                                <p v-if="g.comprobante" class="font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ g.comprobante }}</p>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                {{ g.modalidad === '01' ? 'Público' : 'Privado' }}
                                <p v-if="g.vehiculo_placa" class="font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ g.vehiculo_placa }}</p>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="estadoDe(g).clase" :title="g.sunat_mensaje ?? ''">
                                    <LoaderCircle v-if="g.estado === 'emitida' && g.estado_sunat === 'en_proceso'" class="size-3 animate-spin" />
                                    {{ estadoDe(g).texto }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-0.5">
                                    <button
                                        v-if="sinResolver(g) && puede('guias.gestionar') && envioSunat.activo"
                                        class="mr-1 inline-flex h-8 items-center gap-1.5 rounded-lg border border-stone-300 px-2.5 text-xs font-semibold whitespace-nowrap hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        :disabled="enviando === g.id"
                                        :title="g.estado_sunat === 'en_proceso' ? 'Consultar el resultado en SUNAT' : 'Enviar a SUNAT'"
                                        @click.stop="enviarSunat(g)"
                                    >
                                        <RefreshCw class="size-3.5" :class="enviando === g.id ? 'animate-spin' : ''" />
                                        {{ g.estado_sunat === 'en_proceso' ? 'Consultar' : 'Enviar' }}
                                    </button>
                                    <button :class="claseIcono" title="Ver detalle" @click.stop="verId = g.id"><Eye class="size-4" /></button>
                                    <a :href="`/guias/${g.id}/pdf`" target="_blank" rel="noopener" :class="claseIcono" title="Ver PDF" @click.stop><FileDown class="size-4" /></a>
                                    <button
                                        v-if="g.estado === 'emitida' && g.estado_sunat !== 'en_proceso' && puede('guias.anular')"
                                        class="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        title="Anular"
                                        @click.stop="abrirAnulacion(g)"
                                    >
                                        <Ban class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="guias.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ guias.from }}–{{ guias.to }} de {{ guias.total }} guías</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in guias.links" :key="i">
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

        <!-- Modal detalle -->
        <Teleport to="body">
            <div v-if="ver" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="verId = null" />
                <div class="relative w-full max-w-xl rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div class="min-w-0">
                            <h3 class="flex flex-wrap items-center gap-2 font-semibold tracking-tight">
                                Guía {{ ver.numero }}
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="estadoDe(ver).clase">{{ estadoDe(ver).texto }}</span>
                            </h3>
                            <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                {{ ver.destinatario_nombre }} · traslado el {{ fecha(ver.fecha_traslado) }}
                            </p>
                        </div>
                        <button class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" @click="verId = null">
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="max-h-[60vh] space-y-4 overflow-y-auto px-6 py-4">
                        <!-- Respuesta de SUNAT -->
                        <div
                            v-if="ver.estado === 'anulada'"
                            class="rounded-xl bg-red-100 px-3 py-2 text-xs text-red-800 dark:bg-red-500/15 dark:text-red-300"
                        >
                            Anulada<template v-if="ver.motivo_anulacion">: {{ ver.motivo_anulacion }}</template>
                        </div>
                        <div
                            v-else-if="ver.sunat_mensaje"
                            class="flex items-start gap-2 rounded-xl px-3 py-2 text-xs"
                            :class="['aceptado', 'observado'].includes(ver.estado_sunat)
                                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : ver.estado_sunat === 'rechazado'
                                    ? 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-300'
                                    : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'"
                        >
                            <ShieldCheck class="mt-0.5 size-4 shrink-0" />
                            <span class="break-words">{{ ver.sunat_mensaje }}</span>
                        </div>

                        <!-- Ruta -->
                        <div class="grid items-stretch gap-2 sm:grid-cols-[1fr_auto_1fr]">
                            <div class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-neutral-950/60">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Partida</p>
                                <p class="font-medium">{{ ver.partida }}</p>
                            </div>
                            <ArrowRight class="hidden size-4 self-center text-neutral-400 sm:block" />
                            <div class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-neutral-950/60">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Llegada</p>
                                <p class="font-medium">{{ ver.llegada }}</p>
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div>
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Motivo</dt>
                                <dd class="font-medium">{{ ver.motivo }}<template v-if="ver.motivo_descripcion">: {{ ver.motivo_descripcion }}</template></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Peso bruto</dt>
                                <dd class="font-medium">{{ cantidad(ver.peso_bruto) }} kg<template v-if="ver.bultos"> · {{ ver.bultos }} bulto(s)</template></dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Transporte {{ ver.modalidad === '01' ? 'público' : 'privado' }}</dt>
                                <dd class="font-medium">
                                    <template v-if="ver.modalidad === '01'">{{ ver.transportista }}</template>
                                    <template v-else>
                                        {{ ver.vehiculo_placa ? `Placa ${ver.vehiculo_placa}` : 'Sin placa' }}<template v-if="ver.vehiculo_menor"> (vehículo menor)</template>
                                        <span v-if="ver.conductor" class="block font-normal text-neutral-600 dark:text-neutral-300">{{ ver.conductor }}</span>
                                    </template>
                                </dd>
                            </div>
                            <div v-if="ver.comprobante">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Venta relacionada</dt>
                                <dd class="font-mono font-medium">{{ ver.comprobante }}</dd>
                            </div>
                            <div v-if="ver.observaciones" class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Observaciones</dt>
                                <dd>{{ ver.observaciones }}</dd>
                            </div>
                        </dl>

                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pb-2 font-semibold tracking-wider">Producto</th>
                                    <th class="pb-2 text-right font-semibold tracking-wider">Cantidad</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="d in ver.detalles" :key="d.id">
                                    <td class="py-2.5 pr-2">
                                        {{ d.descripcion }}
                                        <span class="block font-mono text-xs text-neutral-400">{{ d.codigo }}</span>
                                    </td>
                                    <td class="py-2.5 text-right font-medium whitespace-nowrap">{{ cantidad(d.cantidad) }} <span class="text-xs font-normal text-neutral-400">{{ d.unidad_codigo }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <a v-if="ver.tiene_xml" :href="`/guias/${ver.id}/xml`" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-3.5 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                            <FileCode2 class="size-4" />
                            XML
                        </a>
                        <a v-if="ver.tiene_cdr" :href="`/guias/${ver.id}/cdr`" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-3.5 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                            <ShieldCheck class="size-4" />
                            CDR
                        </a>
                        <button
                            v-if="sinResolver(ver) && puede('guias.gestionar') && envioSunat.activo"
                            class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-3.5 py-2 text-sm font-medium hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            :disabled="enviando === ver.id"
                            @click="enviarSunat(ver)"
                        >
                            <RefreshCw class="size-4" :class="enviando === ver.id ? 'animate-spin' : ''" />
                            {{ ver.estado_sunat === 'en_proceso' ? 'Consultar en SUNAT' : 'Enviar a SUNAT' }}
                        </button>
                        <a
                            :href="`/guias/${ver.id}/pdf`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                        >
                            <FileDown class="size-4" />
                            Ver PDF
                        </a>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal anulación -->
        <Teleport to="body">
            <div v-if="guiaAnular" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="guiaAnular = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="anular"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Anular guía {{ guiaAnular.numero }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" @click="guiaAnular = null">
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        La guía quedará anulada en el sistema. No afecta el stock.
                    </p>
                    <p
                        v-if="['aceptado', 'observado'].includes(guiaAnular.estado_sunat)"
                        class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                    >
                        SUNAT ya aceptó esta guía: también debes darla de baja en <strong>SUNAT Operaciones en Línea</strong> (el sistema no puede hacerlo por ti).
                    </p>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="motivo-guia">Motivo *</label>
                        <input
                            id="motivo-guia"
                            v-model="formAnular.motivo"
                            type="text"
                            required
                            maxlength="250"
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            placeholder="Ej. datos del transporte equivocados"
                            autofocus
                        />
                        <p v-if="formAnular.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formAnular.errors.motivo }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="guiaAnular = null">Cancelar</button>
                        <button
                            type="submit"
                            :disabled="formAnular.processing || !formAnular.motivo.trim()"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formAnular.processing ? 'Anulando...' : 'Anular guía' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
