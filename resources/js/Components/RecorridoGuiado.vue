<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { X } from '@lucide/vue'

// Recorrido guiado: oscurece la pantalla, deja a la vista un elemento y lo explica en un globo.
// Cada paso: { objetivo?: selector CSS, titulo, texto }. Sin objetivo, el globo va al centro.
const props = defineProps({
    abierto: { type: Boolean, default: false },
    pasos: { type: Array, required: true },
    // (paso) => Promise: deja la pantalla lista para el paso (p. ej. abrir el menú en el celular)
    preparar: { type: Function, default: null },
    textoFinal: { type: String, default: 'Empezar' },
})
// cerrar(true) = llegó al final · cerrar(false) = lo omitió
const emit = defineEmits(['cerrar'])

const MARGEN = 16 // separación mínima con el borde de la pantalla
const AIRE = 6 // holgura del recuadro alrededor del elemento
const SEPARACION = 14 // entre el recuadro y el globo

const lista = ref([])
const indice = ref(0)
const paso = computed(() => lista.value[indice.value] ?? null)
const esUltimo = computed(() => indice.value === lista.value.length - 1)

const globo = ref(null)
const botonSiguiente = ref(null)
const hueco = ref(null) // { top, left, width, height } del elemento resaltado; null = sin elemento
const lugar = ref({ top: 0, left: 0, lado: 'centro', flecha: 0 })
const listo = ref(false) // el globo ya está medido y colocado

const dosCuadros = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))

function objetivoDe(p) {
    return p?.objetivo ? document.querySelector(p.objetivo) : null
}

function limitar(valor, minimo, maximo) {
    return Math.max(minimo, Math.min(valor, Math.max(minimo, maximo)))
}

function colocar() {
    const el = objetivoDe(paso.value)
    const g = globo.value
    if (!g) return

    const vw = window.innerWidth
    const vh = window.innerHeight
    const ancho = g.offsetWidth
    const alto = g.offsetHeight

    if (!el) {
        hueco.value = null
        lugar.value = { top: Math.max(MARGEN, (vh - alto) / 2), left: Math.max(MARGEN, (vw - ancho) / 2), lado: 'centro', flecha: 0 }
        return
    }

    const r = el.getBoundingClientRect()
    // el recuadro nunca se sale de la pantalla (su borde verde debe verse completo)
    const BORDE = 4
    const top = Math.max(BORDE, r.top - AIRE)
    const left = Math.max(BORDE, r.left - AIRE)
    const h = {
        top,
        left,
        width: Math.min(vw - BORDE, r.right + AIRE) - left,
        height: Math.min(vh - BORDE, r.bottom + AIRE) - top,
    }
    hueco.value = h

    const centroX = h.left + h.width / 2
    const centroY = h.top + h.height / 2
    const derecha = h.left + h.width
    const abajo = h.top + h.height

    // se prueba en este orden: a la derecha, debajo, encima, a la izquierda
    let lado = 'abajo'
    if (derecha + SEPARACION + ancho <= vw - MARGEN) lado = 'derecha'
    else if (abajo + SEPARACION + alto <= vh - MARGEN) lado = 'abajo'
    else if (h.top - SEPARACION - alto >= MARGEN) lado = 'arriba'
    else if (h.left - SEPARACION - ancho >= MARGEN) lado = 'izquierda'

    if (lado === 'derecha' || lado === 'izquierda') {
        const top = limitar(centroY - alto / 2, MARGEN, vh - alto - MARGEN)
        lugar.value = {
            top,
            left: lado === 'derecha' ? derecha + SEPARACION : h.left - SEPARACION - ancho,
            lado,
            flecha: limitar(centroY - top, 20, alto - 20),
        }
    } else {
        const left = limitar(centroX - ancho / 2, MARGEN, vw - ancho - MARGEN)
        lugar.value = {
            top: lado === 'abajo' ? limitar(abajo + SEPARACION, MARGEN, vh - alto - MARGEN) : h.top - SEPARACION - alto,
            left,
            lado,
            flecha: limitar(centroX - left, 20, ancho - 20),
        }
    }
}

// si el elemento quedó escondido (menú lateral largo), se trae sin animación para medirlo bien;
// dentro del menú se centra, así no queda pegado al borde
function traerALaVista(el) {
    if (!el) return
    const lista = el.closest('nav')
    if (!lista) {
        el.scrollIntoView({ block: 'nearest', inline: 'nearest' })
        return
    }
    const a = el.getBoundingClientRect()
    const b = lista.getBoundingClientRect()
    if (a.top < b.top + 12 || a.bottom > b.bottom - 12) el.scrollIntoView({ block: 'center', inline: 'nearest' })
}

let turno = 0
async function mostrar(i) {
    const miTurno = ++turno
    listo.value = false
    indice.value = i
    if (props.preparar) await props.preparar(lista.value[i])
    if (miTurno !== turno || !props.abierto) return
    traerALaVista(objetivoDe(lista.value[i]))
    await nextTick()
    await dosCuadros()
    if (miTurno !== turno || !props.abierto) return
    colocar()
    listo.value = true
    await nextTick()
    botonSiguiente.value?.focus({ preventScroll: true })
}

function siguiente() {
    if (esUltimo.value) emit('cerrar', true)
    else mostrar(indice.value + 1)
}
function atras() {
    if (indice.value > 0) mostrar(indice.value - 1)
}
function omitir() {
    emit('cerrar', false)
}

function alTeclear(e) {
    if (e.key === 'Escape') omitir()
    else if (e.key === 'ArrowRight') siguiente()
    else if (e.key === 'ArrowLeft') atras()
    else return
    e.preventDefault()
}

