<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Banknote, Calculator, Lock, X } from '@lucide/vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    abierto: { type: Boolean, default: false },
    apertura: { type: Object, required: true },
    denominaciones: { type: Array, default: () => [] },
})

const emit = defineEmits(['cerrar', 'cerrado'])

const page = usePage()
const { confirmar } = useConfirmar()

const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
const redondear = (n) => Math.round(Number(n) * 100) / 100
const vacio = (v) => v === '' || v === null || v === undefined

// ---- medios ----
const medios = computed(() => props.apertura.medios ?? [])
const medioEfectivo = computed(() => medios.value.find((m) => m.codigo === 'efectivo') ?? null)
const otrosMedios = computed(() => medios.value.filter((m) => m.codigo !== 'efectivo'))
const esperadoEfectivo = computed(() => Number(medioEfectivo.value?.esperado ?? props.apertura.resumen?.esperado ?? 0))

// ---- estado del formulario ----
const usarConteo = ref(false)
const efectivoContado = ref('')
const conteo = reactive({})
const declarados = reactive({})

function reiniciar() {
    usarConteo.value = false
    efectivoContado.value = ''
    for (const d of props.denominaciones) conteo[d] = ''
    for (const k of Object.keys(declarados)) delete declarados[k]
    for (const m of otrosMedios.value) declarados[m.codigo] = ''
    form.clearErrors()
}

const form = useForm({ monto_cierre: null, conteo: null, declarados: {} })

watch(() => props.abierto, (v) => { if (v) reiniciar() }, { immediate: true })

const subtotal = (d) => redondear(Number(d) * (Number(conteo[d]) || 0))
const totalConteo = computed(() => redondear(props.denominaciones.reduce((s, d) => s + subtotal(d), 0)))

const contado = computed(() => {
    if (usarConteo.value) return totalConteo.value
    return vacio(efectivoContado.value) ? null : redondear(efectivoContado.value)
})

const diferenciaEfectivo = computed(() => (contado.value === null ? null : redondear(contado.value - esperadoEfectivo.value)))

const diferenciaMedio = (m) => (vacio(declarados[m.codigo]) ? null : redondear(Number(declarados[m.codigo]) - Number(m.esperado)))

const diferenciaTotal = computed(() => {
    let total = diferenciaEfectivo.value ?? 0
    for (const m of otrosMedios.value) total += diferenciaMedio(m) ?? 0
    return redondear(total)
})

const hayDiferencias = computed(() =>
    (diferenciaEfectivo.value !== null && diferenciaEfectivo.value !== 0) ||
    otrosMedios.value.some((m) => { const d = diferenciaMedio(m); return d !== null && d !== 0 }))

