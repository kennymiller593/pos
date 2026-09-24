-- 002: anulación de compras y unicidad del comprobante del proveedor
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE compras ADD COLUMN IF NOT EXISTS estado VARCHAR(20) NOT NULL DEFAULT 'registrada';
ALTER TABLE compras ADD COLUMN IF NOT EXISTS anulada_en TIMESTAMPTZ;
ALTER TABLE compras ADD COLUMN IF NOT EXISTS anulada_por UUID REFERENCES usuarios(id);
ALTER TABLE compras ADD COLUMN IF NOT EXISTS motivo_anulacion VARCHAR(250);

ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_estado_check;
ALTER TABLE compras ADD CONSTRAINT compras_estado_check CHECK (estado IN ('registrada', 'anulada'));

-- El mismo comprobante de un proveedor no se registra dos veces (mientras no esté anulado)
CREATE UNIQUE INDEX IF NOT EXISTS uq_compras_proveedor_documento
    ON compras (empresa_id, proveedor_id, serie_numero)
    WHERE estado = 'registrada' AND proveedor_id IS NOT NULL AND serie_numero IS NOT NULL;

-- Tipo de movimiento de kardex para la reversión de una compra
ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_inventario_tipo_check;
ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_tipo_check
    CHECK (tipo IN ('venta', 'compra', 'ajuste', 'merma', 'transferencia_salida', 'transferencia_entrada', 'devolucion', 'compra_anulada'));
