-- 007: confirmación del correo de los usuarios registrados
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS email_verificado_en TIMESTAMPTZ;

-- Los usuarios existentes ya son de confianza
UPDATE usuarios SET email_verificado_en = COALESCE(email_verificado_en, creado_en, now());
