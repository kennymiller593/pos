<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { Eye, EyeOff, Pencil, Plus, Trash2, UserCog, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    usuarios: { type: Object, required: true },
    roles: { type: Array, required: true },
    sucursales: { type: Array, required: true },
    // { admin: ['descripcion', ...], cajero: [...], ... }
    permisosPorRol: { type: Object, default: () => ({}) },
})

const page = usePage()
const miId = computed(() => page.props.auth?.user?.id)

const COLORES_ROL = {
    admin: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    cajero: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
    vendedor: 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
    almacenero: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
}

// descripciones de lo que puede hacer el rol elegido en el formulario
const permisosRolElegido = computed(() => {
    const codigo = props.roles.find((r) => r.id === form.rol_id)?.codigo
    return (codigo && props.permisosPorRol[codigo]) || []
})

// ---- modal ----
const modalAbierto = ref(false)
const usuarioEditar = ref(null)
const verPassword = ref(false)

const form = useForm({
    nombre_completo: '',
    email: '',
    rol_id: '',
    sucursal_ids: [],
    password: '',
    password_confirmation: '',
    activo: true,
    enviar_correo: true,
})

function abrir(usuario = null) {
    usuarioEditar.value = usuario
    form.clearErrors()
    verPassword.value = false
    form.nombre_completo = usuario?.nombre_completo ?? ''
    form.email = usuario?.email ?? ''
    form.rol_id = usuario?.rol_id ?? props.roles.find((r) => r.codigo === 'cajero')?.id ?? ''
    form.sucursal_ids = usuario?.sucursales?.map((s) => s.id) ?? []
    form.password = ''
    form.password_confirmation = ''
    form.activo = usuario?.activo ?? true
    form.enviar_correo = true
    modalAbierto.value = true
}

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (modalAbierto.value = false),
    }
    const transformar = (data) => ({
        ...data,
        password: data.password || null,
        password_confirmation: data.password_confirmation || null,
    })

    if (usuarioEditar.value) {
        form.transform(transformar).put(`/usuarios/${usuarioEditar.value.id}`, opciones)
    } else {
        form.transform(transformar).post('/usuarios', opciones)
    }
}

// ---- eliminar: solo usuarios sin historial (ventas, cajas, compras...); al resto se les desactiva ----
const { confirmar } = useConfirmar()

function motivoNoEliminable(u) {
    if (u.id === miId.value) return 'No puedes eliminarte a ti mismo'
    if (u.es_superadmin) return 'Administra la plataforma'
    if (u.con_historial) return 'Ya tiene movimientos registrados: desactívalo en lugar de eliminarlo'
    return null
}

