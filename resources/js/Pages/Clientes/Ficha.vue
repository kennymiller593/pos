<script setup>
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import {
    ArrowLeft,
    ChevronDown,
    CircleCheck,
    HandCoins,
    Mail,
    MapPin,
    MessageCircle,
    Minus,
    Phone,
    Plus,
    Printer,
    ReceiptText,
    ShoppingBag,
    Star,
    X,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    cliente: { type: Object, required: true },
    resumen: { type: Object, required: true },
    compras: { type: Object, required: true },
    deudas: { type: Array, default: () => [] },
    cobros: { type: Array, default: () => [] },
    frecuentes: { type: Array, default: () => [] },
    // { reglas: { soles_por_punto, valor, minimo_canje } | null, saldo, movimientos } o null si no aplica
    puntos: { type: Object, default: null },
})

const { puede } = usePermisos()
const page = usePage()

const soles = (n) => `S/ ${Number(n ?? 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const cantidad = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })
// 'YYYY-MM-DD' o 'YYYY-MM-DD HH:MM:SS' como fecha local (sin correr un día por la zona horaria)
const aFecha = (texto) => new Date(String(texto).replace(' ', 'T').length <= 10 ? `${texto}T00:00:00` : String(texto).replace(' ', 'T'))
const fecha = (texto) => (texto ? aFecha(texto).toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—')
const fechaHora = (texto) => (texto ? aFecha(texto).toLocaleString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

function haceCuanto(iso) {
    if (!iso) return null
    const hoy = new Date()
    hoy.setHours(0, 0, 0, 0)
    const dias = Math.round((hoy - aFecha(iso)) / 86400000)
    if (dias <= 0) return 'Hoy'
    if (dias === 1) return 'Ayer'
    if (dias < 60) return `Hace ${dias} días`
    const meses = Math.round(dias / 30)
    return meses < 24 ? `Hace ${meses} meses` : `Hace ${Math.round(dias / 365)} años`
}

// ---- contacto por WhatsApp ----
const celular = computed(() => {
    let digitos = String(props.cliente.telefono ?? '').replace(/\D/g, '')
    if (digitos.length === 11 && digitos.startsWith('51')) digitos = digitos.slice(2)
    return /^9\d{8}$/.test(digitos) ? digitos : null
})
const negocio = computed(() => page.props.auth?.user?.empresa?.nombre_comercial || page.props.auth?.user?.empresa?.razon_social || '')
const whatsapp = (mensaje = '') => `https://wa.me/51${celular.value}${mensaje ? `?text=${encodeURIComponent(mensaje)}` : ''}`
const recordatorio = computed(() =>
    `Hola ${props.cliente.nombre}, te saluda ${negocio.value}. Te recordamos que tienes un saldo pendiente de ${soles(props.resumen.deuda)}. ¡Gracias!`)

// ---- historial: cada compra se abre para ver qué llevó ----
const abierta = ref(null)
const alternar = (id) => (abierta.value = abierta.value === id ? null : id)

const maxFrecuente = computed(() => Math.max(...props.frecuentes.map((f) => f.total), 1))

// ---- puntos ----
const valorPuntos = computed(() => (props.puntos?.reglas ? props.puntos.saldo * props.puntos.reglas.valor : null))

const modalPuntos = ref(false)
const sumar = ref(true)
const formPuntos = useForm({ puntos: 10, concepto: '' })

function abrirAjuste() {
    sumar.value = true
    formPuntos.reset()
    formPuntos.clearErrors()
    modalPuntos.value = true
}

function paso(delta) {
    formPuntos.puntos = Math.max(1, Math.round(Number(formPuntos.puntos || 0)) + delta)
}

function guardarAjuste() {
    formPuntos
        .transform((datos) => ({ ...datos, puntos: (sumar.value ? 1 : -1) * Math.abs(Math.round(Number(datos.puntos || 0))) }))
        .post(`/clientes/${props.cliente.id}/puntos`, {
            preserveScroll: true,
            onSuccess: () => {
                if (!page.props.flash?.error) modalPuntos.value = false
            },
        })
}

