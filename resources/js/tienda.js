/*
 * Tienda en línea (la página pública de cada empresa): buscador que sugiere mientras se escribe
 * y el pedido que el cliente va armando para enviarlo por WhatsApp.
 * La tienda funciona sin esto: los enlaces y el buscador siguen sirviendo sin JavaScript.
 */

const datos = JSON.parse(document.getElementById('tienda-datos')?.textContent || '{}')
const texto = (valor) => String(valor ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
const soles = (n) => `S/ ${Number(n).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const icono = (trazos, clase = 'size-4') =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="${clase} shrink-0" aria-hidden="true">${trazos}</svg>`
const TRAZOS = {
    mas: '<path d="M5 12h14"/><path d="M12 5v14"/>',
    menos: '<path d="M5 12h14"/>',
    quitar: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    paquete: '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    flecha: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
}

// el orden del catálogo se aplica al elegirlo (sin JavaScript queda el botón "Aplicar")
document.getElementById('orden')?.addEventListener('change', (e) => e.target.form?.submit())
// filtros de la plantilla Mercado: el precio tope se aplica al soltar el control y se muestra mientras se mueve
document.querySelectorAll('[data-filtro-auto]').forEach((campo) => {
    campo.addEventListener('change', () => campo.form?.submit())
    campo.addEventListener('input', () => {
        const valor = campo.form?.querySelector('[data-filtro-valor]')
        if (valor) valor.textContent = `S/ ${Number(campo.value).toLocaleString('es-PE')}`
    })
})

// ---------------------------------------------------------------- buscador
const buscador = document.getElementById('buscador')
const sugerencias = document.getElementById('sugerencias')

if (buscador && sugerencias) {
    let espera = null
    let pedido = null // la consulta en curso: si se sigue escribiendo, se cancela
    let ultimo = ''

    const cerrar = () => sugerencias.classList.add('hidden')
    const abrir = () => sugerencias.classList.remove('hidden')

    const fila = (p) => `
        <li>
            <a href="${texto(p.url)}" class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                <span class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-50 text-slate-300 ring-1 ring-slate-100">
                    ${p.imagen ? `<img src="${texto(p.imagen)}" alt="" loading="lazy" class="size-full object-contain mix-blend-multiply">` : icono(TRAZOS.paquete, 'size-5')}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="line-clamp-1 text-sm font-medium text-slate-900">${texto(p.nombre)}</span>
                    <span class="block truncate text-xs text-slate-500">${texto(p.detalle ?? '')}</span>
                </span>
                ${p.precio !== null ? `<span class="shrink-0 text-sm font-semibold whitespace-nowrap">${soles(p.precio)}</span>` : ''}
            </a>
        </li>`

    function pintar(q, respuesta) {
        const todos = `/catalogo?q=${encodeURIComponent(q)}`

        sugerencias.innerHTML = respuesta.productos.length
            ? `<ul class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto overscroll-contain">${respuesta.productos.map(fila).join('')}</ul>
               <a href="${todos}" class="flex items-center justify-center gap-1.5 border-t border-slate-100 bg-slate-50 px-4 py-3 text-sm font-semibold text-(--marca-texto) hover:underline">
                   ${respuesta.total > respuesta.productos.length ? `Ver los ${respuesta.total} resultados` : 'Ver en el catálogo'} ${icono(TRAZOS.flecha)}
               </a>`
            : `<p class="px-4 py-6 text-center text-sm text-slate-500">No encontramos productos con <span class="font-semibold text-slate-800">“${texto(q)}”</span>.${
                  datos.whatsapp ? ` <a href="https://wa.me/${datos.whatsapp}?text=${encodeURIComponent(`Hola, busco: ${q}`)}" target="_blank" rel="noopener" class="font-semibold text-(--marca-texto) hover:underline">Pregúntanos por WhatsApp</a>` : ''
              }</p>`
        abrir()
    }

    async function buscar() {
        const q = buscador.value.trim()
        if (q.length < 2) {
            ultimo = ''
            pedido?.abort()
            return cerrar()
        }
        if (q === ultimo) return abrir()

        pedido?.abort()
        pedido = new AbortController()
        try {
            const r = await fetch(`/buscar?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: pedido.signal })
            if (!r.ok) return
            ultimo = q
            // si mientras llegaba la respuesta se escribió otra cosa, esta ya no sirve
            if (buscador.value.trim() === q) pintar(q, await r.json())
        } catch {
            // consulta cancelada o sin conexión: el botón "Buscar" sigue funcionando
        }
    }

    buscador.addEventListener('input', () => {
        clearTimeout(espera)
        espera = setTimeout(buscar, 220)
    })
    buscador.addEventListener('focus', () => buscador.value.trim().length >= 2 && buscar())
    buscador.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrar()
        // con la flecha se baja a la lista de sugerencias
        if (e.key === 'ArrowDown' && !sugerencias.classList.contains('hidden')) {
            e.preventDefault()
            sugerencias.querySelector('a')?.focus()
        }
    })
    sugerencias.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            cerrar()
            buscador.focus()
        }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return
        e.preventDefault()
        const enlaces = [...sugerencias.querySelectorAll('a')]
        const destino = enlaces[enlaces.indexOf(document.activeElement) + (e.key === 'ArrowDown' ? 1 : -1)]
        ;(destino ?? buscador).focus()
    })
    document.addEventListener('click', (e) => {
        if (!e.target.closest('[role="search"]')) cerrar()
    })
}

// ---------------------------------------------------------------- pedido
const panel = document.getElementById('pedido')

if (panel && datos.whatsapp) {
    const CLAVE = 'pedido:v1'
    const MAXIMO = 60 // productos distintos: más que eso no entra en un mensaje de WhatsApp
    const lista = panel.querySelector('[data-pedido-lista]')
    const vacio = panel.querySelector('[data-pedido-vacio]')
    const pie = panel.querySelector('[data-pedido-pie]')
    const total = panel.querySelector('[data-pedido-total]')
    const enviar = panel.querySelector('[data-pedido-enviar]')
    const aviso = document.getElementById('pedido-aviso')
    let ocultarAviso = null

    const leer = () => {
        try {
            const guardado = JSON.parse(localStorage.getItem(CLAVE) || '[]')
            return Array.isArray(guardado) ? guardado.filter((i) => i && i.id && i.nombre && i.cantidad > 0) : []
        } catch {
            return []
        }
    }
    let items = leer()

    const guardar = () => {
        try {
            localStorage.setItem(CLAVE, JSON.stringify(items))
        } catch {
            // navegación privada o sin espacio: el pedido vive mientras dure la página
        }
        pintar()
    }

    const unidades = () => items.reduce((suma, i) => suma + i.cantidad, 0)
    const conPrecio = () => items.length > 0 && items.every((i) => i.precio !== null && i.precio !== undefined)
    const importe = () => items.reduce((suma, i) => suma + i.cantidad * Number(i.precio ?? 0), 0)

    function mensaje() {
        const nombre = panel.querySelector('[data-pedido-nombre]')?.value.trim()
        const nota = panel.querySelector('[data-pedido-nota]')?.value.trim()

        const lineas = items.map((i) => {
            const precio = i.precio !== null && i.precio !== undefined ? ` — ${soles(i.cantidad * i.precio)}` : ''
            return `• ${i.cantidad} x ${i.nombre}${i.unidad ? ` (${i.unidad})` : ''}${precio}`
        })

        return [
            `Hola, quiero hacer este pedido en ${datos.nombre}:`,
            '',
            ...lineas,
            '',
            conPrecio() ? `Total: ${soles(importe())}` : null,
            nombre ? `Nombre: ${nombre}` : null,
            nota ? `Nota: ${nota}` : null,
        ]
            .filter((l) => l !== null)
            .join('\n')
            .trim()
    }

    const filaPedido = (i) => `
        <li class="flex gap-3 px-5 py-4" data-item="${texto(i.id)}">
            <a href="${texto(i.url)}" class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl bg-slate-50 text-slate-300 ring-1 ring-slate-100">
                ${i.imagen ? `<img src="${texto(i.imagen)}" alt="" class="size-full object-contain mix-blend-multiply">` : icono(TRAZOS.paquete, 'size-6')}
            </a>
            <div class="min-w-0 flex-1">
                <div class="flex items-start gap-2">
                    <a href="${texto(i.url)}" class="line-clamp-2 flex-1 text-sm leading-snug font-medium hover:text-(--marca-texto)">${texto(i.nombre)}</a>
                    <button type="button" data-quitar class="-mt-1 -mr-1 grid size-8 shrink-0 place-items-center rounded-lg text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600" aria-label="Quitar ${texto(i.nombre)} del pedido" title="Quitar">${icono(TRAZOS.quitar)}</button>
                </div>
                ${i.unidad ? `<p class="text-xs text-slate-500">${texto(i.unidad)}</p>` : ''}
                <div class="mt-2 flex items-center justify-between gap-3">
                    <div class="flex h-9 items-center rounded-lg ring-1 ring-slate-200">
                        <button type="button" data-menos class="grid h-9 w-9 place-items-center rounded-l-lg text-slate-600 transition-colors hover:bg-slate-100" aria-label="Quitar uno">${icono(TRAZOS.menos)}</button>
                        <input type="text" inputmode="numeric" data-cantidad value="${i.cantidad}" maxlength="3" aria-label="Cantidad de ${texto(i.nombre)}" class="h-9 w-10 bg-transparent text-center text-sm font-semibold tabular-nums focus:outline-none">
                        <button type="button" data-mas class="grid h-9 w-9 place-items-center rounded-r-lg text-slate-600 transition-colors hover:bg-slate-100" aria-label="Agregar uno">${icono(TRAZOS.mas)}</button>
                    </div>
                    <p class="text-sm font-semibold whitespace-nowrap">${i.precio !== null && i.precio !== undefined ? soles(i.cantidad * i.precio) : '<span class="font-medium text-slate-500">Precio por confirmar</span>'}</p>
                </div>
            </div>
        </li>`

    function pintar() {
        const cuantos = unidades()

        document.querySelectorAll('[data-pedido-cuenta]').forEach((marca) => {
            marca.textContent = cuantos > 99 ? '99+' : cuantos
            marca.classList.toggle('hidden', cuantos === 0)
        })
        document.querySelectorAll('[data-pedido-flotante]').forEach((boton) => boton.classList.toggle('hidden', cuantos === 0))

        vacio.classList.toggle('hidden', items.length > 0)
        lista.classList.toggle('hidden', items.length === 0)
        pie.classList.toggle('hidden', items.length === 0)
        lista.innerHTML = items.map(filaPedido).join('')
        total.innerHTML = conPrecio()
            ? `<span class="text-slate-500">Total</span><span class="text-xl font-semibold tracking-tight">${soles(importe())}</span>`
            : `<span class="text-slate-500">${cuantos} producto${cuantos === 1 ? '' : 's'}</span><span class="text-sm font-medium text-slate-500">Te confirmamos el total por WhatsApp</span>`
    }

    function agregar(producto, cantidad) {
        const actual = items.find((i) => i.id === producto.id)
        if (!actual && items.length >= MAXIMO) return avisar(`Tu pedido ya tiene ${MAXIMO} productos distintos. Envíalo y arma otro.`)

        if (actual) {
            // el precio y los datos se refrescan con lo que muestra la tienda ahora
            Object.assign(actual, producto, { cantidad: Math.min(999, actual.cantidad + cantidad) })
        } else {
            items.push({ ...producto, cantidad: Math.min(999, cantidad) })
        }
        guardar()
        avisar(`Añadido: ${producto.nombre}`, true)
    }

    function avisar(mensajeAviso, conBoton = false) {
        if (!aviso) return
        aviso.querySelector('[data-aviso-texto]').textContent = mensajeAviso
        aviso.querySelector('[data-pedido-abrir]').classList.toggle('hidden', !conBoton)
        aviso.classList.remove('hidden')
        clearTimeout(ocultarAviso)
        ocultarAviso = setTimeout(() => aviso.classList.add('hidden'), 3200)
    }

    let antesDeAbrir = null
    function abrirPanel() {
        antesDeAbrir = document.activeElement
        aviso?.classList.add('hidden')
        panel.classList.remove('hidden')
        document.documentElement.classList.add('overflow-hidden')
        panel.querySelector('[data-pedido-cerrar-boton]')?.focus()
    }
    function cerrarPanel() {
        panel.classList.add('hidden')
        document.documentElement.classList.remove('overflow-hidden')
        antesDeAbrir?.focus?.()
    }

    document.addEventListener('click', (e) => {
        const anadir = e.target.closest('[data-anadir]')
        if (anadir) {
            e.preventDefault()
            const zona = anadir.closest('[data-zona-pedido]')
            const campo = zona?.querySelector('[data-cantidad-elegida]')
            const cantidad = Math.max(1, Math.min(999, parseInt(campo?.value, 10) || 1))
            agregar(JSON.parse(anadir.dataset.anadir), cantidad)
            if (campo) campo.value = 1

            // el botón confirma por un momento
            const etiqueta = anadir.querySelector('[data-anadir-texto]')
            if (etiqueta && !anadir.dataset.texto) {
                anadir.dataset.texto = etiqueta.textContent
                etiqueta.textContent = 'Añadido'
                setTimeout(() => {
                    etiqueta.textContent = anadir.dataset.texto
                    delete anadir.dataset.texto
                }, 1400)
            }
            return
        }

        // "Comprar" por WhatsApp desde la tarjeta: el mensaje lleva la cantidad elegida
        const comprar = e.target.closest('[data-comprar]')
        if (comprar) {
            const campo = comprar.closest('[data-zona-pedido]')?.querySelector('[data-cantidad-elegida]')
            const cantidad = Math.max(1, Math.min(999, parseInt(campo?.value, 10) || 1))
            comprar.dataset.base ??= comprar.href
            comprar.href = comprar.dataset.base + encodeURIComponent(`
Cantidad: ${cantidad}`)
            return
        }

        // cantidad a añadir, en la página del producto
        const paso = e.target.closest('[data-elegir-menos], [data-elegir-mas]')
        if (paso) {
            const campo = paso.closest('[data-zona-pedido]')?.querySelector('[data-cantidad-elegida]')
            if (campo) campo.value = Math.max(1, Math.min(999, (parseInt(campo.value, 10) || 1) + (paso.hasAttribute('data-elegir-mas') ? 1 : -1)))
            return
        }

        if (e.target.closest('[data-pedido-abrir]')) return abrirPanel()
        if (e.target.closest('[data-pedido-cerrar]')) return cerrarPanel()

        if (e.target.closest('[data-pedido-vaciar]')) {
            items = []
            return guardar()
        }

        const item = e.target.closest('[data-item]')
        if (!item || !panel.contains(item)) return
        const elegido = items.find((i) => i.id === item.dataset.item)
        if (!elegido) return

        if (e.target.closest('[data-quitar]')) items = items.filter((i) => i !== elegido)
        else if (e.target.closest('[data-mas]')) elegido.cantidad = Math.min(999, elegido.cantidad + 1)
        else if (e.target.closest('[data-menos]')) {
            // bajar de 1 es quitarlo
            if (elegido.cantidad <= 1) items = items.filter((i) => i !== elegido)
            else elegido.cantidad -= 1
        } else return
        guardar()
    })

    // cantidad escrita a mano dentro del pedido
    panel.addEventListener('change', (e) => {
        const campo = e.target.closest('[data-cantidad]')
        const elegido = items.find((i) => i.id === campo?.closest('[data-item]')?.dataset.item)
        if (!elegido) return
        elegido.cantidad = Math.max(1, Math.min(999, parseInt(campo.value, 10) || 1))
        guardar()
    })

    // el enlace de WhatsApp se arma en el momento del clic, con lo que haya en el pedido
    enviar.addEventListener('click', (e) => {
        if (!items.length) return e.preventDefault()
        enviar.href = `https://wa.me/${datos.whatsapp}?text=${encodeURIComponent(mensaje())}`
    })

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.classList.contains('hidden')) cerrarPanel()
    })
    // el pedido es el mismo en todas las pestañas de la tienda
    window.addEventListener('storage', (e) => {
        if (e.key === CLAVE) {
            items = leer()
            pintar()
        }
    })

    // sin JavaScript estos botones no harían nada: aparecen recién aquí
    document.documentElement.classList.add('con-pedido')
    pintar()
}

