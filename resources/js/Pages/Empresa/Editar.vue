<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Building2, ChevronRight, FileKey, FlaskConical, ImageIcon, QrCode, Trash2, Upload } from '@lucide/vue'
import { claseInterruptor } from '@/Components/Tienda/clases'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    empresa: { type: Object, required: true },
    rubros: { type: Array, required: true },
})

const form = useForm({
    _method: 'put', // los archivos deben viajar por POST (limitacion de PHP con multipart)
    razon_social: props.empresa.razon_social,
    nombre_comercial: props.empresa.nombre_comercial ?? '',
    regimen_tributario: props.empresa.regimen_tributario,
    rubro_codigo: props.empresa.rubro_codigo,
    logo: null,
    logo_eliminar: false,
    cuentas_bancarias: props.empresa.cuentas_bancarias ?? '',
    pie_cotizacion: props.empresa.pie_cotizacion ?? '',
    mezclas_activo: !!props.empresa.mezclas_activo,
})

const inputLogo = ref(null)

function seleccionarLogo(evento) {
    const archivo = evento.target.files?.[0]
    if (!archivo) return
    form.logo = archivo
    form.logo_eliminar = false
}

function quitarLogo() {
    form.logo = null
    form.logo_eliminar = true
    if (inputLogo.value) inputLogo.value.value = ''
}

// vista previa: archivo recien elegido > logo guardado > nada
const vistaPrevia = computed(() => {
    if (form.logo) return URL.createObjectURL(form.logo)
    if (!form.logo_eliminar && props.empresa.logo_url) return props.empresa.logo_url
    return null
})

