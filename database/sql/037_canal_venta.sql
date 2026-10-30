-- 037: canal de venta (tienda u online), opcional por empresa. Sirve para medir de dónde vienen las ventas;
-- no va a SUNAT ni se imprime. NULL = venta sin el dato (anterior a activarlo o con el módulo apagado).
-- No confundir con comprobantes.origen, que es técnico. Idempotente.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS canal_venta_activo BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE comprobantes ADD COLUMN IF NOT EXISTS canal_venta VARCHAR(10);
ALTER TABLE comprobantes DROP CONSTRAINT IF EXISTS comprobantes_canal_venta_check;
ALTER TABLE comprobantes ADD CONSTRAINT comprobantes_canal_venta_check
    CHECK (canal_venta IS NULL OR canal_venta IN ('tienda', 'online'));
