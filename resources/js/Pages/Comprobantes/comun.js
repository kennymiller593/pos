// Datos y reglas compartidos por la lista de comprobantes y su detalle.

export const soles = (n) => `S/ ${Number(n ?? 0).toFixed(2)}`
export const numero = (c) => `${c.serie}-${String(c.correlativo).padStart(6, '0')}`

export const TIPOS = { '00': 'Nota de venta', '01': 'Factura', '03': 'Boleta', '07': 'Nota de crédito', '08': 'Nota de débito' }

export const SUNAT_BADGES = {
    aceptado: ['Aceptado', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
    observado: ['Observado', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
    rechazado: ['Rechazado', 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-400'],
    pendiente: ['Pendiente', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
    baja_pendiente: ['Baja en proceso', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
    baja: ['Dada de baja', 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-300'],
}

export const MOTIVOS_NC = [
    { codigo: '01', nombre: 'Anulación de la operación (total)' },
    { codigo: '06', nombre: 'Devolución total' },
    { codigo: '07', nombre: 'Devolución por ítem (parcial)' },
]

export const nombreMotivo = (codigo) => MOTIVOS_NC.find((m) => m.codigo === codigo?.trim())?.nombre ?? 'Nota de crédito'

export const esElectronico = (c) => ['01', '03', '07'].includes(c.tipo_comprobante_codigo)
export const bajaPendiente = (c) => c.sunat?.estado === 'baja_pendiente'
export const badgeSunat = (c) => SUNAT_BADGES[c.sunat?.estado ?? 'pendiente'] ?? SUNAT_BADGES.pendiente
export const puedeReenviar = (c) => esElectronico(c) && c.estado === 'emitido' && ['pendiente', undefined].includes(c.sunat?.estado)
// un rechazado no se reenvia tal cual: se reemite con el mismo numero y los datos del cliente actualizados
export const puedeReemitir = (c) => esElectronico(c) && c.estado === 'emitido' && c.sunat?.estado === 'rechazado'
export const AYUDA_REEMITIR = 'Actualiza los datos del cliente desde Clientes y vuelve a enviar con el mismo número'
