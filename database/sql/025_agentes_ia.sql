-- 025: agentes de inteligencia artificial por rubro (asistente agrónomo, químico farmacéutico, etc.).
-- La plataforma (superadmin) configura los proveedores (claves cifradas por la app), los precios por modelo
-- y los agentes con su cadena de modelos de respaldo; luego activa cada agente por empresa como adicional de pago.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- Proveedores: una fila por servicio. La clave la cifra la app (cast encrypted), aquí solo es texto.
CREATE TABLE IF NOT EXISTS ia_proveedores (
    codigo            VARCHAR(30) PRIMARY KEY,
    nombre            VARCHAR(60) NOT NULL,
    driver            VARCHAR(30) NOT NULL,
    api_key           TEXT,
    url               TEXT,
    activo            BOOLEAN NOT NULL DEFAULT FALSE,
    ultimo_ok_en      TIMESTAMPTZ,
    ultimo_error      TEXT,
    ultimo_error_en   TIMESTAMPTZ,
    fallos_seguidos   INTEGER NOT NULL DEFAULT 0,
    -- "fusible": tras varios fallos seguidos el proveedor se salta hasta esta hora
    pausado_hasta     TIMESTAMPTZ,
    orden             SMALLINT NOT NULL DEFAULT 0,
    actualizado_en    TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO ia_proveedores (codigo, nombre, driver, orden) VALUES
    ('deepseek',   'DeepSeek',   'deepseek',   1),
    ('openrouter', 'OpenRouter', 'openrouter', 2),
    ('anthropic',  'Anthropic',  'anthropic',  3),
    ('openai',     'OpenAI',     'openai',     4)
ON CONFLICT (codigo) DO NOTHING;

-- Precios por modelo (dólares por millón de tokens) para calcular el costo de cada consulta. Editables.
CREATE TABLE IF NOT EXISTS ia_modelos (
    id                UUID PRIMARY KEY,
    proveedor_codigo  VARCHAR(30) NOT NULL REFERENCES ia_proveedores(codigo) ON DELETE CASCADE,
    modelo            VARCHAR(120) NOT NULL,
    precio_entrada    NUMERIC(10,4) NOT NULL DEFAULT 0,
    precio_salida     NUMERIC(10,4) NOT NULL DEFAULT 0,
    nota              VARCHAR(160),
    actualizado_en    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (proveedor_codigo, modelo)
);

-- Agentes: instrucciones del rubro y cadena de modelos [{"proveedor": "deepseek", "modelo": "deepseek-v4-pro"}, ...]
CREATE TABLE IF NOT EXISTS ia_agentes (
    id                UUID PRIMARY KEY,
    codigo            VARCHAR(40) NOT NULL UNIQUE,
    nombre            VARCHAR(80) NOT NULL,
    rubro_codigo      VARCHAR(20) REFERENCES rubros(codigo),
    descripcion       VARCHAR(200),
    instrucciones     TEXT NOT NULL,
    cadena            JSONB NOT NULL DEFAULT '[]'::jsonb,
    limite_mensual    INTEGER NOT NULL DEFAULT 1000,
    max_pasos         SMALLINT NOT NULL DEFAULT 6,
    sugerencias       JSONB,
    activo            BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en         TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Activación por empresa (adicional de pago). limite_mensual anula el del agente si se indica.
CREATE TABLE IF NOT EXISTS ia_agentes_empresa (
    id                UUID PRIMARY KEY,
    empresa_id        UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    agente_id         UUID NOT NULL REFERENCES ia_agentes(id) ON DELETE CASCADE,
    activo            BOOLEAN NOT NULL DEFAULT TRUE,
    limite_mensual    INTEGER,
    activado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    desactivado_en    TIMESTAMPTZ,
    UNIQUE (empresa_id, agente_id)
);

-- Conversaciones del asistente dentro de la app
CREATE TABLE IF NOT EXISTS ia_conversaciones (
    id                UUID PRIMARY KEY,
    empresa_id        UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    usuario_id        UUID NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    agente_id         UUID NOT NULL REFERENCES ia_agentes(id) ON DELETE CASCADE,
    titulo            VARCHAR(120) NOT NULL DEFAULT 'Nueva conversación',
    creado_en         TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ia_conversaciones_usuario_idx ON ia_conversaciones (usuario_id, actualizado_en DESC);

-- Mensajes: cada respuesta del asistente guarda qué proveedor/modelo respondió, tokens y costo (para el tope mensual y el reporte)
CREATE TABLE IF NOT EXISTS ia_mensajes (
    id                UUID PRIMARY KEY,
    conversacion_id   UUID NOT NULL REFERENCES ia_conversaciones(id) ON DELETE CASCADE,
    empresa_id        UUID NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    rol               VARCHAR(12) NOT NULL,
    contenido         TEXT NOT NULL,
    proveedor_codigo  VARCHAR(30),
    modelo            VARCHAR(120),
    tokens_entrada    INTEGER,
    tokens_salida     INTEGER,
    costo_usd         NUMERIC(12,6),
    duracion_ms       INTEGER,
    herramientas      JSONB,
    intentos          JSONB,
    creado_en         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ia_mensajes_conversacion_idx ON ia_mensajes (conversacion_id, creado_en);
CREATE INDEX IF NOT EXISTS ia_mensajes_empresa_mes_idx ON ia_mensajes (empresa_id, rol, creado_en);

-- Precios de partida (US$ por millón de tokens, fuera de hora pico donde aplica; vigentes a 2026-10). Editables desde el superadmin.
INSERT INTO ia_modelos (id, proveedor_codigo, modelo, precio_entrada, precio_salida, nota) VALUES
    (gen_random_uuid(), 'deepseek',   'deepseek-v4-pro',           0.66, 1.98, 'Tarifa fuera de hora pico; en hora pico se duplica'),
    (gen_random_uuid(), 'deepseek',   'deepseek-flash',            0.15, 0.60, 'Tarifa fuera de hora pico; en hora pico se duplica'),
    (gen_random_uuid(), 'openrouter', 'deepseek/deepseek-v4-pro',  0.70, 2.10, 'Incluye la comisión aproximada de OpenRouter'),
    (gen_random_uuid(), 'anthropic',  'claude-haiku-5-5',          0.10, 0.50, NULL),
    (gen_random_uuid(), 'anthropic',  'claude-sonnet-5-5',         2.00, 10.00, NULL),
    (gen_random_uuid(), 'anthropic',  'claude-opus-5-5',           4.00, 20.00, NULL)
ON CONFLICT (proveedor_codigo, modelo) DO NOTHING;
