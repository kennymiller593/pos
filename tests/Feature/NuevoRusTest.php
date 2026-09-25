<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Producto;
use App\Models\SerieCorrelativo;
use App\Models\UnidadMedida;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

/** Nuevo RUS: solo boletas (nunca facturas) y sin IGV; lo gravado se emite como exonerado (20). */
class NuevoRusTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->crearEscenarioBase();

        $this->empresa->update([
            'regimen_tributario' => 'RUS',
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA',
            'clave_certificado' => 'clave123',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos',
            'entorno_sunat' => 'beta',
            'facturacion_electronica' => true,
        ]);

        foreach (['03' => 'B001', '01' => 'F001', '00' => 'NV01'] as $tipo => $serie) {
            SerieCorrelativo::create([
                'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id,
                'tipo_comprobante_codigo' => $tipo, 'serie' => $serie, 'correlativo' => 0,
            ]);
        }

        $enviador = new EnviadorSunatFalso;
        $enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'HASH');
        $this->app->instance(EnviadorSunat::class, $enviador);
    }

    private function vender(string $tipo): \Illuminate\Testing\TestResponse
    {
        $producto = $this->crearProducto(precio: 11.80); // gravado en el catalogo
        $this->darStock($producto, 10, 5.00);
        $this->abrirCaja();

        return $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => $tipo,
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 11.80, 'referencia' => null]],
        ]);
    }

    public function test_la_boleta_sale_sin_igv_como_exonerada(): void
    {
        $this->vender('03')->assertSessionHas('success');

        $boleta = Comprobante::where('empresa_id', $this->empresa->id)->where('tipo_comprobante_codigo', '03')->firstOrFail();
        $this->assertSame(0.0, (float) $boleta->total_igv);
        $this->assertSame(0.0, (float) $boleta->total_gravado);
        $this->assertSame(11.80, (float) $boleta->total_exonerado);
        $this->assertSame(11.80, (float) $boleta->total);
        $this->assertSame('20', $boleta->detalles()->value('tipo_afectacion_codigo'));
    }

    public function test_no_puede_emitir_facturas(): void
    {
        $this->vender('01')->assertSessionHasErrors('tipo_comprobante_codigo');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());

        $this->actingAs($this->admin)->get('/pos')
            ->assertInertia(fn ($p) => $p->where('auth.user.empresa.regimen_tributario', 'RUS'));
    }

    public function test_una_nota_de_venta_se_convierte_solo_en_boleta(): void
    {
        $this->vender('00')->assertSessionHas('success');
        $nota = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertSame(0.0, (float) $nota->total_igv);

        $this->actingAs($this->admin)->post("/comprobantes/{$nota->id}/convertir", ['tipo' => '01'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Nuevo RUS'));

        $this->actingAs($this->admin)->post("/comprobantes/{$nota->id}/convertir", ['tipo' => '03'])
            ->assertSessionHas('success');
        $this->assertSame('03', $nota->fresh()->tipo_comprobante_codigo);
    }

    public function test_una_nota_con_igv_de_antes_del_rus_no_se_convierte(): void
    {
        $this->empresa->update(['regimen_tributario' => 'MYPE']);
        $this->vender('00')->assertSessionHas('success');
        $nota = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertGreaterThan(0, (float) $nota->total_igv);

        $this->empresa->update(['regimen_tributario' => 'RUS']);
        $this->actingAs($this->admin)->post("/comprobantes/{$nota->id}/convertir", ['tipo' => '03'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'se registró con IGV'));
    }

    public function test_los_productos_gravados_se_guardan_como_exonerados(): void
    {
        $unidad = UnidadMedida::query()->value('codigo');

        $this->actingAs($this->admin)->post('/productos', [
            'nombre' => 'Semilla RUS',
            'unidad_base_codigo' => $unidad,
            'tipo_afectacion_codigo' => '10',
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            'presentaciones' => [[
                'id' => null, 'nombre' => 'Unidad', 'unidad_codigo' => $unidad, 'factor_conversion' => 1,
                'precio_venta' => 5, 'es_default' => true,
            ]],
        ])->assertSessionHas('success');

        $this->assertSame('20', Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Semilla RUS')->value('tipo_afectacion_codigo'));
    }
}
