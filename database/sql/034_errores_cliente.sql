-- 034: errores reportados desde el navegador. Lo que falla antes de llegar al servidor (un error de
-- JavaScript, un envío sin respuesta, una página de bloqueo) no deja rastro en ningún log: el navegador
-- lo avisa aquí (POST /errores-cliente) y la plataforma lo revisa en /admin/errores.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

CREATE TABLE IF NOT EXISTS errores_cliente (
    id UUID PRIMARY KEY DEFAULT uuid_v7(),
    empresa_id UUID REFERENCES empresas(id) ON DELETE CASCADE,
    usuario_id UUID REFERENCES usuarios(id) ON DELETE SET NULL,
    -- js: error de JavaScript · red: envío sin respuesta · respuesta: respuesta que no es del sistema (bloqueo, 419, 500)
    -- negocio: rechazo del sistema · validacion: formulario rechazado por datos faltantes (422)
    tipo VARCHAR(12) NOT NULL,
    pantalla VARCHAR(300),
    accion VARCHAR(300),
    mensaje VARCHAR(500) NOT NULL,
    detalle JSONB,
    navegador VARCHAR(300),
    ip VARCHAR(45),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE errores_cliente DROP CONSTRAINT IF EXISTS errores_cliente_tipo_check;
ALTER TABLE errores_cliente ADD CONSTRAINT errores_cliente_tipo_check
    CHECK (tipo IN ('js', 'red', 'respuesta', 'negocio', 'validacion'));

CREATE INDEX IF NOT EXISTS errores_cliente_creado_idx ON errores_cliente (creado_en DESC);
CREATE INDEX IF NOT EXISTS errores_cliente_empresa_idx ON errores_cliente (empresa_id, creado_en DESC);
