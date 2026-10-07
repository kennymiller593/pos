<?php

namespace Tests\Feature;

use App\Console\Commands\ImportarImagenesProductos;
use App\Models\Producto;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ImportarImagenesProductosTest extends TestCase
{
    use CreaEscenarioPos;

    private string $carpeta;

    private string $lista;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->crearEscenarioBase();

        // "el otro sistema": una carpeta con fotos y la lista nombre -> archivo
        $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fotos-origen-'.uniqid();
        File::makeDirectory($this->carpeta);
        $this->lista = $this->carpeta.DIRECTORY_SEPARATOR.'lista.tsv';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);
        parent::tearDown();
    }

    private function foto(string $archivo, int $ancho = 1600, int $alto = 900): void
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 20, 120, 90));
        str_ends_with($archivo, '.png') ? imagepng($imagen, "{$this->carpeta}/{$archivo}") : imagejpeg($imagen, "{$this->carpeta}/{$archivo}");
        imagedestroy($imagen);
    }

    /** @param  array<string, string>  $filas  nombre en el otro sistema => archivo */
    private function escribirLista(array $filas): void
    {
        File::put($this->lista, collect($filas)->map(fn ($archivo, $nombre) => "{$nombre}\t{$archivo}")->implode("\n"));
    }

    private function importar(array $opciones = [])
    {
        return $this->artisan('productos:importar-imagenes', ['ruc' => $this->empresa->ruc, 'lista' => $this->lista, 'carpeta' => $this->carpeta, ...$opciones]);
    }

    private function producto(string $nombre, array $atributos = []): Producto
    {
        return $this->crearProducto(atributos: ['nombre' => $nombre, ...$atributos]);
    }

    /** Estado de la carpeta de origen: nombre de archivo => huella de su contenido. */
    private function huellasDelOrigen(): array
    {
        return collect(File::files($this->carpeta))->mapWithKeys(fn ($f) => [$f->getFilename() => md5_file($f->getPathname())])->all();
    }

    public function test_empareja_por_nombre_optimiza_y_no_toca_el_origen(): void
    {
        $urea = $this->producto('Urea 46% x 50 kg');
        $xtrim = $this->producto('X TRIM MATA TODO X 440 ML');
        $sinPareja = $this->producto('Producto que no estaba antes');

        $this->foto('1725762776.png');
        $this->foto('1775491950.jpg');
        // mayúsculas, tildes, espacios de más, un espacio invisible y la carpeta delante del archivo
        $this->escribirLista([
            '  ÚREA 46%  X 50 KG ' => 'producto/1725762776.png',
            "X TRIM MATA TODO\u{A0}X 440 ML" => 'producto/1775491950.jpg',
            'Otro que ya no se vende' => 'producto/no-importa.jpg',
        ]);
        $antes = $this->huellasDelOrigen();

        $this->importar(['--ejecutar' => true])->assertSuccessful();

        foreach ([$urea, $xtrim] as $producto) {
            $url = $producto->fresh()->imagen_url;
            $this->assertStringStartsWith('/storage/productos/', $url);
            $ruta = substr($url, strlen('/storage/'));
            Storage::disk('public')->assertExists($ruta);

            // misma presentación que una foto subida a mano: 4:3 y como máximo 800x600
            [$ancho, $alto] = getimagesizefromstring(Storage::disk('public')->get($ruta));
            $this->assertSame([800, 600], [$ancho, $alto]);
        }
        $this->assertNotSame($urea->fresh()->imagen_url, $xtrim->fresh()->imagen_url);
        $this->assertNull($sinPareja->fresh()->imagen_url);

        // del otro sistema solo se leyó: mismos archivos, mismo contenido
        $this->assertSame($antes, $this->huellasDelOrigen());
    }

    public function test_sin_ejecutar_solo_simula(): void
    {
        $urea = $this->producto('Urea 46% x 50 kg');
        $this->foto('a.jpg');
        $this->escribirLista(['Urea 46% x 50 kg' => 'a.jpg']);

        $this->importar()
            ->expectsOutputToContain('SIMULACIÓN')
            ->expectsOutputToContain('No se guardó nada')
            ->assertSuccessful();

        $this->assertNull($urea->fresh()->imagen_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_respeta_las_fotos_que_ya_existen_salvo_que_se_pida_reemplazar(): void
    {
        Storage::disk('public')->put('productos/la-de-antes.webp', 'x');
        $conFoto = $this->producto('Glifosato 480 SL x 1 L', ['imagen_url' => '/storage/productos/la-de-antes.webp']);
        $this->foto('g.jpg');
        $this->escribirLista(['Glifosato 480 SL x 1 L' => 'g.jpg']);

        $this->importar(['--ejecutar' => true])->assertSuccessful();
        $this->assertSame('/storage/productos/la-de-antes.webp', $conFoto->fresh()->imagen_url);

        $this->importar(['--ejecutar' => true, '--reemplazar' => true])->assertSuccessful();
        $nueva = $conFoto->fresh()->imagen_url;
        $this->assertNotSame('/storage/productos/la-de-antes.webp', $nueva);
        Storage::disk('public')->assertExists(substr($nueva, strlen('/storage/')));
        // la foto anterior de inkaPos se limpia; la del origen sigue en su carpeta
        Storage::disk('public')->assertMissing('productos/la-de-antes.webp');
        $this->assertFileExists("{$this->carpeta}/g.jpg");
    }

    public function test_omite_la_foto_generica_los_ambiguos_y_los_archivos_que_no_sirven(): void
    {
        // una misma foto en muchos productos = el "sin imagen" del otro sistema
        $genericos = collect(range(1, 4))->map(fn ($i) => $this->producto("Repuesto {$i}"));
        $ambiguo = $this->producto('Abono foliar');
        $sinArchivo = $this->producto('Bomba de mochila');
        $ilegible = $this->producto('Manguera 1 pulgada');
        $bueno = $this->producto('Semilla de maíz');

        $this->foto('generica.jpg');
        $this->foto('abono-1.jpg');
        $this->foto('abono-2.jpg');
        $this->foto('maiz.jpg');
        File::put("{$this->carpeta}/rota.jpg", 'esto no es una imagen');

        File::put($this->lista, implode("\n", [
            "Repuesto 1\tgenerica.jpg", "Repuesto 2\tgenerica.jpg", "Repuesto 3\tgenerica.jpg", "Repuesto 4\tgenerica.jpg",
            "Abono foliar\tabono-1.jpg", "ABONO FOLIAR\tabono-2.jpg",
            "Bomba de mochila\tno-existe.jpg",
            "Manguera 1 pulgada\trota.jpg",
            "Semilla de maíz\tmaiz.jpg",
            "Fila sin archivo\t",
            'fila sin tabulacion',
        ]));

        $this->importar(['--ejecutar' => true])
            ->expectsOutputToContain('Foto genérica: generica.jpg (la usan 4 productos del otro sistema)')
            ->assertSuccessful();

        $this->assertNotNull($bueno->fresh()->imagen_url);
        foreach ([...$genericos, $ambiguo, $sinArchivo, $ilegible] as $producto) {
            $this->assertNull($producto->fresh()->imagen_url, "{$producto->nombre} no debía recibir foto");
        }
        $this->assertCount(1, Storage::disk('public')->allFiles());

        // con el tope en 0 la foto compartida sí entra, una copia por producto
        $this->importar(['--ejecutar' => true, '--max-compartida' => 0])->assertSuccessful();
        $urls = $genericos->map(fn ($p) => $p->fresh()->imagen_url);
        $this->assertCount(4, $urls->filter()->unique());
    }

    public function test_solo_toca_la_empresa_indicada_y_valida_lo_que_recibe(): void
    {
        $mio = $this->producto('Urea 46% x 50 kg');
        $miEmpresa = $this->empresa;
        $this->crearEscenarioBase();
        $ajeno = $this->producto('Urea 46% x 50 kg');
        $this->empresa = $miEmpresa;

        $this->foto('u.jpg');
        $this->escribirLista(['Urea 46% x 50 kg' => 'u.jpg']);

        $this->importar(['--ejecutar' => true])->assertSuccessful();
        $this->assertNotNull($mio->fresh()->imagen_url);
        $this->assertNull($ajeno->fresh()->imagen_url);

        $this->artisan('productos:importar-imagenes', ['ruc' => '00000000000', 'lista' => $this->lista, 'carpeta' => $this->carpeta])->assertFailed();
        $this->artisan('productos:importar-imagenes', ['ruc' => $this->empresa->ruc, 'lista' => $this->lista, 'carpeta' => $this->carpeta.'-no-existe'])->assertFailed();
        $this->artisan('productos:importar-imagenes', ['ruc' => $this->empresa->ruc, 'lista' => $this->carpeta.'/nada.tsv', 'carpeta' => $this->carpeta])->assertFailed();

        // un archivo con ruta no puede salirse de la carpeta de fotos
        File::put($this->lista, "Urea 46% x 50 kg\t../../../../etc/passwd");
        $this->importar(['--ejecutar' => true, '--reemplazar' => true])->assertSuccessful();
        $this->assertStringStartsWith('/storage/productos/', $mio->fresh()->imagen_url);
    }

    public function test_la_comparacion_de_nombres_ignora_mayusculas_tildes_y_espacios(): void
    {
        $this->assertSame('urea 46 x 50 kg', ImportarImagenesProductos::normalizar("  ÚREA 46%\u{A0}x  50 KG "));
        $this->assertSame('x trim mata pulgas y garrapatas x 440 ml', ImportarImagenesProductos::normalizar("X TRIM MATA PULGAS Y GARRAPATAS\u{A0}X 440 ML"));
        $this->assertSame('nandu fosforo', ImportarImagenesProductos::normalizar('ÑANDÚ FÓSFORO'));
        $this->assertSame('', ImportarImagenesProductos::normalizar(' -- '));
    }
}
