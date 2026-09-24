import { computed } from 'vue'
import { useStorage } from '@vueuse/core'

/**
 * Modo de impresión del ticket térmico, guardado por navegador.
 *
 * - 'pdf': abre el PDF del ticket en una pestaña nueva (el usuario imprime desde el visor).
 * - 'directa': abre una ventana pequeña con la versión HTML del ticket (?formato=html),
 *   que llama a window.print() al cargar y se cierra al terminar. Si Chrome se lanzó con
 *   --kiosk-printing y la térmica es la impresora predeterminada, no aparece ningún diálogo:
 *   el ticket sale directo.
 */
function imprimirEnIframe(url) {
    let iframe = document.getElementById('pos-iframe-impresion')
    if (!iframe) {
        iframe = document.createElement('iframe')
        iframe.id = 'pos-iframe-impresion'
        iframe.setAttribute('aria-hidden', 'true')
        iframe.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden'
        document.body.appendChild(iframe)
    }
    iframe.src = url
}

export function useImpresion() {
    const modo = useStorage('pos-impresion-modo', 'pdf')

    const etiquetaModo = computed(() => (modo.value === 'directa' ? 'Directa' : 'PDF'))

    function imprimirTicket(urlTicket) {
        if (!urlTicket) return

        if (modo.value === 'directa') {
            const url = urlTicket + (urlTicket.includes('?') ? '&' : '?') + 'formato=html'
            const ventana = window.open(url, '_blank', 'width=420,height=640')
            // Si el navegador bloqueó la ventana (p. ej. impresión automática fuera de un clic),
            // cargamos el ticket en un iframe oculto: la página HTML ya llama a window.print()
            // por sí sola, así que aquí no invocamos print() para no imprimir dos veces.
            if (!ventana) imprimirEnIframe(url)
            return
        }

        window.open(urlTicket, '_blank', 'noopener')
    }

    return { modo, etiquetaModo, imprimirTicket }
}
