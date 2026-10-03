-- 017: recorrido guiado (globos de bienvenida) para usuarios nuevos
-- Cada usuario lo ve una sola vez, en cualquier equipo; se puede repetir desde el menú de usuario.
-- Los usuarios que ya existían no lo ven solo: se marcan como "visto" únicamente al crear la columna,
-- así volver a correr el script no se lo quita a los que se registren después. Idempotente.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'usuarios' AND column_name = 'recorrido_visto_en'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN recorrido_visto_en TIMESTAMPTZ;
        UPDATE usuarios SET recorrido_visto_en = now();
    END IF;
END $$;
