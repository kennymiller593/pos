<script setup>
import { reactive, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { Ban, Copy, Eye, FileDown, FileText, Mail, MessageCircle, Pencil, Plus, Search, Send, ShoppingCart, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    cotizaciones: { type: Object, required: true },
    filtros: { type: Object, required: true },
    empresaNombre: { type: String, default: '' },
})

const { puede } = usePermisos()
const { confirmar } = useConfirmar()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const fecha = (f) => (f ? new Date(`${String(f).slice(0, 10)}T00:00:00`) : null)?.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const ESTADOS = {
    pendiente: { texto: 'Pendiente', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
    vencida: { texto: 'Vencida', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    convertida: { texto: 'Vendida', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    anulada: { texto: 'Anulada', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' },
}

// una cotización pendiente (o vencida) todavía se puede vender, editar y anular
const abierta = (c) => c.estado === 'pendiente' || c.estado === 'vencida'

// ---- filtros ----
const filtro = reactive({ ...props.filtros })

watchDebounced(filtro, () => {
    const parametros = Object.fromEntries(Object.entries(filtro).filter(([, v]) => v !== '' && v !== null))
    router.get('/cotizaciones', parametros, { preserveState: true, preserveScroll: true, replace: true })
}, { debounce: 350, deep: true })

const hayFiltros = () => Object.values(filtro).some((v) => v !== '' && v !== null)
function limpiarFiltros() {
    Object.assign(filtro, { buscar: '', estado: '', desde: '', hasta: '' })
}

// ---- detalle ----
const ver = ref(null)

// ---- anular ----
async function anular(c) {
    const ok = await confirmar({
        titulo: `Anular ${c.codigo}`,
        mensaje: 'La cotización quedará anulada y ya no se podrá vender ni editar. Si luego la necesitas, duplícala.',
        textoConfirmar: 'Anular',
        peligro: true,
    })
    if (!ok) return
    router.post(`/cotizaciones/${c.id}/anular`, {}, { preserveScroll: true, onSuccess: () => (ver.value = null) })
}

// ---- enviar al cliente ----
const enviar = ref(null)
const telefono = ref('')
const errorTelefono = ref('')
const formCorreo = useForm({ email: '', guardar_en_cliente: false })
const correoEnviado = ref(false)

function limpiarTelefono(t) {
    let d = String(t ?? '').replace(/\D/g, '')
    if (d.length === 11 && d.startsWith('51')) d = d.slice(2)
    return d
}

function abrirEnvio(c) {
    enviar.value = c
    telefono.value = limpiarTelefono(c.cliente_telefono)
    errorTelefono.value = ''
    formCorreo.clearErrors()
    formCorreo.email = c.cliente_email ?? ''
    formCorreo.guardar_en_cliente = false
    correoEnviado.value = false
}

function enviarWhatsapp() {
    errorTelefono.value = ''
    const numero = limpiarTelefono(telefono.value)
    if (!/^9\d{8}$/.test(numero)) {
        errorTelefono.value = 'Número de celular no válido'
        return
    }
    const c = enviar.value
    const texto = `Hola${c.cliente_nombre ? ` ${c.cliente_nombre}` : ''}, te enviamos la cotización ${c.codigo} de ${props.empresaNombre} por ${soles(c.total)}, válida hasta el ${fecha(c.valida_hasta)}: ${c.enlace_publico}`
    window.open(`https://wa.me/51${numero}?text=${encodeURIComponent(texto)}`, '_blank', 'noopener')
}

function enviarCorreo() {
    formCorreo.post(`/cotizaciones/${enviar.value.id}/correo`, {
        preserveScroll: true,
        onSuccess: () => (correoEnviado.value = true),
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseIcono = 'rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
</script>

<template>
    <AppLayout titulo="Cotizaciones">
        <!-- Filtros y acción principal -->
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="grid flex-1 gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_10rem_9.5rem_9.5rem_auto]">
                <div class="relative sm:col-span-2 lg:col-span-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="filtro.buscar" type="text" placeholder="Buscar por cliente, documento o número..." :class="[claseInput, 'pl-9']" />
                </div>
                <select v-model="filtro.estado" :class="claseInput" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <option value="pendiente">Pendientes</option>
                    <option value="vencida">Vencidas</option>
                    <option value="convertida">Vendidas</option>
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
                v-if="puede('cotizaciones.gestionar')"
                href="/cotizaciones/crear"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
            >
                <Plus class="size-4" />
                Nueva cotización
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Número</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Fecha</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Cliente</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Total</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Válida hasta</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Vendedor</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!cotizaciones.data.length">
                            <td colspan="8" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <FileText class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    <template v-if="hayFiltros()">No hay cotizaciones con esos filtros.</template>
                                    <template v-else>
                                        Aún no tienes cotizaciones.
                                        <Link v-if="puede('cotizaciones.gestionar')" href="/cotizaciones/crear" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                            Crea la primera
                                        </Link>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-for="c in cotizaciones.data"
                            :key="c.id"
                            class="cursor-pointer transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            :class="c.estado === 'anulada' ? 'opacity-60' : ''"
                            @click="ver = c"
                        >
                            <td class="px-4 py-3 font-mono text-xs font-semibold whitespace-nowrap">{{ c.codigo }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ fecha(c.fecha_emision) }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ c.cliente_nombre ?? 'Sin cliente' }}</p>
                                <p v-if="c.cliente_numero_doc" class="text-xs text-neutral-500 dark:text-neutral-400">{{ c.cliente_numero_doc }}</p>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold whitespace-nowrap tabular-nums">{{ soles(c.total) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ fecha(c.valida_hasta) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="ESTADOS[c.estado].clase">
                                    {{ ESTADOS[c.estado].texto }}
                                </span>
                                <p v-if="c.comprobante" class="mt-0.5 font-mono text-[11px] text-neutral-500 dark:text-neutral-400">{{ c.comprobante }}</p>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ c.vendedor ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-0.5">
                                    <Link
                                        v-if="abierta(c) && puede('pos.vender')"
                                        :href="`/pos?cotizacion=${c.id}`"
                                        class="mr-1 inline-flex h-8 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-semibold whitespace-nowrap text-white hover:bg-emerald-700"
                                        title="Cargarla en el POS para cobrarla"
                                        @click.stop
                                    >
                                        <ShoppingCart class="size-3.5" />
                                        Vender
                                    </Link>
                                    <button :class="claseIcono" title="Ver detalle" @click.stop="ver = c"><Eye class="size-4" /></button>
                                    <a :href="`/cotizaciones/${c.id}/pdf`" target="_blank" rel="noopener" :class="claseIcono" title="Ver PDF" @click.stop><FileDown class="size-4" /></a>
                                    <button v-if="c.estado !== 'anulada' && puede('cotizaciones.gestionar')" :class="claseIcono" title="Enviar al cliente" @click.stop="abrirEnvio(c)">
                                        <Send class="size-4" />
                                    </button>
                                    <Link v-if="abierta(c) && puede('cotizaciones.gestionar')" :href="`/cotizaciones/${c.id}/editar`" :class="claseIcono" title="Editar" @click.stop>
                                        <Pencil class="size-4" />
                                    </Link>
                                    <Link v-if="puede('cotizaciones.gestionar')" :href="`/cotizaciones/crear?duplicar=${c.id}`" :class="claseIcono" title="Duplicar" @click.stop>
                                        <Copy class="size-4" />
                                    </Link>
                                    <button
                                        v-if="abierta(c) && puede('cotizaciones.anular')"
                                        class="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        title="Anular"
                                        @click.stop="anular(c)"
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
                v-if="cotizaciones.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ cotizaciones.from }}–{{ cotizaciones.to }} de {{ cotizaciones.total }} cotizaciones</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in cotizaciones.links" :key="i">
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
                <div class="fixed inset-0 bg-neutral-950/60" @click="ver = null" />
                <div class="relative w-full max-w-xl rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <div class="min-w-0">
                            <h3 class="flex flex-wrap items-center gap-2 font-semibold tracking-tight">
                                Cotización {{ ver.codigo }}
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="ESTADOS[ver.estado].clase">{{ ESTADOS[ver.estado].texto }}</span>
                            </h3>
                            <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                {{ ver.cliente_nombre ?? 'Sin cliente' }} · {{ fecha(ver.fecha_emision) }} · válida hasta {{ fecha(ver.valida_hasta) }}
                            </p>
                        </div>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="ver = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="max-h-[55vh] overflow-y-auto px-6 py-4">
                        <dl class="mb-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div>
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Término de pago</dt>
                                <dd class="font-medium">{{ ver.es_credito ? 'Crédito' : 'Contado' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Tiempo de entrega</dt>
                                <dd class="font-medium">{{ ver.tiempo_entrega ?? '—' }}</dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Dirección de envío</dt>
                                <dd class="font-medium">{{ ver.direccion_envio ?? '—' }}</dd>
                            </div>
                            <div v-if="ver.observaciones" class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Observaciones</dt>
                                <dd class="whitespace-pre-line">{{ ver.observaciones }}</dd>
                            </div>
                            <div v-if="ver.comprobante" class="col-span-2">
                                <dt class="text-xs text-neutral-500 dark:text-neutral-400">Se vendió con</dt>
                                <dd class="font-mono font-medium">{{ ver.comprobante }}</dd>
                            </div>
                        </dl>

                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-neutral-400 uppercase dark:text-neutral-500">
                                <tr>
                                    <th class="pb-2 font-semibold tracking-wider">Producto</th>
                                    <th class="pb-2 text-center font-semibold tracking-wider">Cant.</th>
                                    <th class="pb-2 text-right font-semibold tracking-wider">P. unit.</th>
                                    <th class="pb-2 text-right font-semibold tracking-wider">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                <tr v-for="d in ver.detalles" :key="d.id">
                                    <td class="py-2.5 pr-2">
                                        {{ d.descripcion }}
                                        <span v-if="d.descuento > 0" class="block text-xs text-amber-600 dark:text-amber-400">Descuento −{{ soles(d.descuento) }}</span>
                                    </td>
                                    <td class="py-2.5 text-center whitespace-nowrap">{{ cantidad(d.cantidad) }} <span class="text-xs text-neutral-400">{{ d.unidad_codigo }}</span></td>
                                    <td class="py-2.5 text-right text-neutral-600 dark:text-neutral-300">{{ soles(d.precio_unitario) }}</td>
                                    <td class="py-2.5 text-right font-medium">{{ soles(d.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-stone-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800">
                        <div>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Total cotizado</p>
                            <p class="text-xl font-bold tracking-tight">{{ soles(ver.total) }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                :href="`/cotizaciones/${ver.id}/pdf`"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            >
                                <FileDown class="size-4" />
                                PDF
                            </a>
                            <button
                                v-if="ver.estado !== 'anulada' && puede('cotizaciones.gestionar')"
                                class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="abrirEnvio(ver)"
                            >
                                <Send class="size-4" />
                                Enviar
                            </button>
                            <Link
                                v-if="abierta(ver) && puede('pos.vender')"
                                :href="`/pos?cotizacion=${ver.id}`"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                            >
                                <ShoppingCart class="size-4" />
                                Convertir en venta
                            </Link>
                        </div>
                    </div>
                    <p v-if="ver.estado === 'vencida'" class="border-t border-stone-200 px-6 py-3 text-xs text-amber-700 dark:border-neutral-800 dark:text-amber-400">
                        Ya venció: si la vendes, el POS usará los precios de lista de hoy, no los cotizados.
                    </p>
                </div>
            </div>
        </Teleport>

        <!-- Modal enviar al cliente -->
        <Teleport to="body">
            <div v-if="enviar" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="enviar = null" />
                <div class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Enviar {{ enviar.codigo }}</h3>
                        <button
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="enviar = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <!-- WhatsApp -->
                    <label class="mb-1 block text-sm font-medium" for="cot-telefono">WhatsApp del cliente</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-neutral-400">+51</span>
                            <input
                                id="cot-telefono"
                                v-model="telefono"
                                type="tel"
                                inputmode="numeric"
                                maxlength="11"
                                placeholder="999 999 999"
                                :class="[claseInput, 'pl-11']"
                                @keydown.enter.prevent="enviarWhatsapp"
                            />
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700"
                            @click="enviarWhatsapp"
                        >
                            <MessageCircle class="size-4" />
                            Enviar
                        </button>
                    </div>
                    <p v-if="errorTelefono" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ errorTelefono }}</p>
                    <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Se abre WhatsApp con el mensaje y el enlace al PDF listos.</p>

                    <!-- Correo -->
                    <form class="mt-5 border-t border-stone-200 pt-4 dark:border-neutral-800" @submit.prevent="enviarCorreo">
                        <label class="mb-1 block text-sm font-medium" for="cot-email">Correo del cliente</label>
                        <div class="flex gap-2">
                            <input id="cot-email" v-model="formCorreo.email" type="email" placeholder="cliente@correo.com" :class="claseInput" />
                            <button
                                type="submit"
                                :disabled="formCorreo.processing || !formCorreo.email.trim()"
                                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            >
                                <Mail class="size-4" />
                                {{ formCorreo.processing ? 'Enviando...' : 'Enviar' }}
                            </button>
                        </div>
                        <p v-if="formCorreo.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formCorreo.errors.email }}</p>
                        <p v-else-if="correoEnviado" class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">Listo: la cotización va en camino con el PDF adjunto.</p>
                        <label v-if="enviar.cliente_nombre && !enviar.cliente_email" class="mt-2 flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                            <input v-model="formCorreo.guardar_en_cliente" type="checkbox" class="size-4 accent-emerald-600" />
                            Guardar este correo en la ficha del cliente
                        </label>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
