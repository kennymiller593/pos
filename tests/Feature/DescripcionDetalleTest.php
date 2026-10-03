<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class DescripcionDetalleTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_la_presentacion_que_se_llama_igual_que_el_producto_no_se_repite(): void
    {
        $producto = $this->crearProducto(precio: 10.0, atributos: ['nombre' => 'Foliar Nutri Mas 20-20-20 kg.']);
        $this->darStock($producto, 50, 4);
        $this->abrirCaja();

        $unidad = $producto->presentaciones->first();            // "Unidad"
        $igual = $this->agregarPresentacion($producto, 'foliar nutri mas 20-20-20 KG.', 1, 10.0);
        $caja = $this->agregarPresentacion($producto, 'Caja x12', 12, 100.0);

        $this->venderContado($unidad, 1)->assertSessionHas('success');
        $this->venderContado($igual, 1)->assertSessionHas('success');
        $this->venderContado($caja, 1)->assertSessionHas('success');

        $descripciones = Comprobante::where('empresa_id', $this->empresa->id)
            ->orderBy('correlativo')
            ->get()
            ->map(fn ($c) => $c->detalles()->value('descripcion'))
            ->all();

        $this->assertSame([
            'Foliar Nutri Mas 20-20-20 kg.',
            'Foliar Nutri Mas 20-20-20 kg.',
            'Foliar Nutri Mas 20-20-20 kg. (Caja x12)',
        ], $descripciones);
    }

    public function test_el_script_limpia_las_descripciones_repetidas_ya_guardadas(): void
    {
        $producto = $this->crearProducto(precio: 10.0, atributos: ['nombre' => 'Abono (premium)']);
        $this->darStock($producto, 50, 4);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 1)->assertSessionHas('success');

        $detalleId = DB::table('comprobante_detalles')->where('empresa_id', $this->empresa->id)->value('id');
        DB::table('comprobante_detalles')->where('id', $detalleId)->update(['descripcion' => 'Abono (premium) (Abono (premium))']);

        DB::unprepared(file_get_contents(base_path('database/sql/016_descripcion_repetida.sql')));

        $this->assertSame('Abono (premium)', DB::table('comprobante_detalles')->where('id', $detalleId)->value('descripcion'));
        // una descripcion normal no se toca
        DB::table('comprobante_detalles')->where('id', $detalleId)->update(['descripcion' => 'Abono (premium) (Caja x12)']);
        DB::unprepared(file_get_contents(base_path('database/sql/016_descripcion_repetida.sql')));
        $this->assertSame('Abono (premium) (Caja x12)', DB::table('comprobante_detalles')->where('id', $detalleId)->value('descripcion'));
    }
}
