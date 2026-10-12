<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Bot, ChevronLeft, MessageCircle, MessageSquarePlus, Package, SendHorizontal, Sparkles, Trash2, UserRound } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { markdownSencillo as html } from '@/utils/markdown'

const props = defineProps({
    agentes: { type: Array, default: () => [] },
    conversaciones: { type: Array, default: () => [] },
    // conversación abierta (?c=id) con sus mensajes, o null
    actual: { type: Object, default: null },
})

const { confirmar } = useConfirmar()

const agenteId = ref(props.actual?.agente_id ?? props.agentes[0]?.id ?? null)
const agente = computed(() => props.agentes.find((a) => a.id === agenteId.value) ?? props.agentes[0])
const conversacionId = ref(props.actual?.id ?? null)
const mensajes = ref(props.actual?.mensajes ? [...props.actual.mensajes] : [])
const lista = ref([...props.conversaciones])
const consultasMes = ref(Object.fromEntries(props.agentes.map((a) => [a.id, a.consultas_mes])))

const texto = ref('')
const enviando = ref(false)
const error = ref(null)
const panelLista = ref(false) // en celular, la lista de conversaciones se abre como panel
const fondo = ref(null)
const entrada = ref(null)

const limiteAlcanzado = computed(() => agente.value && consultasMes.value[agente.value.id] >= agente.value.limite_mensual)

function bajar() {
    nextTick(() => { if (fondo.value) fondo.value.scrollTop = fondo.value.scrollHeight })
}
onMounted(bajar)

function nueva(a = null) {
    if (a) agenteId.value = a.id
    conversacionId.value = null
    mensajes.value = []
    error.value = null
    panelLista.value = false
    history.replaceState({}, '', '/asistente')
    nextTick(() => entrada.value?.focus())
}

function abrir(c) {
    router.get('/asistente', { c: c.id }, { preserveState: false })
}

function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

async function enviar(pregunta = null) {
    const contenido = (pregunta ?? texto.value).trim()
    if (!contenido || enviando.value || !agente.value) return
    texto.value = ''
    error.value = null
    enviando.value = true
    mensajes.value.push({ id: 'tmp-' + Date.now(), rol: 'user', contenido })
    bajar()
    try {
        const r = await fetch('/asistente/mensajes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ conversacion_id: conversacionId.value, agente_id: agente.value.id, texto: contenido }),
        })
        const datos = await r.json()
        if (!r.ok) {
            error.value = datos.mensaje ?? datos.message ?? 'No se pudo enviar la consulta.'
            mensajes.value.pop()
            texto.value = contenido
            return
        }
        mensajes.value.push(datos.mensaje)
        consultasMes.value[agente.value.id] = datos.consultas_mes
        if (!conversacionId.value) {
            conversacionId.value = datos.conversacion.id
            lista.value.unshift(datos.conversacion)
            history.replaceState({}, '', `/asistente?c=${datos.conversacion.id}`)
        } else {
            const i = lista.value.findIndex((c) => c.id === conversacionId.value)
            if (i >= 0) lista.value.splice(i, 1, { ...lista.value[i], ...datos.conversacion })
            lista.value.sort((a, b) => (a.actualizado_en < b.actualizado_en ? 1 : -1))
        }
    } catch (e) {
        error.value = 'Sin conexión con el servidor. Inténtalo de nuevo.'
        mensajes.value.pop()
        texto.value = contenido
    } finally {
        enviando.value = false
        bajar()
        nextTick(() => entrada.value?.focus())
    }
}

async function eliminar(c) {
    const ok = await confirmar({ titulo: 'Eliminar conversación', mensaje: `Se borrará "${c.titulo}".`, textoConfirmar: 'Eliminar', peligro: true })
    if (ok) router.delete(`/asistente/conversaciones/${c.id}`)
}

function hora(iso) {
    if (!iso) return ''
    const f = new Date(String(iso).replace(' ', 'T'))
    return f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}
function fechaCorta(iso) {
    const f = new Date(String(iso).replace(' ', 'T'))
    const hoy = new Date()
    return f.toDateString() === hoy.toDateString() ? hora(iso) : f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' })
}
const nombreAgente = (id) => props.agentes.find((a) => a.id === id)?.nombre ?? 'Asistente'

watch(texto, () => {
    const el = entrada.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(el.scrollHeight, 160) + 'px'
})
</script>

