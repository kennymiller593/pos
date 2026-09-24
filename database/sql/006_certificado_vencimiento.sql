-- 006: fecha de vencimiento del certificado digital (para avisar antes de que expire)
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.
-- Tras aplicarlo, ejecutar `php artisan empresa:cifrar-certificados` para cifrar
-- los certificados guardados en claro (el modelo ahora los lee cifrados).

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS certificado_vence_en DATE;
