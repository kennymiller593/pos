<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function payload(array $extra = []): array
    {
        $unidad = UnidadMedida::query()->value('codigo');

        return [
            'codigo_interno' => 'IMG-'.random_int(100000, 999999),
            'nombre' => 'Producto con foto',
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => $unidad,
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            'presentaciones' => [[
                'id' => null,
                'nombre' => 'Unidad',
                'unidad_codigo' => $unidad,
                'factor_conversion' => 1,
                'precio_venta' => 5,
                'precio_mayorista' => null,
                'cantidad_mayorista' => null,
                'codigo_barras' => null,
                'es_default' => true,
            ]],
            ...$extra,
        ];
    }

    public function test_crear_producto_con_imagen_y_luego_quitarla(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/productos', $this->payload([
            'imagen' => UploadedFile::fake()->image('foto.png', 300, 300),
        ]))->assertSessionHas('success');

        $producto = Producto::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertStringStartsWith('/storage/productos/', $producto->imagen_url);
        Storage::disk('public')->assertExists(substr($producto->imagen_url, strlen('/storage/')));

        // quitar la imagen: el campo queda vacio y el archivo se borra
        $archivo = substr($producto->imagen_url, strlen('/storage/'));
        $this->actingAs($this->admin)->put("/productos/{$producto->id}", $this->payload([
            'codigo_interno' => $producto->codigo_interno,
            'imagen_eliminar' => true,
        ]))->assertSessionHas('success');

        $this->assertNull($producto->fresh()->imagen_url);
        Storage::disk('public')->assertMissing($archivo);
    }

    public function test_rechaza_archivos_que_no_son_imagen(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/productos', $this->payload([
            'imagen' => UploadedFile::fake()->create('archivo.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('imagen');
    }
}
