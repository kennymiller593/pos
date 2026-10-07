-- 021: nombre de enlace (slug) de cada producto, para direcciones amigables en la tienda en línea
-- /producto/urea-46-x-50-kg en lugar de /producto/P0006/urea-46-x-50-kg.
-- Es único por empresa: si dos productos se llaman igual, el segundo lleva "-2".
-- Desde ahora lo mantiene la aplicación al crear o renombrar un producto; aquí se llenan los que ya existen.
-- Idempotente (solo toca los que no tienen). La BD se gestiona fuera de Laravel: espejar este script.

ALTER TABLE productos ADD COLUMN IF NOT EXISTS slug VARCHAR(220);

WITH base AS (
    SELECT id, empresa_id,
           COALESCE(NULLIF(left(trim(BOTH '-' FROM regexp_replace(
               lower(translate(nombre, 'áéíóúüñÁÉÍÓÚÜÑàèìòùÀÈÌÒÙ', 'aeiouunAEIOUUNaeiouAEIOU')),
               '[^a-z0-9]+', '-', 'g')), 200), ''), 'producto') AS s
      FROM productos
     WHERE slug IS NULL
), numerados AS (
    SELECT id, s, row_number() OVER (PARTITION BY empresa_id, s ORDER BY id) AS n
      FROM base
)
UPDATE productos p
   SET slug = CASE WHEN numerados.n = 1 THEN numerados.s ELSE numerados.s || '-' || numerados.n END
  FROM numerados
 WHERE p.id = numerados.id;

CREATE INDEX IF NOT EXISTS idx_productos_slug ON productos (empresa_id, slug);
