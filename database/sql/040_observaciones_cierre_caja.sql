-- 040: al cerrar la caja el cajero puede dejar una observación corta (como en el sistema anterior de los clientes).
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE aperturas_caja ADD COLUMN IF NOT EXISTS observaciones TEXT;
