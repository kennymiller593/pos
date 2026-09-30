-- 013: unidades de medida y rubros gestionables desde el panel de la plataforma
-- descripcion_sunat: nombre oficial del catálogo 03 (se muestra en mayúsculas junto al código: "NIU · UNIDAD (BIENES)")
-- activo: una unidad/rubro desactivado deja de ofrecerse, pero lo ya registrado sigue funcionando.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

ALTER TABLE unidades_medida ADD COLUMN IF NOT EXISTS descripcion_sunat VARCHAR(80);
ALTER TABLE unidades_medida ADD COLUMN IF NOT EXISTS activo BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE rubros ADD COLUMN IF NOT EXISTS activo BOOLEAN NOT NULL DEFAULT TRUE;

UPDATE unidades_medida SET descripcion_sunat = CASE codigo
    WHEN 'NIU' THEN 'UNIDAD (BIENES)'
    WHEN 'ZZ'  THEN 'UNIDAD (SERVICIOS)'
    WHEN 'BO'  THEN 'BOTELLAS'
    WHEN 'TNE' THEN 'TONELADAS'
    WHEN 'INH' THEN 'PULGADAS'
    WHEN 'FOT' THEN 'PIES'
    WHEN 'CEN' THEN 'CIENTO DE UNIDADES'
    WHEN 'MIL' THEN 'MILLARES'
    WHEN 'CA'  THEN 'LATAS'
    WHEN 'BLL' THEN 'BARRILES'
    WHEN 'TU'  THEN 'TUBOS'
    ELSE UPPER(translate(nombre, 'áéíóúÁÉÍÓÚ', 'aeiouAEIOU'))
END
WHERE descripcion_sunat IS NULL;
