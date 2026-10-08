-- 023: la descripción del producto (la que sale en la tienda en línea) pasa de 1500 caracteres a texto libre.
-- La app valida hasta 3000; las descripciones importadas de otros sistemas traen párrafos completos.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE productos ALTER COLUMN descripcion TYPE TEXT;
