<script setup>
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Eye, EyeOff, Lock } from '@lucide/vue'
import PanelMarcaAuth from '@/Components/PanelMarcaAuth.vue'

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
})

const page = usePage()
const errorFlash = computed(() => page.props.flash?.error ?? null)

const verPassword = ref(false)

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

function enviar() {
    form.post('/restablecer-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <div class="flex min-h-screen bg-stone-100 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <PanelMarcaAuth />

        <div class="flex w-full items-center justify-center p-6 lg:w-1/2">
            <div class="w-full max-w-sm">
                <!-- Logo solo móvil -->
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <div class="grid size-10 place-items-center rounded-xl bg-neutral-900 text-lg font-bold text-emerald-400 dark:bg-emerald-500 dark:text-white">
                        P
                    </div>
                    <span class="text-xl font-semibold tracking-tight">{{ $page.props.appName || 'inkaPos' }}</span>
                </div>

                <h1 class="text-3xl font-bold tracking-tight">Nueva contraseña</h1>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">
                    Elige una contraseña nueva para <span class="font-medium">{{ email }}</span>.
                </p>

                <div
                    v-if="errorFlash"
                    class="mt-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-400"
                >
                    {{ errorFlash }}
                    <Link href="/olvide-password" class="ml-1 font-medium underline">Solicitar otro enlace</Link>
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="enviar">
                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium">Nueva contraseña</label>
                        <div class="relative">
                            <Lock class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                id="password"
                                v-model="form.password"
                                :type="verPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                required
                                autofocus
                                placeholder="Mínimo 8 caracteres"
                                class="h-11 w-full rounded-xl border border-stone-300 bg-white pr-11 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:placeholder-neutral-500"
                            />
                            <button
                                type="button"
                                class="absolute top-1/2 right-3 -translate-y-1/2 rounded-md p-0.5 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300"
                                @click="verPassword = !verPassword"
                            >
                                <EyeOff v-if="verPassword" class="size-4" />
                                <Eye v-else class="size-4" />
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <div>
                        <label for="password2" class="mb-1.5 block text-sm font-medium">Repite la contraseña</label>
                        <div class="relative">
                            <Lock class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                id="password2"
                                v-model="form.password_confirmation"
                                :type="verPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                required
                                placeholder="••••••••"
                                class="h-11 w-full rounded-xl border border-stone-300 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:placeholder-neutral-500"
                            />
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-11 w-full items-center justify-center rounded-xl bg-emerald-600 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar y entrar' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
