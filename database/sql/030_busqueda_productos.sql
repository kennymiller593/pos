-- 030: búsqueda de productos con tolerancia (tienda en línea y chatbot).
-- normalizar_texto(): minúsculas y sin tildes ni ñ, para que "maíz" = "maiz" y "niño" = "nino" en ambos lados
-- de la comparación. Es IMMUTABLE, así que se puede indexar; el índice de trigramas (pg_trgm, ya instalado)
-- acelera el LIKE '%...%' y la similitud sobre el nombre.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

CREATE OR REPLACE FUNCTION normalizar_texto(texto TEXT) RETURNS TEXT
LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$
    SELECT lower(translate(texto, 'áéíóúüàèìòùâêîôûÁÉÍÓÚÜÀÈÌÒÙÂÊÎÔÛñÑçÇ', 'aeiouuaeiouaeiouAEIOUUAEIOUAEIOUnNcC'));
$$;

CREATE INDEX IF NOT EXISTS productos_nombre_normalizado_trgm ON productos USING gin (normalizar_texto(nombre) gin_trgm_ops);
