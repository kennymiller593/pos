-- 024: QR de cobro de las billeteras (Yape, Plin) que el POS muestra al cliente al momento de pagar.
-- Se guarda por empresa y, si un local cobra a otra cuenta, también por sucursal (la sucursal manda).
-- Forma: {"yape": {"qr_url": "...", "numero": "987654321", "titular": "..."}, "plin": {...}}
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS cobros_qr JSONB;
ALTER TABLE sucursales ADD COLUMN IF NOT EXISTS cobros_qr JSONB;