function enviar() {
    form.transform((data) => ({
        ...data,
        nombre_comercial: data.nombre_comercial || null,
        cuentas_bancarias: data.cuentas_bancarias.trim() || null,
        pie_cotizacion: data.pie_cotizacion.trim() || null,
    })).post('/empresa', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('logo', 'logo_eliminar')
            if (inputLogo.value) inputLogo.value.value = ''
        },
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'

</script>

<template>
    <AppLayout titulo="Empresa">
        <form class="mx-auto max-w-3xl" @submit.prevent="enviar">
            <!-- Datos generales -->
            <div class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="mb-5 flex items-center gap-3">
                    <div class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <Building2 class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-semibold tracking-tight">Datos generales</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Información fiscal y comercial de tu negocio.</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="claseLabel" for="ruc">RUC</label>
                        <input
                            id="ruc"
                            :value="empresa.ruc"
                            type="text"
                            disabled
                            class="h-10 w-full cursor-not-allowed rounded-xl border border-stone-200 bg-stone-100 px-3 text-sm text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-400"
                        />
                        <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">El RUC no se puede modificar.</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="rubro">Rubro *</label>
                        <select id="rubro" v-model="form.rubro_codigo" :class="claseInput">
                            <option v-for="r in rubros" :key="r.codigo" :value="r.codigo">{{ r.nombre }}</option>
                        </select>
                        <p v-if="form.errors.rubro_codigo" :class="claseError">{{ form.errors.rubro_codigo }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="razon_social">Razón social *</label>
                        <input id="razon_social" v-model="form.razon_social" type="text" :class="claseInput" />
                        <p v-if="form.errors.razon_social" :class="claseError">{{ form.errors.razon_social }}</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="nombre_comercial">Nombre comercial</label>
                        <input id="nombre_comercial" v-model="form.nombre_comercial" type="text" :class="claseInput" />
                    </div>
                    <div>
                        <label :class="claseLabel" for="regimen">Régimen tributario *</label>
                        <select id="regimen" v-model="form.regimen_tributario" :class="claseInput">
                            <option value="RUS">Nuevo RUS (solo boletas, sin IGV)</option>
                            <option value="RER">RER</option>
                            <option value="MYPE">MYPE Tributario</option>
                            <option value="GENERAL">Régimen General</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="cuentas_bancarias">Cuentas para el pago <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <textarea
                            id="cuentas_bancarias"
                            v-model="form.cuentas_bancarias"
                            rows="3"
                            maxlength="500"
                            :class="[claseInput, 'h-auto py-2']"
                            placeholder="BCP Soles 191-1234567-0-12 · CCI 002-191-001234567012-55&#10;Yape / Plin 999 999 999"
                        />
                        <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Se imprimen al pie de tus cotizaciones para que el cliente sepa dónde pagar. Una cuenta por línea.</p>
                        <p v-if="form.errors.cuentas_bancarias" :class="claseError">{{ form.errors.cuentas_bancarias }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="pie_cotizacion">Texto al pie de la cotización <span class="font-normal text-neutral-400">(opcional)</span></label>
                        <textarea
                            id="pie_cotizacion"
                            v-model="form.pie_cotizacion"
                            rows="4"
                            maxlength="1000"
                            :class="[claseInput, 'h-auto py-2']"
                            placeholder="GRACIAS POR SU PREFERENCIA&#10;UNA VEZ SALIDA LA MERCADERÍA NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES&#10;Sin otro particular y a la espera de sus gratas órdenes, quedamos de usted.&#10;Atentamente,"
                            data-pie-cotizacion
                        />
                        <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Se imprime al final de tus cotizaciones. Las líneas escritas en MAYÚSCULAS salen centradas y en negrita.</p>
                        <p v-if="form.errors.pie_cotizacion" :class="claseError">{{ form.errors.pie_cotizacion }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <Link
                            href="/empresa/facturacion-electronica"
                            class="group flex items-center gap-3 rounded-xl border border-stone-200 p-3 transition-colors hover:border-emerald-500 hover:bg-emerald-50/50 dark:border-neutral-800 dark:hover:border-emerald-600 dark:hover:bg-emerald-950/20"
                            data-ir-facturacion
                        >
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <FileKey class="size-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">Facturación electrónica (SUNAT)</span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">Clave SOL, certificado digital y guías de remisión. Se configura aparte.</span>
                            </span>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="empresa.facturacion_electronica
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                    : 'bg-stone-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'"
                            >
                                {{ empresa.facturacion_electronica ? (empresa.entorno_sunat === 'produccion' ? 'Activa' : 'Activa · Beta') : 'Desactivada' }}
                            </span>
                            <ChevronRight class="size-5 shrink-0 text-neutral-400 transition-transform group-hover:translate-x-0.5 group-hover:text-emerald-600" />
                        </Link>
                    </div>
                    <div class="sm:col-span-2">
                        <Link
                            href="/empresa/cobros-qr"
                            class="group flex items-center gap-3 rounded-xl border border-stone-200 p-3 transition-colors hover:border-emerald-500 hover:bg-emerald-50/50 dark:border-neutral-800 dark:hover:border-emerald-600 dark:hover:bg-emerald-950/20"
                        >
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <QrCode class="size-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">Cobros con QR · Yape y Plin</span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">Sube tu QR para mostrarlo al cliente cuando cobras en el punto de venta.</span>
                            </span>
                            <ChevronRight class="size-5 shrink-0 text-neutral-400 transition-transform group-hover:translate-x-0.5 group-hover:text-emerald-600" />
                        </Link>
                    </div>
                    <!-- módulo opcional: mezclas y preparados -->
                    <div class="sm:col-span-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-stone-200 p-3 transition-colors has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50 dark:border-neutral-800 dark:has-[:checked]:border-emerald-600 dark:has-[:checked]:bg-emerald-950/20">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <FlaskConical class="size-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">Mezclas y preparados</span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">Actívalo si combinas productos del stock para obtener otro que vendes (mezclas de fertilizantes, packs, kits). Los insumos salen del stock a su costo real y lo obtenido entra con el costo calculado, sin registrar ventas ni compras.</span>
                            </span>
                            <input v-model="form.mezclas_activo" type="checkbox" class="peer sr-only" data-mezclas-activo />
                            <span :class="[claseInterruptor, 'mt-2']" />
                        </label>
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel">Logo</label>
                        <div class="flex items-center gap-4">
                            <div class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50 dark:border-neutral-800 dark:bg-neutral-950">
                                <img v-if="vistaPrevia" :src="vistaPrevia" alt="Logo de la empresa" class="size-full object-contain" />
                                <ImageIcon v-else class="size-7 text-neutral-300 dark:text-neutral-600" />
                            </div>
                            <div class="flex flex-col items-start gap-1.5">
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-stone-300 px-3.5 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        @click="inputLogo.click()"
                                    >
                                        <Upload class="size-4" />
                                        {{ vistaPrevia ? 'Cambiar logo' : 'Subir logo' }}
                                    </button>
                                    <button
                                        v-if="vistaPrevia"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40"
                                        @click="quitarLogo"
                                    >
                                        <Trash2 class="size-4" />
                                        Quitar
                                    </button>
                                </div>
                                <p class="text-xs text-neutral-400 dark:text-neutral-500">JPG, PNG o WEBP · máximo 2 MB</p>
                            </div>
                            <input
                                ref="inputLogo"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                @change="seleccionarLogo"
                            />
                        </div>
                        <p v-if="form.errors.logo" :class="claseError">{{ form.errors.logo }}</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end border-t border-stone-200 pt-5 dark:border-neutral-800">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        data-guardar-empresa
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </div>

        </form>
    </AppLayout>
</template>
