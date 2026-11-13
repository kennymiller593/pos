-- 044: series / IMEI. Un producto puede "controlar serie": cada unidad se registra con su número (IMEI o serie)
-- al comprar o al ajustar stock, se elige al vender y queda el rastro de a quién se vendió.
-- Módulo opcional por empresa (empresas.series_activo). La serie es una capa ENCIMA del inventario: stock,
-- capas_costo y el FIFO no cambian (el costo de la venta sigue siendo el FIFO del producto).
-- Invariante: en un producto con controla_serie, las series 'disponible' de una sucursal = stock.cantidad.
-- El permiso series.ver vive en App\Support\Permisos (matriz por rol en código: no necesita datos aquí).
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS series_activo BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS controla_serie BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS producto_series (
    id                     UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id             UUID NOT NULL REFERENCES empresas(id),
    producto_id            UUID NOT NULL REFERENCES productos(id),
    sucursal_id            UUID NOT NULL REFERENCES sucursales(id),   -- dónde está (o dónde estuvo por última vez)
    numero_serie           VARCHAR(60) NOT NULL,                      -- en MAYÚSCULAS y sin espacios
    serie_alterna          VARCHAR(60),                               -- segundo IMEI (equipos doble SIM)
    estado                 VARCHAR(12) NOT NULL DEFAULT 'disponible',
    costo_unitario         NUMERIC(14,6),                             -- informativo: el costo real de la venta es el FIFO
    compra_detalle_id      UUID,                                      -- la compra con que entró (si entró por compra)
    comprobante_detalle_id UUID,                                      -- la venta vigente (NULL si no está vendida)
    creado_en              TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT producto_series_estado_check CHECK (estado IN ('disponible', 'vendida', 'en_transito', 'baja')),
    CONSTRAINT uq_producto_series_numero UNIQUE (empresa_id, producto_id, numero_serie)
);

CREATE INDEX IF NOT EXISTS idx_producto_series_numero ON producto_series (empresa_id, numero_serie);
CREATE INDEX IF NOT EXISTS idx_producto_series_alterna ON producto_series (empresa_id, serie_alterna);
CREATE INDEX IF NOT EXISTS idx_producto_series_disponibles ON producto_series (producto_id, sucursal_id) WHERE estado = 'disponible';
CREATE INDEX IF NOT EXISTS idx_producto_series_venta ON producto_series (comprobante_detalle_id) WHERE comprobante_detalle_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_producto_series_compra ON producto_series (compra_detalle_id) WHERE compra_detalle_id IS NOT NULL;

-- línea de tiempo de cada unidad: entró, se vendió, volvió, se dio de baja...
CREATE TABLE IF NOT EXISTS serie_movimientos (
    id                     UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id             UUID NOT NULL REFERENCES empresas(id),
    serie_id               UUID NOT NULL REFERENCES producto_series(id) ON DELETE CASCADE,
    sucursal_id            UUID NOT NULL REFERENCES sucursales(id),
    usuario_id             UUID REFERENCES usuarios(id),
    tipo                   VARCHAR(24) NOT NULL,
    referencia_id          UUID,                                      -- compra, comprobante, nota de crédito...
    comprobante_detalle_id UUID,                                      -- la línea del comprobante (venta o nota) donde figura
    creado_en              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT serie_movimientos_tipo_check CHECK (tipo IN (
        'compra', 'ajuste_entrada', 'regularizacion', 'venta', 'venta_anulada', 'devolucion',
        'transferencia_salida', 'transferencia_entrada', 'transferencia_anulada', 'baja', 'compra_anulada'
    ))
);

CREATE INDEX IF NOT EXISTS idx_serie_movimientos_serie ON serie_movimientos (serie_id, creado_en);
CREATE INDEX IF NOT EXISTS idx_serie_movimientos_detalle ON serie_movimientos (comprobante_detalle_id);
CREATE INDEX IF NOT EXISTS idx_serie_movimientos_referencia ON serie_movimientos (referencia_id);
