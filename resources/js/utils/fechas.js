// Fecha de hoy en la hora del equipo (no UTC), como 'YYYY-MM-DD'.
// new Date().toISOString() da la fecha en UTC: en el Perú, desde las 7 p. m. ya devuelve el día siguiente.
export function fechaLocal(masDias = 0) {
    const d = new Date()
    d.setDate(d.getDate() + masDias)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
