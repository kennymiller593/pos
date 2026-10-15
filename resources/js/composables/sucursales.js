import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// La sucursal actual del usuario, la misma que muestra el selector de la barra superior.
// El backend la comparte en auth.user (sucursales_accesibles, sucursal_consulta_id, caja_abierta...).
export function useSucursales() {
    const page = usePage()
    const usuario = computed(() => page.props.auth?.user ?? null)

    // la empresa tiene más de una sucursal activa: las listas muestran la columna "Sucursal"
    const multisucursal = computed(() => !!usuario.value?.empresa?.multisucursal)
    // [{ id, nombre }] a las que puede entrar
    const accesibles = computed(() => usuario.value?.sucursales_accesibles ?? [])
    // la elegida en el selector; null = todas las accesibles
    const consultaId = computed(() => usuario.value?.sucursal_consulta_id ?? null)
    const etiqueta = computed(() => usuario.value?.sucursal_etiqueta ?? '')
    // { sucursal_id, sucursal_nombre, caja_nombre } si tiene una caja abierta
    const cajaAbierta = computed(() => usuario.value?.caja_abierta ?? null)
    // está viendo "todas": las pantallas que trabajan sobre una sola le piden elegir
    const viendoTodas = computed(() => !consultaId.value && accesibles.value.length > 1)

    const esAccesible = (id) => accesibles.value.some((s) => s.id === id)
    const nombreDe = (id) => accesibles.value.find((s) => s.id === id)?.nombre ?? null

    return { multisucursal, accesibles, consultaId, etiqueta, cajaAbierta, viendoTodas, esAccesible, nombreDe }
}
