<script setup>
// Captura de series / IMEI, una por unidad: pistola lectora (Enter agrega y el foco se queda), cámara del
// celular, o pegar una lista. v-model: [{ numero, alterna }] (alterna = segundo IMEI, opcional).
// Se guarda como en el servidor: en MAYÚSCULAS y sin espacios.
import { computed, nextTick, ref } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import { Camera, ClipboardPaste, ScanBarcode, X } from '@lucide/vue'
import EscanerCamara from '@/Components/EscanerCamara.vue'
import { puedeEscanear } from '@/composables/escaner'

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    // cuántas unidades se esperan (0 = sin tope)
    esperadas: { type: Number, default: 0 },
    // regularización: se pueden registrar MENOS de las esperadas ("hasta N"); nunca más
    opcional: { type: Boolean, default: false },
    // error de validación que devolvió el servidor sobre estas series (se muestra junto a la captura)
    error: { type: String, default: '' },
    // nombre del producto, para el título del escáner
    producto: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const MAX_LARGO = 60
// lo mismo que acepta el servidor: nada que rompa el comprobante electrónico
const PERMITIDO = /^[A-Za-z0-9\-/._]+$/
// en celular el campo es angosto: el texto de ayuda va más corto para que no se corte
const angosto = useMediaQuery('(max-width: 639px)')
const normalizar = (texto) => String(texto ?? '').replace(/\s+/g, '').toUpperCase()

const campo = ref(null)
const texto = ref('')
const aviso = ref('') // por qué no entró lo último (repetida, sobra, muy larga)
const pegando = ref(false)
const lista = ref('')
const conAlterna = ref(props.modelValue.some((s) => s.alterna))
const camaraAbierta = ref(false)

const total = computed(() => props.modelValue.length)
const faltan = computed(() => (props.esperadas > 0 ? props.esperadas - total.value : 0))
const completo = computed(() => props.esperadas > 0 && faltan.value === 0)

// todo lo ya usado en esta captura: series y segundos IMEI
const usados = () => new Set(props.modelValue.flatMap((s) => [s.numero, s.alterna].filter(Boolean)))

/** Agrega una o varias series (separadas por salto de línea, coma, punto y coma o tabulación). */
function agregar(entrada) {
    const numeros = String(entrada ?? '').split(/[\n\r,;\t]+/).map(normalizar).filter(Boolean)
    if (!numeros.length) return { ok: false, mensaje: 'Escribe o escanea una serie.' }

    const vistos = usados()
    const nuevas = []
    const repetidas = []
    let largas = 0
    let raras = 0
    let sobran = 0

    for (const numero of numeros) {
        if (numero.length > MAX_LARGO) largas++
        else if (!PERMITIDO.test(numero)) raras++
        else if (vistos.has(numero)) repetidas.push(numero)
        else if (props.esperadas > 0 && total.value + nuevas.length >= props.esperadas) sobran++
        else {
            vistos.add(numero)
            nuevas.push({ numero, alterna: '' })
        }
    }

    if (nuevas.length) emit('update:modelValue', [...props.modelValue, ...nuevas])

    const partes = []
    if (repetidas.length) partes.push(repetidas.length === 1 ? `La serie ${repetidas[0]} ya está en la lista.` : `${repetidas.length} series ya estaban en la lista (${repetidas.slice(0, 3).join(', ')}${repetidas.length > 3 ? '…' : ''}).`)
    if (sobran) {
        partes.push(props.opcional
            // regularización: el tope son las unidades que estaban sin número
            ? (props.esperadas === 1 ? 'Ya registraste la unidad que faltaba.' : 'Ya registraste todas las unidades que faltaban.')
            : `${props.esperadas === 1 ? 'Ya está la serie de la única unidad' : `Ya están las ${props.esperadas} series`}: ${sobran === 1 ? 'esa serie no entró' : `${sobran} series no entraron`}. Sube la cantidad si llegaron más unidades.`)
    }
    if (largas) partes.push(`Una serie no puede pasar de ${MAX_LARGO} caracteres.`)
    if (raras) partes.push('La serie solo puede tener letras, números, guion, punto, barra o guion bajo.')
    aviso.value = partes.join(' ')

    return nuevas.length
        ? { ok: true, mensaje: nuevas.length === 1 ? `Serie ${nuevas[0].numero}` : `${nuevas.length} series agregadas`, detalle: props.esperadas > 0 ? `${total.value + nuevas.length}/${props.esperadas}` : '' }
        : { ok: false, mensaje: aviso.value || 'No se agregó ninguna serie.' }
}

