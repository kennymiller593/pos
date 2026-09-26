// El botón de escanear con la cámara solo se muestra en pantallas táctiles con cámara
// (celulares y tablets); en la PC se usa la pistola lectora o el teclado.
export const puedeEscanear =
    typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches && !!navigator.mediaDevices?.getUserMedia
