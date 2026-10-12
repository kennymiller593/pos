// Markdown sencillo y seguro: negritas, cursivas, listas, títulos y saltos de línea (el texto se escapa primero)
export function markdownSencillo(texto) {
    const esc = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const enLinea = (t) => esc(t)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/`([^`]+)`/g, '<code class="rounded bg-black/5 px-1 dark:bg-white/10">$1</code>')
    const lineas = texto.split(/\r?\n/)
    let salida = ''
    let listaAbierta = null
    const cerrar = () => { if (listaAbierta) { salida += `</${listaAbierta}>`; listaAbierta = null } }
    for (const l of lineas) {
        const vineta = l.match(/^\s*[-*•]\s+(.*)$/)
        const numerada = l.match(/^\s*\d+[.)]\s+(.*)$/)
        const titulo = l.match(/^\s*#{1,4}\s+(.*)$/)
        if (vineta || numerada) {
            const tipo = vineta ? 'ul' : 'ol'
            if (listaAbierta !== tipo) { cerrar(); salida += `<${tipo} class="${tipo === 'ul' ? 'list-disc' : 'list-decimal'} space-y-0.5 pl-5">`; listaAbierta = tipo }
            salida += `<li>${enLinea((vineta ?? numerada)[1])}</li>`
        } else if (titulo) {
            cerrar(); salida += `<p class="mt-2 font-semibold">${enLinea(titulo[1])}</p>`
        } else if (l.trim() === '') {
            cerrar(); salida += '<div class="h-2"></div>'
        } else {
            cerrar(); salida += `<p>${enLinea(l)}</p>`
        }
    }
    cerrar()
    return salida
}
