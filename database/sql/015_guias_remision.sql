-- 015: guías de remisión electrónicas (GRE remitente, tipo 09)
-- La guía sustenta el traslado: no mueve stock ni caja (eso lo hacen la venta y la transferencia).
-- Se envía por la plataforma nueva de SUNAT (API REST), que pide un Client ID / Client Secret
-- generados en SUNAT Operaciones en Línea, además del certificado y la clave SOL.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

INSERT INTO tipos_comprobante (codigo, nombre, es_electronico)
VALUES ('09', 'Guia de remision remitente', true)
ON CONFLICT (codigo) DO NOTHING;

-- credenciales de la API de guías (el secret se guarda cifrado desde la aplicación)
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS gre_client_id VARCHAR(100);
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS gre_client_secret TEXT;

CREATE TABLE IF NOT EXISTS guias_remision (
    id                     UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id             UUID NOT NULL REFERENCES empresas(id),
    sucursal_id            UUID NOT NULL REFERENCES sucursales(id),
    usuario_id             UUID NOT NULL REFERENCES usuarios(id),
    serie                  VARCHAR(4) NOT NULL,
    correlativo            INTEGER NOT NULL,
    fecha_emision          DATE NOT NULL,
    hora_emision           TIME NOT NULL DEFAULT CURRENT_TIME,
    fecha_traslado         DATE NOT NULL,
    motivo_codigo          CHAR(2) NOT NULL,               -- catálogo 20: 01 venta, 04 entre establecimientos, 13 otros
    motivo_descripcion     VARCHAR(100),
    modalidad              CHAR(2) NOT NULL,               -- catálogo 18: 01 transporte público, 02 privado
    vehiculo_menor         BOOLEAN NOT NULL DEFAULT FALSE, -- categoría M1 o L: no exige placa ni conductor
    peso_bruto             NUMERIC(12,3) NOT NULL CHECK (peso_bruto > 0),
    bultos                 INTEGER,
    cliente_id             UUID REFERENCES clientes(id),
    destinatario_tipo_doc  CHAR(1) NOT NULL,
    destinatario_numero_doc VARCHAR(15) NOT NULL,
    destinatario_nombre    VARCHAR(200) NOT NULL,
    partida_ubigeo         CHAR(6) NOT NULL,
    partida_direccion      VARCHAR(250) NOT NULL,
    partida_cod_local      CHAR(4),
    llegada_ubigeo         CHAR(6) NOT NULL,
    llegada_direccion      VARCHAR(250) NOT NULL,
    llegada_cod_local      CHAR(4),
    transportista_ruc      CHAR(11),
    transportista_nombre   VARCHAR(200),
    transportista_mtc      VARCHAR(20),
    vehiculo_placa         VARCHAR(8),
    conductor_tipo_doc     CHAR(1),
    conductor_numero_doc   VARCHAR(15),
    conductor_nombres      VARCHAR(100),
    conductor_apellidos    VARCHAR(100),
    conductor_licencia     VARCHAR(12),
    comprobante_id         UUID REFERENCES comprobantes(id),     -- la venta que origina el traslado
    transferencia_id       UUID REFERENCES transferencias(id),   -- o el traslado entre sucursales
    observaciones          VARCHAR(250),
    estado                 VARCHAR(20) NOT NULL DEFAULT 'emitida',
    estado_sunat           VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    sunat_ticket           VARCHAR(60),
    sunat_mensaje          TEXT,
    hash_cpe               VARCHAR(100),
    qr_url                 TEXT,                           -- enlace de consulta que devuelve el CDR
    xml_url                TEXT,
    cdr_url                TEXT,
    intentos               SMALLINT NOT NULL DEFAULT 0,
    enviado_en             TIMESTAMPTZ,
    anulada_en             TIMESTAMPTZ,
    anulada_por            UUID REFERENCES usuarios(id),
    motivo_anulacion       VARCHAR(250),
    creado_en              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT guias_remision_estado_check CHECK (estado IN ('emitida', 'anulada')),
    CONSTRAINT guias_remision_estado_sunat_check CHECK (estado_sunat IN ('pendiente', 'en_proceso', 'aceptado', 'observado', 'rechazado')),
    CONSTRAINT guias_remision_modalidad_check CHECK (modalidad IN ('01', '02')),
    CONSTRAINT guias_remision_traslado_check CHECK (fecha_traslado >= fecha_emision)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_guias_remision_numero ON guias_remision (empresa_id, serie, correlativo);
CREATE INDEX IF NOT EXISTS idx_guias_remision_fecha ON guias_remision (empresa_id, fecha_emision DESC);
CREATE INDEX IF NOT EXISTS idx_guias_remision_comprobante ON guias_remision (comprobante_id);
CREATE INDEX IF NOT EXISTS idx_guias_remision_transferencia ON guias_remision (transferencia_id);
CREATE INDEX IF NOT EXISTS idx_guias_remision_sunat ON guias_remision (estado_sunat) WHERE estado_sunat IN ('pendiente', 'en_proceso');

CREATE TABLE IF NOT EXISTS guia_remision_detalles (
    id              UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id      UUID NOT NULL REFERENCES empresas(id),
    guia_id         UUID NOT NULL REFERENCES guias_remision(id) ON DELETE CASCADE,
    producto_id     UUID NOT NULL REFERENCES productos(id),
    presentacion_id UUID REFERENCES producto_presentaciones(id),
    orden           SMALLINT NOT NULL DEFAULT 0,
    codigo          VARCHAR(50) NOT NULL,
    descripcion     VARCHAR(250) NOT NULL,
    unidad_codigo   VARCHAR(5) NOT NULL,
    cantidad        NUMERIC(12,3) NOT NULL CHECK (cantidad > 0)
);

CREATE INDEX IF NOT EXISTS idx_guia_remision_detalles_guia ON guia_remision_detalles (guia_id, orden);
