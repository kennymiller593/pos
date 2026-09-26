<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Check, MapPin, Pencil, Phone, Plus, ReceiptText, Store, Trash2, UserRound, Wallet, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import BuscadorUbigeo from '@/Components/BuscadorUbigeo.vue'

const props = defineProps({
    sucursales: { type: Array, required: true },
})

// ---- modal sucursal ----
const modalSucursal = ref(false)
const sucursalEditar = ref(null)

const formSucursal = useForm({
    nombre: '',
    codigo_sunat: '',
    direccion: '',
    ubigeo: '',
    telefono: '',
    activo: true,
})

function abrirSucursal(sucursal = null) {
    sucursalEditar.value = sucursal
    formSucursal.clearErrors()
    formSucursal.nombre = sucursal?.nombre ?? ''
    formSucursal.codigo_sunat = sucursal?.codigo_sunat ?? ''
    formSucursal.direccion = sucursal?.direccion ?? ''
    formSucursal.ubigeo = sucursal?.ubigeo ?? ''
    formSucursal.telefono = sucursal?.telefono ?? ''
    formSucursal.activo = sucursal?.activo ?? true
    modalSucursal.value = true
}

function guardarSucursal() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (modalSucursal.value = false),
    }
    const transformar = (data) => ({
        ...data,
        direccion: data.direccion || null,
        ubigeo: data.ubigeo || null,
        telefono: data.telefono || null,
    })

    if (sucursalEditar.value) {
        formSucursal.transform(transformar).put(`/sucursales/${sucursalEditar.value.id}`, opciones)
    } else {
        formSucursal.transform(transformar).post('/sucursales', opciones)
    }
}

// ---- modal caja ----
const modalCaja = ref(null) // { sucursal, caja: null | Object }

const formCaja = useForm({
    caja_id: null,
    nombre: '',
    ancho_ticket: 80,
    activo: true,
})

function abrirCaja(sucursal, caja = null) {
    modalCaja.value = { sucursal, caja }
    formCaja.clearErrors()
    formCaja.caja_id = caja?.id ?? null
    formCaja.nombre = caja?.nombre ?? ''
    formCaja.ancho_ticket = caja?.ancho_ticket ?? 80
    formCaja.activo = caja?.activo ?? true
}

function guardarCaja() {
    formCaja.post(`/sucursales/${modalCaja.value.sucursal.id}/cajas`, {
        preserveScroll: true,
        onSuccess: () => (modalCaja.value = null),
    })
}

// ---- modal series ----
const TIPOS_COMPROBANTE = [
    { codigo: '00', nombre: 'Nota de venta', ejemplo: 'NV02' },
    { codigo: '03', nombre: 'Boleta', ejemplo: 'B002' },
    { codigo: '01', nombre: 'Factura', ejemplo: 'F002' },
]

const nombreTipo = (codigo) => TIPOS_COMPROBANTE.find((t) => t.codigo === codigo)?.nombre ?? codigo

const modalSeriesId = ref(null)
// siempre la version fresca de la sucursal, aun despues de agregar/eliminar
const sucursalSeries = computed(() => props.sucursales.find((s) => s.id === modalSeriesId.value) ?? null)

const formSerie = useForm({
    serie_id: null,
    tipo_comprobante_codigo: '00',
    serie: '',
    caja_id: '',
    correlativo: 0,
})

function abrirSeries(sucursal) {
    formSerie.reset()
    formSerie.clearErrors()
    modalSeriesId.value = sucursal.id
}

function agregarSerie() {
    formSerie
        .transform((data) => ({
            ...data,
            serie: data.serie.toUpperCase(),
            caja_id: data.caja_id || null,
        }))
        .post(`/sucursales/${modalSeriesId.value}/series`, {
            preserveScroll: true,
            onSuccess: () => {
                formSerie.reset()
                formSerie.clearErrors()
            },
        })
}

