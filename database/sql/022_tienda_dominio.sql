-- 022: dominio propio para la tienda en línea (www.agrocampo.com en vez de agrocampo.inkanet.pro)
-- Es un adicional aparte que activa la plataforma (tienda_dominio_habilitado). El dominio pasa por
-- estados: pendiente (falta el CNAME) -> verificando (Cloudflare emite el certificado) -> activo | error.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_dominio_habilitado BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_dominio VARCHAR(253);
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_dominio_estado VARCHAR(20);
-- id del "custom hostname" en Cloudflare, para consultarlo y borrarlo
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_dominio_externo_id VARCHAR(64);
-- qué falta o qué falló, en palabras para el dueño
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_dominio_detalle TEXT;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_dominio_activado_en TIMESTAMPTZ;

-- un dominio pertenece a una sola empresa
CREATE UNIQUE INDEX IF NOT EXISTS empresas_tienda_dominio_unico ON empresas (tienda_dominio) WHERE tienda_dominio IS NOT NULL;
