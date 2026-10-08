<script setup>
import { ExternalLink, Plus, Trash2 } from '@lucide/vue'
import { claseArea, claseAyuda, claseError, claseInput, claseLabel, claseTarjeta } from './clases'

const props = defineProps({
    // el formulario de la tienda: aquí se editan los textos y form.preguntas
    form: { type: Object, required: true },
    // dirección de la tienda ya publicada (para abrir la página), o null
    url: { type: String, default: null },
    // lo que está guardado hoy: una página solo existe si su texto ya se guardó
    guardado: { type: Object, required: true },
    maxPreguntas: { type: Number, default: 12 },
})

const TEXTOS = [
    {
        campo: 'nosotros', titulo: 'Sobre nosotros', max: 3000, filas: 6, pagina: '/nosotros',
        ayuda: 'Quiénes son, desde cuándo atienden, qué los hace distintos. Un adelanto sale también en la portada.',
        ejemplo: 'Somos una agroveterinaria familiar con más de 10 años atendiendo a los agricultores de la zona...',
    },
    {
        campo: 'envios', titulo: 'Envíos y entregas', max: 2000, filas: 4, pagina: '/politicas',
        ayuda: 'A dónde envías, cuánto demora, cuánto cuesta y si se puede recoger en tienda.',
        ejemplo: 'Hacemos envíos a toda la provincia por agencia. Los pedidos confirmados antes del mediodía salen el mismo día...',
    },
    {
        campo: 'devoluciones', titulo: 'Cambios y devoluciones', max: 2000, filas: 4, pagina: '/politicas',
        ayuda: 'En qué casos aceptas un cambio, en cuántos días y qué debe presentar el cliente.',
        ejemplo: 'Aceptamos cambios dentro de los 7 días con el comprobante y el producto sellado...',
    },
    {
        campo: 'pagos', titulo: 'Formas de pago', max: 1000, filas: 3, pagina: '/politicas',
        ayuda: 'Efectivo, Yape, Plin, transferencia, tarjeta...',
        ejemplo: 'Aceptamos efectivo, Yape, Plin y transferencia bancaria.',
    },
]

const enlace = (campo, pagina) => (props.url && props.guardado[campo] ? props.url.replace(/\/$/, '') + pagina : null)

function agregarPregunta() {
    if (props.form.preguntas.length < props.maxPreguntas) props.form.preguntas.push({ clave: Math.random().toString(36).slice(2), pregunta: '', respuesta: '' })
}

function quitarPregunta(indice) {
    props.form.preguntas.splice(indice, 1)
    props.form.clearErrors()
}

const error = (indice, campo) => props.form.errors[`preguntas.${indice}.${campo}`]
</script>

<template>
    <div class="grid gap-4">
        <section :class="[claseTarjeta, 'p-5']" aria-labelledby="titulo-contenido">
            <h2 id="titulo-contenido" class="font-semibold tracking-tight">Páginas de tu tienda</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Lo que llenes aparece como una página propia, enlazada al pie de tu tienda. Lo que dejes vacío no se muestra. Separa los párrafos con una línea en blanco.
            </p>

            <div class="mt-4 grid gap-5 lg:grid-cols-2">
                <div v-for="t in TEXTOS" :key="t.campo" :class="t.campo === 'nosotros' ? 'lg:col-span-2' : ''">
                    <div class="mb-1 flex items-baseline justify-between gap-3">
                        <label class="text-sm font-medium" :for="`contenido_${t.campo}`">{{ t.titulo }} <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <a
                            v-if="enlace(t.campo, t.pagina)"
                            :href="enlace(t.campo, t.pagina)"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                        >
                            Ver página
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                    <textarea :id="`contenido_${t.campo}`" v-model="form[t.campo]" :rows="t.filas" :maxlength="t.max" :class="claseArea" :placeholder="t.ejemplo" />
                    <p v-if="form.errors[t.campo]" :class="claseError">{{ form.errors[t.campo] }}</p>
                    <p v-else :class="claseAyuda">{{ (form[t.campo] ?? '').length }} de {{ t.max }}. {{ t.ayuda }}</p>
                </div>
            </div>
        </section>

        <section :class="[claseTarjeta, 'p-5']" aria-labelledby="titulo-preguntas">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="titulo-preguntas" class="font-semibold tracking-tight">Preguntas frecuentes</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Lo que tus clientes siempre te preguntan por WhatsApp, ya respondido.
                        <a
                            v-if="url && guardado.preguntas?.length"
                            :href="url.replace(/\/$/, '') + '/preguntas-frecuentes'"
                            target="_blank"
                            rel="noopener"
                            class="font-medium text-emerald-700 hover:underline dark:text-emerald-400"
                        >Ver página</a>
                    </p>
                </div>
                <button
                    v-if="form.preguntas.length < maxPreguntas"
                    type="button"
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-semibold transition-colors hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    @click="agregarPregunta"
                >
                    <Plus class="size-4" />
                    Agregar pregunta
                </button>
            </div>
            <p v-if="form.errors.preguntas" :class="[claseError, 'mt-3']">{{ form.errors.preguntas }}</p>

            <p v-if="!form.preguntas.length" class="mt-4 rounded-xl bg-stone-50 px-4 py-6 text-center text-sm text-neutral-500 dark:bg-neutral-800/60 dark:text-neutral-400">
                Aún no tienes preguntas. Por ejemplo: "¿Hacen envíos a provincia?", "¿Venden por mayor?", "¿Dan factura?".
            </p>

            <ul v-else class="mt-4 space-y-3">
                <li v-for="(p, i) in form.preguntas" :key="p.clave" class="flex items-start gap-3 rounded-2xl border border-stone-200 p-3 sm:p-4 dark:border-neutral-800" data-pregunta>
                    <span class="mt-2 grid size-6 shrink-0 place-items-center rounded-lg bg-stone-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ i + 1 }}</span>
                    <div class="grid min-w-0 flex-1 gap-2">
                        <div>
                            <label class="sr-only" :for="`pregunta_${p.clave}`">Pregunta {{ i + 1 }}</label>
                            <input :id="`pregunta_${p.clave}`" v-model="p.pregunta" type="text" maxlength="160" :class="claseInput" placeholder="¿Hacen envíos a provincia?" />
                            <p v-if="error(i, 'pregunta')" :class="claseError">{{ error(i, 'pregunta') }}</p>
                        </div>
                        <div>
                            <label class="sr-only" :for="`respuesta_${p.clave}`">Respuesta {{ i + 1 }}</label>
                            <textarea :id="`respuesta_${p.clave}`" v-model="p.respuesta" rows="2" maxlength="1000" :class="claseArea" placeholder="Sí, enviamos por agencia a todo el país..." />
                            <p v-if="error(i, 'respuesta')" :class="claseError">{{ error(i, 'respuesta') }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                        :aria-label="`Quitar la pregunta ${i + 1}`"
                        title="Quitar"
                        @click="quitarPregunta(i)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
