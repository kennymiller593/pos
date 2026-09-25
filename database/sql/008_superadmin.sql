-- 008: superadministrador de la plataforma (ve todas las empresas, gestiona planes y suscripciones)
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.
-- Asignar con: php artisan superadmin:asignar correo@dominio

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS es_superadmin BOOLEAN NOT NULL DEFAULT FALSE;
CREATE INDEX IF NOT EXISTS idx_usuarios_superadmin ON usuarios (es_superadmin) WHERE es_superadmin;
