<script setup>
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { ArrowLeft, CheckCircle2, Mail } from '@lucide/vue'
import PanelMarcaAuth from '@/Components/PanelMarcaAuth.vue'

const page = usePage()
const enviado = computed(() => page.props.flash?.success ?? null)

const form = useForm({
    email: '',
})

function enviar() {
    form.post('/olvide-password')
}
</script>

<template>
    <div class="flex min-h-screen bg-neutral-950 text-neutral-100 dark:bg-neutral-950 dark:text-neutral-100">
        <PanelMarcaAuth
            eyebrow="<recuperar_acceso />"
            titulo="Tranquilo,"
            destacado="pasa hasta en las mejores cajas."
            descripcion="Te enviamos un enlace a tu correo y en un minuto estás vendiendo de nuevo."
        />

        <div class="flex w-full items-center justify-center p-6 lg:w-1/2">
            <div class="anim w-full max-w-sm">
                <!-- Logo solo móvil -->
                <div class="mb-10 flex justify-center lg:hidden">
                    <div>
                        <img :src="'/images/inkanet_logo.png'" alt="inkaPos" class="h-14 w-auto" />
                    </div>
                </div>

                <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-600 uppercase dark:text-emerald-400">
                    &lt;recuperar_acceso /&gt;
                </p>
                <h1 class="font-display mt-3 text-3xl font-bold tracking-tight">¿Olvidaste tu contraseña?</h1>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">
                    Escribe tu correo y te enviaremos un enlace para elegir una nueva.
                </p>

                <div
                    v-if="enviado"
                    class="mt-6 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
                >
                    <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                    <span>{{ enviado }}</span>
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="enviar">
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-medium">Correo</label>
                        <div class="relative">
                            <Mail class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                required
                                autofocus
                                placeholder="tucorreo@negocio.com"
                                class="h-12 w-full rounded-xl border bg-white pr-4 pl-10 text-sm placeholder-neutral-400 shadow-sm transition-shadow focus:border-emerald-500 focus:ring-4 focus:ring-emerald-400/20 focus:outline-none dark:bg-neutral-900 dark:placeholder-neutral-500"
                                :class="form.errors.email
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-neutral-200 dark:border-neutral-700'"
                            />
                        </div>
                        <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-500 hover:shadow-emerald-500/30 focus-visible:ring-4 focus-visible:ring-emerald-400/40 focus-visible:outline-none active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
                    >
                        <svg v-if="form.processing" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        {{ form.processing ? 'Enviando...' : 'Enviar enlace' }}
                    </button>
                </form>

                <Link
                    href="/login"
                    class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 hover:underline dark:text-emerald-400"
                >
                    <ArrowLeft class="size-4" />
                    Volver al inicio de sesión
                </Link>

                <p class="font-receipt mt-10 text-center text-[11px] tracking-wider text-neutral-400 dark:text-neutral-600">
                    INKAPOS <span class="text-emerald-500">/</span> SISTEMA DE PUNTO DE VENTA <span class="text-emerald-500">/</span> PERÚ
                </p>
            </div>
        </div>
    </div>
</template>

<!-- Las fuentes y la animación .anim vienen del componente PanelMarcaAuth (estilos globales) -->