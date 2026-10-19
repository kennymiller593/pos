-- 029: la tienda en línea se arma más a gusto del dueño.
-- En categorias: orden (en qué posición sale en la tienda; sin orden, por nombre) e imagen_url
-- (foto propia de la categoría; sin ella se usa la de uno de sus productos).
-- En productos: destacado_orden (posición del producto en la sección destacada de la portada).
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE categorias ADD COLUMN IF NOT EXISTS orden INTEGER;
ALTER TABLE categorias ADD COLUMN IF NOT EXISTS imagen_url TEXT;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS destacado_orden INTEGER;
