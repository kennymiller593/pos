<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Bot, ExternalLink, ImagePlus, MessageCircle, Sparkles, Trash2 } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    negocio: { type: String, default: '' },
    agentes: { type: Array, default: () => [] },
    chatbot: { type: Object, required: true },
    tienda: { type: Object, required: true },
    chats_sin_leer: { type: Number, default: 0 },
})

const form = useForm({
    chatbot_activo: props.chatbot.activo,
    chatbot_agente: props.chatbot.agente ?? (props.agentes[0]?.id ?? ''),
    chatbot_nombre: props.chatbot.nombre ?? '',
    chatbot_saludo: props.chatbot.saludo ?? '',
    chatbot_color: props.chatbot.color ?? '',
    chatbot_avatar: null,
    chatbot_avatar_quitar: false,
})

// ---- imagen del asistente ----
const inputAvatar = ref(null)
const avatarNuevo = ref(null)
const avatar = computed(() => avatarNuevo.value ?? (form.chatbot_avatar_quitar ? null : props.chatbot.avatar))
function elegirAvatar(evento) {
    const archivo = evento.target.files?.[0]
    if (!archivo) return
    form.clearErrors('chatbot_avatar')
    if (archivo.size > 2 * 1024 * 1024) {
        form.setError('chatbot_avatar', 'La imagen no debe pesar más de 2 MB.')
        evento.target.value = ''
        return
    }
    if (avatarNuevo.value) URL.revokeObjectURL(avatarNuevo.value)
    avatarNuevo.value = URL.createObjectURL(archivo)
    form.chatbot_avatar = archivo
    form.chatbot_avatar_quitar = false
}
function quitarAvatar() {
    if (avatarNuevo.value) URL.revokeObjectURL(avatarNuevo.value)
    avatarNuevo.value = null
    form.chatbot_avatar = null
    form.chatbot_avatar_quitar = true
    if (inputAvatar.value) inputAvatar.value.value = ''
}