function eliminarSerie(serie) {
    router.delete(`/sucursales/${modalSeriesId.value}/series/${serie.id}`, { preserveScroll: true })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Sucursales">
        <div class="mb-4 flex justify-end">
            <button
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                @click="abrirSucursal()"
            >
                <Plus class="size-4" />
                Nueva sucursal
            </button>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <div
                v-for="s in sucursales"
                :key="s.id"
                class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900"
                :class="!s.activo ? 'opacity-60' : ''"
            >
                <!-- Encabezado -->
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <Store class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-semibold tracking-tight">
                                {{ s.nombre }}
                                <span class="ml-1.5 font-mono text-xs font-normal text-neutral-400">#{{ s.codigo_sunat }}</span>
                            </p>
                            <p class="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                                <UserRound class="size-3" /> {{ s.usuarios_count }} usuario{{ s.usuarios_count === 1 ? '' : 's' }}
                                <span
                                    v-if="!s.activo"
                                    class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400"
                                >
                                    Desactivada
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                            title="Configurar series de comprobantes"
                            @click="abrirSeries(s)"
                        >
                            <ReceiptText class="size-4" />
                            Series
                        </button>
                        <button
                            class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                            title="Editar sucursal"
                            @click="abrirSucursal(s)"
                        >
                            <Pencil class="size-4" />
                        </button>
                    </div>
                </div>

                <!-- Datos -->
                <div class="space-y-1.5 px-5 py-4 text-sm text-neutral-600 dark:text-neutral-300">
                    <p v-if="s.direccion" class="flex items-center gap-2">
                        <MapPin class="size-4 shrink-0 text-neutral-400" />
                        <span class="truncate">
                            {{ s.direccion }}<template v-if="s.ubigeo_info">
                                · <span class="capitalize">{{ s.ubigeo_info.distrito.toLowerCase() }}, {{ s.ubigeo_info.provincia.toLowerCase() }}</span>
                            </template><template v-else-if="s.ubigeo"> · Ubigeo {{ s.ubigeo }}</template>
                        </span>
                    </p>
                    <p v-if="s.telefono" class="flex items-center gap-2">
                        <Phone class="size-4 shrink-0 text-neutral-400" />
                        {{ s.telefono }}
                    </p>
                    <p v-if="!s.direccion && !s.telefono" class="text-neutral-400 dark:text-neutral-500">
                        Sin dirección ni teléfono registrados.
                    </p>
                </div>

                <!-- Cajas -->
                <div class="border-t border-stone-200 px-5 py-4 dark:border-neutral-800">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">Cajas</p>
                        <button
                            class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                            @click="abrirCaja(s)"
                        >
                            <Plus class="size-3.5" />
                            Agregar
                        </button>
                    </div>
                    <ul class="space-y-1.5">
                        <li
                            v-for="c in s.cajas"
                            :key="c.id"
                            class="flex items-center justify-between gap-2 rounded-xl bg-stone-50 px-3 py-2 dark:bg-neutral-950"
                        >
                            <div class="flex min-w-0 items-center gap-2 text-sm">
                                <Wallet class="size-4 shrink-0 text-neutral-400" />
                                <span class="truncate font-medium" :class="!c.activo ? 'text-neutral-400 line-through' : ''">
                                    {{ c.nombre }}
                                </span>
                                <span class="shrink-0 rounded-full bg-stone-200 px-2 py-0.5 text-[11px] font-semibold text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                                    {{ c.ancho_ticket }}mm
                                </span>
                                <span
                                    v-if="c.apertura_abierta"
                                    class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300"
                                >
                                    En uso · {{ c.apertura_abierta.usuario?.nombre_completo }}
                                </span>
                            </div>
                            <button
                                class="shrink-0 rounded-lg p-1.5 text-neutral-400 hover:bg-stone-200 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                title="Editar caja"
                                @click="abrirCaja(s, c)"
                            >
                                <Pencil class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Modal sucursal -->
        <Teleport to="body">
            <div v-if="modalSucursal" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalSucursal = false" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarSucursal"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">{{ sucursalEditar ? 'Editar sucursal' : 'Nueva sucursal' }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalSucursal = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" for="s_nombre">Nombre *</label>
                            <input id="s_nombre" v-model="formSucursal.nombre" type="text" :class="claseInput" placeholder="Sucursal Centro" />
                            <p v-if="formSucursal.errors.nombre" :class="claseError">{{ formSucursal.errors.nombre }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="s_codigo">Código SUNAT *</label>
                            <input id="s_codigo" v-model="formSucursal.codigo_sunat" type="text" maxlength="4" inputmode="numeric" :class="claseInput" placeholder="0001" />
                            <p v-if="formSucursal.errors.codigo_sunat" :class="claseError">{{ formSucursal.errors.codigo_sunat }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="s_direccion">Dirección</label>
                            <input id="s_direccion" v-model="formSucursal.direccion" type="text" :class="claseInput" placeholder="Av. Principal 123" />
                        </div>
                        <div>
                            <label :class="claseLabel" for="s_ubigeo">Distrito (ubigeo)</label>
                            <BuscadorUbigeo v-model="formSucursal.ubigeo" />
                            <p v-if="formSucursal.errors.ubigeo" :class="claseError">{{ formSucursal.errors.ubigeo }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="s_telefono">Teléfono</label>
                            <input id="s_telefono" v-model="formSucursal.telefono" type="text" :class="claseInput" placeholder="01 234 5678" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="formSucursal.activo" type="checkbox" class="size-4 rounded accent-emerald-600" />
                                Sucursal activa
                            </label>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalSucursal = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formSucursal.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formSucursal.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal caja -->
        <Teleport to="body">
            <div v-if="modalCaja" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalCaja = null" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarCaja"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">
                            {{ modalCaja.caja ? 'Editar caja' : 'Nueva caja' }} · {{ modalCaja.sucursal.nombre }}
                        </h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalCaja = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label :class="claseLabel" for="c_nombre">Nombre *</label>
                            <input id="c_nombre" v-model="formCaja.nombre" type="text" :class="claseInput" placeholder="Caja 2" />
                            <p v-if="formCaja.errors.nombre" :class="claseError">{{ formCaja.errors.nombre }}</p>
                        </div>
                        <div>
                            <p :class="claseLabel">Tiketera (ancho del papel) *</p>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    class="h-10 rounded-xl border text-sm font-medium transition-colors"
                                    :class="formCaja.ancho_ticket === 58
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    @click="formCaja.ancho_ticket = 58"
                                >
                                    58 mm
                                </button>
                                <button
                                    type="button"
                                    class="h-10 rounded-xl border text-sm font-medium transition-colors"
                                    :class="formCaja.ancho_ticket === 80
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    @click="formCaja.ancho_ticket = 80"
                                >
                                    80 mm
                                </button>
                            </div>
                            <p v-if="formCaja.errors.ancho_ticket" :class="claseError">{{ formCaja.errors.ancho_ticket }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="formCaja.activo" type="checkbox" class="size-4 rounded accent-emerald-600" />
                            Caja activa
                        </label>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalCaja = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formCaja.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formCaja.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
        <!-- Modal series -->
        <Teleport to="body">
            <div v-if="sucursalSeries" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalSeriesId = null" />
                <div
                    class="relative w-full max-w-2xl rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Series de comprobantes · {{ sucursalSeries.nombre }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalSeriesId = null"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <!-- Lista -->
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                            <tr>
                                <th class="py-2.5 pr-3 font-semibold tracking-wider">Comprobante</th>
                                <th class="px-3 py-2.5 font-semibold tracking-wider">Serie</th>
                                <th class="px-3 py-2.5 font-semibold tracking-wider">Caja</th>
                                <th class="px-3 py-2.5 text-right font-semibold tracking-wider">Último Nº</th>
                                <th class="py-2.5 pl-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-if="!sucursalSeries.series.length">
                                <td colspan="5" class="py-6 text-center text-neutral-400 dark:text-neutral-500">
                                    <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                        Sin series: se crearán automáticamente en la primera venta.
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="serie in sucursalSeries.series" :key="serie.id">
                                <td class="py-2.5 pr-3">{{ nombreTipo(serie.tipo_comprobante_codigo) }}</td>
                                <td class="px-3 py-2.5 font-mono font-semibold">{{ serie.serie }}</td>
                                <td class="px-3 py-2.5 text-neutral-500 dark:text-neutral-400">{{ serie.caja?.nombre ?? 'Toda la sucursal' }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ serie.correlativo }}</td>
                                <td class="py-2.5 pl-3 text-right">
                                    <button
                                        v-if="serie.correlativo === 0"
                                        class="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        title="Eliminar serie"
                                        @click="eliminarSerie(serie)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                    <span v-else class="text-[11px] text-neutral-300 dark:text-neutral-600" title="Ya emitió comprobantes">en uso</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Agregar -->
                    <form
                        class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-stone-50 p-3 dark:bg-neutral-950"
                        @submit.prevent="agregarSerie"
                    >
                        <select v-model="formSerie.tipo_comprobante_codigo" :class="[claseInput, 'w-auto flex-1']">
                            <option v-for="t in TIPOS_COMPROBANTE" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                        </select>
                        <input
                            v-model="formSerie.serie"
                            type="text"
                            maxlength="4"
                            :class="[claseInput, 'w-24 font-mono uppercase']"
                            :placeholder="TIPOS_COMPROBANTE.find((t) => t.codigo === formSerie.tipo_comprobante_codigo)?.ejemplo"
                        />
                        <select v-model="formSerie.caja_id" :class="[claseInput, 'w-auto flex-1']">
                            <option value="">Toda la sucursal</option>
                            <option v-for="c in sucursalSeries.cajas" :key="c.id" :value="c.id">Solo {{ c.nombre }}</option>
                        </select>
                        <input
                            v-model.number="formSerie.correlativo"
                            type="number"
                            min="0"
                            :class="[claseInput, 'w-24']"
                            title="Último correlativo emitido (0 si es nueva)"
                        />
                        <button
                            type="submit"
                            :disabled="formSerie.processing || !formSerie.serie"
                            class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-600 text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            title="Agregar serie"
                        >
                            <Check class="size-5" />
                        </button>
                    </form>
                    <p v-if="formSerie.errors.serie || formSerie.errors.correlativo" :class="claseError">
                        {{ formSerie.errors.serie ?? formSerie.errors.correlativo }}
                    </p>
                    <p class="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                        El último número es el ya emitido: el próximo comprobante saldrá con el siguiente. Si no configuras nada, la serie se crea sola en la primera venta.
                    </p>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