function agregarDelCampo() {
    // Enter con el campo vacío o solo con espacios no hace nada
    if (!normalizar(texto.value)) {
        texto.value = ''
        aviso.value = ''
        return
    }
    agregar(texto.value)
    texto.value = ''
    nextTick(() => campo.value?.focus())
}

// pegar varias líneas en el campo de escaneo las agrega todas
function alPegar(evento) {
    const pegado = evento.clipboardData?.getData('text') ?? ''
    if (!/[\n\r,;\t]/.test(pegado.trim())) return
    evento.preventDefault()
    agregar(pegado)
    texto.value = ''
}

function agregarLista() {
    if (lista.value.trim()) agregar(lista.value)
    lista.value = ''
    pegando.value = false
    nextTick(() => campo.value?.focus())
}

function quitar(indice) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== indice))
    aviso.value = ''
}

function quitarTodas() {
    emit('update:modelValue', [])
    aviso.value = ''
}

// segundo IMEI de una unidad: no puede repetir ninguna otra serie de la lista
function ponerAlterna(indice, valor) {
    const alterna = normalizar(valor).slice(0, MAX_LARGO)
    if (alterna && !PERMITIDO.test(alterna)) {
        aviso.value = 'La serie solo puede tener letras, números, guion, punto, barra o guion bajo.'
        emit('update:modelValue', props.modelValue.map((s, i) => (i === indice ? { ...s, alterna: '' } : s)))
        return
    }
    const choca = alterna && props.modelValue.some((s, i) => (i !== indice && (s.numero === alterna || s.alterna === alterna)) || (i === indice && s.numero === alterna))
    aviso.value = choca ? `El segundo IMEI ${alterna} ya está en la lista.` : ''
    emit('update:modelValue', props.modelValue.map((s, i) => (i === indice ? { ...s, alterna: choca ? '' : alterna } : s)))
}

function cambiarConAlterna() {
    conAlterna.value = !conAlterna.value
    if (!conAlterna.value && props.modelValue.some((s) => s.alterna)) {
        emit('update:modelValue', props.modelValue.map((s) => ({ ...s, alterna: '' })))
    }
}

defineExpose({ enfocar: () => campo.value?.focus() })

const claseBotonChico =
    'inline-flex h-10 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-stone-300 bg-white px-3 text-sm font-medium text-neutral-700 transition-colors hover:border-emerald-400 hover:bg-emerald-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-200 dark:hover:bg-emerald-950/40'
</script>

