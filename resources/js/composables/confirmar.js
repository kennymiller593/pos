import { reactive } from 'vue'

// estado compartido: un solo dialogo global renderizado en AppLayout
const estado = reactive({
    abierto: false,
    titulo: '',
    mensaje: '',
    textoConfirmar: 'Confirmar',
    textoCancelar: 'Cancelar',
    peligro: false,
    resolver: null,
})

export function useConfirmar() {
    /**
     * Muestra el dialogo y devuelve una promesa que resuelve true/false.
     * await confirmar({ titulo, mensaje, textoConfirmar, peligro })
     */
    function confirmar({ titulo, mensaje = '', textoConfirmar = 'Confirmar', textoCancelar = 'Cancelar', peligro = false }) {
        estado.titulo = titulo
        estado.mensaje = mensaje
        estado.textoConfirmar = textoConfirmar
        estado.textoCancelar = textoCancelar
        estado.peligro = peligro
        estado.abierto = true

        return new Promise((resolve) => {
            estado.resolver = resolve
        })
    }

    function responder(valor) {
        estado.abierto = false
        estado.resolver?.(valor)
        estado.resolver = null
    }

    return { estado, confirmar, responder }
}
