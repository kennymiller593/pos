<script setup>
// Rejilla talla × color de un modelo con variantes. Tres modos:
//  - "tocar": cada celda es un botón (POS: tocar agrega una unidad) con su stock y un contador de lo ya agregado;
//  - "cantidades": un stepper − / + por celda (compras y transferencias);
//  - "lectura": solo el número (stock), atenuado en 0 y resaltado bajo el mínimo.
// Con 1 característica es una lista de opciones; con 3, la tercera se elige arriba con pestañas.
import { computed, nextTick, ref, watch } from 'vue'
import { useResizeObserver } from '@vueuse/core'
import { Minus, Plus } from '@lucide/vue'
import { armarRejilla } from '@/utils/variantes'

const props = defineProps({
    // definición del modelo: [{ nombre: 'Talla', valores: ['S', 'M'] }, ...]
    atributos: { type: Array, required: true },
    // productos del modelo: [{ id, nombre, atributos: { Talla: 'M', Color: 'Rojo' }, ... }]
    variantes: { type: Array, required: true },
    modo: { type: String, default: 'tocar' },
    // modo "cantidades": { [id de la variante]: cantidad } (v-model:cantidades)
    cantidades: { type: Object, default: () => ({}) },
    // modo "tocar": { [id]: unidades ya agregadas } para el contador de la celda
    contadores: { type: Object, default: () => ({}) },
    // (variante) => número que se muestra como stock, o null para no mostrarlo
    stockDe: { type: Function, default: null },
    // (variante) => tope de la celda en modo "cantidades" (null = sin tope)
    maximoDe: { type: Function, default: null },
    // (variante) => true si la celda no se puede tocar ni llenar (agotada)
    bloqueada: { type: Function, default: null },
    // (variante) => texto bajo el número (p. ej. el precio cuando difiere entre variantes)
    notaDe: { type: Function, default: null },
    // modo "lectura": (variante) => 'agotado' | 'bajo' | 'ok'
    estadoDe: { type: Function, default: null },
    // modo "lectura": las celdas se pueden tocar (emite "tocar") y esta es la elegida
    tocable: { type: Boolean, default: false },
    elegida: { type: String, default: null },
    // alto máximo del cuerpo con scroll (clases de Tailwind)
    claseScroll: { type: String, default: 'max-h-[52dvh]' },
})
const emit = defineEmits(['tocar', 'update:cantidades'])

const rejilla = computed(() => armarRejilla(props.atributos, props.variantes))

// tercera característica: se elige primero
const pestana = ref(0)
const pestanaActiva = computed(() => Math.min(pestana.value, Math.max(0, rejilla.value.pestanas.length - 1)))
const valorPestana = computed(() => rejilla.value.pestanas[pestanaActiva.value] ?? null)

const cantidadDe = (variante) => Number((props.modo === 'cantidades' ? props.cantidades : props.contadores)?.[variante.id] ?? 0)

// cuánto lleva cada pestaña (para no perder de vista lo ya puesto en las otras)
const totalPestana = (valor) => {
    const r = rejilla.value
    let total = 0
    for (const fila of r.filas) {
        for (const columna of r.columnas) {
            const variante = r.variante(fila, columna, valor)
            if (variante) total += cantidadDe(variante)
        }
    }
    return total
}

const etiquetaDe = (variante) => props.atributos.map((a) => variante.atributos?.[a.nombre]).filter(Boolean).join(' / ') || variante.nombre

