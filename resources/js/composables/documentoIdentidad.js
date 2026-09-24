// Catálogo 06 de SUNAT: 0 sin documento, 1 DNI, 4 carnet de extranjería, 6 RUC, 7 pasaporte.
// Solo es ayuda visual: el servidor valida el formato y devuelve errors.numero_documento.
const AYUDAS = {
    1: '8 dígitos',
    4: '6 a 12 caracteres',
    6: '11 dígitos',
    7: '6 a 15 caracteres',
}

const codigo = (tipo) => String(tipo ?? '').trim()

export const esSinDocumento = (tipo) => codigo(tipo) === '0'

export const ayudaDocumento = (tipo) => AYUDAS[codigo(tipo)] ?? ''
