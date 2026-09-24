import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// permisos del usuario actual, enviados por el backend en auth.user.permisos
export function usePermisos() {
    const page = usePage()

    const permisos = computed(() => {
        const lista = page.props.auth?.user?.permisos
        return Array.isArray(lista) ? lista : []
    })

    /**
     * true si el usuario tiene el permiso indicado.
     * puede('comprobantes.anular')
     */
    function puede(permiso) {
        return permisos.value.includes(permiso)
    }

    return { puede, permisos }
}
