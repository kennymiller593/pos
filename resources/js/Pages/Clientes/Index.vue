<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { Eye, LoaderCircle, Pencil, Plus, Search, Star, Trash2, UserRound, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'
import { ayudaDocumento, esSinDocumento } from '@/composables/documentoIdentidad'

const props = defineProps({
    clientes: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    tiposDocumento: { type: Array, required: true },
    // { activo, soles_por_punto, valor, minimo_canje }
    programaPuntos: { type: Object, default: () => ({ activo: false, soles_por_punto: 10, valor: 0.2, minimo_canje: 0 }) },
})

const { confirmar } = useConfirmar()
const { puede } = usePermisos()
const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')

watchDebounced(buscar, () => {
    router.get('/clientes', { buscar: buscar.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })
}, { debounce: 350 })

// ---- modal ----
const modalAbierto = ref(false)
const clienteEditar = ref(null)

const form = useForm({
    tipo_documento_codigo: '1',
    numero_documento: '',
    nombre: '',
    direccion: '',
    telefono: '',
    email: '',
    limite_credito: 0,
})

function abrir(cliente = null) {
    clienteEditar.value = cliente
    form.clearErrors()
    form.tipo_documento_codigo = cliente?.tipo_documento_codigo?.trim() ?? '1'
    form.numero_documento = cliente?.numero_documento ?? ''
    form.nombre = cliente?.nombre ?? ''
    form.direccion = cliente?.direccion ?? ''
    form.telefono = cliente?.telefono ?? ''
    form.email = cliente?.email ?? ''
    form.limite_credito = Number(cliente?.limite_credito ?? 0)
    modalAbierto.value = true
}

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (modalAbierto.value = false),
    }
    const transformar = (data) => ({
        ...data,
        numero_documento: data.numero_documento || null,
        direccion: data.direccion || null,
        telefono: data.telefono || null,
        email: data.email || null,
    })

    if (clienteEditar.value) {
        form.transform(transformar).put(`/clientes/${clienteEditar.value.id}`, opciones)
    } else {
        form.transform(transformar).post('/clientes', opciones)
    }
}

async function eliminar(cliente) {
    const confirmado = await confirmar({
        titulo: 'Eliminar cliente',
        mensaje: `"${cliente.nombre}" dejará de aparecer en el sistema.`,
        textoConfirmar: 'Eliminar',
        peligro: true,
    })
    if (confirmado) {
        router.delete(`/clientes/${cliente.id}`, { preserveScroll: true })
    }
}

// ---- programa de puntos ----
const modalPuntos = ref(false)
const formPuntos = useForm({ activo: false, soles_por_punto: 10, valor: 0.2, minimo_canje: 0 })

function abrirPuntos() {
    formPuntos.clearErrors()
    formPuntos.activo = props.programaPuntos.activo
    formPuntos.soles_por_punto = props.programaPuntos.soles_por_punto
    formPuntos.valor = props.programaPuntos.valor
    formPuntos.minimo_canje = props.programaPuntos.minimo_canje
    modalPuntos.value = true
}

function guardarPuntos() {
    formPuntos
        .transform((datos) => ({ ...datos, minimo_canje: datos.minimo_canje === '' ? 0 : datos.minimo_canje }))
        .put('/programa-puntos', { preserveScroll: true, onSuccess: () => (modalPuntos.value = false) })
}

// "con una compra de S/ 100 gana 10 puntos, que valen S/ 2.00": el dueño ve cuánto está regalando
const ejemploPuntos = computed(() => {
    const cada = Number(formPuntos.soles_por_punto)
    const valor = Number(formPuntos.valor)
    if (!(cada > 0) || !(valor > 0)) return null
    const ganados = Math.floor(100 / cada + 1e-9)
    return { ganados, descuento: ganados * valor, retorno: (valor / cada) * 100 }
})

// "Sin documento" no lleva numero
watch(() => form.tipo_documento_codigo, (tipo) => {
    if (esSinDocumento(tipo)) form.numero_documento = ''
})

// ---- lookup DNI / RUC ----
const consultando = ref(false)
const errorConsulta = ref('')

