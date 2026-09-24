<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { CheckCircle2, CreditCard, LifeBuoy, Mail, MessageCircle, Receipt, Store, TriangleAlert, Users } from '@lucide/vue'

const props = defineProps({
    suscripcion: { type: Object, required: true },
    uso: { type: Object, default: () => ({ usuarios: 0, sucursales: 0, comprobantes_mes: 0 }) },
    limites: { type: Object, default: null },
    planes: { type: Array, default: () => [] },
    historial: { type: Array, default: () => [] },
    contacto: { type: Object, default: () => ({ whatsapp: null, email: null }) },
    empresa: { type: Object, default: () => ({ ruc: '', razon_social: '' }) },
})

// 'YYYY-MM-DD' como fecha local (evita el desfase de un dia por UTC)
function aFecha(iso) {
    if (!iso) return null
    const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number)
    return new Date(a, m - 1, d)
}
function fechaLarga(iso) {
    const f = aFecha(iso)
    return f ? f.toLocaleDateString('es-PE', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'
}
function fechaCorta(iso) {
    const f = aFecha(iso)
    return f ? f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

// ---- estado ----
const ESTADOS = {
    activa: { texto: 'Activa', clase: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
    en_gracia: { texto: 'En periodo de gracia', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    vencida: { texto: 'Vencida', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' },
    sin_plan: { texto: 'Sin plan', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' },
}
const estado = computed(() => ESTADOS[props.suscripcion.estado] ?? ESTADOS.sin_plan)
const nombrePlan = computed(() => (props.suscripcion.es_prueba ? 'Prueba gratuita' : props.suscripcion.plan) || 'Sin plan')
const sinServicio = computed(() => ['vencida', 'sin_plan'].includes(props.suscripcion.estado))

const textoDias = computed(() => {
    const s = props.suscripcion
    const d = s.dias_restantes ?? 0
    if (s.estado === 'en_gracia') return 'Renueva pronto para no perder el acceso'
    if (!s.vigente) return null
    if (d <= 0) return 'Vence hoy'
    return d === 1 ? 'Queda 1 día' : `Quedan ${d} días`
})

// ---- uso vs limites ----
const filasUso = computed(() => [
    { clave: 'usuarios', label: 'Usuarios', icon: Users },
    { clave: 'sucursales', label: 'Sucursales', icon: Store },
    { clave: 'comprobantes_mes', label: 'Comprobantes este mes', icon: Receipt },
].map((f) => {
    const usado = props.uso?.[f.clave] ?? 0
    const limite = props.limites?.[f.clave] ?? null
    const pct = limite ? Math.min(100, Math.round((usado / limite) * 100)) : null
    return { ...f, usado, limite, pct }
}))

function claseBarra(pct) {
    if (pct >= 100) return 'bg-red-500'
    if (pct >= 80) return 'bg-amber-500'
    return 'bg-emerald-500'
}

// ---- planes ----
function precio(p) {
    const n = Number(p.precio_mensual ?? 0)
    return Number.isInteger(n) ? String(n) : n.toFixed(2)
}
function limitePlan(valor, singular, plural, ilimitado) {
    if (valor === null || valor === undefined) return ilimitado
    return `${valor} ${valor === 1 ? singular : plural}`
}
function limiteComprobantes(valor) {
    if (valor === null || valor === undefined) return 'Comprobantes ilimitados'
    return `${Number(valor).toLocaleString('es-PE')} comprobantes/mes`
}

const esActual = (p) => !!props.suscripcion.plan_codigo && p.codigo === props.suscripcion.plan_codigo && !props.suscripcion.es_prueba

function mensajePlan(p) {
    return `Hola, quiero activar el plan ${p.nombre} para ${props.empresa.razon_social} (RUC ${props.empresa.ruc})`
}

function enlacePlan(p) {
    const texto = mensajePlan(p)
    const whatsapp = String(props.contacto?.whatsapp ?? '').replace(/\D/g, '')
    if (whatsapp) return `https://wa.me/${whatsapp}?text=${encodeURIComponent(texto)}`
    if (props.contacto?.email) {
        const asunto = `Activar plan ${p.nombre} - ${props.empresa.razon_social}`
        return `mailto:${props.contacto.email}?subject=${encodeURIComponent(asunto)}&body=${encodeURIComponent(texto)}`
    }
    return null
}
const canal = computed(() => {
    if (String(props.contacto?.whatsapp ?? '').replace(/\D/g, '')) return 'whatsapp'
    if (props.contacto?.email) return 'email'
    return null
})

// ---- historial ----
const ESTADOS_HISTORIAL = {
    activa: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    en_gracia: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    vencida: 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    cancelada: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
}
const etiquetaEstado = (e) => (ESTADOS[e]?.texto ?? (e ? e.charAt(0).toUpperCase() + e.slice(1).replace('_', ' ') : '—'))
</script>

<template>
    <AppLayout titulo="Suscripción">
        <div class="mx-auto max-w-6xl space-y-6">
            <!-- Aviso de servicio suspendido -->
            <div v-if="sinServicio"
                class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                <TriangleAlert class="mt-0.5 size-5 shrink-0" />
                <div>
                    <p class="font-semibold">Tu suscripción venció. Activa un plan para seguir vendiendo.</p>
                    <p class="text-sm opacity-90">Elige un plan abajo y escríbenos; lo activamos el mismo día.</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-5">
                <!-- Estado -->
                <section
                    class="rounded-2xl border border-stone-200 bg-white p-5 lg:col-span-2 dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-start gap-3">
                        <div
                            class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                            <CreditCard class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                                Plan actual
                            </p>
                            <p class="truncate text-xl font-semibold tracking-tight">{{ nombrePlan }}</p>
                        </div>
                        <span class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold" :class="estado.clase">
                            {{ estado.texto }}
                        </span>
                    </div>

                    <dl class="mt-5 space-y-2.5 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">
                                {{ suscripcion.estado === 'activa' ? 'Vence el' : 'Venció el' }}
                            </dt>
                            <dd class="font-medium">{{ fechaLarga(suscripcion.fecha_fin) }}</dd>
                        </div>
                        <div v-if="textoDias" class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">Tiempo restante</dt>
                            <dd class="text-right font-medium"
                                :class="suscripcion.estado === 'en_gracia' || (suscripcion.dias_restantes ?? 0) <= 3 ? 'text-amber-600 dark:text-amber-400' : ''">
                                {{ textoDias }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">Empresa</dt>
                            <dd class="truncate text-right font-medium">{{ empresa.razon_social }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">RUC</dt>
                            <dd class="font-medium tabular-nums">{{ empresa.ruc }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Uso -->
                <section
                    class="rounded-2xl border border-stone-200 bg-white p-5 lg:col-span-3 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 class="font-semibold tracking-tight">Uso actual</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Lo que consumes frente a los límites de tu plan.</p>

                    <ul class="mt-5 space-y-4">
                        <li v-for="f in filasUso" :key="f.clave">
                            <div class="mb-1.5 flex items-center justify-between gap-3 text-sm">
                                <span class="flex items-center gap-2 font-medium">
                                    <component :is="f.icon" class="size-4 text-neutral-400" />
                                    {{ f.label }}
                                </span>
                                <span class="tabular-nums text-neutral-600 dark:text-neutral-300">
                                    <template v-if="f.limite !== null">
                                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ f.usado.toLocaleString('es-PE') }}</span>
                                        de {{ f.limite.toLocaleString('es-PE') }}
                                    </template>
                                    <template v-else>
                                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ f.usado.toLocaleString('es-PE') }}</span>
                                        (ilimitado)
                                    </template>
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div v-if="f.pct !== null" class="h-full rounded-full transition-all"
                                    :class="claseBarra(f.pct)" :style="{ width: `${Math.max(f.pct, f.usado ? 2 : 0)}%` }" />
                                <div v-else class="h-full w-full rounded-full bg-emerald-500/25" />
                            </div>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Planes -->
            <section>
                <div class="mb-3">
                    <h2 class="text-lg font-semibold tracking-tight">Planes</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Aún no tenemos pago en línea. Escríbenos, te indicamos cómo pagar y activamos tu plan el mismo día.
                    </p>
                </div>

                <div v-if="!planes.length"
                    class="rounded-2xl border border-stone-200 bg-white p-8 text-center text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400">
                    No hay planes disponibles por ahora.
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <article v-for="p in planes" :key="p.id"
                        class="relative flex flex-col rounded-2xl border bg-white p-5 dark:bg-neutral-900"
                        :class="esActual(p)
                            ? 'border-emerald-500 ring-1 ring-emerald-500 dark:border-emerald-500'
                            : 'border-stone-200 dark:border-neutral-800'">
                        <span v-if="esActual(p)"
                            class="absolute -top-2.5 left-5 rounded-lg bg-emerald-600 px-2 py-0.5 text-[11px] font-bold tracking-wide text-white uppercase">
                            Plan actual
                        </span>
                        <h3 class="text-lg font-semibold tracking-tight">{{ p.nombre }}</h3>
                        <p v-if="p.descripcion" class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ p.descripcion }}</p>

                        <p class="mt-4">
                            <span class="text-3xl font-bold tracking-tight">S/ {{ precio(p) }}</span>
                            <span class="text-sm text-neutral-500 dark:text-neutral-400"> / mes</span>
                        </p>

                        <ul class="mt-4 flex-1 space-y-2 text-sm">
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                {{ limitePlan(p.max_sucursales, 'sucursal', 'sucursales', 'Sucursales ilimitadas') }}
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                {{ limitePlan(p.max_usuarios, 'usuario', 'usuarios', 'Usuarios ilimitados') }}
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                {{ limiteComprobantes(p.max_comprobantes_mes) }}
                            </li>
                        </ul>

                        <a v-if="enlacePlan(p)" :href="enlacePlan(p)" target="_blank" rel="noopener"
                            class="mt-5 flex h-10 items-center justify-center gap-2 rounded-xl text-sm font-semibold transition-colors"
                            :class="esActual(p)
                                ? 'border border-emerald-600 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10'
                                : 'bg-emerald-600 text-white hover:bg-emerald-700'">
                            <MessageCircle v-if="canal === 'whatsapp'" class="size-4" />
                            <Mail v-else class="size-4" />
                            {{ esActual(p) ? 'Renovar este plan' : 'Quiero este plan' }}
                        </a>
                        <button v-else type="button" disabled
                            class="mt-5 flex h-10 cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-stone-100 text-sm font-semibold text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                            <LifeBuoy class="size-4" />
                            Contacta a soporte
                        </button>
                    </article>
                </div>

                <p v-if="canal" class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                    <template v-if="canal === 'whatsapp'">Te llevaremos a WhatsApp con el mensaje listo para enviar.</template>
                    <template v-else>Se abrirá tu correo con el mensaje listo para enviar a {{ contacto.email }}.</template>
                    <template v-if="canal === 'whatsapp' && contacto.email"> También puedes escribirnos a {{ contacto.email }}.</template>
                </p>
            </section>

            <!-- Historial -->
            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div class="border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <h2 class="font-semibold tracking-tight">Historial</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Plan</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider whitespace-nowrap">Periodo</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Estado</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Nota</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-if="!historial.length">
                                <td colspan="4" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400">
                                    Sin movimientos todavía.
                                </td>
                            </tr>
                            <tr v-for="h in historial" :key="h.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                                <td class="px-4 py-3 font-medium whitespace-nowrap">
                                    {{ h.plan?.nombre ?? '—' }}
                                    <span v-if="h.es_prueba"
                                        class="ml-1.5 rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                                        Prueba
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-neutral-600 tabular-nums dark:text-neutral-300">
                                    {{ fechaCorta(h.fecha_inicio) }} → {{ fechaCorta(h.fecha_fin) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="rounded-md px-2 py-0.5 text-xs font-semibold"
                                        :class="ESTADOS_HISTORIAL[h.estado] ?? 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'">
                                        {{ etiquetaEstado(h.estado) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ h.nota || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
