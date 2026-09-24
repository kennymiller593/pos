-- 003: medio de pago en los movimientos de caja (devoluciones por Yape/tarjeta no tocan el efectivo)
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE movimientos_caja
    ADD COLUMN IF NOT EXISTS medio_pago_codigo VARCHAR(20) NOT NULL DEFAULT 'efectivo' REFERENCES medios_pago(codigo);
ALTER TABLE movimientos_caja
    ADD COLUMN IF NOT EXISTS referencia VARCHAR(100);

CREATE INDEX IF NOT EXISTS idx_movimientos_caja_apertura_medio ON movimientos_caja (apertura_id, tipo, medio_pago_codigo);
