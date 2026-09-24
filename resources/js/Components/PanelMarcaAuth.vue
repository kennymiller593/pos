<script setup>
import { CheckCircle2, TrendingUp } from '@lucide/vue'

defineProps({
    eyebrow: { type: String, default: '<punto_de_venta />' },
    titulo: { type: String, default: 'Gestiona tu negocio,' },
    destacado: { type: String, default: 'vende sin fricción.' },
    descripcion: { type: String, default: 'Ventas, inventario, caja y facturación electrónica en un solo lugar.' },
})
</script>

<template>
    <div
        class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-[#050807] p-10 text-white lg:flex xl:p-14">
        <!-- Atmósfera: los dos colores de la marca -->
        <div
            class="pointer-events-none absolute -top-40 -right-32 size-[34rem] rounded-full bg-emerald-500/20 blur-[110px]" />
        <div
            class="pointer-events-none absolute -bottom-48 -left-32 size-[30rem] rounded-full bg-[#4078F5]/15 blur-[110px]" />
        <!-- Grilla sutil de fondo -->
        <div class="pointer-events-none absolute inset-0 opacity-[0.04]"
            style="background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px); background-size: 44px 44px;" />

        <!-- Logo -->
        <div class="anim relative">
            <img :src="'/images/falcontech_oscuro.png'" alt="FalconTech" class="h-20 w-auto" />
        </div>

        <!-- Mensaje central + firma visual -->
        <div class="relative max-w-md">
            <p class="anim d1 font-receipt text-[13px] tracking-[0.18em] text-emerald-400/90 uppercase">
                {{ eyebrow }}
            </p>
            <h2 class="anim d2 font-display mt-4 text-[2.5rem] leading-[1.1] font-bold tracking-tight text-balance">
                {{ titulo }}<br />
                <span class="bg-gradient-to-r from-emerald-400 to-[#5B8DF7] bg-clip-text text-transparent">{{ destacado
                }}</span>
            </h2>
            <p class="anim d3 mt-5 text-lg leading-relaxed text-neutral-400">
                {{ descripcion }}
            </p>

            <!-- Firma visual: por defecto, la boleta electrónica -->
            <div class="anim d4 mt-10">
                <slot name="firma">
                    <div class="relative w-fit">
                        <div
                            class="ticket w-64 -rotate-2 bg-[#FAFAF8] px-5 pt-5 pb-8 text-neutral-800 shadow-2xl shadow-black/60">
                            <div class="font-receipt text-[11px] leading-relaxed">
                                <p class="text-center font-semibold tracking-wide">FALCONTECH POS</p>
                                <p class="text-center text-neutral-500">BOLETA ELECTRÓNICA</p>
                                <p class="text-center text-neutral-500">B001-00042381</p>
                                <div class="my-2.5 border-t border-dashed border-neutral-300" />
                                <div class="flex justify-between"><span>INCA KOLA 500ML</span><span>3.50</span></div>
                                <div class="flex justify-between"><span>PAN FRANCÉS x6</span><span>2.40</span></div>
                                <div class="flex justify-between"><span>LECHE GLORIA x2</span><span>9.80</span></div>
                                <div class="my-2.5 border-t border-dashed border-neutral-300" />
                                <div class="flex justify-between text-neutral-500"><span>OP.
                                        GRAVADA</span><span>13.31</span></div>
                                <div class="flex justify-between text-neutral-500"><span>IGV 18%</span><span>2.39</span>
                                </div>
                                <div class="mt-1.5 flex justify-between text-[13px] font-bold"><span>TOTAL
                                        S/</span><span>15.70</span></div>
                                <div class="mt-3 flex items-center justify-center gap-1.5 text-emerald-600">
                                    <CheckCircle2 class="size-3.5" />
                                    <span class="font-medium">ACEPTADA POR SUNAT</span>
                                </div>
                            </div>
                        </div>

                    </div>
                    <!-- Chip flotante de ventas -->
                    <div
                        class="absolute -right-8 -bottom-5 flex rotate-2 items-center gap-2.5 rounded-2xl border border-white/10 bg-white/[0.07] px-4 py-3 shadow-xl shadow-black/40 backdrop-blur-md">
                        <div class="grid size-8 place-items-center rounded-lg bg-emerald-500/20 text-emerald-400">
                            <TrendingUp class="size-4" />
                        </div>
                        <div class="leading-tight">
                            <p class="font-receipt text-sm font-bold tracking-tight">S/ 1,240.50</p>
                            <p class="text-[11px] font-medium text-emerald-400">+18% vs. ayer</p>
                        </div>
                    </div>
                </slot>
            </div>
        </div>

        <!-- Módulos del producto -->
        <p class="anim d5 font-receipt relative text-[12px] tracking-wider text-neutral-500">
            VENTAS <span class="text-emerald-500">/</span> INVENTARIO <span class="text-emerald-500">/</span> CAJA <span
                class="text-emerald-500">/</span> FACTURACIÓN SUNAT
            <span class="float-right normal-case tracking-normal">Perú</span>
        </p>
    </div>
</template>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap');

.font-display {
    font-family: 'Sora', ui-sans-serif, system-ui, sans-serif;
}

.font-receipt {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
}

/* Borde perforado de la boleta */
.ticket {
    border-radius: 6px 6px 0 0;
    -webkit-mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / 14px 100%;
    mask: conic-gradient(from -45deg at bottom, #0000, #000 1deg 89deg, #0000 90deg) 50% / 14px 100%;
}

/* Entrada escalonada */
.anim {
    animation: fadeUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.d1 {
    animation-delay: 0.08s;
}

.d2 {
    animation-delay: 0.16s;
}

.d3 {
    animation-delay: 0.24s;
}

.d4 {
    animation-delay: 0.34s;
}

.d5 {
    animation-delay: 0.44s;
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(14px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .anim {
        animation: none;
    }
}
</style>