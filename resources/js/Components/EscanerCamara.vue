<script setup>
// Escáner de códigos de barras con la cámara del celular.
// Lee de forma continua: cada código leído se entrega a `alLeer(codigo)`, que devuelve
// { ok, mensaje } para mostrar el resultado (agregado, sin stock, no registrado...).
// Usa el lector nativo del navegador (BarcodeDetector, Android/Chrome) y, si no existe
// (iPhone/Safari), la librería ZXing, que se descarga solo al abrir el escáner.
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { CircleAlert, CircleCheck, Flashlight, FlashlightOff, LoaderCircle, ScanBarcode, X } from '@lucide/vue'

const props = defineProps({
    abierto: { type: Boolean, default: false },
    // (codigo: string) => { ok: boolean, mensaje: string }
    alLeer: { type: Function, required: true },
    // texto de resumen al pie, p. ej. "3 productos · S/ 85.00"
    resumen: { type: String, default: '' },
})

const emit = defineEmits(['cerrar'])

const FORMATOS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf']
// el mismo código vuelve a sumar solo si salió de la cámara al menos este tiempo:
// dejar el celular apuntando a un producto no suma unidades sin control
const AUSENCIA_PARA_REPETIR = 1200
const ESPERA_ENTRE_LECTURAS = 600 // ms mínimos entre dos lecturas cualesquiera

const video = ref(null)
const estado = ref('cargando') // cargando | leyendo | error
const error = ref('')
const ultimo = ref(null) // { ok, mensaje, codigo }
const hayLinterna = ref(false)
const linternaEncendida = ref(false)

let stream = null
let detenerLectura = null
let audio = null
let ultimoCodigo = ''
let ultimoMomento = 0 // cuándo se aceptó la última lectura
let ultimoVisto = 0 // cuándo se vio por última vez ultimoCodigo (aunque no se haya aceptado)
let temporizadorAviso = null

// ---------------- ciclo de vida ----------------
watch(
    () => props.abierto,
    async (abierto) => {
        if (abierto) {
            await nextTick()
            iniciar()
        } else {
            detener()
        }
    },
)

onBeforeUnmount(detener)

async function iniciar() {
    estado.value = 'cargando'
    error.value = ''
    ultimo.value = null
    linternaEncendida.value = false
    ultimoCodigo = ''

    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        return fallar('Tu navegador no permite usar la cámara en esta página.')
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        })
    } catch (e) {
        return fallar(
            e?.name === 'NotAllowedError'
                ? 'No diste permiso para usar la cámara. Actívalo en los ajustes del navegador (ícono del candado junto a la dirección) y vuelve a intentarlo.'
                : e?.name === 'NotFoundError'
                  ? 'No encontramos una cámara en este dispositivo.'
                  : 'No se pudo abrir la cámara. Cierra otras apps que la estén usando y vuelve a intentarlo.',
        )
    }

    if (!props.abierto) return detener() // se cerró mientras pedía permiso

    video.value.srcObject = stream
    await video.value.play().catch(() => {})

    const pista = stream.getVideoTracks()[0]
    hayLinterna.value = !!pista?.getCapabilities?.()?.torch

    // el sonido se prepara aquí porque el navegador exige un toque del usuario (el botón que abrió el escáner)
    try {
        audio = new (window.AudioContext || window.webkitAudioContext)()
    } catch {
        audio = null
    }

    try {
        detenerLectura = (await lectorNativo()) ?? (await lectorZxing())
        estado.value = 'leyendo'
    } catch {
        fallar('No se pudo iniciar el lector de códigos en este navegador.')
    }
}

function detener() {
    detenerLectura?.()
    detenerLectura = null
    stream?.getTracks().forEach((t) => t.stop())
    stream = null
    if (video.value) video.value.srcObject = null
    audio?.close?.().catch(() => {})
    audio = null
    clearTimeout(temporizadorAviso)
}

function fallar(mensaje) {
    detener()
    estado.value = 'error'
    error.value = mensaje
}

function cerrar() {
    detener()
    emit('cerrar')
}

