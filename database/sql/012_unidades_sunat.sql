-- 012: unidades de medida más usadas del catálogo 03 de SUNAT (para todos los rubros)
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos (ya está en catalogos.sql).

INSERT INTO unidades_medida (codigo, nombre, permite_decimales) VALUES
    ('GRM', 'Gramo',            true),
    ('MLT', 'Mililitro',        true),
    ('TNE', 'Tonelada',         true),
    ('CMT', 'Centímetro',       true),
    ('INH', 'Pulgada',          true),
    ('FOT', 'Pie',              true),
    ('MTK', 'Metro cuadrado',   true),
    ('MTQ', 'Metro cúbico',     true),
    ('PR',  'Par',              false),
    ('CEN', 'Ciento',           false),
    ('MIL', 'Millar',           false),
    ('BJ',  'Balde',            false),
    ('CA',  'Lata',             false),
    ('BLL', 'Barril',           false),
    ('CY',  'Cilindro',         false),
    ('TU',  'Tubo',             false),
    ('RO',  'Rollo',            false),
    ('EV',  'Sobre',            false),
    ('BE',  'Fardo',            false),
    ('SET', 'Juego',            false),
    ('KT',  'Kit',              false),
    ('ZZ',  'Servicio',         false),
    ('HUR', 'Hora',             true),
    ('DAY', 'Día',              false)
ON CONFLICT (codigo) DO NOTHING;