let pendiente = 0
function recolocar() {
    cancelAnimationFrame(pendiente)
    pendiente = requestAnimationFrame(() => { if (props.abierto && listo.value) colocar() })
}

function escuchar(activar) {
    const accion = activar ? 'addEventListener' : 'removeEventListener'
    window[accion]('resize', recolocar)
    window[accion]('scroll', recolocar, true)
    window[accion]('keydown', alTeclear)
}

watch(() => props.abierto, (abierto) => {
    escuchar(abierto)
    if (!abierto) {
        turno++
        listo.value = false
        return
    }
    // solo los pasos cuyo elemento existe para este usuario (el menú cambia según sus permisos)
    lista.value = props.pasos.filter((p) => !p.objetivo || objetivoDe(p))
    if (!lista.value.length) {
        emit('cerrar', false)
        return
    }
    hueco.value = null
    mostrar(0)
}, { immediate: true })

onBeforeUnmount(() => escuchar(false))

const estiloHueco = computed(() => {
    const h = hueco.value
    if (!h) {
        // sin elemento: un punto en el centro, la sombra oscurece toda la pantalla
        return { top: '50%', left: '50%', width: '0px', height: '0px' }
    }
    return { top: `${h.top}px`, left: `${h.left}px`, width: `${h.width}px`, height: `${h.height}px` }
})

const estiloFlecha = computed(() => {
    const { lado, flecha } = lugar.value
    if (lado === 'derecha') return { left: '-6px', top: `${flecha - 6}px` }
    if (lado === 'izquierda') return { right: '-6px', top: `${flecha - 6}px` }
    if (lado === 'abajo') return { top: '-6px', left: `${flecha - 6}px` }
    return { bottom: '-6px', left: `${flecha - 6}px` }
})
</script>

<template>
    <Teleport to="body">
        <!-- la capa cubre todo: durante el recorrido solo se usan los botones del globo -->
        <div v-if="abierto && paso" class="fixed inset-0 z-[70]" role="dialog" aria-modal="true" aria-labelledby="recorrido-titulo">
            <div class="recorrido-hueco pointer-events-none fixed" :class="hueco ? 'recorrido-hueco--marcado rounded-xl' : ''" :style="estiloHueco" />

            <div
                ref="globo"
                class="fixed w-80 max-w-[calc(100vw-2rem)] rounded-2xl bg-white p-4 text-neutral-900 shadow-2xl shadow-black/30 transition-opacity duration-150 dark:bg-neutral-900 dark:text-neutral-100 dark:ring-1 dark:ring-neutral-700"
                :class="listo ? 'opacity-100' : 'opacity-0'"
                :style="{ top: `${lugar.top}px`, left: `${lugar.left}px` }"
            >
                <span v-if="lugar.lado !== 'centro'" class="absolute size-3 rotate-45 bg-white dark:bg-neutral-900" :style="estiloFlecha" aria-hidden="true" />

                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold tracking-wider text-emerald-600 uppercase dark:text-emerald-400">
                            Paso {{ indice + 1 }} de {{ lista.length }}
                        </p>
                        <h2 id="recorrido-titulo" class="mt-0.5 text-base leading-snug font-semibold tracking-tight">{{ paso.titulo }}</h2>
                    </div>
                    <button
                        type="button"
                        class="-mt-1 -mr-1 shrink-0 rounded-lg p-1.5 text-[#64748B] hover:bg-slate-100 hover:text-[#0F172A] dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        title="Cerrar el recorrido"
                        aria-label="Cerrar el recorrido"
                        @click="omitir"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <p class="mt-1.5 text-sm leading-relaxed text-[#475569] dark:text-neutral-300">{{ paso.texto }}</p>

                <!-- avance -->
                <div class="mt-3 flex gap-1" aria-hidden="true">
                    <span
                        v-for="(p, i) in lista"
                        :key="i"
                        class="h-1 flex-1 rounded-full transition-colors"
                        :class="i <= indice ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-slate-200 dark:bg-neutral-700'"
                    />
                </div>

                <div class="mt-3.5 flex items-center justify-end gap-2">
                    <button
                        v-if="!esUltimo"
                        type="button"
                        class="mr-auto text-sm font-medium text-[#64748B] underline-offset-2 hover:text-[#0F172A] hover:underline dark:text-neutral-400 dark:hover:text-neutral-100"
                        @click="omitir"
                    >
                        Omitir
                    </button>
                    <button
                        v-if="indice > 0"
                        type="button"
                        class="inline-flex h-9 items-center rounded-xl border border-[#E2E8F0] px-3.5 text-sm font-semibold text-[#475569] transition-colors hover:bg-slate-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        @click="atras"
                    >
                        Atrás
                    </button>
                    <button
                        ref="botonSiguiente"
                        type="button"
                        class="inline-flex h-9 items-center rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 focus-visible:outline-none dark:focus-visible:ring-offset-neutral-900"
                        @click="siguiente"
                    >
                        {{ esUltimo ? textoFinal : indice === 0 ? 'Empezar' : 'Siguiente' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* el "hueco": una caja transparente cuya sombra enorme oscurece el resto de la pantalla */
.recorrido-hueco {
    box-shadow: 0 0 0 200vmax rgb(15 23 42 / 0.62);
    transition:
        top 0.2s ease,
        left 0.2s ease,
        width 0.2s ease,
        height 0.2s ease;
}

.recorrido-hueco--marcado {
    box-shadow:
        0 0 0 3px rgb(16 185 129 / 0.95),
        0 0 0 200vmax rgb(15 23 42 / 0.62);
}
</style>
