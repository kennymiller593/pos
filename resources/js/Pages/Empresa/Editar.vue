<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Building2, Check, FileKey, ImageIcon, Trash2, Upload, X, Zap, ZapOff } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    empresa: { type: Object, required: true },
    rubros: { type: Array, required: true },
    requisitosFacturacion: { type: Object, default: () => ({}) },
})

const { confirmar } = useConfirmar()

const REQUISITOS = [
    { clave: 'certificado', label: 'Certificado digital cargado' },
    { clave: 'clave_certificado', label: 'Contraseña del certificado' },
    { clave: 'credenciales_sol', label: 'Usuario y clave SOL' },
    { clave: 'serie', label: 'Serie de boleta o factura configurada (Sucursales)' },
]

const listoParaActivar = computed(() => REQUISITOS.every((r) => props.requisitosFacturacion[r.clave]))

async function alternarFacturacion() {
    const activando = !props.empresa.facturacion_electronica
    const ok = await confirmar({
        titulo: activando ? '¿Activar facturación electrónica?' : '¿Desactivar facturación electrónica?',
        mensaje: activando
            ? `El POS podrá emitir boletas en el entorno ${props.empresa.entorno_sunat === 'produccion' ? 'de PRODUCCIÓN (SUNAT real)' : 'beta (pruebas)'}.`
            : 'El POS volverá a emitir solo notas de venta internas.',
        textoConfirmar: activando ? 'Activar' : 'Desactivar',
        peligro: !activando,
    })
    if (ok) {
        router.post('/empresa/facturacion', {}, { preserveScroll: true })
    }
}

const form = useForm({
    _method: 'put', // los archivos deben viajar por POST (limitacion de PHP con multipart)
    razon_social: props.empresa.razon_social,
    nombre_comercial: props.empresa.nombre_comercial ?? '',
    regimen_tributario: props.empresa.regimen_tributario,
    rubro_codigo: props.empresa.rubro_codigo,
    logo: null,
    logo_eliminar: false,
    usuario_sol: props.empresa.usuario_sol ?? '',
    certificado_digital: '',
    clave_sol: '',
    clave_certificado: '',
    entorno_sunat: props.empresa.entorno_sunat ?? 'beta',
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
        usuario_sol: data.usuario_sol || null,
        certificado_digital: data.certificado_digital || null,
        clave_sol: data.clave_sol || null,
        clave_certificado: data.clave_certificado || null,
    })).post('/empresa', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('certificado_digital', 'clave_sol', 'clave_certificado', 'logo', 'logo_eliminar')
            if (inputLogo.value) inputLogo.value.value = ''
        },
    })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'

// parsea 'YYYY-MM-DD' como fecha local (evita el corrimiento de un dia que da `new Date('YYYY-MM-DD')` por UTC)
function parseFechaLocal(valor) {
    const [anio, mes, dia] = valor.split('-').map(Number)
    return new Date(anio, mes - 1, dia)
}

const certificadoVencimiento = computed(() => {
    if (!props.empresa.certificado_vence_en) return null
    const fecha = parseFechaLocal(props.empresa.certificado_vence_en)
    const hoy = new Date()
    hoy.setHours(0, 0, 0, 0)
    const diasRestantes = Math.round((fecha - hoy) / 86400000)
    return {
        texto: fecha.toLocaleDateString('es-PE', { day: '2-digit', month: 'long', year: 'numeric' }),
        vencido: diasRestantes < 0,
        porVencer: diasRestantes >= 0 && diasRestantes <= 30,
    }
})
</script>

