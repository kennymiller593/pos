-- 010: planes públicos/privados y límites ilimitados
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- Público = se muestra en la landing y en Suscripción. Privado = solo lo asigna el superadmin (planes a medida).
ALTER TABLE planes ADD COLUMN IF NOT EXISTS publico BOOLEAN NOT NULL DEFAULT TRUE;

-- NULL = sin límite (el panel de planes ya ofrece "Ilimitado" y SuscripcionService lo respeta)
ALTER TABLE planes ALTER COLUMN max_sucursales DROP NOT NULL;
ALTER TABLE planes ALTER COLUMN max_usuarios DROP NOT NULL;
