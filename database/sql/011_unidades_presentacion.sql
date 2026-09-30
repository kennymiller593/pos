-- 011: más unidades para nombrar presentaciones sin escribir ("Paquete x6", "Docena", "Bolsa x10")
-- Códigos del catálogo 03 de SUNAT (van en el XML de la boleta/factura).
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos (ya está en catalogos.sql).

INSERT INTO unidades_medida (codigo, nombre, permite_decimales) VALUES
    ('PK',  'Paquete', false),
    ('DZN', 'Docena',  false),
    ('BG',  'Bolsa',   false),
    ('BO',  'Botella', false)
ON CONFLICT (codigo) DO NOTHING;
