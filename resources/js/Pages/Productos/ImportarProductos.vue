<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Upload, X, XCircle } from '@lucide/vue'

const props = defineProps({
    abierto: { type: Boolean, default: false },
})

const emit = defineEmits(['cerrar'])

// ---- estado ----
const paso = ref(1)
const archivo = ref(null)
const inputArchivo = ref(null)
const arrastrando = ref(false)
const revisando = ref(false)
const importando = ref(false)
const errores = ref([])
const resultado = ref(null)
const filtro = ref('todas')

function resetear() {
    paso.value = 1
    archivo.value = null
    arrastrando.value = false
    revisando.value = false
    importando.value = false
    errores.value = []
    resultado.value = null
    filtro.value = 'todas'
    if (inputArchivo.value) inputArchivo.value.value = ''
}

watch(
    () => props.abierto,
    (abierto) => {
        if (abierto) resetear()
    },
)

function cerrar() {
    if (importando.value) return
    emit('cerrar')
}

// ---- paso 1: archivo ----
function asignarArchivo(f) {
    errores.value = []
    if (!f) return
    if (!/\.xlsx$/i.test(f.name)) {
        archivo.value = null
        errores.value = ['El archivo debe ser un Excel (.xlsx).']
        return
    }
    archivo.value = f
}

function seleccionarArchivo(evento) {
    asignarArchivo(evento.target.files?.[0])
}

function soltarArchivo(evento) {
    arrastrando.value = false
    asignarArchivo(evento.dataTransfer?.files?.[0])
}

function tamanio(bytes) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

async function revisar() {
    if (!archivo.value || revisando.value) return
    revisando.value = true
    errores.value = []

    const datos = new FormData()
    datos.append('archivo', archivo.value)

    try {
        const respuesta = await fetch('/productos/importar/previsualizar', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: datos,
        })
        const json = await respuesta.json().catch(() => ({}))

        if (!respuesta.ok) {
            if (json.errors) {
                errores.value = Object.values(json.errors).flat()
            } else {
                errores.value = [json.message || 'No se pudo revisar el archivo. Intenta nuevamente.']
            }
            return
        }

        resultado.value = json
        filtro.value = 'todas'
        paso.value = 2
    } catch {
        errores.value = ['No se pudo conectar con el servidor. Revisa tu conexión e intenta nuevamente.']
    } finally {
        revisando.value = false
    }
}

// ---- paso 2: vista previa ----
const resumen = computed(() => resultado.value?.resumen ?? { total: 0, crear: 0, actualizar: 0, errores: 0, advertencias: 0 })
const aImportar = computed(() => Number(resumen.value.crear ?? 0) + Number(resumen.value.actualizar ?? 0))

const filasFiltradas = computed(() => {
    const filas = resultado.value?.filas ?? []
    if (filtro.value === 'errores') return filas.filter((f) => f.estado === 'error')
    if (filtro.value === 'advertencias') return filas.filter((f) => f.estado === 'advertencia')
    return filas
})

const filtros = computed(() => [
    { valor: 'todas', texto: 'Todas', cantidad: resumen.value.total },
    { valor: 'errores', texto: 'Con errores', cantidad: resumen.value.errores },
    { valor: 'advertencias', texto: 'Con advertencias', cantidad: resumen.value.advertencias },
])

function elegirOtro() {
    resetear()
}

function importar() {
    if (!aImportar.value || importando.value) return
    router.post(
        '/productos/importar',
        { token: resultado.value.token },
        {
            preserveScroll: true,
            onStart: () => (importando.value = true),
            onFinish: () => (importando.value = false),
            onSuccess: () => {
                importando.value = false
                emit('cerrar')
                resetear()
            },
        },
    )
}

function dinero(valor) {
    if (valor === null || valor === undefined || valor === '') return '—'
    const n = Number(valor)
    return Number.isFinite(n) ? `S/ ${n.toFixed(2)}` : String(valor)
}

function texto(valor) {
    if (valor === null || valor === undefined || valor === '') return '—'
    return valor
}

function presentaciones(valor) {
    if (valor === null || valor === undefined || valor === '') return '—'
    if (Array.isArray(valor)) {
        if (!valor.length) return '—'
        return valor.map((p) => (typeof p === 'object' ? (p.nombre ?? p.unidad ?? JSON.stringify(p)) : p)).join(', ')
    }
    return valor
}

