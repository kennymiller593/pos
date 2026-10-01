<?php

namespace Tests\Feature;

use App\Jobs\EnviarCotizacionPorCorreo;
use App\Models\Comprobante;
use App\Models\Cotizacion;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Usuario;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CotizacionTest extends TestCase
{
    use CreaEscenarioPos;

    private Usuario $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');
    }

    private function payload(array $items, array $extra = []): array
    {
        return [
            'cliente_id' => null,
            'fecha_emision' => now()->toDateString(),
            'valida_hasta' => now()->addDays(7)->toDateString(),
            'tiempo_entrega' => '2 días hábiles',
            'direccion_envio' => 'Jr. Lamas 123',
            'es_credito' => false,
            'observaciones' => 'Incluye instalación',
            'items' => $items,
            ...$extra,
        ];
    }

    private function cotizar(array $items, array $extra = [], ?Usuario $usuario = null): Cotizacion
    {
        $this->actingAs($usuario ?? $this->admin)
            ->post('/cotizaciones', $this->payload($items, $extra))
            ->assertRedirect(route('cotizaciones.index'))
            ->assertSessionHas('success');

        return Cotizacion::where('empresa_id', $this->empresa->id)->orderByDesc('numero')->firstOrFail();
    }

    public function test_cotizar_calcula_como_una_venta_pero_no_toca_stock_ni_comprobantes(): void
    {
        $producto = $this->crearProducto(precio: 118.00);
        $this->darStock($producto, 10, 50);
        $cliente = $this->crearCliente();

        $cotizacion = $this->cotizar(
            [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2, 'descuento' => 36]],
            ['cliente_id' => $cliente->id],
        );

        $this->assertSame(1, $cotizacion->numero);
        $this->assertSame('COT-000001', $cotizacion->codigo());
        $this->assertSame('pendiente', $cotizacion->estado);
        $this->assertSame($cliente->nombre, $cotizacion->cliente_nombre);
        // 2 x 118 = 236, menos 36 = 200 con IGV incluido
        $this->assertEqualsWithDelta(200.00, (float) $cotizacion->total, 0.001);
        $this->assertEqualsWithDelta(169.49, (float) $cotizacion->total_gravado, 0.001);
        $this->assertEqualsWithDelta(30.51, (float) $cotizacion->total_igv, 0.001);
        $this->assertEqualsWithDelta(36.00, (float) $cotizacion->total_descuentos, 0.001);
        $this->assertSame(1, $cotizacion->detalles()->count());

        $this->assertEqualsWithDelta(10, $this->stockDe($producto), 0.001);
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, MovimientoInventario::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_el_correlativo_es_por_empresa_y_se_puede_cotizar_sin_stock(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 50]];

        $this->assertSame(1, $this->cotizar($item)->numero);
        $this->assertSame(2, $this->cotizar($item)->numero);
    }

    public function test_solo_se_cotizan_productos_del_catalogo_de_la_empresa(): void
    {
        $this->actingAs($this->admin)
            ->post('/cotizaciones', $this->payload([['presentacion_id' => '01900000-0000-7000-8000-000000000000', 'cantidad' => 1]]))
            ->assertSessionHas('error');

        $this->actingAs($this->admin)
            ->post('/cotizaciones', $this->payload([]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Cotizacion::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_la_validez_no_puede_ser_anterior_a_la_emision(): void
    {
        $producto = $this->crearProducto();

        $this->actingAs($this->admin)
            ->post('/cotizaciones', $this->payload(
                [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
                ['valida_hasta' => now()->subDay()->toDateString()],
            ))
            ->assertSessionHasErrors('valida_hasta');
    }

    public function test_sin_permiso_de_precio_manual_se_cotiza_al_precio_de_lista(): void
    {
        $producto = $this->crearProducto(precio: 20);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1, 'precio_unitario' => 5]];

        $delVendedor = $this->cotizar($item, usuario: $this->vendedor);
        $this->assertEqualsWithDelta(20.00, (float) $delVendedor->total, 0.001);

        $delAdmin = $this->cotizar($item);
        $this->assertEqualsWithDelta(5.00, (float) $delAdmin->total, 0.001);
    }

    public function test_editar_reemplaza_el_detalle_y_conserva_el_numero(): void
    {
        $a = $this->crearProducto(precio: 10);
        $b = $this->crearProducto(precio: 30);
        $cotizacion = $this->cotizar([['presentacion_id' => $a->presentaciones->first()->id, 'cantidad' => 1]]);

        $this->actingAs($this->admin)
            ->put("/cotizaciones/{$cotizacion->id}", $this->payload([
                ['presentacion_id' => $b->presentaciones->first()->id, 'cantidad' => 3],
            ]))
            ->assertRedirect(route('cotizaciones.index'))
            ->assertSessionHas('success');

        $cotizacion->refresh();
        $this->assertSame(1, $cotizacion->numero);
        $this->assertEqualsWithDelta(90.00, (float) $cotizacion->total, 0.001);
        $this->assertSame(1, $cotizacion->detalles()->count());
        $this->assertSame($b->id, $cotizacion->detalles()->first()->producto_id);
    }

    public function test_una_cotizacion_anulada_ya_no_se_edita_ni_se_vende(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]];
        $cotizacion = $this->cotizar($item);

        $this->actingAs($this->admin)->post("/cotizaciones/{$cotizacion->id}/anular")->assertSessionHas('success');
        $this->assertSame('anulada', $cotizacion->fresh()->estado);

        $this->actingAs($this->admin)->put("/cotizaciones/{$cotizacion->id}", $this->payload($item))->assertSessionHas('error');
        $this->actingAs($this->admin)->get("/cotizaciones/{$cotizacion->id}/editar")->assertRedirect(route('cotizaciones.index'));

        $this->abrirCaja();
        $this->actingAs($this->admin)->get("/pos?cotizacion={$cotizacion->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cotizacion', null));
    }

    public function test_el_vendedor_cotiza_pero_no_anula(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $cotizacion = $this->cotizar([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]], usuario: $this->vendedor);

        $this->actingAs($this->vendedor)->get('/cotizaciones')->assertOk();
        $this->actingAs($this->vendedor)->post("/cotizaciones/{$cotizacion->id}/anular")->assertForbidden();

        $almacenero = $this->crearUsuario('almacenero', 'alm'.random_int(10000, 99999).'@test.local');
        $this->actingAs($almacenero)->get('/cotizaciones')->assertForbidden();
    }

    public function test_otra_empresa_no_ve_ni_toca_la_cotizacion(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $cotizacion = $this->cotizar([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        $this->crearEscenarioBase(); // otra empresa con su admin

        $this->actingAs($this->admin)->get("/cotizaciones/{$cotizacion->id}/pdf")->assertForbidden();
        $this->actingAs($this->admin)->post("/cotizaciones/{$cotizacion->id}/anular")->assertForbidden();
        $this->actingAs($this->admin)->get('/cotizaciones')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('cotizaciones.data', 0));
    }

    public function test_el_pos_carga_la_cotizacion_y_al_vender_queda_convertida(): void
    {
        $producto = $this->crearProducto(precio: 20);
        $presentacion = $producto->presentaciones->first();
        $this->darStock($producto, 10, 8);
        $cliente = $this->crearCliente();

        // el admin cotiza a 15 (precio especial) y lo vende un vendedor sin permiso de precio manual
        $cotizacion = $this->cotizar(
            [['presentacion_id' => $presentacion->id, 'cantidad' => 2, 'precio_unitario' => 15]],
            ['cliente_id' => $cliente->id],
        );

        $this->abrirCaja();
        \App\Models\AperturaCaja::where('caja_id', $this->caja->id)->update(['usuario_id' => $this->vendedor->id]);

        $this->actingAs($this->vendedor)->get("/pos?cotizacion={$cotizacion->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cotizacion.codigo', 'COT-000001')
                ->where('cotizacion.vigente', true)
                ->where('cotizacion.cliente.id', $cliente->id)
                ->where('cotizacion.items.0.presentacion_id', $presentacion->id)
                ->where('cotizacion.items.0.precio_unitario', 15));

        $venta = fn (array $extra) => $this->actingAs($this->vendedor)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 2, 'precio_unitario' => 15]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 30, 'referencia' => null]],
            ...$extra,
        ]);

        // sin la cotizacion, el vendedor no puede cobrar un precio distinto al de lista
        $venta([])->assertSessionHas('error');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());

        $venta(['cotizacion_id' => $cotizacion->id])->assertSessionHas('success');

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertEqualsWithDelta(30.00, (float) $comprobante->total, 0.001);
        $this->assertEqualsWithDelta(8, $this->stockDe($producto), 0.001);

        $cotizacion->refresh();
        $this->assertSame('convertida', $cotizacion->estado);
        $this->assertSame($comprobante->id, $cotizacion->comprobante_id);

        // una cotizacion ya convertida no vuelve a habilitar el precio
        $venta(['cotizacion_id' => $cotizacion->id])->assertSessionHas('error');
    }

    public function test_una_cotizacion_vencida_se_vende_pero_sin_respetar_su_precio(): void
    {
        $producto = $this->crearProducto(precio: 20);
        $presentacion = $producto->presentaciones->first();
        $this->darStock($producto, 10, 8);

        $cotizacion = $this->cotizar([['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'precio_unitario' => 15]]);
        Cotizacion::whereKey($cotizacion->id)->update([
            'fecha_emision' => now()->subDays(10)->toDateString(),
            'valida_hasta' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertSame('vencida', $cotizacion->fresh()->estadoVisible());

        $this->abrirCaja();
        \App\Models\AperturaCaja::where('caja_id', $this->caja->id)->update(['usuario_id' => $this->vendedor->id]);

        $this->actingAs($this->vendedor)->get("/pos?cotizacion={$cotizacion->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cotizacion.vigente', false));

        $this->actingAs($this->vendedor)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'cotizacion_id' => $cotizacion->id,
            'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'precio_unitario' => 15]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 15, 'referencia' => null]],
        ])->assertSessionHas('error');

        $this->actingAs($this->admin)->get('/cotizaciones?estado=vencida')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('cotizaciones.data', 1)->where('cotizaciones.data.0.estado', 'vencida'));
        $this->actingAs($this->admin)->get('/cotizaciones?estado=pendiente')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('cotizaciones.data', 0));
    }

    public function test_nuevo_rus_cotiza_sin_igv(): void
    {
        $this->empresa->update(['regimen_tributario' => 'RUS']);
        $this->admin->unsetRelation('empresa');
        $producto = $this->crearProducto(precio: 118);

        $cotizacion = $this->cotizar([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        $this->assertEqualsWithDelta(0, (float) $cotizacion->total_igv, 0.001);
        $this->assertEqualsWithDelta(118.00, (float) $cotizacion->total_exonerado, 0.001);
        $this->assertSame(Empresa::AFECTACION_RUS, trim($cotizacion->detalles()->first()->tipo_afectacion_codigo));
    }

    public function test_pdf_enlace_publico_y_correo(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $cotizacion = $this->cotizar([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        $html = view('pdf.cotizacion', [
            'cotizacion' => $cotizacion->load(['empresa', 'detalles', 'sucursal', 'usuario']),
            'empresa' => $cotizacion->empresa,
            'logo' => null,
            'letras' => 'DIEZ CON 00/100 SOLES',
        ])->render();
        $this->assertStringContainsString('COT-000001', $html);
        $this->assertStringContainsString('Incluye instalación', $html);

        // el enlace publico exige firma
        $this->get("/q/{$cotizacion->id}")->assertForbidden();
        $this->assertStringContainsString('signature=', URL::temporarySignedRoute('cotizaciones.publico', now()->addDay(), ['cotizacion' => $cotizacion->id]));

        Bus::fake();
        $this->actingAs($this->admin)
            ->post("/cotizaciones/{$cotizacion->id}/correo", ['email' => 'cliente@correo.pe'])
            ->assertSessionHas('success');
        Bus::assertDispatchedAfterResponse(EnviarCotizacionPorCorreo::class, fn ($job) => $job->email === 'cliente@correo.pe');
    }

    public function test_las_cuentas_bancarias_de_la_empresa_salen_en_la_cotizacion(): void
    {
        $this->actingAs($this->admin)->put('/empresa', [
            'razon_social' => $this->empresa->razon_social,
            'regimen_tributario' => 'MYPE',
            'rubro_codigo' => $this->empresa->rubro_codigo,
            'cuentas_bancarias' => "BCP Soles 191-1234567-0-12\nYape 977 425 905",
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('BCP Soles', (string) $this->empresa->fresh()->cuentas_bancarias);
    }
}
