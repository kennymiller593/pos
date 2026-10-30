-- 036: texto libre que cada empresa imprime al pie de sus cotizaciones (agradecimiento, condiciones,
-- políticas de cambio). Vacío = no se imprime nada. Idempotente.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS pie_cotizacion VARCHAR(1000);
