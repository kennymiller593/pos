-- 045: direcciones anteriores de los productos en la tienda en línea.
-- Cuando un producto cambia de nombre su slug cambia, y la dirección vieja (/catalogo/{slug-viejo}) ya estaba
-- en Google: aquí se guarda para responder con un 301 a la dirección actual en vez de un 404.
-- También guarda las direcciones del sistema anterior de una empresa (comando productos:slugs-anteriores).
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

CREATE TABLE IF NOT EXISTS producto_slugs_anteriores (
    id          UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id  UUID NOT NULL REFERENCES empresas(id),
    producto_id UUID NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    slug        VARCHAR(255) NOT NULL,
    creado_en   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_producto_slugs_anteriores UNIQUE (empresa_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_producto_slugs_anteriores_producto ON producto_slugs_anteriores (producto_id);