// ---- color y vista previa ----
const TEXTOS = { claro: '#FFFFFF', oscuro: '#0F172A' }
function contraste(a, b) {
    const luminancia = (hex) => {
        const [r, g, v] = [1, 3, 5].map((i) => {
            const c = parseInt(hex.slice(i, i + 2), 16) / 255
            return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
        })
        return 0.2126 * r + 0.7152 * g + 0.0722 * v
    }
    const [clara, oscura] = [luminancia(a), luminancia(b)].sort((x, y) => y - x)
    return (clara + 0.05) / (oscura + 0.05)
}
const colorBotValido = computed(() => /^#[0-9a-f]{6}$/i.test(form.chatbot_color ?? ''))
const colorBot = computed(() => (colorBotValido.value ? form.chatbot_color : props.tienda.color))
const sobreBot = computed(() => (contraste(colorBot.value, TEXTOS.claro) >= contraste(colorBot.value, TEXTOS.oscuro) ? TEXTOS.claro : TEXTOS.oscuro))
const nombreBot = computed(() => form.chatbot_nombre.trim() || 'Asistente virtual')
const saludoBot = computed(() => form.chatbot_saludo.trim() || `¡Hola! Soy ${nombreBot.value}, asistente virtual de ${props.negocio}. Cuéntame qué buscas y te ayudo a encontrarlo.`)

function guardar() {
    form.transform((d) => ({ ...d, _method: 'put', chatbot_agente: d.chatbot_agente || null })).post('/asistente', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            if (avatarNuevo.value) URL.revokeObjectURL(avatarNuevo.value)
            avatarNuevo.value = null
            form.chatbot_avatar = null
            form.chatbot_avatar_quitar = false
            if (inputAvatar.value) inputAvatar.value.value = ''
        },
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseAyuda = 'mt-1 text-xs text-neutral-400 dark:text-neutral-500'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900'
</script>

<template>
    <AppLayout titulo="Asistente de IA">
        <div class="space-y-4">
            <!-- accesos y uso -->
            <div class="grid gap-4 md:grid-cols-3">
                <Link href="/asistente/consultas" :class="[claseTarjeta, 'group flex items-center gap-3 transition-colors hover:border-emerald-500']" data-ir-consultas>
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"><Sparkles class="size-5" /></span>
                    <span class="min-w-0">
                        <span class="block font-semibold">Consultar al asistente</span>
                        <span class="block text-xs text-neutral-500 dark:text-neutral-400">Para ti y tu equipo: pregunta por productos y usos con tu catálogo.</span>
                    </span>
                </Link>
                <Link v-if="tienda.habilitada" href="/asistente/tienda" :class="[claseTarjeta, 'group flex items-center gap-3 transition-colors hover:border-emerald-500']" data-ir-chats>
                    <span class="relative grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <MessageCircle class="size-5" />
                        <span v-if="chats_sin_leer" class="absolute -top-1.5 -right-1.5 min-w-5 rounded-full bg-red-500 px-1 text-center text-[11px] leading-5 font-bold text-white">{{ chats_sin_leer }}</span>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-semibold">Chats de la tienda</span>
                        <span class="block text-xs text-neutral-500 dark:text-neutral-400">{{ chats_sin_leer ? `${chats_sin_leer} cliente(s) esperan respuesta.` : 'Lo que preguntan tus clientes; puedes responderles tú.' }}</span>
                    </span>
                </Link>
                <div :class="claseTarjeta">
                    <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">Consultas este mes</p>
                    <p v-for="a in agentes" :key="a.id" class="mt-1 flex items-baseline justify-between gap-2 text-sm">
                        <span class="truncate font-medium">{{ a.nombre }}</span>
                        <span class="shrink-0 text-neutral-500 dark:text-neutral-400"><b class="text-neutral-900 dark:text-neutral-100">{{ a.consultas_mes.toLocaleString('es-PE') }}</b> / {{ a.limite_mensual.toLocaleString('es-PE') }}</span>
                    </p>
                    <p :class="claseAyuda">Suma las consultas de tu equipo y las de los clientes en la tienda.</p>
                </div>
            </div>

            <!-- chatbot de la tienda -->
            <form :class="[claseTarjeta, 'sm:p-6']" data-chatbot-config @submit.prevent="guardar">
                <div class="flex items-start gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"><Bot class="size-5" /></div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold tracking-tight">Asistente virtual en tu tienda en línea</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">
                            Un chat flotante en tu tienda, como el de WhatsApp: tus clientes le preguntan y él recomienda productos de tu catálogo con foto, precio y botón para comprar.
                        </p>
                    </div>
                </div>

                <div v-if="!tienda.habilitada" class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    Tu empresa no tiene contratada la tienda en línea. Mientras tanto, el asistente ya atiende a tu equipo en "Consultar al asistente".
                </div>

                <div v-else class="mt-5 grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors sm:col-span-2" :class="form.chatbot_activo ? 'border-emerald-500 bg-emerald-50/60 dark:bg-emerald-950/30' : 'border-stone-200 dark:border-neutral-800'">
                            <input v-model="form.chatbot_activo" type="checkbox" class="size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" />
                            <span>
                                <span class="block text-sm font-semibold">Mostrar el asistente en mi tienda</span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">Aparece como botón flotante en todas las páginas y reemplaza al botón de WhatsApp (que sigue en la cabecera y en "Comprar").</span>
                            </span>
                        </label>
                        <div v-if="agentes.length > 1" class="sm:col-span-2">
                            <label :class="claseLabel" for="chatbot_agente">Agente que atiende</label>
                            <select id="chatbot_agente" v-model="form.chatbot_agente" :class="claseInput">
                                <option v-for="a in agentes" :key="a.id" :value="a.id">{{ a.nombre }}<template v-if="a.descripcion"> · {{ a.descripcion }}</template></option>
                            </select>
                            <p v-if="form.errors.chatbot_agente" :class="claseError">{{ form.errors.chatbot_agente }}</p>
                        </div>
                        <p v-else-if="form.errors.chatbot_agente" :class="[claseError, 'sm:col-span-2']">{{ form.errors.chatbot_agente }}</p>
                        <div>
                            <label :class="claseLabel" for="chatbot_nombre">Nombre del asistente</label>
                            <input id="chatbot_nombre" v-model="form.chatbot_nombre" type="text" maxlength="40" :class="claseInput" placeholder="Dania" />
                            <p v-if="form.errors.chatbot_nombre" :class="claseError">{{ form.errors.chatbot_nombre }}</p>
                            <p v-else :class="claseAyuda">Se presenta como "Soy {{ nombreBot }}, asistente virtual de {{ negocio }}".</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="chatbot_color">Color del chat <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <div class="flex items-center gap-2">
                                <input :value="colorBot" type="color" class="size-10 shrink-0 cursor-pointer rounded-xl border border-stone-300 bg-white p-1 dark:border-neutral-700" aria-label="Elegir color" @input="form.chatbot_color = $event.target.value.toUpperCase()" />
                                <input id="chatbot_color" v-model="form.chatbot_color" type="text" maxlength="7" :class="claseInput" placeholder="El de tu tienda" @input="form.clearErrors('chatbot_color')" />
                                <button v-if="form.chatbot_color" type="button" class="h-10 shrink-0 rounded-xl border border-stone-300 px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="form.chatbot_color = ''">Usar el de la tienda</button>
                            </div>
                            <p v-if="form.errors.chatbot_color" :class="claseError">{{ form.errors.chatbot_color }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="chatbot_saludo">Primer mensaje <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <textarea id="chatbot_saludo" v-model="form.chatbot_saludo" rows="2" maxlength="200" :class="[claseInput, 'h-auto py-2']" :placeholder="`¡Hola! Soy ${nombreBot}, asistente virtual de ${negocio}. Cuéntame qué buscas y te ayudo a encontrarlo.`" />
                            <p v-if="form.errors.chatbot_saludo" :class="claseError">{{ form.errors.chatbot_saludo }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel">Imagen del asistente</label>
                            <div class="flex flex-wrap items-center gap-4">
                                <div class="grid size-16 place-items-center overflow-hidden rounded-full border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-950">
                                    <img v-if="avatar" :src="avatar" alt="Imagen actual" class="size-16 object-cover" />
                                    <span v-else class="text-xl font-bold text-neutral-300">{{ nombreBot.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="inline-flex h-10 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-semibold hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="inputAvatar.click()">
                                        <ImagePlus class="size-4" />
                                        {{ avatar ? 'Cambiar imagen' : 'Subir imagen' }}
                                    </button>
                                    <button v-if="avatar" type="button" class="inline-flex h-10 items-center gap-2 rounded-xl px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" @click="quitarAvatar">
                                        <Trash2 class="size-4" />
                                        Quitar
                                    </button>
                                </div>
                                <input ref="inputAvatar" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" aria-label="Imagen del asistente" @change="elegirAvatar" />
                            </div>
                            <p v-if="form.errors.chatbot_avatar" :class="claseError">{{ form.errors.chatbot_avatar }}</p>
                            <p v-else :class="claseAyuda">Una foto o ilustración cuadrada (se recorta en círculo). Sin imagen se muestra la inicial del nombre.</p>
                        </div>
                    </div>

                    <!-- vista previa -->
                    <div>
                        <p :class="claseLabel">Así lo verá tu cliente</p>
                        <div class="overflow-hidden rounded-3xl border border-stone-200 bg-white shadow-sm dark:border-neutral-700">
                            <div class="flex items-center gap-3 px-4 py-3" :style="{ background: colorBot, color: sobreBot }">
                                <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-full bg-white/20 ring-2 ring-white/60">
                                    <img v-if="avatar" :src="avatar" alt="" class="size-10 object-cover" />
                                    <span v-else class="font-bold">{{ nombreBot.charAt(0).toUpperCase() }}</span>
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-semibold leading-tight">{{ nombreBot }}</p>
                                    <p class="flex items-center gap-1.5 text-xs opacity-90"><span class="size-2 rounded-full bg-emerald-300" /> En línea</p>
                                </div>
                            </div>
                            <div class="space-y-2 bg-slate-50 p-3 text-sm">
                                <div class="max-w-[90%] rounded-2xl rounded-bl-md border border-slate-200 bg-white px-3 py-2 text-slate-800">{{ saludoBot }}</div>
                                <div class="ml-auto max-w-[80%] rounded-2xl rounded-br-md px-3 py-2" :style="{ background: colorBot, color: sobreBot }">¿Qué tienen para…?</div>
                                <div class="max-w-[90%] rounded-2xl border border-slate-200 bg-white p-2 text-xs text-slate-500">Recomendación con foto, precio y botones <b>Comprar</b> / <b>Detalles</b></div>
                            </div>
                        </div>
                        <p :class="claseAyuda">Responde solo con tu catálogo y respeta lo que muestras en la tienda{{ tienda.mostrar_precios ? ' (con precios)' : ' (sin precios)' }}.</p>
                        <a v-if="tienda.publicada && tienda.url" :href="tienda.url" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-700 underline underline-offset-2 dark:text-emerald-400">
                            Ver mi tienda <ExternalLink class="size-3" />
                        </a>
                    </div>
                </div>

                <div v-if="tienda.habilitada" class="mt-5 flex items-center justify-end gap-3">
                    <span v-if="form.isDirty" class="text-xs text-neutral-400">Tienes cambios sin guardar</span>
                    <button type="submit" :disabled="form.processing" class="inline-flex h-10 items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
