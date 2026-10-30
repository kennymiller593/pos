-- 035: motivo opcional de los ajustes de stock (entrada o salida/merma), escrito por quien ajusta.
-- Se muestra en el kardex. Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE movimientos_inventario ADD COLUMN IF NOT EXISTS observacion VARCHAR(250);
