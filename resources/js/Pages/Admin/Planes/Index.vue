<script setup>
import { reactive } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Building2, CreditCard } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    planes: { type: Array, default: () => [] },
})

const LIMITES = [
    { clave: 'max_sucursales', label: 'Máx. sucursales' },
    { clave: 'max_usuarios', label: 'Máx. usuarios' },
    { clave: 'max_comprobantes_mes', label: 'Máx. comprobantes/mes' },
]

// un formulario por plan; "ilimitado" vive aparte y al enviar manda null
function crearEditor(p) {
    const ilimitado = reactive({})
    const valores = {}
    for (const l of LIMITES) {
        ilimitado[l.clave] = p[l.clave] === null || p[l.clave] === undefined
        valores[l.clave] = ilimitado[l.clave] ? '' : p[l.clave]
    }
    const form = useForm({
        nombre: p.nombre ?? '',
        descripcion: p.descripcion ?? '',
        precio_mensual: p.precio_mensual ?? 0,
        ...valores,
        activo: p.codigo === 'prueba' ? true : !!p.activo,
        orden: p.orden ?? 0,
    })
    return { plan: p, form, ilimitado }
}

const editores = props.planes.map(crearEditor)

function guardar(ed) {
    ed.form
        .transform((d) => {
            const datos = { ...d, descripcion: d.descripcion?.trim() || null }
            for (const l of LIMITES) {
                datos[l.clave] = ed.ilimitado[l.clave] || d[l.clave] === '' ? null : Number(d[l.clave])
            }
            if (ed.plan.codigo === 'prueba') datos.activo = true
            return datos
        })
        .put(`/admin/planes/${ed.plan.id}`, { preserveScroll: true })
}

function marcarIlimitado(ed, clave) {
    if (!ed.ilimitado[clave] && ed.form[clave] === '') ed.form[clave] = 1
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Planes">
        <div class="mx-auto max-w-6xl">
            <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">
                Edita nombre, precio y límites de cada plan. Los cambios aplican a todas las empresas con ese plan.
            </p>

            <div v-if="!editores.length" class="rounded-2xl border border-stone-200 bg-white px-4 py-12 text-center text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400">
                <CreditCard class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                No hay planes registrados.
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <form
                    v-for="ed in editores"
                    :key="ed.plan.id"
                    class="rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"
                    @submit.prevent="guardar(ed)"
                >
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                                {{ ed.plan.codigo }}
                            </p>
                            <p class="truncate text-lg font-semibold tracking-tight">{{ ed.form.nombre || ed.plan.nombre }}</p>
                            <p class="flex items-center gap-1.5 text-sm text-neutral-500 dark:text-neutral-400">
                                <Building2 class="size-4" />
                                {{ ed.plan.empresas_activas ?? 0 }} empresa{{ (ed.plan.empresas_activas ?? 0) === 1 ? '' : 's' }} con este plan
                            </p>
                        </div>
                        <span
                            class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="ed.plan.activo
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                : 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'"
                        >
                            {{ ed.plan.activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label :class="claseLabel" :for="`p_nombre_${ed.plan.id}`">Nombre *</label>
                            <input :id="`p_nombre_${ed.plan.id}`" v-model="ed.form.nombre" type="text" :class="claseInput" />
                            <p v-if="ed.form.errors.nombre" :class="claseError">{{ ed.form.errors.nombre }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" :for="`p_precio_${ed.plan.id}`">Precio mensual (S/) *</label>
                            <input :id="`p_precio_${ed.plan.id}`" v-model="ed.form.precio_mensual" type="number" min="0" step="0.01" :class="claseInput" />
                            <p v-if="ed.form.errors.precio_mensual" :class="claseError">{{ ed.form.errors.precio_mensual }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" :for="`p_desc_${ed.plan.id}`">Descripción</label>
                            <textarea
                                :id="`p_desc_${ed.plan.id}`"
                                v-model="ed.form.descripcion"
                                rows="2"
                                :class="[claseInput, 'h-auto py-2']"
                            />
                            <p v-if="ed.form.errors.descripcion" :class="claseError">{{ ed.form.errors.descripcion }}</p>
                        </div>

                        <div v-for="l in LIMITES" :key="l.clave">
                            <label :class="claseLabel" :for="`p_${l.clave}_${ed.plan.id}`">{{ l.label }}</label>
                            <input
                                :id="`p_${l.clave}_${ed.plan.id}`"
                                v-model="ed.form[l.clave]"
                                type="number"
                                min="1"
                                step="1"
                                :disabled="ed.ilimitado[l.clave]"
                                :placeholder="ed.ilimitado[l.clave] ? 'Ilimitado' : ''"
                                :class="claseInput"
                            />
                            <label class="mt-1.5 flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-300">
                                <input
                                    v-model="ed.ilimitado[l.clave]"
                                    type="checkbox"
                                    class="size-4 rounded accent-emerald-600"
                                    @change="marcarIlimitado(ed, l.clave)"
                                />
                                Ilimitado
                            </label>
                            <p v-if="ed.form.errors[l.clave]" :class="claseError">{{ ed.form.errors[l.clave] }}</p>
                        </div>

                        <div>
                            <label :class="claseLabel" :for="`p_orden_${ed.plan.id}`">Orden</label>
                            <input :id="`p_orden_${ed.plan.id}`" v-model.number="ed.form.orden" type="number" min="0" step="1" :class="claseInput" />
                            <p v-if="ed.form.errors.orden" :class="claseError">{{ ed.form.errors.orden }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-4 dark:border-neutral-800">
                        <div>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="ed.form.activo"
                                    type="checkbox"
                                    class="size-4 rounded accent-emerald-600"
                                    :disabled="ed.plan.codigo === 'prueba'"
                                />
                                Plan activo
                            </label>
                            <p v-if="ed.plan.codigo === 'prueba'" class="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">
                                La prueba siempre está activa
                            </p>
                            <p v-if="ed.form.errors.activo" :class="claseError">{{ ed.form.errors.activo }}</p>
                        </div>
                        <button
                            type="submit"
                            :disabled="ed.form.processing"
                            class="inline-flex h-10 items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ ed.form.processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
