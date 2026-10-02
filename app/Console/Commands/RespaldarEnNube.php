<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Copia fuera del servidor lo que no se puede reconstruir: la base de datos y los archivos
 * (XML/CDR de SUNAT, fotos de productos, logos). SUNAT exige conservar los comprobantes
 * electrónicos, y el backup local de deploy/backup.sh se pierde si falla el disco del VPS.
 *
 * Destino: el disco "respaldo" (Cloudflare R2 u otro compatible con S3). Sin las variables
 * RESPALDO_* el comando no hace nada, así que programarlo es inofensivo.
 * El .env no se sube: guarda una copia de APP_KEY aparte (sin ella no se descifran los certificados).
 */
class RespaldarEnNube extends Command
{
    protected $signature = 'respaldo:nube
        {--sin-base : Solo archivos, sin volcado de la base de datos}
        {--conservar=14 : Cuántos volcados de la base se guardan en la nube}';

    protected $description = 'Respalda la base de datos y los archivos (XML/CDR, fotos, logos) en almacenamiento externo';

    /** Carpetas locales que se copian y con qué prefijo quedan en la nube. */
    private const CARPETAS = [
        'archivos/privado' => 'app/private',
        'archivos/publico' => 'app/public',
    ];

    public function handle(): int
    {
        if (blank(config('filesystems.disks.respaldo.bucket')) || blank(config('filesystems.disks.respaldo.key'))) {
            $this->info('Respaldo en la nube sin configurar (faltan las variables RESPALDO_* en .env): no se hizo nada.');

            return self::SUCCESS;
        }

        $nube = Storage::disk('respaldo');

        try {
            $subidos = 0;
            foreach (self::CARPETAS as $prefijo => $carpeta) {
                $subidos += $this->sincronizar($nube, storage_path($carpeta), $prefijo);
            }
            $this->info("Archivos: {$subidos} nuevos o modificados subidos.");

            if (! $this->option('sin-base')) {
                $this->respaldarBase($nube, max(1, (int) $this->option('conservar')));
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error('El respaldo en la nube falló: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Sube lo que falta o cambió de tamaño. Nunca borra en la nube: un archivo eliminado
     * (o dañado) en el servidor no debe llevarse también su copia.
     */
    private function sincronizar(Filesystem $nube, string $carpeta, string $prefijo): int
    {
        if (! is_dir($carpeta)) {
            return 0;
        }

        // un solo listado remoto por carpeta, en vez de preguntar archivo por archivo
        $remotos = [];
        foreach ($nube->listContents($prefijo, true) as $item) {
            if ($item->isFile()) {
                $remotos[$item->path()] = $item->fileSize();
            }
        }

        $subidos = 0;

        foreach (File::allFiles($carpeta) as $archivo) {
            $relativa = str_replace('\\', '/', $archivo->getRelativePathname());

            if (str_starts_with(basename($relativa), '.')) {
                continue; // .gitignore y similares
            }

            $destino = "{$prefijo}/{$relativa}";

            if (($remotos[$destino] ?? null) === $archivo->getSize()) {
                continue;
            }

            $flujo = fopen($archivo->getPathname(), 'rb');
            try {
                if (! $nube->writeStream($destino, $flujo)) {
                    throw new \RuntimeException("No se pudo subir {$relativa}.");
                }
            } finally {
                if (is_resource($flujo)) {
                    fclose($flujo);
                }
            }

            $subidos++;
        }

        return $subidos;
    }

    private function respaldarBase(Filesystem $nube, int $conservar): void
    {
        $bd = config('database.connections.'.config('database.default'));
        $temporal = storage_path('app/respaldo-'.now()->format('Ymd-His').'.dump');

        try {
            $resultado = Process::timeout(1800)
                ->env(['PGPASSWORD' => (string) $bd['password']])
                ->run([
                    'pg_dump', '-h', $bd['host'], '-p', (string) $bd['port'], '-U', $bd['username'], '-d', $bd['database'],
                    '--format=custom', '--no-owner', "--file={$temporal}",
                ]);

            if ($resultado->failed() || ! is_file($temporal)) {
                throw new \RuntimeException('pg_dump falló: '.trim($resultado->errorOutput()));
            }

            $nombre = 'base-datos/inkapos-'.now()->format('Ymd-His').'.dump';
            $flujo = fopen($temporal, 'rb');
            try {
                if (! $nube->writeStream($nombre, $flujo)) {
                    throw new \RuntimeException('No se pudo subir el volcado de la base.');
                }
            } finally {
                if (is_resource($flujo)) {
                    fclose($flujo);
                }
            }

            $this->info('Base de datos: '.$nombre.' ('.round(filesize($temporal) / 1048576, 1).' MB).');
        } finally {
            @unlink($temporal);
        }

        // los nombres llevan la fecha, así que ordenados alfabéticamente quedan del más antiguo al más nuevo
        $volcados = collect($nube->files('base-datos'))->filter(fn ($f) => str_ends_with($f, '.dump'))->sort()->values();
        $volcados->slice(0, max(0, $volcados->count() - $conservar))->each(fn ($viejo) => $nube->delete($viejo));
    }
}