// ---------------------------------------------------------------- asistente (chatbot de la tienda)
const bot = document.getElementById('asistente')

if (bot && datos.asistente) {
    const CLAVE_BOT = 'asistente:v1'
    const conf = datos.asistente
    const panelBot = bot.querySelector('[data-bot-panel]')
    const mensajes = bot.querySelector('[data-bot-mensajes]')
    const formBot = bot.querySelector('[data-bot-form]')
    const entrada = bot.querySelector('[data-bot-texto]')
    const botonEnviar = bot.querySelector('[data-bot-enviar]')
    let estado = leerBot()
    let enviando = false
    let sondeo = null // cuando atiende una persona, se consulta cada pocos segundos si respondió
    let sinLeer = 0 // respuestas de una persona llegadas con el chat cerrado (se marcan en el botón)
    const globo = bot.querySelector('[data-bot-globo]')

    // un "tin" corto cuando una persona del negocio responde (el navegador solo deja sonar tras una interacción)
    let audio = null
    function sonar() {
        try {
            const Clase = window.AudioContext || window.webkitAudioContext
            if (!Clase) return
            audio ??= new Clase()
            if (audio.state === 'suspended') audio.resume()
            const t = audio.currentTime
            for (const [f, d] of [[880, 0], [1320, 0.12]]) {
                const o = audio.createOscillator()
                const g = audio.createGain()
                o.type = 'sine'
                o.frequency.value = f
                g.gain.setValueAtTime(0.0001, t + d)
                g.gain.exponentialRampToValueAtTime(0.25, t + d + 0.01)
                g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.16)
                o.connect(g).connect(audio.destination)
                o.start(t + d)
                o.stop(t + d + 0.2)
            }
        } catch { /* sin audio no pasa nada */ }
    }
    function marcarGlobo() {
        if (!globo) return
        globo.textContent = sinLeer > 9 ? '9+' : String(sinLeer)
        globo.classList.toggle('hidden', sinLeer === 0)
    }

    function leerBot() {
        try {
            const g = JSON.parse(localStorage.getItem(CLAVE_BOT) || '{}')
            return {
                visitante: typeof g.visitante === 'string' && /^[A-Za-z0-9_-]{8,64}$/.test(g.visitante) ? g.visitante : idVisitante(),
                conversacion: typeof g.conversacion === 'string' ? g.conversacion : null,
                mensajes: Array.isArray(g.mensajes) ? g.mensajes.filter((m) => m && ['user', 'assistant', 'humano'].includes(m.rol) && typeof m.contenido === 'string').slice(-40) : [],
                modo: g.modo === 'humano' ? 'humano' : 'bot',
                ultimo: Number(g.ultimo) || 0, // cuándo escribió por última vez
            }
        } catch {
            return { visitante: idVisitante(), conversacion: null, mensajes: [], modo: 'bot', ultimo: 0 }
        }
    }
    function idVisitante() {
        const bytes = new Uint8Array(16)
        crypto.getRandomValues(bytes)
        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
    }
    const guardarBot = () => {
        try { localStorage.setItem(CLAVE_BOT, JSON.stringify(estado)) } catch { /* sin almacenamiento: la charla dura lo que dure la página */ }
    }

    // Markdown mínimo y seguro: negritas, cursivas, listas (el texto se escapa primero)
    const enLinea = (t) => texto(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>')
    function markdown(t) {
        let salida = ''
        let lista = null
        const cerrar = () => { if (lista) { salida += `</${lista}>`; lista = null } }
        for (const l of String(t).split(/\r?\n/)) {
            const vineta = l.match(/^\s*[-*•]\s+(.*)$/)
            const numerada = l.match(/^\s*\d+[.)]\s+(.*)$/)
            if (vineta || numerada) {
                const tipo = vineta ? 'ul' : 'ol'
                if (lista !== tipo) { cerrar(); salida += `<${tipo} class="${tipo === 'ul' ? 'list-disc' : 'list-decimal'} space-y-0.5 pl-5">`; lista = tipo }
                salida += `<li>${enLinea((vineta ?? numerada)[1])}</li>`
            } else if (l.trim() === '') {
                cerrar(); salida += '<div class="h-1.5"></div>'
            } else {
                cerrar(); salida += `<p>${enLinea(l.replace(/^\s*#{1,4}\s+/, ''))}</p>`
            }
        }
        cerrar()
        return salida
    }

    const tarjeta = (p) => `
        <article class="rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm">
            <div class="flex gap-3">
                <a href="${texto(p.url)}" class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl bg-slate-50 text-slate-300 ring-1 ring-slate-100">
                    ${p.imagen ? `<img src="${texto(p.imagen)}" alt="" loading="lazy" class="size-full object-contain mix-blend-multiply">` : icono(TRAZOS.paquete, 'size-6')}
                </a>
                <div class="min-w-0 flex-1">
                    ${p.categoria ? `<p class="truncate text-[10px] font-semibold tracking-wider text-slate-400 uppercase">${texto(p.categoria)}</p>` : ''}
                    <p class="line-clamp-2 text-sm leading-snug font-semibold text-slate-900">${texto(p.nombre)}</p>
                    ${p.precio !== null && p.precio !== undefined ? `<p class="mt-0.5 text-sm font-bold text-(--marca-texto)">${soles(p.precio)}${p.presentacion ? ` <span class="text-xs font-normal text-slate-500">· ${texto(p.presentacion)}</span>` : ''}</p>` : ''}
                    ${p.disponible === false ? '<p class="text-xs font-medium text-red-600">Agotado</p>' : ''}
                </div>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2">
                ${p.pedido ? `<a href="${texto(p.pedido)}" target="_blank" rel="noopener" class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl bg-[#25D366] text-xs font-semibold text-white transition-colors hover:bg-[#1eb957]">${icono('<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9z"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0zm0 0a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>', 'size-4')} Comprar</a>` : ''}
                <a href="${texto(p.url)}" class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl bg-(--bot) text-xs font-semibold text-(--sobre-bot) transition-opacity hover:opacity-90 ${p.pedido ? '' : 'col-span-2'}">${icono('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>', 'size-4')} Detalles</a>
            </div>
        </article>`

    const burbuja = (m) => {
        if (m.rol === 'user') return `<div class="flex justify-end"><div class="max-w-[85%] rounded-2xl rounded-br-md bg-(--bot) px-3.5 py-2 text-sm whitespace-pre-wrap text-(--sobre-bot)">${texto(m.contenido)}</div></div>`
        if (m.rol === 'humano') return `<div class="flex justify-start"><div class="max-w-[92%]"><p class="mb-0.5 pl-1 text-[11px] font-semibold text-slate-500">${texto(datos.nombre)} · una persona te responde</p><div class="rounded-2xl rounded-bl-md border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap text-slate-800">${texto(m.contenido)}</div></div></div>`
        const productos = Array.isArray(m.productos) && m.productos.length ? `<div class="mt-2 space-y-2">${m.productos.map(tarjeta).join('')}</div>` : ''
        return `<div class="flex justify-start"><div class="max-w-[92%]"><div class="space-y-1 rounded-2xl rounded-bl-md border border-slate-200 bg-white px-3.5 py-2 text-sm leading-relaxed text-slate-800">${markdown(m.contenido)}</div>${productos}</div></div>`
    }

    const saludo = () => `<div class="flex justify-start"><div class="max-w-[92%] space-y-1 rounded-2xl rounded-bl-md border border-slate-200 bg-white px-3.5 py-2 text-sm leading-relaxed text-slate-800"><p>${texto(conf.saludo)}</p></div></div>`
    const sugerencias = () => conf.sugerencias?.length
        ? `<div class="flex flex-wrap gap-1.5 pl-1">${conf.sugerencias.map((s) => `<button type="button" data-bot-sugerencia class="cursor-pointer rounded-full border border-slate-300 bg-white px-3 py-1.5 text-left text-xs font-medium text-slate-700 transition-colors hover:border-(--bot) hover:text-(--marca-texto)">${texto(s)}</button>`).join('')}</div>`
        : ''
    const esperando = () => `<div class="flex justify-start"><div class="rounded-2xl rounded-bl-md border border-amber-200 bg-amber-50 px-3.5 py-2 text-xs text-amber-800">Una persona de ${texto(datos.nombre)} te está atendiendo. Te responde por aquí en breve.</div></div>`
    const pensando = () => `<div data-bot-pensando class="flex justify-start"><div class="flex items-center gap-1.5 rounded-2xl rounded-bl-md border border-slate-200 bg-white px-4 py-3"><span class="size-2 animate-bounce rounded-full bg-slate-400 [animation-delay:-0.3s]"></span><span class="size-2 animate-bounce rounded-full bg-slate-400 [animation-delay:-0.15s]"></span><span class="size-2 animate-bounce rounded-full bg-slate-400"></span><span class="ml-2 text-xs text-slate-500">Buscando en el catálogo…</span></div></div>`

    function pintar(extra = '') {
        const ultimo = estado.mensajes.at(-1)
        const enEspera = estado.modo === 'humano' && ultimo?.rol === 'user' && !extra ? esperando() : ''
        mensajes.innerHTML = saludo() + (estado.mensajes.length ? '' : sugerencias()) + estado.mensajes.map(burbuja).join('') + enEspera + extra
        mensajes.scrollTop = mensajes.scrollHeight
    }

    // mientras atiende una persona, se revisa si escribió algo nuevo
    async function revisar() {
        if (!estado.conversacion || document.hidden) return
        const desde = [...estado.mensajes].reverse().find((m) => m.id)?.id ?? ''
        try {
            const r = await fetch(`/asistente/mensajes?conversacion_id=${encodeURIComponent(estado.conversacion)}&visitante=${encodeURIComponent(estado.visitante)}&desde=${encodeURIComponent(desde)}`, { headers: { Accept: 'application/json' } })
            if (!r.ok) return
            const respuesta = await r.json()
            const nuevos = (respuesta.mensajes ?? []).filter((m) => !estado.mensajes.some((x) => x.id && x.id === m.id))
            const cambioModo = respuesta.modo !== estado.modo
            estado.modo = respuesta.modo === 'humano' ? 'humano' : 'bot'
            if (nuevos.length) estado.mensajes.push(...nuevos.map((m) => ({ id: m.id, rol: m.rol, contenido: m.contenido, productos: m.productos ?? [], creado_en: m.creado_en })))
            if (nuevos.some((m) => m.rol === 'humano')) {
                sonar()
                if (panelBot.classList.contains('hidden')) { sinLeer += nuevos.filter((m) => m.rol === 'humano').length; marcarGlobo() }
            }
            if (nuevos.length || cambioModo) { guardarBot(); pintar() }
            vigilar()
        } catch { /* se reintenta en el siguiente ciclo */ }
    }
    // con el panel abierto se revisa si hay novedades: seguido cuando atiende una persona, de vez en cuando si no
    // (así el cliente se entera cuando el negocio toma la conversación aunque no haya vuelto a escribir)
    let ritmo = 0
    function vigilar() {
        // con el chat cerrado se sigue vigilando un rato (y siempre mientras atiende una persona):
        // si el negocio toma la conversación y responde, el botón lo avisa aunque el cliente siga navegando
        const abierto = !panelBot.classList.contains('hidden')
        const reciente = estado.ultimo && Date.now() - estado.ultimo < 30 * 60 * 1000
        const hace = !!estado.conversacion && (abierto || estado.modo === 'humano' || reciente)
        const nuevoRitmo = !hace ? 0 : estado.modo === 'humano' ? (abierto ? 4000 : 8000) : (abierto ? 10000 : 20000)
        if (nuevoRitmo === ritmo) return
        if (sondeo) { clearInterval(sondeo); sondeo = null }
        ritmo = nuevoRitmo
        if (ritmo) sondeo = setInterval(revisar, ritmo)
    }
    const aviso = (t) => `<p class="rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700">${texto(t)}</p>`

    function abrirBot() {
        panelBot.classList.remove('hidden')
        sinLeer = 0
        marcarGlobo()
        pintar()
        if (estado.conversacion) revisar()
        vigilar()
        if (window.matchMedia('(pointer: fine)').matches) entrada.focus()
    }
    const cerrarBot = () => { panelBot.classList.add('hidden'); vigilar() }

    async function enviarBot(contenido) {
        contenido = String(contenido ?? '').trim()
        if (!contenido || enviando) return
        enviando = true
        botonEnviar.disabled = true
        entrada.value = ''
        entrada.style.height = ''
        estado.mensajes.push({ rol: 'user', contenido })
        estado.ultimo = Date.now()
        guardarBot()
        pintar(pensando())
        try {
            const r = await fetch('/asistente', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ texto: contenido, visitante: estado.visitante, conversacion_id: estado.conversacion }),
            })
            const respuesta = await r.json().catch(() => ({}))
            if (!r.ok) {
                estado.mensajes.pop()
                entrada.value = contenido
                pintar(aviso(r.status === 429 ? 'Muchos mensajes seguidos: espera un momento y vuelve a intentarlo.' : (respuesta.mensaje ?? respuesta.message ?? 'No se pudo enviar la consulta. Inténtalo de nuevo.')))
                return
            }
            estado.conversacion = respuesta.conversacion_id ?? estado.conversacion
            estado.modo = respuesta.modo === 'humano' ? 'humano' : 'bot'
            if (respuesta.mensaje) estado.mensajes.push({ id: respuesta.mensaje.id, rol: 'assistant', contenido: respuesta.mensaje.contenido ?? '', productos: respuesta.mensaje.productos ?? [], creado_en: respuesta.mensaje.creado_en })
            guardarBot()
            pintar()
            vigilar()
        } catch {
            estado.mensajes.pop()
            entrada.value = contenido
            pintar(aviso('Sin conexión. Revisa tu internet e inténtalo de nuevo.'))
        } finally {
            enviando = false
            botonEnviar.disabled = false
        }
    }

    bot.querySelector('[data-bot-abrir]')?.addEventListener('click', abrirBot)
    bot.querySelector('[data-bot-cerrar]')?.addEventListener('click', cerrarBot)
    bot.querySelector('[data-bot-nueva]')?.addEventListener('click', () => {
        estado = { visitante: estado.visitante, conversacion: null, mensajes: [], modo: 'bot', ultimo: 0 }
        guardarBot()
        pintar()
        vigilar()
    })
    mensajes.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-bot-sugerencia]')
        if (chip) enviarBot(chip.textContent)
    })
    formBot.addEventListener('submit', (e) => {
        e.preventDefault()
        enviarBot(entrada.value)
    })
    entrada.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault()
            enviarBot(entrada.value)
        }
    })
    entrada.addEventListener('input', () => {
        entrada.style.height = 'auto'
        entrada.style.height = Math.min(entrada.scrollHeight, 128) + 'px'
    })
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panelBot.classList.contains('hidden')) cerrarBot()
    })

    document.documentElement.classList.add('con-bot')
    vigilar()
}

// ---------------- medición: Google Analytics, Meta Pixel, TikTok Pixel y Microsoft Clarity ----------------
// El dueño conecta cada herramienta desde su panel; aquí solo llegan los identificadores (#medicion-datos).
// Los scripts de cada plataforma se cargan desde este archivo (la CSP no permite código en línea) y las
// acciones del cliente (ver producto, buscar, agregar al carrito, pedir por WhatsApp) se envían como eventos.
const medicion = JSON.parse(document.getElementById('medicion-datos')?.textContent || 'null')
if (medicion && (medicion.ga4 || medicion.meta || medicion.tiktok || medicion.clarity)) {
    const cargar = (src) => {
        const s = document.createElement('script')
        s.async = true
        s.src = src
        document.head.appendChild(s)
    }

    // Google Analytics 4 (gtag.js)
    if (medicion.ga4) {
        window.dataLayer = window.dataLayer || []
        window.gtag = function () { window.dataLayer.push(arguments) }
        window.gtag('js', new Date())
        window.gtag('config', medicion.ga4)
        cargar(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(medicion.ga4)}`)
    }

    // Meta Pixel: el mismo arranque que da Meta, sin código en línea
    if (medicion.meta && !window.fbq) {
        const n = (window.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments) })
        if (!window._fbq) window._fbq = n
        n.push = n
        n.loaded = true
        n.version = '2.0'
        n.queue = []
        cargar('https://connect.facebook.net/en_US/fbevents.js')
        window.fbq('init', medicion.meta)
        window.fbq('track', 'PageView')
    }

    // TikTok Pixel: el mismo arranque que da TikTok
    if (medicion.tiktok && !window.ttq) {
        window.TiktokAnalyticsObject = 'ttq'
        const ttq = (window.ttq = [])
        ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent']
        ttq.setAndDefer = (t, e) => { t[e] = function () { t.push([e].concat(Array.prototype.slice.call(arguments, 0))) } }
        ttq.methods.forEach((m) => ttq.setAndDefer(ttq, m))
        ttq.instance = (t) => { const e = ttq._i[t] || []; ttq.methods.forEach((m) => ttq.setAndDefer(e, m)); return e }
        ttq.load = (e, n) => {
            const r = 'https://analytics.tiktok.com/i18n/pixel/events.js'
            ttq._i = ttq._i || {}; ttq._i[e] = []; ttq._i[e]._u = r
            ttq._t = ttq._t || {}; ttq._t[e] = +new Date()
            ttq._o = ttq._o || {}; ttq._o[e] = n || {}
            cargar(`${r}?sdkid=${encodeURIComponent(e)}&lib=ttq`)
        }
        ttq.load(medicion.tiktok)
        ttq.page()
    }

    // Microsoft Clarity
    if (medicion.clarity && !window.clarity) {
        window.clarity = function () { (window.clarity.q = window.clarity.q || []).push(arguments) }
        cargar(`https://www.clarity.ms/tag/${encodeURIComponent(medicion.clarity)}`)
    }

    // un evento, en el "idioma" de cada herramienta
    const articulo = (p, cantidad = 1) => ({ item_id: String(p.id ?? ''), item_name: p.nombre ?? '', price: p.precio ?? undefined, quantity: cantidad, item_category: p.categoria ?? undefined })
    const enviar = (ga4, meta, tiktok, p = {}, cantidad = 1) => {
        const valor = p.precio != null ? Math.round(p.precio * cantidad * 100) / 100 : undefined
        try {
            if (medicion.ga4) window.gtag('event', ga4, { currency: 'PEN', value: valor, items: p.id ? [articulo(p, cantidad)] : undefined, search_term: p.termino })
            if (medicion.meta) window.fbq('track', meta, { content_ids: p.id ? [String(p.id)] : undefined, content_name: p.nombre, content_type: p.id ? 'product' : undefined, value: valor, currency: 'PEN', search_string: p.termino })
            if (medicion.tiktok) window.ttq.track(tiktok, { contents: p.id ? [{ content_id: String(p.id), content_name: p.nombre, price: p.precio, quantity: cantidad }] : undefined, content_type: 'product', value: valor, currency: 'PEN', query: p.termino })
        } catch {
            // una herramienta que aún no cargó no debe romper la tienda
        }
    }

    // lo que pasó en esta página (ver un producto o buscar)
    if (medicion.evento?.tipo === 'producto') enviar('view_item', 'ViewContent', 'ViewContent', medicion.evento)
    if (medicion.evento?.tipo === 'busqueda') enviar('search', 'Search', 'Search', { termino: medicion.evento.termino })

    document.addEventListener('click', (e) => {
        const anadir = e.target.closest('[data-anadir]')
        if (anadir) {
            const campo = anadir.closest('[data-zona-pedido]')?.querySelector('[data-cantidad-elegida]')
            return enviar('add_to_cart', 'AddToCart', 'AddToCart', JSON.parse(anadir.dataset.anadir), Math.max(1, parseInt(campo?.value, 10) || 1))
        }
        // "Comprar" de una tarjeta o "Pedir por WhatsApp" de la ficha
        if (e.target.closest('[data-comprar], [data-pedir-producto]')) return enviar('generate_lead', 'Contact', 'Contact', medicion.evento?.tipo === 'producto' ? medicion.evento : {})
        // el carrito completo se envía por WhatsApp
        if (e.target.closest('[data-pedido-enviar]')) {
            const items = JSON.parse(localStorage.getItem('pedido:v1') || '[]')
            const total = items.reduce((s, i) => s + (i.precio ?? 0) * (i.cantidad ?? 1), 0)
            return enviar('begin_checkout', 'InitiateCheckout', 'PlaceAnOrder', { precio: total, id: undefined }, 1)
        }
    })
}
