-- 018: programa de puntos para clientes
-- Cada empresa decide si lo usa: cuántos soles de compra dan 1 punto y cuánto vale el punto al canjear.
-- El saldo vive en clientes.puntos y cada cambio queda en movimientos_puntos (ganado en una venta,
-- canjeado como descuento, devuelto por nota de crédito, revertido por anulación o ajuste manual).
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS puntos_activo BOOLEAN NOT NULL DEFAULT FALSE;
-- por cada tantos soles de compra el cliente gana 1 punto
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS puntos_soles_por_punto NUMERIC(10,2) NOT NULL DEFAULT 10;
-- lo que vale 1 punto, en soles, cuando se canjea como descuento
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS puntos_valor NUMERIC(10,2) NOT NULL DEFAULT 0.20;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS puntos_minimo_canje INTEGER NOT NULL DEFAULT 0;

ALTER TABLE clientes ADD COLUMN IF NOT EXISTS puntos INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS movimientos_puntos (
    id             UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id     UUID NOT NULL REFERENCES empresas(id),
    cliente_id     UUID NOT NULL REFERENCES clientes(id),
    comprobante_id UUID REFERENCES comprobantes(id),
    usuario_id     UUID REFERENCES usuarios(id),
    tipo           VARCHAR(20) NOT NULL,
    puntos         INTEGER NOT NULL,                 -- con signo: positivo suma, negativo resta
    saldo          INTEGER NOT NULL,                 -- saldo del cliente después del movimiento
    concepto       VARCHAR(200) NOT NULL,
    creado_en      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT movimientos_puntos_tipo_check CHECK (tipo IN ('ganado', 'canje', 'devolucion', 'anulacion', 'ajuste')),
    CONSTRAINT movimientos_puntos_puntos_check CHECK (puntos <> 0),
    CONSTRAINT movimientos_puntos_saldo_check CHECK (saldo >= 0)
);

CREATE INDEX IF NOT EXISTS idx_movimientos_puntos_cliente ON movimientos_puntos (cliente_id, creado_en DESC);
CREATE INDEX IF NOT EXISTS idx_movimientos_puntos_comprobante ON movimientos_puntos (comprobante_id);
