-- 032: Libro de Reclamaciones virtual de la tienda en línea (Código de Protección al Consumidor, Ley 29571,
-- y su reglamento). Cada hoja lleva un número correlativo por empresa; el proveedor responde en el panel.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

CREATE TABLE IF NOT EXISTS reclamaciones (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    numero INTEGER NOT NULL,
    tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('reclamo', 'queja')),
    -- consumidor
    nombre VARCHAR(150) NOT NULL,
    documento_tipo VARCHAR(10) NOT NULL,
    documento_numero VARCHAR(20) NOT NULL,
    domicilio VARCHAR(250) NOT NULL,
    telefono VARCHAR(30),
    email VARCHAR(150) NOT NULL,
    menor_de_edad BOOLEAN NOT NULL DEFAULT FALSE,
    apoderado VARCHAR(150),
    -- bien contratado
    bien_tipo VARCHAR(10) NOT NULL CHECK (bien_tipo IN ('producto', 'servicio')),
    monto NUMERIC(12, 2),
    bien_descripcion VARCHAR(500) NOT NULL,
    -- detalle
    detalle TEXT NOT NULL,
    pedido TEXT NOT NULL,
    -- respuesta del proveedor
    estado VARCHAR(12) NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'respondido')),
    respuesta TEXT,
    respondido_en TIMESTAMPTZ,
    respondido_por UUID REFERENCES usuarios(id) ON DELETE SET NULL,
    ip VARCHAR(45),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (empresa_id, numero)
);

CREATE INDEX IF NOT EXISTS reclamaciones_empresa_idx ON reclamaciones (empresa_id, creado_en DESC);
