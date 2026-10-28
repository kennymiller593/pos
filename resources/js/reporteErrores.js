// Avisa al servidor de lo que falla en el navegador y que, si no, no deja rastro en ningún log:
// errores de JavaScript, envíos de Inertia sin respuesta (red, bloqueo), respuestas que no son del sistema
// (una página de Cloudflare, un 419, un 500) y los rechazos del propio sistema (flash de error).
// Los avisos van en segundo plano (keepalive) y no interrumpen al usuario. Se ven en /admin/errores.
import { router } from '@inertiajs/vue3'

const MAX_POR_MINUTO = 10
let enviados = []
let ultimo = null
// la última acción pedida al servidor, para saber qué intentaba el usuario cuando falló
let ultimaVisita = null

const recortar = (texto, largo) => (texto == null ? null : String(texto).slice(0, largo))
const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '')

export function reportar(tipo, mensaje, detalle = {}) {
    try {
        const ahora = Date.now()
        enviados = enviados.filter((t) => ahora - t < 60000)
        const firma = `${tipo}|${mensaje}`
        // el mismo error repetido en segundos (un bucle, un reintento) se avisa una sola vez
        if (enviados.length >= MAX_POR_MINUTO || (ultimo && ultimo.firma === firma && ahora - ultimo.en < 10000)) return
        enviados.push(ahora)
        ultimo = { firma, en: ahora }

        fetch('/errores-cliente', {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                tipo,
                mensaje: recortar(mensaje, 2000) || 'Sin mensaje',
                pantalla: recortar(location.pathname + location.search, 1000),
                accion: ultimaVisita ? recortar(`${ultimaVisita.method.toUpperCase()} ${ultimaVisita.url}`, 1000) : null,
                detalle,
            }),
        }).catch(() => {})
    } catch {
        // el aviso nunca debe romper la pantalla
    }
}

export function vigilarErrores() {
    window.addEventListener('error', (e) => {
        reportar('js', e.message || 'Error de JavaScript', { archivo: recortar(e.filename, 300), linea: e.lineno, columna: e.colno, pila: recortar(e.error?.stack, 1500) })
    })
    window.addEventListener('unhandledrejection', (e) => {
        const r = e.reason
        // una visita de Inertia cancelada por otra no es un error
        if (r?.name === 'CanceledError' || r?.code === 'ERR_CANCELED') return
        reportar('js', r?.message || String(r) || 'Promesa rechazada', { pila: recortar(r?.stack, 1500) })
    })

    router.on('before', (e) => {
        const v = e.detail.visit
        ultimaVisita = { method: v.method, url: typeof v.url === 'string' ? v.url : v.url?.pathname + (v.url?.search ?? ''), desde: Date.now() }
    })
    // el servidor respondió algo que no es una página del sistema: bloqueo, sesión vencida (419), error 500...
    router.on('httpException', (e) => {
        const r = e.detail.response
        reportar('respuesta', `El servidor respondió ${r?.status ?? '?'} ${r?.statusText ?? ''}`.trim(), {
            estado: r?.status,
            tipo_contenido: recortar(r?.headers?.['content-type'], 100),
            respuesta: recortar(typeof r?.data === 'string' ? r.data.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ') : JSON.stringify(r?.data), 600),
            demora_ms: ultimaVisita ? Date.now() - ultimaVisita.desde : null,
        })
    })
    // no hubo respuesta: sin red, petición cortada o bloqueada antes de llegar
    router.on('networkError', (e) => {
        const x = e.detail.error
        reportar('red', x?.message || 'La petición no obtuvo respuesta', { codigo: x?.code, demora_ms: ultimaVisita ? Date.now() - ultimaVisita.desde : null, en_linea: navigator.onLine })
    })
    // el sistema rechazó la acción (stock insuficiente, lote faltante...): queda por empresa
    router.on('success', (e) => {
        const error = e.detail.page?.props?.flash?.error
        if (error && ultimaVisita && ultimaVisita.method !== 'get') reportar('negocio', error)
    })
}
