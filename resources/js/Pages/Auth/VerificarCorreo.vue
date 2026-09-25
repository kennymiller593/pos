<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { CheckCircle2, MailCheck, XCircle } from '@lucide/vue'
import PanelMarcaAuth from '@/Components/PanelMarcaAuth.vue'

defineProps({
    email: { type: String, default: '' },
})

const page = usePage()
const exitoFlash = computed(() => page.props.flash?.success ?? null)
const errorFlash = computed(() => page.props.flash?.error ?? null)

const reenviando = ref(false)
function reenviar() {
    reenviando.value = true
    router.post('/verificar-correo/reenviar', {}, {
        preserveScroll: true,
        onFinish: () => { reenviando.value = false },
    })
}

const cerrandoSesion = ref(false)
function cerrarSesion() {
    cerrandoSesion.value = true
    router.post('/logout')
}
</script>

<template>
    <div class="flex min-h-screen bg-[#F5F9F6] text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">

        <PanelMarcaAuth
            eyebrow="<confirmar_correo />"
            titulo="Un último paso"
            destacado="antes de vender."
            descripcion="Confirma tu correo para seguir usando el sistema sin interrupciones."
        />

        <!-- ============ Panel del formulario ============ -->
        <div class="relative flex w-full items-center justify-center p-6 lg:w-1/2">
            <div class="anim w-full max-w-sm">

                <!-- Logo solo móvil -->
                <div class="mb-10 flex justify-center lg:hidden">
                    <div>
                        <img :src="'/images/inkanet_logo.png'" alt="inkaPos" class="h-14 w-auto" />
                    </div>
                </div>

                <div class="mb-5 grid size-12 place-items-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                    <MailCheck class="size-6" />
                </div>

                <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-600 uppercase dark:text-emerald-400">
                    &lt;confirmar_correo /&gt;
                </p>
                <h1 class="font-display mt-3 text-3xl font-bold tracking-tight">Confirma tu correo</h1>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">
                    Para seguir usando el sistema confirma el correo
                    <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ email }}</span>
                    con el enlace que te enviamos. Si no lo encuentras, revisa el spam o pide uno nuevo.
                </p>

                <div v-if="exitoFlash"
                    class="mt-6 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
                    <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                    <span>{{ exitoFlash }}</span>
                </div>
                <div v-if="errorFlash"
                    class="mt-6 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                    <XCircle class="mt-0.5 size-4 shrink-0" />
                    <span>{{ errorFlash }}</span>
                </div>

                <button type="button" :disabled="reenviando" @click="reenviar"
                    class="group mt-8 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-500 hover:shadow-emerald-500/30 focus-visible:ring-4 focus-visible:ring-emerald-400/40 focus-visible:outline-none active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100">
                    <svg v-if="reenviando" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    {{ reenviando ? 'Enviando...' : 'Reenviar enlace' }}
                </button>

                <button type="button" :disabled="cerrandoSesion" @click="cerrarSesion"
                    class="mt-6 block w-full text-center text-sm font-medium text-neutral-500 hover:text-emerald-600 hover:underline disabled:cursor-not-allowed disabled:opacity-60 dark:text-neutral-400 dark:hover:text-emerald-400">
                    Cerrar sesión
                </button>

                <p
                    class="font-receipt mt-10 text-center text-[11px] tracking-wider text-neutral-400 dark:text-neutral-600">
                    INKAPOS <span class="text-emerald-500">/</span> SISTEMA DE PUNTO DE VENTA <span
                        class="text-emerald-500">/</span> PERÚ
                </p>
            </div>
        </div>
    </div>
</template>

<!-- Las fuentes, .ticket y la animación .anim vienen de PanelMarcaAuth (estilos globales) -->
