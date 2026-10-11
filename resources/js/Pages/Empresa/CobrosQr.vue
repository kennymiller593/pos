<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowLeft, Building2, QrCode, ScanLine, Smartphone, Store } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TarjetaCobroQr from '@/Components/Empresa/TarjetaCobroQr.vue'

const props = defineProps({
    billeteras: { type: Array, required: true },
    negocio: { type: String, default: '' },
    // lo guardado para toda la empresa: { yape: {...}|null, plin: {...}|null }
    empresa: { type: Object, required: true },
    // [{ id, nombre, propios: { yape, plin } }]
    sucursales: { type: Array, default: () => [] },
})

// 'empresa' o el id de una sucursal; con un solo local no hace falta elegir
const ambito = ref('empresa')
const variasSucursales = computed(() => props.sucursales.length > 1)
const sucursal = computed(() => props.sucursales.find((s) => s.id === ambito.value) ?? null)
const propios = computed(() => (sucursal.value ? sucursal.value.propios : props.empresa))

const PASOS = [
    { icono: Smartphone, titulo: 'Descarga tu QR', texto: 'En tu app de Yape o Plin entra a "Mi QR" y guarda o captura la imagen.' },
    { icono: QrCode, titulo: 'Súbelo aquí', texto: 'Una imagen por billetera. Puedes añadir tu celular y el nombre del titular.' },
    { icono: ScanLine, titulo: 'Cobra en el punto de venta', texto: 'Al elegir Yape o Plin aparece tu QR con el monto para que el cliente pague.' },
]
</script>

<template>
    <AppLayout titulo="Cobros con QR">
        <div class="mx-auto max-w-5xl space-y-4">
            <Link href="/empresa" class="inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 hover:text-emerald-600 dark:text-neutral-400 dark:hover:text-emerald-400">
                <ArrowLeft class="size-4" />
                Volver a Empresa
            </Link>

            <!-- presentación -->
            <div class="rounded-2xl border border-stone-200 bg-white p-5 sm:p-6 dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-start gap-3">
                    <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <QrCode class="size-5" />
                    </div>
                    <div>
                        <h1 class="font-semibold tracking-tight">Cobra con Yape y Plin desde tu punto de venta</h1>
                        <p class="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                            Sube el QR de tu negocio una sola vez. Al cobrar, tu cajero lo muestra en la pantalla y el cliente lo escanea desde su celular.
                        </p>
                    </div>
                </div>
                <ol class="mt-5 grid gap-3 sm:grid-cols-3">
                    <li v-for="(paso, i) in PASOS" :key="paso.titulo" class="flex gap-3 rounded-xl bg-stone-50 p-3 dark:bg-neutral-950">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-white text-emerald-600 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-emerald-400 dark:ring-neutral-800">
                            <component :is="paso.icono" class="size-4" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold">{{ i + 1 }}. {{ paso.titulo }}</p>
                            <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ paso.texto }}</p>
                        </div>
                    </li>
                </ol>
            </div>

            <!-- ámbito: toda la empresa o un local -->
            <div v-if="variasSucursales" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <p class="mb-2 text-sm font-medium">¿Para qué local?</p>
                <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    <button
                        type="button"
                        class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border px-3.5 text-sm font-medium transition-colors"
                        :class="ambito === 'empresa'
                            ? 'border-emerald-600 bg-emerald-600 text-white'
                            : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        @click="ambito = 'empresa'"
                    >
                        <Building2 class="size-4" />
                        Toda la empresa
                    </button>
                    <button
                        v-for="s in sucursales"
                        :key="s.id"
                        type="button"
                        class="inline-flex h-10 shrink-0 items-center gap-2 rounded-xl border px-3.5 text-sm font-medium transition-colors"
                        :class="ambito === s.id
                            ? 'border-emerald-600 bg-emerald-600 text-white'
                            : 'border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800'"
                        @click="ambito = s.id"
                    >
                        <Store class="size-4" />
                        {{ s.nombre }}
                        <span
                            v-if="s.propios.yape || s.propios.plin"
                            class="size-2 rounded-full"
                            :class="ambito === s.id ? 'bg-white' : 'bg-emerald-500'"
                            title="Tiene QR propio"
                        />
                    </button>
                </div>
                <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ sucursal
                        ? `Solo para ${sucursal.nombre}. Si lo dejas vacío, este local cobra con el QR de toda la empresa.`
                        : 'Vale para todos tus locales, salvo los que tengan su propio QR.' }}
                </p>
            </div>

            <TarjetaCobroQr
                v-for="b in billeteras"
                :key="`${ambito}-${b.codigo}`"
                :ambito="ambito"
                :billetera="b"
                :propio="propios[b.codigo]"
                :heredado="sucursal ? empresa[b.codigo] : null"
            />

            <p class="px-1 text-xs text-neutral-400 dark:text-neutral-500">
                El QR es el de tu cuenta: el cliente escribe el monto en su app y tú confirmas el pago en tu celular antes de registrar la venta.
            </p>
        </div>
    </AppLayout>
</template>
