-- 043: variantes. Un "modelo" (Polo básico) con 1 a 3 atributos propios (Talla, Color...) genera UN PRODUCTO
-- NORMAL por combinación ("Polo básico M Rojo"). Módulo opcional por empresa (empresas.variantes_activo).
-- El modelo vive en producto_grupos; cada variante es una fila de productos con grupo_id y sus valores en
-- productos.atributos ({"Talla":"M","Color":"Rojo"}). Ventas, compras, FIFO, kardex y SUNAT no cambian:
-- siguen operando por producto.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS variantes_activo BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS producto_grupos (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    nombre VARCHAR(160) NOT NULL,
    categoria_id UUID REFERENCES categorias(id) ON DELETE SET NULL,
    marca_id UUID REFERENCES marcas(id) ON DELETE SET NULL,
    descripcion TEXT,
    imagen_url TEXT,
    -- definición ordenada: [{"nombre":"Talla","valores":["S","M","L"]},{"nombre":"Color","valores":["Rojo","Azul"]}]
    atributos JSONB NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    eliminado_en TIMESTAMPTZ
);

-- combinaciones que el negocio decidió no tener (no existe el Polo L Azul): [["L","Azul"], ...]. Sin esto,
-- cada vez que se edita el modelo volverían a crearse solas.
ALTER TABLE producto_grupos ADD COLUMN IF NOT EXISTS omitidas JSONB NOT NULL DEFAULT '[]';

CREATE INDEX IF NOT EXISTS idx_producto_grupos_empresa ON producto_grupos (empresa_id) WHERE eliminado_en IS NULL;

ALTER TABLE productos ADD COLUMN IF NOT EXISTS grupo_id UUID REFERENCES producto_grupos(id) ON DELETE SET NULL;
-- posición de la combinación según el orden de los valores del modelo (S, M, L no salen alfabéticas)
ALTER TABLE productos ADD COLUMN IF NOT EXISTS variante_orden INTEGER;

CREATE INDEX IF NOT EXISTS idx_productos_grupo ON productos (grupo_id) WHERE grupo_id IS NOT NULL;
-- una sola variante viva por combinación dentro del modelo
CREATE UNIQUE INDEX IF NOT EXISTS uq_productos_variante ON productos (grupo_id, atributos) WHERE grupo_id IS NOT NULL AND eliminado_en IS NULL;

-- rubros que arrancan con el módulo encendido al registrarse
INSERT INTO rubros (codigo, nombre) VALUES
    ('ropa', 'Ropa, calzado y accesorios'),
    ('celulares', 'Celulares y electrónica')
ON CONFLICT (codigo) DO NOTHING;
