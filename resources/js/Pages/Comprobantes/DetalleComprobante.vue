<script setup>
// Detalle desplegable de un comprobante: productos, pagos, SUNAT, notas de crédito y archivos.
import { CloudUpload, FileCode2, FileText, Mail, Printer, RefreshCw, ShieldCheck } from '@lucide/vue'
import { usePermisos } from '@/composables/permisos'
import { useImpresion } from '@/composables/impresion'
import { AYUDA_REEMITIR, SUNAT_BADGES, esElectronico, nombreMotivo, numero, puedeReemitir, soles } from './comun'

defineProps({
    c: { type: Object, required: true },
    enviandoSunat: { type: String, default: null },
})

const emit = defineEmits(['enviar-sunat', 'reemitir', 'correo'])

const { puede } = usePermisos()
const { imprimirTicket } = useImpresion()

const claseTitulo = 'mb-2 text-[11px] font-semibold tracking-wider text-[#94A3B8] uppercase'
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div>
            <p :class="claseTitulo">Productos</p>
            <div class="divide-y divide-[#E2E8F0] rounded-xl border border-[#E2E8F0] bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                <div v-for="d in c.detalles" :key="d.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <span class="min-w-0">
                        <span class="font-semibold tabular-nums">{{ Number(d.cantidad) }}</span>
                        <span class="text-[#94A3B8]"> × </span>
                        <span>{{ d.descripcion }}</span>
                        <span class="ml-1 text-xs text-[#94A3B8]">({{ soles(d.precio_unitario) }} c/u)</span>
                    </span>
                    <span class="shrink-0 font-semibold tabular-nums">{{ soles(d.total) }}</span>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <div>
                <p :class="claseTitulo">Pagos</p>
                <div v-if="c.pagos.length" class="space-y-1.5">
                    <div v-for="p in c.pagos" :key="p.id" class="flex items-center justify-between gap-3 text-sm">
                        <span>
                            {{ p.medio_pago?.nombre ?? p.medio_pago_codigo }}
                            <span v-if="p.referencia" class="text-xs text-[#94A3B8]">({{ p.referencia }})</span>
                        </span>
                        <span class="font-semibold tabular-nums">{{ soles(p.monto) }}</span>
                    </div>
                </div>
                <p v-else class="text-sm text-[#64748B] dark:text-neutral-400">{{ c.es_credito ? 'Venta al crédito' : 'Sin pagos (anulado)' }}</p>
            </div>

            <p v-if="c.sunat_respuesta?.convertido_de" class="text-xs text-[#64748B] dark:text-neutral-400">
                Emitido a partir de la nota de venta <span class="font-semibold">{{ c.sunat_respuesta.convertido_de }}</span>
            </p>

            <div v-if="c.estado === 'anulado'" class="rounded-xl bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-500/15 dark:text-red-300">
                Anulado: {{ c.motivo_anulacion }}
            </div>

            <div v-if="esElectronico(c) && c.sunat?.mensaje_sunat" class="flex items-start gap-2 rounded-xl bg-white px-3 py-2 text-xs text-[#475569] ring-1 ring-[#E2E8F0] dark:bg-neutral-900 dark:text-neutral-300 dark:ring-neutral-800">
                <ShieldCheck class="mt-0.5 size-4 shrink-0 text-[#94A3B8]" />
                <span class="break-words">
                    {{ c.sunat.mensaje_sunat }}
                    <span v-if="c.sunat.intentos > 1" class="text-[#94A3B8]">({{ c.sunat.intentos }} intentos)</span>
                </span>
            </div>

            <div v-if="c.notas?.length">
                <p :class="claseTitulo">Notas de crédito</p>
                <div
                    v-for="n in c.notas"
                    :key="n.id"
                    class="mb-1.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs dark:border-amber-500/20 dark:bg-amber-500/10"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">{{ numero(n) }}</span>
                        <span class="font-semibold text-amber-700 dark:text-amber-400">-{{ soles(n.total) }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                        <span class="text-[#64748B] dark:text-neutral-400">{{ nombreMotivo(n.motivo_nota) }}</span>
                        <span class="inline-flex rounded-full px-2 py-0.5 font-semibold" :class="SUNAT_BADGES[n.sunat?.estado ?? 'pendiente']?.[1]" :title="n.sunat?.mensaje_sunat ?? ''">
                            {{ SUNAT_BADGES[n.sunat?.estado ?? 'pendiente']?.[0] }}
                        </span>
                    </div>
                    <div class="mt-1.5 flex items-center gap-1">
                        <button
                            v-if="puede('comprobantes.sunat') && puedeReemitir(n)"
                            :disabled="enviandoSunat === n.id"
                            class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-60 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                            :title="AYUDA_REEMITIR"
                            @click.stop="emit('reemitir', n)"
                        >
                            <RefreshCw class="size-3.5" :class="enviandoSunat === n.id ? 'animate-spin' : ''" />
                            {{ enviandoSunat === n.id ? 'Reenviando...' : 'Corregir y reenviar' }}
                        </button>
                        <button
                            v-else-if="puede('comprobantes.sunat') && ['pendiente', undefined].includes(n.sunat?.estado)"
                            :disabled="enviandoSunat === n.id"
                            class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-60 dark:text-emerald-400 dark:hover:bg-emerald-950/40"
                            title="Reenviar la nota de crédito a SUNAT"
                            @click.stop="emit('enviar-sunat', n)"
                        >
                            <CloudUpload class="size-3.5" />
                            {{ enviandoSunat === n.id ? 'Enviando...' : 'Enviar a SUNAT' }}
                        </button>
                        <span class="ml-auto flex items-center gap-0.5">
                            <button type="button" title="Imprimir ticket" class="rounded-lg p-1.5 text-[#64748B] hover:bg-amber-100 dark:text-neutral-400 dark:hover:bg-amber-500/20" @click.stop="imprimirTicket(`/comprobantes/${n.id}/ticket`)">
                                <Printer class="size-3.5" />
                            </button>
                            <a :href="`/comprobantes/${n.id}/a4`" target="_blank" rel="noopener" title="PDF A4" class="rounded-lg p-1.5 text-[#64748B] hover:bg-amber-100 dark:text-neutral-400 dark:hover:bg-amber-500/20" @click.stop>
                                <FileText class="size-3.5" />
                            </a>
                            <button type="button" title="Enviar por correo" class="rounded-lg p-1.5 text-[#64748B] hover:bg-amber-100 dark:text-neutral-400 dark:hover:bg-amber-500/20" @click.stop="emit('correo', n)">
                                <Mail class="size-3.5" />
                            </button>
                        </span>
                    </div>
                </div>
            </div>

            <div v-if="c.sunat?.xml_url || c.sunat?.cdr_url" class="flex gap-2">
                <a
                    v-if="c.sunat?.xml_url"
                    :href="`/comprobantes/${c.id}/xml`"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-[#E2E8F0] bg-white px-2.5 py-1.5 text-xs font-medium text-[#475569] hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click.stop
                >
                    <FileCode2 class="size-3.5" />
                    Descargar XML
                </a>
                <a
                    v-if="c.sunat?.cdr_url"
                    :href="`/comprobantes/${c.id}/cdr`"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-[#E2E8F0] bg-white px-2.5 py-1.5 text-xs font-medium text-[#475569] hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click.stop
                >
                    <ShieldCheck class="size-3.5" />
                    Descargar CDR
                </a>
            </div>
        </div>
    </div>
</template>