// todo en una sola cuadrícula: esquina, cabeceras de columna y, por fila, su cabecera y sus celdas
const piezas = computed(() => {
    const r = rejilla.value
    const lista = []
    if (r.unEje) {
        for (const columna of r.columnas) {
            const variante = r.variante(null, columna, null)
            if (variante) lista.push({ tipo: 'celda', clave: `c-${variante.id}`, variante, titulo: columna })
        }
    } else {
        lista.push({ tipo: 'esquina', clave: 'esquina' })
        for (const columna of r.columnas) lista.push({ tipo: 'columna', clave: `col-${columna}`, texto: columna })
        for (const fila of r.filas) {
            lista.push({ tipo: 'fila', clave: `fila-${fila}`, texto: fila })
            for (const columna of r.columnas) {
                const variante = r.variante(fila, columna, valorPestana.value)
                lista.push({ tipo: variante ? 'celda' : 'vacia', clave: `c-${fila}-${columna}`, variante })
            }
        }
    }
    // variantes que no calzan con la definición del modelo: a lo ancho, con su nombre
    for (const variante of r.sueltas) lista.push({ tipo: 'celda', clave: `s-${variante.id}`, variante, titulo: etiquetaDe(variante), ancha: !r.unEje })
    return lista
})

const anchoCelda = computed(() => (props.modo === 'cantidades' ? '6.75rem' : '3.75rem'))
const estiloCuadricula = computed(() => (rejilla.value.unEje
    ? { gridTemplateColumns: `repeat(auto-fill, minmax(${props.modo === 'cantidades' ? '6.75rem' : '4.75rem'}, 1fr))` }
    : { gridTemplateColumns: `max-content repeat(${rejilla.value.columnas.length}, minmax(${anchoCelda.value}, 1fr))` }))

// con muchas columnas (colores) la rejilla se desliza a los lados: la columna fija de filas lleva sombra mientras
// hay celdas pasando por debajo y un degradado en el borde derecho avisa que quedan columnas por ver
const cuerpo = ref(null)
const desplazada = ref(false)
const hayMasALaDerecha = ref(false)
function medirScroll() {
    const el = cuerpo.value
    if (!el) return
    desplazada.value = el.scrollLeft > 1
    hayMasALaDerecha.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 2
}
useResizeObserver(cuerpo, medirScroll)

// las cabeceras fijas se estiran justo lo que mide la separación entre celdas, para que nada asome por ella
// al deslizar (en "cantidades" la separación es menor para que entren 3 columnas en un celular)
const compacta = computed(() => props.modo === 'cantidades')
const claseSeparacion = computed(() => (compacta.value ? 'gap-1' : 'gap-1.5'))
const claseTapaDerecha = computed(() => (compacta.value ? '-mr-1 pr-3' : '-mr-1.5 pr-3.5'))
const claseTapaAbajo = computed(() => (compacta.value ? '-mb-1 pb-2' : '-mb-1.5 pb-2.5'))
const claseSombra = computed(() => (desplazada.value ? 'shadow-[6px_0_6px_-6px_rgb(0_0_0/0.18)] dark:shadow-[6px_0_6px_-6px_rgb(0_0_0/0.7)]' : ''))
watch([piezas, cuerpo], () => nextTick(medirScroll), { flush: 'post' })

const estaBloqueada = (variante) => !!props.bloqueada?.(variante)
const maximo = (variante) => {
    const tope = props.maximoDe?.(variante)
    return tope == null ? null : Math.max(0, Number(tope))
}

function poner(variante, valor) {
    let cantidad = Math.max(0, Math.floor(Number(valor) || 0))
    const tope = maximo(variante)
    if (tope != null) cantidad = Math.min(cantidad, Math.floor(tope))
    const nuevas = { ...props.cantidades }
    if (cantidad > 0) nuevas[variante.id] = cantidad
    else delete nuevas[variante.id]
    emit('update:cantidades', nuevas)
}

const enTope = (variante) => maximo(variante) != null && cantidadDe(variante) >= Math.floor(maximo(variante))
const numero = (n) => Number(n ?? 0).toLocaleString('es-PE', { maximumFractionDigits: 3 })

const claseLectura = (variante) => ({
    agotado: 'border-stone-200 bg-stone-50 text-neutral-300 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-600',
    bajo: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700/60 dark:bg-amber-500/10 dark:text-amber-300',
    ok: 'border-stone-200 bg-white text-neutral-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100',
}[props.estadoDe?.(variante) ?? 'ok'])
</script>

