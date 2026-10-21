-- 031: miniatura de la foto de cada producto (400x300, WebP) para las tarjetas de la tienda en línea.
-- La foto grande (800x600) se sigue usando en la ficha. Se genera sola al subir o cambiar la foto;
-- las fotos que ya existían se procesan con `php artisan productos:miniaturas`.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE productos ADD COLUMN IF NOT EXISTS imagen_miniatura TEXT;
