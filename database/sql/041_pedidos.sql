-- 041: pedidos (encargos). El cliente encarga productos para una fecha de entrega, deja adelantos y el negocio
-- lo alista y lo entrega cobrando el saldo desde el POS (la venta nace ahí: NV/boleta/factura).
-- No reserva ni mueve stock. El adelanto entra a la caja abierta como ingreso (movimientos_caja) el día que lo
-- dejan y NO genera comprobante; en la venta final figura como pago con el medio interno 'adelanto' SIN apertura
-- (no vuelve a entrar a caja). Los permisos pedidos.* viven en App\Support\Permisos.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- medio interno: solo lo escribe el sistema al entregar un pedido; las pantallas no lo ofrecen (MedioPago::INTERNOS)
INSERT INTO medios_pago (codigo, nombre, requiere_referencia)
VALUES ('adelanto', 'Adelanto de pedido', FALSE)
ON CONFLICT (codigo) DO NOTHING;

CREATE TABLE IF NOT EXISTS pedidos (
    id                       UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id               UUID NOT NULL REFERENCES empresas(id),
    sucursal_id              UUID NOT NULL REFERENCES sucursales(id),
    cliente_id               UUID NOT NULL REFERENCES clientes(id),   -- un encargo siempre tiene quien lo recoge
    usuario_id               UUID NOT NULL REFERENCES usuarios(id),
    numero                   INTEGER NOT NULL,                        -- correlativo por empresa: PED-000001
    fecha_pedido             DATE NOT NULL,
    fecha_entrega            DATE NOT NULL,
    hora_entrega             TIME,
    observaciones            VARCHAR(1000),
    cliente_tipo_doc         CHAR(1),
    cliente_numero_doc       VARCHAR(15),
    cliente_nombre           VARCHAR(200) NOT NULL,
    cliente_telefono         VARCHAR(20),                             -- copia al crear: el WhatsApp sale de aquí
    cliente_direccion        VARCHAR(250),
    total_gravado            NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_exonerado          NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_inafecto           NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_igv                NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_descuentos         NUMERIC(12,2) NOT NULL DEFAULT 0,
    total                    NUMERIC(12,2) NOT NULL,
    estado                   VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    listo_en                 TIMESTAMPTZ,
    listo_por                UUID REFERENCES usuarios(id),
    comprobante_id           UUID REFERENCES comprobantes(id),        -- la venta con que se entregó
    entregado_en             TIMESTAMPTZ,
    anulado_en               TIMESTAMPTZ,
    anulado_por              UUID REFERENCES usuarios(id),
    motivo_anulacion         VARCHAR(250),
    -- al anular con adelantos: lo devuelto (egreso de caja). NULL = no se devolvió
    devolucion_monto         NUMERIC(12,2),
    devolucion_movimiento_id UUID REFERENCES movimientos_caja(id),
    creado_en                TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en           TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT pedidos_estado_check CHECK (estado IN ('pendiente', 'listo', 'entregado', 'anulado')),
    CONSTRAINT pedidos_entrega_check CHECK (fecha_entrega >= fecha_pedido)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_pedidos_numero ON pedidos (empresa_id, numero);
CREATE INDEX IF NOT EXISTS idx_pedidos_entrega ON pedidos (empresa_id, estado, fecha_entrega);
CREATE INDEX IF NOT EXISTS idx_pedidos_cliente ON pedidos (cliente_id);
CREATE INDEX IF NOT EXISTS idx_pedidos_comprobante ON pedidos (comprobante_id);

CREATE TABLE IF NOT EXISTS pedido_detalles (
    id                     UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id             UUID NOT NULL REFERENCES empresas(id),
    pedido_id              UUID NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    producto_id            UUID NOT NULL REFERENCES productos(id),
    presentacion_id        UUID REFERENCES producto_presentaciones(id),
    orden                  SMALLINT NOT NULL DEFAULT 0,
    descripcion            VARCHAR(250) NOT NULL,
    unidad_codigo          VARCHAR(5) NOT NULL,
    tipo_afectacion_codigo CHAR(2) NOT NULL,
    cantidad               NUMERIC(12,3) NOT NULL CHECK (cantidad > 0),
    valor_unitario         NUMERIC(14,6) NOT NULL,
    precio_unitario        NUMERIC(14,6) NOT NULL,                  -- precio fijado al pedir: se respeta al entregar
    descuento              NUMERIC(12,2) NOT NULL DEFAULT 0,
    igv                    NUMERIC(12,2) NOT NULL DEFAULT 0,
    total                  NUMERIC(12,2) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pedido_detalles_pedido ON pedido_detalles (pedido_id, orden);

-- cada adelanto es un ingreso real de la caja abierta de quien lo recibió (el movimiento es la verdad del dinero)
CREATE TABLE IF NOT EXISTS pedido_adelantos (
    id                 UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id         UUID NOT NULL REFERENCES empresas(id),
    pedido_id          UUID NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    movimiento_caja_id UUID NOT NULL REFERENCES movimientos_caja(id),
    apertura_id        UUID NOT NULL REFERENCES aperturas_caja(id),
    usuario_id         UUID NOT NULL REFERENCES usuarios(id),
    medio_pago_codigo  VARCHAR(20) NOT NULL REFERENCES medios_pago(codigo),
    monto              NUMERIC(12,2) NOT NULL CHECK (monto > 0),
    referencia         VARCHAR(100),
    creado_en          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pedido_adelantos_pedido ON pedido_adelantos (pedido_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_pedido_adelantos_movimiento ON pedido_adelantos (movimiento_caja_id);
