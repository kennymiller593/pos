-- 038: abono pagado en el momento de la venta al crédito ("paga ahora, a cuenta").
-- TRUE = cobro creado en la misma transacción de la venta desde el POS; FALSE = cobro posterior desde Cuentas por cobrar.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE cobros ADD COLUMN IF NOT EXISTS abono_inicial BOOLEAN NOT NULL DEFAULT FALSE;
