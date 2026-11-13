// Variantes (tallas y colores): utilidades para operar por modelo en el POS, compras, transferencias y stock.
// Cada variante es un producto normal con `grupo_id`, `atributos` ({ Talla: 'M', Color: 'Rojo' }) y `variante_orden`;
// el modelo trae la definición ordenada de sus características: [{ nombre: 'Talla', valores: ['S', 'M'] }, ...].

// para comparar textos: minúsculas, sin tildes y sin espacios de más
export const normalizar = (texto) => String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()

// ["M", "Rojo"] y ["m ", "ROJO"] son la misma combinación (igual que VarianteService::clave)
export const claveValores = (valores) => valores.map((v) => String(v ?? '').trim().toLowerCase()).join('\x1f')

// los valores de una variante en el orden de las características del modelo
export const valoresDe = (variante, atributos) => (atributos ?? []).map((a) => String(variante.atributos?.[a.nombre] ?? '').trim())

// ¿cada palabra buscada empieza alguna palabra del nombre? ("polo rojo m" encuentra "Polo básico M Rojo").
// Una palabra de 1 o 2 letras suele ser una talla (m, l, xl): esa debe ser una palabra EXACTA del nombre, no un
// prefijo ("m" no es "movil"), así "casaca movil negro m" da solo la talla M.
export function coincidePalabras(nombreNormalizado, palabras) {
    const partes = nombreNormalizado.split(/\s+/)
    return palabras.every((palabra) => (palabra.length <= 2
        ? partes.includes(palabra)
        : partes.some((parte) => parte.startsWith(palabra))))
}

/**
 * Buscador de compras y transferencias: lo escrito contra los productos sueltos y los modelos.
 *  - Sin modelos (empresa sin variantes): los productos que cumplen `coincide`, como siempre.
 *  - Con modelos: salen los modelos que coinciden (por su nombre o por alguna variante) y, de sus variantes, solo
 *    las que lo escrito señala de forma concreta: todas las palabras están en la variante y no bastaba el nombre
 *    del modelo ("polo" → el modelo; "polo rojo m" → esa variante), o se escribió su código.
 * `coincide(producto, texto)` es la regla de siempre de la pantalla (nombre, código interno o código de barras).
 * @returns {{ modelos: object[], productos: object[] }}
 */
export function buscarConModelos(texto, productos, grupos, coincide, limite = 8, limiteModelos = 4) {
    const buscado = String(texto ?? '').trim().toLowerCase()
    if (!buscado) return { modelos: [], productos: [] }
    if (!grupos.size) return { modelos: [], productos: productos.filter((p) => coincide(p, buscado)).slice(0, limite) }

    const palabras = normalizar(buscado).split(/\s+/)
    const modelos = new Set()
    const lista = []
    for (const producto of productos) {
        const grupo = producto.grupo_id ? grupos.get(producto.grupo_id) : null
        if (!grupo) {
            if (coincide(producto, buscado)) lista.push(producto)
            continue
        }
        const deSiempre = coincide(producto, buscado)
        const porPalabras = coincidePalabras(normalizar(producto.nombre), palabras)
        if (!deSiempre && !porPalabras) continue
        modelos.add(grupo)
        const porCodigo = deSiempre && !producto.nombre.toLowerCase().includes(buscado)
        if (porCodigo || (porPalabras && !coincidePalabras(grupo.nombreNormalizado, palabras))) lista.push(producto)
    }
    return { modelos: [...modelos].slice(0, limiteModelos), productos: lista.slice(0, limite) }
}

/**
 * Agrupa los productos que son variantes por su modelo. Solo quedan los modelos con 2 o más variantes
 * (uno con una sola se sigue viendo como producto suelto).
 * @returns Map<grupo_id, { id, modelo, nombre, nombreNormalizado, variantes }>
 */
export function agruparPorModelo(productos, modelos) {
    const grupos = new Map()
    if (!modelos?.length) return grupos
    const porId = new Map(modelos.map((m) => [m.id, m]))
    for (const producto of productos) {
        const modelo = producto.grupo_id ? porId.get(producto.grupo_id) : null
        if (!modelo) continue
        let grupo = grupos.get(modelo.id)
        if (!grupo) {
            grupo = { esModelo: true, id: `modelo-${modelo.id}`, grupoId: modelo.id, modelo, nombre: modelo.nombre, nombreNormalizado: normalizar(modelo.nombre), variantes: [] }
            grupos.set(modelo.id, grupo)
        }
        grupo.variantes.push(producto)
    }
    for (const [id, grupo] of grupos) {
        if (grupo.variantes.length < 2) grupos.delete(id)
        else grupo.variantes.sort((a, b) => (a.variante_orden ?? 0) - (b.variante_orden ?? 0))
    }
    return grupos
}

/**
 * Reparte las variantes en una rejilla según cuántas características tenga el modelo:
 *  - 1: una sola lista de opciones (`columnas`), sin filas;
 *  - 2: filas = la primera (Talla), columnas = la segunda (Color);
 *  - 3: además `pestanas` = la tercera, que se elige antes de ver la rejilla.
 * Los valores salen en el orden en que se escribieron en el modelo y solo los que tienen alguna variante;
 * la combinación que no existe (omitida o inactiva) devuelve null y la celda queda vacía.
 */
export function armarRejilla(atributos, variantes) {
    const definicion = (atributos ?? []).slice(0, 3)
    const lista = variantes ?? []

    const ejes = definicion.map((atributo) => {
        const presentes = new Map()
        for (const variante of lista) {
            const valor = String(variante.atributos?.[atributo.nombre] ?? '').trim()
            if (valor && !presentes.has(valor.toLowerCase())) presentes.set(valor.toLowerCase(), valor)
        }
        const ordenados = []
        for (const valor of atributo.valores ?? []) {
            const clave = String(valor).trim().toLowerCase()
            if (presentes.has(clave)) {
                ordenados.push(String(valor).trim())
                presentes.delete(clave)
            }
        }
        // un valor que ya no está en la definición pero sigue en una variante: al final
        return [...ordenados, ...presentes.values()]
    })

    const porClave = new Map()
    for (const variante of lista) {
        const clave = claveValores(valoresDe(variante, definicion))
        if (!porClave.has(clave)) porClave.set(clave, variante)
    }

    const unEje = definicion.length <= 1
    const filas = unEje ? [] : ejes[0]
    const columnas = unEje ? (ejes[0] ?? []) : ejes[1]
    const pestanas = ejes[2] ?? []

    const variante = (fila, columna, pestana) => porClave.get(claveValores(
        unEje ? [columna] : (definicion.length === 2 ? [fila, columna] : [fila, columna, pestana]),
    )) ?? null

    // variantes cuyos valores no calzan con la definición (no debería pasar): se listan aparte para no perderlas
    const ubicadas = new Set()
    for (const pestana of pestanas.length ? pestanas : [null]) {
        for (const fila of filas.length ? filas : [null]) {
            for (const columna of columnas) {
                const hallada = variante(fila, columna, pestana)
                if (hallada) ubicadas.add(hallada.id)
            }
        }
    }

    return {
        unEje,
        filas,
        columnas,
        pestanas,
        nombreFilas: unEje ? '' : (definicion[0]?.nombre ?? ''),
        nombreColumnas: (unEje ? definicion[0]?.nombre : definicion[1]?.nombre) ?? '',
        nombrePestanas: definicion[2]?.nombre ?? '',
        variante,
        sueltas: lista.filter((v) => !ubicadas.has(v.id)),
    }
}
