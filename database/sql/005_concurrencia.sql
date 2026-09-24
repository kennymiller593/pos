-- 005: restricciones que protegen contra operaciones simultáneas
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- Dos ventas a la vez no pueden dejar el stock por debajo de cero
ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_cantidad_check;
ALTER TABLE stock ADD CONSTRAINT stock_cantidad_check CHECK (cantidad >= 0);

-- Un usuario solo puede tener una caja abierta a la vez (ya existía una por caja)
CREATE UNIQUE INDEX IF NOT EXISTS uq_apertura_usuario_abierta
    ON aperturas_caja (usuario_id) WHERE cerrada_en IS NULL;
