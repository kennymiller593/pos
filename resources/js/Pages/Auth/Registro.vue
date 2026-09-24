<script setup>
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, ArrowRight, Check, Eye, EyeOff, LoaderCircle, Search } from '@lucide/vue'
import PanelMarcaAuth from '@/Components/PanelMarcaAuth.vue'

defineProps({
    rubros: { type: Array, required: true },
})

const form = useForm({
    ruc: '',
    razon_social: '',
    nombre_comercial: '',
    rubro_codigo: '',
    regimen_tributario: 'RUS',
    direccion: '',
    ubigeo: '',
    nombre_completo: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const verPassword = ref(false)

// ---- pasos ----
const paso = ref(1)
const CAMPOS_PASO_1 = ['ruc', 'razon_social', 'nombre_comercial', 'rubro_codigo', 'regimen_tributario', 'direccion', 'ubigeo']

const pasos = [
    { numero: 1, label: 'Tu negocio', descripcion: 'Los datos fiscales de tu empresa' },
    { numero: 2, label: 'Tu cuenta', descripcion: 'El acceso del administrador' },
]

function avanzar() {
    form.clearErrors(...CAMPOS_PASO_1)
    let valido = true

    if (!/^\d{11}$/.test(form.ruc)) {
        form.setError('ruc', 'El RUC debe tener 11 dígitos.')
        valido = false
    }
    if (!form.razon_social.trim()) {
        form.setError('razon_social', 'Ingresa la razón social.')
        valido = false
    }
    if (!form.rubro_codigo) {
        form.setError('rubro_codigo', 'Elige el rubro de tu negocio.')
        valido = false
    }

    if (valido) paso.value = 2
}

// ---- consulta automatica del RUC en SUNAT ----
const consultandoRuc = ref(false)
const errorRuc = ref('')
const sunat = ref(null)

async function consultarRuc() {
    if (!/^\d{11}$/.test(form.ruc) || consultandoRuc.value) return
    consultandoRuc.value = true
    errorRuc.value = ''
    sunat.value = null
    try {
        const respuesta = await fetch(`/consultas/ruc/${form.ruc}`, { headers: { Accept: 'application/json' } })
        const datos = await respuesta.json()
        if (!respuesta.ok) {
            errorRuc.value = datos.message ?? 'No se pudo consultar el RUC.'
            return
        }
        sunat.value = datos
        if (datos.razon_social) form.razon_social = datos.razon_social
        if (datos.direccion) form.direccion = datos.direccion.trim()
        form.ubigeo = /^\d{6}$/.test(datos.ubigeo ?? '') ? datos.ubigeo : ''
        form.clearErrors('ruc', 'razon_social')
    } catch {
        errorRuc.value = 'No se pudo consultar el RUC. Completa los datos manualmente.'
    } finally {
        consultandoRuc.value = false
    }
}

watch(() => form.ruc, (ruc) => {
    errorRuc.value = ''
    if (/^\d{11}$/.test(ruc)) consultarRuc()
})

const ubicacionSunat = computed(() => {
    if (!sunat.value) return ''
    return [sunat.value.distrito, sunat.value.provincia, sunat.value.departamento].filter(Boolean).join(' · ')
})

const pasoActual = computed(() => pasos.find((p) => p.numero === paso.value))

function enviar() {
    form.post('/registro', {
        onFinish: () => form.reset('password', 'password_confirmation'),
        onError: (errores) => {
            // si el servidor rechaza algo del paso 1, regresamos a ese paso
            if (Object.keys(errores).some((campo) => CAMPOS_PASO_1.includes(campo))) {
                paso.value = 1
            }
        },
    })
}

const claseInput =
    'h-12 w-full rounded-xl border border-neutral-200 bg-white px-3.5 text-sm placeholder-neutral-400 shadow-sm transition-shadow focus:border-emerald-500 focus:ring-4 focus:ring-emerald-400/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1.5 block text-sm font-medium'
const claseError = 'mt-1.5 text-sm text-red-600 dark:text-red-400'
</script>

<template>
    <div class="flex min-h-screen bg-[#EFF3F0] text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">

        <!-- Panel de marca compartido, con el terminal de configuración como firma -->
        <PanelMarcaAuth ancho="w-5/12" eyebrow="" titulo="Empieza a vender" destacado="hoy mismo."
            descripcion="Registra tu negocio en un minuto: creamos tu sucursal, tu caja y tu usuario administrador automáticamente.">
            <template #firma>
                <div
                    class="font-receipt w-80 rounded-xl border border-white/10 bg-black/50 p-5 text-[12.5px] leading-loose shadow-2xl shadow-black/50 backdrop-blur-md">
                    <p class="text-neutral-500">$ falcontech <span class="text-white">init</span></p>
                    <p class="text-neutral-300"><span class="text-emerald-400">✓</span> empresa registrada</p>
                    <p class="text-neutral-300"><span class="text-emerald-400">✓</span> sucursal principal creada</p>
                    <p class="text-neutral-300"><span class="text-emerald-400">✓</span> caja 01 aperturada</p>
                    <p class="text-neutral-300"><span class="text-emerald-400">✓</span> facturación SUNAT conectada</p>
                    <p class="text-[#5B8DF7]">&gt; listo para vender<span class="cursor">_</span></p>
                </div>
            </template>
        </PanelMarcaAuth>

        <!-- ============ Formulario ============ -->
        <div class="flex w-full items-center justify-center p-4 sm:p-6 lg:w-7/12">
            <div
                class="anim w-full max-w-lg rounded-3xl border border-stone-200/80 bg-white p-6 shadow-xl shadow-stone-900/5 sm:p-8 dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-black/20">

                <!-- Logo solo móvil -->
                <div class="mb-10 flex justify-center lg:hidden">
                    <div class="rounded-2xl bg-[#050807] px-5 py-4">
                        <img :src="'/images/falcontech_oscuro.png'" alt="FalconTech" class="h-8 w-auto" />
                    </div>
                </div>

                <h1 class="font-display mt-3 text-3xl font-bold tracking-tight">Registra tu negocio</h1>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">
                    ¿Ya tienes cuenta?
                    <Link href="/login" class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                        Inicia sesión</Link>
                </p>

                <!-- Indicador de pasos -->
                <div class="mt-9">
                    <div class="mx-auto flex max-w-sm items-start">
                        <template v-for="(p, i) in pasos" :key="p.numero">
                            <button type="button" class="group flex w-24 flex-col items-center gap-1.5"
                                :disabled="p.numero >= paso" @click="p.numero < paso && (paso = p.numero)">
                                <span
                                    class="font-receipt grid size-10 place-items-center rounded-full text-sm font-semibold transition-colors duration-300"
                                    :class="paso > p.numero
                                        ? 'bg-emerald-600 text-white group-hover:bg-emerald-500'
                                        : paso === p.numero
                                            ? 'bg-neutral-900 text-emerald-400 ring-4 ring-emerald-400/25 dark:bg-emerald-500 dark:text-white'
                                            : 'border-2 border-neutral-200 bg-white text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-500'">
                                    <Check v-if="paso > p.numero" class="size-4.5" />
                                    <template v-else>0{{ p.numero }}</template>
                                </span>
                                <span class="text-sm font-medium transition-colors"
                                    :class="paso >= p.numero ? '' : 'text-neutral-400 dark:text-neutral-500'">
                                    {{ p.label }}
                                </span>
                            </button>
                            <div v-if="i < pasos.length - 1"
                                class="mt-5 h-0.5 flex-1 rounded transition-colors duration-300"
                                :class="paso > p.numero ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-neutral-200 dark:bg-neutral-800'" />
                        </template>
                    </div>
                    <p class="mt-4 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        {{ pasoActual.descripcion }}
                    </p>
                </div>

                <form class="mt-8" @submit.prevent="paso === 2 ? enviar() : avanzar()">
                    <!-- Paso 1: datos del negocio -->
                    <div v-show="paso === 1" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" for="ruc">RUC *</label>
                            <div class="relative">
                                <input id="ruc" v-model="form.ruc" type="text" maxlength="11" inputmode="numeric"
                                    :class="[claseInput, 'pr-11']" placeholder="20123456789" />
                                <button type="button"
                                    class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 transition-colors hover:bg-stone-100 hover:text-emerald-600 disabled:cursor-default dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                    title="Buscar en SUNAT" :disabled="consultandoRuc" @click="consultarRuc">
                                    <LoaderCircle v-if="consultandoRuc" class="size-4 animate-spin" />
                                    <Search v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="form.errors.ruc" :class="claseError">{{ form.errors.ruc }}</p>
                            <p v-if="errorRuc" :class="claseError">{{ errorRuc }}</p>
                            <p v-if="sunat" class="mt-1.5 text-sm" :class="sunat.estado === 'ACTIVO'
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-amber-600 dark:text-amber-400'">
                                ✓ {{ sunat.estado ?? 'Encontrado' }}{{ sunat.condicion ? ` · ${sunat.condicion}` : '' }}
                            </p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="rubro">Rubro *</label>
                            <select id="rubro" v-model="form.rubro_codigo" :class="claseInput">
                                <option value="" disabled>Elige tu rubro</option>
                                <option v-for="r in rubros" :key="r.codigo" :value="r.codigo">{{ r.nombre }}</option>
                            </select>
                            <p v-if="form.errors.rubro_codigo" :class="claseError">{{ form.errors.rubro_codigo }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="razon_social">Razón social *</label>
                            <input id="razon_social" v-model="form.razon_social" type="text" :class="claseInput"
                                placeholder="Mi Negocio S.A.C." />
                            <p v-if="form.errors.razon_social" :class="claseError">{{ form.errors.razon_social }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="nombre_comercial">Nombre comercial</label>
                            <input id="nombre_comercial" v-model="form.nombre_comercial" type="text" :class="claseInput"
                                placeholder="Mi Negocio" />
                        </div>
                        <div>
                            <label :class="claseLabel" for="regimen">Régimen tributario *</label>
                            <select id="regimen" v-model="form.regimen_tributario" :class="claseInput">
                                <option value="RUS">RUS</option>
                                <option value="RER">RER</option>
                                <option value="MYPE">MYPE</option>
                                <option value="GENERAL">General</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="direccion">Dirección fiscal</label>
                            <input id="direccion" v-model="form.direccion" type="text" :class="claseInput"
                                placeholder="Av. Principal 123" />
                            <p v-if="ubicacionSunat" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                {{ ubicacionSunat }}<template v-if="form.ubigeo"> · Ubigeo {{ form.ubigeo }}</template>
                            </p>
                            <p v-if="form.errors.direccion" :class="claseError">{{ form.errors.direccion }}</p>
                            <p v-if="form.errors.ubigeo" :class="claseError">{{ form.errors.ubigeo }}</p>
                        </div>

                        <div class="sm:col-span-2">
                            <button type="submit"
                                class="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-500 hover:shadow-emerald-500/30 focus-visible:ring-4 focus-visible:ring-emerald-400/40 focus-visible:outline-none active:scale-[0.99]">
                                Siguiente
                                <ArrowRight class="size-4 transition-transform group-hover:translate-x-0.5" />
                            </button>
                        </div>
                    </div>

                    <!-- Paso 2: cuenta del administrador -->
                    <div v-show="paso === 2" class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="nombre_completo">Tu nombre *</label>
                            <input id="nombre_completo" v-model="form.nombre_completo" type="text" :class="claseInput"
                                placeholder="Juan Pérez" />
                            <p v-if="form.errors.nombre_completo" :class="claseError">{{ form.errors.nombre_completo }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="email">Correo *</label>
                            <input id="email" v-model="form.email" type="email" autocomplete="email" :class="claseInput"
                                placeholder="tucorreo@negocio.com" />
                            <p v-if="form.errors.email" :class="claseError">{{ form.errors.email }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="password">Contraseña *</label>
                            <div class="relative">
                                <input id="password" v-model="form.password" :type="verPassword ? 'text' : 'password'"
                                    autocomplete="new-password" :class="[claseInput, 'pr-11']"
                                    placeholder="Mínimo 8 caracteres" />
                                <button type="button"
                                    class="absolute top-1/2 right-3 -translate-y-1/2 rounded-md p-1 text-neutral-400 transition-colors hover:text-neutral-600 focus-visible:ring-2 focus-visible:ring-emerald-400 focus-visible:outline-none dark:hover:text-neutral-300"
                                    :title="verPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                    @click="verPassword = !verPassword">
                                    <EyeOff v-if="verPassword" class="size-4" />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="form.errors.password" :class="claseError">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="password_confirmation">Repite la contraseña *</label>
                            <input id="password_confirmation" v-model="form.password_confirmation"
                                :type="verPassword ? 'text' : 'password'" autocomplete="new-password"
                                :class="claseInput" placeholder="••••••••" />
                        </div>

                        <div class="flex gap-2 sm:col-span-2">
                            <button type="button"
                                class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-neutral-200 bg-white px-5 text-sm font-medium shadow-sm transition-colors hover:bg-stone-50 focus-visible:ring-4 focus-visible:ring-emerald-400/30 focus-visible:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:hover:bg-neutral-800"
                                @click="paso = 1">
                                <ArrowLeft class="size-4" />
                                Atrás
                            </button>
                            <button type="submit" :disabled="form.processing"
                                class="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-500 hover:shadow-emerald-500/30 focus-visible:ring-4 focus-visible:ring-emerald-400/40 focus-visible:outline-none active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100">
                                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                {{ form.processing ? 'Creando tu negocio...' : 'Crear mi negocio' }}
                            </button>
                        </div>
                    </div>
                </form>

                <p
                    class="font-receipt mt-10 text-center text-[11px] tracking-wider text-neutral-400 dark:text-neutral-600">
                    FALCONTECH <span class="text-emerald-500">/</span> SISTEMA DE PUNTO DE VENTA <span
                        class="text-emerald-500">/</span>
                    PERÚ
                </p>
            </div>
        </div>
    </div>
</template>

<!-- Las fuentes, .anim y .cursor vienen de PanelMarcaAuth (estilos globales) -->