<template>
    <div class="min-w-0" data-rejilla-variantes :data-rejilla-modo="modo">
        <!-- tercera característica (p. ej. Material): pestañas -->
        <div v-if="rejilla.pestanas.length" class="mb-2.5 flex flex-wrap items-center gap-1.5" data-pestanas-variante>
            <span class="mr-0.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ rejilla.nombrePestanas }}</span>
            <button
                v-for="(valor, i) in rejilla.pestanas"
                :key="valor"
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-xl border px-3 text-sm font-medium transition-colors"
                :class="i === pestanaActiva
                    ? 'border-emerald-600 bg-emerald-600 text-white'
                    : 'border-stone-200 bg-white text-neutral-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                :aria-pressed="i === pestanaActiva"
                :data-pestana-variante="valor"
                @click="pestana = i"
            >
                {{ valor }}
                <span
                    v-if="modo !== 'lectura' && totalPestana(valor) > 0"
                    class="grid h-5 min-w-5 place-items-center rounded-md px-1 text-[11px] font-semibold tabular-nums"
                    :class="i === pestanaActiva ? 'bg-white/25 text-white' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'"
                >{{ totalPestana(valor) }}</span>
            </button>
        </div>

        <p v-if="!piezas.length" class="py-6 text-center text-sm text-neutral-500 dark:text-neutral-400">Este modelo no tiene variantes disponibles.</p>

        <div v-else class="relative" :data-mas-columnas="hayMasALaDerecha || undefined">
        <!-- en PC la barra vertical tiene su sitio reservado: si aparece no le quita ancho a las columnas -->
        <div ref="cuerpo" class="scroll-fino overflow-auto overscroll-contain sm:[scrollbar-gutter:stable]" :class="claseScroll" @scroll.passive="medirScroll">
            <div class="grid pb-1" :class="claseSeparacion" :style="estiloCuadricula">
                <template v-for="pieza in piezas" :key="pieza.clave">
                    <!-- esquina: qué va en filas y qué en columnas -->
                    <div
                        v-if="pieza.tipo === 'esquina'"
                        class="sticky top-0 left-0 z-20 flex items-end bg-white text-[10px] leading-tight font-medium whitespace-nowrap text-neutral-400 uppercase dark:bg-neutral-900 dark:text-neutral-500"
                        :class="[claseTapaDerecha, claseTapaAbajo, claseSombra]"
                    >
                        {{ rejilla.nombreFilas }}
                    </div>
                    <div
                        v-else-if="pieza.tipo === 'columna'"
                        class="sticky top-0 z-10 flex items-end justify-center bg-white px-1 text-center text-xs leading-tight font-semibold text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300"
                        :class="claseTapaAbajo"
                        :title="`${rejilla.nombreColumnas}: ${pieza.texto}`"
                    >
                        <span class="line-clamp-2 break-words">{{ pieza.texto }}</span>
                    </div>
                    <div
                        v-else-if="pieza.tipo === 'fila'"
                        class="sticky left-0 z-10 -my-0.5 flex max-w-[6.375rem] items-center bg-white py-0.5 text-sm font-semibold text-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :class="[claseTapaDerecha, claseSombra]"
                        :title="`${rejilla.nombreFilas}: ${pieza.texto}`"
                    >
                        <span class="line-clamp-2 break-words">{{ pieza.texto }}</span>
                    </div>

                    <!-- combinación que no existe (omitida o inactiva) -->
                    <div
                        v-else-if="pieza.tipo === 'vacia'"
                        class="grid min-h-12 place-items-center rounded-xl border border-dashed border-stone-200 text-neutral-300 dark:border-neutral-800 dark:text-neutral-700"
                        aria-hidden="true"
                        data-celda-vacia
                    >
                        ·
                    </div>

                    <!-- ===== celda: tocar para agregar ===== -->
                    <button
                        v-else-if="modo === 'tocar'"
                        type="button"
                        class="relative flex min-h-12 min-w-0 flex-col items-center justify-center rounded-xl border px-1.5 py-1.5 text-center transition-colors"
                        :class="[
                            estaBloqueada(pieza.variante)
                                ? 'cursor-not-allowed border-stone-200 bg-stone-50 text-neutral-300 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-600'
                                : (cantidadDe(pieza.variante) > 0
                                    ? 'border-emerald-500 bg-emerald-50 text-neutral-900 hover:bg-emerald-100 active:bg-emerald-200 dark:border-emerald-600 dark:bg-emerald-950/30 dark:text-neutral-100 dark:hover:bg-emerald-950/60 dark:active:bg-emerald-900/50'
                                    : 'border-stone-200 bg-white text-neutral-900 hover:border-emerald-400 hover:bg-emerald-50 active:bg-emerald-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:border-emerald-600 dark:hover:bg-emerald-950/30 dark:active:bg-emerald-950/60'),
                            pieza.ancha ? 'col-span-full' : '',
                        ]"
                        :disabled="estaBloqueada(pieza.variante)"
                        :aria-label="`${etiquetaDe(pieza.variante)}${stockDe && stockDe(pieza.variante) != null ? `, ${numero(stockDe(pieza.variante))} disponibles` : ''}`"
                        :title="pieza.variante.nombre"
                        :data-celda-variante="etiquetaDe(pieza.variante)"
                        :data-variante-id="pieza.variante.id"
                        :data-agotada="estaBloqueada(pieza.variante) || undefined"
                        :data-cantidad="cantidadDe(pieza.variante)"
                        @click="emit('tocar', pieza.variante)"
                    >
                        <span v-if="pieza.titulo" class="max-w-full truncate text-sm font-semibold">{{ pieza.titulo }}</span>
                        <span
                            v-if="stockDe && stockDe(pieza.variante) != null"
                            class="tabular-nums"
                            :class="pieza.titulo ? 'text-[11px] text-neutral-500 dark:text-neutral-400' : 'text-sm font-semibold'"
                            data-celda-stock
                        >{{ numero(stockDe(pieza.variante)) }}{{ pieza.titulo ? ' disp.' : '' }}</span>
                        <Plus v-else-if="!pieza.titulo" class="size-4 text-emerald-600 dark:text-emerald-400" />
                        <span v-if="notaDe && notaDe(pieza.variante)" class="max-w-full truncate text-[11px] font-medium text-emerald-700 dark:text-emerald-400" data-celda-nota>{{ notaDe(pieza.variante) }}</span>
                        <span
                            v-if="cantidadDe(pieza.variante) > 0"
                            class="absolute top-0.5 right-0.5 grid h-4 min-w-4 place-items-center rounded-md bg-emerald-600 px-1 text-[10px] leading-none font-bold text-white tabular-nums"
                            data-celda-contador
                        >{{ numero(cantidadDe(pieza.variante)) }}</span>
                    </button>

                    <!-- ===== celda: cantidad con − / + ===== -->
                    <div
                        v-else-if="modo === 'cantidades'"
                        class="min-w-0"
                        :class="pieza.ancha ? 'col-span-full' : ''"
                        :title="pieza.variante.nombre"
                        :data-celda-variante="etiquetaDe(pieza.variante)"
                        :data-variante-id="pieza.variante.id"
                        :data-agotada="estaBloqueada(pieza.variante) || undefined"
                        :data-cantidad="cantidadDe(pieza.variante)"
                    >
                        <p v-if="pieza.titulo" class="mb-0.5 truncate text-center text-xs font-semibold text-neutral-700 dark:text-neutral-200">{{ pieza.titulo }}</p>
                        <div
                            class="flex h-11 items-stretch overflow-hidden rounded-xl border bg-white dark:bg-neutral-950"
                            :class="estaBloqueada(pieza.variante)
                                ? 'border-stone-200 opacity-45 dark:border-neutral-800'
                                : (cantidadDe(pieza.variante) > 0 ? 'border-emerald-500 dark:border-emerald-600' : 'border-stone-300 dark:border-neutral-700')"
                        >
                            <button
                                type="button"
                                class="grid w-9 shrink-0 place-items-center text-neutral-500 hover:bg-stone-100 active:bg-stone-200 disabled:opacity-30 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:active:bg-neutral-700"
                                :aria-label="`Restar ${etiquetaDe(pieza.variante)}`"
                                :disabled="estaBloqueada(pieza.variante) || cantidadDe(pieza.variante) <= 0"
                                data-celda-menos
                                @click="poner(pieza.variante, cantidadDe(pieza.variante) - 1)"
                            >
                                <Minus class="size-3.5" />
                            </button>
                            <input
                                type="number"
                                inputmode="numeric"
                                min="0"
                                step="1"
                                :max="maximo(pieza.variante) ?? undefined"
                                :value="cantidadDe(pieza.variante) || ''"
                                placeholder="0"
                                :disabled="estaBloqueada(pieza.variante)"
                                :aria-label="`Cantidad de ${etiquetaDe(pieza.variante)}`"
                                class="w-full min-w-0 flex-1 [appearance:textfield] bg-transparent text-center text-sm font-semibold tabular-nums placeholder:font-normal placeholder:text-neutral-300 focus:outline-none dark:placeholder:text-neutral-600 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                data-celda-cantidad
                                @focus="$event.target.select()"
                                @change="poner(pieza.variante, $event.target.value); $event.target.value = cantidadDe(pieza.variante) || ''"
                            />
                            <button
                                type="button"
                                class="grid w-9 shrink-0 place-items-center text-emerald-600 hover:bg-emerald-50 active:bg-emerald-100 disabled:text-neutral-400 disabled:opacity-30 dark:text-emerald-400 dark:hover:bg-emerald-950/40 dark:active:bg-emerald-950/70"
                                :aria-label="`Sumar ${etiquetaDe(pieza.variante)}`"
                                :disabled="estaBloqueada(pieza.variante) || enTope(pieza.variante)"
                                data-celda-mas
                                @click="poner(pieza.variante, cantidadDe(pieza.variante) + 1)"
                            >
                                <Plus class="size-3.5" />
                            </button>
                        </div>
                        <p
                            v-if="stockDe && stockDe(pieza.variante) != null"
                            class="mt-0.5 text-center text-[10px] leading-tight tabular-nums"
                            :class="enTope(pieza.variante) && cantidadDe(pieza.variante) > 0 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-neutral-400 dark:text-neutral-500'"
                            data-celda-stock
                        >{{ numero(stockDe(pieza.variante)) }} disp.</p>
                    </div>

                    <!-- ===== celda: solo lectura (stock) ===== -->
                    <component
                        :is="tocable ? 'button' : 'div'"
                        v-else
                        :type="tocable ? 'button' : undefined"
                        class="flex min-h-11 min-w-0 flex-col items-center justify-center rounded-xl border px-1.5 py-1 text-center"
                        :class="[
                            claseLectura(pieza.variante),
                            tocable ? 'transition-colors hover:border-emerald-400 dark:hover:border-emerald-600' : '',
                            elegida === pieza.variante.id ? 'ring-2 ring-emerald-500/60' : '',
                            pieza.ancha ? 'col-span-full' : '',
                        ]"
                        :title="pieza.variante.nombre"
                        :data-celda-variante="etiquetaDe(pieza.variante)"
                        :data-variante-id="pieza.variante.id"
                        :data-estado="estadoDe ? estadoDe(pieza.variante) : undefined"
                        :data-stock="stockDe ? stockDe(pieza.variante) : undefined"
                        @click="tocable && emit('tocar', pieza.variante)"
                    >
                        <span v-if="pieza.titulo" class="max-w-full truncate text-xs font-medium opacity-80">{{ pieza.titulo }}</span>
                        <span class="text-sm font-semibold tabular-nums" data-celda-stock>{{ stockDe ? numero(stockDe(pieza.variante)) : '' }}</span>
                    </component>
                </template>
            </div>
        </div>
        <!-- quedan columnas a la derecha -->
        <div
            v-show="hayMasALaDerecha"
            class="pointer-events-none absolute inset-y-0 right-0 z-30 w-7 bg-gradient-to-l from-white to-transparent dark:from-neutral-900"
            aria-hidden="true"
            data-pista-mas-columnas
        />
        </div>
    </div>
</template>
