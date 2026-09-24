<script setup>
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { CheckCircle2, Eye, EyeOff, Lock, Mail } from '@lucide/vue'
import PanelMarcaAuth from '@/Components/PanelMarcaAuth.vue'

const page = usePage()
const exitoFlash = computed(() => page.props.flash?.success ?? null)

const form = useForm({
    email: '',
    password: '',
})

const verPassword = ref(false)

function enviar() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <div class="flex min-h-screen bg-[#F5F9F6] text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">

        <!-- Panel de marca compartido: usa los textos por defecto (los del login) -->
        <PanelMarcaAuth />

        <!-- ============ Panel del formulario ============ -->
        <div class="relative flex w-full items-center justify-center p-6 lg:w-1/2">
            <div class="anim w-full max-w-sm">

                <!-- Logo solo móvil -->
                <div class="mb-10 flex justify-center lg:hidden">
                    <div class="rounded-2xl bg-[#050807] px-5 py-4">
                        <img :src="'/images/falcontech_oscuro.png'" alt="FalconTech" class="h-8 w-auto" />
                    </div>
                </div>

                <p class="font-receipt text-[12px] tracking-[0.18em] text-emerald-600 uppercase dark:text-emerald-400">
                    &lt;iniciar_sesión /&gt;
                </p>
                <h1 class="font-display mt-3 text-3xl font-bold tracking-tight">Bienvenido de nuevo</h1>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">
                    ¿Aún no tienes cuenta?
                    <Link href="/registro" class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                        Registra tu negocio</Link>
                </p>

                <div v-if="exitoFlash"
                    class="mt-6 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
                    <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                    <span>{{ exitoFlash }}</span>
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="enviar">
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-medium">Correo</label>
                        <div class="relative">
                            <Mail
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                            <input id="email" v-model="form.email" type="email" autocomplete="email" required autofocus
                                placeholder="tucorreo@negocio.com"
                                class="h-12 w-full rounded-xl border bg-white pr-4 pl-10 text-sm placeholder-neutral-400 shadow-sm transition-shadow focus:border-emerald-500 focus:ring-4 focus:ring-emerald-400/20 focus:outline-none dark:bg-neutral-900 dark:placeholder-neutral-500"
                                :class="form.errors.email
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-neutral-200 dark:border-neutral-700'" />
                        </div>
                        <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <label for="password" class="block text-sm font-medium">Contraseña</label>
                            <Link href="/olvide-password"
                                class="text-sm font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                ¿La olvidaste?
                            </Link>
                        </div>
                        <div class="relative">
                            <Lock
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                            <input id="password" v-model="form.password" :type="verPassword ? 'text' : 'password'"
                                autocomplete="current-password" required placeholder="••••••••"
                                class="h-12 w-full rounded-xl border bg-white pr-11 pl-10 text-sm placeholder-neutral-400 shadow-sm transition-shadow focus:border-emerald-500 focus:ring-4 focus:ring-emerald-400/20 focus:outline-none dark:bg-neutral-900 dark:placeholder-neutral-500"
                                :class="form.errors.password
                                    ? 'border-red-400 dark:border-red-500'
                                    : 'border-neutral-200 dark:border-neutral-700'" />
                            <button type="button"
                                class="absolute top-1/2 right-3 -translate-y-1/2 rounded-md p-1 text-neutral-400 transition-colors hover:text-neutral-600 focus-visible:ring-2 focus-visible:ring-emerald-400 focus-visible:outline-none dark:hover:text-neutral-300"
                                :title="verPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                @click="verPassword = !verPassword">
                                <EyeOff v-if="verPassword" class="size-4" />
                                <Eye v-else class="size-4" />
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-500 hover:shadow-emerald-500/30 focus-visible:ring-4 focus-visible:ring-emerald-400/40 focus-visible:outline-none active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100">
                        <svg v-if="form.processing" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        {{ form.processing ? 'Ingresando...' : 'Ingresar' }}
                    </button>
                </form>

                <p
                    class="font-receipt mt-10 text-center text-[11px] tracking-wider text-neutral-400 dark:text-neutral-600">
                    FALCONTECH <span class="text-emerald-500">/</span> SISTEMA DE PUNTO DE VENTA <span
                        class="text-emerald-500">/</span> PERÚ
                </p>
            </div>
        </div>
    </div>
</template>

<!-- Las fuentes, .ticket y la animación .anim vienen de PanelMarcaAuth (estilos globales) -->