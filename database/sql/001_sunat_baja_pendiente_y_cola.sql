-- 001: estado intermedio de baja en SUNAT + tablas de cola de Laravel
-- La BD se gestiona fuera de Laravel: espejar este script en los scripts externos.

-- Un comprobante cuya baja fue recibida por SUNAT pero aún no confirmada
-- queda en 'baja_pendiente'; la anulación interna se completa al confirmar.
ALTER TABLE comprobantes_sunat DROP CONSTRAINT IF EXISTS comprobantes_sunat_estado_check;
ALTER TABLE comprobantes_sunat ADD CONSTRAINT comprobantes_sunat_estado_check
    CHECK (estado IN ('pendiente', 'aceptado', 'rechazado', 'observado', 'baja_pendiente', 'baja'));

-- Para que el comando programado encuentre rápido los pendientes de reenvío
CREATE INDEX IF NOT EXISTS idx_comprobantes_sunat_estado ON comprobantes_sunat (estado);

-- Datos previos: bajas enviadas con el flujo antiguo (anulado con ticket sin confirmar)
UPDATE comprobantes_sunat s
   SET estado = 'baja_pendiente'
  FROM comprobantes c
 WHERE c.id = s.comprobante_id
   AND c.estado = 'anulado'
   AND s.ticket IS NOT NULL
   AND s.estado IN ('aceptado', 'observado');

-- Cola de trabajos de Laravel (QUEUE_CONNECTION=database)
CREATE TABLE IF NOT EXISTS jobs (
    id           BIGSERIAL PRIMARY KEY,
    queue        VARCHAR(255) NOT NULL,
    payload      TEXT NOT NULL,
    attempts     SMALLINT NOT NULL,
    reserved_at  INTEGER,
    available_at INTEGER NOT NULL,
    created_at   INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS jobs_queue_index ON jobs (queue);

CREATE TABLE IF NOT EXISTS job_batches (
    id             VARCHAR(255) PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    total_jobs     INTEGER NOT NULL,
    pending_jobs   INTEGER NOT NULL,
    failed_jobs    INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options        TEXT,
    cancelled_at   INTEGER,
    created_at     INTEGER NOT NULL,
    finished_at    INTEGER
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id         BIGSERIAL PRIMARY KEY,
    uuid       VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue      TEXT NOT NULL,
    payload    TEXT NOT NULL,
    exception  TEXT NOT NULL,
    failed_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
