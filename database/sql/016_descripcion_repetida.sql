-- 016: descripciones "Producto (Producto)" en los detalles ya emitidos
-- Cuando la presentación se llamaba igual que el producto, la descripción repetía el nombre.
-- Desde ahora no se genera así; aquí se limpian las existentes (solo el texto que se imprime;
-- los XML ya enviados a SUNAT no cambian). Idempotente.
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

UPDATE comprobante_detalles
   SET descripcion = regexp_replace(descripcion, '^(.+) \((\1)\)$', '\1')
 WHERE descripcion ~ '^(.+) \(\1\)$';

UPDATE cotizacion_detalles
   SET descripcion = regexp_replace(descripcion, '^(.+) \((\1)\)$', '\1')
 WHERE descripcion ~ '^(.+) \(\1\)$';

UPDATE guia_remision_detalles
   SET descripcion = regexp_replace(descripcion, '^(.+) \((\1)\)$', '\1')
 WHERE descripcion ~ '^(.+) \(\1\)$';
