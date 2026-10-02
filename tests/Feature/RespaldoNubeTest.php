<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RespaldoNubeTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        // archivos de prueba en una subcarpeta propia de storage/app/private
        $this->carpeta = storage_path('app/private/prueba-respaldo-'.uniqid());
        File::ensureDirectoryExists($this->carpeta);
        File::put("{$this->carpeta}/factura.xml", '<Invoice/>');
        File::put("{$this->carpeta}/R-factura.zip", 'CDR');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);
        parent::tearDown();
    }

    private function configurar(): void
    {
        config(['filesystems.disks.respaldo.bucket' => 'inkapos-respaldos', 'filesystems.disks.respaldo.key' => 'clave']);
        Storage::fake('respaldo');
    }

    public function test_sin_configurar_no_hace_nada(): void
    {
        config(['filesystems.disks.respaldo.bucket' => null, 'filesystems.disks.respaldo.key' => null]);
        Process::fake();

        $this->artisan('respaldo:nube')->expectsOutputToContain('sin configurar')->assertSuccessful();

        Process::assertNothingRan();
    }

    public function test_sube_los_archivos_y_la_segunda_vez_solo_lo_que_cambio(): void
    {
        $this->configurar();
        $nube = Storage::disk('respaldo');
        $prefijo = 'archivos/privado/'.basename($this->carpeta);

        $this->artisan('respaldo:nube --sin-base')->assertSuccessful();

        $nube->assertExists("{$prefijo}/factura.xml");
        $nube->assertExists("{$prefijo}/R-factura.zip");
        $this->assertSame('<Invoice/>', $nube->get("{$prefijo}/factura.xml"));

        // lo ya subido no se vuelve a subir; lo que cambió de tamaño y lo nuevo, sí
        $nube->put("{$prefijo}/R-factura.zip", 'XXX'); // mismo tamaño: si se re-subiera, volvería a decir "CDR"
        File::put("{$this->carpeta}/factura.xml", '<Invoice>corregida</Invoice>');
        File::put("{$this->carpeta}/nueva.xml", '<Invoice/>');

        $this->artisan('respaldo:nube --sin-base')->assertSuccessful();

        $this->assertSame('XXX', $nube->get("{$prefijo}/R-factura.zip"));
        $this->assertSame('<Invoice>corregida</Invoice>', $nube->get("{$prefijo}/factura.xml"));
        $nube->assertExists("{$prefijo}/nueva.xml");

        // borrar en el servidor no borra la copia
        File::delete("{$this->carpeta}/nueva.xml");
        $this->artisan('respaldo:nube --sin-base')->assertSuccessful();
        $nube->assertExists("{$prefijo}/nueva.xml");
    }

    public function test_sube_el_volcado_de_la_base_y_conserva_solo_los_ultimos(): void
    {
        $this->configurar();
        $nube = Storage::disk('respaldo');
        foreach (['20260101-020000', '20260102-020000', '20260103-020000'] as $fecha) {
            $nube->put("base-datos/inkapos-{$fecha}.dump", 'viejo');
        }

        // pg_dump simulado: escribe el archivo que se le pide con --file=
        Process::fake(function ($proceso) {
            $archivo = collect($proceso->command)->first(fn ($a) => str_starts_with($a, '--file='));
            File::put(substr($archivo, strlen('--file=')), 'VOLCADO');

            return Process::result();
        });

        $this->artisan('respaldo:nube --conservar=2')->assertSuccessful();

        $volcados = collect($nube->files('base-datos'))->sort()->values();
        $this->assertCount(2, $volcados);
        $this->assertSame('base-datos/inkapos-20260103-020000.dump', $volcados[0]);
        $this->assertSame('VOLCADO', $nube->get($volcados[1]));
        Process::assertRan(fn ($proceso) => $proceso->command[0] === 'pg_dump' && ($proceso->environment['PGPASSWORD'] ?? null) !== null);
        $this->assertEmpty(glob(storage_path('app/respaldo-*.dump'))); // no deja el temporal
    }

    public function test_si_pg_dump_falla_el_comando_falla(): void
    {
        $this->configurar();
        Process::fake(fn () => Process::result(errorOutput: 'connection refused', exitCode: 1));

        $this->artisan('respaldo:nube')->expectsOutputToContain('falló')->assertFailed();

        $this->assertEmpty(Storage::disk('respaldo')->files('base-datos'));
    }
}
