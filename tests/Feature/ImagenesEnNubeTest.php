<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use App\Services\ImagenProductoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ImagenesEnNubeTest extends TestCase
{
    use CreaEscenarioPos;

    private const BASE = 'https://img.ejemplo.pe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        Storage::fake('public');
    }

    /** Simula el bucket público: disco s3 con dirección propia. */
    private function activarNube(): void
    {
        Storage::fake(ImagenProductoService::DISCO);
        config(['filesystems.disks.imagenes.driver' => 's3', 'filesystems.disks.imagenes.url' => self::BASE]);
    }

    private function payload(array $extra = []): array
    {
        $unidad = UnidadMedida::query()->value('codigo');

        return [
            'codigo_interno' => 'IMG-'.random_int(100000, 999999),
            'nombre' => 'Producto con foto',
            'categoria_id' => null,
            'marca_id' => null,
            'stock_minimo' => 0,
            'unidad_base_codigo' => $unidad,
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'activo' => true,
            'presentaciones' => [['id' => null, 'nombre' => 'Unidad', 'unidad_codigo' => $unidad, 'factor_conversion' => 1, 'precio_venta' => 10, 'precio_mayorista' => null, 'cantidad_mayorista' => null, 'codigo_barras' => null, 'es_default' => true]],
            ...$extra,
        ];
    }

    public function test_con_la_nube_activa_la_foto_va_al_bucket_y_la_direccion_es_absoluta(): void
    {
        $this->activarNube();

        $this->actingAs($this->admin)
            ->post('/productos', $this->payload(['imagen' => UploadedFile::fake()->image('foto.png', 1200, 900)]))
            ->assertSessionHasNoErrors();

        $producto = Producto::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->assertStringStartsWith(self::BASE.'/productos/', $producto->imagen_url);
        $ruta = substr($producto->imagen_url, strlen(self::BASE) + 1);
        Storage::disk(ImagenProductoService::DISCO)->assertExists($ruta);
        $this->assertEmpty(Storage::disk('public')->allFiles()); // nada queda en el servidor

        // al cambiar la foto, la anterior se borra del bucket
        $this->actingAs($this->admin)
            ->post("/productos/{$producto->id}", $this->payload(['_method' => 'put', 'imagen' => UploadedFile::fake()->image('otra.png', 400, 300)]))
            ->assertSessionHasNoErrors();

        Storage::disk(ImagenProductoService::DISCO)->assertMissing($ruta);
        $this->assertCount(1, Storage::disk(ImagenProductoService::DISCO)->allFiles());

        // y al quitarla, también
        $this->actingAs($this->admin)
            ->post("/productos/{$producto->id}", $this->payload(['_method' => 'put', 'imagen_eliminar' => true]))
            ->assertSessionHasNoErrors();

        $this->assertNull($producto->fresh()->imagen_url);
        $this->assertEmpty(Storage::disk(ImagenProductoService::DISCO)->allFiles());
    }

    public function test_sin_configurar_la_nube_sigue_guardando_en_el_servidor(): void
    {
        $this->actingAs($this->admin)
            ->post('/productos', $this->payload(['imagen' => UploadedFile::fake()->image('foto.png', 800, 600)]))
            ->assertSessionHasNoErrors();

        $producto = Producto::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->assertStringStartsWith('/storage/productos/', $producto->imagen_url);
        $this->assertFalse(app(ImagenProductoService::class)->enNube());

        $this->artisan('productos:imagenes-a-nube')->expectsOutputToContain('no están configuradas')->assertSuccessful();
        $this->assertStringStartsWith('/storage/productos/', $producto->fresh()->imagen_url);
    }

    public function test_el_comando_pasa_a_la_nube_las_fotos_que_ya_estaban_en_el_servidor(): void
    {
        Storage::disk('public')->put('productos/vieja.webp', 'FOTO');
        $a = $this->crearProducto();
        $b = $this->crearProducto();
        $perdida = $this->crearProducto();
        $externa = $this->crearProducto();
        $a->update(['imagen_url' => '/storage/productos/vieja.webp']);
        $b->update(['imagen_url' => '/storage/productos/vieja.webp']); // comparten archivo
        $perdida->update(['imagen_url' => '/storage/productos/no-existe.webp']);
        $externa->update(['imagen_url' => 'https://otro-sitio.com/foto.jpg']);

        $this->activarNube();
        $this->artisan('productos:imagenes-a-nube')->assertSuccessful();

        $url = $a->fresh()->imagen_url;
        $this->assertStringStartsWith(self::BASE.'/productos/', $url);
        $this->assertSame($url, $b->fresh()->imagen_url); // un solo archivo subido para los dos
        $this->assertSame('FOTO', Storage::disk(ImagenProductoService::DISCO)->get(substr($url, strlen(self::BASE) + 1)));
        $this->assertCount(1, Storage::disk(ImagenProductoService::DISCO)->allFiles());
        $this->assertSame('/storage/productos/no-existe.webp', $perdida->fresh()->imagen_url);
        $this->assertSame('https://otro-sitio.com/foto.jpg', $externa->fresh()->imagen_url);
        Storage::disk('public')->assertExists('productos/vieja.webp'); // el local se conserva

        // repetirlo no vuelve a subir nada
        $this->artisan('productos:imagenes-a-nube')->assertSuccessful();
        $this->assertCount(1, Storage::disk(ImagenProductoService::DISCO)->allFiles());
    }
}
