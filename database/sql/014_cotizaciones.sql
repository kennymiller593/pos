-- 014: cotizaciones (proformas) y cuentas bancarias de la empresa
-- Una cotización es un documento interno: no va a SUNAT, no mueve stock ni caja y no cuenta
-- en el límite de comprobantes del plan. Al aceptarla el cliente se convierte en venta desde el POS.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- se imprimen al pie de la cotización para que el cliente sepa dónde pagar
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS cuentas_bancarias VARCHAR(500);

CREATE TABLE IF NOT EXISTS cotizaciones (
    id                 UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id         UUID NOT NULL REFERENCES empresas(id),
    sucursal_id        UUID NOT NULL REFERENCES sucursales(id),
    cliente_id         UUID REFERENCES clientes(id),
    usuario_id         UUID NOT NULL REFERENCES usuarios(id),
    numero             INTEGER NOT NULL,                  -- correlativo por empresa: COT-000001
    fecha_emision      DATE NOT NULL,
    valida_hasta       DATE NOT NULL,
    tiempo_entrega     VARCHAR(100),
    direccion_envio    VARCHAR(250),
    es_credito         BOOLEAN NOT NULL DEFAULT FALSE,    -- solo informativo: el cobro se define al vender
    observaciones      VARCHAR(1000),
    cliente_tipo_doc   CHAR(1),
    cliente_numero_doc VARCHAR(15),
    cliente_nombre     VARCHAR(200),
    cliente_direccion  VARCHAR(250),
    total_gravado      NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_exonerado    NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_inafecto     NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_igv          NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_descuentos   NUMERIC(12,2) NOT NULL DEFAULT 0,
    total              NUMERIC(12,2) NOT NULL,
    estado             VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    comprobante_id     UUID REFERENCES comprobantes(id),  -- la venta en que se convirtió
    anulada_en         TIMESTAMPTZ,
    anulada_por        UUID REFERENCES usuarios(id),
    creado_en          TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT cotizaciones_estado_check CHECK (estado IN ('pendiente', 'convertida', 'anulada')),
    CONSTRAINT cotizaciones_validez_check CHECK (valida_hasta >= fecha_emision)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_cotizaciones_numero ON cotizaciones (empresa_id, numero);
CREATE INDEX IF NOT EXISTS idx_cotizaciones_fecha ON cotizaciones (empresa_id, fecha_emision DESC);
CREATE INDEX IF NOT EXISTS idx_cotizaciones_cliente ON cotizaciones (cliente_id);

CREATE TABLE IF NOT EXISTS cotizacion_detalles (
    id                     UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id             UUID NOT NULL REFERENCES empresas(id),
    cotizacion_id          UUID NOT NULL REFERENCES cotizaciones(id) ON DELETE CASCADE,
    producto_id            UUID NOT NULL REFERENCES productos(id),
    presentacion_id        UUID REFERENCES producto_presentaciones(id),
    orden                  SMALLINT NOT NULL DEFAULT 0,
    descripcion            VARCHAR(250) NOT NULL,
    unidad_codigo          VARCHAR(5) NOT NULL,
    tipo_afectacion_codigo CHAR(2) NOT NULL,
    cantidad               NUMERIC(12,3) NOT NULL CHECK (cantidad > 0),
    valor_unitario         NUMERIC(14,6) NOT NULL,
    precio_unitario        NUMERIC(14,6) NOT NULL,
    descuento              NUMERIC(12,2) NOT NULL DEFAULT 0,
    igv                    NUMERIC(12,2) NOT NULL DEFAULT 0,
    total                  NUMERIC(12,2) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cotizacion_detalles_cotizacion ON cotizacion_detalles (cotizacion_id, orden);
