<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { ArrowDown, ArrowUp, Bot, Building2, Pencil, Plus, SendHorizontal, Sparkles, Trash2, WandSparkles, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'
import { markdownSencillo } from '@/utils/markdown'

const props = defineProps({
    agentes: { type: Array, default: () => [] },
    rubros: { type: Array, default: () => [] },
    proveedores: { type: Array, default: () => [] },
    plantillas: { type: Object, default: () => ({}) },
    empresas: { type: Array, default: () => [] },
})

const { confirmar } = useConfirmar()

// ---- crear / editar ----
const modal = ref(false)
const editando = ref(null)
const form = useForm({
    nombre: '', rubro_codigo: '', descripcion: '', instrucciones: '',
    cadena: [], limite_mensual: 1000, max_pasos: 6, sugerencias: [], activo: true,
})

const cadenaInicial = () => {
    const listos = props.proveedores.filter((p) => p.listo)
    const primero = listos.find((p) => p.codigo === 'deepseek') ?? listos[0] ?? props.proveedores[0]
    return primero ? [{ proveedor: primero.codigo, modelo: primero.modelos[0] ?? '' }] : []
}

function abrir(a = null) {
    editando.value = a
    form.clearErrors()
    form.nombre = a?.nombre ?? ''
    form.rubro_codigo = a?.rubro_codigo ?? ''
    form.descripcion = a?.descripcion ?? ''
    form.instrucciones = a?.instrucciones ?? ''
    form.cadena = a ? a.cadena.map((e) => ({ ...e })) : cadenaInicial()
    form.limite_mensual = a?.limite_mensual ?? 1000
    form.max_pasos = a?.max_pasos ?? 6
    form.sugerencias = a ? [...a.sugerencias] : []
    form.activo = a?.activo ?? true
    modal.value = true
}

function usarPlantilla() {
    const p = props.plantillas[form.rubro_codigo] ?? props.plantillas.general
    if (!p) return
    if (!form.nombre) form.nombre = p.nombre
    if (!form.descripcion) form.descripcion = p.descripcion
    form.instrucciones = p.instrucciones
    form.sugerencias = [...p.sugerencias]
}

function agregarEslabon() {
    if (form.cadena.length >= 6) return
    const usados = form.cadena.map((e) => e.proveedor)
    const libre = props.proveedores.find((p) => !usados.includes(p.codigo)) ?? props.proveedores[0]
    form.cadena.push({ proveedor: libre?.codigo ?? '', modelo: libre?.modelos[0] ?? '' })
}
function mover(i, d) {
    const j = i + d
    if (j < 0 || j >= form.cadena.length) return
    const copia = [...form.cadena]
    ;[copia[i], copia[j]] = [copia[j], copia[i]]
    form.cadena = copia
}
const modelosDe = (codigo) => props.proveedores.find((p) => p.codigo === codigo)?.modelos ?? []
const proveedorListo = (codigo) => !!props.proveedores.find((p) => p.codigo === codigo)?.listo

function guardar() {
    const opciones = { preserveScroll: true, onSuccess: () => (modal.value = false) }
    form.transform((d) => ({ ...d, rubro_codigo: d.rubro_codigo || null, descripcion: d.descripcion || null, sugerencias: d.sugerencias.filter((s) => s.trim()) }))
    editando.value ? form.put(`/admin/ia/agentes/${editando.value.id}`, opciones) : form.post('/admin/ia/agentes', opciones)
}

async function activarRubro(a) {
    const ok = await confirmar({
        titulo: `Activar "${a.nombre}" en todo el rubro`,
        mensaje: `Se activará en las ${a.empresas_del_rubro} empresa(s) activas del rubro ${a.rubro} que aún no lo tengan. Es un adicional de pago: hazlo solo si ya está acordado con ellas.`,
        textoConfirmar: 'Activar en el rubro',
    })
    if (ok) router.post(`/admin/ia/agentes/${a.id}/activar-rubro`, {}, { preserveScroll: true })
}

// ---- probar un agente con el catálogo de una empresa ----
const modalPrueba = ref(false)
const agentePrueba = ref(null)
const prueba = ref({ empresa_id: '', texto: '', cargando: false, respuesta: null, error: null })
function abrirPrueba(a) {
    agentePrueba.value = a
    const delRubro = props.empresas.find((e) => e.rubro_codigo === a.rubro_codigo)
    prueba.value = { empresa_id: delRubro?.id ?? props.empresas[0]?.id ?? '', texto: a.sugerencias[0] ?? '', cargando: false, respuesta: null, error: null }
    modalPrueba.value = true
}
function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}
async function probar() {
    if (!prueba.value.texto.trim() || !prueba.value.empresa_id) return
    prueba.value.cargando = true
    prueba.value.error = null
    prueba.value.respuesta = null
    try {
        const r = await fetch(`/admin/ia/agentes/${agentePrueba.value.id}/probar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ empresa_id: prueba.value.empresa_id, texto: prueba.value.texto }),
        })
        const datos = await r.json()
        if (!r.ok) prueba.value.error = datos.mensaje ?? datos.message ?? 'No se pudo probar.'
        else prueba.value.respuesta = datos
    } catch (e) {
        prueba.value.error = 'Sin conexión con el servidor.'
    } finally {
        prueba.value.cargando = false
    }
}

const usd = (n) => (n === null || n === undefined ? '—' : '$ ' + Number(n).toFixed(Number(n) >= 1 ? 2 : 4))
const totalMes = computed(() => props.agentes.reduce((s, a) => s + a.costo_mes, 0))

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900'
const VERDE = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
const GRIS = 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'
</script>

<template>
    <AppLayout titulo="Agentes de IA">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Cada agente tiene las instrucciones de un rubro y una cadena de modelos: si el primero falla, responde el siguiente. Se activan por empresa (adicional de pago) o para todo un rubro.
                <span v-if="agentes.length"> Costo del mes: <b>{{ usd(totalMes) }}</b>.</span>
            </p>
            <button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700" @click="abrir()">
                <Plus class="size-4" />
                Nuevo agente
            </button>
        </div>

        <div v-if="!proveedores.some((p) => p.listo)" class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
            Ningún proveedor tiene clave todavía. Configúralos en <a href="/admin/ia/proveedores" class="font-semibold underline">Proveedores de IA</a>; sin eso los agentes no pueden responder.
        </div>

        <div v-if="!agentes.length" :class="[claseTarjeta, 'py-12 text-center text-neutral-500 dark:text-neutral-400']">
            <Bot class="mx-auto mb-2 size-8 text-neutral-300" />
            Sin agentes. Crea el primero con la plantilla de un rubro.
        </div>

        <div v-else class="grid items-start gap-4 xl:grid-cols-2">
            <section v-for="a in agentes" :key="a.id" :class="claseTarjeta" :data-agente="a.codigo">
                <div class="flex items-start gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <Bot class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="flex flex-wrap items-center gap-2 font-semibold tracking-tight">
                            {{ a.nombre }}
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="a.activo ? VERDE : GRIS">{{ a.activo ? 'Activo' : 'Inactivo' }}</span>
                        </h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ a.rubro ? `Rubro ${a.rubro}` : 'Sin rubro (general)' }}<template v-if="a.descripcion"> · {{ a.descripcion }}</template></p>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-neutral-400 hover:bg-stone-100 hover:text-neutral-700 dark:hover:bg-neutral-800" title="Editar" @click="abrir(a)"><Pencil class="size-4" /></button>
                </div>

                <ol class="mt-4 space-y-1.5">
                    <li v-for="(e, i) in a.cadena" :key="i" class="flex items-center gap-2 text-sm">
                        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-stone-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ i + 1 }}</span>
                        <span class="font-medium">{{ proveedores.find((p) => p.codigo === e.proveedor)?.nombre ?? e.proveedor }}</span>
                        <span class="truncate text-neutral-500 dark:text-neutral-400">{{ e.modelo }}</span>
                        <span v-if="!proveedorListo(e.proveedor)" class="ml-auto shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">sin clave</span>
                    </li>
                </ol>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-4">
                    <div><dt class="text-neutral-400">Empresas activas</dt><dd class="font-medium">{{ a.empresas_activas }}<span v-if="a.rubro" class="text-neutral-400"> / {{ a.empresas_del_rubro }} del rubro</span></dd></div>
                    <div><dt class="text-neutral-400">Consultas del mes</dt><dd class="font-medium">{{ a.consultas_mes.toLocaleString('es-PE') }}</dd></div>
                    <div><dt class="text-neutral-400">Costo del mes</dt><dd class="font-medium">{{ usd(a.costo_mes) }}</dd></div>
                    <div><dt class="text-neutral-400">Tope por empresa</dt><dd class="font-medium">{{ a.limite_mensual.toLocaleString('es-PE') }} / mes</dd></div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-stone-300 px-3 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="abrirPrueba(a)">
                        <Sparkles class="size-4 text-emerald-600" />
                        Probar
                    </button>
                    <button v-if="a.rubro" type="button" class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-stone-300 px-3 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="activarRubro(a)">
                        <Building2 class="size-4" />
                        Activar en todo el rubro
                    </button>
                </div>
            </section>
        </div>

        <!-- modal crear / editar -->
        <Teleport to="body">
            <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modal = false" />
                <form class="relative flex max-h-[94vh] w-full max-w-3xl flex-col rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl supports-[height:100dvh]:max-h-[94dvh] dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" @submit.prevent="guardar">
                    <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <h3 class="text-lg font-semibold tracking-tight">{{ editando ? 'Editar agente' : 'Nuevo agente' }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 dark:hover:bg-neutral-800" @click="modal = false"><X class="size-5" /></button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label :class="claseLabel" for="ag_nombre">Nombre</label>
                                <input id="ag_nombre" v-model="form.nombre" type="text" maxlength="80" :class="claseInput" placeholder="Agente agrónomo" />
                                <p v-if="form.errors.nombre" :class="claseError">{{ form.errors.nombre }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="ag_rubro">Rubro</label>
                                <div class="flex gap-2">
                                    <select id="ag_rubro" v-model="form.rubro_codigo" :class="claseInput">
                                        <option value="">General (cualquier rubro)</option>
                                        <option v-for="r in rubros" :key="r.codigo" :value="r.codigo">{{ r.nombre }}</option>
                                    </select>
                                    <button type="button" class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl border border-stone-300 px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" title="Carga las instrucciones de ejemplo del rubro" @click="usarPlantilla">
                                        <WandSparkles class="size-4 text-emerald-600" />
                                        Usar plantilla
                                    </button>
                                </div>
                                <p v-if="form.errors.rubro_codigo" :class="claseError">{{ form.errors.rubro_codigo }}</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label :class="claseLabel" for="ag_desc">Descripción corta <span class="font-normal text-neutral-400">(la ve la empresa)</span></label>
                                <input id="ag_desc" v-model="form.descripcion" type="text" maxlength="200" :class="claseInput" placeholder="Asesora sobre cultivos, plagas y productos del catálogo." />
                            </div>
                            <div class="sm:col-span-2">
                                <label :class="claseLabel" for="ag_instr">Instrucciones del agente</label>
                                <textarea id="ag_instr" v-model="form.instrucciones" rows="10" :class="[claseInput, 'h-auto py-2 font-mono text-xs leading-relaxed']" placeholder="Eres un ingeniero agrónomo…" />
                                <p v-if="form.errors.instrucciones" :class="claseError">{{ form.errors.instrucciones }}</p>
                                <p v-else class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Las normas fijas de la plataforma (solo productos del catálogo, nada de diagnósticos ni dosis inventadas) se aplican siempre, encima de estas instrucciones.</p>
                            </div>

                            <div class="sm:col-span-2">
                                <div class="mb-1.5 flex items-center justify-between">
                                    <p class="text-sm font-medium">Cadena de modelos <span class="font-normal text-neutral-400">(en orden: si uno falla, sigue el siguiente)</span></p>
                                    <button v-if="form.cadena.length < 6" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40" @click="agregarEslabon">
                                        <Plus class="size-3.5" />
                                        Agregar
                                    </button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(e, i) in form.cadena" :key="i" class="flex items-center gap-2 rounded-xl border border-stone-200 p-2 dark:border-neutral-700">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-stone-100 text-xs font-semibold dark:bg-neutral-800">{{ i + 1 }}</span>
                                        <select v-model="e.proveedor" :class="[claseInput, 'w-40 shrink-0']" @change="e.modelo = modelosDe(e.proveedor)[0] ?? e.modelo">
                                            <option v-for="p in proveedores" :key="p.codigo" :value="p.codigo">{{ p.nombre }}{{ p.listo ? '' : ' (sin clave)' }}</option>
                                        </select>
                                        <input v-model="e.modelo" type="text" :list="`cadena-modelos-${i}`" :class="claseInput" placeholder="nombre del modelo" />
                                        <datalist :id="`cadena-modelos-${i}`"><option v-for="m in modelosDe(e.proveedor)" :key="m" :value="m" /></datalist>
                                        <div class="flex shrink-0">
                                            <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 disabled:opacity-30 dark:hover:bg-neutral-800" :disabled="i === 0" title="Subir" @click="mover(i, -1)"><ArrowUp class="size-4" /></button>
                                            <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 disabled:opacity-30 dark:hover:bg-neutral-800" :disabled="i === form.cadena.length - 1" title="Bajar" @click="mover(i, 1)"><ArrowDown class="size-4" /></button>
                                            <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30 dark:hover:bg-red-950/40" :disabled="form.cadena.length === 1" title="Quitar" @click="form.cadena.splice(i, 1)"><Trash2 class="size-4" /></button>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="form.errors.cadena || Object.keys(form.errors).some((k) => k.startsWith('cadena.'))" :class="claseError">
                                    {{ form.errors.cadena || Object.entries(form.errors).find(([k]) => k.startsWith('cadena.'))?.[1] }}
                                </p>
                            </div>

                            <div>
                                <label :class="claseLabel" for="ag_limite">Tope de consultas por empresa al mes</label>
                                <input id="ag_limite" v-model.number="form.limite_mensual" type="number" min="1" :class="claseInput" />
                                <p v-if="form.errors.limite_mensual" :class="claseError">{{ form.errors.limite_mensual }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="ag_pasos">Consultas al catálogo por respuesta (máx.)</label>
                                <input id="ag_pasos" v-model.number="form.max_pasos" type="number" min="2" max="12" :class="claseInput" />
                                <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">Cuántas veces puede buscar en el catálogo antes de responder. 6 es un buen valor.</p>
                            </div>

                            <div class="sm:col-span-2">
                                <div class="mb-1.5 flex items-center justify-between">
                                    <p class="text-sm font-medium">Preguntas sugeridas <span class="font-normal text-neutral-400">(se muestran al abrir el asistente)</span></p>
                                    <button v-if="form.sugerencias.length < 6" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40" @click="form.sugerencias.push('')">
                                        <Plus class="size-3.5" />
                                        Agregar
                                    </button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(s, i) in form.sugerencias" :key="i" class="flex gap-2">
                                        <input v-model="form.sugerencias[i]" type="text" maxlength="120" :class="claseInput" placeholder="¿Qué le aplico al maíz con gusano cogollero?" />
                                        <button type="button" class="shrink-0 rounded-lg p-2 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" @click="form.sugerencias.splice(i, 1)"><Trash2 class="size-4" /></button>
                                    </div>
                                </div>
                            </div>

                            <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium sm:col-span-2">
                                <input v-model="form.activo" type="checkbox" class="size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" />
                                Agente activo (si lo desactivas, deja de responder en todas las empresas)
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <button type="button" class="h-10 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="modal = false">Cancelar</button>
                        <button type="submit" :disabled="form.processing" class="h-10 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                            {{ form.processing ? 'Guardando...' : (editando ? 'Guardar cambios' : 'Crear agente') }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- modal probar -->
        <Teleport to="body">
            <div v-if="modalPrueba" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalPrueba = false" />
                <div class="relative flex max-h-[94vh] w-full max-w-2xl flex-col rounded-2xl border border-stone-200 bg-white text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" data-modal-prueba>
                    <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-neutral-800">
                        <h3 class="text-lg font-semibold tracking-tight">Probar "{{ agentePrueba?.nombre }}"</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 dark:hover:bg-neutral-800" @click="modalPrueba = false"><X class="size-5" /></button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                        <div class="grid gap-3">
                            <div>
                                <label :class="claseLabel" for="pr_empresa">Responder con el catálogo de</label>
                                <select id="pr_empresa" v-model="prueba.empresa_id" :class="claseInput">
                                    <option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label :class="claseLabel" for="pr_texto">Consulta</label>
                                <div class="flex gap-2">
                                    <input id="pr_texto" v-model="prueba.texto" type="text" maxlength="2000" :class="claseInput" @keydown.enter.prevent="probar" />
                                    <button type="button" :disabled="prueba.cargando || !prueba.texto.trim()" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60" @click="probar">
                                        <SendHorizontal class="size-4" />
                                        {{ prueba.cargando ? 'Consultando...' : 'Enviar' }}
                                    </button>
                                </div>
                                <div v-if="agentePrueba?.sugerencias.length" class="mt-2 flex flex-wrap gap-1.5">
                                    <button v-for="s in agentePrueba.sugerencias" :key="s" type="button" class="rounded-full border border-stone-200 px-2.5 py-1 text-xs hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="prueba.texto = s">{{ s }}</button>
                                </div>
                            </div>
                        </div>
                        <p v-if="prueba.error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ prueba.error }}</p>
                        <div v-if="prueba.respuesta" class="mt-4 rounded-xl bg-stone-50 p-4 dark:bg-neutral-950">
                            <div class="space-y-1 text-sm" data-respuesta-prueba v-html="markdownSencillo(prueba.respuesta.contenido)" />
                            <p class="mt-3 border-t border-stone-200 pt-2 text-xs text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                                {{ prueba.respuesta.proveedor_codigo }} · {{ prueba.respuesta.modelo }} · {{ prueba.respuesta.duracion_ms }} ms ·
                                {{ prueba.respuesta.tokens_entrada + prueba.respuesta.tokens_salida }} tokens · {{ usd(prueba.respuesta.costo_usd) }}
                                <template v-if="prueba.respuesta.herramientas?.length"> · herramientas: {{ prueba.respuesta.herramientas.map((h) => h.nombre).join(', ') }}</template>
                            </p>
                            <p v-if="prueba.respuesta.intentos?.length" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                Saltó: {{ prueba.respuesta.intentos.map((i) => `${i.proveedor} (${i.error})`).join(' · ') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
