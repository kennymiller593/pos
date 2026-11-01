<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Calculator, Lock, X } from '@lucide/vue'
import { useConfirmar } from '@/composables/confirmar'

// Cierre sencillo, como el sistema anterior de los clientes: el resumen del turno ya viene calculado
// (ventas + ingresos − egresos = saldo, por cada medio) y cada campo sale prellenado con lo que el
// sistema espera. El cajero solo corrige lo que contó distinto; si no toca nada, la caja queda cuadrada.
const props = defineProps({
    abierto: { type: Boolean, default: false },
    apertura: { type: Object, required: true },
    denominaciones: { type: Array, default: () => [] },
})

const emit = defineEmits(['cerrar', 'cerrado'])

const page = usePage()
const { confirmar } = useConfirmar()

const redondear = (n) => Math.round(Number(n) * 100) / 100
const vacio = (v) => v === '' || v === null || v === undefined
const formato = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// negativos con el signo delante de la moneda: −S/ 1,359.18
const soles = (n) => {
    const v = Number(n ?? 0)
    return `${v < 0 ? '−' : ''}S/ ${formato.format(Math.abs(v))}`
}

// ---- medios ----
const medios = computed(() => props.apertura.medios ?? [])
const medioEfectivo = computed(() => medios.value.find((m) => m.codigo === 'efectivo') ?? null)
const otrosMedios = computed(() => medios.value.filter((m) => m.codigo !== 'efectivo'))
const esperadoEfectivo = computed(() => Number(medioEfectivo.value?.esperado ?? props.apertura.resumen?.esperado ?? 0))

// desglose del efectivo (lo que el viejo mostraba como Ingreso / Egreso / Saldo)
const inicial = computed(() => Number(medioEfectivo.value?.inicial ?? props.apertura.monto_inicial ?? 0))
const ventasEfectivo = computed(() => redondear(Number(medioEfectivo.value?.ventas ?? 0) + Number(medioEfectivo.value?.cobros ?? 0)))
const ingresosEfectivo = computed(() => Number(medioEfectivo.value?.ingresos ?? 0))
const egresosEfectivo = computed(() => redondear(Number(medioEfectivo.value?.egresos ?? 0) + Number(medioEfectivo.value?.pagos_proveedor ?? 0)))
const entradasMedio = (m) => redondear(Number(m.ventas ?? 0) + Number(m.cobros ?? 0) + Number(m.ingresos ?? 0))
const salidasMedio = (m) => redondear(Number(m.egresos ?? 0) + Number(m.pagos_proveedor ?? 0))

// todo lo que entró menos todo lo que salió, por cualquier medio (el "Saldo" del sistema anterior)
const totalTurno = computed(() => redondear(medios.value.reduce((s, m) => s + Number(m.esperado ?? 0), 0)))

// ---- estado del formulario ----
const usarConteo = ref(false)
const efectivoContado = ref('')
const conteo = reactive({})
const declarados = reactive({})
const observaciones = ref('')

function reiniciar() {
    usarConteo.value = false
    // prellenado con lo esperado: cerrar sin tocar nada deja la caja cuadrada
    efectivoContado.value = esperadoEfectivo.value.toFixed(2)
    for (const d of props.denominaciones) conteo[d] = ''
    for (const k of Object.keys(declarados)) delete declarados[k]
    for (const m of otrosMedios.value) declarados[m.codigo] = Number(m.esperado ?? 0).toFixed(2)
    observaciones.value = ''
    form.clearErrors()
}

const form = useForm({ monto_cierre: null, conteo: null, declarados: {}, observaciones: null })

watch(() => props.abierto, (v) => { if (v) reiniciar() }, { immediate: true })

const subtotal = (d) => redondear(Number(d) * (Number(conteo[d]) || 0))
const totalConteo = computed(() => redondear(props.denominaciones.reduce((s, d) => s + subtotal(d), 0)))

// null = lo dejó vacío: se cierra con lo esperado
const contado = computed(() => {
    if (usarConteo.value) return totalConteo.value
    return vacio(efectivoContado.value) ? null : redondear(efectivoContado.value)
})

const diferenciaEfectivo = computed(() => (contado.value === null ? 0 : redondear(contado.value - esperadoEfectivo.value)))

const diferenciaMedio = (m) => (vacio(declarados[m.codigo]) ? 0 : redondear(Number(declarados[m.codigo]) - Number(m.esperado)))

const diferenciaTotal = computed(() => {
    let total = diferenciaEfectivo.value
    for (const m of otrosMedios.value) total += diferenciaMedio(m)
    return redondear(total)
})

const hayDiferencias = computed(() => Math.abs(diferenciaTotal.value) >= 0.005 || otrosMedios.value.some((m) => Math.abs(diferenciaMedio(m)) >= 0.005) || Math.abs(diferenciaEfectivo.value) >= 0.005)

