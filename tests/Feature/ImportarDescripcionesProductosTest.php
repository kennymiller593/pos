<?php

namespace Tests\Feature;

use App\Console\Commands\ImportarDescripcionesProductos;
use App\Models\Producto;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ImportarDescripcionesProductosTest extends TestCase
{
    use CreaEscenarioPos;

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->archivo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'descripciones-'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        File::delete($this->archivo);
        parent::tearDown();
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $filas  nombre, descripción corta, descripción larga */
    private function escribir(array $filas): void
    {
        $f = fopen($this->archivo, 'w');
        fputcsv($f, ['nombre', 'descripcion', 'descripcion_largo'], escape: '');
        foreach ($filas as $fila) {
            fputcsv($f, $fila, escape: '');
        }
        fclose($f);
    }

    private function importar(array $opciones = [])
    {
        return $this->artisan('productos:importar-descripciones', ['ruc' => $this->empresa->ruc, 'archivo' => $this->archivo, ...$opciones]);
    }

    public function test_arma_un_texto_plano_con_la_frase_corta_y_el_html_del_otro_sistema(): void
    {
        $html = '<h3>? Plantines de Albahaca</h3><p>Plantines <strong>sanos</strong> y listos.</p><ul><li>Buen desarrollo radicular</li><li>Crecimiento&nbsp;uniforme</li></ul><p>Ideal para huertos.</p>';

        $this->assertSame(
            "Plantines de Albahaca\n\nPlantines sanos y listos.\n\n• Buen desarrollo radicular\n• Crecimiento uniforme\n\nIdeal para huertos.",
            ImportarDescripcionesProductos::htmlATexto($html),
        );

        // la frase corta va primero, con mayúscula y punto; si es solo el nombre o ya está en el largo, no se repite
        $this->assertSame("Bioestimulante para el estrés.\n\nTexto largo del producto.", ImportarDescripcionesProductos::armar('ERGOFIX X LITRO', 'bioestimulante para el estrés', '<p>Texto largo del producto.</p>'));
        $this->assertSame('Texto largo.', ImportarDescripcionesProductos::armar('ERGOFIX X LITRO', 'Ergofix x litro', 'Texto largo.'));
        $this->assertSame('Es un bioestimulante para el estrés hídrico.', ImportarDescripcionesProductos::armar('ERGOFIX', 'bioestimulante para el estrés hídrico', 'Es un bioestimulante para el estrés hídrico.'));
        $this->assertSame('Fungicida de amplio espectro.', ImportarDescripcionesProductos::armar('MANZATE', 'fungicida de amplio espectro', ''));
        $this->assertSame('', ImportarDescripcionesProductos::armar('MANZATE', 'Manzate', ''));

        // el recorte respeta párrafos u oraciones
        $largo = str_repeat('Una oración de prueba. ', 30)."\n\n".str_repeat('Otra más. ', 30);
        $recortado = ImportarDescripcionesProductos::recortar($largo, 400);
        $this->assertLessThanOrEqual(400, mb_strlen($recortado));
        $this->assertStringEndsWith('.', $recortado);
    }

    public function test_importa_por_nombre_solo_en_las_descripciones_vacias_y_sin_ejecutar_no_toca_nada(): void
    {
        $urea = $this->crearProducto(atributos: ['nombre' => 'Urea 46% x 50 kg']);
        $regent = $this->crearProducto(atributos: ['nombre' => 'REGENT X 250 ML', 'descripcion' => 'La que escribió el dueño']);
        $sinTexto = $this->crearProducto(atributos: ['nombre' => 'Aspersor circular']);
        $sinPareja = $this->crearProducto(atributos: ['nombre' => 'Mochila fumigadora']);

        $this->escribir([
            ['ÚREA 46%  X 50 KG', 'fertilizante nitrogenado', '<p>Urea granulada con <b>46% de nitrógeno</b>.</p><p>Para maíz, papa y pastos.</p>'],
            ['Regent x 250 ml', 'insecticida', '<p>Controla gusano blanco.</p>'],
            ['ASPERSOR CIRCULAR', '', ''],
            ['Producto que no existe aquí', 'algo', ''],
        ]);

        // simulación: informa y no guarda
        $this->importar()
            ->expectsOutputToContain('SIMULACIÓN')
            ->expectsOutputToContain('Descripciones que se importarían: 1')
            ->expectsOutputToContain('Ya tenían descripción')
            ->expectsOutputToContain('· Mochila fumigadora')
            ->assertSuccessful();
        $this->assertNull($urea->fresh()->descripcion);

        $this->importar(['--ejecutar' => true])->expectsOutputToContain('Descripciones importadas: 1')->assertSuccessful();
        $this->assertSame("Fertilizante nitrogenado.\n\nUrea granulada con 46% de nitrógeno.\n\nPara maíz, papa y pastos.", $urea->fresh()->descripcion);
        $this->assertSame('La que escribió el dueño', $regent->fresh()->descripcion);
        $this->assertNull($sinTexto->fresh()->descripcion);
        $this->assertNull($sinPareja->fresh()->descripcion);

        // con --sobrescribir también entra lo que ya tenía texto; y no se toca a otra empresa
        $this->importar(['--ejecutar' => true, '--sobrescribir' => true])->assertSuccessful();
        $this->assertSame("Insecticida.\n\nControla gusano blanco.", $regent->fresh()->descripcion);
        $this->assertSame(2, Producto::where('empresa_id', $this->empresa->id)->whereNotNull('descripcion')->count());

        // sin la empresa o sin el archivo, avisa
        $this->artisan('productos:importar-descripciones', ['ruc' => '00000000000', 'archivo' => $this->archivo])->assertFailed();
        $this->artisan('productos:importar-descripciones', ['ruc' => $this->empresa->ruc, 'archivo' => $this->archivo.'.no'])->assertFailed();
    }
}
