<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\ComprobanteSunat;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoSerie;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\SerieCorrelativo;
use App\Models\SerieMovimiento;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use App\Services\SerieService;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use App\Services\VentaService;
use App\Support\Permisos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

/**
 * Series / IMEI (módulo opcional): cada unidad de un producto que controla serie entra con su número, se elige
 * al vender, sale en el comprobante y se puede rastrear. El stock y el FIFO siguen siendo los del producto.
 */
class SerieTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $equipo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->update(['series_activo' => true]);

        $this->equipo = $this->crearProducto(precio: 100.00, atributos: ['nombre' => 'Samsung A15 128GB', 'controla_serie' => true]);
    }

    // ------------------------------------------------------------------ ayudas

    private function comprar(Producto $producto, array $series, array $extra = [], float $costo = 60.0)
    {
        return $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => '00',
            'serie_numero' => 'N-'.random_int(100000, 999999),
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [[
                'presentacion_id' => $producto->presentaciones->first()->id,
                'cantidad' => $extra['cantidad'] ?? count($series),
                'costo_unitario' => $costo,
                'series' => $series,
                ...(isset($extra['series_alternas']) ? ['series_alternas' => $extra['series_alternas']] : []),
            ]],
        ]);
    }

    private function serie(string $numero, ?Producto $producto = null): ProductoSerie
    {
        return ProductoSerie::where('producto_id', ($producto ?? $this->equipo)->id)->where('numero_serie', $numero)->firstOrFail();
    }

    private function vender(array $numeros, array $extra = [], ?Producto $producto = null)
    {
        $producto ??= $this->equipo;
        $presentacion = $producto->presentaciones->first();
        $cantidad = $extra['cantidad'] ?? count($numeros);
        unset($extra['cantidad']);

        return $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [[
                'presentacion_id' => $presentacion->id,
                'cantidad' => $cantidad,
                'series' => array_map(fn ($n) => $this->serie($n, $producto)->id, $numeros),
            ]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => round($cantidad * (float) $presentacion->precio_venta, 2), 'referencia' => null]],
            ...$extra,
        ]);
    }

    private function ultimaVenta(): Comprobante
    {
        return Comprobante::where('empresa_id', $this->empresa->id)->where('tipo_comprobante_codigo', '!=', '07')->latest('creado_en')->latest('id')->firstOrFail();
    }

    private function disponibles(?Producto $producto = null, ?string $sucursalId = null): int
    {
        return ProductoSerie::where('producto_id', ($producto ?? $this->equipo)->id)
            ->where('sucursal_id', $sucursalId ?? $this->sucursal->id)
            ->where('estado', 'disponible')
            ->count();
    }

    /** El invariante del módulo: series disponibles en la sucursal == stock del producto. */
    private function assertInvariante(int $esperado, ?Producto $producto = null): void
    {
        $producto ??= $this->equipo;
        $this->assertSame($esperado, $this->disponibles($producto), 'series disponibles');
        $this->assertSame((float) $esperado, $this->stockDe($producto), 'stock');
    }

    /** Empresa con facturación electrónica y serie de boletas; la SUNAT simulada acepta todo. */
    private function activarBoletas(): EnviadorSunatFalso
    {
        Storage::fake('local');
        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA',
            'clave_certificado' => 'clave123',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos',
            'entorno_sunat' => 'beta',
            'facturacion_electronica' => true,
        ]);
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03', 'serie' => 'B001', 'correlativo' => 0,
        ]);
        $enviador = new EnviadorSunatFalso;
        $enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'HASH');
        $this->app->instance(EnviadorSunat::class, $enviador);

        return $enviador;
    }

    private function payloadProducto(array $extra = []): array
    {
        $unidad = UnidadMedida::query()->value('codigo');

        return [
            'codigo_interno' => 'SER-'.random_int(100000, 999999),
            'nombre' => 'Equipo '.random_int(1000, 9999),
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => $unidad,
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_serie' => true,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            'presentaciones' => [[
                'id' => null, 'nombre' => 'Unidad', 'unidad_codigo' => $unidad, 'factor_conversion' => 1, 'precio_venta' => 500,
                'precio_mayorista' => null, 'cantidad_mayorista' => null, 'codigo_barras' => null, 'es_default' => true,
            ]],
            ...$extra,
        ];
    }

    // ------------------------------------------------------------------ compras

    public function test_la_compra_exige_tantas_series_como_unidades_y_las_registra(): void
    {
        // 3 unidades con 2 series: no entra nada
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'], ['cantidad' => 3])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'controla serie') && str_contains($e, '3'));
        $this->assertSame(0, Compra::where('empresa_id', $this->empresa->id)->count());
        $this->assertInvariante(0);

        // se guardan en mayúsculas y sin espacios; el segundo IMEI es opcional
        $this->comprar($this->equipo, [' imei-1 ', 'IMEI -2', 'imei-3'], ['series_alternas' => ['alt-1', null, '']])
            ->assertRedirect(route('compras.index'))->assertSessionHas('success');

        $series = ProductoSerie::where('producto_id', $this->equipo->id)->orderBy('numero_serie')->get();
        $this->assertSame(['IMEI-1', 'IMEI-2', 'IMEI-3'], $series->pluck('numero_serie')->all());
        $this->assertSame(['ALT-1', null, null], $series->pluck('serie_alterna')->all());
        $this->assertSame(['disponible'], $series->pluck('estado')->unique()->all());
        $this->assertEqualsWithDelta(60.0, (float) $series[0]->costo_unitario, 0.0001);
        $this->assertNotNull($series[0]->compra_detalle_id);
        $this->assertSame(3, SerieMovimiento::whereIn('serie_id', $series->pluck('id'))->where('tipo', 'compra')->count());
        $this->assertInvariante(3);

        // la pantalla de compra sabe qué productos piden series
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p->where('productos.0.controla_serie', true));
    }

    public function test_la_compra_no_acepta_una_serie_repetida_en_el_pedido_ni_en_la_base(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'imei-1'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-1') && str_contains($e, 'repetida'));
        $this->assertInvariante(0);

        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');

        // ya está en el negocio: no entra otra vez y la compra completa se revierte
        $this->comprar($this->equipo, ['IMEI-9', 'IMEI-2'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-2') && str_contains($e, 'ya está registrada'));
        $this->assertSame(1, Compra::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, ProductoSerie::where('numero_serie', 'IMEI-9')->count());
        $this->assertInvariante(2);

        // tampoco como segundo IMEI de otra unidad
        $this->comprar($this->equipo, ['IMEI-5'], ['series_alternas' => ['IMEI-1']])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'choca'));
        $this->assertInvariante(2);
    }

    public function test_una_serie_que_salio_y_vuelve_a_entrar_se_reactiva_sin_duplicarse(): void
    {
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');
        $serie = $this->serie('IMEI-1');

        $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", [
            'direccion' => 'salida', 'cantidad' => 1, 'serie_ids' => [$serie->id], 'motivo' => 'pantalla rota',
        ])->assertSessionHas('success');
        $this->assertSame('baja', $serie->fresh()->estado);
        $this->assertInvariante(0);

        // el proveedor lo repuso: vuelve a entrar con la misma serie
        $this->comprar($this->equipo, ['IMEI-1'], costo: 55)->assertSessionHas('success');
        $this->assertSame(1, ProductoSerie::where('producto_id', $this->equipo->id)->count());
        $this->assertSame('disponible', $serie->fresh()->estado);
        $this->assertSame(['compra', 'baja', 'compra'], $serie->movimientos()->orderBy('creado_en')->orderBy('id')->pluck('tipo')->all());
        $this->assertInvariante(1);
    }

    public function test_no_se_anula_la_compra_si_una_de_sus_series_ya_se_vendio(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->comprar($this->equipo, ['IMEI-3'])->assertSessionHas('success');
        $segunda = Compra::where('empresa_id', $this->empresa->id)->latest('creado_en')->latest('id')->firstOrFail();
        $this->abrirCaja();

        // se vende la unidad de la SEGUNDA compra: el FIFO consume la capa de la primera, así que la capa de la
        // segunda sigue completa y solo la serie delata que esa mercadería ya salió
        $this->vender(['IMEI-3'])->assertSessionHas('success');
        $capa = CapaCosto::whereIn('compra_detalle_id', $segunda->detalles()->pluck('id'))->firstOrFail();
        $this->assertEqualsWithDelta((float) $capa->cantidad_inicial, (float) $capa->cantidad_restante, 0.0001);

        $this->actingAs($this->admin)->post("/compras/{$segunda->id}/anular", ['motivo' => 'error'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-3') && str_contains($e, 'ya se vendió'));
        $this->assertSame('registrada', $segunda->fresh()->estado);
        $this->assertInvariante(2);

        // una compra con todas sus unidades en su sitio sí se anula: las series quedan de baja
        $this->comprar($this->equipo, ['IMEI-4', 'IMEI-5'])->assertSessionHas('success');
        $tercera = Compra::where('empresa_id', $this->empresa->id)->latest('creado_en')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/compras/{$tercera->id}/anular", ['motivo' => 'error'])->assertSessionHas('success');

        $this->assertSame('baja', $this->serie('IMEI-4')->estado);
        $this->assertSame('baja', $this->serie('IMEI-5')->estado);
        $this->assertSame(1, $this->serie('IMEI-4')->movimientos()->where('tipo', 'compra_anulada')->count());
        $this->assertInvariante(2);
    }

    // ------------------------------------------------------------------ ajustes de stock

    public function test_el_ajuste_de_entrada_exige_series_y_el_de_salida_las_da_de_baja(): void
    {
        $ajustar = fn (array $datos) => $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", $datos);

        $ajustar(['direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 50])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'controla serie') && str_contains($e, '2'));
        $ajustar(['direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 50, 'series' => ['AJ-1']])->assertSessionHas('error');
        $ajustar(['direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 50, 'series' => ['AJ-1', 'aj-1']])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'repetida'));
        $this->assertInvariante(0);

        $ajustar(['direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 50, 'series' => ['aj-1', 'AJ-2'], 'series_alternas' => [null, 'AJ-2B']])
            ->assertSessionHas('success');
        $this->assertSame('ajuste_entrada', $this->serie('AJ-1')->movimientos()->value('tipo'));
        $this->assertSame('AJ-2B', $this->serie('AJ-2')->serie_alterna);
        $this->assertNull($this->serie('AJ-1')->compra_detalle_id);
        $this->assertInvariante(2);

        // el kardex lista las unidades disponibles, como hace con los lotes
        $this->actingAs($this->admin)->getJson("/stock/{$this->equipo->id}/kardex")
            ->assertOk()->assertJsonCount(2, 'series')->assertJsonPath('series.0.numero_serie', 'AJ-1');

        // salida: hay que decir qué unidad sale
        $ajustar(['direccion' => 'salida', 'cantidad' => 1])->assertSessionHas('error', fn ($e) => str_contains($e, 'elige'));
        $this->assertInvariante(2);

        $ajustar(['direccion' => 'salida', 'cantidad' => 1, 'serie_ids' => [$this->serie('AJ-1')->id], 'motivo' => 'equipo dañado'])
            ->assertSessionHas('success');
        $this->assertSame('baja', $this->serie('AJ-1')->estado);
        $this->assertSame('disponible', $this->serie('AJ-2')->estado);
        $this->assertInvariante(1);

        // una unidad ya dada de baja no sale dos veces
        $ajustar(['direccion' => 'salida', 'cantidad' => 1, 'serie_ids' => [$this->serie('AJ-1')->id]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'AJ-1') && str_contains($e, 'ya no está disponible'));
        $this->assertInvariante(1);
    }

    // ------------------------------------------------------------------ ventas

    public function test_la_venta_exige_elegir_la_serie_y_la_marca_vendida(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2', 'IMEI-3'])->assertSessionHas('success');
        $this->abrirCaja();

        // sin series, o con menos de las que se venden, no hay venta
        $this->venderContado($this->equipo->presentaciones->first(), 1)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'serie / IMEI'));
        $this->vender(['IMEI-1'], ['cantidad' => 2])->assertSessionHas('error', fn ($e) => str_contains($e, 'las 2 unidades'));
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertInvariante(3);

        $this->vender(['IMEI-1', 'IMEI-3'])->assertSessionHas('success');

        $venta = $this->ultimaVenta();
        $detalle = $venta->detalles()->firstOrFail();
        $this->assertSame('vendida', $this->serie('IMEI-1')->estado);
        $this->assertSame($detalle->id, $this->serie('IMEI-1')->comprobante_detalle_id);
        $this->assertSame('vendida', $this->serie('IMEI-3')->estado);
        $this->assertSame('disponible', $this->serie('IMEI-2')->estado);
        $this->assertSame(['IMEI-1', 'IMEI-3'], $detalle->series->pluck('numero_serie')->all());
        // la descripción guardada queda limpia; las series se agregan al imprimir
        $this->assertSame('Samsung A15 128GB', $detalle->descripcion);
        $this->assertSame('Samsung A15 128GB S/N: IMEI-1, IMEI-3', $detalle->descripcionCompleta());
        // el costo sigue siendo el FIFO del producto
        $this->assertEqualsWithDelta(60.0, (float) $detalle->costo_unitario, 0.0001);
        $this->assertInvariante(1);
    }

    public function test_las_series_salen_en_el_ticket_el_a4_y_el_xml(): void
    {
        $enviador = $this->activarBoletas();
        $this->comprar($this->equipo, ['356789012345678'], ['series_alternas' => ['356789012345686']])->assertSessionHas('success');
        $this->abrirCaja();

        $this->vender(['356789012345678'], ['tipo_comprobante_codigo' => '03'])->assertSessionHas('success');
        $venta = $this->ultimaVenta();

        // ticket (versión HTML): la serie y su segundo IMEI, en su propio renglón bajo la línea
        $this->actingAs($this->admin)->get("/comprobantes/{$venta->id}/ticket?formato=html")
            ->assertOk()->assertSeeInOrder(['Samsung A15 128GB', 'S/N:', '356789012345678 /', '356789012345686']);

        // A4: se genera y su plantilla imprime la descripción con series
        $pdf = $this->actingAs($this->admin)->get("/comprobantes/{$venta->id}/a4")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $html = view('pdf.comprobante-a4', [
            'comprobante' => $venta->fresh(['empresa', 'detalles', 'pagos.medioPago', 'sucursal', 'sunat']),
            'empresa' => $this->empresa, 'numero' => 'B001-000001', 'logo' => null, 'qr' => null, 'hash' => null,
            'letras' => 'CIEN CON 00/100 SOLES', 'motivoNota' => null, 'abono' => null,
        ])->render();
        $this->assertStringContainsString('S/N: 356789012345678 / 356789012345686', $html);

        // XML a SUNAT: la serie principal en la descripción de la línea
        $this->assertSame('Samsung A15 128GB S/N: 356789012345678', $enviador->ultimoInvoice->getDetails()[0]->getDescripcion());
        $this->assertSame('aceptado', ComprobanteSunat::find($venta->id)->estado);
    }

    public function test_no_se_vende_una_serie_de_otra_sucursal_de_otra_empresa_ni_ya_vendida(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->abrirCaja();
        $presentacion = $this->equipo->presentaciones->first();
        $venderConId = fn (string $serieId) => $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'series' => [$serieId]]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 100, 'referencia' => null]],
        ]);

        // otra sucursal de la misma empresa
        $sede2 = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sede 2', 'activo' => true]);
        $lejana = ProductoSerie::create([
            'empresa_id' => $this->empresa->id, 'producto_id' => $this->equipo->id, 'sucursal_id' => $sede2->id, 'numero_serie' => 'IMEI-SEDE2',
        ]);
        $venderConId($lejana->id)->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-SEDE2') && str_contains($e, 'ya no está disponible'));

        // otra empresa (aunque adivinen el id)
        $otra = Empresa::create([
            'ruc' => '20'.random_int(10000000, 99999999).'3', 'razon_social' => 'Otra Empresa SAC', 'regimen_tributario' => 'MYPE',
            'rubro_codigo' => Rubro::query()->value('codigo'), 'activo' => true,
        ]);
        $sucursalOtra = Sucursal::create(['empresa_id' => $otra->id, 'codigo_sunat' => '0000', 'nombre' => 'Principal', 'activo' => true]);
        $productoOtro = Producto::create([
            'empresa_id' => $otra->id, 'codigo_interno' => 'X-1', 'nombre' => 'Equipo ajeno', 'unidad_base_codigo' => $this->equipo->unidad_base_codigo,
            'tipo_afectacion_codigo' => $this->equipo->tipo_afectacion_codigo, 'permite_fraccion' => false, 'controla_lote' => false,
            'controla_serie' => true, 'controla_stock' => true, 'stock_minimo' => 0, 'activo' => true,
        ]);
        $ajena = ProductoSerie::create([
            'empresa_id' => $otra->id, 'producto_id' => $productoOtro->id, 'sucursal_id' => $sucursalOtra->id, 'numero_serie' => 'IMEI-AJENO',
        ]);
        $venderConId($ajena->id)->assertSessionHas('error', fn ($e) => str_contains($e, 'no existe o no es de ese producto'));
        $this->assertSame('disponible', $ajena->fresh()->estado);

        // ya vendida
        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $this->vender(['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-1') && str_contains($e, 'ya no está disponible'));

        // la misma unidad dos veces en la misma venta
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [
                ['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'series' => [$this->serie('IMEI-2')->id]],
                ['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'series' => [$this->serie('IMEI-2')->id]],
            ],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 200, 'referencia' => null]],
        ])->assertSessionHas('error');

        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertInvariante(1);
    }

    public function test_anular_la_venta_devuelve_la_serie_y_el_comprobante_conserva_su_rastro(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->abrirCaja();
        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $this->assertInvariante(1);

        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/anular", ['motivo' => 'El cliente desistió'])
            ->assertSessionHas('success');

        $serie = $this->serie('IMEI-1');
        $this->assertSame('disponible', $serie->estado);
        $this->assertNull($serie->comprobante_detalle_id);
        $this->assertSame(['compra', 'venta', 'venta_anulada'], $serie->movimientos()->orderBy('creado_en')->orderBy('id')->pluck('tipo')->all());
        $this->assertInvariante(2);

        // el comprobante anulado sigue mostrando qué unidad llevaba, y la unidad se puede vender de nuevo
        $this->assertSame('Samsung A15 128GB S/N: IMEI-1', $venta->detalles()->first()->descripcionCompleta());
        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $this->assertSame('vendida', $serie->fresh()->estado);
        $this->assertInvariante(1);
    }

    public function test_convertir_la_nota_de_venta_en_boleta_conserva_las_series(): void
    {
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');
        $this->abrirCaja();
        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $venta = $this->ultimaVenta();

        $enviador = $this->activarBoletas();
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/convertir", ['tipo' => '03', 'cliente_id' => null])
            ->assertSessionHas('success');

        $this->assertSame('03', $venta->fresh()->tipo_comprobante_codigo);
        $this->assertSame('vendida', $this->serie('IMEI-1')->estado);
        $this->assertSame('Samsung A15 128GB S/N: IMEI-1', $venta->detalles()->first()->descripcionCompleta());
        $this->assertSame('Samsung A15 128GB S/N: IMEI-1', $enviador->ultimoInvoice->getDetails()[0]->getDescripcion());
    }

    public function test_la_nota_de_credito_total_devuelve_todas_las_series(): void
    {
        $this->activarBoletas();
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2', 'IMEI-3'])->assertSessionHas('success');
        $this->abrirCaja(500);
        $this->vender(['IMEI-1', 'IMEI-2'], ['tipo_comprobante_codigo' => '03'])->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $detalle = $venta->detalles()->firstOrFail();
        $this->assertInvariante(1);

        // devolución por ítem sin decir qué unidad vuelve: no pasa
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", [
            'motivo' => '07', 'items' => [['detalle_id' => $detalle->id, 'cantidad' => 1]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'serie / IMEI') && str_contains($e, 'la unidad que vuelve'));
        $this->assertSame(0, Comprobante::where('comprobante_ref_id', $venta->id)->count());
        $this->assertInvariante(1);

        // anulación de la operación: vuelven todas
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", ['motivo' => '01'])->assertSessionHas('success');
        $nota = Comprobante::where('comprobante_ref_id', $venta->id)->firstOrFail();

        $this->assertSame('disponible', $this->serie('IMEI-1')->estado);
        $this->assertSame('disponible', $this->serie('IMEI-2')->estado);
        $this->assertNull($this->serie('IMEI-1')->comprobante_detalle_id);
        $this->assertSame(1, $this->serie('IMEI-1')->movimientos()->where('tipo', 'devolucion')->where('referencia_id', $nota->id)->count());
        // la nota también dice qué unidades devuelve
        $this->assertSame('Samsung A15 128GB S/N: IMEI-1, IMEI-2', $nota->detalles()->first()->descripcionCompleta());
        $this->assertInvariante(3);
    }

    // ------------------------------------------------------------------ consulta

    public function test_buscar_el_imei_muestra_el_cliente_el_comprobante_y_la_historia(): void
    {
        $cliente = $this->crearCliente();
        $this->comprar($this->equipo, ['356789012345678', 'IMEI-2'], ['series_alternas' => ['356789012345686', null]])->assertSessionHas('success');
        $this->abrirCaja();
        $this->vender(['356789012345678'], ['cliente_id' => $cliente->id])->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $numero = "{$venta->serie}-".str_pad((string) $venta->correlativo, 6, '0', STR_PAD_LEFT);

        // por la serie (con espacios y minúsculas da igual) y por el segundo IMEI
        foreach ([' 356789012345678 ', '356789012345686'] as $buscado) {
            $this->actingAs($this->admin)->get('/series?numero='.urlencode($buscado))->assertInertia(fn (Assert $p) => $p
                ->component('Series/Index')
                ->has('fichas', 1)
                ->where('fichas.0.numero_serie', '356789012345678')
                ->where('fichas.0.estado', 'vendida')
                ->where('fichas.0.producto.nombre', 'Samsung A15 128GB')
                ->where('fichas.0.sucursal', 'Principal')
                ->where('fichas.0.venta.cliente', $cliente->nombre)
                ->where('fichas.0.venta.numero', $numero)
                ->where('fichas.0.venta.fecha', now()->toDateString())
                ->where('fichas.0.compra.fecha', now()->toDateString())
                ->has('fichas.0.historial', 2)
                ->where('fichas.0.historial.0.tipo', 'compra')
                ->where('fichas.0.historial.1.tipo', 'venta')
                ->where('fichas.0.historial.1.detalle', fn ($d) => str_contains($d, $numero) && str_contains($d, $cliente->nombre)));
        }

        // la misma ficha en JSON
        $this->actingAs($this->admin)->getJson('/series/buscar?numero=356789012345678')
            ->assertOk()->assertJsonPath('fichas.0.venta.cliente', $cliente->nombre);

        // una serie que no existe y el listado con sus filtros
        $this->actingAs($this->admin)->get('/series?numero=NO-EXISTE')->assertInertia(fn (Assert $p) => $p->has('fichas', 0)->has('series.data', 2));
        $this->actingAs($this->admin)->get('/series?estado=disponible')->assertInertia(fn (Assert $p) => $p
            ->has('series.data', 1)->where('series.data.0.numero_serie', 'IMEI-2'));
        $this->actingAs($this->admin)->get('/series?estado=vendida&producto='.$this->equipo->id)->assertInertia(fn (Assert $p) => $p->has('series.data', 1));

        // quien no ve costos no los recibe en la ficha
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(10000, 99999).'@test.local');
        $this->actingAs($vendedor)->get('/series?numero=IMEI-2')->assertInertia(fn (Assert $p) => $p->where('fichas.0.costo_unitario', null));
        $this->actingAs($this->admin)->get('/series?numero=IMEI-2')->assertInertia(fn (Assert $p) => $p->where('fichas.0.costo_unitario', fn ($c) => (float) $c === 60.0));
    }

    public function test_el_pos_ofrece_las_unidades_de_su_sucursal_y_encuentra_la_escaneada(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'], ['series_alternas' => ['ALT-1', null]])->assertSessionHas('success');

        // sin caja abierta no hay unidades que ofrecer
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id)->assertOk()->assertJsonCount(0, 'series');
        $this->abrirCaja();

        // el catálogo del POS no carga las series: solo sabe qué producto las pide
        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (Assert $p) => $p
            ->where('productos.0.controla_serie', true)->missing('productos.0.series'));

        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id)
            ->assertOk()->assertJsonCount(2, 'series')->assertJsonPath('series.0.numero_serie', 'IMEI-1');
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id.'&buscar=mei-2')
            ->assertJsonCount(1, 'series')->assertJsonPath('series.0.numero_serie', 'IMEI-2');

        // escaneo: por la serie o por el segundo IMEI llega la unidad con su producto
        $this->actingAs($this->admin)->getJson('/pos/series?numero=alt-1')
            ->assertOk()->assertJsonCount(1, 'encontradas')
            ->assertJsonPath('encontradas.0.numero_serie', 'IMEI-1')
            ->assertJsonPath('encontradas.0.producto_id', $this->equipo->id);
        $this->actingAs($this->admin)->getJson('/pos/series?numero=NADA')->assertJsonCount(0, 'encontradas')->assertJsonPath('aviso', null);

        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $this->actingAs($this->admin)->getJson('/pos/series?numero=IMEI-1')
            ->assertJsonCount(0, 'encontradas')->assertJsonPath('aviso', fn ($a) => str_contains($a, 'ya está vendida'));
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id)->assertJsonCount(1, 'series');
    }

    // ------------------------------------------------------------------ módulo, permisos y producto

    public function test_con_el_modulo_apagado_la_consulta_responde_403_y_controla_serie_se_ignora(): void
    {
        $this->empresa->update(['series_activo' => false]);

        $this->actingAs($this->admin)->get('/series')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/series/buscar?numero=X')->assertForbidden();
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('auth.user.empresa.series_activo', false));

        // el formulario de producto no puede marcarlo: se guarda sin serie
        $this->actingAs($this->admin)->post('/productos', $this->payloadProducto(['nombre' => 'Sin modulo']))->assertSessionHas('success');
        $this->assertFalse(Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Sin modulo')->firstOrFail()->controla_serie);

        // y un producto que ya lo tenía lo conserva aunque el formulario no lo mande
        $pres = $this->equipo->presentaciones->first();
        $payload = $this->payloadProducto(['codigo_interno' => $this->equipo->codigo_interno, 'nombre' => 'Samsung A15 128GB', 'controla_serie' => false]);
        $payload['presentaciones'][0]['id'] = $pres->id;
        $this->actingAs($this->admin)->put("/productos/{$this->equipo->id}", $payload)->assertSessionHas('success');
        $this->assertTrue($this->equipo->fresh()->controla_serie);

        // el interruptor de Configuración → Empresa lo enciende
        $datos = ['razon_social' => $this->empresa->razon_social, 'regimen_tributario' => 'MYPE', 'rubro_codigo' => $this->empresa->rubro_codigo];
        $this->actingAs($this->admin)->put('/empresa', [...$datos, 'series_activo' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->empresa->fresh()->series_activo);
        $this->actingAs($this->admin)->get('/empresa')->assertInertia(fn (Assert $p) => $p->where('empresa.series_activo', true));
        $this->actingAs($this->admin)->get('/series')->assertOk();
    }

    public function test_la_consulta_pide_el_permiso_series_ver(): void
    {
        // los roles que venden o manejan almacén lo tienen; la ruta lo exige junto con el módulo activo
        foreach (['admin', 'cajero', 'vendedor', 'almacenero'] as $rol) {
            $this->assertTrue(Permisos::tiene($rol, 'series.ver'), $rol);
        }
        $this->assertFalse(Permisos::tiene('rol-inexistente', 'series.ver'));

        foreach (['series.index', 'series.buscar'] as $ruta) {
            $middleware = Route::getRoutes()->getByName($ruta)->gatherMiddleware();
            $this->assertContains('can:series.ver', $middleware);
            $this->assertContains('series.activas', $middleware);
        }

        // un usuario cuyo rol no tiene el permiso recibe 403
        Rol::create(['codigo' => 'sin-series', 'nombre' => 'Rol sin series (test)']);
        $sinPermiso = $this->crearUsuario('sin-series', 'sp'.random_int(10000, 99999).'@test.local');
        $this->actingAs($sinPermiso)->get('/series')->assertForbidden();
        $this->actingAs($this->admin)->get('/series')->assertOk();
    }

    public function test_el_producto_con_serie_no_admite_fraccion_lote_cajas_ni_stock_inicial(): void
    {
        $crear = fn (array $extra) => $this->actingAs($this->admin)->post('/productos', $this->payloadProducto($extra));

        $crear(['permite_fraccion' => true])->assertSessionHasErrors('controla_serie');
        $crear(['controla_lote' => true])->assertSessionHasErrors('controla_serie');
        $crear(['controla_stock' => false])->assertSessionHasErrors('controla_serie');

        $conCaja = $this->payloadProducto();
        $conCaja['presentaciones'][] = [...$conCaja['presentaciones'][0], 'nombre' => 'Caja x10', 'factor_conversion' => 10, 'es_default' => false];
        $this->actingAs($this->admin)->post('/productos', $conCaja)->assertSessionHasErrors('presentaciones.1.factor_conversion');

        $conStock = $this->payloadProducto();
        $conStock['presentaciones'][0] += ['stock_inicial' => 3, 'precio_compra' => 300];
        $this->actingAs($this->admin)->post('/productos', $conStock)
            ->assertSessionHasErrors(['presentaciones.0.stock_inicial' => 'Este producto controla serie / IMEI: carga el stock con un ajuste de stock indicando las series.']);

        $crear(['nombre' => 'Laptop con serie'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertTrue(Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Laptop con serie')->firstOrFail()->controla_serie);
    }

    public function test_activar_serie_con_stock_deja_unidades_por_regularizar_y_desactivarla_pide_que_no_queden(): void
    {
        // un producto normal con stock SÍ puede pasar a controlar serie: sus unidades quedan por regularizar
        $normal = $this->crearProducto(precio: 50.0, atributos: ['nombre' => 'Parlante']);
        $this->darStock($normal, 4, 20);
        $editar = function (Producto $producto, bool $controlaSerie) {
            $payload = $this->payloadProducto(['codigo_interno' => $producto->codigo_interno, 'nombre' => $producto->nombre, 'controla_serie' => $controlaSerie]);
            $payload['presentaciones'][0]['id'] = $producto->presentaciones->first()->id;

            return $this->actingAs($this->admin)->put("/productos/{$producto->id}", $payload);
        };

        $editar($normal, true)->assertSessionHasNoErrors();
        $this->assertTrue($normal->fresh()->controla_serie);
        $this->assertSame(4, app(SerieService::class)->sinSerie($normal->fresh(), $this->sucursal->id));
        $this->actingAs($this->admin)->getJson("/stock/{$normal->id}/kardex")->assertJsonPath('sin_serie', 4);
        $this->actingAs($this->admin)->get('/stock')->assertInertia(fn (Assert $p) => $p
            ->where('productos.data', fn ($filas) => collect($filas)->firstWhere('id', $normal->id)['sin_serie'] === 4));

        // lo que no puede haber es mercadería en camino (llegaría sin número)
        $otro = $this->crearProducto(precio: 30.0, atributos: ['nombre' => 'Audífono']);
        $this->darStock($otro, 3, 10);
        $sede2 = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sede 2', 'activo' => true]);
        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $sede2->id, 'observacion' => null, 'items' => [['producto_id' => $otro->id, 'cantidad' => 1]],
        ])->assertSessionHas('success');
        $editar($otro, true)->assertSessionHasErrors('controla_serie');
        $this->assertFalse($otro->fresh()->controla_serie);

        // con unidades en stock no se puede quitar el control de serie
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');
        $editar($this->equipo, false)->assertSessionHasErrors('controla_serie');
        $this->assertTrue($this->equipo->fresh()->controla_serie);

        $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", ['direccion' => 'salida', 'cantidad' => 1, 'serie_ids' => [$this->serie('IMEI-1')->id]])
            ->assertSessionHas('success');
        $editar($this->equipo, false)->assertSessionHasNoErrors();
        $this->assertFalse($this->equipo->fresh()->controla_serie);
    }

    // ------------------------------------------------------------------ lo que no cambia y lo que se bloquea

    public function test_un_producto_sin_serie_se_compra_ajusta_vende_y_anula_igual_que_antes(): void
    {
        $normal = $this->crearProducto(precio: 10.0, atributos: ['nombre' => 'Cable USB']);
        $presentacion = $normal->presentaciones->first();

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-1', 'fecha' => now()->toDateString(), 'es_credito' => false,
            // aunque lleguen series, un producto sin serie las ignora
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 5, 'costo_unitario' => 4, 'series' => ['X-1']]],
        ])->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/stock/{$normal->id}/ajustar", ['direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 4])->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/stock/{$normal->id}/ajustar", ['direccion' => 'salida', 'cantidad' => 1])->assertSessionHas('success');
        $this->assertSame(6.0, $this->stockDe($normal));

        $this->abrirCaja();
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 2, 'series' => [(string) Str::uuid()]]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 20, 'referencia' => null]],
        ])->assertSessionHas('success');
        $this->venderContado($presentacion, 1)->assertSessionHas('success');
        $this->assertSame(3.0, $this->stockDe($normal));

        $venta = $this->ultimaVenta();
        $detalle = $venta->detalles()->firstOrFail();
        $this->assertSame('Cable USB', $detalle->descripcion);
        $this->assertSame($detalle->descripcion, $detalle->descripcionCompleta());
        $this->actingAs($this->admin)->get("/comprobantes/{$venta->id}/ticket?formato=html")->assertOk()->assertSee('Cable USB')->assertDontSee('S/N:');

        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/anular", ['motivo' => 'prueba'])->assertSessionHas('success');
        $this->assertSame(4.0, $this->stockDe($normal));

        // nada de esto dejó rastro en las tablas de series
        $this->assertSame(0, ProductoSerie::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, SerieMovimiento::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_cotizar_o_encargar_un_producto_con_serie_no_pide_la_serie(): void
    {
        // cotizaciones y pedidos calculan sus líneas sin series: se eligen después, al vender en el POS
        [$lineas, $totales] = app(VentaService::class)->calcularLineas($this->empresa->id, [
            ['presentacion_id' => $this->equipo->presentaciones->first()->id, 'cantidad' => 2],
        ]);

        $this->assertSame([], $lineas[0]['series']);
        $this->assertEqualsWithDelta(200.0, $totales['total'], 0.001);
    }

    public function test_mezclar_un_producto_con_serie_se_bloquea_y_transferirlo_pide_elegir_las_unidades(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $destino = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sede 2', 'activo' => true]);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $destino->id, 'observacion' => null,
            'items' => [['producto_id' => $this->equipo->id, 'cantidad' => 1]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'controla serie / IMEI') && str_contains($e, 'la unidad que envías'));
        $this->assertInvariante(2);

        // mezclas: ni como insumo ni como producto obtenido
        $this->empresa->update(['mezclas_activo' => true]);
        $this->admin->unsetRelation('empresa'); // el usuario ya tenía cargada la empresa con el módulo apagado
        $insumo = $this->crearProducto(precio: 5.0, atributos: ['nombre' => 'Insumo']);
        $this->darStock($insumo, 10, 2);
        $obtenido = $this->crearProducto(precio: 20.0, atributos: ['nombre' => 'Kit armado']);
        $mezcla = fn (Producto $producto, Producto $de) => $this->actingAs($this->admin)->post('/mezclas', [
            'producto_id' => $producto->id, 'presentacion_id' => null, 'cantidad' => 1, 'otros_costos' => 0,
            'insumos' => [['producto_id' => $de->id, 'presentacion_id' => null, 'cantidad' => 1]],
        ]);

        $mezcla($obtenido, $this->equipo)->assertSessionHas('error', fn ($e) => str_contains($e, 'no puede ser insumo'));
        $mezcla($this->equipo, $insumo)->assertSessionHas('error', fn ($e) => str_contains($e, 'no se puede obtener de una mezcla'));
        $this->assertInvariante(2);
        $this->assertSame(10.0, $this->stockDe($insumo));
    }

    public function test_registrarse_con_rubro_celulares_deja_las_series_activas(): void
    {
        Mail::fake();
        Cache::flush();

        $registrar = function (string $ruc, string $rubro): Empresa {
            auth()->logout();
            $this->post('/registro', [
                'ruc' => $ruc,
                'razon_social' => 'Negocio '.$rubro,
                'nombre_comercial' => null,
                'rubro_codigo' => $rubro,
                'regimen_tributario' => 'RUS',
                'direccion' => null,
                'ubigeo' => null,
                'nombre_completo' => 'Dueño',
                'email' => "dueno{$rubro}".random_int(10000, 99999).'@test.local',
                'password' => 'clave2026',
                'password_confirmation' => 'clave2026',
            ])->assertSessionHasNoErrors()->assertRedirect('/dashboard');

            return Empresa::where('ruc', $ruc)->firstOrFail();
        };

        $celulares = $registrar($this->rucValido('2060000011'), 'celulares');
        $this->assertTrue($celulares->series_activo);
        $this->assertTrue($celulares->variantes_activo);
        // ropa enciende variantes pero no series
        $this->assertFalse($registrar($this->rucValido('2060000012'), 'ropa')->series_activo);
    }

    /** Completa los 10 primeros dígitos de un RUC con su dígito verificador (módulo 11). */
    private function rucValido(string $base): string
    {
        $suma = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $factor) {
            $suma += (int) $base[$i] * $factor;
        }

        return $base.((11 - ($suma % 11)) % 10);
    }

    // ------------------------------------------------------------------ el invariante, de punta a punta

    public function test_las_series_disponibles_siempre_igualan_al_stock(): void
    {
        $this->assertInvariante(0);

        $this->comprar($this->equipo, ['A', 'B', 'C'])->assertSessionHas('success');                       // compra
        $this->assertInvariante(3);

        $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", [                         // ajuste de entrada
            'direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 55, 'series' => ['D', 'E'],
        ])->assertSessionHas('success');
        $this->assertInvariante(5);

        $this->abrirCaja();
        $this->vender(['A', 'D'])->assertSessionHas('success');                                            // venta
        $this->assertInvariante(3);

        $this->actingAs($this->admin)->post("/comprobantes/{$this->ultimaVenta()->id}/anular", ['motivo' => 'x'])   // anulación
            ->assertSessionHas('success');
        $this->assertInvariante(5);

        $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", [                         // baja
            'direccion' => 'salida', 'cantidad' => 2, 'serie_ids' => [$this->serie('B')->id, $this->serie('E')->id],
        ])->assertSessionHas('success');
        $this->assertInvariante(3);

        $this->vender(['C'])->assertSessionHas('success');
        $this->assertInvariante(2);

        // y las capas de costo cuadran con el stock, como siempre
        $this->assertEqualsWithDelta(2.0, (float) CapaCosto::where('producto_id', $this->equipo->id)->sum('cantidad_restante'), 0.0001);
        $this->assertSame(1, ComprobanteDetalle::where('producto_id', $this->equipo->id)->whereHas('comprobante', fn ($q) => $q->where('estado', 'emitido'))->count());
    }
}