// ---------------- lectores ----------------
// Android/Chrome: lector integrado del navegador (rápido y sin descargar nada)
async function lectorNativo() {
    if (!('BarcodeDetector' in window)) return null
    const soportados = await window.BarcodeDetector.getSupportedFormats().catch(() => [])
    const formatos = FORMATOS.filter((f) => soportados.includes(f))
    if (!formatos.length) return null

    const detector = new window.BarcodeDetector({ formats: formatos })
    let activo = true
    let temporizador = null

    const ciclo = async () => {
        if (!activo) return
        if (video.value?.readyState >= 2) {
            try {
                const codigos = await detector.detect(video.value)
                if (codigos[0]?.rawValue) leido(codigos[0].rawValue)
            } catch {
                // un frame que no se pudo analizar: se sigue con el siguiente
            }
        }
        temporizador = setTimeout(ciclo, 120)
    }
    ciclo()

    return () => {
        activo = false
        clearTimeout(temporizador)
    }
}

// iPhone/Safari y navegadores sin lector integrado: ZXing (se descarga recién aquí)
async function lectorZxing() {
    const [{ BrowserMultiFormatReader }, { BarcodeFormat, DecodeHintType }] = await Promise.all([
        import('@zxing/browser'),
        import('@zxing/library'),
    ])
    const pistas = new Map()
    pistas.set(DecodeHintType.POSSIBLE_FORMATS, [
        BarcodeFormat.EAN_13, BarcodeFormat.EAN_8, BarcodeFormat.UPC_A, BarcodeFormat.UPC_E,
        BarcodeFormat.CODE_128, BarcodeFormat.CODE_39, BarcodeFormat.ITF,
    ])
    const lector = new BrowserMultiFormatReader(pistas)
    const controles = await lector.decodeFromVideoElement(video.value, (resultado) => {
        if (resultado) leido(resultado.getText())
    })

    return () => controles.stop()
}

// ---------------- al leer un código ----------------
function leido(codigo) {
    codigo = String(codigo).trim()
    const ahora = Date.now()
    if (!codigo) return
    if (codigo === ultimoCodigo) {
        const sigueEnCamara = ahora - ultimoVisto < AUSENCIA_PARA_REPETIR
        ultimoVisto = ahora
        if (sigueEnCamara) return
    }
    if (ahora - ultimoMomento < ESPERA_ENTRE_LECTURAS) return
    ultimoCodigo = codigo
    ultimoMomento = ahora
    ultimoVisto = ahora

    const resultado = props.alLeer(codigo) ?? { ok: false, mensaje: '' }
    ultimo.value = { ...resultado, codigo }
    avisar(resultado.ok)

    clearTimeout(temporizadorAviso)
    temporizadorAviso = setTimeout(() => (ultimo.value = null), 2500)
}

function avisar(ok) {
    navigator.vibrate?.(ok ? 70 : [60, 60, 60])
    if (!audio) return
    try {
        const osc = audio.createOscillator()
        const vol = audio.createGain()
        osc.type = 'sine'
        osc.frequency.value = ok ? 1250 : 330
        vol.gain.setValueAtTime(0.15, audio.currentTime)
        vol.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + (ok ? 0.12 : 0.3))
        osc.connect(vol).connect(audio.destination)
        osc.start()
        osc.stop(audio.currentTime + (ok ? 0.12 : 0.3))
    } catch {
        // sin sonido no pasa nada: queda la vibración y el aviso en pantalla
    }
}

