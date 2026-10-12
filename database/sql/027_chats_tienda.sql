-- 027: el dueño ve los chats de la tienda y puede tomar la conversación (el bot deja de responder
-- hasta que la devuelve). Los mensajes del dueño llevan su usuario.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE ia_conversaciones ADD COLUMN IF NOT EXISTS modo VARCHAR(10) NOT NULL DEFAULT 'bot';
ALTER TABLE ia_conversaciones ADD COLUMN IF NOT EXISTS atendida_por UUID REFERENCES usuarios(id) ON DELETE SET NULL;
ALTER TABLE ia_conversaciones ADD COLUMN IF NOT EXISTS leida_en TIMESTAMPTZ;
ALTER TABLE ia_mensajes ADD COLUMN IF NOT EXISTS usuario_id UUID REFERENCES usuarios(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS ia_conversaciones_canal_idx ON ia_conversaciones (empresa_id, canal, actualizado_en DESC);
