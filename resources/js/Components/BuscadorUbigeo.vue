<script setup>
import { onMounted, ref, watch } from 'vue'
import { onClickOutside, watchDebounced } from '@vueuse/core'
import { MapPin, X } from '@lucide/vue'

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Busca tu distrito...' },
})

const emit = defineEmits(['update:modelValue'])

const texto = ref('')
const etiquetaElegida = ref('')
const resultados = ref([])
const abierto = ref(false)
const contenedor = ref(null)

onClickOutside(contenedor, () => (abierto.value = false))

async function buscar(parametros) {
    try {
        const r = await fetch(`/consultas/ubigeos?${parametros}`, { headers: { Accept: 'application/json' } })
        return r.ok ? await r.json() : []
    } catch {
        return []
    }
}

// al montar (o si el formulario cambia el valor), resolver la etiqueta del codigo guardado
async function resolverEtiqueta() {
    if (!props.modelValue) {
        texto.value = ''
        etiquetaElegida.value = ''
        return
    }
    const [encontrado] = await buscar(`codigo=${encodeURIComponent(props.modelValue)}`)
    etiquetaElegida.value = encontrado?.etiqueta ?? props.modelValue
    texto.value = etiquetaElegida.value
}

onMounted(resolverEtiqueta)
watch(() => props.modelValue, (nuevo) => {
    // cambio externo (reset del formulario, apertura de otro registro)
    if ((nuevo || '') !== '' && etiquetaElegida.value && texto.value === etiquetaElegida.value) return
    resolverEtiqueta()
})

watchDebounced(texto, async (valor) => {
    if (valor === etiquetaElegida.value) return
    // el usuario esta escribiendo algo nuevo: el codigo anterior deja de valer
    if (props.modelValue) emit('update:modelValue', '')
    if (valor.trim().length < 2) {
        resultados.value = []
        abierto.value = false
        return
    }
    resultados.value = await buscar(`buscar=${encodeURIComponent(valor.trim())}`)
    abierto.value = true
}, { debounce: 300 })

function elegir(resultado) {
    etiquetaElegida.value = resultado.etiqueta
    texto.value = resultado.etiqueta
    abierto.value = false
    emit('update:modelValue', resultado.codigo)
}

function limpiar() {
    texto.value = ''
    etiquetaElegida.value = ''
    resultados.value = []
    abierto.value = false
    emit('update:modelValue', '')
}
</script>

<template>
    <div ref="contenedor" class="relative">
        <MapPin class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
        <input
            v-model="texto"
            type="text"
            :placeholder="placeholder"
            class="h-10 w-full rounded-xl border border-stone-300 bg-white pr-9 pl-9 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
            @focus="resultados.length && (abierto = true)"
        />
        <button
            v-if="texto"
            type="button"
            class="absolute top-1/2 right-2.5 -translate-y-1/2 rounded-md p-0.5 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300"
            title="Limpiar"
            @click="limpiar"
        >
            <X class="size-4" />
        </button>

        <div
            v-if="abierto"
            class="absolute z-20 mt-1.5 max-h-56 w-full overflow-y-auto rounded-xl border border-stone-200 bg-white py-1 shadow-lg shadow-neutral-900/5 dark:border-neutral-700 dark:bg-neutral-900 dark:shadow-black/30"
        >
            <p v-if="!resultados.length" class="px-3.5 py-2.5 text-sm text-neutral-400 dark:text-neutral-500">
                Sin coincidencias.
            </p>
            <button
                v-for="r in resultados"
                :key="r.codigo"
                type="button"
                class="flex w-full items-baseline justify-between gap-2 px-3.5 py-2 text-left text-sm hover:bg-stone-50 dark:hover:bg-neutral-800"
                @click="elegir(r)"
            >
                <span class="truncate">{{ r.etiqueta }}</span>
                <span class="shrink-0 font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ r.codigo }}</span>
            </button>
        </div>
    </div>
</template>
