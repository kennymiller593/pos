<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { watchDebounced } from '@vueuse/core'
import { Building2, LoaderCircle, Pencil, Plus, Search, Trash2, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { usePermisos } from '@/composables/permisos'

const props = defineProps({
    proveedores: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
})

const { confirmar } = useConfirmar()
const { puede } = usePermisos()
const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`

// ---- filtros ----
const buscar = ref(props.filtros.buscar ?? '')

watchDebounced(buscar, () => {
    router.get('/proveedores', { buscar: buscar.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })
}, { debounce: 350 })

// ---- modal ----
const modalAbierto = ref(false)
const proveedorEditar = ref(null)

const form = useForm({
    ruc: '',
    razon_social: '',
    contacto: '',
    telefono: '',
})

function abrir(proveedor = null) {
    proveedorEditar.value = proveedor
    form.clearErrors()
    errorConsulta.value = ''
    form.ruc = proveedor?.ruc?.trim() ?? ''
    form.razon_social = proveedor?.razon_social ?? ''
    form.contacto = proveedor?.contacto ?? ''
    form.telefono = proveedor?.telefono ?? ''
    modalAbierto.value = true
}

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (modalAbierto.value = false),
    }
    const transformar = (data) => ({
        ...data,
        ruc: data.ruc || null,
        contacto: data.contacto || null,
        telefono: data.telefono || null,
    })

    if (proveedorEditar.value) {
        form.transform(transformar).put(`/proveedores/${proveedorEditar.value.id}`, opciones)
    } else {
        form.transform(transformar).post('/proveedores', opciones)
    }
}

async function eliminar(proveedor) {
    const confirmado = await confirmar({
        titulo: 'Eliminar proveedor',
        mensaje: `"${proveedor.razon_social}" dejará de aparecer, pero sus compras históricas se conservan.`,
        textoConfirmar: 'Eliminar',
        peligro: true,
    })
    if (confirmado) {
        router.delete(`/proveedores/${proveedor.id}`, { preserveScroll: true })
    }
}

// ---- lookup RUC ----
const consultando = ref(false)
const errorConsulta = ref('')

async function consultarRuc() {
    const ruc = form.ruc.trim()
    if (!/^\d{11}$/.test(ruc) || consultando.value) return

    consultando.value = true
    errorConsulta.value = ''
    try {
        const r = await fetch(`/consultas/ruc/${ruc}`, { headers: { Accept: 'application/json' } })
        const datos = await r.json()
        if (!r.ok) {
            errorConsulta.value = datos.message ?? 'No se pudo consultar el RUC.'
            return
        }
        if (datos.razon_social) form.razon_social = datos.razon_social
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
    <AppLayout titulo="Proveedores">
        <!-- Barra de acciones -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="buscar"
                    type="text"
                    placeholder="Buscar por razón social o RUC..."
                    class="h-10 w-full rounded-xl border border-stone-200 bg-white pr-4 pl-10 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-800 dark:bg-neutral-900 dark:placeholder-neutral-500"
                />
            </div>
            <button
                v-if="puede('proveedores.gestionar')"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                @click="abrir()"
            >
                <Plus class="size-4" />
                Nuevo proveedor
            </button>
        </div>

        <!-- Tabla -->
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Proveedor</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">RUC</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Teléfono</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Compras</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Total comprado</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!proveedores.data.length">
                            <td colspan="6" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <Building2 class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay proveedores que mostrar.
                                    <button v-if="puede('proveedores.gestionar')" class="ml-1 font-medium text-emerald-600 hover:underline dark:text-emerald-400" @click="abrir()">
                                        Crea el primero
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="p in proveedores.data" :key="p.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="grid size-9 shrink-0 place-items-center rounded-xl bg-stone-100 text-neutral-400 dark:bg-neutral-800 dark:text-neutral-500">
                                        <Building2 class="size-5" />
                                    </div>
                                    <div>
                                        <p class="font-medium">{{ p.razon_social }}</p>
                                        <p v-if="p.contacto" class="text-xs text-neutral-500 dark:text-neutral-400">{{ p.contacto }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ p.ruc?.trim() ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ p.telefono ?? '—' }}</td>
                            <td class="px-4 py-3 text-center text-neutral-600 dark:text-neutral-300">{{ p.compras_count }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ soles(p.total_comprado) }}</td>
                            <td class="px-4 py-3">
                                <div v-if="puede('proveedores.gestionar')" class="flex justify-end gap-1">
                                    <button
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        title="Editar"
                                        @click="abrir(p)"
                                    >
                                        <Pencil class="size-4" />
                                    </button>
                                    <button
                                        class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                        title="Eliminar"
                                        @click="eliminar(p)"
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
                v-if="proveedores.data.length"
                class="flex flex-col items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-neutral-500 sm:flex-row dark:border-neutral-800 dark:text-neutral-400"
            >
                <span>Mostrando {{ proveedores.from }}–{{ proveedores.to }} de {{ proveedores.total }} proveedores</span>
                <div class="flex flex-wrap gap-1.5">
                    <template v-for="(link, i) in proveedores.links" :key="i">
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

        <!-- Modal crear/editar -->
        <Teleport to="body">
            <div v-if="modalAbierto" class="fixed inset-0 z-50 grid place-items-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalAbierto = false" />
                <form
                    class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardar"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">{{ proveedorEditar ? 'Editar proveedor' : 'Nuevo proveedor' }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalAbierto = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label :class="claseLabel" for="p_ruc">RUC</label>
                            <div class="relative">
                                <input
                                    id="p_ruc"
                                    v-model="form.ruc"
                                    type="text"
                                    maxlength="11"
                                    inputmode="numeric"
                                    :class="[claseInput, 'pr-11']"
                                    placeholder="20123456789"
                                    @blur="consultarRuc"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-neutral-400 hover:bg-stone-100 hover:text-emerald-600 dark:hover:bg-neutral-800 dark:hover:text-emerald-400"
                                    title="Buscar en SUNAT"
                                    :disabled="consultando"
                                    @click="consultarRuc"
                                >
                                    <LoaderCircle v-if="consultando" class="size-4 animate-spin" />
                                    <Search v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="form.errors.ruc" :class="claseError">{{ form.errors.ruc }}</p>
                            <p v-if="errorConsulta" :class="claseError">{{ errorConsulta }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="p_razon">Razón social *</label>
                            <input id="p_razon" v-model="form.razon_social" type="text" :class="claseInput" placeholder="Distribuidora S.A.C." />
                            <p v-if="form.errors.razon_social" :class="claseError">{{ form.errors.razon_social }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="claseLabel" for="p_contacto">Contacto</label>
                                <input id="p_contacto" v-model="form.contacto" type="text" :class="claseInput" placeholder="Juan Díaz" />
                            </div>
                            <div>
                                <label :class="claseLabel" for="p_telefono">Teléfono</label>
                                <input id="p_telefono" v-model="form.telefono" type="text" :class="claseInput" placeholder="999 999 999" />
                            </div>
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
