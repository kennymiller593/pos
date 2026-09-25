<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Services\ImportacionProductosService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ImportacionProductosTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    /** Arma un .xlsx con la cabecera de la plantilla y las filas dadas (por nombre de columna). */
    private function excel(array $filas, ?array $cabecera = null): UploadedFile
    {
        $cabecera ??= ImportacionProductosService::COLUMNAS;
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray($cabecera, null, 'A1');
        foreach ($filas as $i => $fila) {
            $hoja->fromArray(array_map(fn ($col) => $fila[$col] ?? '', $cabecera), null, 'A'.($i + 2));
        }
        $ruta = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function previsualizar(UploadedFile $archivo)
    {
        return $this->actingAs($this->admin)->post('/productos/importar/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json']);
    }

    public function test_la_plantilla_se_descarga_con_las_columnas(): void
    {
        $respuesta = $this->actingAs($this->admin)->get('/productos/importar/plantilla');
        $respuesta->assertOk()->assertDownload('plantilla-productos.xlsx');
    }

    public function test_vista_previa_marca_errores_y_advertencias_sin_guardar_nada(): void
    {
        $respuesta = $this->previsualizar($this->excel([
            ['nombre' => 'Cable UTP Cat6', 'precio_venta' => '1,20', 'precio_compra' => '', 'stock_inicial' => 305, 'unidad' => 'Metro', 'permite_fraccion' => 'SI'],
            ['nombre' => '', 'precio_venta' => 5],
            ['nombre' => 'Sin precio'],
            ['nombre' => 'Unidad rara', 'precio_venta' => 3, 'unidad' => 'Barriles'],
            ['nombre' => 'A pérdida', 'precio_venta' => 2, 'precio_compra' => 3],
        ]))->assertOk();

        $respuesta->assertJsonPath('resumen.total', 5)
            ->assertJsonPath('resumen.crear', 2)
            ->assertJsonPath('resumen.errores', 3)
            ->assertJsonPath('filas.0.estado', 'advertencia')   // stock sin precio de compra
            ->assertJsonPath('filas.0.unidad', 'Metro')
            ->assertJsonPath('filas.0.precio_venta', 1.2)
            ->assertJsonPath('filas.1.estado', 'error')
            ->assertJsonPath('filas.3.estado', 'error')
            ->assertJsonPath('filas.4.estado', 'advertencia');   // vende a perdida

        $this->assertSame(0, Producto::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_importa_productos_con_presentaciones_stock_y_costo(): void
    {
        $token = $this->previsualizar($this->excel([
            [
                'nombre' => 'Cable UTP Cat6', 'precio_venta' => 1.20, 'stock_inicial' => 305, 'unidad' => 'Metro',
                'permite_fraccion' => 'SI', 'categoria' => 'Redes', 'marca' => 'Dixon', 'stock_minimo' => 20,
                'precio_mayorista' => 1.00, 'cantidad_mayorista' => 50,
                'presentacion' => 'Caja 305 m', 'factor' => 305, 'precio_presentacion' => 190, 'precio_compra_presentacion' => 142.30,
            ],
            ['nombre' => 'Arroz 1 kg', 'precio_venta' => 4.80, 'precio_compra' => 3.90, 'afecto_igv' => 'EXONERADO', 'codigo_barras' => '7751234567890'],
            ['nombre' => '', 'precio_venta' => 1],
        ]))->json('token');

        $this->actingAs($this->admin)->post('/productos/importar', ['token' => $token])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 productos nuevos') && str_contains($m, '1 filas con errores'));

        $cable = Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Cable UTP Cat6')->with('presentaciones')->firstOrFail();
        $this->assertSame('P0001', $cable->codigo_interno);
        $this->assertSame('MTR', $cable->unidad_base_codigo);
        $this->assertTrue($cable->permite_fraccion);
        $this->assertSame('Redes', Categoria::find($cable->categoria_id)->nombre);
        $this->assertCount(2, $cable->presentaciones);
        $caja = $cable->presentaciones->firstWhere('nombre', 'Caja 305 m');
        $this->assertSame(305.0, (float) $caja->factor_conversion);

        // stock inicial con el costo derivado de la caja: 142.30 / 305
        $this->assertSame(305.0, $this->stockDe($cable));
        $capa = CapaCosto::where('producto_id', $cable->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.466557, (float) $capa->costo_unitario, 0.00001);
        $this->assertSame(1, MovimientoInventario::where('producto_id', $cable->id)->where('tipo', 'ajuste')->count());

        $arroz = Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Arroz 1 kg')->firstOrFail();
        $this->assertSame('P0002', $arroz->codigo_interno);
        $this->assertSame('20', trim($arroz->tipo_afectacion_codigo));
        $this->assertSame(0.0, $this->stockDe($arroz));

        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->empresa->id, 'accion' => 'productos.importados']);

        // el token no se puede reusar
        $this->actingAs($this->admin)->post('/productos/importar', ['token' => $token])->assertSessionHas('error');
    }

    public function test_una_fila_existente_actualiza_sin_duplicar_ni_tocar_stock(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $producto->presentaciones()->first()->update(['codigo_barras' => '7750000000011']);
        $this->darStock($producto, 8, 4.00);

        $token = $this->previsualizar($this->excel([
            ['nombre' => 'Otro nombre cualquiera', 'precio_venta' => 12.50, 'codigo_barras' => '7750000000011', 'stock_inicial' => 100],
        ]))->assertJsonPath('filas.0.accion', 'actualizar')
            ->assertJsonPath('filas.0.estado', 'advertencia')
            ->json('token');

        $this->actingAs($this->admin)->post('/productos/importar', ['token' => $token])->assertSessionHas('success');

        $this->assertSame(1, Producto::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(12.5, (float) $producto->presentaciones()->first()->precio_venta);
        $this->assertSame(8.0, $this->stockDe($producto));
    }

    public function test_archivo_sin_columnas_obligatorias_o_sin_permiso(): void
    {
        $this->previsualizar($this->excel([['nombre' => 'X']], ['nombre', 'categoria']))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'precio_venta'));

        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(10000, 99999).'@test.local');
        $this->actingAs($vendedor)->get('/productos/importar/plantilla')->assertForbidden();
    }
}
