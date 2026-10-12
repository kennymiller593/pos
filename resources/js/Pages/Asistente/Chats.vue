<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Bot, ChevronLeft, Hand, MessageCircle, SendHorizontal, Sparkles, Undo2, UserRound } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { markdownSencillo as html } from '@/utils/markdown'

const props = defineProps({
    negocio: { type: String, default: '' },
    conversaciones: { type: Array, default: () => [] },
    actual: { type: Object, default: null },
})

const lista = ref([...props.conversaciones])
const actual = ref(props.actual ? { ...props.actual, mensajes: [...props.actual.mensajes] } : null)
const texto = ref('')
const enviando = ref(false)
const error = ref(null)
const panelLista = ref(false)
const fondo = ref(null)
const entrada = ref(null)
let sondeo = null

const humano = computed(() => actual.value?.modo === 'humano')

function bajar() {
    nextTick(() => { if (fondo.value) fondo.value.scrollTop = fondo.value.scrollHeight })
}

function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}
const cabeceras = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' })

function abrir(c) {
    router.get('/asistente/tienda', { c: c.id }, { preserveState: false })
}

// la conversación abierta se actualiza sola: lo que escriba el cliente aparece sin recargar
async function refrescar() {
    if (!actual.value || document.hidden) return
    const ultimo = actual.value.mensajes.at(-1)?.id ?? ''
    try {
        const r = await fetch(`/asistente/tienda/${actual.value.id}/mensajes?desde=${encodeURIComponent(ultimo)}`, { headers: { Accept: 'application/json' } })
        if (!r.ok) return
        const datos = await r.json()
        actual.value.modo = datos.modo
        actual.value.atendida_por = datos.atendida_por
        const nuevos = datos.mensajes.filter((m) => !actual.value.mensajes.some((x) => x.id === m.id))
        if (nuevos.length) {
            actual.value.mensajes.push(...nuevos)
            const i = lista.value.findIndex((c) => c.id === actual.value.id)
            if (i >= 0) lista.value[i] = { ...lista.value[i], ultimo: { rol: nuevos.at(-1).rol, texto: nuevos.at(-1).contenido.slice(0, 70) }, mensajes: lista.value[i].mensajes + nuevos.length }
            bajar()
        }
    } catch { /* sin conexión momentánea: se reintenta en el siguiente ciclo */ }
}

async function accion(ruta) {
    error.value = null
    try {
        const r = await fetch(`/asistente/tienda/${actual.value.id}/${ruta}`, { method: 'POST', headers: cabeceras(), body: '{}' })
        const datos = await r.json()
        if (!r.ok) { error.value = datos.mensaje ?? datos.message ?? 'No se pudo hacer.'; return }
        actual.value.modo = datos.modo
        actual.value.atendida_por = datos.atendida_por
        const i = lista.value.findIndex((c) => c.id === actual.value.id)
        if (i >= 0) lista.value[i] = { ...lista.value[i], modo: datos.modo, atendida_por: datos.atendida_por }
        if (datos.modo === 'humano') nextTick(() => entrada.value?.focus())
    } catch { error.value = 'Sin conexión con el servidor.' }
}

async function responder() {
    const contenido = texto.value.trim()
    if (!contenido || enviando.value || !actual.value) return
    enviando.value = true
    error.value = null
    try {
        const r = await fetch(`/asistente/tienda/${actual.value.id}/responder`, { method: 'POST', headers: cabeceras(), body: JSON.stringify({ texto: contenido }) })
        const datos = await r.json()
        if (!r.ok) { error.value = datos.mensaje ?? datos.message ?? 'No se pudo enviar.'; return }
        texto.value = ''
        actual.value.modo = datos.modo
        actual.value.atendida_por = datos.atendida_por
        actual.value.mensajes.push(datos.mensaje)
        const i = lista.value.findIndex((c) => c.id === actual.value.id)
        if (i >= 0) lista.value[i] = { ...lista.value[i], modo: datos.modo, atendida_por: datos.atendida_por, ultimo: { rol: 'humano', texto: contenido.slice(0, 70) }, mensajes: lista.value[i].mensajes + 1 }
        bajar()
    } catch { error.value = 'Sin conexión con el servidor.' } finally {
        enviando.value = false
        nextTick(() => entrada.value?.focus())
    }
}

function hora(iso) {
    if (!iso) return ''
    return new Date(String(iso).replace(' ', 'T')).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}
