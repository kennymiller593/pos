<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import {
    ArrowLeft,
    Boxes,
    CalendarClock,
    CalendarPlus,
    CreditCard,
    Package,
    Power,
    Receipt,
    ShoppingCart,
    Store,
    Users,
    UserRound,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    empresa: { type: Object, required: true },
    suscripcion: { type: Object, default: () => ({ estado: 'sin_plan' }) },
    historial: { type: Array, default: () => [] },
    usuarios: { type: Array, default: () => [] },
    planes: { type: Array, default: () => [] },
})

const { confirmar } = useConfirmar()

// ---- formato ----
// 'YYYY-MM-DD' como fecha local (evita el desfase de un dia por UTC)
function aFecha(iso) {
    if (!iso) return null
    const [a, m, d] = String(iso).slice(0, 10).split('-').map(Number)
    return new Date(a, m - 1, d)
}
function fechaCorta(iso) {
    const f = aFecha(iso)
    return f ? f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}
function fechaLarga(iso) {
    const f = aFecha(iso)
    return f ? f.toLocaleDateString('es-PE', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'
}
function fechaHora(iso) {
    if (!iso) return '—'
    const f = new Date(String(iso).replace(' ', 'T'))
    if (Number.isNaN(f.getTime())) return fechaCorta(iso)
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' +
        f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}
const numero = (n) => Number(n ?? 0).toLocaleString('es-PE')
function soles(n) {
    const v = Number(n ?? 0)
    return `S/ ${Number.isInteger(v) ? v : v.toFixed(2)}`
}

const AMBAR = 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
const VERDE = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
const ROJO = 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'
const GRIS = 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'

// ---- suscripcion ----
const ESTADOS = {
    activa: { texto: 'Activa', clase: VERDE },
    en_gracia: { texto: 'En periodo de gracia', clase: AMBAR },
    vencida: { texto: 'Vencida', clase: ROJO },
    sin_plan: { texto: 'Sin plan', clase: ROJO },
}
const estadoSus = computed(() => ESTADOS[props.suscripcion?.estado] ?? ESTADOS.sin_plan)
const nombrePlan = computed(() => {
    const s = props.suscripcion ?? {}
    if (s.estado === 'sin_plan') return 'Sin plan'
    return s.es_prueba ? 'Prueba gratuita' : (s.plan || 'Sin plan')
})
const badgePlan = computed(() => {
    const s = props.suscripcion ?? {}
    if (s.estado === 'sin_plan') return null
    if (s.es_prueba) return { texto: 'Prueba', clase: AMBAR }
    return { texto: s.plan ?? 'Plan', clase: ['vencida'].includes(s.estado) ? ROJO : VERDE }
})
const textoDias = computed(() => {
    const s = props.suscripcion ?? {}
    const d = s.dias_restantes
    if (d === null || d === undefined || !s.fecha_fin) return null
    if (d < 0) {
        const n = Math.abs(d)
        return `Venció hace ${n} día${n === 1 ? '' : 's'}`
    }
    if (d === 0) return 'Vence hoy'
    return d === 1 ? 'Queda 1 día' : `Quedan ${d} días`
})

const ESTADOS_HISTORIAL = {
    activa: VERDE,
    en_gracia: AMBAR,
    vencida: GRIS,
    cancelada: ROJO,
}
const etiquetaEstado = (e) => (e ? e.charAt(0).toUpperCase() + e.slice(1).replace('_', ' ') : '—')

// ---- acciones ----
const planInicial = (() => {
    const s = props.suscripcion ?? {}
    if (s.plan_codigo && !s.es_prueba && props.planes.some((p) => p.codigo === s.plan_codigo)) return s.plan_codigo
    return props.planes.find((p) => p.codigo !== 'prueba')?.codigo ?? props.planes[0]?.codigo ?? ''
})()

const formPlan = useForm({ plan: planInicial, meses: 1, nota: '' })
const formExtender = useForm({ dias: 7 })
const formActivo = useForm({})

const planElegido = computed(() => props.planes.find((p) => p.codigo === formPlan.plan) ?? null)
const totalPlan = computed(() => Number(planElegido.value?.precio_mensual ?? 0) * Number(formPlan.meses || 0))

function activarPlan() {
    formPlan
        .transform((d) => ({ ...d, nota: d.nota?.trim() || null }))
        .post(`/admin/empresas/${props.empresa.id}/plan`, {
            preserveScroll: true,
            onSuccess: () => formPlan.reset('nota'),
        })
}

function extender() {
    formExtender.post(`/admin/empresas/${props.empresa.id}/extender`, { preserveScroll: true })
}

async function alternarActivo() {
    const desactivar = props.empresa.activo
    const ok = await confirmar({
        titulo: desactivar ? 'Desactivar empresa' : 'Reactivar empresa',
        mensaje: desactivar
            ? `${props.empresa.razon_social} y sus usuarios no podrán ingresar al sistema hasta que la reactives.`
            : `${props.empresa.razon_social} volverá a tener acceso al sistema.`,
        textoConfirmar: desactivar ? 'Desactivar' : 'Reactivar',
        peligro: desactivar,
    })
    if (!ok) return
    formActivo.post(`/admin/empresas/${props.empresa.id}/activo`, { preserveScroll: true })
}

// ---- uso ----
const tarjetasUso = computed(() => [
    { label: 'Usuarios', valor: numero(props.empresa.usuarios), icon: Users },
    { label: 'Sucursales', valor: numero(props.empresa.sucursales), icon: Store },
    { label: 'Productos', valor: numero(props.empresa.productos), icon: Package },
    { label: 'Clientes', valor: numero(props.empresa.clientes), icon: UserRound },
    {
        label: 'Comprobantes',
        valor: numero(props.empresa.comprobantes_mes),
        extra: `este mes · ${numero(props.empresa.comprobantes_total)} en total`,
        icon: Receipt,
    },
    {
        label: 'Última venta',
        valor: props.empresa.ultima_venta ? fechaCorta(props.empresa.ultima_venta) : 'Sin ventas',
        icon: ShoppingCart,
    },
])

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900'
const claseBoton =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60'
</script>

<template>
    <AppLayout :titulo="empresa.razon_social">
        <div class="mx-auto max-w-6xl space-y-6">
            <!-- Cabecera -->
            <div>
                <Link
                    href="/admin/empresas"
                    class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100"
                >
                    <ArrowLeft class="size-4" />
                    Empresas
                </Link>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-2xl font-semibold tracking-tight">{{ empresa.razon_social }}</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">
                            <span class="tabular-nums">RUC {{ empresa.ruc }}</span>
                            <template v-if="empresa.nombre_comercial"> · {{ empresa.nombre_comercial }}</template>
                            <template v-if="empresa.rubro"> · {{ empresa.rubro }}</template>
                            <template v-if="empresa.regimen_tributario"> · {{ empresa.regimen_tributario }}</template>
                        </p>
                        <p class="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">Alta: {{ fechaLarga(empresa.creado_en) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="empresa.activo ? VERDE : GRIS">
                            {{ empresa.activo ? 'Activa' : 'Desactivada' }}
                        </span>
                        <span v-if="badgePlan" class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="badgePlan.clase">
                            {{ badgePlan.texto }}
                        </span>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="estadoSus.clase">
                            {{ estadoSus.texto }}
                        </span>
                        <span
                            v-if="empresa.facturacion_electronica"
                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="empresa.entorno_sunat === 'produccion' ? VERDE : AMBAR"
                        >
                            SUNAT {{ empresa.entorno_sunat === 'produccion' ? 'producción' : 'beta' }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Suscripción -->
                <section :class="claseTarjeta">
                    <div class="flex items-start gap-3">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                            <CreditCard class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">Suscripción</p>
                            <p class="truncate text-xl font-semibold tracking-tight">{{ nombrePlan }}</p>
                        </div>
                    </div>
                    <dl class="mt-5 space-y-2.5 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">Estado</dt>
                            <dd>
                                <span class="rounded-lg px-2 py-0.5 text-xs font-semibold" :class="estadoSus.clase">{{ estadoSus.texto }}</span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">
                                {{ (suscripcion?.dias_restantes ?? 0) < 0 ? 'Venció el' : 'Vence el' }}
                            </dt>
                            <dd class="font-medium">{{ fechaLarga(suscripcion?.fecha_fin) }}</dd>
                        </div>
                        <div v-if="textoDias" class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">Tiempo</dt>
                            <dd
                                class="font-medium"
                                :class="(suscripcion?.dias_restantes ?? 0) <= 3 ? 'text-amber-600 dark:text-amber-400' : ''"
                            >
                                {{ textoDias }}
                            </dd>
                        </div>
                        <div v-if="empresa.certificado_vence_en" class="flex justify-between gap-3">
                            <dt class="text-neutral-500 dark:text-neutral-400">Certificado vence</dt>
                            <dd class="font-medium">{{ fechaLarga(empresa.certificado_vence_en) }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Activar / renovar plan -->
                <form :class="claseTarjeta" @submit.prevent="activarPlan">
                    <h3 class="flex items-center gap-2 font-semibold tracking-tight">
                        <CalendarClock class="size-4 text-emerald-600 dark:text-emerald-400" />
                        Activar / renovar plan
                    </h3>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label :class="claseLabel" for="a_plan">Plan</label>
                            <select id="a_plan" v-model="formPlan.plan" :class="claseInput">
                                <option v-for="p in planes" :key="p.codigo" :value="p.codigo">
                                    {{ p.nombre }} — {{ soles(p.precio_mensual) }}/mes
                                </option>
                            </select>
                            <p v-if="formPlan.errors.plan" :class="claseError">{{ formPlan.errors.plan }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="a_meses">Meses</label>
                            <input id="a_meses" v-model.number="formPlan.meses" type="number" min="1" max="36" :class="claseInput" />
                            <p v-if="formPlan.errors.meses" :class="claseError">{{ formPlan.errors.meses }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="a_nota">Nota <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <input id="a_nota" v-model="formPlan.nota" type="text" maxlength="255" placeholder="Ej. Yape 24/09, op. 123456" :class="claseInput" />
                            <p v-if="formPlan.errors.nota" :class="claseError">{{ formPlan.errors.nota }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between gap-3">
                        <p v-if="planElegido" class="text-sm text-neutral-500 dark:text-neutral-400">
                            Total: <span class="font-semibold text-neutral-900 tabular-nums dark:text-neutral-100">{{ soles(totalPlan) }}</span>
                        </p>
                        <button type="submit" :disabled="formPlan.processing || !formPlan.plan" :class="[claseBoton, 'ml-auto']">
                            {{ formPlan.processing ? 'Guardando...' : 'Activar plan' }}
                        </button>
                    </div>
                </form>

                <div class="space-y-6">
                    <!-- Extender -->
                    <form :class="claseTarjeta" @submit.prevent="extender">
                        <h3 class="flex items-center gap-2 font-semibold tracking-tight">
                            <CalendarPlus class="size-4 text-emerald-600 dark:text-emerald-400" />
                            Extender vigencia
                        </h3>
                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Suma días a la suscripción actual.</p>
                        <div class="mt-3 flex items-start gap-2">
                            <div class="flex-1">
                                <label class="sr-only" for="a_dias">Días</label>
                                <input id="a_dias" v-model.number="formExtender.dias" type="number" min="1" max="90" :class="claseInput" />
                                <p v-if="formExtender.errors.dias" :class="claseError">{{ formExtender.errors.dias }}</p>
                            </div>
                            <button type="submit" :disabled="formExtender.processing" :class="claseBoton">
                                {{ formExtender.processing ? 'Extendiendo...' : 'Extender' }}
                            </button>
                        </div>
                    </form>

                    <!-- Activar / desactivar -->
                    <section :class="claseTarjeta">
                        <h3 class="flex items-center gap-2 font-semibold tracking-tight">
                            <Power class="size-4" :class="empresa.activo ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'" />
                            Acceso de la empresa
                        </h3>
                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {{ empresa.activo ? 'Desactivarla bloquea el ingreso de todos sus usuarios.' : 'La empresa está desactivada; sus usuarios no pueden ingresar.' }}
                        </p>
                        <button
                            type="button"
                            :disabled="formActivo.processing"
                            class="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl px-5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                            :class="empresa.activo
                                ? 'border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-950/40'
                                : 'bg-emerald-600 text-white hover:bg-emerald-700'"
                            @click="alternarActivo"
                        >
                            {{ formActivo.processing ? 'Procesando...' : (empresa.activo ? 'Desactivar empresa' : 'Reactivar') }}
                        </button>
                    </section>
                </div>
            </div>

            <!-- Uso -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <div v-for="t in tarjetasUso" :key="t.label" :class="claseTarjeta">
                    <div class="flex items-center gap-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                        <component :is="t.icon" class="size-4" />
                        {{ t.label }}
                    </div>
                    <p class="mt-2 text-xl font-semibold tracking-tight tabular-nums">{{ t.valor }}</p>
                    <p v-if="t.extra" class="text-xs text-neutral-500 dark:text-neutral-400">{{ t.extra }}</p>
                </div>
            </div>

            <!-- Historial -->
            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <h3 class="border-b border-stone-200 px-5 py-4 font-semibold tracking-tight dark:border-neutral-800">Historial de suscripciones</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Plan</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Desde</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Hasta</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Estado</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Nota</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Registrado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-if="!historial.length">
                                <td colspan="6" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400">
                                    <Boxes class="mx-auto mb-2 size-7 text-neutral-300 dark:text-neutral-600" />
                                    Sin suscripciones registradas.
                                </td>
                            </tr>
                            <tr v-for="h in historial" :key="h.id">
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ h.plan?.nombre ?? '—' }}</span>
                                    <span v-if="h.es_prueba" class="ml-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="AMBAR">Prueba</span>
                                    <p v-else-if="h.plan" class="text-xs text-neutral-500 dark:text-neutral-400">{{ soles(h.plan.precio_mensual) }}/mes</p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ fechaCorta(h.fecha_inicio) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ fechaCorta(h.fecha_fin) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS_HISTORIAL[h.estado] ?? GRIS">
                                        {{ etiquetaEstado(h.estado) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ h.nota || '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-neutral-500 dark:text-neutral-400">{{ fechaHora(h.creado_en) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Usuarios -->
            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <h3 class="border-b border-stone-200 px-5 py-4 font-semibold tracking-tight dark:border-neutral-800">
                    Usuarios <span class="font-normal text-neutral-400">({{ usuarios.length }})</span>
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Nombre</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Correo</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Rol</th>
                                <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                                <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Correo confirmado</th>
                                <th class="px-4 py-3.5 font-semibold tracking-wider">Alta</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-if="!usuarios.length">
                                <td colspan="6" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400">Sin usuarios.</td>
                            </tr>
                            <tr v-for="u in usuarios" :key="u.id">
                                <td class="px-4 py-3 font-medium">{{ u.nombre_completo }}</td>
                                <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ u.email }}</td>
                                <td class="px-4 py-3">{{ u.rol?.nombre ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="u.activo ? VERDE : ROJO">
                                        {{ u.activo ? 'Activo' : 'Desactivado' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="u.email_verificado_en ? VERDE : AMBAR">
                                        {{ u.email_verificado_en ? 'Sí' : 'No' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-neutral-500 dark:text-neutral-400">{{ fechaCorta(u.creado_en) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
