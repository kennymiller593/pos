-- 019: tienda en línea (catálogo público por empresa en {slug}.inkanet.pro)
-- Cada empresa elige su dirección (tienda_slug), decide si la publica y guarda en tienda_config
-- lo que se muestra: presentación, contactos, color y si van los precios y la disponibilidad.
-- En productos: en_tienda (se muestra o no), destacado (va en "Productos destacados") y una
-- descripción para la página del producto.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_slug VARCHAR(40);
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_publicada BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_config JSONB;

-- una dirección es de una sola empresa (siempre en minúsculas)
CREATE UNIQUE INDEX IF NOT EXISTS uq_empresas_tienda_slug ON empresas (tienda_slug) WHERE tienda_slug IS NOT NULL;

ALTER TABLE productos ADD COLUMN IF NOT EXISTS en_tienda BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS destacado BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS descripcion VARCHAR(1500);

-- el catálogo público solo lee los productos visibles de una empresa
CREATE INDEX IF NOT EXISTS idx_productos_tienda ON productos (empresa_id, nombre)
    WHERE en_tienda AND activo AND eliminado_en IS NULL;
