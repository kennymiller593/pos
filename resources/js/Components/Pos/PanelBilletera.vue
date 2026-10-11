<script setup>
// Lo que el cajero le muestra al cliente para que pague con una billetera (Yape, Plin):
// el QR del negocio, el monto y el número de celular. Se usa en el cobro del POS y en la
// vista previa de la configuración.
import { computed } from 'vue'
import { QrCode } from '@lucide/vue'

const props = defineProps({
    codigo: { type: String, required: true },
    nombre: { type: String, required: true },
    // { qr_url, numero, titular } o null si el negocio aún no configuró esta billetera
    datos: { type: Object, default: null },
    monto: { type: Number, default: null },
})

// colores de cada marca (clases completas para que Tailwind las genere)
const MARCAS = {
    yape: { borde: 'border-[#742284]', texto: 'text-[#742284] dark:text-[#d7a3e6]', fondo: 'bg-[#742284]/8 dark:bg-[#742284]/25' },
    plin: { borde: 'border-[#0891b2]', texto: 'text-[#0e7490] dark:text-[#67e8f9]', fondo: 'bg-[#0891b2]/8 dark:bg-[#0891b2]/20' },
}
const marca = computed(() => MARCAS[props.codigo] ?? MARCAS.yape)

const numeroBonito = computed(() => (props.datos?.numero ?? '').replace(/(\d{3})(?=\d)/g, '$1 '))
const soles = (n) => 'S/ ' + Number(n).toFixed(2)
</script>

<template>
    <div class="rounded-2xl p-4" :class="marca.fondo" data-panel-billetera>
        <!-- con QR: en pantallas anchas el QR va a la izquierda y los datos a la derecha -->
        <div v-if="datos?.qr_url" class="flex flex-col items-center gap-3 text-center sm:flex-row sm:gap-4 sm:text-left">
            <div class="shrink-0 rounded-2xl border-2 bg-white p-2 shadow-sm" :class="marca.borde">
                <img :src="datos.qr_url" :alt="`QR de ${nombre}`" class="block size-40 rounded-lg object-contain sm:size-36" />
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold" :class="marca.texto">Escanea para pagar con {{ nombre }}</p>
                <template v-if="monto !== null">
                    <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">Monto a pagar</p>
                    <p class="text-2xl leading-tight font-bold text-neutral-900 tabular-nums dark:text-white">{{ soles(monto) }}</p>
                </template>
                <p v-if="datos.numero" class="mt-2 text-base font-semibold tracking-wide text-neutral-800 tabular-nums dark:text-neutral-100">{{ numeroBonito }}</p>
                <p v-if="datos.titular" class="truncate text-xs text-neutral-500 dark:text-neutral-400">{{ datos.titular }}</p>
            </div>
        </div>

        <!-- solo el número -->
        <div v-else-if="datos?.numero" class="text-center">
            <p class="text-sm font-semibold" :class="marca.texto">Paga con {{ nombre }} a este número</p>
            <p class="mt-1 text-2xl font-bold tracking-wide tabular-nums" :class="marca.texto">{{ numeroBonito }}</p>
            <p v-if="datos.titular" class="text-xs text-neutral-500 dark:text-neutral-400">{{ datos.titular }}</p>
            <p v-if="monto !== null" class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                Monto a pagar <span class="text-lg font-bold text-neutral-900 tabular-nums dark:text-white">{{ soles(monto) }}</span>
            </p>
        </div>

        <!-- sin configurar -->
        <div v-else class="text-center">
            <div class="mx-auto grid size-12 place-items-center rounded-xl bg-white/70 dark:bg-neutral-900/60" :class="marca.texto">
                <QrCode class="size-6" />
            </div>
            <p class="mt-2 text-sm font-semibold" :class="marca.texto">Cobro con {{ nombre }}</p>
            <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400"><slot name="vacio">Aún no hay un QR configurado.</slot></p>
        </div>
    </div>
</template>
