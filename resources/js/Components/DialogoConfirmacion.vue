<script setup>
import { TriangleAlert } from '@lucide/vue'
import { useConfirmar } from '@/composables/confirmar'

const { estado, responder } = useConfirmar()
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="estado.abierto" class="fixed inset-0 z-[60] grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="responder(false)" />

                <div class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <div class="flex items-start gap-4">
                        <div
                            class="grid size-11 shrink-0 place-items-center rounded-xl"
                            :class="estado.peligro
                                ? 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400'
                                : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'"
                        >
                            <TriangleAlert class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="font-semibold tracking-tight">{{ estado.titulo }}</h3>
                            <p v-if="estado.mensaje" class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                {{ estado.mensaje }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="responder(false)"
                        >
                            {{ estado.textoCancelar }}
                        </button>
                        <button
                            type="button"
                            class="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-colors"
                            :class="estado.peligro ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                            @click="responder(true)"
                        >
                            {{ estado.textoConfirmar }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
