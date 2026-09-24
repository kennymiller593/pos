<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { Ban, ChevronDown, CloudUpload, FileText, Printer, ReceiptText, Search, Undo2, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    comprobantes: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    esAdmin: { type: Boolean, default: false },
})

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const numero = (c) => `${c.serie}-${String(c.correlativo).padStart(6, '0')}`
const TIPOS = { '00': 'Nota de venta', '01': 'Factura', '03': 'Boleta', '07': 'Nota de crédito', '08': 'Nota de débito' }

function fechaHora(c) {
    const fecha = new Date(`${c.fecha_emision.slice(0, 10)}T${c.hora_emision}`)
    return fecha.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' +
        fecha.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')
const tipo = ref(props.filtros.tipo ?? '')
const estado = ref(props.filtros.estado ?? '')
const sunat = ref(props.filtros.sunat ?? '')

function aplicarFiltros() {
    router.get('/comprobantes', {
        buscar: buscar.value || undefined,
        tipo: tipo.value || undefined,
        estado: estado.value || undefined,
        sunat: sunat.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watchDebounced(buscar, aplicarFiltros, { debounce: 350 })
watch([tipo, estado, sunat], aplicarFiltros)

// ---- detalle expandible ----
const expandido = ref(null)

// ---- envio a SUNAT ----
const esElectronico = (c) => ['01', '03', '07'].includes(c.tipo_comprobante_codigo)
const SUNAT_BADGES = {
    aceptado: ['Aceptado', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'],
    observado: ['Observado', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
    rechazado: ['Rechazado', 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'],
    pendiente: ['Pendiente', 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'],
    baja_pendiente: ['Baja en proceso', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
    baja: ['Dada de baja', 'bg-stone-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200'],
}
const bajaPendiente = (c) => c.sunat?.estado === 'baja_pendiente'
const badgeSunat = (c) => SUNAT_BADGES[c.sunat?.estado ?? 'pendiente'] ?? SUNAT_BADGES.pendiente
const puedeReenviar = (c) =>
    esElectronico(c) && c.estado === 'emitido' && ['pendiente', 'rechazado', undefined].includes(c.sunat?.estado)

const enviandoSunat = ref(null)

function enviarSunat(c) {
    enviandoSunat.value = c.id
    router.post(`/comprobantes/${c.id}/sunat`, {}, {
        preserveScroll: true,
        onFinish: () => (enviandoSunat.value = null),
    })
}

// ---- nota de credito ----
const MOTIVOS_NC = [
    { codigo: '01', nombre: 'Anulación de la operación (total)' },
    { codigo: '06', nombre: 'Devolución total' },
    { codigo: '07', nombre: 'Devolución por ítem (parcial)' },
]
const nombreMotivo = (codigo) =>
    MOTIVOS_NC.find((m) => m.codigo === codigo?.trim())?.nombre ?? 'Nota de crédito'
const comprobanteNota = ref(null)
const formNota = useForm({ motivo: '06', items: [] })
const cantidadesNota = ref({}) // detalle_id -> cantidad a devolver

const puedeNotaCredito = (c) =>
    props.esAdmin && esElectronico(c) && c.estado === 'emitido' && ['aceptado', 'observado'].includes(c.sunat?.estado)

function abrirNotaCredito(c) {
    comprobanteNota.value = c
    formNota.clearErrors()
    formNota.motivo = '06'
    cantidadesNota.value = Object.fromEntries(c.detalles.map((d) => [d.id, '']))
}

const totalNota = computed(() => {
    const c = comprobanteNota.value
    if (!c) return 0
    if (formNota.motivo !== '07') return Number(c.total)
    return c.detalles.reduce((suma, d) => {
        const cant = Number(cantidadesNota.value[d.id])
        if (!(cant > 0)) return suma
        return suma + (Number(d.total) * Math.min(cant, Number(d.cantidad))) / Number(d.cantidad)
    }, 0)
})

function emitirNota() {
    formNota.items = formNota.motivo === '07'
        ? Object.entries(cantidadesNota.value)
            .filter(([, cant]) => Number(cant) > 0)
            .map(([detalle_id, cantidad]) => ({ detalle_id, cantidad: Number(cantidad) }))
        : []

    formNota.post(`/comprobantes/${comprobanteNota.value.id}/nota-credito`, {
        preserveScroll: true,
        onSuccess: () => (comprobanteNota.value = null),
    })
}

// ---- anulacion ----
const comprobanteAnular = ref(null)
const formAnular = useForm({ motivo: '' })

function abrirAnulacion(c) {
    comprobanteAnular.value = c
    formAnular.clearErrors()
    formAnular.motivo = ''
}

function anular() {
    formAnular.post(`/comprobantes/${comprobanteAnular.value.id}/anular`, {
        preserveScroll: true,
        onSuccess: () => (comprobanteAnular.value = null),
    })
}

const claseInput =
    'h-10 rounded-xl border border-stone-200 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500'
</script>

<template>
    <AppLayout titulo="Comprobantes">
        <!-- Filtros -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por número, cliente o documento..."
                    :class="[claseInput, 'w-full pl-10']"
                />
            </div>
            <select v-model="tipo" :class="claseInput">
                <option value="">Todos los tipos</option>
                <option value="00">Notas de venta</option>
                <option value="03">Boletas</option>
                <option value="01">Facturas</option>
            </select>
            <select v-model="estado" :class="claseInput">
                <option value="">Todos los estados</option>
                <option value="emitido">Emitidos</option>
                <option value="anulado">Anulados</option>
            </select>
            <select v-model="sunat" :class="claseInput">
                <option value="">SUNAT: todos</option>
                <option value="pendiente">Sin aceptar (pendientes y rechazados)</option>
                <option value="aceptado">Aceptados</option>
                <option value="observado">Observados</option>
                <option value="rechazado">Rechazados</option>
                <option value="baja_pendiente">Baja en proceso</option>
                <option value="baja">Dados de baja</option>
            </select>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="w-8 px-2 py-3.5" />
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Número</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Tipo</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Fecha</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Cliente</th>
                            <th class="px-3 py-3.5 text-right font-semibold tracking-wider">Total</th>
                            <th class="px-3 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-3 py-3.5 text-center font-semibold tracking-wider">SUNAT</th>
                            <th class="px-3 py-3.5 font-semibold tracking-wider">Vendedor</th>
                            <th class="px-3 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!comprobantes.data.length">
                            <td colspan="10" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <ReceiptText class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                No hay comprobantes que mostrar.
                            </td>
                        </tr>
                        <template v-for="c in comprobantes.data" :key="c.id">
                            <tr
                                class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                                @click="expandido = expandido === c.id ? null : c.id"
                            >
                                <td class="px-2 py-3 text-center">
                                    <ChevronDown
                                        class="size-4 text-neutral-400 transition-transform"
                                        :class="expandido === c.id ? 'rotate-180' : ''"
                                    />
                                </td>
                                <td class="px-3 py-3 font-mono text-xs font-semibold">{{ numero(c) }}</td>
                                <td class="px-3 py-3">
                                    {{ TIPOS[c.tipo_comprobante_codigo] ?? c.tipo_comprobante_codigo }}
                                    <span v-if="c.notas?.length" class="block text-xs text-amber-600 dark:text-amber-400">
                                        {{ c.notas.length }} nota{{ c.notas.length > 1 ? 's' : '' }} de crédito
                                    </span>
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ fechaHora(c) }}</td>
                                <td class="px-3 py-3 text-neutral-600 dark:text-neutral-300">{{ c.cliente_nombre ?? 'Público general' }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ soles(c.total) }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="c.estado === 'emitido'
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                            : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'"
                                    >
                                        {{ c.estado === 'emitido' ? 'Emitido' : 'Anulado' }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span
                                        v-if="esElectronico(c)"
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="badgeSunat(c)[1]"
                                        :title="c.sunat?.mensaje_sunat ?? ''"
                                    >
                                        {{ badgeSunat(c)[0] }}
                                    </span>
                                    <span v-else class="text-neutral-400 dark:text-neutral-600">—</span>
                                </td>
                                <td class="px-3 py-3 text-neutral-600 dark:text-neutral-300">{{ c.usuario?.nombre_completo ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <a
                                            :href="`/comprobantes/${c.id}/ticket`"
                                            target="_blank"
                                            rel="noopener"
                                            class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-emerald-600 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                            title="Imprimir ticket"
                                            @click.stop
                                        >
                                            <Printer class="size-4" />
                                        </a>
                                        <a
                                            :href="`/comprobantes/${c.id}/a4`"
                                            target="_blank"
                                            rel="noopener"
                                            class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-emerald-600 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                            title="PDF en A4"
                                            @click.stop
                                        >
                                            <FileText class="size-4" />
                                        </a>
                                        <button
                                            v-if="puedeReenviar(c) || bajaPendiente(c)"
                                            :disabled="enviandoSunat === c.id"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                                            :title="bajaPendiente(c) ? 'Consultar baja en SUNAT' : 'Enviar a SUNAT'"
                                            @click.stop="enviarSunat(c)"
                                        >
                                            <CloudUpload class="size-3.5" />
                                            {{ enviandoSunat === c.id ? 'Consultando...' : bajaPendiente(c) ? 'Consultar baja' : 'SUNAT' }}
                                        </button>
                                        <button
                                            v-if="puedeNotaCredito(c)"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/40"
                                            title="Emitir nota de crédito"
                                            @click.stop="abrirNotaCredito(c)"
                                        >
                                            <Undo2 class="size-3.5" />
                                            N. crédito
                                        </button>
                                        <button
                                            v-if="esAdmin && c.estado === 'emitido' && c.tipo_comprobante_codigo !== '07' && !bajaPendiente(c)"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                            @click.stop="abrirAnulacion(c)"
                                        >
                                            <Ban class="size-3.5" />
                                            Anular
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Detalle expandido -->
                            <tr v-if="expandido === c.id">
                                <td colspan="10" class="bg-stone-50 px-6 py-4 dark:bg-neutral-950/50">
                                    <div class="grid gap-4 lg:grid-cols-[1fr_260px]">
                                        <div>
                                            <p class="mb-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Productos</p>
                                            <div class="space-y-1.5">
                                                <div
                                                    v-for="d in c.detalles"
                                                    :key="d.id"
                                                    class="flex items-center justify-between gap-3 text-sm"
                                                >
                                                    <span>{{ Number(d.cantidad) }} × {{ d.descripcion }}</span>
                                                    <span class="font-medium whitespace-nowrap">{{ soles(d.total) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="mb-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Pagos</p>
                                            <div v-if="c.pagos.length" class="space-y-1.5">
                                                <div v-for="p in c.pagos" :key="p.id" class="flex items-center justify-between gap-3 text-sm">
                                                    <span>
                                                        {{ p.medio_pago?.nombre ?? p.medio_pago_codigo }}
                                                        <span v-if="p.referencia" class="text-xs text-neutral-500 dark:text-neutral-400">({{ p.referencia }})</span>
                                                    </span>
                                                    <span class="font-medium">{{ soles(p.monto) }}</span>
                                                </div>
                                            </div>
                                            <p v-else class="text-sm text-neutral-500 dark:text-neutral-400">Sin pagos (anulado)</p>

                                            <div v-if="c.estado === 'anulado'" class="mt-3 rounded-xl bg-red-100 px-3 py-2 text-xs text-red-800 dark:bg-red-500/15 dark:text-red-300">
                                                Anulado: {{ c.motivo_anulacion }}
                                            </div>

                                            <div
                                                v-if="esElectronico(c) && c.sunat?.mensaje_sunat"
                                                class="mt-3 rounded-xl bg-stone-100 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                            >
                                                <span class="font-semibold">SUNAT:</span> {{ c.sunat.mensaje_sunat }}
                                                <span v-if="c.sunat.intentos > 1" class="text-neutral-400">({{ c.sunat.intentos }} intentos)</span>
                                            </div>

                                            <div v-if="c.notas?.length" class="mt-3">
                                                <p class="mb-1.5 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Notas de crédito</p>
                                                <div
                                                    v-for="n in c.notas"
                                                    :key="n.id"
                                                    class="mb-1.5 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs dark:border-amber-500/20 dark:bg-amber-500/10"
                                                >
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-mono font-semibold">{{ numero(n) }}</span>
                                                        <span class="text-neutral-500 dark:text-neutral-400">{{ nombreMotivo(n.motivo_nota) }}</span>
                                                        <span
                                                            class="inline-flex rounded-full px-2 py-0.5 font-semibold"
                                                            :class="SUNAT_BADGES[n.sunat?.estado ?? 'pendiente']?.[1]"
                                                            :title="n.sunat?.mensaje_sunat ?? ''"
                                                        >
                                                            {{ SUNAT_BADGES[n.sunat?.estado ?? 'pendiente']?.[0] }}
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-semibold text-amber-700 dark:text-amber-400">-{{ soles(n.total) }}</span>
                                                        <button
                                                            v-if="['pendiente', 'rechazado', undefined].includes(n.sunat?.estado)"
                                                            :disabled="enviandoSunat === n.id"
                                                            class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-medium text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                                                            title="Reenviar la nota de crédito a SUNAT"
                                                            @click.stop="enviarSunat(n)"
                                                        >
                                                            <CloudUpload class="size-3.5" />
                                                            {{ enviandoSunat === n.id ? 'Enviando...' : 'SUNAT' }}
                                                        </button>
                                                        <a :href="`/comprobantes/${n.id}/ticket`" target="_blank" rel="noopener" title="Ticket" class="text-neutral-500 hover:text-emerald-600 dark:text-neutral-400 dark:hover:text-emerald-400">
                                                            <Printer class="size-3.5" />
                                                        </a>
                                                        <a :href="`/comprobantes/${n.id}/a4`" target="_blank" rel="noopener" title="PDF A4" class="text-neutral-500 hover:text-emerald-600 dark:text-neutral-400 dark:hover:text-emerald-400">
                                                            <FileText class="size-3.5" />
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>

                                            <div v-if="c.sunat?.xml_url || c.sunat?.cdr_url" class="mt-2 flex gap-2">
                                                <a
                                                    v-if="c.sunat?.xml_url"
                                                    :href="`/comprobantes/${c.id}/xml`"
                                                    class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs font-medium text-neutral-600 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                >
                                                    Descargar XML
                                                </a>
                                                <a
                                                    v-if="c.sunat?.cdr_url"
                                                    :href="`/comprobantes/${c.id}/cdr`"
                                                    class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs font-medium text-neutral-600 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                >
                                                    Descargar CDR
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="comprobantes.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ comprobantes.from }}–{{ comprobantes.to }} de {{ comprobantes.total }} comprobantes</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in comprobantes.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            preserve-state
                            class="rounded-lg border px-3.5 py-1.5"
                            :class="link.active
                                ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal nota de crédito -->
        <Teleport to="body">
            <div v-if="comprobanteNota" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="comprobanteNota = null" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="emitirNota"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Nota de crédito sobre {{ numero(comprobanteNota) }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="comprobanteNota = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <label class="mb-1 block text-sm font-medium" for="motivo-nc">Motivo *</label>
                    <select
                        id="motivo-nc"
                        v-model="formNota.motivo"
                        class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <option v-for="m in MOTIVOS_NC" :key="m.codigo" :value="m.codigo">{{ m.nombre }}</option>
                    </select>
                    <p v-if="formNota.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formNota.errors.motivo }}</p>

                    <!-- items para devolucion parcial -->
                    <div v-if="formNota.motivo === '07'" class="mt-4">
                        <p class="mb-2 text-sm font-medium">Productos a devolver</p>
                        <div class="max-h-48 space-y-2 overflow-y-auto pr-1">
                            <div
                                v-for="d in comprobanteNota.detalles"
                                :key="d.id"
                                class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 px-3 py-2 text-sm dark:border-neutral-800"
                            >
                                <div class="min-w-0">
                                    <p class="truncate">{{ d.descripcion }}</p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                        Vendido: {{ Number(d.cantidad) }} × S/ {{ Number(d.precio_unitario).toFixed(2) }}
                                    </p>
                                </div>
                                <input
                                    v-model="cantidadesNota[d.id]"
                                    type="number"
                                    min="0"
                                    :max="Number(d.cantidad)"
                                    step="any"
                                    placeholder="0"
                                    class="h-9 w-20 rounded-lg border border-stone-300 bg-white px-2 text-right text-sm focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                />
                            </div>
                        </div>
                        <p v-if="formNota.errors.items" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formNota.errors.items }}</p>
                    </div>

                    <div class="mt-4 flex items-center justify-between rounded-xl bg-stone-100 px-4 py-2.5 text-sm dark:bg-neutral-800">
                        <span class="text-neutral-500 dark:text-neutral-400">Total a acreditar</span>
                        <span class="font-semibold">{{ soles(totalNota) }}</span>
                    </div>

                    <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                        Se repone el stock de lo devuelto. Si la venta fue al contado, el dinero sale de tu caja abierta;
                        si fue al crédito, se descuenta de la deuda del cliente. La nota se envía a SUNAT automáticamente.
                    </p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="comprobanteNota = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formNota.processing || totalNota <= 0"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formNota.processing ? 'Emitiendo...' : 'Emitir nota de crédito' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal anulación -->
        <Teleport to="body">
            <div v-if="comprobanteAnular" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="comprobanteAnular = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="anular"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Anular {{ numero(comprobanteAnular) }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="comprobanteAnular = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Se repondrá el stock vendido y los pagos dejarán de contar en caja. Esta acción no se puede deshacer.
                    </p>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium" for="motivo">Motivo *</label>
                        <input
                            id="motivo"
                            v-model="formAnular.motivo"
                            type="text"
                            class="h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            placeholder="Ej. error de digitación"
                            autofocus
                        />
                        <p v-if="formAnular.errors.motivo" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formAnular.errors.motivo }}</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="comprobanteAnular = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formAnular.processing"
                            class="rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formAnular.processing ? 'Anulando...' : 'Anular comprobante' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