function cuando(iso) {
    const f = new Date(String(iso).replace(' ', 'T'))
    return f.toDateString() === new Date().toDateString() ? hora(iso) : f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' })
}
const quien = (rol) => ({ user: 'Cliente', assistant: 'Asistente', humano: 'Tú' }[rol] ?? rol)

// la lista también se renueva sola: llegan chats nuevos sin recargar
let sondeoLista = null
watch(() => props.conversaciones, (v) => { lista.value = [...v] })

onMounted(() => {
    bajar()
    sondeo = setInterval(refrescar, 4000)
    sondeoLista = setInterval(() => { if (!document.hidden) router.reload({ only: ['conversaciones'], preserveScroll: true, preserveState: true }) }, 20000)
})
onBeforeUnmount(() => { clearInterval(sondeo); clearInterval(sondeoLista) })
</script>

<template>
    <AppLayout titulo="Chats de la tienda">
        <div class="flex h-[calc(100dvh-9rem)] min-h-[480px] gap-4 lg:h-[calc(100dvh-6.5rem)]">
            <aside
                class="flex w-80 shrink-0 flex-col rounded-2xl border border-stone-200 bg-white max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:w-80 max-lg:max-w-[85vw] max-lg:rounded-none max-lg:transition-transform dark:border-neutral-800 dark:bg-neutral-900"
                :class="panelLista ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'"
                data-lista-chats
            >
                <div class="flex items-center justify-between gap-2 border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                    <div>
                        <p class="font-semibold">Conversaciones</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Lo que preguntan tus clientes en la tienda</p>
                    </div>
                    <button type="button" class="grid size-9 place-items-center rounded-xl hover:bg-stone-100 lg:hidden dark:hover:bg-neutral-800" @click="panelLista = false"><ChevronLeft class="size-5" /></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    <p v-if="!lista.length" class="px-3 py-8 text-center text-sm text-neutral-400">Aún no hay chats. Cuando un cliente le escriba al asistente de tu tienda, aparecerá aquí.</p>
                    <button
                        v-for="c in lista"
                        :key="c.id"
                        type="button"
                        class="flex w-full items-start gap-2.5 rounded-xl px-3 py-2.5 text-left hover:bg-stone-50 dark:hover:bg-neutral-800"
                        :class="c.id === actual?.id ? 'bg-emerald-50 dark:bg-emerald-950/40' : ''"
                        :data-chat="c.id"
                        @click="c.id === actual?.id ? (panelLista = false) : abrir(c)"
                    >
                        <span class="relative grid size-9 shrink-0 place-items-center rounded-full bg-stone-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-300">
                            <UserRound class="size-4" />
                            <span v-if="c.sin_leer" class="absolute -top-0.5 -right-0.5 size-3 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-neutral-900" title="Mensajes nuevos" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-sm" :class="c.sin_leer ? 'font-semibold' : 'font-medium'">{{ c.titulo }}</span>
                                <span class="ml-auto shrink-0 text-[11px] text-neutral-400">{{ cuando(c.actualizado_en) }}</span>
                            </span>
                            <span class="block truncate text-xs text-neutral-500 dark:text-neutral-400">
                                <span v-if="c.ultimo" class="font-medium">{{ quien(c.ultimo.rol) }}:</span> {{ c.ultimo?.texto }}
                            </span>
                            <span v-if="c.modo === 'humano'" class="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                                <Hand class="size-3" /> {{ c.atendida_por ? `Atiende ${c.atendida_por.split(' ')[0]}` : 'Atendida por el negocio' }}
                            </span>
                        </span>
                    </button>
                </div>
                <div class="border-t border-stone-200 p-2 dark:border-neutral-800">
                    <Link href="/asistente" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-stone-50 dark:text-neutral-300 dark:hover:bg-neutral-800">
                        <Sparkles class="size-4 text-emerald-600" />
                        Mi asistente (uso interno)
                    </Link>
                </div>
            </aside>
            <div v-if="panelLista" class="fixed inset-0 z-30 bg-neutral-950/40 lg:hidden" @click="panelLista = false" />

            <section class="flex min-w-0 flex-1 flex-col rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900" data-chat-abierto>
                <div v-if="!actual" class="m-auto max-w-sm p-8 text-center text-neutral-500 dark:text-neutral-400">
                    <MessageCircle class="mx-auto mb-2 size-8 text-neutral-300" />
                    <p class="font-medium text-neutral-700 dark:text-neutral-200">Elige una conversación</p>
                    <p class="mt-1 text-sm">Verás lo que preguntó el cliente y lo que respondió el asistente. Si quieres, tomas la conversación y le respondes tú.</p>
                    <button type="button" class="mt-4 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium lg:hidden dark:border-neutral-700" @click="panelLista = true">Ver conversaciones</button>
                </div>

                <template v-else>
                    <header class="flex flex-wrap items-center gap-3 border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                        <button type="button" class="grid size-9 shrink-0 place-items-center rounded-xl hover:bg-stone-100 lg:hidden dark:hover:bg-neutral-800" @click="panelLista = true"><ChevronLeft class="size-5" /></button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ actual.titulo }}</p>
                            <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                Visitante {{ actual.visitante }} · desde {{ cuando(actual.creado_en) }} ·
                                <span v-if="humano" class="font-medium text-amber-700 dark:text-amber-300">atiende {{ actual.atendida_por ?? 'el negocio' }}</span>
                                <span v-else class="font-medium text-emerald-700 dark:text-emerald-400">responde el asistente</span>
                            </p>
                        </div>
                        <button
                            v-if="!humano"
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 text-sm font-semibold text-white hover:bg-emerald-700"
                            data-tomar
                            @click="accion('tomar')"
                        >
                            <Hand class="size-4" />
                            Tomar la conversación
                        </button>
                        <button
                            v-else
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-stone-300 px-3.5 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            data-devolver
                            @click="accion('devolver')"
                        >
                            <Undo2 class="size-4" />
                            Devolver al asistente
                        </button>
                    </header>

                    <div ref="fondo" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
                        <div v-for="m in actual.mensajes" :key="m.id" class="flex gap-3" :class="m.rol === 'user' ? '' : 'justify-end'" :data-mensaje="m.rol">
                            <div v-if="m.rol === 'user'" class="grid size-8 shrink-0 place-items-center rounded-xl bg-stone-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300"><UserRound class="size-4" /></div>
                            <div class="max-w-[85%]">
                                <p class="mb-0.5 text-[11px] text-neutral-400" :class="m.rol === 'user' ? '' : 'text-right'">{{ quien(m.rol) }} · {{ hora(m.creado_en) }}</p>
                                <div
                                    class="rounded-2xl px-4 py-2.5 text-sm leading-relaxed"
                                    :class="m.rol === 'user' ? 'bg-stone-100 dark:bg-neutral-800' : m.rol === 'humano' ? 'bg-emerald-600 text-white' : 'border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900'"
                                >
                                    <div v-if="m.rol === 'assistant'" class="space-y-1" v-html="html(m.contenido)" />
                                    <div v-else class="whitespace-pre-wrap">{{ m.contenido }}</div>
                                    <p v-if="m.productos?.length" class="mt-1.5 text-[11px]" :class="m.rol === 'humano' ? 'text-emerald-100' : 'text-neutral-400'">Recomendó: {{ m.productos.map((p) => p.nombre).join(', ') }}</p>
                                </div>
                            </div>
                            <div v-if="m.rol !== 'user'" class="grid size-8 shrink-0 place-items-center rounded-xl" :class="m.rol === 'humano' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-stone-100 text-neutral-500 dark:bg-neutral-800'">
                                <Bot v-if="m.rol === 'assistant'" class="size-4" />
                                <UserRound v-else class="size-4" />
                            </div>
                        </div>
                    </div>

                    <footer class="border-t border-stone-200 p-3 dark:border-neutral-800">
                        <p v-if="error" class="mb-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>
                        <form class="flex items-end gap-2" @submit.prevent="responder">
                            <textarea
                                ref="entrada"
                                v-model="texto"
                                rows="1"
                                maxlength="2000"
                                :disabled="enviando"
                                :placeholder="humano ? 'Escríbele al cliente…' : 'Escribe para tomar la conversación y responderle tú…'"
                                class="max-h-40 min-h-11 w-full resize-none rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                                @keydown.enter.exact.prevent="responder"
                            />
                            <button type="submit" :disabled="enviando || !texto.trim()" class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" title="Enviar">
                                <SendHorizontal class="size-5" />
                            </button>
                        </form>
                        <p class="mt-1.5 px-1 text-[11px] text-neutral-400 dark:text-neutral-500">
                            {{ humano ? 'El asistente no responde mientras atiendes tú. Cuando termines, devuélvele la conversación.' : 'El cliente ve tu mensaje en su chat de la tienda al instante.' }}
                        </p>
                    </footer>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
