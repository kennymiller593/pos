//
import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { createPinia } from 'pinia'
import '../css/app.css'

// el <title> de cada pagina: "Comprobantes · inkaPos" (o solo la marca en la landing/login)
const nombreApp = import.meta.env.VITE_APP_NAME || 'inkaPos'

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · ${nombreApp}` : nombreApp),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        // cada petición dice para qué usuario se dibujó esta pestaña: si en otra pestaña del mismo
        // navegador entró otro usuario, el servidor no la acepta y recarga la página (ver SesionCoherente)
        let usuarioDePestana = props.initialPage.props.auth?.user?.id ?? null
        router.on('success', (evento) => { usuarioDePestana = evento.detail.page.props.auth?.user?.id ?? usuarioDePestana })
        router.on('before', (evento) => { if (usuarioDePestana) evento.detail.visit.headers['X-Usuario'] = usuarioDePestana })

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el)
    },
})