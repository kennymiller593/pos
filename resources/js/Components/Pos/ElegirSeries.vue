<script setup>
// Modal del POS para elegir QUÉ unidades se entregan de un producto que controla serie / IMEI.
// Trae las disponibles de la sucursal de la caja (/pos/series). Con la pistola lectora: se escanea el IMEI en
// el buscador y Enter lo marca. En el celular sube desde abajo, como una hoja.
import { computed, nextTick, onMounted, ref } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { Check, LoaderCircle, ScanBarcode, Search, X } from '@lucide/vue'

const props = defineProps({
    producto: { type: Object, required: true },
    // unidades ya elegidas en esa línea del carrito: [{ id, numero_serie, serie_alterna }]
    elegidas: { type: Array, default: () => [] },
    // ids que ya están en otra línea del carrito (no se ofrecen)
    excluidas: { type: Array, default: () => [] },
    // cuántas pide la línea (cotización o pedido); 0 = las que se elijan
    pedidas: { type: Number, default: 0 },
    // de dónde salen las unidades: por defecto las de la sucursal de la caja (/pos/series); una transferencia
    // pasa la URL de las disponibles en la sucursal de origen (debe responder { series: [...] })
    fuente: { type: String, default: '' },
    titulo: { type: String, default: 'Elige la unidad que entregas' },
    // tope de unidades que se pueden elegir (0 = sin tope): una línea de venta lleva como máximo 50
    maximo: { type: Number, default: 0 },
    textoQuitar: { type: String, default: 'Quitar del carrito' },
})
const emit = defineEmits(['confirmar', 'cerrar'])

const LIMITE = 500 // lo que devuelve el servidor de una vez; con más, se busca en el servidor
const normalizar = (texto) => String(texto ?? '').replace(/\s+/g, '').toUpperCase()

const campo = ref(null)
const texto = ref('')
const cargando = ref(true)
const error = ref('')
const disponibles = ref([])
const truncado = ref(false)
const seleccion = ref(new Map(props.elegidas.map((s) => [s.id, s])))
const yaNoEstan = ref(0)

async function cargar(buscar = '') {
    cargando.value = true
    error.value = ''
    try {
        const consulta = `producto_id=${props.producto.id}${buscar ? `&buscar=${encodeURIComponent(buscar)}` : ''}`
        // otra fuente (kardex de la sucursal de origen): la búsqueda en servidor va en ?buscar_serie=
        const url = props.fuente
            ? (buscar ? `${props.fuente}${props.fuente.includes('?') ? '&' : '?'}buscar_serie=${encodeURIComponent(buscar)}` : props.fuente)
            : `/pos/series?${consulta}`
        const r = await fetch(url, { headers: { Accept: 'application/json' } })
        if (!r.ok) throw new Error()
        const datos = await r.json()
        const lista = (datos.series ?? []).filter((s) => !props.excluidas.includes(s.id))
        disponibles.value = lista
        if (!buscar) {
            truncado.value = datos.series_truncadas ?? (datos.series ?? []).length >= LIMITE
            // una unidad elegida antes que ya se vendió en otra caja deja de estar elegida
            if (!truncado.value) {
                const vivas = new Set(lista.map((s) => s.id))
                const antes = seleccion.value.size
                seleccion.value = new Map([...seleccion.value].filter(([id]) => vivas.has(id)))
                yaNoEstan.value = antes - seleccion.value.size
            }
        }
        if (datos.aviso) error.value = datos.aviso
    } catch {
        error.value = 'No se pudieron cargar las unidades. Revisa tu conexión y vuelve a intentar.'
    } finally {
        cargando.value = false
    }
}

onMounted(() => {
    cargar()
    // en el celular no se enfoca: el teclado taparía la lista
    if (window.matchMedia?.('(pointer: fine)').matches) nextTick(() => campo.value?.focus())
})

// con muchísimas unidades la lista viene recortada: lo escrito se busca en el servidor
watchDebounced(texto, (valor) => truncado.value && cargar(normalizar(valor)), { debounce: 300 })

const filtradas = computed(() => {
    const buscado = normalizar(texto.value)
    return buscado
        ? disponibles.value.filter((s) => s.numero_serie.includes(buscado) || (s.serie_alterna ?? '').includes(buscado))
        : disponibles.value
})

const elegidasLista = computed(() => [...seleccion.value.values()])
const total = computed(() => seleccion.value.size)

function alternar(serie) {
    const nueva = new Map(seleccion.value)
    if (nueva.has(serie.id)) nueva.delete(serie.id)
    else if (props.maximo > 0 && nueva.size >= props.maximo) {
        error.value = `Máximo ${props.maximo} equipos con serie por línea; agrega otra línea o haz otra venta.`
        return
    } else nueva.set(serie.id, { id: serie.id, numero_serie: serie.numero_serie, serie_alterna: serie.serie_alterna })
    seleccion.value = nueva
}

// pistola lectora: el IMEI exacto + Enter marca esa unidad y deja el campo listo para la siguiente
function alPresionarEnter() {
    const buscado = normalizar(texto.value)
    if (!buscado) return
    const exacta = disponibles.value.find((s) => s.numero_serie === buscado || s.serie_alterna === buscado)
        ?? (filtradas.value.length === 1 ? filtradas.value[0] : null)
    if (!exacta) {
        error.value = `La serie ${buscado} no está disponible en esta sucursal para este producto.`
        return
    }
    error.value = ''
    if (!seleccion.value.has(exacta.id)) alternar(exacta)
    texto.value = ''
}

