<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Activity, BrainCircuit, Check, ExternalLink, KeyRound, Plus, TestTube, Trash2, TriangleAlert, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useConfirmar } from '@/composables/confirmar'

const props = defineProps({
    proveedores: { type: Array, default: () => [] },
    ayuda: { type: Object, default: () => ({}) },
})

const { confirmar } = useConfirmar()

// un formulario por proveedor (clave, URL, activo)
const forms = reactive(Object.fromEntries(props.proveedores.map((p) => [p.codigo, useForm({ api_key: '', quitar_clave: false, url: p.url ?? '', activo: p.activo })])))
const mostrarClave = reactive({})
// al pegar una clave nueva, lo normal es querer usar el proveedor: se marca activo solo
for (const p of props.proveedores) watch(() => forms[p.codigo].api_key, (v) => { if (v.trim()) forms[p.codigo].activo = true })

function guardar(p) {
    const f = forms[p.codigo]
    f.transform((d) => ({ ...d, url: d.url.trim() || null, api_key: d.api_key.trim() || null })).put(`/admin/ia/proveedores/${p.codigo}`, {
        preserveScroll: true,
        onSuccess: () => { f.api_key = ''; f.quitar_clave = false; mostrarClave[p.codigo] = false },
    })
}

async function quitarClave(p) {
    const ok = await confirmar({
        titulo: `Quitar la clave de ${p.nombre}`,
        mensaje: 'El proveedor quedará desactivado y las cadenas que lo usan lo saltarán hasta que pongas una clave nueva.',
        textoConfirmar: 'Quitar clave',
        peligro: true,
    })
    if (!ok) return
    const f = forms[p.codigo]
    f.api_key = ''
    f.quitar_clave = true
    f.activo = false
    guardar(p)
}

// probar conexión: manda una consulta mínima con el modelo indicado
const modeloPrueba = reactive(Object.fromEntries(props.proveedores.map((p) => [p.codigo, p.modelos[0]?.modelo ?? ''])))
const probando = ref(null)
function probar(p) {
    if (!modeloPrueba[p.codigo]) return
    probando.value = p.codigo
    router.post(`/admin/ia/proveedores/${p.codigo}/probar`, { modelo: modeloPrueba[p.codigo] }, { preserveScroll: true, onFinish: () => (probando.value = null) })
}

// precios por modelo
const modalModelo = ref(false)
const proveedorModelo = ref(null)
const formModelo = useForm({ modelo: '', precio_entrada: '', precio_salida: '', nota: '' })
function abrirModelo(p, m = null) {
    proveedorModelo.value = p
    formModelo.clearErrors()
    formModelo.modelo = m?.modelo ?? ''
    formModelo.precio_entrada = m?.precio_entrada ?? ''
    formModelo.precio_salida = m?.precio_salida ?? ''
    formModelo.nota = m?.nota ?? ''
    modalModelo.value = true
}
function guardarModelo() {
    formModelo.post(`/admin/ia/proveedores/${proveedorModelo.value.codigo}/modelos`, { preserveScroll: true, onSuccess: () => (modalModelo.value = false) })
}
async function eliminarModelo(m) {
    const ok = await confirmar({ titulo: `Quitar ${m.modelo}`, mensaje: 'Las consultas con este modelo dejarán de valorizarse en dólares.', textoConfirmar: 'Quitar', peligro: true })
    if (ok) router.delete(`/admin/ia/modelos/${m.id}`, { preserveScroll: true })
}

const totalMes = computed(() => props.proveedores.reduce((s, p) => s + p.costo_mes, 0))
const consultasMes = computed(() => props.proveedores.reduce((s, p) => s + p.consultas_mes, 0))

function fechaHora(iso) {
    if (!iso) return '—'
    const f = new Date(String(iso).replace(' ', 'T'))
    return f.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) + ' ' + f.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}
const usd = (n) => '$ ' + Number(n ?? 0).toFixed(n >= 1 ? 2 : 4)