<template>
    <AppLayout titulo="Empresa">
        <form class="mx-auto max-w-3xl space-y-4" @submit.prevent="enviar">
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
            </div>

            <!-- Facturación electrónica -->
            <div class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <FileKey class="size-5" />
                        </div>
                        <div>
                            <h2 class="font-semibold tracking-tight">Facturación electrónica (SUNAT)</h2>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                Con el interruptor apagado, el POS emite solo notas de venta internas.
                            </p>
                            <p
                                v-if="form.regimen_tributario === 'RUS'"
                                class="mt-2 rounded-lg bg-sky-50 px-2.5 py-1.5 text-xs text-sky-800 dark:bg-sky-500/10 dark:text-sky-300"
                            >
                                Régimen Nuevo RUS: solo se emiten <strong>boletas</strong> (no facturas) y sin IGV; las ventas van
                                como exoneradas.
                            </p>
                        </div>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="empresa.facturacion_electronica
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                            : 'bg-stone-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'"
                    >
                        {{ empresa.facturacion_electronica
                            ? (empresa.entorno_sunat === 'produccion' ? 'Activa · Producción' : 'Activa · Beta')
                            : 'Desactivada' }}
                    </span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="claseLabel" for="usuario_sol">Usuario SOL</label>
                        <input id="usuario_sol" v-model="form.usuario_sol" type="text" :class="claseInput" placeholder="MODDATOS" />
                    </div>
                    <div>
                        <label :class="claseLabel" for="clave_sol">Clave SOL</label>
                        <input
                            id="clave_sol"
                            v-model="form.clave_sol"
                            type="password"
                            autocomplete="new-password"
                            :class="claseInput"
                            :placeholder="empresa.tiene_clave_sol ? '••••••• (guardada, escribe para cambiarla)' : 'Tu clave SOL'"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <label :class="claseLabel" for="certificado">Certificado digital</label>
                        <textarea
                            id="certificado"
                            v-model="form.certificado_digital"
                            rows="4"
                            class="w-full rounded-xl border border-stone-300 bg-white px-3 py-2 font-mono text-xs placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500"
                            :placeholder="empresa.tiene_certificado
                                ? 'Ya hay un certificado guardado. Pega uno nuevo solo si quieres reemplazarlo.'
                                : 'Pega aquí el contenido de tu certificado (.pem)'"
                        />
                        <p v-if="empresa.tiene_certificado && !form.certificado_digital" class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">
                            ✓ Certificado guardado.
                            <template v-if="certificadoVencimiento">
                                <span v-if="certificadoVencimiento.vencido" class="text-red-600 dark:text-red-400">
                                    Venció el {{ certificadoVencimiento.texto }}: renuévalo.
                                </span>
                                <span v-else-if="certificadoVencimiento.porVencer" class="text-amber-600 dark:text-amber-400">
                                    Vence el {{ certificadoVencimiento.texto }}.
                                </span>
                                <span v-else>Vence el {{ certificadoVencimiento.texto }}.</span>
                            </template>
                            Déjalo vacío para conservarlo.
                        </p>
                        <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                            Acepta el .pem (llave + certificado) o el .pfx en base64. Si la llave tiene contraseña, escríbela en "Contraseña del certificado".
                        </p>
                        <p v-if="form.errors.certificado_digital" :class="claseError">{{ form.errors.certificado_digital }}</p>
                    </div>
                    <div>
                        <label :class="claseLabel" for="clave_certificado">Contraseña del certificado</label>
                        <input
                            id="clave_certificado"
                            v-model="form.clave_certificado"
                            type="password"
                            autocomplete="new-password"
                            :class="claseInput"
                            :placeholder="empresa.tiene_clave_certificado ? '••••••• (guardada)' : 'Contraseña del .pfx/.pem'"
                        />
                    </div>
                    <div>
                        <label :class="claseLabel" for="entorno_sunat">Entorno</label>
                        <select
                            id="entorno_sunat"
                            v-model="form.entorno_sunat"
                            :disabled="empresa.facturacion_electronica"
                            :class="[claseInput, empresa.facturacion_electronica ? 'cursor-not-allowed bg-stone-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' : '']"
                        >
                            <option value="beta">Beta (pruebas, sin valor real)</option>
                            <option value="produccion">Producción (SUNAT real)</option>
                        </select>
                        <p v-if="empresa.facturacion_electronica" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                            Desactiva la facturación electrónica para cambiar el entorno.
                        </p>
                        <p v-if="form.errors.entorno_sunat" :class="claseError">{{ form.errors.entorno_sunat }}</p>
                        <p
                            v-if="form.entorno_sunat === 'beta'"
                            class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                        >
                            Estás en el ambiente de pruebas de SUNAT: las boletas y facturas que emitas NO tienen valor tributario y se imprimen con esa leyenda.
                        </p>
                    </div>
                </div>

                <!-- Checklist + interruptor -->
                <div class="mt-5 rounded-xl bg-stone-50 p-4 dark:bg-neutral-950">
                    <p class="mb-2 text-xs font-semibold tracking-wider text-neutral-400 uppercase">Requisitos para activar</p>
                    <ul class="space-y-1.5">
                        <li v-for="r in REQUISITOS" :key="r.clave" class="flex items-center gap-2 text-sm">
                            <Check v-if="requisitosFacturacion[r.clave]" class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <X v-else class="size-4 shrink-0 text-neutral-300 dark:text-neutral-600" />
                            <span :class="requisitosFacturacion[r.clave] ? '' : 'text-neutral-400 dark:text-neutral-500'">{{ r.label }}</span>
                        </li>
                    </ul>

                    <button
                        type="button"
                        class="mt-4 inline-flex h-10 items-center justify-center gap-2 rounded-xl px-5 text-sm font-semibold transition-colors"
                        :class="empresa.facturacion_electronica
                            ? 'border border-stone-300 text-neutral-700 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800'
                            : (listoParaActivar
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                : 'cursor-not-allowed bg-stone-200 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500')"
                        :disabled="!empresa.facturacion_electronica && !listoParaActivar"
                        :title="!empresa.facturacion_electronica && !listoParaActivar ? 'Completa los requisitos y guarda los cambios primero' : undefined"
                        @click="alternarFacturacion"
                    >
                        <ZapOff v-if="empresa.facturacion_electronica" class="size-4" />
                        <Zap v-else class="size-4" />
                        {{ empresa.facturacion_electronica ? 'Desactivar facturación' : 'Activar facturación' }}
                    </button>
                    <p class="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                        Guarda los cambios de credenciales antes de activar. Empieza en Beta para probar sin riesgo.
                    </p>
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
