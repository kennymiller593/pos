<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Check, Copy, ExternalLink, Globe, LoaderCircle, RefreshCw, Trash2 } from '@lucide/vue'
import { useConfirmar } from '@/composables/confirmar'
import { claseAyuda, claseError, claseInput, claseLabel, claseTarjeta } from './clases'

const props = defineProps({
    // { slug, publicada, url, url_gratuita, dominio (base), dominio_propio: { habilitado, disponible, dominio, raiz, estado, detalle, activado_en, origen } }
    tienda: { type: Object, required: true },
})

const { confirmar } = useConfirmar()
const d = computed(() => props.tienda.dominio_propio)
const form = useForm({ dominio: d.value.dominio ?? '' })
watch(() => d.value.dominio, (v) => (form.dominio = v ?? ''))

const ESTADOS = {
    pendiente: { texto: 'Falta el CNAME', clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
    verificando: { texto: 'Emitiendo certificado', clase: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
    activo: { texto: 'Activo', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    error: { texto: 'Con error', clase: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' },
}

function guardar() {
    form.post('/tienda-en-linea/dominio', { preserveScroll: true })
}

const verificando = ref(false)
function verificar() {
    verificando.value = true
    router.post('/tienda-en-linea/dominio/verificar', {}, { preserveScroll: true, onFinish: () => (verificando.value = false) })
}

async function quitar() {
    const ok = await confirmar({
        titulo: 'Quitar el dominio propio',
        mensaje: `Tu tienda dejará de atender en ${d.value.dominio} y volverá a ${props.tienda.url_gratuita}. Puedes registrarlo otra vez cuando quieras.`,
        textoConfirmar: 'Quitar dominio',
        peligro: true,
    })
    if (ok) router.delete('/tienda-en-linea/dominio', { preserveScroll: true })
}

// mientras Cloudflare emite el certificado, la pantalla se actualiza sola
let temporizador = null
function vigilar() {
    clearInterval(temporizador)
    if (d.value.estado === 'verificando') temporizador = setInterval(() => router.reload({ only: ['tienda'] }), 10000)
}
onMounted(vigilar)
watch(() => d.value.estado, vigilar)
onBeforeUnmount(() => clearInterval(temporizador))

const copiado = ref('')
async function copiar(texto) {
    try {
        await navigator.clipboard.writeText(texto)
        copiado.value = texto
        setTimeout(() => (copiado.value = ''), 1800)
    } catch {
        // sin permiso de portapapeles: el valor sigue visible para copiarlo a mano
    }
}

// la parte "www" (o la que sea) del dominio: es el nombre del registro CNAME
const nombreCname = computed(() => (d.value.dominio ?? '').replace(`.${d.value.raiz}`, '') || 'www')
</script>

<template>
    <div class="grid gap-4">
        <!-- Dirección gratuita: siempre existe -->
        <section :class="[claseTarjeta, 'p-5']">
            <h2 class="font-semibold tracking-tight">Tu dirección gratuita</h2>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-stone-100 px-2.5 py-1 font-medium dark:bg-neutral-800"><Globe class="size-4 text-neutral-400" />{{ tienda.url_gratuita?.replace(/^https?:\/\//, '').replace(/\/$/, '') || `tu-negocio.${tienda.dominio}` }}</span>
                <span class="text-neutral-500 dark:text-neutral-400">Siempre funcionará, aunque configures tu propio dominio.</span>
            </p>
        </section>

        <!-- Sin el adicional -->
        <section v-if="!d.habilitado || !d.disponible" :class="[claseTarjeta, 'p-5']" data-dominio-sin-adicional>
            <h2 class="font-semibold tracking-tight">Dominio propio</h2>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                Atiende tu tienda en tu propio dominio, por ejemplo <span class="font-medium text-neutral-800 dark:text-neutral-200">www.tunegocio.com</span>, con su certificado de seguridad incluido.
            </p>
            <p v-if="!d.disponible" class="mt-3 rounded-xl bg-stone-50 px-4 py-3 text-sm text-neutral-600 dark:bg-neutral-800/60 dark:text-neutral-300">
                Esta opción no está disponible en este entorno.
            </p>
            <p v-else class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                Es un adicional aparte. Para activarlo escríbenos al WhatsApp <a href="https://wa.me/51977425905" target="_blank" rel="noopener" class="font-semibold underline underline-offset-2">977 425 905</a>. Necesitas tener un dominio comprado (en GoDaddy, Namecheap, Punto.pe, Cloudflare, etc.).
            </p>
        </section>

        <template v-else>
            <!-- Registrar / cambiar -->
            <section :class="[claseTarjeta, 'p-5']" data-dominio-form>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold tracking-tight">Dominio propio</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Escribe el dominio que compraste. La tienda se atenderá en la versión con "www".</p>
                    </div>
                    <span v-if="d.dominio" class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="ESTADOS[d.estado]?.clase" data-dominio-estado>{{ ESTADOS[d.estado]?.texto }}</span>
                </div>

                <p v-if="!tienda.publicada" class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    Tu tienda aún no está publicada: el dominio se puede dejar listo, pero los visitantes verán la tienda recién cuando la publiques.
                </p>

                <form class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start" @submit.prevent="guardar">
                    <div class="min-w-0 flex-1">
                        <label :class="claseLabel" for="tienda_dominio" class="sr-only">Tu dominio</label>
                        <input id="tienda_dominio" v-model="form.dominio" type="text" inputmode="url" autocomplete="off" spellcheck="false" maxlength="260" :class="claseInput" placeholder="www.tunegocio.com" />
                        <p v-if="form.errors.dominio" :class="claseError">{{ form.errors.dominio }}</p>
                        <p v-else :class="claseAyuda">Sin https:// ni barras. Ejemplo: www.tunegocio.com o tunegocio.com.pe</p>
                    </div>
                    <button
                        type="submit"
                        :disabled="form.processing || !form.dominio.trim()"
                        class="inline-flex h-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Guardando...' : (d.dominio ? 'Guardar cambio' : 'Guardar y verificar') }}
                    </button>
                </form>
            </section>

            <!-- Estado y pasos -->
            <section v-if="d.dominio" :class="[claseTarjeta, 'p-5']" data-dominio-pasos>
                <!-- Activo -->
                <div v-if="d.estado === 'activo'" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><Check class="size-5" /></span>
                        <div>
                            <p class="font-semibold tracking-tight">Tu tienda ya atiende en <a :href="`https://${d.dominio}`" target="_blank" rel="noopener" class="text-emerald-700 underline underline-offset-2 dark:text-emerald-400">{{ d.dominio }}</a></p>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                Certificado de seguridad activo. Quien entre por {{ tienda.url_gratuita?.replace(/^https?:\/\//, '').replace(/\/$/, '') }} pasa automáticamente a tu dominio.
                                <template v-if="d.raiz !== d.dominio"> Recuerda la redirección de {{ d.raiz }} en tu registrador.</template>
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <a :href="`https://${d.dominio}`" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-semibold hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><ExternalLink class="size-4" />Abrir</a>
                        <button type="button" class="inline-flex h-10 items-center gap-2 rounded-xl px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" @click="quitar"><Trash2 class="size-4" />Quitar</button>
                    </div>
                </div>

                <!-- Pendiente / verificando / error -->
                <template v-else>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold tracking-tight">Cómo conectar {{ d.dominio }}</h2>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">Se hace una sola vez, en el panel de tu proveedor de dominio (donde lo compraste).</p>
                        </div>
                        <button type="button" class="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" @click="quitar"><Trash2 class="size-3.5" />Quitar dominio</button>
                    </div>

                    <ol class="mt-4 space-y-4">
                        <li class="flex gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-neutral-900 text-xs font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900">1</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">Crea un registro DNS de tipo CNAME</p>
                                <dl class="mt-2 grid gap-px overflow-hidden rounded-xl bg-stone-200 text-sm sm:grid-cols-[8rem_1fr] dark:bg-neutral-800">
                                    <dt class="bg-stone-50 px-3 py-2 text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">Tipo</dt>
                                    <dd class="bg-white px-3 py-2 font-mono dark:bg-neutral-950">CNAME</dd>
                                    <dt class="bg-stone-50 px-3 py-2 text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">Nombre</dt>
                                    <dd class="flex items-center justify-between gap-2 bg-white px-3 py-2 font-mono dark:bg-neutral-950">
                                        {{ nombreCname }}
                                        <button type="button" class="inline-flex items-center gap-1 rounded-md px-2 py-1 font-sans text-xs text-neutral-500 hover:bg-stone-100 dark:hover:bg-neutral-800" @click="copiar(nombreCname)"><component :is="copiado === nombreCname ? Check : Copy" class="size-3.5" />{{ copiado === nombreCname ? 'Copiado' : 'Copiar' }}</button>
                                    </dd>
                                    <dt class="bg-stone-50 px-3 py-2 text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">Valor / destino</dt>
                                    <dd class="flex items-center justify-between gap-2 bg-white px-3 py-2 font-mono break-all dark:bg-neutral-950">
                                        {{ d.origen }}
                                        <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 font-sans text-xs text-neutral-500 hover:bg-stone-100 dark:hover:bg-neutral-800" @click="copiar(d.origen)"><component :is="copiado === d.origen ? Check : Copy" class="size-3.5" />{{ copiado === d.origen ? 'Copiado' : 'Copiar' }}</button>
                                    </dd>
                                    <dt class="bg-stone-50 px-3 py-2 text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">TTL</dt>
                                    <dd class="bg-white px-3 py-2 font-mono dark:bg-neutral-950">Automático</dd>
                                </dl>
                                <p :class="claseAyuda">Si tu dominio está en Cloudflare, deja la nube en gris (DNS only).</p>
                            </div>
                        </li>
                        <li v-if="d.raiz !== d.dominio" class="flex gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-neutral-900 text-xs font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900">2</span>
                            <div>
                                <p class="text-sm font-medium">Redirige {{ d.raiz }} a {{ d.dominio }}</p>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">En el mismo panel busca "Redirección" o "Forwarding" y manda {{ d.raiz }} a https://{{ d.dominio }}. Así quien escriba tu dominio sin "www" también llega.</p>
                            </div>
                        </li>
                        <li class="flex gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-neutral-900 text-xs font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900">{{ d.raiz !== d.dominio ? 3 : 2 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">Verifica</p>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">Los cambios de DNS pueden tardar desde minutos hasta algunas horas. Cuando el CNAME esté listo, nosotros emitimos el certificado de seguridad.</p>
                                <div class="mt-3 rounded-xl px-4 py-3 text-sm" :class="d.estado === 'error' ? 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-300' : d.estado === 'verificando' ? 'bg-sky-50 text-sky-900 dark:bg-sky-500/10 dark:text-sky-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200'" data-dominio-detalle>
                                    <template v-if="d.estado === 'verificando'">
                                        <span class="inline-flex items-center gap-2"><LoaderCircle class="size-4 animate-spin" />El CNAME está bien. Emitiendo el certificado de seguridad; esta pantalla se actualiza sola.</span>
                                    </template>
                                    <template v-else>{{ d.detalle || 'Todavía no verificamos tu dominio.' }}</template>
                                </div>
                                <button
                                    type="button"
                                    :disabled="verificando"
                                    class="mt-3 inline-flex h-10 items-center gap-2 rounded-xl bg-neutral-900 px-5 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 disabled:opacity-60 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-white"
                                    @click="verificar"
                                >
                                    <RefreshCw class="size-4" :class="verificando ? 'animate-spin' : ''" />
                                    {{ verificando ? 'Verificando...' : 'Verificar ahora' }}
                                </button>
                            </div>
                        </li>
                    </ol>
                </template>
            </section>
        </template>
    </div>
</template>