function estado(p) {
    if (!p.tiene_clave) return { texto: 'Sin clave', clase: 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300' }
    if (!p.activo) return { texto: 'Desactivado', clase: 'bg-stone-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300' }
    if (p.en_pausa) return { texto: 'En pausa por fallos', clase: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300' }
    if (p.fallos_seguidos > 0) return { texto: `${p.fallos_seguidos} fallo(s) seguido(s)`, clase: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' }
    return { texto: 'Operativo', clase: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' }
}

const claseInput =
    'h-10 w-full rounded-xl border border-stone-300 bg-white px-3 text-sm placeholder-neutral-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-400/30 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:placeholder-neutral-500'
const claseLabel = 'mb-1 block text-sm font-medium'
const claseError = 'mt-1 text-xs text-red-600 dark:text-red-400'
const claseTarjeta = 'rounded-2xl border border-stone-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900'
</script>

<template>
    <AppLayout titulo="Proveedores de IA">
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div :class="claseTarjeta">
                <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">Consultas este mes</p>
                <p class="mt-1 text-2xl font-bold tracking-tight">{{ consultasMes.toLocaleString('es-PE') }}</p>
            </div>
            <div :class="claseTarjeta">
                <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">Costo este mes</p>
                <p class="mt-1 text-2xl font-bold tracking-tight">{{ usd(totalMes) }}</p>
                <p class="text-xs text-neutral-400">según los precios por modelo que registres</p>
            </div>
            <div :class="claseTarjeta">
                <p class="text-xs font-semibold tracking-wider text-neutral-400 uppercase">Proveedores operativos</p>
                <p class="mt-1 text-2xl font-bold tracking-tight">{{ proveedores.filter((p) => p.tiene_clave && p.activo && !p.en_pausa).length }} <span class="text-base font-normal text-neutral-400">de {{ proveedores.length }}</span></p>
            </div>
        </div>

        <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">
            Las claves se guardan cifradas y nunca se vuelven a mostrar. Si un proveedor falla {{ 3 }} veces seguidas, se pausa 5 minutos y las cadenas de los agentes pasan al siguiente.
        </p>

        <div class="grid items-start gap-4 xl:grid-cols-2">
            <section v-for="p in proveedores" :key="p.codigo" :class="claseTarjeta" :data-proveedor="p.codigo">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <BrainCircuit class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold tracking-tight">{{ p.nombre }}</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                            <template v-if="p.tiene_clave">Clave configurada · termina en …{{ p.clave_final }}</template>
                            <template v-else>Sin clave</template>
                        </p>
                    </div>
                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="estado(p).clase">{{ estado(p).texto }}</span>
                </div>

                <!-- salud -->
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-4">
                    <div><dt class="text-neutral-400">Última respuesta</dt><dd class="font-medium">{{ fechaHora(p.ultimo_ok_en) }}</dd></div>
                    <div><dt class="text-neutral-400">Último error</dt><dd class="font-medium">{{ fechaHora(p.ultimo_error_en) }}</dd></div>
                    <div><dt class="text-neutral-400">Consultas del mes</dt><dd class="font-medium">{{ p.consultas_mes.toLocaleString('es-PE') }}</dd></div>
                    <div><dt class="text-neutral-400">Costo del mes</dt><dd class="font-medium">{{ usd(p.costo_mes) }}</dd></div>
                </dl>
                <p v-if="p.ultimo_error" class="mt-2 flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                    <span class="min-w-0 break-words">{{ p.ultimo_error }}</span>
                </p>

                <!-- credenciales -->
                <form class="mt-4 grid gap-3" @submit.prevent="guardar(p)">
                    <div>
                        <label :class="claseLabel" :for="`clave-${p.codigo}`">{{ p.tiene_clave ? 'Nueva clave API (deja vacío para conservar la actual)' : 'Clave API' }}</label>
                        <div class="flex gap-2">
                            <div class="relative min-w-0 flex-1">
                                <KeyRound class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                                <input
                                    :id="`clave-${p.codigo}`"
                                    v-model="forms[p.codigo].api_key"
                                    :type="mostrarClave[p.codigo] ? 'text' : 'password'"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="p.tiene_clave ? '••••••••' : 'Pega aquí la clave'"
                                    :class="[claseInput, 'pl-9']"
                                />
                            </div>
                            <button type="button" class="h-10 shrink-0 rounded-xl border border-stone-300 px-3 text-xs font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="mostrarClave[p.codigo] = !mostrarClave[p.codigo]">
                                {{ mostrarClave[p.codigo] ? 'Ocultar' : 'Ver' }}
                            </button>
                        </div>
                        <p v-if="forms[p.codigo].errors.api_key" :class="claseError">{{ forms[p.codigo].errors.api_key }}</p>
                        <p v-else-if="ayuda[p.codigo]" class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                            Se obtiene en
                            <a :href="ayuda[p.codigo].consola" target="_blank" rel="noopener" class="inline-flex items-center gap-0.5 text-emerald-700 underline underline-offset-2 dark:text-emerald-400">su consola <ExternalLink class="size-3" /></a>.
                            Modelos: {{ ayuda[p.codigo].modelos }}.
                        </p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                        <div>
                            <label :class="claseLabel" :for="`url-${p.codigo}`">URL del servicio <span class="font-normal text-neutral-400">(opcional, solo si el proveedor la cambia)</span></label>
                            <input :id="`url-${p.codigo}`" v-model="forms[p.codigo].url" type="url" placeholder="https://…" :class="claseInput" />
                            <p v-if="forms[p.codigo].errors.url" :class="claseError">{{ forms[p.codigo].errors.url }}</p>
                        </div>
                        <label class="inline-flex h-10 cursor-pointer items-center gap-2 text-sm font-medium">
                            <input v-model="forms[p.codigo].activo" type="checkbox" class="size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" />
                            Activo
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" :disabled="forms[p.codigo].processing" class="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                            <Check class="size-4" />
                            {{ forms[p.codigo].processing ? 'Guardando...' : 'Guardar' }}
                        </button>
                        <button v-if="p.tiene_clave" type="button" class="inline-flex h-10 items-center gap-1.5 rounded-xl px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40" @click="quitarClave(p)">
                            <Trash2 class="size-4" />
                            Quitar clave
                        </button>
                    </div>
                </form>

                <!-- probar -->
                <div class="mt-4 rounded-xl bg-stone-50 p-3 dark:bg-neutral-950">
                    <p class="mb-2 flex items-center gap-1.5 text-sm font-medium"><Activity class="size-4 text-emerald-600" /> Probar conexión</p>
                    <div class="flex gap-2">
                        <input v-model="modeloPrueba[p.codigo]" type="text" placeholder="Nombre del modelo" :class="claseInput" :list="`modelos-${p.codigo}`" />
                        <datalist :id="`modelos-${p.codigo}`">
                            <option v-for="m in p.modelos" :key="m.id" :value="m.modelo" />
                        </datalist>
                        <button
                            type="button"
                            :disabled="probando === p.codigo || !p.tiene_clave || !modeloPrueba[p.codigo]"
                            class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-white disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            @click="probar(p)"
                        >
                            <TestTube class="size-4" />
                            {{ probando === p.codigo ? 'Probando...' : 'Probar' }}
                        </button>
                    </div>
                    <p class="mt-1.5 text-xs text-neutral-400 dark:text-neutral-500">Manda una consulta mínima con ese modelo y muestra cuánto tardó. El resultado sale arriba como aviso.</p>
                </div>

                <!-- precios por modelo -->
                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-sm font-medium">Precios por modelo <span class="font-normal text-neutral-400">(US$ por millón de tokens)</span></p>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40" @click="abrirModelo(p)">
                            <Plus class="size-3.5" />
                            Agregar modelo
                        </button>
                    </div>
                    <p v-if="!p.modelos.length" class="text-xs text-neutral-400 dark:text-neutral-500">Sin precios: las consultas con este proveedor no se valorizarán hasta que agregues el modelo.</p>
                    <table v-else class="w-full text-left text-sm">
                        <thead class="text-xs text-neutral-400 uppercase">
                            <tr><th class="py-1 font-semibold">Modelo</th><th class="py-1 text-right font-semibold">Entrada</th><th class="py-1 text-right font-semibold">Salida</th><th class="py-1"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-for="m in p.modelos" :key="m.id">
                                <td class="py-1.5 pr-2">
                                    <button type="button" class="font-medium hover:text-emerald-700 dark:hover:text-emerald-400" @click="abrirModelo(p, m)">{{ m.modelo }}</button>
                                    <span v-if="m.nota" class="block text-xs text-neutral-400">{{ m.nota }}</span>
                                </td>
                                <td class="py-1.5 text-right tabular-nums">{{ usd(m.precio_entrada) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ usd(m.precio_salida) }}</td>
                                <td class="py-1.5 pl-2 text-right">
                                    <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" title="Quitar" @click="eliminarModelo(m)"><Trash2 class="size-4" /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- modal de precio -->
        <Teleport to="body">
            <div v-if="modalModelo" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-neutral-950/60" @click="modalModelo = false" />
                <form class="relative w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 text-neutral-900 shadow-xl dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100" @submit.prevent="guardarModelo">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold tracking-tight">Precio de un modelo · {{ proveedorModelo?.nombre }}</h3>
                        <button type="button" class="rounded-lg p-1.5 text-neutral-400 hover:bg-stone-100 dark:hover:bg-neutral-800" @click="modalModelo = false"><X class="size-5" /></button>
                    </div>
                    <div class="grid gap-3">
                        <div>
                            <label :class="claseLabel" for="pm_modelo">Nombre del modelo</label>
                            <input id="pm_modelo" v-model="formModelo.modelo" type="text" :class="claseInput" placeholder="deepseek-v4-pro" />
                            <p v-if="formModelo.errors.modelo" :class="claseError">{{ formModelo.errors.modelo }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="claseLabel" for="pm_entrada">Entrada (US$ / millón)</label>
                                <input id="pm_entrada" v-model="formModelo.precio_entrada" type="number" step="0.0001" min="0" :class="claseInput" />
                                <p v-if="formModelo.errors.precio_entrada" :class="claseError">{{ formModelo.errors.precio_entrada }}</p>
                            </div>
                            <div>
                                <label :class="claseLabel" for="pm_salida">Salida (US$ / millón)</label>
                                <input id="pm_salida" v-model="formModelo.precio_salida" type="number" step="0.0001" min="0" :class="claseInput" />
                                <p v-if="formModelo.errors.precio_salida" :class="claseError">{{ formModelo.errors.precio_salida }}</p>
                            </div>
                        </div>
                        <div>
                            <label :class="claseLabel" for="pm_nota">Nota <span class="font-normal text-neutral-400">(opcional)</span></label>
                            <input id="pm_nota" v-model="formModelo.nota" type="text" maxlength="160" :class="claseInput" placeholder="Tarifa fuera de hora pico, vigente desde…" />
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="h-10 rounded-xl border border-stone-300 px-4 text-sm font-medium hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800" @click="modalModelo = false">Cancelar</button>
                        <button type="submit" :disabled="formModelo.processing" class="h-10 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">Guardar</button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
