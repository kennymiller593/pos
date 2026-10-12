-- 026: el asistente de IA también atiende a los clientes en la tienda en línea (chatbot público).
-- Las conversaciones de la tienda no tienen usuario: se identifican por un visitante anónimo (id del navegador).
-- Cada respuesta puede traer los productos recomendados (códigos) para mostrarlos como tarjetas.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE ia_conversaciones ALTER COLUMN usuario_id DROP NOT NULL;
ALTER TABLE ia_conversaciones ADD COLUMN IF NOT EXISTS canal VARCHAR(12) NOT NULL DEFAULT 'app';
ALTER TABLE ia_conversaciones ADD COLUMN IF NOT EXISTS visitante VARCHAR(64);
CREATE INDEX IF NOT EXISTS ia_conversaciones_visitante_idx ON ia_conversaciones (empresa_id, visitante);

ALTER TABLE ia_mensajes ADD COLUMN IF NOT EXISTS productos JSONB;
