-- 004: planes con código y suscripciones de prueba
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE planes ADD COLUMN IF NOT EXISTS codigo VARCHAR(30);
ALTER TABLE planes ADD COLUMN IF NOT EXISTS descripcion VARCHAR(250);
ALTER TABLE planes ADD COLUMN IF NOT EXISTS activo BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE planes ADD COLUMN IF NOT EXISTS orden SMALLINT NOT NULL DEFAULT 0;
CREATE UNIQUE INDEX IF NOT EXISTS uq_planes_codigo ON planes (codigo);

ALTER TABLE suscripciones ADD COLUMN IF NOT EXISTS es_prueba BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE suscripciones ADD COLUMN IF NOT EXISTS creado_en TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE suscripciones ADD COLUMN IF NOT EXISTS nota VARCHAR(250);
CREATE INDEX IF NOT EXISTS idx_suscripciones_empresa_fin ON suscripciones (empresa_id, fecha_fin DESC);

-- Catálogo de planes (max_* NULL = sin límite). Los precios se ajustan desde aquí.
INSERT INTO planes (codigo, nombre, descripcion, precio_mensual, max_sucursales, max_usuarios, max_comprobantes_mes, orden)
VALUES
    ('prueba',       'Prueba gratuita', '14 días con todo habilitado para conocer el sistema',           0,   2,  5,   300,  0),
    ('emprendedor',  'Emprendedor',     'Para una tienda con hasta 3 usuarios',                         49,   1,  3,   500,  1),
    ('negocio',      'Negocio',         'Hasta 3 sucursales y 10 usuarios, comprobantes ilimitados',    99,   3, 10,  NULL,  2),
    ('empresa',      'Empresa',         'Hasta 10 sucursales y 30 usuarios, comprobantes ilimitados',  199,  10, 30,  NULL,  3)
ON CONFLICT (codigo) DO UPDATE SET
    nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    max_sucursales = EXCLUDED.max_sucursales,
    max_usuarios = EXCLUDED.max_usuarios,
    max_comprobantes_mes = EXCLUDED.max_comprobantes_mes,
    orden = EXCLUDED.orden;

-- Empresas existentes sin suscripción: reciben 30 días de prueba para no quedar bloqueadas al desplegar
INSERT INTO suscripciones (empresa_id, plan_id, fecha_inicio, fecha_fin, estado, es_prueba, nota)
SELECT e.id, (SELECT id FROM planes WHERE codigo = 'prueba'), CURRENT_DATE, CURRENT_DATE + 30, 'activa', TRUE, 'Prueba asignada al desplegar suscripciones'
  FROM empresas e
 WHERE NOT EXISTS (SELECT 1 FROM suscripciones s WHERE s.empresa_id = e.id);
