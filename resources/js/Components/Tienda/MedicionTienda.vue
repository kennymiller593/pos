<script setup>
import { BarChart3, CheckCircle2 } from '@lucide/vue'
import { claseAyuda, claseError, claseInput, claseLabel, claseTarjeta } from './clases'

const props = defineProps({
    form: { type: Object, required: true },
    // lo guardado: identificadores ya reconocidos (para mostrar "Conectado")
    guardado: { type: Object, required: true },
})

// qué es cada herramienta y dónde consigue el dueño su código
const HERRAMIENTAS = [
    {
        campo: 'medicion_ga4', nombre: 'Google Analytics 4', ejemplo: 'G-ABC1234XYZ',
        para: 'Cuántas personas visitan tu tienda, de dónde llegan, qué miran y cuántas piden por WhatsApp.',
        donde: 'En analytics.google.com: Administrar → Flujos de datos → tu sitio → copia el "ID de medición" (empieza con G-), o pega el código completo de la etiqueta de Google.',
    },
    {
        campo: 'medicion_meta', nombre: 'Meta Pixel (Facebook e Instagram)', ejemplo: '1662429348886201',
        para: 'Mide qué productos ven y piden las personas que llegan desde tus anuncios de Facebook e Instagram, para optimizarlos.',
        donde: 'En business.facebook.com: Administrador de eventos → Orígenes de datos → tu píxel → "Instalar código manualmente". Pega aquí todo el código o solo el número del píxel.',
    },
    {
        campo: 'medicion_tiktok', nombre: 'TikTok Pixel', ejemplo: 'C1A2B3C4D5E6F7G8H9I0',
        para: 'Lo mismo que el Meta Pixel, para tus anuncios en TikTok.',
        donde: 'En ads.tiktok.com: Herramientas → Eventos → Web → tu píxel → "Instalar código manualmente". Pega el código o el ID del píxel.',
    },
    {
        campo: 'medicion_clarity', nombre: 'Microsoft Clarity', ejemplo: 'abc123xyz9',
        para: 'Gratis. Graba cómo navegan los visitantes (sin datos personales) y muestra mapas de calor: dónde hacen clic, hasta dónde bajan, dónde se traban. Ideal para ver por qué no piden.',
        donde: 'En clarity.microsoft.com crea un proyecto con la dirección de tu tienda, elige "Instalar manualmente" y pega el código o el ID del proyecto.',
    },
    {
        campo: 'medicion_google_verificacion', nombre: 'Google Search Console', ejemplo: 'AbCdEf...',
        para: 'Para que Google te muestre en qué búsquedas aparece tu tienda, qué páginas indexó y si hay errores. No mide visitas: es la conexión con el buscador.',
        donde: 'En search.google.com/search-console: agrega la dirección de tu tienda como "Prefijo de URL", elige verificar con "Etiqueta HTML" y pega aquí la etiqueta o el código. Después de guardar, vuelve a Search Console y pulsa "Verificar".',
    },
]
</script>

<template>
    <div class="grid gap-4">
        <section :class="[claseTarjeta, 'p-5']" aria-labelledby="titulo-medicion" data-medicion>
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><BarChart3 class="size-5" /></span>
                <div>
                    <h2 id="titulo-medicion" class="font-semibold tracking-tight">Medición y anuncios</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Conecta las herramientas con las que quieras medir tu tienda. Pega el código completo que te da cada plataforma o solo su identificador: nosotros reconocemos el ID y lo instalamos en todas las páginas. Lo que tus clientes hacen (ver un producto, buscar, agregar al carrito, pedir por WhatsApp) se envía como eventos a cada herramienta.
                    </p>
                </div>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div v-for="h in HERRAMIENTAS" :key="h.campo" class="rounded-xl border border-stone-200 p-4 dark:border-neutral-800" :data-herramienta="h.campo">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label :class="[claseLabel, 'mb-0']" :for="h.campo">{{ h.nombre }}</label>
                        <span v-if="guardado[h.campo]" class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <CheckCircle2 class="size-3.5" />Conectado: {{ guardado[h.campo] }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ h.para }}</p>
                    <input
                        :id="h.campo"
                        v-model="form[h.campo]"
                        type="text"
                        :placeholder="`Pega el código o el ID, por ejemplo ${h.ejemplo}`"
                        autocomplete="off"
                        spellcheck="false"
                        :class="[claseInput, 'mt-3 font-mono text-[13px]']"
                    />
                    <p v-if="form.errors[h.campo]" :class="claseError">{{ form.errors[h.campo] }}</p>
                    <p v-else :class="claseAyuda">{{ h.donde }}</p>
                </div>
            </div>

            <p class="mt-5 rounded-xl bg-stone-50 px-4 py-3 text-xs leading-relaxed text-neutral-500 dark:bg-neutral-950/50 dark:text-neutral-400">
                Para desconectar una herramienta, borra su casilla y guarda. Las herramientas no se cargan en la vista previa, solo en tu tienda publicada. Recuerda que, si usas píxeles de anuncios, tu política de privacidad debe mencionarlo.
            </p>
        </section>
    </div>
</template>