// solo se muestra cuando hay algo que decir: si cuadra, no estorba
function estado(dif) {
    if (Math.abs(dif) < 0.005) return null
    if (dif > 0)
        return { texto: `Sobra ${soles(dif)}`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: `Falta ${soles(-dif)}`, clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }
}

const claseMonto = (n) => (Number(n) < 0 ? 'text-red-600 dark:text-red-400' : '')

// errores (incluye claves anidadas como conteo.200 o declarados.yape)
const errores = computed(() => Object.values(form.errors ?? {}))

const seleccionar = (e) => e.target.select()

async function enviar() {
    if (form.processing) return

    const ok = await confirmar({
        titulo: 'Cerrar caja',
        mensaje: hayDiferencias.value
            ? 'Hay diferencias con lo que el sistema esperaba. ¿Cerrar igual?'
            : 'Se cerrará el turno actual y no podrás registrar más ventas ni movimientos en él.',
        textoConfirmar: hayDiferencias.value ? 'Cerrar igual' : 'Cerrar caja',
        peligro: true,
    })
    if (!ok) return

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
            // siempre va el contado (en modo conteo es el total de billetes, aunque sea 0): el backend prioriza el conteo si trae datos
            monto_cierre: contado.value,
            conteo: conteoEnvio,
            declarados: declaradosEnvio,
            observaciones: observaciones.value.trim() || null,
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
    'h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-right text-sm font-semibold text-slate-900 tabular-nums placeholder-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:placeholder-neutral-500'
const claseFila = 'flex items-center justify-between gap-3 py-1.5 text-sm'
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <form
                class="relative my-auto flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                data-modal-cierre
                @submit.prevent="enviar"
            >
                <!-- cabecera -->
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-neutral-800">
                    <div>
                        <h3 class="font-semibold tracking-tight">Cerrar caja</h3>
                        <p class="text-xs text-slate-500 dark:text-neutral-400">{{ apertura.caja }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        aria-label="Cerrar ventana"
                        @click="emit('cerrar')"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="scroll-fino flex-1 overflow-y-auto px-5 py-4">
                    <!-- ===== Efectivo: ventas + ingresos − egresos = saldo ===== -->
                    <section data-bloque-efectivo>
                        <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-neutral-400">Efectivo</p>
                        <div class="mt-1 divide-y divide-slate-100 dark:divide-neutral-800">
                            <div v-if="inicial > 0" :class="claseFila">
                                <span class="text-slate-600 dark:text-neutral-300">Monto inicial</span>
                                <span class="tabular-nums">{{ soles(inicial) }}</span>
                            </div>
                            <div :class="claseFila">
                                <span class="text-slate-600 dark:text-neutral-300">Ventas en efectivo</span>
                                <span class="tabular-nums">{{ soles(ventasEfectivo) }}</span>
                            </div>
                            <div :class="claseFila">
                                <span class="text-slate-600 dark:text-neutral-300">Ingresos</span>
                                <span class="tabular-nums text-emerald-600 dark:text-emerald-400">{{ ingresosEfectivo > 0 ? soles(ingresosEfectivo) : soles(0) }}</span>
                            </div>
                            <div :class="claseFila">
                                <span class="text-slate-600 dark:text-neutral-300">Egresos <span class="text-xs text-slate-400 dark:text-neutral-500">(compras, pagos, devoluciones)</span></span>
                                <span class="tabular-nums text-red-600 dark:text-red-400">{{ egresosEfectivo > 0 ? `− ${soles(egresosEfectivo)}` : soles(0) }}</span>
                            </div>
                            <div :class="claseFila" class="font-semibold">
                                <span>Saldo en caja</span>
                                <span class="tabular-nums" :class="claseMonto(esperadoEfectivo)" data-saldo-efectivo>{{ soles(esperadoEfectivo) }}</span>
                            </div>
                        </div>

                        <!-- contado: prellenado con el saldo -->
                        <div class="mt-3 grid grid-cols-[1fr_8.5rem] items-center gap-3 sm:grid-cols-[1fr_9rem]">
                            <div class="min-w-0">
                                <label class="block text-sm font-medium" for="efectivo_contado">Efectivo contado</label>
                                <span v-if="estado(diferenciaEfectivo)" class="mt-0.5 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="estado(diferenciaEfectivo).clase" data-estado-efectivo>
                                    {{ estado(diferenciaEfectivo).texto }}
                                </span>
                                <span v-else class="mt-0.5 block text-xs text-slate-400 dark:text-neutral-500">Cámbialo solo si contaste otra cosa</span>
                            </div>
                            <input
                                v-if="!usarConteo"
                                id="efectivo_contado"
                                v-model="efectivoContado"
                                type="number"
                                step="0.01"
                                :class="[claseInput, claseMonto(efectivoContado)]"
                                placeholder="0.00"
                                autofocus
                                @focus="seleccionar"
                            />
                            <span v-else class="h-10 rounded-xl bg-slate-50 px-3 text-right text-sm leading-10 font-semibold tabular-nums dark:bg-neutral-950">{{ soles(totalConteo) }}</span>
                        </div>

                        <button
                            type="button"
                            class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:underline dark:text-emerald-400"
                            @click="usarConteo = !usarConteo"
                        >
                            <Calculator class="size-3.5" />
                            {{ usarConteo ? 'Escribir el total' : 'Contar billetes y monedas' }}
                        </button>

                        <!-- conteo por denominación (ayuda opcional) -->
                        <div v-if="usarConteo" class="mt-2 grid gap-x-6 gap-y-1.5 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 dark:bg-neutral-950">
                            <div v-for="d in denominaciones" :key="d" class="flex items-center gap-2">
                                <span class="w-14 shrink-0 text-sm font-medium tabular-nums">S/ {{ d }}</span>
                                <span class="text-xs text-slate-400">×</span>
                                <input
                                    v-model="conteo[d]"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    class="h-9 w-16 rounded-xl border border-slate-200 bg-white px-2 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900"
                                    placeholder="0"
                                />
                                <span class="ml-auto text-sm text-slate-500 tabular-nums dark:text-neutral-400">{{ soles(subtotal(d)) }}</span>
                            </div>
                        </div>
                    </section>

                    <!-- ===== Otros medios: una línea por medio, prellenada ===== -->
                    <section v-if="otrosMedios.length" class="mt-5" data-bloque-medios>
                        <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-neutral-400">Otros medios</p>
                        <div class="mt-1 divide-y divide-slate-100 dark:divide-neutral-800">
                            <div v-for="m in otrosMedios" :key="m.codigo" class="grid grid-cols-[1fr_8.5rem] items-center gap-3 py-2 sm:grid-cols-[1fr_9rem]">
                                <div class="min-w-0">
                                    <label class="block text-sm font-medium" :for="`declarado_${m.codigo}`">{{ m.nombre }}</label>
                                    <p class="text-xs text-slate-500 dark:text-neutral-400">
                                        {{ Number(m.ingresos ?? 0) > 0 ? 'Entradas' : 'Ventas' }} {{ soles(entradasMedio(m)) }}<template v-if="salidasMedio(m) > 0"> · Salidas {{ soles(salidasMedio(m)) }}</template>
                                    </p>
                                    <span v-if="estado(diferenciaMedio(m))" class="mt-0.5 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="estado(diferenciaMedio(m)).clase">
                                        {{ estado(diferenciaMedio(m)).texto }}
                                    </span>
                                </div>
                                <input
                                    :id="`declarado_${m.codigo}`"
                                    v-model="declarados[m.codigo]"
                                    type="number"
                                    step="0.01"
                                    :class="[claseInput, claseMonto(declarados[m.codigo])]"
                                    placeholder="0.00"
                                    @focus="seleccionar"
                                />
                            </div>
                        </div>
                    </section>

                    <!-- ===== Total y observación ===== -->
                    <div class="mt-5 flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-neutral-950">
                        <span class="text-sm font-semibold">Total del turno</span>
                        <span class="text-lg font-bold tabular-nums" :class="claseMonto(totalTurno)" data-total-turno>{{ soles(totalTurno) }}</span>
                    </div>
                    <p v-if="hayDiferencias" class="mt-2 text-right text-sm" data-diferencia-total>
                        <span class="text-slate-500 dark:text-neutral-400">Diferencia</span>
                        <span class="ml-2 font-semibold tabular-nums" :class="diferenciaTotal > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'">
                            {{ diferenciaTotal > 0 ? '+' : '−' }}{{ soles(Math.abs(diferenciaTotal)) }}
                        </span>
                    </p>

                    <label class="mt-4 block">
                        <span class="mb-1 block text-sm font-medium">Observación <span class="font-normal text-slate-400 dark:text-neutral-500">(opcional)</span></span>
                        <input
                            v-model="observaciones"
                            type="text"
                            maxlength="500"
                            class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:placeholder-neutral-500"
                            placeholder="Ej.: faltó sencillo, se pagó al proveedor con plata del cajón"
                            data-observaciones
                        />
                    </label>

                    <div v-if="errores.length" class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400">
                        <p v-for="(e, i) in errores" :key="i">{{ e }}</p>
                    </div>
                </div>

                <!-- acciones -->
                <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4 dark:border-neutral-800">
                    <button
                        type="button"
                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                        @click="emit('cerrar')"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <Lock class="size-4" />
                        {{ form.processing ? 'Cerrando...' : 'Cerrar caja' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
