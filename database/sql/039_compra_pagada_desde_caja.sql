-- 039: una compra al contado puede pagarse desde la caja abierta en el mismo registro (como lo hacía el sistema
-- anterior de los clientes). El pago queda en pagos_proveedor ligado a la compra, sin pasar por cuentas por pagar.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE pagos_proveedor ALTER COLUMN cuenta_id DROP NOT NULL;
ALTER TABLE pagos_proveedor ADD COLUMN IF NOT EXISTS compra_id UUID REFERENCES compras(id);
CREATE INDEX IF NOT EXISTS pagos_proveedor_compra_id_idx ON pagos_proveedor (compra_id);

-- un pago responde a una deuda o a una compra pagada al momento, nunca a nada
ALTER TABLE pagos_proveedor DROP CONSTRAINT IF EXISTS pagos_proveedor_origen_check;
ALTER TABLE pagos_proveedor ADD CONSTRAINT pagos_proveedor_origen_check CHECK (cuenta_id IS NOT NULL OR compra_id IS NOT NULL);
