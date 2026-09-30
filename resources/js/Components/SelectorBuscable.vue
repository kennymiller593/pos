<script setup>
// Select con buscador: se escribe para filtrar (por código o nombre), flechas para moverse, Enter elige.
// opciones: [{ valor, texto, detalle? }]
import { computed, nextTick, ref, watch } from 'vue'
import { ChevronDown, Search } from '@lucide/vue'

const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    opciones: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Buscar...' },
    id: { type: String, default: undefined },
    claseInput: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const abierto = ref(false)
const texto = ref('')
const activa = ref(0)
const campo = ref(null)
const lista = ref(null)

const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const elegida = computed(() => props.opciones.find((o) => o.valor === props.modelValue) ?? null)

const filtradas = computed(() => {
    const q = normalizar(texto.value.trim())
    if (!q) return props.opciones
    const palabras = q.split(/\s+/)
    return props.opciones.filter((o) => {
        const pajar = normalizar(`${o.valor} ${o.texto} ${o.detalle ?? ''}`)
        return palabras.every((p) => pajar.includes(p))
    })
})

watch(filtradas, () => (activa.value = 0))

function abrir() {
    abierto.value = true
    texto.value = ''
    activa.value = Math.max(0, props.opciones.findIndex((o) => o.valor === props.modelValue))
    nextTick(() => desplazarAActiva())
}

function cerrar() {
    abierto.value = false
    texto.value = ''
}

function elegir(opcion) {
    if (!opcion) return
    emit('update:modelValue', opcion.valor)
    cerrar()
    campo.value?.blur()
}

function mover(paso) {
    if (!abierto.value) return abrir()
    const n = filtradas.value.length
    if (!n) return
    activa.value = (activa.value + paso + n) % n
    nextTick(desplazarAActiva)
}

function desplazarAActiva() {
    lista.value?.children?.[activa.value]?.scrollIntoView?.({ block: 'nearest' })
}

// al salir sin elegir, se vuelve a mostrar la opción actual
function alSalir() {
    setTimeout(cerrar, 120)
}
</script>

<template>
    <div class="relative">
        <div class="relative">
            <Search v-if="abierto" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
            <input
                :id="id"
                ref="campo"
                type="text"
                autocomplete="off"
                :value="abierto ? texto : (elegida?.texto ?? '')"
                :placeholder="abierto ? 'Escribe para buscar...' : placeholder"
                :class="[claseInput, 'cursor-pointer pr-9', abierto ? 'pl-9' : '']"
                role="combobox"
                :aria-expanded="abierto"
                @focus="abrir"
                @click="!abierto && abrir()"
                @input="texto = $event.target.value"
                @keydown.down.prevent="mover(1)"
                @keydown.up.prevent="mover(-1)"
                @keydown.enter.prevent="elegir(filtradas[activa])"
                @keydown.esc.prevent="cerrar(); campo?.blur()"
                @keydown.tab="cerrar()"
                @blur="alSalir"
            />
            <ChevronDown class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-neutral-400" />
        </div>

        <ul
            v-if="abierto"
            ref="lista"
            class="absolute right-0 left-0 z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border border-stone-200 bg-white py-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-800"
            role="listbox"
        >
            <li v-if="!filtradas.length" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">Sin resultados</li>
            <li
                v-for="(o, i) in filtradas"
                :key="o.valor"
                role="option"
                :aria-selected="o.valor === modelValue"
                class="flex cursor-pointer items-baseline gap-2 px-3 py-2 text-sm"
                :class="[
                    i === activa ? 'bg-emerald-50 dark:bg-emerald-950/40' : '',
                    o.valor === modelValue ? 'font-semibold text-emerald-700 dark:text-emerald-400' : '',
                ]"
                @mousedown.prevent="elegir(o)"
                @mousemove="activa = i"
            >
                <span class="min-w-0 truncate">{{ o.texto }}</span>
                <span v-if="o.detalle" class="ml-auto shrink-0 text-xs text-neutral-400">{{ o.detalle }}</span>
            </li>
        </ul>
    </div>
</template>