async function alternarLinterna() {
    const pista = stream?.getVideoTracks()[0]
    if (!pista) return
    try {
        await pista.applyConstraints({ advanced: [{ torch: !linternaEncendida.value }] })
        linternaEncendida.value = !linternaEncendida.value
    } catch {
        hayLinterna.value = false
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-[70] flex flex-col bg-black text-white">
            <!-- Barra superior -->
            <div class="flex items-center justify-between gap-3 px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-3">
                <p class="inline-flex items-center gap-2 text-sm font-semibold">
                    <ScanBarcode class="size-5" />
                    Escanear productos
                </p>
                <div class="flex items-center gap-2">
                    <button
                        v-if="hayLinterna"
                        type="button"
                        class="grid size-11 place-items-center rounded-full bg-white/15 backdrop-blur"
                        :aria-label="linternaEncendida ? 'Apagar linterna' : 'Encender linterna'"
                        @click="alternarLinterna"
                    >
                        <FlashlightOff v-if="linternaEncendida" class="size-5" />
                        <Flashlight v-else class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="grid size-11 place-items-center rounded-full bg-white/15 backdrop-blur"
                        aria-label="Cerrar escáner"
                        @click="cerrar"
                    >
                        <X class="size-5" />
                    </button>
                </div>
            </div>

            <!-- Cámara -->
            <div class="relative min-h-0 flex-1 overflow-hidden">
                <video ref="video" class="size-full object-cover" playsinline muted autoplay />

                <!-- Guía -->
                <div v-if="estado === 'leyendo'" class="pointer-events-none absolute inset-0 grid place-items-center">
                    <div class="relative h-40 w-[80%] max-w-sm rounded-2xl shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]">
                        <span class="absolute -top-0.5 -left-0.5 size-8 rounded-tl-2xl border-t-4 border-l-4 border-white" />
                        <span class="absolute -top-0.5 -right-0.5 size-8 rounded-tr-2xl border-t-4 border-r-4 border-white" />
                        <span class="absolute -bottom-0.5 -left-0.5 size-8 rounded-bl-2xl border-b-4 border-l-4 border-white" />
                        <span class="absolute -right-0.5 -bottom-0.5 size-8 rounded-br-2xl border-r-4 border-b-4 border-white" />
                        <span class="absolute inset-x-4 top-1/2 h-0.5 animate-pulse bg-red-500/80" />
                    </div>
                    <p class="absolute bottom-6 px-6 text-center text-sm text-white/80">
                        Apunta al código de barras del producto.<br />
                        <span class="text-xs text-white/60">Para sumar otra unidad del mismo, aparta la cámara y vuelve a apuntar.</span>
                    </p>
                </div>

                <div v-if="estado === 'cargando'" class="absolute inset-0 grid place-items-center">
                    <p class="inline-flex items-center gap-2 text-sm text-white/80">
                        <LoaderCircle class="size-5 animate-spin" />
                        Abriendo la cámara…
                    </p>
                </div>

                <div v-if="estado === 'error'" class="absolute inset-0 grid place-items-center p-6">
                    <div class="max-w-sm rounded-2xl bg-white p-5 text-center text-neutral-900">
                        <CircleAlert class="mx-auto mb-2 size-8 text-red-500" />
                        <p class="text-sm">{{ error }}</p>
                        <div class="mt-4 flex justify-center gap-2">
                            <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium" @click="cerrar">Cerrar</button>
                            <button type="button" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" @click="iniciar">Reintentar</button>
                        </div>
                    </div>
                </div>

                <!-- Resultado de la última lectura -->
                <Transition
                    enter-active-class="transition duration-150"
                    enter-from-class="opacity-0 -translate-y-2"
                    leave-active-class="transition duration-200"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="ultimo"
                        class="absolute inset-x-4 top-4 flex items-start gap-2.5 rounded-2xl px-4 py-3 text-sm font-medium shadow-lg"
                        :class="ultimo.ok ? 'bg-emerald-600' : 'bg-red-600'"
                    >
                        <CircleCheck v-if="ultimo.ok" class="mt-0.5 size-5 shrink-0" />
                        <CircleAlert v-else class="mt-0.5 size-5 shrink-0" />
                        <span>{{ ultimo.mensaje }}</span>
                    </div>
                </Transition>
            </div>

            <!-- Pie -->
            <div class="flex items-center justify-between gap-3 px-4 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <p class="min-w-0 truncate text-sm text-white/80">{{ resumen || 'Carrito vacío' }}</p>
                <button type="button" class="h-11 shrink-0 rounded-xl bg-emerald-600 px-6 text-sm font-semibold" @click="cerrar">
                    Listo
                </button>
            </div>
        </div>
    </Teleport>
</template>