async function eliminar(u) {
    const ok = await confirmar({
        titulo: `Eliminar a ${u.nombre_completo}`,
        mensaje: 'Se borrará su cuenta y ya no podrá ingresar. Esta acción no se puede deshacer.',
        textoConfirmar: 'Eliminar',
        peligro: true,
    })
    if (ok) router.delete(`/usuarios/${u.id}`, { preserveScroll: true })
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
</script>

<template>
    <AppLayout titulo="Usuarios">
        <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                {{ usuarios.total }} usuario{{ usuarios.total === 1 ? '' : 's' }} en tu negocio
            </p>
            <button
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                @click="abrir()"
            >
                <Plus class="size-4" />
                Nuevo usuario
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div class="@container overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:text-neutral-500">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Usuario</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Rol</th>
                            <th class="px-4 py-3.5 font-semibold tracking-wider">Sucursales</th>
                            <th class="px-4 py-3.5 text-center font-semibold tracking-wider">Estado</th>
                            <th class="px-4 py-3.5 text-right font-semibold tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                        <tr v-if="!usuarios.data.length">
                            <td colspan="5" class="px-4 py-12 text-center text-neutral-500 dark:text-neutral-400">
                                <div class="sticky left-4 max-w-[calc(100cqw-2rem)]">
                                    <UserCog class="mx-auto mb-2 size-8 text-neutral-300 dark:text-neutral-600" />
                                    No hay usuarios que mostrar.
                                </div>
                            </td>
                        </tr>
                        <tr v-for="u in usuarios.data" :key="u.id" class="transition-colors hover:bg-stone-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="grid size-9 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                        {{ u.nombre_completo.charAt(0).toUpperCase() }}
                                    </div>
                                    <div>
                                        <p class="font-medium">
                                            {{ u.nombre_completo }}
                                            <span
                                                v-if="u.id === miId"
                                                class="ml-1.5 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400"
                                            >
                                                Tú
                                            </span>
                                        </p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ u.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="COLORES_ROL[u.rol?.codigo] ?? 'bg-stone-100 text-neutral-500'"
                                >
                                    {{ u.rol?.nombre ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                {{ u.sucursales?.length ? u.sucursales.map((s) => s.nombre).join(', ') : 'Todas' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="u.activo
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                        : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'"
                                >
                                    {{ u.activo ? 'Activo' : 'Desactivado' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    class="rounded-lg p-2 text-neutral-500 hover:bg-stone-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                    title="Editar"
                                    @click="abrir(u)"
                                >
                                    <Pencil class="size-4" />
                                </button>
                                <button
                                    class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-neutral-500 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                                    :title="motivoNoEliminable(u) ?? 'Eliminar'"
                                    :aria-label="`Eliminar a ${u.nombre_completo}`"
                                    :disabled="!!motivoNoEliminable(u)"
                                    @click="eliminar(u)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div
                v-if="usuarios.data.length && usuarios.last_page > 1"
                class="flex flex-wrap justify-end gap-1.5 border-t border-stone-200 px-4 py-3 dark:border-neutral-800"
            >
                <template v-for="(link, i) in usuarios.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded-lg border px-3.5 py-1.5 text-sm"
                        :class="link.active
                            ? 'border-emerald-600 bg-emerald-600 font-semibold text-white'
                            : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>

        <!-- Modal crear/editar -->
        <Teleport to="body">
            <div v-if="modalAbierto" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalAbierto = false" />
                <form
                    class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                    @submit.prevent="guardar"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-semibold tracking-tight">{{ usuarioEditar ? 'Editar usuario' : 'Nuevo usuario' }}</h3>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="modalAbierto = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="u_nombre">Nombre completo *</label>
                            <input id="u_nombre" v-model="form.nombre_completo" type="text" :class="claseInput" placeholder="María Torres" />
                            <p v-if="form.errors.nombre_completo" :class="claseError">{{ form.errors.nombre_completo }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="u_email">Correo *</label>
                            <input id="u_email" v-model="form.email" type="email" :class="claseInput" placeholder="maria@negocio.com" />
                            <p v-if="form.errors.email" :class="claseError">{{ form.errors.email }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel" for="u_rol">Rol *</label>
                            <select id="u_rol" v-model="form.rol_id" :class="claseInput">
                                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                            </select>
                            <p v-if="form.errors.rol_id" :class="claseError">{{ form.errors.rol_id }}</p>
                            <div
                                v-if="permisosRolElegido.length"
                                class="mt-2 rounded-xl bg-stone-50 px-3 py-2 dark:bg-neutral-950/60"
                            >
                                <p class="mb-1 text-[11px] font-semibold tracking-wider text-neutral-400 uppercase">Este rol puede</p>
                                <ul class="max-h-36 space-y-0.5 overflow-y-auto text-xs text-neutral-600 dark:text-neutral-300">
                                    <li v-for="d in permisosRolElegido" :key="d" class="flex gap-1.5">
                                        <span class="text-emerald-600 dark:text-emerald-400">•</span>
                                        <span>{{ d }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="claseLabel">Sucursales donde trabaja</label>
                            <div class="flex flex-wrap gap-x-5 gap-y-2 rounded-xl border border-stone-300 px-3 py-2.5 dark:border-neutral-700">
                                <label v-for="s in sucursales" :key="s.id" class="flex items-center gap-2 text-sm">
                                    <input
                                        v-model="form.sucursal_ids"
                                        type="checkbox"
                                        :value="s.id"
                                        class="size-4 rounded accent-emerald-600"
                                    />
                                    {{ s.nombre }}
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                Sin marcar ninguna, tendrá acceso a todas las sucursales.
                            </p>
                            <p v-if="form.errors.sucursal_ids" :class="claseError">{{ form.errors.sucursal_ids }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="u_password">
                                {{ usuarioEditar ? 'Nueva contraseña' : 'Contraseña *' }}
                            </label>
                            <div class="relative">
                                <input
                                    id="u_password"
                                    v-model="form.password"
                                    :type="verPassword ? 'text' : 'password'"
                                    autocomplete="new-password"
                                    :class="[claseInput, 'pr-10']"
                                    :placeholder="usuarioEditar ? 'Dejar vacío para no cambiar' : 'Mínimo 8 caracteres'"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-2.5 -translate-y-1/2 rounded-md p-0.5 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300"
                                    @click="verPassword = !verPassword"
                                >
                                    <EyeOff v-if="verPassword" class="size-4" />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <p v-if="form.errors.password" :class="claseError">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label :class="claseLabel" for="u_password2">Repite la contraseña</label>
                            <input
                                id="u_password2"
                                v-model="form.password_confirmation"
                                :type="verPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                :class="claseInput"
                                placeholder="••••••••"
                            />
                        </div>
                        <div class="flex items-center sm:col-span-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="form.activo"
                                    type="checkbox"
                                    class="size-4 rounded accent-emerald-600"
                                    :disabled="usuarioEditar?.id === miId"
                                />
                                Usuario activo
                            </label>
                            <span v-if="usuarioEditar?.id === miId" class="ml-2 text-xs text-neutral-400 dark:text-neutral-500">
                                (no puedes desactivarte a ti mismo)
                            </span>
                        </div>
                        <div v-if="!usuarioEditar" class="flex items-start gap-3 rounded-xl bg-stone-50 p-3 sm:col-span-2 dark:bg-neutral-800/60">
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="form.enviar_correo"
                                aria-label="Enviar datos de acceso por correo"
                                class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                                :class="form.enviar_correo ? 'bg-indigo-600 dark:bg-emerald-600' : 'bg-slate-300 dark:bg-neutral-700'"
                                @click="form.enviar_correo = !form.enviar_correo"
                            >
                                <span
                                    class="inline-block size-5 rounded-full bg-white shadow transition-transform"
                                    :class="form.enviar_correo ? 'translate-x-5' : 'translate-x-0.5'"
                                />
                            </button>
                            <span class="cursor-pointer text-sm select-none" @click="form.enviar_correo = !form.enviar_correo">
                                <span class="font-medium">Enviar datos de acceso por correo</span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ form.enviar_correo
                                        ? 'Le llegará su correo, su contraseña y un botón para ingresar.'
                                        : 'No se enviará nada; compártele sus datos tú mismo.' }}
                                </span>
                            </span>
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