const confirmar = () => emit('confirmar', elegidasLista.value)
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-4" data-elegir-series @keydown.esc="emit('cerrar')">
            <div class="fixed inset-0 bg-neutral-950/60" @click="emit('cerrar')" />
            <div class="relative flex max-h-[88dvh] w-full flex-col overflow-hidden rounded-t-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl sm:max-w-md sm:rounded-2xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                <!-- cabecera -->
                <div class="flex items-start gap-3 border-b border-stone-200 px-4 py-3.5 sm:px-5 dark:border-neutral-800">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <ScanBarcode class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base leading-tight font-semibold tracking-tight">{{ titulo }}</h3>
                        <p class="truncate text-sm text-neutral-500 dark:text-neutral-400">{{ producto.nombre }}</p>
                    </div>
                    <button type="button" class="-mt-1 -mr-1.5 grid size-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200" aria-label="Cerrar" @click="emit('cerrar')">
                        <X class="size-5" />
                    </button>
                </div>

                <!-- buscador / escaneo -->
                <div class="px-4 pt-3 sm:px-5">
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            ref="campo"
                            v-model="texto"
                            type="text"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            placeholder="Escanea o busca la serie / IMEI"
                            aria-label="Buscar serie o IMEI"
                            class="h-11 w-full rounded-xl border border-stone-300 bg-white pr-3 pl-9 font-mono text-sm placeholder:font-sans placeholder:text-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950"
                            data-elegir-series-buscar
                            @keydown.enter.prevent="alPresionarEnter"
                        />
                    </div>
                    <p v-if="pedidas > 0" class="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400">Esta línea lleva {{ pedidas }} {{ pedidas === 1 ? 'unidad' : 'unidades' }}.</p>
                    <p v-if="yaNoEstan > 0" class="mt-1.5 text-xs font-medium text-amber-700 dark:text-amber-400" data-elegir-series-aviso>
                        {{ yaNoEstan === 1 ? 'Una unidad que habías elegido ya no está disponible' : `${yaNoEstan} unidades que habías elegido ya no están disponibles` }} (se vendió o se movió).
                    </p>
                    <p v-if="error" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400" data-elegir-series-error>{{ error }}</p>
                </div>

                <!-- unidades disponibles -->
                <div class="scroll-fino min-h-32 flex-1 overflow-y-auto px-4 py-3 sm:px-5">
                    <p v-if="cargando && !disponibles.length" class="flex items-center justify-center gap-2 py-8 text-sm text-neutral-500 dark:text-neutral-400">
                        <LoaderCircle class="size-4 animate-spin" /> Cargando unidades...
                    </p>
                    <p v-else-if="!disponibles.length && !texto" class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400" data-elegir-series-vacio>
                        No hay unidades con serie disponibles en esta sucursal.<br />Ingrésalas con una compra o un ajuste de stock.
                    </p>
                    <p v-else-if="!filtradas.length" class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">Ninguna unidad coincide con "{{ texto }}".</p>
                    <ul v-else class="grid gap-1.5 sm:grid-cols-2">
                        <li v-for="serie in filtradas" :key="serie.id">
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-2.5 rounded-xl border px-3 py-2 text-left transition-colors"
                                :class="seleccion.has(serie.id)
                                    ? 'border-emerald-500 bg-emerald-50 dark:border-emerald-600 dark:bg-emerald-950/30'
                                    : 'border-stone-200 hover:border-emerald-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                :aria-pressed="seleccion.has(serie.id)"
                                :data-serie-opcion="serie.numero_serie"
                                :data-elegida="seleccion.has(serie.id) || undefined"
                                @click="alternar(serie)"
                            >
                                <span
                                    class="grid size-5 shrink-0 place-items-center rounded-md border"
                                    :class="seleccion.has(serie.id) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-stone-300 dark:border-neutral-600'"
                                >
                                    <Check v-if="seleccion.has(serie.id)" class="size-3.5" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-mono text-sm font-medium">{{ serie.numero_serie }}</span>
                                    <span v-if="serie.serie_alterna" class="block truncate font-mono text-[11px] text-neutral-500 dark:text-neutral-400">2.º {{ serie.serie_alterna }}</span>
                                </span>
                            </button>
                        </li>
                    </ul>
                    <p v-if="truncado && !texto" class="mt-2 text-center text-xs text-neutral-400">Hay más unidades: escribe o escanea la serie para encontrarla.</p>
                </div>

                <!-- pie -->
                <div class="flex items-center gap-3 border-t border-stone-200 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5 dark:border-neutral-800">
                    <p class="min-w-0 flex-1 text-sm text-neutral-500 dark:text-neutral-400" data-elegir-series-contador>
                        <span class="font-semibold text-neutral-900 dark:text-neutral-100">{{ total }}</span>
                        {{ total === 1 ? 'unidad elegida' : 'unidades elegidas' }}
                    </p>
                    <button type="button" class="h-11 shrink-0 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="emit('cerrar')">Cancelar</button>
                    <button type="button" class="h-11 shrink-0 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700" data-elegir-series-confirmar @click="confirmar">
                        {{ total ? 'Listo' : textoQuitar }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