function estado(dif) {
    if (dif === null) return null
    if (Math.abs(dif) < 0.005)
        return { texto: 'Cuadrado', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
    if (dif > 0)
        return { texto: `Sobra ${soles(dif)}`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: `Falta ${soles(-dif)}`, clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
}

const puedeEnviar = computed(() => contado.value !== null && !form.processing)

// errores (incluye claves anidadas como conteo.200 o declarados.yape)
const errores = computed(() => Object.values(form.errors ?? {}))

async function enviar() {
    if (!puedeEnviar.value) return

    if (hayDiferencias.value) {
        const ok = await confirmar({
            titulo: 'Cerrar caja',
            mensaje: 'Hay diferencias en el cuadre. ¿Cerrar igual?',
            textoConfirmar: 'Cerrar igual',
            peligro: true,
        })
        if (!ok) return
    } else {
        const ok = await confirmar({
            titulo: 'Cerrar caja',
            mensaje: 'Se cerrará el turno actual y no podrás registrar más ventas ni movimientos en él.',
            textoConfirmar: 'Cerrar caja',
            peligro: true,
        })
        if (!ok) return
    }

    let conteoEnvio = null
    if (usarConteo.value) {
        conteoEnvio = {}
        for (const d of props.denominaciones) {
            const c = parseInt(conteo[d], 10)
            if (c > 0) conteoEnvio[d] = c
        }
    }

    const declaradosEnvio = {}
    for (const m of otrosMedios.value) {
        if (!vacio(declarados[m.codigo])) declaradosEnvio[m.codigo] = redondear(declarados[m.codigo])
    }

    form
        .transform(() => ({
            monto_cierre: usarConteo.value ? null : contado.value,
            conteo: conteoEnvio,
            declarados: declaradosEnvio,
        }))
        .post('/caja/cerrar', {
            preserveScroll: true,
            onSuccess: () => {
                if (page.props.flash?.error) return
                emit('cerrado', page.props.flash?.ticket ?? null)
            },
        })
}

const claseInput =
    'h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:placeholder-neutral-500'
const claseSeccion = 'rounded-2xl border border-slate-200 p-4 dark:border-neutral-800'
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <form
                class="relative my-auto flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                @submit.prevent="enviar"
            >
                <!-- cabecera -->
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-neutral-800">
                    <div>
                        <h3 class="font-semibold tracking-tight">Cerrar caja · Arqueo</h3>
                        <p class="text-xs text-slate-500 dark:text-neutral-400">{{ apertura.caja }} · cuadre por medio de pago</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        @click="emit('cerrar')"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <!-- ===== Efectivo ===== -->
                    <section :class="claseSeccion">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <div class="grid size-8 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                                    <Banknote class="size-4" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold">Efectivo</p>
                                    <p class="text-xs text-slate-500 dark:text-neutral-400">Esperado {{ soles(esperadoEfectivo) }}</p>
                                </div>
                            </div>
                            <div class="inline-flex cursor-pointer items-center gap-2 text-sm select-none">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="usarConteo"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                                    :class="usarConteo ? 'bg-indigo-600 dark:bg-emerald-600' : 'bg-slate-300 dark:bg-neutral-700'"
                                    @click="usarConteo = !usarConteo"
                                >
                                    <span
                                        class="inline-block size-5 rounded-full bg-white shadow transition-transform"
                                        :class="usarConteo ? 'translate-x-5' : 'translate-x-0.5'"
                                    />
                                </button>
                                <span @click="usarConteo = !usarConteo">Contar por billetes y monedas</span>
                            </div>
                        </div>

                        <!-- input único -->
                        <div v-if="!usarConteo" class="mt-4">
                            <label class="mb-1 block text-sm font-medium" for="efectivo_contado">Efectivo contado *</label>
                            <input
                                id="efectivo_contado"
                                v-model="efectivoContado"
                                type="number"
                                step="0.01"
                                min="0"
                                inputmode="decimal"
                                :class="claseInput"
                                placeholder="0.00"
                                autofocus
                            />
                        </div>

                        <!-- conteo por denominación -->
                        <div v-else class="mt-4">
                            <div class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                <div v-for="d in denominaciones" :key="d" class="flex items-center gap-2">
                                    <span class="w-16 shrink-0 text-sm font-medium tabular-nums">S/ {{ d }}</span>
                                    <span class="text-xs text-slate-400">×</span>
                                    <input
                                        v-model="conteo[d]"
                                        type="number"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        class="h-9 w-20 rounded-xl border border-slate-200 bg-white px-2 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                                        placeholder="0"
                                    />
                                    <span class="ml-auto text-sm text-slate-500 tabular-nums dark:text-neutral-400">{{ soles(subtotal(d)) }}</span>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-neutral-950">
                                <span class="inline-flex items-center gap-1.5 text-slate-500 dark:text-neutral-400"><Calculator class="size-4" /> Total contado</span>
                                <span class="font-semibold tabular-nums">{{ soles(totalConteo) }}</span>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="text-slate-500 dark:text-neutral-400">
                                Esperado <span class="font-semibold text-slate-900 dark:text-neutral-100">{{ soles(esperadoEfectivo) }}</span>
                                <template v-if="contado !== null"> · Contado <span class="font-semibold text-slate-900 dark:text-neutral-100">{{ soles(contado) }}</span></template>
                            </span>
                            <span
                                v-if="estado(diferenciaEfectivo)"
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="estado(diferenciaEfectivo).clase"
                            >
                                {{ estado(diferenciaEfectivo).texto }}
                            </span>
                        </div>
                    </section>

                    <!-- ===== Otros medios ===== -->
                    <section v-if="otrosMedios.length" :class="claseSeccion">
                        <p class="text-sm font-semibold">Otros medios</p>
                        <p class="mb-3 text-xs text-slate-500 dark:text-neutral-400">
                            Revisa tu app de Yape / el cierre del POS de tarjeta. Si lo dejas vacío, se registra como no verificado.
                        </p>
                        <div class="divide-y divide-slate-100 dark:divide-neutral-800">
                            <div v-for="m in otrosMedios" :key="m.codigo" class="grid items-center gap-2 py-2.5 sm:grid-cols-[1fr_10rem_auto]">
                                <div>
                                    <p class="text-sm font-medium">{{ m.nombre }}</p>
                                    <p class="text-xs text-slate-500 dark:text-neutral-400">Esperado {{ soles(m.esperado) }}</p>
                                </div>
                                <label class="block">
                                    <span class="mb-0.5 block text-xs font-medium text-slate-500 dark:text-neutral-400">Declarado</span>
                                    <input
                                        v-model="declarados[m.codigo]"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        inputmode="decimal"
                                        :class="claseInput"
                                        placeholder="Igual al esperado"
                                    />
                                </label>
                                <span class="min-w-24 text-right sm:self-end sm:pb-2">
                                    <span
                                        v-if="estado(diferenciaMedio(m))"
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                        :class="estado(diferenciaMedio(m)).clase"
                                    >
                                        {{ estado(diferenciaMedio(m)).texto }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400 dark:text-neutral-500">Sin verificar</span>
                                </span>
                            </div>
                        </div>
                    </section>

                    <div v-if="errores.length" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400">
                        <p v-for="(e, i) in errores" :key="i">{{ e }}</p>
                    </div>
                </div>

                <!-- resumen final -->
                <div class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800">
                    <div class="text-sm">
                        <span class="text-slate-500 dark:text-neutral-400">Diferencia total</span>
                        <span
                            class="ml-2 font-bold tabular-nums"
                            :class="Math.abs(diferenciaTotal) < 0.005
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : diferenciaTotal > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'"
                        >
                            {{ diferenciaTotal > 0 ? '+' : diferenciaTotal < 0 ? '−' : '' }}{{ soles(Math.abs(diferenciaTotal)) }}
                        </span>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="emit('cerrar')"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="!puedeEnviar"
                            class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <Lock class="size-4" />
                            {{ form.processing ? 'Cerrando...' : 'Cerrar caja' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </Teleport>
</template>
