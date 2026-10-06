-- 020: la tienda en línea es un adicional de pago que activa la plataforma por empresa
-- Mientras tienda_habilitada sea falso, el dueño no ve la opción "Tienda en línea" y su tienda
-- no se muestra aunque la tuviera publicada (conserva su configuración para cuando se reactive).
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS tienda_habilitada BOOLEAN NOT NULL DEFAULT FALSE;
