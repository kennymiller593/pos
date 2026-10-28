-- 033: mezclas y preparados. Insumos del stock que se combinan para obtener otro producto que se vende
-- (mezclas de fertilizantes, packs, kits). Módulo opcional por empresa (empresas.mezclas_activo).
-- No es venta ni compra: no va a SUNAT ni toca la caja. Los insumos salen a su costo FIFO real y el
-- producto obtenido entra con el costo calculado (insumos + otros costos) / cantidad obtenida.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS mezclas_activo BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS mezclas (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    sucursal_id UUID NOT NULL REFERENCES sucursales(id),
    usuario_id UUID REFERENCES usuarios(id) ON DELETE SET NULL,
    numero INTEGER NOT NULL,
    -- lo que se obtiene
    producto_id UUID NOT NULL REFERENCES productos(id),
    presentacion_id UUID REFERENCES producto_presentaciones(id) ON DELETE SET NULL,
    lote_id UUID REFERENCES lotes(id) ON DELETE SET NULL,
    capa_id UUID REFERENCES capas_costo(id) ON DELETE SET NULL,
    cantidad NUMERIC(12, 3) NOT NULL,        -- en la presentación elegida
    cantidad_base NUMERIC(12, 3) NOT NULL,   -- en unidad base: lo que entra al stock
    -- costo
    costo_insumos NUMERIC(14, 2) NOT NULL,
    otros_costos NUMERIC(14, 2) NOT NULL DEFAULT 0,
    costo_total NUMERIC(14, 2) NOT NULL,
    costo_unitario NUMERIC(14, 6) NOT NULL,  -- por unidad base del producto obtenido
    observacion VARCHAR(200),
    estado VARCHAR(12) NOT NULL DEFAULT 'registrada' CHECK (estado IN ('registrada', 'anulada')),
    anulada_en TIMESTAMPTZ,
    anulada_por UUID REFERENCES usuarios(id) ON DELETE SET NULL,
    motivo_anulacion VARCHAR(250),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (empresa_id, numero)
);

CREATE INDEX IF NOT EXISTS mezclas_empresa_idx ON mezclas (empresa_id, creado_en DESC);
CREATE INDEX IF NOT EXISTS mezclas_producto_idx ON mezclas (producto_id);

CREATE TABLE IF NOT EXISTS mezcla_insumos (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    mezcla_id UUID NOT NULL REFERENCES mezclas(id) ON DELETE CASCADE,
    producto_id UUID NOT NULL REFERENCES productos(id),
    presentacion_id UUID REFERENCES producto_presentaciones(id) ON DELETE SET NULL,
    cantidad NUMERIC(12, 3) NOT NULL,        -- en la presentación elegida
    cantidad_base NUMERIC(12, 3) NOT NULL,   -- en unidad base: lo que sale del stock
    costo_unitario NUMERIC(14, 6) NOT NULL,  -- por unidad base, FIFO real
    total NUMERIC(14, 2) NOT NULL
);

CREATE INDEX IF NOT EXISTS mezcla_insumos_mezcla_idx ON mezcla_insumos (mezcla_id);
CREATE INDEX IF NOT EXISTS mezcla_insumos_producto_idx ON mezcla_insumos (producto_id);

-- de qué capas de costo salió cada insumo, para devolverlas al anular
CREATE TABLE IF NOT EXISTS mezcla_consumos (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    insumo_id UUID NOT NULL REFERENCES mezcla_insumos(id) ON DELETE CASCADE,
    capa_id UUID NOT NULL REFERENCES capas_costo(id),
    cantidad NUMERIC(12, 3) NOT NULL,
    costo_unitario NUMERIC(14, 6) NOT NULL
);

CREATE INDEX IF NOT EXISTS mezcla_consumos_insumo_idx ON mezcla_consumos (insumo_id);

-- fórmula guardada por producto: los insumos que lleva una tanda de `rinde_base` unidades base del producto
CREATE TABLE IF NOT EXISTS recetas (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    producto_id UUID NOT NULL UNIQUE REFERENCES productos(id) ON DELETE CASCADE,
    rinde_base NUMERIC(12, 3) NOT NULL,
    otros_costos NUMERIC(14, 2) NOT NULL DEFAULT 0,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS receta_insumos (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    receta_id UUID NOT NULL REFERENCES recetas(id) ON DELETE CASCADE,
    producto_id UUID NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    presentacion_id UUID REFERENCES producto_presentaciones(id) ON DELETE SET NULL,
    cantidad_base NUMERIC(12, 4) NOT NULL,
    UNIQUE (receta_id, producto_id)
);

-- kardex: el insumo sale "usado en mezcla", el producto entra "obtenido de mezcla"; anular saca lo obtenido
ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_inventario_tipo_check;
ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_tipo_check
    CHECK (tipo IN ('venta', 'compra', 'ajuste', 'merma', 'transferencia_salida', 'transferencia_entrada', 'devolucion', 'compra_anulada',
                    'mezcla_salida', 'mezcla_entrada', 'mezcla_anulada'));