const claseEstado = {
    ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    advertencia: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    error: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
}
const textoEstado = { ok: 'OK', advertencia: 'Advertencia', error: 'Error' }
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="fixed inset-0 bg-neutral-950/60" @click="cerrar" />

            <div class="relative mx-auto my-4 w-full max-w-5xl px-3 sm:my-8 sm:px-4">
                <div class="rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100">
                    <!-- Encabezado -->
                    <div class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-4 sm:px-6 dark:border-neutral-800">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                                <FileSpreadsheet class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold tracking-tight">Importar productos</h2>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    Paso {{ paso }} de 2 · {{ paso === 1 ? 'Subir archivo' : 'Vista previa' }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="cerrar"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <!-- Paso 1: subir -->
                    <template v-if="paso === 1">
                        <div class="px-4 py-5 sm:px-6">
                            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                                Carga tus productos en minutos: descarga la plantilla, llénala y súbela. Solo
                                <strong class="font-semibold text-neutral-900 dark:text-neutral-100">nombre</strong> y
                                <strong class="font-semibold text-neutral-900 dark:text-neutral-100">precio de venta</strong>
                                son obligatorios.
                            </p>

                            <a
                                href="/productos/importar/plantilla"
                                class="mt-4 inline-flex items-center gap-2 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            >
                                <Download class="size-4" />
                                Descargar plantilla
                            </a>

                            <div
                                class="mt-5 flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-4 py-10 text-center transition-colors"
                                :class="arrastrando
                                    ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10'
                                    : 'border-stone-300 hover:border-emerald-400 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800/50'"
                                role="button"
                                tabindex="0"
                                @click="inputArchivo?.click()"
                                @keydown.enter.prevent="inputArchivo?.click()"
                                @dragover.prevent="arrastrando = true"
                                @dragenter.prevent="arrastrando = true"
                                @dragleave.prevent="arrastrando = false"
                                @drop.prevent="soltarArchivo"
                            >
                                <template v-if="archivo">
                                    <FileSpreadsheet class="mb-2 size-10 text-emerald-600 dark:text-emerald-400" />
                                    <p class="text-sm font-semibold break-all">{{ archivo.name }}</p>
                                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ tamanio(archivo.size) }} · Haz clic para cambiar el archivo
                                    </p>
                                </template>
                                <template v-else>
                                    <Upload class="mb-2 size-10 text-neutral-300 dark:text-neutral-600" />
                                    <p class="text-sm font-medium">
                                        Arrastra tu archivo aquí o
                                        <span class="text-emerald-600 dark:text-emerald-400">elige uno</span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">Solo archivos Excel (.xlsx)</p>
                                </template>
                                <input
                                    ref="inputArchivo"
                                    type="file"
                                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    class="hidden"
                                    @change="seleccionarArchivo"
                                />
                            </div>

                            <div
                                v-if="errores.length"
                                class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300"
                            >
                                <p v-for="(e, i) in errores" :key="i">{{ e }}</p>
                            </div>
                        </div>

                        <div class="flex flex-col-reverse gap-2 border-t border-stone-200 px-4 py-4 sm:flex-row sm:justify-end sm:px-6 dark:border-neutral-800">
                            <button
                                type="button"
                                class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="cerrar"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                :disabled="!archivo || revisando"
                                class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="revisar"
                            >
                                {{ revisando ? 'Revisando...' : 'Revisar archivo' }}
                            </button>
                        </div>
                    </template>

                    <!-- Paso 2: vista previa -->
                    <template v-else>
                        <div class="px-4 py-5 sm:px-6">
                            <!-- Resumen -->
                            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                <div class="rounded-xl border border-stone-200 p-3 dark:border-neutral-800">
                                    <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Nuevos</p>
                                    <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ resumen.crear }}</p>
                                </div>
                                <div class="rounded-xl border border-stone-200 p-3 dark:border-neutral-800">
                                    <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Se actualizarán</p>
                                    <p class="mt-1 text-2xl font-semibold text-sky-600 dark:text-sky-400">{{ resumen.actualizar }}</p>
                                </div>
                                <div class="rounded-xl border border-stone-200 p-3 dark:border-neutral-800">
                                    <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Con errores</p>
                                    <p class="mt-1 text-2xl font-semibold" :class="resumen.errores ? 'text-red-600 dark:text-red-400' : ''">{{ resumen.errores }}</p>
                                </div>
                                <div class="rounded-xl border border-stone-200 p-3 dark:border-neutral-800">
                                    <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Con advertencias</p>
                                    <p class="mt-1 text-2xl font-semibold" :class="resumen.advertencias ? 'text-amber-600 dark:text-amber-400' : ''">{{ resumen.advertencias }}</p>
                                </div>
                            </div>

                            <div
                                v-if="resumen.errores"
                                class="mt-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300"
                            >
                                <XCircle class="mt-0.5 size-4 shrink-0" />
                                <p>Las filas con errores no se importan; corrígelas en el Excel y vuelve a subirlo.</p>
                            </div>

                            <!-- Filtro -->
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-for="f in filtros"
                                    :key="f.valor"
                                    type="button"
                                    class="rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="filtro === f.valor
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-stone-200 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                                    @click="filtro = f.valor"
                                >
                                    {{ f.texto }}
                                    <span class="ml-1 opacity-75">{{ f.cantidad }}</span>
                                </button>
                            </div>

                            <!-- Tabla -->
                            <div class="mt-3 max-h-[55vh] overflow-auto rounded-xl border border-stone-200 dark:border-neutral-800">
                                <table class="w-full min-w-[820px] text-left text-sm">
                                    <thead class="sticky top-0 z-10 border-b border-stone-200 bg-stone-50 text-xs text-neutral-400 uppercase dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-500">
                                        <tr>
                                            <th class="px-3 py-3 font-semibold tracking-wider">Fila</th>
                                            <th class="px-3 py-3 font-semibold tracking-wider">Nombre</th>
                                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Precio venta</th>
                                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Precio compra</th>
                                            <th class="px-3 py-3 text-right font-semibold tracking-wider">Stock inicial</th>
                                            <th class="px-3 py-3 font-semibold tracking-wider">Unidad</th>
                                            <th class="px-3 py-3 font-semibold tracking-wider">Presentaciones</th>
                                            <th class="px-3 py-3 text-center font-semibold tracking-wider">Acción</th>
                                            <th class="px-3 py-3 text-center font-semibold tracking-wider">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                        <tr v-if="!filasFiltradas.length">
                                            <td colspan="9" class="px-3 py-10 text-center text-neutral-500 dark:text-neutral-400">
                                                No hay filas que mostrar.
                                            </td>
                                        </tr>
                                        <tr
                                            v-for="f in filasFiltradas"
                                            :key="f.fila"
                                            class="align-top"
                                            :class="f.estado === 'error' ? 'bg-red-50/50 dark:bg-red-950/20' : ''"
                                        >
                                            <td class="px-3 py-2.5 font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ f.fila }}</td>
                                            <td class="px-3 py-2.5">
                                                <p class="font-medium">{{ texto(f.nombre) }}</p>
                                                <p
                                                    v-for="(e, i) in f.errores ?? []"
                                                    :key="`e${i}`"
                                                    class="mt-0.5 text-xs text-red-600 dark:text-red-400"
                                                >
                                                    {{ e }}
                                                </p>
                                                <p
                                                    v-for="(a, i) in f.advertencias ?? []"
                                                    :key="`a${i}`"
                                                    class="mt-0.5 text-xs text-amber-600 dark:text-amber-400"
                                                >
                                                    {{ a }}
                                                </p>
                                            </td>
                                            <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ dinero(f.precio_venta) }}</td>
                                            <td class="px-3 py-2.5 text-right whitespace-nowrap text-neutral-600 dark:text-neutral-300">{{ dinero(f.precio_compra) }}</td>
                                            <td class="px-3 py-2.5 text-right text-neutral-600 dark:text-neutral-300">{{ texto(f.stock_inicial) }}</td>
                                            <td class="px-3 py-2.5 text-neutral-600 dark:text-neutral-300">{{ texto(f.unidad) }}</td>
                                            <td class="px-3 py-2.5 text-neutral-600 dark:text-neutral-300">{{ presentaciones(f.presentaciones) }}</td>
                                            <td class="px-3 py-2.5 text-center">
                                                <span
                                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                                    :class="f.accion === 'crear'
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
                                                        : 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300'"
                                                >
                                                    {{ f.accion === 'crear' ? 'Nuevo' : 'Actualiza' }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5 text-center">
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                                    :class="claseEstado[f.estado] ?? claseEstado.ok"
                                                >
                                                    <XCircle v-if="f.estado === 'error'" class="size-3.5" />
                                                    <AlertTriangle v-else-if="f.estado === 'advertencia'" class="size-3.5" />
                                                    <CheckCircle2 v-else class="size-3.5" />
                                                    {{ textoEstado[f.estado] ?? f.estado }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="flex flex-col-reverse gap-2 border-t border-stone-200 px-4 py-4 sm:flex-row sm:justify-end sm:px-6 dark:border-neutral-800">
                            <button
                                type="button"
                                :disabled="importando"
                                class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                @click="elegirOtro"
                            >
                                Elegir otro archivo
                            </button>
                            <button
                                type="button"
                                :disabled="!aImportar || importando"
                                class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="importar"
                            >
                                {{ importando ? 'Importando...' : `Importar ${aImportar} ${aImportar === 1 ? 'producto' : 'productos'}` }}
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>
