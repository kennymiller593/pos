import { useStorage } from '@vueuse/core'

/**
 * Pitidos del POS, generados por el navegador (sin archivos de audio).
 *
 * - 'ok': un tono corto y agudo, como el de un lector de códigos: el producto entró al carrito.
 * - 'error': dos tonos graves: no entró (sin stock, código desconocido).
 *
 * El contexto de audio se crea en el primer pitido: el navegador solo lo permite después de un
 * gesto del usuario, y agregar un producto (clic, toque, Enter o la pistola lectora) lo es.
 * Se puede silenciar; la preferencia se guarda por navegador.
 */
let contexto = null

function audio() {
    if (contexto) return contexto
    const Clase = window.AudioContext || window.webkitAudioContext
    if (!Clase) return null
    try {
        contexto = new Clase()
    } catch {
        contexto = null
    }
    return contexto
}

function tono(ctx, frecuencia, inicio, duracion, tipo, volumen) {
    const oscilador = ctx.createOscillator()
    const ganancia = ctx.createGain()
    oscilador.type = tipo
    oscilador.frequency.value = frecuencia
    // sube y baja en unos milisegundos: sin eso el tono empieza y termina con un "clic"
    ganancia.gain.setValueAtTime(0.0001, inicio)
    ganancia.gain.exponentialRampToValueAtTime(volumen, inicio + 0.008)
    ganancia.gain.exponentialRampToValueAtTime(0.0001, inicio + duracion)
    oscilador.connect(ganancia).connect(ctx.destination)
    oscilador.start(inicio)
    oscilador.stop(inicio + duracion + 0.02)
}

export function useSonido() {
    const activo = useStorage('pos-sonido', true)

    function pitido(tipo = 'ok') {
        if (!activo.value) return
        const ctx = audio()
        if (!ctx) return
        try {
            // tras un rato sin uso el navegador lo deja en pausa
            if (ctx.state === 'suspended') ctx.resume()
            const ahora = ctx.currentTime
            if (tipo === 'error') {
                tono(ctx, 220, ahora, 0.13, 'square', 0.12)
                tono(ctx, 196, ahora + 0.17, 0.2, 'square', 0.12)
            } else {
                tono(ctx, 1320, ahora, 0.09, 'sine', 0.3)
            }
        } catch {
            // sin sonido no pasa nada: el producto ya entró (o el aviso ya se mostró)
        }
    }

    return { activo, pitido }
}