async function consultarDocumento() {
    const numero = form.numero_documento.trim()
    const tipo = form.tipo_documento_codigo
    const esDni = tipo === '1' && /^\d{8}$/.test(numero)
    const esRuc = tipo === '6' && /^\d{11}$/.test(numero)
    if ((!esDni && !esRuc) || consultando.value) return

    consultando.value = true
    errorConsulta.value = ''
    try {
        const url = esDni ? `/consultas/dni/${numero}` : `/consultas/ruc/${numero}`
        const r = await fetch(url, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (!r.ok) {
            errorConsulta.value = datos.message ?? 'No se pudo consultar el documento.'
            return
        }
        if (esDni && datos.nombre_completo) form.nombre = datos.nombre_completo
        if (esRuc) {
            if (datos.razon_social) form.nombre = datos.razon_social
            if (datos.direccion) form.direccion = datos.direccion.trim()
        }
    } catch {
        errorConsulta.value = 'No se pudo consultar. Completa los datos manualmente.'
    } finally {
        consultando.value = false
    }
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Clientes">
        <!-- Barra de acciones -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por nombre o documento..."
                    class="h-10 w-full rounded-xl border border-stone-200 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500"
                />
            </div>
            <div class="flex gap-2">
                <button
                    v-if="puede('clientes.puntos')"
                    class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 text-sm font-medium transition-colors hover:bg-stone-50 sm:flex-none dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    @click="abrirPuntos"
                >
                    <Star class="size-4" :class="programaPuntos.activo ? 'fill-amber-400 text-amber-400' : 'text-neutral-400'" />
                    Puntos
                    <span class="text-xs font-normal text-neutral-500 dark:text-neutral-400">{{ programaPuntos.activo ? 'activo' : 'apagado' }}</span>
                </button>
                <button
                    v-if="puede('clientes.gestionar')"
                    class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 sm:flex-none"
                    @click="abrir()"
                >
                    <Plus class="size-4" />
                    Nuevo cliente
                </button>
            </div>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Cliente</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Documento</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Teléfono</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Límite crédito</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Deuda</th>
                            <th v-if="programaPuntos.activo" class="px-4 py-3.5 text-right font-semibold tracking-wider">Puntos</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!clientes.data.length">
                            <td :colspan="programaPuntos.activo ? 7 : 6" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <UserRound class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay clientes que mostrar.
                                    <button v-if="puede('clientes.gestionar')" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400" @click="abrir()">
                                        Crea el primero
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="c in clientes.data" :key="c.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="grid size-9 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                        {{ c.nombre.charAt(0).toUpperCase() }}
                                    </div>
                                    <div>
                                        <Link :href="`/clientes/${c.id}`" class="font-medium hover:text-emerald-700 hover:underline dark:hover:text-emerald-400">{{ c.nombre }}</Link>
                                        <p v-if="c.email" class="text-xs text-neutral-500 dark:text-neutral-400">{{ c.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ c.numero_documento ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ c.telefono ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-neutral-600 dark:text-neutral-300">
                                {{ Number(c.limite_credito) > 0 ? soles(c.limite_credito) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span
                                    v-if="Number(c.deuda) > 0"
                                    class="font-semibold text-amber-600 dark:text-amber-400"
                                >
                                    {{ soles(c.deuda) }}
                                </span>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td v-if="programaPuntos.activo" class="px-4 py-3 text-right tabular-nums">
                                <span v-if="c.puntos > 0" class="inline-flex items-center gap-1 font-semibold">
                                    <Star class="size-3.5 fill-amber-400 text-amber-400" />{{ c.puntos }}
                                </span>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <Link
                                        :href="`/clientes/${c.id}`"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Ver compras y deuda"
                                        :aria-label="`Ver la ficha de ${c.nombre}`"
                                    >
                                        <Eye class="size-4" />
                                    </Link>
                                    <button
                                        v-if="puede('clientes.gestionar')"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Editar"
                                        @click="abrir(c)"
                                    >
                                        <Pencil class="size-4" />
                                    </button>
                                    <button
                                        v-if="puede('clientes.eliminar')"
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        title="Eliminar"
                                        @click="eliminar(c)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="clientes.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ clientes.from }}–{{ clientes.to }} de {{ clientes.total }} clientes</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in clientes.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            preserve-state
                            class="rounded-lg border px-3.5 py-1.5"
                            :class="link.active
                                ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                                : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-lg border border-stone-200 px-3.5 py-1.5 opacity-50 dark:border-neutral-700"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Modal: programa de puntos -->
        <Teleport to="body">
            <div v-if="modalPuntos" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalPuntos = false" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardarPuntos"
                >
                    <div class="mb-1 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">Programa de puntos</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            aria-label="Cerrar"
                            @click="modalPuntos = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Tus clientes ganan puntos al comprar y los usan como descuento en su siguiente compra.
                    </p>

                    <label class="mt-4 flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-stone-200 px-4 py-3 dark:border-neutral-800">
                        <span>
                            <span class="block text-sm font-medium">{{ formPuntos.activo ? 'Programa activo' : 'Programa apagado' }}</span>
                            <span class="block text-xs text-neutral-500 dark:text-neutral-400">
                                {{ formPuntos.activo ? 'Las ventas a clientes registrados suman puntos.' : 'Nadie gana ni canjea. Los puntos ya ganados se conservan.' }}
                            </span>
                        </span>
                        <input v-model="formPuntos.activo" type="checkbox" class="peer sr-only" />
                        <span class="relative h-6 w-11 shrink-0 rounded-full bg-stone-300 transition-colors peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-400/50 after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-5 dark:bg-neutral-700" aria-hidden="true" />
                    </label>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" for="pts_cada">Gana 1 punto por cada</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-neutral-400">S/</span>
                                <input id="pts_cada" v-model="formPuntos.soles_por_punto" type="number" step="0.01" min="0.1" inputmode="decimal" :class="[claseInput, 'pl-9']" />
                            </div>
                            <p v-if="formPuntos.errors.soles_por_punto" :class="claseError">{{ formPuntos.errors.soles_por_punto }}</p>
                            <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">de compra</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="pts_valor">Cada punto vale</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-neutral-400">S/</span>
                                <input id="pts_valor" v-model="formPuntos.valor" type="number" step="0.01" min="0.01" inputmode="decimal" :class="[claseInput, 'pl-9']" />
                            </div>
                            <p v-if="formPuntos.errors.valor" :class="claseError">{{ formPuntos.errors.valor }}</p>
                            <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">de descuento al canjear</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="pts_minimo">Se puede canjear desde <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <div class="relative">
                                <input id="pts_minimo" v-model="formPuntos.minimo_canje" type="number" step="1" min="0" inputmode="numeric" :class="[claseInput, 'pr-16']" />
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-neutral-400">puntos</span>
                            </div>
                            <p v-if="formPuntos.errors.minimo_canje" :class="claseError">{{ formPuntos.errors.minimo_canje }}</p>
                            <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Con 0 puede usar sus puntos desde el primero.</p>
                        </div>
                    </div>

                    <p v-if="ejemploPuntos" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200">
                        Con una compra de S/ 100 gana <strong>{{ ejemploPuntos.ganados }} punto{{ ejemploPuntos.ganados === 1 ? '' : 's' }}</strong>,
                        que valen <strong class="whitespace-nowrap">{{ soles(ejemploPuntos.descuento) }}</strong> de descuento.
                        Le devuelves el {{ ejemploPuntos.retorno.toFixed(1) }}% de lo que compra.
                    </p>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalPuntos = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formPuntos.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ formPuntos.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Modal crear/editar -->
        <Teleport to="body">
            <div v-if="modalAbierto" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalAbierto = false" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardar"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">{{ clienteEditar ? 'Editar cliente' : 'Nuevo cliente' }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalAbierto = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" for="cli_tipo">Tipo de documento *</label>
                            <select id="cli_tipo" v-model="form.tipo_documento_codigo" :class="claseInput">
                                <option v-for="t in tiposDocumento" :key="t.codigo" :value="t.codigo">{{ t.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="claseLabel" for="cli_numero">N° documento</label>
                            <div class="relative">
                                <input
                                    id="cli_numero"
                                    v-model="form.numero_documento"
                                    type="text"
                                    maxlength="15"
                                    :disabled="esSinDocumento(form.tipo_documento_codigo)"
                                    :class="[claseInput, 'pr-11 disabled:cursor-not-allowed disabled:bg-stone-100 dark:disabled:bg-neutral-800']"
                                    :placeholder="esSinDocumento(form.tipo_documento_codigo) ? '' : '12345678'"
                                    @blur="consultarDocumento"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                    title="Buscar en RENIEC / SUNAT"
                                    :disabled="consultando || esSinDocumento(form.tipo_documento_codigo)"
                                    @click="consultarDocumento"
                                >
                                    <LoaderCircle v-if="consultando" class="size-4 animate-spin" />
                                    <Search v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="ayudaDocumento(form.tipo_documento_codigo)" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                {{ ayudaDocumento(form.tipo_documento_codigo) }}
                            </p>
                            <p v-if="form.errors.numero_documento" :class="claseError">{{ form.errors.numero_documento }}</p>
                            <p v-if="errorConsulta" :class="claseError">{{ errorConsulta }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="cli_nombre">Nombre / Razón social *</label>
                            <input id="cli_nombre" v-model="form.nombre" type="text" :class="claseInput" placeholder="Juan Pérez" />
                            <p v-if="form.errors.nombre" :class="claseError">{{ form.errors.nombre }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="cli_direccion">Dirección</label>
                            <input id="cli_direccion" v-model="form.direccion" type="text" :class="claseInput" />
                        </div>
                        <div>
                            <label :class="claseLabel" for="cli_telefono">Teléfono</label>
                            <input id="cli_telefono" v-model="form.telefono" type="text" :class="claseInput" placeholder="999 999 999" />
                        </div>
                        <div>
                            <label :class="claseLabel" for="cli_email">Correo</label>
                            <input id="cli_email" v-model="form.email" type="email" :class="claseInput" />
                            <p v-if="form.errors.email" :class="claseError">{{ form.errors.email }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="cli_limite">Límite de crédito (S/)</label>
                            <input
                                id="cli_limite"
                                v-model="form.limite_credito"
                                type="number"
                                step="0.01"
                                min="0"
                                :disabled="!puede('clientes.credito')"
                                :class="[claseInput, 'disabled:cursor-not-allowed disabled:bg-stone-100 disabled:text-neutral-500 dark:disabled:bg-neutral-800 dark:disabled:text-neutral-400']"
                            />
                            <p v-if="form.errors.limite_credito" :class="claseError">{{ form.errors.limite_credito }}</p>
                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                <template v-if="puede('clientes.credito')">Con 0 el cliente no puede comprar al crédito (fiado).</template>
                                <template v-else>Solo un administrador puede cambiar el límite de crédito.</template>
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="modalAbierto = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ form.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