<template>
    <AppLayout titulo="Asistente">
        <div class="flex h-[calc(100dvh-9rem)] min-h-[480px] gap-4 lg:h-[calc(100dvh-6.5rem)]">
            <!-- lista de conversaciones: columna fija en PC, panel deslizable en celular -->
            <aside
                class="flex w-72 shrink-0 flex-col rounded-2xl border border-stone-200 bg-white max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:w-80 max-lg:max-w-[85vw] max-lg:rounded-none max-lg:transition-transform dark:border-neutral-800 dark:bg-neutral-900"
                :class="panelLista ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'"
                data-lista-conversaciones
            >
                <div class="flex items-center gap-2 border-b border-stone-200 p-3 dark:border-neutral-800">
                    <button type="button" class="flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white hover:bg-emerald-700" @click="nueva()">
                        <MessageSquarePlus class="size-4" />
                        Nueva consulta
                    </button>
                    <button type="button" class="grid size-10 place-items-center rounded-xl hover:bg-stone-100 lg:hidden dark:hover:bg-neutral-800" @click="panelLista = false"><ChevronLeft class="size-5" /></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    <p v-if="!lista.length" class="px-3 py-6 text-center text-xs text-neutral-400">Tus consultas anteriores aparecerán aquí.</p>
                    <button
                        v-for="c in lista"
                        :key="c.id"
                        type="button"
                        class="group flex w-full items-start gap-2 rounded-xl px-3 py-2 text-left hover:bg-stone-50 dark:hover:bg-neutral-800"
                        :class="c.id === conversacionId ? 'bg-emerald-50 dark:bg-emerald-950/40' : ''"
                        @click="c.id === conversacionId ? (panelLista = false) : abrir(c)"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ c.titulo }}</p>
                            <p class="truncate text-xs text-neutral-400">{{ nombreAgente(c.agente_id) }} · {{ fechaCorta(c.actualizado_en) }}</p>
                        </div>
                        <span class="rounded-lg p-1 text-neutral-300 opacity-0 group-hover:opacity-100 hover:bg-red-50 hover:text-red-600 max-lg:opacity-100 dark:hover:bg-red-950/40" title="Eliminar" @click.stop="eliminar(c)"><Trash2 class="size-3.5" /></span>
                    </button>
                </div>
            </aside>
            <div v-if="panelLista" class="fixed inset-0 z-30 bg-neutral-950/40 lg:hidden" @click="panelLista = false" />

            <!-- chat -->
            <section class="flex min-w-0 flex-1 flex-col rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900" data-chat>
                <header class="flex items-center gap-3 border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                    <button type="button" class="grid size-9 shrink-0 place-items-center rounded-xl hover:bg-stone-100 lg:hidden dark:hover:bg-neutral-800" title="Conversaciones" @click="panelLista = true"><MessageSquarePlus class="size-5" /></button>
                    <div class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"><Bot class="size-5" /></div>
                    <div class="min-w-0 flex-1">
                        <select v-if="agentes.length > 1 && !conversacionId" v-model="agenteId" class="h-8 max-w-full rounded-lg border border-stone-200 bg-white px-2 text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-950">
                            <option v-for="a in agentes" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                        </select>
                        <p v-else class="truncate font-semibold">{{ agente?.nombre }}</p>
                        <p class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ agente?.descripcion || 'Responde con el catálogo de tu negocio' }}</p>
                    </div>
                    <p v-if="agente" class="shrink-0 text-xs text-neutral-400" :title="'Consultas usadas este mes'">{{ consultasMes[agente.id] }} / {{ agente.limite_mensual }}</p>
                    <Link v-if="$page.props.auth?.user?.empresa?.tienda_habilitada" href="/asistente/tienda" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl border border-stone-300 px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" title="Lo que preguntan tus clientes en la tienda">
                        <MessageCircle class="size-4 text-emerald-600" />
                        <span class="max-sm:hidden">Chats de la tienda</span>
                    </Link>
                </header>

                <div ref="fondo" class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
                    <!-- bienvenida -->
                    <div v-if="!mensajes.length" class="mx-auto flex h-full max-w-xl flex-col items-center justify-center text-center">
                        <div class="grid size-14 place-items-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"><Sparkles class="size-7" /></div>
                        <h2 class="mt-3 text-lg font-semibold tracking-tight">¿En qué te ayudo hoy?</h2>
                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Pregúntame por productos, usos o alternativas. Respondo solo con lo que hay en tu catálogo, con precios y stock.</p>
                        <div v-if="agente?.sugerencias.length" class="mt-5 flex flex-wrap justify-center gap-2">
                            <button v-for="s in agente.sugerencias" :key="s" type="button" class="rounded-full border border-stone-200 px-3 py-1.5 text-sm hover:border-emerald-500 hover:bg-emerald-50 dark:border-neutral-700 dark:hover:bg-emerald-950/30" @click="enviar(s)">{{ s }}</button>
                        </div>
                    </div>

                    <!-- mensajes -->
                    <div v-else class="mx-auto max-w-3xl space-y-4">
                        <div v-for="m in mensajes" :key="m.id" class="flex gap-3" :class="m.rol === 'user' ? 'justify-end' : ''" :data-mensaje="m.rol">
                            <div v-if="m.rol !== 'user'" class="grid size-8 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"><Bot class="size-4" /></div>
                            <div class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed" :class="m.rol === 'user' ? 'bg-emerald-600 text-white' : 'bg-stone-100 dark:bg-neutral-800'">
                                <div v-if="m.rol === 'user'" class="whitespace-pre-wrap">{{ m.contenido }}</div>
                                <div v-else class="space-y-1" v-html="html(m.contenido)" />
                                <div v-if="m.rol !== 'user' && m.productos?.length" class="mt-2 grid gap-2 sm:grid-cols-2" data-tarjetas>
                                    <a v-for="p in m.productos" :key="p.codigo" :href="p.url" class="flex gap-2.5 rounded-xl border border-stone-200 bg-white p-2 transition-colors hover:border-emerald-500 dark:border-neutral-700 dark:bg-neutral-900">
                                        <span class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-stone-50 text-neutral-300 dark:bg-neutral-800">
                                            <img v-if="p.imagen" :src="p.imagen" alt="" class="size-full object-contain" />
                                            <Package v-else class="size-5" />
                                        </span>
                                        <span class="min-w-0">
                                            <span v-if="p.categoria" class="block truncate text-[10px] font-semibold tracking-wider text-neutral-400 uppercase">{{ p.categoria }}</span>
                                            <span class="line-clamp-2 text-xs leading-snug font-semibold">{{ p.nombre }}</span>
                                            <span v-if="p.precio !== null" class="block text-xs font-bold text-emerald-700 dark:text-emerald-400">S/ {{ Number(p.precio).toFixed(2) }}<span v-if="p.presentacion" class="font-normal text-neutral-400"> · {{ p.presentacion }}</span></span>
                                            <span v-if="!p.disponible" class="block text-[11px] font-medium text-red-600">Sin stock</span>
                                        </span>
                                    </a>
                                </div>
                                <p v-if="m.rol !== 'user' && (m.modelo || m.duracion_ms)" class="mt-1.5 text-[11px] text-neutral-400 dark:text-neutral-500">
                                    {{ m.modelo }}<template v-if="m.duracion_ms"> · {{ (m.duracion_ms / 1000).toFixed(1) }} s</template><template v-if="m.herramientas?.length"> · consultó el catálogo</template>
                                </p>
                            </div>
                            <div v-if="m.rol === 'user'" class="grid size-8 shrink-0 place-items-center rounded-xl bg-stone-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300"><UserRound class="size-4" /></div>
                        </div>
                        <div v-if="enviando" class="flex gap-3" data-pensando>
                            <div class="grid size-8 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"><Bot class="size-4" /></div>
                            <div class="flex items-center gap-1.5 rounded-2xl bg-stone-100 px-4 py-3 dark:bg-neutral-800">
                                <span class="size-2 animate-bounce rounded-full bg-neutral-400 [animation-delay:-0.3s]" />
                                <span class="size-2 animate-bounce rounded-full bg-neutral-400 [animation-delay:-0.15s]" />
                                <span class="size-2 animate-bounce rounded-full bg-neutral-400" />
                                <span class="ml-2 text-xs text-neutral-500">Buscando en tu catálogo…</span>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="border-t border-stone-200 p-3 dark:border-neutral-800">
                    <p v-if="error" class="mb-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>
                    <p v-if="limiteAlcanzado" class="mb-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        Se alcanzó el tope de {{ agente.limite_mensual }} consultas de este mes.
                    </p>
                    <form class="flex items-end gap-2" @submit.prevent="enviar()">
                        <textarea
                            ref="entrada"
                            v-model="texto"
                            rows="1"
                            maxlength="2000"
                            :disabled="enviando || limiteAlcanzado"
                            placeholder="Escribe tu consulta…"
                            class="max-h-40 min-h-11 w-full resize-none rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none disabled:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500 dark:disabled:bg-neutral-900"
                            @keydown.enter.exact.prevent="enviar()"
                        />
                        <button type="submit" :disabled="enviando || limiteAlcanzado || !texto.trim()" class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" title="Enviar">
                            <SendHorizontal class="size-5" />
                        </button>
                    </form>
                    <p class="mt-1.5 px-1 text-[11px] text-neutral-400 dark:text-neutral-500">Orienta con tu catálogo; dosis y usos son los de la etiqueta. No reemplaza al profesional. <span class="max-sm:hidden">Enter envía, Shift+Enter cambia de línea.</span></p>
                </footer>
            </section>
        </div>
    </AppLayout>
</template>
