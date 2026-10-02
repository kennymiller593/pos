<script setup>
// Dibuja un código de barras como SVG vectorial (se imprime nítido a cualquier tamaño).
// EAN-13 / EAN-8 cuando el código es válido para ese formato; el resto va como CODE 128,
// que acepta letras y cualquier largo.
import { onMounted, ref, watch } from 'vue'
import JsBarcode from 'jsbarcode'

const props = defineProps({
    valor: { type: String, required: true },
})

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
    } catch {
        invalido.value = true
    }
}

onMounted(dibujar)
watch(() => props.valor, dibujar)
</script>

<template>
    <svg v-show="!invalido" ref="svg" class="block size-full" shape-rendering="crispEdges" aria-hidden="true" />
</template>
