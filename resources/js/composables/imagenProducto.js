// Optimiza la foto de un producto en el navegador antes de subirla (el servidor repite lo mismo
// como respaldo). Mismo criterio que ImagenProductoService: lienzo 4:3 —la proporción de las
// tarjetas del POS— con la foto completa centrada sobre fondo blanco, máx. 800x600, en WebP.
// Así una foto muy alta o muy ancha se ve entera y una foto de celular de 8 MB queda en ~100 KB.

const ANCHO_MAX = 800

async function cargar(archivo) {
    // createImageBitmap respeta la rotación EXIF de las fotos de celular
    if ('createImageBitmap' in window) {
        try {
            return await createImageBitmap(archivo, { imageOrientation: 'from-image' })
        } catch {
            // navegador antiguo o formato no soportado: se intenta con <img>
        }
    }
    const url = URL.createObjectURL(archivo)
    try {
        const img = new Image()
        img.src = url
        await img.decode()
        return img
    } finally {
        URL.revokeObjectURL(url)
    }
}

const aBlob = (canvas, tipo, calidad) => new Promise((resolver) => canvas.toBlob(resolver, tipo, calidad))

/**
 * @param {File} archivo
 * @returns {Promise<File>} la imagen optimizada, o el archivo original si no se pudo procesar
 */
export async function optimizarImagenProducto(archivo) {
    try {
        const imagen = await cargar(archivo)
        const w = imagen.width
        const h = imagen.height
        if (!w || !h) return archivo

        // la caja 4:3 más chica que contiene la foto; se reduce a 800x600 si es más grande (nunca se agranda)
        const cajaAncho = Math.max(w, (h * 4) / 3)
        const factor = Math.min(1, ANCHO_MAX / cajaAncho)
        const lienzoAncho = Math.max(4, Math.round(cajaAncho * factor))
        const lienzoAlto = Math.max(3, Math.round((lienzoAncho * 3) / 4))
        const nuevoAncho = Math.round(w * factor)
        const nuevoAlto = Math.round(h * factor)

        const canvas = document.createElement('canvas')
        canvas.width = lienzoAncho
        canvas.height = lienzoAlto
        const ctx = canvas.getContext('2d')
        ctx.fillStyle = '#ffffff' // PNG transparente -> fondo blanco
        ctx.fillRect(0, 0, lienzoAncho, lienzoAlto)
        ctx.imageSmoothingQuality = 'high'
        ctx.drawImage(imagen, Math.floor((lienzoAncho - nuevoAncho) / 2), Math.floor((lienzoAlto - nuevoAlto) / 2), nuevoAncho, nuevoAlto)
        imagen.close?.()

        // Safari antiguo no genera WebP (devuelve PNG): en ese caso, JPEG
        let blob = await aBlob(canvas, 'image/webp', 0.82)
        if (!blob || blob.type !== 'image/webp') blob = await aBlob(canvas, 'image/jpeg', 0.85)
        if (!blob) return archivo

        const extension = blob.type === 'image/webp' ? 'webp' : 'jpg'
        const nombre = (archivo.name || 'producto').replace(/\.[^.]+$/, '') + '.' + extension
        return new File([blob], nombre, { type: blob.type })
    } catch {
        return archivo
    }
}
