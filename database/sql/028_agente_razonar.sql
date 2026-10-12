-- 028: cada agente de IA decide si usa el "razonamiento profundo" del modelo (piensa antes de responder:
-- mejor en casos difíciles, pero más lento y con más tokens). Apagado, el modelo responde directo.
-- Idempotente. La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'ia_agentes' AND column_name = 'razonar') THEN
        ALTER TABLE ia_agentes ADD COLUMN razonar BOOLEAN NOT NULL DEFAULT FALSE;
        -- solo al crear la columna: los agentes de asesoría técnica arrancan con el razonamiento encendido
        UPDATE ia_agentes SET razonar = TRUE WHERE rubro_codigo IN ('agro', 'botica');
    END IF;
END $$;
