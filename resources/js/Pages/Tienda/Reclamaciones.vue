<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { BookOpen, CheckCircle2, ChevronDown, Clock, Send } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { claseArea, claseError, claseTarjeta } from '@/Components/Tienda/clases'

const props = defineProps({
    hojas: { type: Object, required: true },
    estado: { type: String, default: null },
    pendientes: { type: Number, default: 0 },
    plazo: { type: Number, default: 15 },
})

const FILTROS = [
    { valor: null, label: 'Todas' },
    { valor: 'pendiente', label: 'Pendientes' },
    { valor: 'respondido', label: 'Respondidas' },
]
function filtrar(valor) {
    router.get('/tienda-en-linea/reclamaciones', valor ? { estado: valor } : {}, { preserveScroll: true, replace: true })
}

const abierta = ref(props.hojas.data.find((h) => h.estado === 'pendiente')?.id ?? null)
const form = useForm({ respuesta: '' })
function responder(hoja) {
    form.post(`/tienda-en-linea/reclamaciones/${hoja.id}/responder`, { preserveScroll: true, onSuccess: () => form.reset() })
}
const soles = (n) => `S/ ${Number(n).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
</script>

<template>
    <AppLayout titulo="Libro de Reclamaciones">
        <div :class="[claseTarjeta, 'mb-4 flex flex-col gap-3 p-5 sm:flex-row sm:items-center']">
            <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><BookOpen class="size-5" /></div>
            <div class="min-w-0 flex-1">
                <h1 class="font-semibold tracking-tight">Libro de Reclamaciones de tu tienda en línea</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    Los clientes lo llenan desde el pie de tu tienda. Tienes {{ plazo }} días hábiles para responder; la respuesta le llega a su correo.
                </p>
            </div>
            <Link href="/tienda-en-linea" class="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400">Volver a Tienda en línea</Link>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="flex gap-1 rounded-xl bg-stone-100 p-1 dark:bg-neutral-800" role="tablist">
                <button
                    v-for="f in FILTROS"
                    :key="f.label"
                    type="button"
                    role="tab"
                    :aria-selected="estado === f.valor"
                    class="h-8 rounded-lg px-3.5 text-sm font-medium"
                    :class="estado === f.valor ? 'bg-white shadow-sm dark:bg-neutral-950' : 'text-neutral-500 dark:text-neutral-400'"
                    @click="filtrar(f.valor)"
                >
                    {{ f.label }}
                </button>
            </div>
            <p v-if="pendientes" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                <Clock class="size-4" />{{ pendientes }} pendiente{{ pendientes === 1 ? '' : 's' }} de respuesta
            </p>
        </div>

        <div :class="[claseTarjeta, 'overflow-hidden']">
            <p v-if="!hojas.data.length" class="px-5 py-14 text-center text-sm text-neutral-500 dark:text-neutral-400">
                <BookOpen class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                {{ estado ? 'No hay hojas en este estado.' : 'Aún no hay reclamos ni quejas. ¡Bien!' }}
            </p>
            <ul class="divide-y divide-stone-100 dark:divide-neutral-800">
                <li v-for="h in hojas.data" :key="h.id" :data-hoja="h.codigo">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3.5 text-left hover:bg-stone-50 dark:hover:bg-neutral-800/50" :aria-expanded="abierta === h.id" @click="abierta = abierta === h.id ? null : h.id">
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold tabular-nums">N.º {{ h.codigo }}</span>
                                <span class="rounded-md bg-stone-100 px-1.5 py-0.5 text-xs font-medium capitalize dark:bg-neutral-800">{{ h.tipo }}</span>
                                <span v-if="h.estado === 'respondido'" class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><CheckCircle2 class="size-3.5" />Respondido</span>
                                <span v-else class="rounded-md px-1.5 py-0.5 text-xs font-medium" :class="h.vencido ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'">
                                    {{ h.vencido ? 'Vencido' : 'Responder' }} hasta {{ h.vence }}
                                </span>
                            </span>
                            <span class="mt-0.5 block truncate text-sm text-neutral-500 dark:text-neutral-400">{{ h.fecha }} · {{ h.nombre }} · {{ h.bien }}</span>
                        </span>
                        <ChevronDown class="size-4 shrink-0 text-neutral-400 transition-transform" :class="abierta === h.id ? 'rotate-180' : ''" />
                    </button>

                    <div v-if="abierta === h.id" class="border-t border-stone-100 bg-stone-50/60 px-5 py-4 dark:border-neutral-800 dark:bg-neutral-950/40">
                        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div><dt class="text-neutral-500">Consumidor</dt><dd>{{ h.nombre }} · {{ h.documento }}</dd></div>
                            <div><dt class="text-neutral-500">Contacto</dt><dd class="break-all">{{ h.email }}<template v-if="h.telefono"> · {{ h.telefono }}</template></dd></div>
                            <div class="sm:col-span-2"><dt class="text-neutral-500">Domicilio</dt><dd>{{ h.domicilio }}</dd></div>
                            <div v-if="h.apoderado"><dt class="text-neutral-500">Apoderado (menor de edad)</dt><dd>{{ h.apoderado }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-neutral-500">Bien contratado</dt><dd>{{ h.bien }}<template v-if="h.monto !== null"> · {{ soles(h.monto) }}</template></dd></div>
                            <div class="sm:col-span-2"><dt class="text-neutral-500">Detalle</dt><dd class="whitespace-pre-line">{{ h.detalle }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-neutral-500">Pedido del consumidor</dt><dd class="whitespace-pre-line">{{ h.pedido }}</dd></div>
                        </dl>

                        <div v-if="h.estado === 'respondido'" class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm dark:bg-emerald-500/10">
                            <p class="font-medium text-emerald-800 dark:text-emerald-300">Respuesta enviada el {{ h.respondido_en }}</p>
                            <p class="mt-1 whitespace-pre-line text-emerald-950 dark:text-emerald-100">{{ h.respuesta }}</p>
                        </div>
                        <form v-else class="mt-4" @submit.prevent="responder(h)">
                            <label :for="`respuesta-${h.id}`" class="mb-1 block text-sm font-medium">Respuesta al cliente</label>
                            <textarea :id="`respuesta-${h.id}`" v-model="form.respuesta" rows="5" maxlength="5000" :class="claseArea" placeholder="Explica qué harás para solucionarlo (cambio, devolución, plazo...)." />
                            <p v-if="form.errors.respuesta" :class="claseError">{{ form.errors.respuesta }}</p>
                            <button type="submit" :disabled="form.processing" class="mt-3 inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60" data-responder>
                                <Send class="size-4" />{{ form.processing ? 'Enviando...' : 'Enviar respuesta' }}
                            </button>
                        </form>
                    </div>
                </li>
            </ul>

            <div v-if="hojas.last_page > 1" class="flex flex-wrap justify-center gap-1.5 border-t border-stone-200 px-5 py-3 text-sm dark:border-neutral-800">
                <template v-for="(link, i) in hojas.links" :key="i">
                    <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-lg border px-3 py-1.5" :class="link.active ? 'border-emerald-600 bg-emerald-600 font-semibold text-white' : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'" v-html="link.label" />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
