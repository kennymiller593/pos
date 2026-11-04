-- 042: el asistente de la tienda marca las respuestas en que deriva al cliente con una persona del negocio
-- (no tenía el dato: dosis fuera del catálogo, envíos, precios ocultos...). La tienda muestra debajo un botón
-- de WhatsApp con la pregunta del cliente ya escrita.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE ia_mensajes ADD COLUMN IF NOT EXISTS derivar BOOLEAN NOT NULL DEFAULT FALSE;
