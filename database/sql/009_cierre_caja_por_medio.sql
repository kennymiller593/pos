-- 009: cierre de caja por medio de pago (efectivo, Yape, tarjeta...) y conteo por denominación
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- Lo que el sistema esperaba y lo que el cajero declaró, por cada medio, al cerrar un turno
CREATE TABLE IF NOT EXISTS cierres_caja_medios (
    id                UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id        UUID NOT NULL REFERENCES empresas(id),
    apertura_id       UUID NOT NULL REFERENCES aperturas_caja(id),
    medio_pago_codigo VARCHAR(20) NOT NULL REFERENCES medios_pago(codigo),
    esperado          NUMERIC(12,2) NOT NULL,
    declarado         NUMERIC(12,2),          -- NULL = el cajero no lo verificó (se asume igual al esperado)
    diferencia        NUMERIC(12,2) NOT NULL DEFAULT 0,
    creado_en         TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (apertura_id, medio_pago_codigo)
);
CREATE INDEX IF NOT EXISTS idx_cierres_caja_medios_empresa ON cierres_caja_medios (empresa_id, creado_en);

-- Conteo del efectivo por billete/moneda (opcional): {"200": 1, "100": 3, "0.50": 4}
ALTER TABLE aperturas_caja ADD COLUMN IF NOT EXISTS conteo_efectivo JSONB;
