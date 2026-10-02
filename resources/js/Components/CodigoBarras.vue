<script setup>
// Dibuja un código de barras como SVG vectorial (se imprime nítido a cualquier tamaño).
// EAN-13 / EAN-8 cuando el código es válido para ese formato; el resto va como CODE 128,
// que acepta letras y cualquier largo.
import { onMounted, ref, watch } from 'vue'
import JsBarcode from 'jsbarcode'

const props = defineProps({
    valor: { type: String, required: true },
    // ancho disponible en mm. Con este dato cada barra se ajusta a un número entero de puntos de
    // la impresora térmica (203 dpi = 8 puntos por mm): si una barra mide 3,6 puntos, la impresora
    // la redondea distinto cada vez y el código sale "pixeleado" y con barras desiguales.
    anchoMax: { type: Number, default: 0 },
})

const PUNTO_MM = 0.125 // 1 punto a 203 dpi
const PUNTOS_MAX = 4 // 0,5 mm por barra: más ancho no se lee mejor

const svg = ref(null)
const invalido = ref(false)

function digitoControl(digitos) {
    // pesos 1 y 3 alternados contando desde la derecha (sirve para EAN-13 y EAN-8)
    const suma = [...digitos].reverse().reduce((s, d, i) => s + Number(d) * (i % 2 === 0 ? 3 : 1), 0)
    return (10 - (suma % 10)) % 10
}

function formatoDe(codigo) {
    if (/^\d{13}$/.test(codigo) && digitoControl(codigo.slice(0, 12)) === Number(codigo[12])) return 'EAN13'
    if (/^\d{8}$/.test(codigo) && digitoControl(codigo.slice(0, 7)) === Number(codigo[7])) return 'EAN8'
    return 'CODE128'
}

function dibujar() {
    if (!svg.value) return
    invalido.value = false
    try {
        JsBarcode(svg.value, props.valor, {
            format: formatoDe(props.valor),
            width: 1, // 1 unidad por módulo: el tamaño real lo da el CSS del contenedor
            height: 40,
            margin: 0,
            marginLeft: 10, // zona de silencio que el lector necesita a cada lado
            marginRight: 10,
            displayValue: false, // los números se imprimen aparte, con la tipografía de la etiqueta
            flat: true,
        })
        // se estira al ancho y alto que le dé la etiqueta
        svg.value.setAttribute('preserveAspectRatio', 'none')
        svg.value.removeAttribute('style')

        // ancho exacto: módulos × puntos enteros (la unidad --mm la pone la etiqueta: 1mm o escala de vista previa)
        const modulos = svg.value.viewBox.baseVal.width
        const puntos = Math.min(PUNTOS_MAX, Math.floor(props.anchoMax / (modulos * PUNTO_MM)))
        svg.value.style.width = puntos >= 1 ? `calc(${modulos * puntos * PUNTO_MM} * var(--mm, 1mm))` : '100%'
    } catch {
        invalido.value = true
    }
}

onMounted(dibujar)
watch(() => [props.valor, props.anchoMax], dibujar)
</script>

<template>
    <svg v-show="!invalido" ref="svg" class="mx-auto block h-full max-w-full" shape-rendering="crispEdges" aria-hidden="true" />
</template>
