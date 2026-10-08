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
            return `• ${i.cantidad} x ${i.nombre}${i.unidad ? ` (${i.unidad})` : ''}${i.codigo ? ` [${i.codigo}]` : ''}${precio}`
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
