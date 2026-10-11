<script setup>
// Configuración de una billetera (Yape o Plin) en un ámbito: toda la empresa o una sucursal.
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Check, ImagePlus, Trash2 } from '@lucide/vue'
import PanelBilletera from '@/Components/Pos/PanelBilletera.vue'

const props = defineProps({
    // 'empresa' o el id de la sucursal
    ambito: { type: String, required: true },
    billetera: { type: Object, required: true },
    // lo guardado en este ámbito ({ qr_url, numero, titular } o null)
    propio: { type: Object, default: null },
    // lo de la empresa, que la sucursal usa mientras no tenga el suyo
    heredado: { type: Object, default: null },
})

const form = useForm({
    ambito: props.ambito,
    medio: props.billetera.codigo,
    numero: props.propio?.numero ?? '',
    titular: props.propio?.titular ?? '',
    qr: null,
    qr_eliminar: false,
})

const entrada = ref(null)
const vistaLocal = ref(null)
const arrastrando = ref(false)

const imagen = computed(() => vistaLocal.value ?? (form.qr_eliminar ? null : props.propio?.qr_url) ?? null)
const esSucursal = computed(() => props.ambito !== 'empresa')

// lo que verá el cajero con lo que hay ahora en el formulario
const vistaPrevia = computed(() => {
    const numero = form.numero.replace(/\D+/g, '')
    if (imagen.value || numero) return { qr_url: imagen.value, numero: numero || null, titular: form.titular.trim() || null }
    return esSucursal.value ? props.heredado : null
})
const usaHeredado = computed(() => esSucursal.value && !imagen.value && !form.numero.trim() && !!props.heredado)

function tomar(archivo) {
    if (!archivo || !archivo.type.startsWith('image/')) return
    form.qr = archivo
    form.qr_eliminar = false
    if (vistaLocal.value) URL.revokeObjectURL(vistaLocal.value)
    vistaLocal.value = URL.createObjectURL(archivo)
}

function quitarImagen() {
    form.qr = null
    form.qr_eliminar = true
    if (vistaLocal.value) URL.revokeObjectURL(vistaLocal.value)
    vistaLocal.value = null
    if (entrada.value) entrada.value.value = ''
}

function guardar() {
    form.post('/empresa/cobros-qr', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.qr = null
            form.qr_eliminar = false
            if (vistaLocal.value) URL.revokeObjectURL(vistaLocal.value)
            vistaLocal.value = null
            if (entrada.value) entrada.value.value = ''
            form.defaults()
        },
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const punto = { yape: 'bg-[#742284]', plin: 'bg-[#0891b2]' }
</script>

<template>
    <form
        class="rounded-2xl border border-stone-200 bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900"
        :data-billetera="billetera.codigo"
        @submit.prevent="guardar"
    >
        <div class="mb-5 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-xl text-sm font-bold text-white" :class="punto[billetera.codigo]">
                    {{ billetera.nombre[0] }}
                </span>
                <div>
                    <h2 class="font-semibold tracking-tight">{{ billetera.nombre }}</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        {{ propio ? 'Configurado' : usaHeredado ? 'Usa el de la empresa' : 'Sin configurar' }}
                    </p>
                </div>
            </div>
            <span
                v-if="propio"
                class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-400 dark:ring-emerald-900"
            >
                <Check class="size-3.5" />
                Activo
            </span>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="space-y-4">
                <!-- imagen del QR -->
                <div>
                    <p :class="claseLabel">Imagen del QR</p>
                    <div
                        class="flex items-center gap-4 rounded-xl border-2 border-dashed p-3 transition-colors"
                        :class="arrastrando ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30' : 'border-stone-300 dark:border-neutral-700'"
                        @dragover.prevent="arrastrando = true"
                        @dragleave.prevent="arrastrando = false"
                        @drop.prevent="arrastrando = false; tomar($event.dataTransfer.files?.[0])"
                    >
                        <button
                            type="button"
                            class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50 text-neutral-400 hover:text-emerald-600 dark:border-neutral-800 dark:bg-neutral-950"
                            :title="imagen ? 'Cambiar imagen' : 'Subir imagen'"
                            @click="entrada.click()"
                        >
                            <img v-if="imagen" :src="imagen" alt="" class="size-full object-contain" />
                            <ImagePlus v-else class="size-7" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center rounded-xl border border-stone-300 px-3 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    @click="entrada.click()"
                                >
                                    {{ imagen ? 'Cambiar' : 'Subir QR' }}
                                </button>
                                <button
                                    v-if="imagen"
                                    type="button"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-xl px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                    @click="quitarImagen"
                                >
                                    <Trash2 class="size-4" />
                                    Quitar
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-neutral-400 dark:text-neutral-500">JPG, PNG o WEBP de hasta 4 MB. También puedes arrastrarla aquí.</p>
                        </div>
                        <input ref="entrada" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="tomar($event.target.files?.[0])" />
                    </div>
                    <p v-if="form.errors.qr" :class="claseError">{{ form.errors.qr }}</p>
                </div>

                <div>
                    <label :class="claseLabel" :for="`numero-${billetera.codigo}`">Celular <span class="font-normal text-neutral-400">(opcional)</span></label>
                    <div class="flex">
                        <span class="grid h-10 place-items-center rounded-l-xl border border-r-0 border-stone-300 bg-stone-50 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">+51</span>
                        <input
                            :id="`numero-${billetera.codigo}`"
                            v-model="form.numero"
                            type="tel"
                            inputmode="numeric"
                            maxlength="11"
                            placeholder="987 654 321"
                            @input="form.clearErrors('numero')"
                            :class="[claseInput, 'rounded-l-none']"
                        />
                    </div>
                    <p v-if="form.errors.numero" :class="claseError">{{ form.errors.numero }}</p>
                    <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Para el cliente que prefiere pagar al número en vez de escanear.</p>
                </div>

                <div>
                    <label :class="claseLabel" :for="`titular-${billetera.codigo}`">Nombre del titular <span class="font-normal text-neutral-400">(opcional)</span></label>
                    <input :id="`titular-${billetera.codigo}`" v-model="form.titular" type="text" maxlength="80" placeholder="Como aparece al pagar" :class="claseInput" />
                    <p v-if="form.errors.titular" :class="claseError">{{ form.errors.titular }}</p>
                </div>
            </div>

            <!-- vista previa -->
            <div>
                <p :class="claseLabel">Así lo verá tu cajero al cobrar</p>
                <PanelBilletera :codigo="billetera.codigo" :nombre="billetera.nombre" :datos="vistaPrevia" :monto="vistaPrevia ? 25.5 : null">
                    <template #vacio>Sube el QR o escribe el celular para verlo aquí.</template>
                </PanelBilletera>
                <p v-if="usaHeredado" class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                    Esta sucursal no tiene uno propio: se muestra el de la empresa.
                </p>
            </div>
        </div>

        <p v-if="form.errors.ambito || form.errors.medio" :class="claseError">{{ form.errors.ambito || form.errors.medio }}</p>

        <div class="mt-5 flex justify-end">
            <button
                type="submit"
                class="inline-flex h-10 items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="form.processing || !form.isDirty"
            >
                {{ form.processing ? 'Guardando...' : `Guardar ${billetera.nombre}` }}
            </button>
        </div>
    </form>
</template>