<template>
    <div class="min-w-0 rounded-xl bg-sky-50 p-2.5 dark:bg-sky-950/30" data-captura-series>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <span class="text-xs font-semibold text-sky-800 dark:text-sky-300">Series / IMEI *</span>
            <span
                class="rounded-lg px-2 py-0.5 text-xs font-semibold tabular-nums"
                :class="completo || (opcional && total > 0 && faltan >= 0)
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
                    : faltan < 0
                        ? 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-400'
                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-400'"
                :data-series-faltan="faltan"
                data-series-contador
            >
                <template v-if="opcional">{{ total }} de hasta {{ esperadas }}</template>
                <template v-else-if="esperadas > 0">
                    {{ total }} de {{ esperadas }}
                    <template v-if="faltan > 0"> · {{ faltan === 1 ? 'falta 1' : `faltan ${faltan}` }}</template>
                    <template v-else-if="faltan < 0"> · {{ faltan === -1 ? 'sobra 1' : `sobran ${-faltan}` }}</template>
                    <template v-else> · completo</template>
                </template>
                <template v-else>{{ total }} {{ total === 1 ? 'serie' : 'series' }}</template>
            </span>
        </div>

        <!-- escaneo continuo: Enter agrega y el foco se queda para la siguiente -->
        <div class="flex gap-2">
            <div class="relative min-w-0 flex-1">
                <ScanBarcode class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    ref="campo"
                    v-model="texto"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    :maxlength="MAX_LARGO + 20"
                    :disabled="completo"
                    :placeholder="completo ? 'Ya están todas' : (angosto ? 'Escanea o escribe' : 'Escanea o escribe la serie y pulsa Enter')"
                    aria-label="Serie o IMEI"
                    class="h-10 w-full rounded-xl border border-stone-300 bg-white pr-3 pl-9 font-mono text-sm placeholder:font-sans placeholder:text-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none disabled:bg-stone-50 disabled:placeholder:text-neutral-400 dark:border-neutral-700 dark:bg-neutral-950 dark:disabled:bg-neutral-900"
                    data-serie-entrada
                    @keydown.enter.prevent="agregarDelCampo"
                    @paste="alPegar"
                />
            </div>
            <button
                v-if="puedeEscanear"
                type="button"
                :class="[claseBotonChico, 'w-10 !px-0']"
                :disabled="completo"
                aria-label="Escanear series con la cámara"
                title="Escanear con la cámara"
                data-serie-camara
                @click="camaraAbierta = true"
            >
                <Camera class="size-4" />
            </button>
            <button type="button" :class="[claseBotonChico, 'w-10 !px-0 sm:w-auto sm:!px-3']" :disabled="completo" title="Pegar una lista de series" data-serie-pegar @click="pegando = !pegando">
                <ClipboardPaste class="size-4" />
                <span class="hidden sm:inline">Pegar lista</span>
            </button>
        </div>

        <div v-if="pegando" class="mt-2">
            <textarea
                v-model="lista"
                rows="3"
                placeholder="Pega aquí las series, una por línea (o separadas por coma)"
                class="scroll-fino w-full rounded-xl border border-stone-300 bg-white px-3 py-2 font-mono text-sm placeholder:font-sans placeholder:text-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                data-serie-lista
            />
            <div class="mt-1.5 flex justify-end gap-2">
                <button type="button" class="h-9 rounded-xl px-3 text-sm font-medium text-neutral-600 hover:bg-white dark:text-neutral-300 dark:hover:bg-neutral-900" @click="pegando = false">Cancelar</button>
                <button type="button" class="h-9 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700" data-serie-lista-agregar @click="agregarLista">Agregar</button>
            </div>
        </div>

        <p v-if="aviso" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400" data-serie-aviso>{{ aviso }}</p>
        <p v-if="error" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400" data-serie-error-servidor>{{ error }}</p>

        <!-- unidades capturadas -->
        <div v-if="total" class="mt-2">
            <div v-if="!conAlterna" class="scroll-fino flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                <span
                    v-for="(serie, i) in modelValue"
                    :key="serie.numero"
                    class="inline-flex max-w-full items-center gap-1 rounded-lg border border-sky-200 bg-white py-1 pr-1 pl-2.5 font-mono text-xs dark:border-sky-900 dark:bg-neutral-950"
                    data-serie-chip
                >
                    <span class="min-w-0 truncate">{{ serie.numero }}</span>
                    <button type="button" class="grid size-6 shrink-0 place-items-center rounded-md text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" :aria-label="`Quitar ${serie.numero}`" @click="quitar(i)">
                        <X class="size-3.5" />
                    </button>
                </span>
            </div>
            <div v-else class="scroll-fino max-h-56 space-y-1.5 overflow-y-auto">
                <div
                    v-for="(serie, i) in modelValue"
                    :key="serie.numero"
                    class="flex items-center gap-2 rounded-lg border border-sky-200 bg-white py-1 pr-1 pl-2.5 dark:border-sky-900 dark:bg-neutral-950"
                    data-serie-chip
                >
                    <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ serie.numero }}</span>
                    <input
                        :value="serie.alterna"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :maxlength="MAX_LARGO"
                        placeholder="2.º IMEI (opcional)"
                        :aria-label="`Segundo IMEI de ${serie.numero}`"
                        class="h-8 w-0 min-w-0 flex-1 rounded-lg border border-stone-200 bg-white px-2 font-mono text-xs placeholder:font-sans focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                        data-serie-alterna
                        @change="ponerAlterna(i, $event.target.value)"
                        @keydown.enter.prevent="$event.target.blur()"
                    />
                    <button type="button" class="grid size-7 shrink-0 place-items-center rounded-md text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" :aria-label="`Quitar ${serie.numero}`" @click="quitar(i)">
                        <X class="size-3.5" />
                    </button>
                </div>
            </div>

            <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2">
                <label class="flex cursor-pointer items-center gap-1.5 text-xs text-neutral-600 dark:text-neutral-300">
                    <input type="checkbox" class="size-3.5 rounded accent-emerald-600" :checked="conAlterna" data-serie-alterna-toggle @change="cambiarConAlterna" />
                    Equipos con 2 IMEI (doble SIM)
                </label>
                <button v-if="total > 1" type="button" class="text-xs font-medium text-neutral-500 hover:text-red-600 dark:text-neutral-400" @click="quitarTodas">Quitar todas</button>
            </div>
        </div>

        <EscanerCamara
            v-if="puedeEscanear"
            :abierto="camaraAbierta"
            :al-leer="agregar"
            :titulo="producto ? `Series de ${producto}` : 'Escanear series'"
            :resumen="esperadas > 0 ? `${total} de ${esperadas} series` : `${total} series`"
            @cerrar="camaraAbierta = false"
        />
    </div>
</template>
