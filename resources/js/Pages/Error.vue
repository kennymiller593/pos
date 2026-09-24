<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { AlertTriangle, Lock, SearchX, ServerCrash, Wrench } from '@lucide/vue'

const props = defineProps({
    estado: { type: Number, required: true },
})

const TEXTOS = {
    403: { titulo: 'No tienes permiso para esto', detalle: 'Tu rol no puede acceder a esta sección. Si crees que es un error, pídeselo a un administrador.', icono: Lock },
    404: { titulo: 'No encontramos esa página', detalle: 'El enlace puede estar mal escrito o el registro ya no existe.', icono: SearchX },
    500: { titulo: 'Algo salió mal', detalle: 'Ocurrió un error inesperado. Ya quedó registrado; intenta de nuevo en un momento.', icono: ServerCrash },
    503: { titulo: 'Estamos en mantenimiento', detalle: 'Volvemos en unos minutos. Tus ventas registradas están a salvo.', icono: Wrench },
}

const texto = computed(() => TEXTOS[props.estado] ?? { titulo: 'Algo salió mal', detalle: 'Intenta de nuevo en un momento.', icono: AlertTriangle })
</script>

<template>
    <div class="grid min-h-screen place-items-center bg-stone-100 p-6 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
        <div class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-8 text-center dark:border-neutral-800 dark:bg-neutral-900">
            <component :is="texto.icono" class="mx-auto mb-4 size-12 text-emerald-600 dark:text-emerald-400" />
            <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">Error {{ estado }}</p>
            <h1 class="mt-1 text-xl font-bold tracking-tight">{{ texto.titulo }}</h1>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ texto.detalle }}</p>
            <div class="mt-6 flex justify-center gap-2">
                <button
                    type="button"
                    class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    @click="history.back()"
                >
                    Volver
                </button>
                <Link
                    href="/dashboard"
                    class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                >
                    Ir al inicio
                </Link>
            </div>
        </div>
    </div>
</template>
