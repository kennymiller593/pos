<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Eye, EyeOff, ShieldCheck, UserRound } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    cuenta: { type: Object, required: true },
})

const form = useForm({
    nombre_completo: '',
    email: '',
    password: '',
    password_confirmation: '',
})
const verPassword = ref(false)
const { confirmar } = useConfirmar()

async function migrar() {
    const ok = await confirmar({
        titulo: 'Mover el acceso de plataforma',
        mensaje: `Se creará la cuenta ${form.email} como administradora de la plataforma y ${props.cuenta.email} volverá a ser una cuenta normal de ${props.cuenta.empresa}. Se cerrará tu sesión.`,
        textoConfirmar: 'Mover acceso',
        peligro: true,
    })
    if (ok) form.post('/admin/cuenta/migrar')
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Mi cuenta">
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <!-- Cuenta actual -->
            <section class="rounded-2xl border border-[#E2E8F0] bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-center gap-3">
                    <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                        <ShieldCheck class="size-6" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">Cuenta de plataforma</h2>
                        <p class="text-sm text-[#64748B] dark:text-neutral-400">Administra empresas y planes; no opera ninguna empresa.</p>
                    </div>
                </div>
                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-[#64748B] dark:text-neutral-400">Nombre</dt>
                        <dd class="text-right font-medium">{{ cuenta.nombre }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-[#64748B] dark:text-neutral-400">Correo</dt>
                        <dd class="text-right font-medium">{{ cuenta.email }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-[#64748B] dark:text-neutral-400">Registrada en</dt>
                        <dd class="text-right font-medium">{{ cuenta.empresa }}</dd>
                    </div>
                </dl>
                <p class="mt-5 rounded-xl bg-slate-50 p-3 text-xs text-[#475569] dark:bg-neutral-950/60 dark:text-neutral-300">
                    Si este correo también lo usas para vender o administrar tu empresa, mueve el acceso de plataforma a un correo
                    aparte: así esta cuenta vuelve a tener el POS, compras y demás, y la nueva queda solo para la plataforma.
                </p>
            </section>

            <!-- Mover a otro correo -->
            <form
                class="rounded-2xl border border-[#E2E8F0] bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900"
                @submit.prevent="migrar"
            >
                <div class="flex items-center gap-3">
                    <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <UserRound class="size-6" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">Mover el acceso a otro correo</h2>
                        <p class="text-sm text-[#64748B] dark:text-neutral-400">Se crea una cuenta nueva, exclusiva de la plataforma.</p>
                    </div>
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label :class="claseLabel" for="c_nombre">Nombre *</label>
                        <input id="c_nombre" v-model="form.nombre_completo" type="text" :class="claseInput" placeholder="Administrador inkaPos" />
                        <p v-if="form.errors.nombre_completo" :class="claseError">{{ form.errors.nombre_completo }}</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="c_email">Correo nuevo *</label>
                        <input id="c_email" v-model="form.email" type="email" :class="claseInput" placeholder="plataforma@inkanet.pro" />
                        <p v-if="form.errors.email" :class="claseError">{{ form.errors.email }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" for="c_password">Contraseña *</label>
                            <div class="relative">
                                <input
                                    id="c_password"
                                    v-model="form.password"
                                    :type="verPassword ? 'text' : 'password'"
                                    autocomplete="new-password"
                                    :class="[claseInput, 'pr-10']"
                                    placeholder="Mínimo 8 caracteres"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-2 grid size-7 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                    :title="verPassword ? 'Ocultar' : 'Mostrar'"
                                    @click="verPassword = !verPassword"
                                >
                                    <EyeOff v-if="verPassword" class="size-4" />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="form.errors.password" :class="claseError">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="c_password2">Repite la contraseña *</label>
                            <input
                                id="c_password2"
                                v-model="form.password_confirmation"
                                :type="verPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                :class="claseInput"
                                placeholder="••••••••"
                            />
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-11 items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ form.processing ? 'Moviendo...' : 'Mover acceso de plataforma' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