const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'
const claseEtiqueta = 'text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500'
const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
// stepper − / + (mismo estilo que cotizaciones y compras)
const claseGrupo = 'flex h-11 items-stretch overflow-hidden rounded-xl border border-stone-300 bg-white focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-400/30 dark:border-neutral-700 dark:bg-neutral-950'
const claseBotonPaso = 'grid w-11 shrink-0 place-items-center text-neutral-500 transition-colors hover:bg-stone-100 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100'
</script>

<template>
    <AppLayout :titulo="cliente.nombre">
        <Link href="/clientes" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
            <ArrowLeft class="size-4" />
            Clientes
        </Link>

        <!-- Quién es -->
        <div :class="[claseTarjeta, 'mb-4 flex flex-col gap-4 p-5 sm:flex-row sm:items-center']">
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <div class="grid size-14 shrink-0 place-items-center rounded-2xl bg-emerald-100 text-xl font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                    {{ cliente.nombre.charAt(0).toUpperCase() }}
                </div>
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold tracking-tight">{{ cliente.nombre }}</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        <template v-if="cliente.numero_documento">{{ cliente.tipo_documento }} {{ cliente.numero_documento }}</template>
                        <template v-else>Sin documento</template>
                        <template v-if="resumen.primera"> · Cliente desde {{ fecha(resumen.primera) }}</template>
                    </p>
                    <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-600 dark:text-neutral-300">
                        <span v-if="cliente.telefono" class="inline-flex items-center gap-1.5"><Phone class="size-3.5 text-neutral-400" />{{ cliente.telefono }}</span>
                        <span v-if="cliente.email" class="inline-flex min-w-0 items-center gap-1.5"><Mail class="size-3.5 shrink-0 text-neutral-400" /><span class="truncate">{{ cliente.email }}</span></span>
                        <span v-if="cliente.direccion" class="inline-flex min-w-0 items-center gap-1.5"><MapPin class="size-3.5 shrink-0 text-neutral-400" /><span class="truncate">{{ cliente.direccion }}</span></span>
                    </div>
                </div>
            </div>
            <a
                v-if="celular"
                :href="whatsapp()"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
            >
                <MessageCircle class="size-4 text-emerald-600 dark:text-emerald-400" />
                WhatsApp
            </a>
        </div>

        <!-- Resumen -->
        <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4" :class="puntos ? 'lg:grid-cols-5' : 'lg:grid-cols-4'">
            <div :class="[claseTarjeta, 'p-4']">
                <p :class="claseEtiqueta">Total comprado</p>
                <p class="mt-1.5 truncate text-lg font-bold tracking-tight sm:text-xl">{{ soles(resumen.total) }}</p>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ resumen.compras }} compra{{ resumen.compras === 1 ? '' : 's' }}</p>
            </div>
            <div :class="[claseTarjeta, 'p-4']">
                <p :class="claseEtiqueta">Ticket promedio</p>
                <p class="mt-1.5 truncate text-lg font-bold tracking-tight sm:text-xl">{{ soles(resumen.ticket_promedio) }}</p>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">por compra</p>
            </div>
            <div :class="[claseTarjeta, 'p-4']">
                <p :class="claseEtiqueta">Última compra</p>
                <p class="mt-1.5 truncate text-lg font-bold tracking-tight sm:text-xl">{{ haceCuanto(resumen.ultima) ?? 'Nunca' }}</p>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ resumen.ultima ? fecha(resumen.ultima) : 'Aún no compra' }}</p>
            </div>
            <div :class="[claseTarjeta, 'p-4', resumen.deuda > 0 ? 'border-amber-300 dark:border-amber-500/40' : '']">
                <p :class="claseEtiqueta">Deuda</p>
                <p class="mt-1.5 truncate text-lg font-bold tracking-tight sm:text-xl" :class="resumen.deuda > 0 ? 'text-amber-600 dark:text-amber-400' : ''">{{ soles(resumen.deuda) }}</p>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                    <template v-if="resumen.credito_disponible !== null">Le queda {{ soles(resumen.credito_disponible) }} de {{ soles(cliente.limite_credito) }}</template>
                    <template v-else>Sin línea de crédito</template>
                </p>
            </div>
            <div v-if="puntos" :class="[claseTarjeta, 'col-span-2 p-4 lg:col-span-1']">
                <p :class="claseEtiqueta">Puntos</p>
                <p class="mt-1.5 flex items-center gap-1.5 text-lg font-bold tracking-tight sm:text-xl">
                    <Star class="size-4.5 fill-amber-400 text-amber-400" />
                    {{ puntos.saldo.toLocaleString('es-PE') }}
                </p>
                <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ valorPuntos !== null ? `Valen ${soles(valorPuntos)} de descuento` : 'Programa apagado' }}
                </p>
            </div>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-3">
            <!-- Historial de compras -->
            <section :class="[claseTarjeta, 'overflow-hidden xl:col-span-2']" aria-label="Historial de compras">
                <div class="flex items-center gap-2 border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <ShoppingBag class="size-4.5 text-neutral-400" />
                    <h3 class="font-semibold tracking-tight">Historial de compras</h3>
                    <span class="ml-auto text-xs text-neutral-400 dark:text-neutral-500">{{ compras.total }} en total</span>
                </div>

                <p v-if="!compras.data.length" class="px-5 py-12 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    <ReceiptText class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                    Este cliente todavía no tiene compras.
                </p>

                <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                    <li v-for="c in compras.data" :key="c.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50"
                            :aria-expanded="abierta === c.id"
                            @click="alternar(c.id)"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm font-medium">
                                    <span :class="c.estado === 'anulado' ? 'text-neutral-400 line-through' : ''">{{ c.numero }}</span>
                                    <span class="text-xs font-normal text-neutral-500 dark:text-neutral-400">{{ c.tipo }}</span>
                                    <span v-if="c.estado === 'anulado'" class="rounded-md bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400">Anulado</span>
                                    <span v-else-if="c.saldo > 0" class="rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">Debe {{ soles(c.saldo) }}</span>
                                    <span v-else-if="c.es_credito" class="rounded-md bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">Crédito pagado</span>
                                </p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ fecha(c.fecha) }} · {{ c.hora }}
                                    <template v-if="c.modifica"> · modifica {{ c.modifica }}</template>
                                    · {{ c.items.length }} producto{{ c.items.length === 1 ? '' : 's' }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-sm font-semibold tabular-nums"
                                :class="c.estado === 'anulado' ? 'text-neutral-400 line-through' : c.es_nota_credito ? 'text-red-600 dark:text-red-400' : ''"
                            >
                                {{ c.es_nota_credito ? '−' : '' }}{{ soles(c.total) }}
                            </span>
                            <ChevronDown class="size-4 shrink-0 text-neutral-400 transition-transform" :class="abierta === c.id ? 'rotate-180' : ''" />
                        </button>

                        <!-- lo que llevó -->
                        <div v-if="abierta === c.id" class="border-t border-stone-100 bg-stone-50/70 px-5 py-3 dark:border-neutral-800 dark:bg-neutral-950/40">
                            <ul class="space-y-1.5">
                                <li v-for="(item, i) in c.items" :key="i" class="flex items-baseline gap-3 text-sm">
                                    <span class="w-14 shrink-0 text-right text-neutral-500 tabular-nums dark:text-neutral-400">{{ cantidad(item.cantidad) }} ×</span>
                                    <span class="min-w-0 flex-1">
                                        {{ item.descripcion }}
                                        <span class="text-xs text-neutral-400 dark:text-neutral-500">a {{ soles(item.precio_unitario) }}</span>
                                        <span v-if="item.descuento > 0" class="text-xs text-amber-600 dark:text-amber-400"> · desc. {{ soles(item.descuento) }}</span>
                                    </span>
                                    <span class="shrink-0 font-medium tabular-nums">{{ soles(item.total) }}</span>
                                </li>
                            </ul>
                            <a
                                v-if="puede('comprobantes.ver')"
                                :href="`/comprobantes/${c.id}/ticket`"
                                target="_blank"
                                rel="noopener"
                                class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                            >
                                <Printer class="size-4" />
                                Ver comprobante
                            </a>
                        </div>
                    </li>
                </ul>

                <!-- Paginación -->
                <div
                    v-if="compras.last_page > 1"
                    class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-5 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
                >
                    <span>{{ compras.from }}–{{ compras.to }} de {{ compras.total }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        <template v-for="(link, i) in compras.links" :key="i">
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
            </section>

            <div class="space-y-4">
                <!-- Deuda -->
                <section :class="[claseTarjeta, 'overflow-hidden']" aria-label="Deuda">
                    <div class="flex items-center gap-2 border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                        <HandCoins class="size-4.5 text-neutral-400" />
                        <h3 class="font-semibold tracking-tight">Deuda</h3>
                        <span v-if="resumen.deuda > 0" class="ml-auto text-sm font-bold text-amber-600 dark:text-amber-400">{{ soles(resumen.deuda) }}</span>
                    </div>

                    <p v-if="!deudas.length" class="flex items-center gap-2 px-5 py-5 text-sm text-neutral-500 dark:text-neutral-400">
                        <CircleCheck class="size-4.5 shrink-0 text-emerald-500" />
                        No debe nada.
                    </p>

                    <ul v-else class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <li v-for="d in deudas" :key="d.id" class="flex items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ d.comprobante }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ fecha(d.fecha) }} · {{ d.dias === 0 ? 'hoy' : `hace ${d.dias} día${d.dias === 1 ? '' : 's'}` }}
                                    <template v-if="d.pagado > 0"> · pagó {{ soles(d.pagado) }} de {{ soles(d.total) }}</template>
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums">{{ soles(d.saldo) }}</span>
                        </li>
                    </ul>

                    <div v-if="deudas.length" class="flex flex-wrap gap-2 border-t border-stone-200 px-5 py-3 dark:border-neutral-800">
                        <Link
                            v-if="puede('cuentas_cobrar.ver')"
                            :href="`/cuentas-por-cobrar?buscar=${encodeURIComponent(cliente.numero_documento || cliente.nombre)}`"
                            class="inline-flex h-9 items-center gap-2 rounded-xl bg-emerald-600 px-3.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                        >
                            <HandCoins class="size-4" />
                            Cobrar
                        </Link>
                        <a
                            v-if="celular"
                            :href="whatsapp(recordatorio)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-9 items-center gap-2 rounded-xl border border-stone-300 px-3.5 text-sm font-medium transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        >
                            <MessageCircle class="size-4 text-emerald-600 dark:text-emerald-400" />
                            Recordarle
                        </a>
                    </div>

                    <!-- últimos pagos -->
                    <div v-if="cobros.length" class="border-t border-stone-200 px-5 py-3 dark:border-neutral-800">
                        <p :class="[claseEtiqueta, 'mb-2']">Últimos pagos</p>
                        <ul class="space-y-1.5">
                            <li v-for="c in cobros" :key="c.id" class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate text-neutral-600 dark:text-neutral-300">
                                    {{ fecha(c.fecha) }} · {{ c.medio }}<template v-if="c.comprobante"> · {{ c.comprobante }}</template>
                                </span>
                                <span class="shrink-0 font-medium text-emerald-700 tabular-nums dark:text-emerald-400">{{ soles(c.monto) }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Puntos -->
                <section v-if="puntos" :class="[claseTarjeta, 'overflow-hidden']" aria-label="Puntos">
                    <div class="flex items-center gap-2 border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                        <Star class="size-4.5 text-neutral-400" />
                        <h3 class="font-semibold tracking-tight">Puntos</h3>
                        <button
                            v-if="puede('clientes.puntos')"
                            type="button"
                            class="ml-auto inline-flex h-8 items-center rounded-lg border border-stone-300 px-3 text-xs font-semibold transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="abrirAjuste"
                        >
                            Sumar o restar
                        </button>
                    </div>

                    <div class="px-5 py-4">
                        <p class="text-2xl font-bold tracking-tight">{{ puntos.saldo.toLocaleString('es-PE') }} <span class="text-sm font-medium text-neutral-500 dark:text-neutral-400">puntos</span></p>
                        <p v-if="puntos.reglas" class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                            Gana 1 punto por cada {{ soles(puntos.reglas.soles_por_punto) }} de compra. Cada punto vale {{ soles(puntos.reglas.valor) }}.
                            <template v-if="puntos.reglas.minimo_canje > 0"> Canjea desde {{ puntos.reglas.minimo_canje }} puntos.</template>
                        </p>
                        <p v-else class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">El programa está apagado: conserva sus puntos pero no gana ni canjea.</p>
                    </div>

                    <ul v-if="puntos.movimientos.length" class="divide-y divide-stone-100 border-t border-stone-200 dark:divide-neutral-800 dark:border-neutral-800">
                        <li v-for="m in puntos.movimientos" :key="m.id" class="flex items-center gap-3 px-5 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm" :title="m.concepto">{{ m.concepto }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ fechaHora(m.fecha) }} · {{ m.tipo }}</p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums" :class="m.puntos > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                {{ m.puntos > 0 ? '+' : '' }}{{ m.puntos }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Lo que más compra -->
                <section v-if="frecuentes.length" :class="[claseTarjeta, 'p-5']" aria-label="Lo que más compra">
                    <h3 class="font-semibold tracking-tight">Lo que más compra</h3>
                    <ul class="mt-3 space-y-3">
                        <li v-for="f in frecuentes" :key="f.nombre">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate font-medium" :title="f.nombre">{{ f.nombre }}</span>
                                <span class="shrink-0 font-semibold tabular-nums">{{ soles(f.total) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.max(4, (f.total / maxFrecuente) * 100)}%` }" />
                            </div>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ cantidad(f.cantidad) }} unid. en {{ f.veces }} compra{{ f.veces === 1 ? '' : 's' }} · última {{ fecha(f.ultima) }}
                            </p>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- Modal: sumar o restar puntos -->
        <Teleport to="body">
            <div v-if="modalPuntos" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalPuntos = false" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarAjuste"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Puntos de {{ cliente.nombre }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="modalPuntos = false">
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1 dark:bg-neutral-800" role="radiogroup" aria-label="Sumar o restar">
                        <button
                            v-for="opcion in [{ valor: true, label: 'Sumar' }, { valor: false, label: 'Restar' }]"
                            :key="opcion.label"
                            type="button"
                            role="radio"
                            :aria-checked="sumar === opcion.valor"
                            class="h-9 rounded-lg text-sm font-semibold transition-colors"
                            :class="sumar === opcion.valor ? 'bg-white shadow-sm dark:bg-neutral-950' : 'text-neutral-500 dark:text-neutral-400'"
                            @click="sumar = opcion.valor"
                        >
                            {{ opcion.label }}
                        </button>
                    </div>

                    <label class="mt-4 mb-1 block text-sm font-medium" for="ajuste_puntos">Puntos</label>
                    <div :class="claseGrupo">
                        <button type="button" :class="claseBotonPaso" aria-label="Menos" @click="paso(-1)"><Minus class="size-4" /></button>
                        <input id="ajuste_puntos" v-model="formPuntos.puntos" type="number" min="1" step="1" inputmode="numeric" class="w-full min-w-0 border-x border-stone-200 bg-transparent text-center text-base font-semibold focus:outline-none dark:border-neutral-700" />
                        <button type="button" :class="claseBotonPaso" aria-label="Más" @click="paso(1)"><Plus class="size-4" /></button>
                    </div>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Tiene {{ puntos?.saldo ?? 0 }} puntos.</p>
                    <p v-if="formPuntos.errors.puntos" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formPuntos.errors.puntos }}</p>

                    <label class="mt-4 mb-1 block text-sm font-medium" for="ajuste_motivo">Motivo</label>
                    <input id="ajuste_motivo" v-model="formPuntos.concepto" type="text" maxlength="150" :class="claseInput" :placeholder="sumar ? 'Regalo por su cumpleaños' : 'Canjeó un premio'" />
                    <p v-if="formPuntos.errors.concepto" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formPuntos.errors.concepto }}</p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="modalPuntos = false">Cancelar</button>
                        <button type="submit" :disabled="formPuntos.processing" class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ formPuntos.processing ? 'Guardando...' : sumar ? 'Sumar puntos' : 'Restar puntos' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
