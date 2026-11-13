<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\ProductoSerie;
use App\Models\Rubro;
use App\Models\SerieCorrelativo;
use App\Models\SerieMovimiento;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\TipoAfectacionIgv;
use App\Models\Transferencia;
use App\Models\UnidadMedida;
use App\Services\SerieService;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

/**
 * Series / IMEI, segunda parte: unidades sin serie y su regularización, nota de crédito por ítem eligiendo la
 * unidad, transferencias con series y las correcciones de la revisión (caracteres, ids repetidos, ticket, XML).
 */
class SerieRegularizacionTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $equipo;

    private Sucursal $sede2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->update(['series_activo' => true]);

        $this->equipo = $this->crearProducto(precio: 100.00, atributos: ['nombre' => 'Samsung A15 128GB', 'controla_serie' => true]);
        $this->sede2 = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sede 2', 'activo' => true]);
    }

    // ------------------------------------------------------------------ ayudas

    private function comprar(Producto $producto, array $series, array $alternas = [])
    {
        return $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-'.random_int(100000, 999999),
            'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [[
                'presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => count($series), 'costo_unitario' => 60,
                'series' => $series, ...($alternas ? ['series_alternas' => $alternas] : []),
            ]],
        ]);
    }

    private function serie(string $numero, ?Producto $producto = null): ProductoSerie
    {
        return ProductoSerie::where('producto_id', ($producto ?? $this->equipo)->id)->where('numero_serie', $numero)->firstOrFail();
    }

    private function vender(array $numeros, array $extra = [])
    {
        $presentacion = $this->equipo->presentaciones->first();

        return $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => count($numeros), 'series' => array_map(fn ($n) => $this->serie($n)->id, $numeros)]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => count($numeros) * 100, 'referencia' => null]],
            ...$extra,
        ]);
    }

    private function ultimaVenta(): Comprobante
    {
        return Comprobante::where('empresa_id', $this->empresa->id)->where('tipo_comprobante_codigo', '!=', '07')->latest('creado_en')->latest('id')->firstOrFail();
    }

    private function disponibles(?string $sucursalId = null, ?Producto $producto = null): int
    {
        return ProductoSerie::where('producto_id', ($producto ?? $this->equipo)->id)
            ->where('sucursal_id', $sucursalId ?? $this->sucursal->id)->where('estado', 'disponible')->count();
    }

    private function stockEn(string $sucursalId, ?Producto $producto = null): float
    {
        return (float) Stock::where('producto_id', ($producto ?? $this->equipo)->id)->where('sucursal_id', $sucursalId)->value('cantidad');
    }

    /** Series disponibles == stock, en la sucursal indicada. */
    private function assertInvariante(int $esperado, ?string $sucursalId = null, ?Producto $producto = null): void
    {
        $sucursalId ??= $this->sucursal->id;
        $this->assertSame($esperado, $this->disponibles($sucursalId, $producto), 'series disponibles');
        $this->assertSame((float) $esperado, $this->stockEn($sucursalId, $producto), 'stock');
    }

    private function sinSerie(?Producto $producto = null): int
    {
        return app(SerieService::class)->sinSerie(($producto ?? $this->equipo)->fresh(), $this->sucursal->id);
    }

    private function regularizar(array $series, array $extra = [], ?Producto $producto = null)
    {
        return $this->actingAs($this->admin)->post('/stock/'.($producto ?? $this->equipo)->id.'/regularizar-series', ['series' => $series, ...$extra]);
    }

    private function activarBoletas(): EnviadorSunatFalso
    {
        Storage::fake('local');
        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA', 'clave_certificado' => 'clave123', 'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos', 'entorno_sunat' => 'beta', 'facturacion_electronica' => true,
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

    private function transferir(array $numeros, array $extra = [])
    {
        return $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->sede2->id, 'observacion' => null,
            'items' => [['producto_id' => $this->equipo->id, 'cantidad' => $extra['cantidad'] ?? count($numeros), 'series' => $extra['series'] ?? array_map(fn ($n) => $this->serie($n)->id, $numeros)]],
        ]);
    }

    // ------------------------------------------------------------------ R1: stock que vuelve sin serie

    public function test_a_una_venta_anterior_a_activar_la_serie_se_anula_y_la_unidad_queda_por_regularizar(): void
    {
        // (a) el producto se vendía sin serie; se activa la serie y después se anula aquella venta
        $normal = $this->crearProducto(precio: 50.0, atributos: ['nombre' => 'Parlante']);
        $this->darStock($normal, 1, 20);
        $this->abrirCaja();
        $this->venderContado($normal->presentaciones->first(), 1)->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $normal->update(['controla_serie' => true]);

        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/anular", ['motivo' => 'devolución'])->assertSessionHas('success');

        // volvió 1 al stock sin número: no se puede vender, pero se ve y se regulariza
        $this->assertSame(1.0, $this->stockEn($this->sucursal->id, $normal));
        $this->assertSame(1, $this->sinSerie($normal));
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$normal->id)->assertJsonCount(0, 'series');
        $this->venderContado($normal->presentaciones->first(), 1)->assertSessionHas('error', fn ($e) => str_contains($e, 'serie / IMEI'));

        $this->regularizar(['PAR-001'], producto: $normal)->assertSessionHas('success');
        $this->assertSame(0, $this->sinSerie($normal));
        $this->assertInvariante(1, producto: $normal);
        $this->assertSame('regularizacion', $this->serie('PAR-001', $normal)->movimientos()->value('tipo'));
    }

    public function test_b_si_la_serie_vendida_reingreso_la_venta_ya_no_se_anula_ni_admite_nota_de_credito(): void
    {
        $enviador = $this->activarBoletas();
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->abrirCaja(500);
        $this->vender(['IMEI-1'], ['tipo_comprobante_codigo' => '03'])->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $envios = $enviador->llamadas;

        // (b) el mismo equipo vuelve a entrar por una compra: la serie se reactiva
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');
        $this->assertSame('disponible', $this->serie('IMEI-1')->estado);
        $this->assertInvariante(2);

        // anular la venta original repondría otra unidad sin serie: se rechaza ANTES de avisar a SUNAT
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/anular", ['motivo' => 'x'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-1') && str_contains($e, 'ya volvió a ingresar'));
        $this->assertSame('emitido', $venta->fresh()->estado);
        $this->assertNull($enviador->ultimaBaja);

        // lo mismo la nota de crédito total...
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", ['motivo' => '01'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'ya volvió a ingresar'));
        // ...y (c) la parcial, que ya no encuentra la línea "con series" por el estado actual pero sí por el historial
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", [
            'motivo' => '07', 'items' => [['detalle_id' => $venta->detalles()->value('id'), 'cantidad' => 1]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'ya volvió a ingresar'));

        $this->assertSame(0, Comprobante::where('comprobante_ref_id', $venta->id)->count());
        $this->assertSame($envios, $enviador->llamadas);
        $this->assertInvariante(2);
    }

    public function test_una_venta_anulada_y_vuelta_a_vender_no_bloquea_la_anulacion_de_la_segunda(): void
    {
        // volver por la anulación de la propia venta es el camino normal: no cuenta como "reingreso por otro documento"
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');
        $this->abrirCaja();
        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $primera = $this->ultimaVenta();
        $this->actingAs($this->admin)->post("/comprobantes/{$primera->id}/anular", ['motivo' => 'x'])->assertSessionHas('success');

        $this->vender(['IMEI-1'])->assertSessionHas('success');
        $segunda = $this->ultimaVenta();
        $this->actingAs($this->admin)->post("/comprobantes/{$segunda->id}/anular", ['motivo' => 'x'])->assertSessionHas('success');

        $this->assertSame('disponible', $this->serie('IMEI-1')->estado);
        $this->assertInvariante(1);
    }

    public function test_regularizar_parcial_y_total_sin_mover_stock_ni_pasar_de_la_diferencia(): void
    {
        // 3 unidades en stock sin número (se activó la serie con stock)
        $this->darStock($this->equipo, 3, 55);
        $this->assertSame(3, $this->sinSerie());

        // más series que unidades sin número: no entra ninguna
        $this->regularizar(['R-1', 'R-2', 'R-3', 'R-4'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Solo hay 3'));
        $this->assertSame(0, ProductoSerie::where('producto_id', $this->equipo->id)->count());

        // parcial
        $this->regularizar(['r-1'], ['series_alternas' => ['r-1b']])->assertSessionHas('success');
        $this->assertSame(2, $this->sinSerie());
        $this->assertSame(1, $this->disponibles());
        $this->assertSame('R-1B', $this->serie('R-1')->serie_alterna);
        $this->assertEqualsWithDelta(55.0, (float) $this->serie('R-1')->costo_unitario, 0.001);

        // el POS solo ofrece la que ya tiene número
        $this->abrirCaja();
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id)->assertJsonCount(1, 'series');

        // repetida y exceso sobre lo que queda
        $this->regularizar(['R-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'ya está registrada'));
        $this->regularizar(['R-2', 'R-3', 'R-4'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Solo hay 2'));

        // total: el stock y las capas no se movieron en ningún momento
        $this->regularizar(['R-2', 'R-3'])->assertSessionHas('success');
        $this->assertSame(0, $this->sinSerie());
        $this->assertInvariante(3);
        $this->assertEqualsWithDelta(3.0, (float) CapaCosto::where('producto_id', $this->equipo->id)->sum('cantidad_restante'), 0.0001);
        $this->assertSame(0, MovimientoInventario::where('producto_id', $this->equipo->id)->count());
        $this->regularizar(['R-9'])->assertSessionHas('error', fn ($e) => str_contains($e, 'ya no tiene unidades sin serie'));

        // sin permiso de ajustar stock no se regulariza; un producto de otra empresa, tampoco
        $vendedor = $this->crearUsuario('vendedor', 'v'.random_int(10000, 99999).'@test.local');
        $this->actingAs($vendedor)->post("/stock/{$this->equipo->id}/regularizar-series", ['series' => ['X']])->assertForbidden();
    }

    public function test_el_ajuste_de_salida_retira_sin_serie_solo_las_unidades_que_no_tienen_numero(): void
    {
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');
        $this->darStock($this->equipo, 2, 55); // 2 unidades más sin número
        $this->assertSame(2, $this->sinSerie());
        $ajustar = fn (array $datos) => $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", ['direccion' => 'salida', ...$datos]);

        // 3 sin serie cuando solo hay 2 sin número: no
        $ajustar(['cantidad' => 3])->assertSessionHas('error', fn ($e) => str_contains($e, 'Sin serie registrada solo hay 2'));
        $this->assertSame(3.0, $this->stockEn($this->sucursal->id));

        // 1 sin número + la unidad con serie
        $ajustar(['cantidad' => 2, 'serie_ids' => [$this->serie('IMEI-1')->id]])->assertSessionHas('success');
        $this->assertSame('baja', $this->serie('IMEI-1')->estado);
        $this->assertSame(1.0, $this->stockEn($this->sucursal->id));
        $this->assertSame(1, $this->sinSerie());

        // la última sin número
        $ajustar(['cantidad' => 1])->assertSessionHas('success');
        $this->assertSame(0, $this->sinSerie());
        $this->assertInvariante(0);

        // ya no queda nada sin número: sin elegir serie no sale
        $this->comprar($this->equipo, ['IMEI-9'])->assertSessionHas('success');
        $ajustar(['cantidad' => 1])->assertSessionHas('error', fn ($e) => str_contains($e, 'elige'));
        $this->assertInvariante(1);
    }

    // ------------------------------------------------------------------ R2–R7

    public function test_el_ticket_con_series_largas_sale_en_una_sola_pagina_en_58_y_80_mm(): void
    {
        $this->comprar($this->equipo, ['356789012345671', '356789012345672', '356789012345673'], ['356789012345681', '356789012345682', '356789012345683'])
            ->assertSessionHas('success');
        // y una serie de 60 caracteres sin espacios
        $larga = str_repeat('ABCDEFGHIJ', 6);
        $otro = $this->crearProducto(precio: 100.00, atributos: ['nombre' => 'Laptop Lenovo IdeaPad Slim 5 16 pulgadas Ryzen 7', 'controla_serie' => true]);
        $this->comprar($otro, [$larga])->assertSessionHas('success');
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [
                ['presentacion_id' => $this->equipo->presentaciones->first()->id, 'cantidad' => 3, 'series' => ProductoSerie::where('producto_id', $this->equipo->id)->pluck('id')->all()],
                ['presentacion_id' => $otro->presentaciones->first()->id, 'cantidad' => 1, 'series' => [$this->serie($larga, $otro)->id]],
            ],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 400, 'referencia' => null]],
        ])->assertSessionHas('success');
        $venta = $this->ultimaVenta();

        foreach ([80, 58] as $ancho) {
            $this->caja->update(['ancho_ticket' => $ancho]);
            $pdf = $this->actingAs($this->admin)->get("/comprobantes/{$venta->id}/ticket")->assertOk()->getContent();
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf), "páginas del ticket de {$ancho} mm");
        }

        // la serie va en su propio renglón, que se parte solo: no comparte celda con los precios
        $this->actingAs($this->admin)->get("/comprobantes/{$venta->id}/ticket?formato=html")
            ->assertSee('<span>'.$larga.'</span>', false)
            // cada serie y cada segundo IMEI en su propia pieza: el ticket no parte un número a la mitad
            ->assertSee('<span>356789012345671 /</span> <span>356789012345681,</span>', false);
    }

    public function test_la_serie_solo_admite_letras_numeros_guion_punto_barra_y_guion_bajo(): void
    {
        $mensaje = 'La serie solo puede tener letras, números, guion, punto, barra o guion bajo.';

        foreach (['IMEI]]>1', "IMEI\0A", 'IMEI<1>', 'IMEI&1', 'ÑANDÚ-1'] as $mala) {
            $this->comprar($this->equipo, [$mala])->assertSessionHasErrors(['items.0.series.0' => $mensaje]);
            $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", ['direccion' => 'entrada', 'cantidad' => 1, 'costo_unitario' => 50, 'series' => [$mala]])
                ->assertSessionHasErrors(['series.0' => $mensaje]);
            $this->regularizar([$mala])->assertSessionHasErrors(['series.0' => $mensaje]);
        }
        $this->comprar($this->equipo, ['OK-1'], ['MAL]]>'])->assertSessionHasErrors(['items.0.series_alternas.0' => $mensaje]);
        $this->assertSame(0, ProductoSerie::where('producto_id', $this->equipo->id)->count());

        // lo permitido, con espacios de más que se quitan al guardar
        $this->comprar($this->equipo, [' sn/2026_a.b-1 '])->assertSessionHas('success');
        $this->assertSame('SN/2026_A.B-1', ProductoSerie::where('producto_id', $this->equipo->id)->value('numero_serie'));

        // y el servicio no confía en quien lo llame
        $this->expectExceptionMessage($mensaje);
        app(SerieService::class)->ingresar($this->equipo, $this->sucursal->id, ['A]]>B'], 1, 'ajuste_entrada', null, null, $this->admin);
    }

    public function test_la_misma_serie_en_dos_lineas_con_el_id_en_mayusculas_no_se_vende_dos_veces(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->abrirCaja();
        $presentacion = $this->equipo->presentaciones->first();
        $id = $this->serie('IMEI-1')->id;

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [
                ['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'series' => [$id]],
                ['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'series' => [strtoupper($id)]],
            ],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 200, 'referencia' => null]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'la misma serie dos veces'));

        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertInvariante(2);

        // la defensa de fondo: vender() no marca una unidad que ya no está disponible
        $serie = $this->serie('IMEI-1');
        ProductoSerie::whereKey($serie->id)->update(['estado' => 'vendida']);
        $this->vender(['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'ya no está disponible'));
    }

    public function test_la_ficha_solo_muestra_la_compra_a_quien_ve_compras_y_los_parametros_array_no_rompen(): void
    {
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('success');

        // el vendedor no ve compras: ni el documento ni el proveedor, tampoco en la historia
        $vendedor = $this->crearUsuario('vendedor', 'v'.random_int(10000, 99999).'@test.local');
        $this->assertFalse($vendedor->can('compras.ver'));
        $this->actingAs($vendedor)->get('/series?numero=IMEI-1')->assertInertia(fn (Assert $p) => $p
            ->where('fichas.0.compra', null)->where('fichas.0.ver_compra', false)->where('fichas.0.historial.0.detalle', null));
        $this->actingAs($this->admin)->get('/series?numero=IMEI-1')->assertInertia(fn (Assert $p) => $p
            ->where('fichas.0.ver_compra', true)->where('fichas.0.compra.fecha', now()->toDateString())->where('fichas.0.historial.0.detalle', fn ($d) => str_contains((string) $d, 'Compra')));

        // parámetros que deberían ser texto y llegan como arreglo: error de validación, nunca un 500
        $this->actingAs($this->admin)->getJson('/series/buscar?numero[]=x')->assertStatus(422);
        $this->actingAs($this->admin)->get('/series?numero[]=x&estado[]=y&producto[]=z')->assertStatus(302);
        $this->abrirCaja();
        $this->actingAs($this->admin)->getJson('/pos/series?numero[]=x')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id[]=x')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id.'&buscar[]=x')->assertStatus(422);
    }

    public function test_el_xml_no_corta_una_serie_a_la_mitad_y_avisa_cuantas_faltan(): void
    {
        $numeros = array_map(fn ($i) => '3567890123456'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 40));
        $this->comprar($this->equipo, $numeros)->assertSessionHas('success');
        $this->abrirCaja();
        $this->vender($numeros)->assertSessionHas('success');
        $detalle = $this->ultimaVenta()->detalles()->firstOrFail();

        $xml = $detalle->descripcionParaXml(500);
        $this->assertLessThanOrEqual(500, mb_strlen($xml));
        $this->assertMatchesRegularExpression('/^Samsung A15 128GB S\/N: (\d{15}, )*\d{15} y (\d+) más$/', $xml);
        preg_match('/ y (\d+) más$/', $xml, $m);
        $this->assertSame(40, substr_count($xml, '35678901234') + (int) $m[1]);
        // el impreso sí las lleva todas
        $this->assertSame(40, substr_count($detalle->descripcionCompleta(), '35678901234'));
        // y cuando entran todas no se agrega nada
        $this->assertSame('Samsung A15 128GB S/N: '.implode(', ', $numeros), $detalle->descripcionParaXml(5000));
    }

    public function test_el_pos_devuelve_el_producto_de_cada_unidad_cuando_la_serie_esta_en_dos_productos(): void
    {
        $otro = $this->crearProducto(precio: 80.00, atributos: ['nombre' => 'Xiaomi Redmi 13', 'controla_serie' => true]);
        $this->comprar($this->equipo, ['DUP-1'])->assertSessionHas('success');
        $this->comprar($otro, ['DUP-1'])->assertSessionHas('success');
        $this->abrirCaja();

        $respuesta = $this->actingAs($this->admin)->getJson('/pos/series?numero=dup-1')->assertOk()->assertJsonCount(2, 'encontradas')->json('encontradas');
        $this->assertEqualsCanonicalizing(['Samsung A15 128GB', 'Xiaomi Redmi 13'], array_column($respuesta, 'producto'));
        $this->actingAs($this->admin)->getJson('/pos/series?numero=NO-HAY')->assertJsonPath('existe', false)->assertJsonCount(0, 'encontradas');
    }

    // ------------------------------------------------------------------ R10: variantes

    public function test_una_variante_marcada_con_serie_la_conserva_al_volver_a_guardar_el_modelo(): void
    {
        $this->empresa->update(['variantes_activo' => true]);
        $this->admin->unsetRelation('empresa');
        $datos = [
            'nombre' => 'Galaxy S24', 'categoria_id' => null, 'marca_id' => null, 'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'), 'precio_base' => 3000, 'costo' => null,
            'atributos' => [['nombre' => 'Capacidad', 'valores' => ['128GB', '256GB']]], 'variantes' => [],
        ];
        $this->actingAs($this->admin)->post('/productos/modelos', $datos)->assertSessionHasNoErrors();
        $grupo = ProductoGrupo::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
        $variante = $grupo->variantes()->with('presentaciones')->orderBy('variante_orden')->firstOrFail();
        $this->assertFalse($variante->controla_serie);

        // se marca desde el formulario normal de producto
        $unidad = UnidadMedida::query()->where('codigo', 'NIU')->value('codigo');
        $pres = $variante->presentaciones->first();
        $this->actingAs($this->admin)->put("/productos/{$variante->id}", [
            'codigo_interno' => $variante->codigo_interno, 'nombre' => $variante->nombre, 'categoria_id' => null, 'marca_id' => null,
            'unidad_base_codigo' => $unidad, 'tipo_afectacion_codigo' => $variante->tipo_afectacion_codigo,
            'permite_fraccion' => false, 'controla_lote' => false, 'controla_serie' => true, 'controla_stock' => true, 'stock_minimo' => 0, 'activo' => true,
            'presentaciones' => [[
                'id' => $pres->id, 'nombre' => $pres->nombre, 'unidad_codigo' => $pres->unidad_codigo, 'factor_conversion' => 1,
                'precio_venta' => 3000, 'precio_mayorista' => null, 'cantidad_mayorista' => null, 'codigo_barras' => null, 'es_default' => true,
            ]],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($variante->fresh()->controla_serie);
        $this->comprar($variante->fresh()->load('presentaciones'), ['S24-001'])->assertSessionHas('success');

        // se edita el modelo (un valor más, y un stock inicial para la variante que ya existe, que no debe cargarse)
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", [
            ...$datos, 'costo' => 2000,
            'atributos' => [['nombre' => 'Capacidad', 'valores' => ['128GB', '256GB', '512GB']]],
            'variantes' => [['valores' => ['128GB'], 'stock_inicial' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($variante->fresh()->controla_serie);
        $this->assertSame(3, $grupo->variantes()->count());
        $this->assertSame(1.0, $this->stockEn($this->sucursal->id, $variante));
        $this->assertSame(0, $this->sinSerie($variante));
        $this->assertSame('disponible', $this->serie('S24-001', $variante)->estado);
    }

    // ------------------------------------------------------------------ S2.1: nota de crédito por ítem eligiendo la unidad

    public function test_la_nota_de_credito_por_item_devuelve_solo_la_unidad_elegida(): void
    {
        $enviador = $this->activarBoletas();
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2', 'IMEI-3'])->assertSessionHas('success');
        $otro = $this->crearProducto(precio: 100.00, atributos: ['nombre' => 'Xiaomi Redmi 13', 'controla_serie' => true]);
        $this->comprar($otro, ['XIA-1'])->assertSessionHas('success');
        $this->abrirCaja(1000);

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03', 'cliente_id' => null, 'es_credito' => false,
            'items' => [
                ['presentacion_id' => $this->equipo->presentaciones->first()->id, 'cantidad' => 3, 'series' => ProductoSerie::where('producto_id', $this->equipo->id)->pluck('id')->all()],
                ['presentacion_id' => $otro->presentaciones->first()->id, 'cantidad' => 1, 'series' => [$this->serie('XIA-1', $otro)->id]],
            ],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 400, 'referencia' => null]],
        ])->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $linea = $venta->detalles()->where('producto_id', $this->equipo->id)->firstOrFail();
        $nota = fn (array $items) => $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", ['motivo' => '07', 'items' => $items]);

        // la pantalla recibe las unidades que siguen vendidas en cada línea
        $this->actingAs($this->admin)->get('/comprobantes')->assertInertia(fn (Assert $p) => $p
            ->where('comprobantes.data.0.detalles', fn ($detalles) => collect($detalles)->firstWhere('id', $linea->id)['series_vendidas'] !== []
                && count(collect($detalles)->firstWhere('id', $linea->id)['series_vendidas']) === 3));

        // una serie de OTRA línea no sirve; tampoco menos series que la cantidad
        $nota([['detalle_id' => $linea->id, 'cantidad' => 1, 'series' => [$this->serie('XIA-1', $otro)->id]]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'no es de'));
        $nota([['detalle_id' => $linea->id, 'cantidad' => 2, 'series' => [$this->serie('IMEI-2')->id]]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'las 2 unidades que vuelven'));
        $this->assertSame(0, Comprobante::where('comprobante_ref_id', $venta->id)->count());

        // devuelve IMEI-2: solo esa vuelve
        $nota([['detalle_id' => $linea->id, 'cantidad' => 1, 'series' => [$this->serie('IMEI-2')->id]]])->assertSessionHas('success');
        $nc = Comprobante::where('comprobante_ref_id', $venta->id)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $nc->total, 0.001);
        $this->assertSame('disponible', $this->serie('IMEI-2')->estado);
        $this->assertNull($this->serie('IMEI-2')->comprobante_detalle_id);
        $this->assertSame('vendida', $this->serie('IMEI-1')->estado);
        $this->assertSame('vendida', $this->serie('IMEI-3')->estado);
        $this->assertSame('vendida', $this->serie('XIA-1', $otro)->estado);
        $this->assertInvariante(1);

        // la nota impresa y su XML dicen qué unidad volvió; la venta original conserva las tres
        $this->assertSame('Samsung A15 128GB S/N: IMEI-2', $nc->detalles()->first()->descripcionCompleta());
        $this->assertSame('Samsung A15 128GB S/N: IMEI-2', $enviador->ultimoInvoice->getDetails()[0]->getDescripcion());
        $this->actingAs($this->admin)->get("/comprobantes/{$nc->id}/ticket?formato=html")->assertOk()->assertSeeInOrder(['S/N:', 'IMEI-2'])->assertDontSee('IMEI-1');
        $this->assertSame('Samsung A15 128GB S/N: IMEI-1, IMEI-2, IMEI-3', $linea->fresh()->descripcionCompleta());

        // la ya devuelta no vuelve otra vez; otra unidad de la misma línea sí
        $nota([['detalle_id' => $linea->id, 'cantidad' => 1, 'series' => [$this->serie('IMEI-2')->id]]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'ya fue devuelta'));
        $nota([['detalle_id' => $linea->id, 'cantidad' => 1, 'series' => [strtoupper($this->serie('IMEI-3')->id)]]])->assertSessionHas('success');
        $this->assertSame('disponible', $this->serie('IMEI-3')->estado);
        $this->assertSame(2, Comprobante::where('comprobante_ref_id', $venta->id)->count());
        $this->assertInvariante(2);

        // la unidad devuelta se puede vender de nuevo
        $this->vender(['IMEI-2'])->assertSessionHas('success');
        $this->assertInvariante(1);
    }

    // ------------------------------------------------------------------ S2.2: transferencias con series

    public function test_la_serie_viaja_en_la_transferencia_y_queda_disponible_en_el_destino(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2', 'IMEI-3'])->assertSessionHas('success');

        $this->actingAs($this->admin)->get('/transferencias/crear')->assertInertia(fn (Assert $p) => $p
            ->where('productos', fn ($productos) => collect($productos)->firstWhere('id', $this->equipo->id)['controla_serie'] === true));

        // sin elegir unidades, o con menos de las que se envían, no sale
        $this->transferir([], ['cantidad' => 1, 'series' => []])->assertSessionHas('error', fn ($e) => str_contains($e, 'la unidad que envías'));
        $this->transferir(['IMEI-1'], ['cantidad' => 2])->assertSessionHas('error', fn ($e) => str_contains($e, 'las 2 unidades que envías'));
        $this->assertSame(0, Transferencia::where('empresa_id', $this->empresa->id)->count());

        $this->transferir(['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();

        // en tránsito: no cuentan en ninguna sucursal, igual que el stock
        $this->assertSame('en_transito', $this->serie('IMEI-1')->estado);
        $this->assertSame('en_transito', $this->serie('IMEI-2')->estado);
        $this->assertInvariante(1);
        $this->assertInvariante(0, $this->sede2->id);
        $this->assertSame(2, SerieMovimiento::where('referencia_id', $transferencia->id)->where('tipo', 'transferencia_salida')->count());

        // el detalle de la transferencia muestra qué series viajan
        $this->actingAs($this->admin)->get('/transferencias')->assertInertia(fn (Assert $p) => $p
            ->where('transferencias.data.0.detalles.0.series', ['IMEI-1', 'IMEI-2']));

        // una unidad en tránsito no se vende, no se transfiere otra vez ni reingresa
        $this->abrirCaja();
        $this->vender(['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-1') && str_contains($e, 'ya no está disponible'));
        $this->transferir(['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'ya no está disponible'));
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'en tránsito'));
        $this->actingAs($this->admin)->getJson('/pos/series?numero=IMEI-1')->assertJsonPath('aviso', fn ($a) => str_contains($a, 'en tránsito'));

        // recibir: disponibles en el destino
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('success');
        $this->assertSame('disponible', $this->serie('IMEI-1')->estado);
        $this->assertSame($this->sede2->id, $this->serie('IMEI-1')->sucursal_id);
        $this->assertSame($this->sede2->id, $this->serie('IMEI-2')->sucursal_id);
        $this->assertSame(['compra', 'transferencia_salida', 'transferencia_entrada'], $this->serie('IMEI-1')->movimientos()->orderBy('creado_en')->orderBy('id')->pluck('tipo')->all());
        $this->assertInvariante(1);
        $this->assertInvariante(2, $this->sede2->id);

        // desde la caja de la sucursal principal ya no se vende esa unidad
        $this->vender(['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'ya no está disponible'));
        $this->vender(['IMEI-3'])->assertSessionHas('success');
        $this->assertInvariante(0);
    }

    public function test_anular_la_transferencia_devuelve_las_series_al_origen(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->transferir(['IMEI-2'])->assertSessionHas('success');
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertInvariante(1);

        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/anular")->assertSessionHas('success');

        $this->assertSame('disponible', $this->serie('IMEI-2')->estado);
        $this->assertSame($this->sucursal->id, $this->serie('IMEI-2')->sucursal_id);
        $this->assertSame(1, $this->serie('IMEI-2')->movimientos()->where('tipo', 'transferencia_anulada')->count());
        $this->assertInvariante(2);
        $this->assertInvariante(0, $this->sede2->id);

        // y vuelve a poder salir
        $this->transferir(['IMEI-2'])->assertSessionHas('success');
        $this->assertInvariante(1);
    }

    public function test_no_se_transfiere_una_serie_vendida_de_otra_sucursal_ni_de_otra_empresa(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->abrirCaja();
        $this->vender(['IMEI-1'])->assertSessionHas('success');

        // vendida
        $this->darStock($this->equipo, 1, 60); // hay stock suficiente: lo que frena es la serie
        $this->transferir(['IMEI-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-1') && str_contains($e, 'ya no está disponible'));

        // de otra sucursal
        $lejana = ProductoSerie::create(['empresa_id' => $this->empresa->id, 'producto_id' => $this->equipo->id, 'sucursal_id' => $this->sede2->id, 'numero_serie' => 'IMEI-SEDE2']);
        $this->transferir([], ['cantidad' => 1, 'series' => [$lejana->id]])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI-SEDE2'));

        // de otra empresa
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
        $ajena = ProductoSerie::create(['empresa_id' => $otra->id, 'producto_id' => $productoOtro->id, 'sucursal_id' => $sucursalOtra->id, 'numero_serie' => 'IMEI-AJENO']);
        $this->transferir([], ['cantidad' => 1, 'series' => [$ajena->id]])->assertSessionHas('error', fn ($e) => str_contains($e, 'no existe o no es de ese producto'));

        $this->assertSame(0, Transferencia::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame('disponible', $ajena->fresh()->estado);
        $this->assertSame(2.0, $this->stockEn($this->sucursal->id));
    }

    // ------------------------------------------------------------------ tercera ronda (C1–C8, P1)

    public function test_c1_con_la_baja_pendiente_en_sunat_la_serie_no_reingresa_y_al_confirmarse_vuelve_sola(): void
    {
        $enviador = $this->activarBoletas();
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->abrirCaja(500);
        $this->vender(['IMEI-1'], ['tipo_comprobante_codigo' => '03'])->assertSessionHas('success');
        $venta = $this->ultimaVenta();

        // SUNAT recibe la baja pero aún la procesa: la venta sigue vigente y la serie vendida
        $enviador->respuestaBaja = new RespuestaSunat(aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'T-1', enProceso: true);
        $enviador->respuestaTicket = new RespuestaSunat(aceptado: false, codigo: '98', mensaje: 'Procesando', ticket: 'T-1', enProceso: true);
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/anular", ['motivo' => 'devolución'])->assertSessionHas('success');
        $this->assertSame('baja_pendiente', ComprobanteSunat::find($venta->id)->estado);
        $this->assertSame('vendida', $this->serie('IMEI-1')->estado);

        // mientras tanto nadie puede reingresar esa serie: ni compra, ni ajuste, ni regularización
        $esperado = fn ($e) => str_contains($e, 'IMEI-1') && str_contains($e, 'en proceso de anulación en SUNAT');
        $this->comprar($this->equipo, ['IMEI-1'])->assertSessionHas('error', $esperado);
        $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", ['direccion' => 'entrada', 'cantidad' => 1, 'costo_unitario' => 50, 'series' => ['IMEI-1']])
            ->assertSessionHas('error', $esperado);
        $this->darStock($this->equipo, 1, 60);
        $this->regularizar(['IMEI-1'])->assertSessionHas('error', $esperado);
        $this->assertSame('vendida', $this->serie('IMEI-1')->estado);
        $this->regularizar(['IMEI-3'])->assertSessionHas('success');
        $this->assertInvariante(2);

        // SUNAT confirma: la anulación se completa y la serie vuelve sola
        $enviador->respuestaTicket = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'T-1');
        $this->artisan('sunat:sincronizar')->assertSuccessful();

        $this->assertSame('anulado', $venta->fresh()->estado);
        $this->assertSame('disponible', $this->serie('IMEI-1')->estado);
        $this->assertSame(0, $this->sinSerie());
        $this->assertInvariante(3);
    }

    public function test_c2_una_compra_anterior_al_control_de_serie_se_anula_solo_si_sus_unidades_siguen_sin_numero(): void
    {
        $normal = $this->crearProducto(precio: 50.0, atributos: ['nombre' => 'Parlante']);
        $compra = function () use ($normal) {
            $this->actingAs($this->admin)->post('/compras', [
                'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-'.random_int(100000, 999999),
                'fecha' => now()->toDateString(), 'es_credito' => false,
                'items' => [['presentacion_id' => $normal->presentaciones->first()->id, 'cantidad' => 2, 'costo_unitario' => 20]],
            ])->assertSessionHas('success');

            return Compra::where('empresa_id', $this->empresa->id)->latest('creado_en')->latest('id')->firstOrFail();
        };

        // dos compras de 2 antes de activar la serie; luego se activa (4 unidades sin número)
        $primera = $compra();
        $segunda = $compra();
        $normal->update(['controla_serie' => true]);
        $this->assertSame(4, $this->sinSerie($normal));

        // sus unidades siguen sin número: la compra se anula y baja el stock sin tocar series
        $this->actingAs($this->admin)->post("/compras/{$segunda->id}/anular", ['motivo' => 'error'])->assertSessionHas('success');
        $this->assertSame('anulada', $segunda->fresh()->estado);
        $this->assertSame(2.0, $this->stockEn($this->sucursal->id, $normal));
        $this->assertSame(2, $this->sinSerie($normal));

        // se regulariza una: ya no quedan 2 sin número para cubrir la otra compra
        $this->regularizar(['PAR-1'], producto: $normal)->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/compras/{$primera->id}/anular", ['motivo' => 'error'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'anterior al control de serie') && str_contains($e, 'ajuste de salida'));
        $this->assertSame('registrada', $primera->fresh()->estado);
        $this->assertSame(2.0, $this->stockEn($this->sucursal->id, $normal));
        $this->assertSame('disponible', $this->serie('PAR-1', $normal)->estado);
    }

    public function test_c3_las_series_0123_y_123_son_distintas(): void
    {
        $this->comprar($this->equipo, ['0123', '123'])->assertSessionHas('success');
        $this->assertSame(['0123', '123'], ProductoSerie::where('producto_id', $this->equipo->id)->orderBy('numero_serie')->pluck('numero_serie')->all());
        $this->assertInvariante(2);

        // dar de baja "123" y reingresarla reactiva ESA, no la "0123"
        $this->actingAs($this->admin)->post("/stock/{$this->equipo->id}/ajustar", ['direccion' => 'salida', 'cantidad' => 1, 'serie_ids' => [$this->serie('123')->id]])
            ->assertSessionHas('success');
        $this->comprar($this->equipo, ['123'])->assertSessionHas('success');
        $this->assertSame('disponible', $this->serie('123')->estado);
        $this->assertSame(1, $this->serie('0123')->movimientos()->count());
        $this->assertSame(2, ProductoSerie::where('producto_id', $this->equipo->id)->count());
        $this->comprar($this->equipo, ['0123'])->assertSessionHas('error', fn ($e) => str_contains($e, '0123') && str_contains($e, 'ya está registrada'));
        $this->assertInvariante(2);
    }

    public function test_c4_el_kardex_busca_series_en_el_servidor_y_dice_si_la_lista_viene_recortada(): void
    {
        $this->comprar($this->equipo, ['AAA-1', 'AAA-2', 'BBB-1'])->assertSessionHas('success');

        $this->actingAs($this->admin)->getJson("/stock/{$this->equipo->id}/kardex")
            ->assertOk()->assertJsonCount(3, 'series')->assertJsonPath('series_truncadas', false);
        $this->actingAs($this->admin)->getJson("/stock/{$this->equipo->id}/kardex?buscar_serie=bbb")
            ->assertJsonCount(1, 'series')->assertJsonPath('series.0.numero_serie', 'BBB-1');
        $this->actingAs($this->admin)->getJson("/stock/{$this->equipo->id}/kardex?buscar_serie[]=x")->assertOk()->assertJsonCount(3, 'series');

        // con más unidades que el límite de la lista, el JSON lo avisa y la búsqueda encuentra la que no venía
        $filas = [];
        for ($i = 1; $i <= SerieService::LIMITE_LISTA; $i++) {
            $filas[] = [
                'id' => (string) Str::uuid7(), 'empresa_id' => $this->empresa->id, 'producto_id' => $this->equipo->id,
                'sucursal_id' => $this->sucursal->id, 'numero_serie' => 'MASIVA-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'estado' => 'disponible',
            ];
        }
        ProductoSerie::insert($filas);
        ProductoSerie::create(['empresa_id' => $this->empresa->id, 'producto_id' => $this->equipo->id, 'sucursal_id' => $this->sucursal->id, 'numero_serie' => 'ZZZ-ULTIMA']);

        $lista = $this->actingAs($this->admin)->getJson("/stock/{$this->equipo->id}/kardex")->assertJsonPath('series_truncadas', true)->json('series');
        $this->assertCount(SerieService::LIMITE_LISTA, $lista);
        $this->assertNotContains('ZZZ-ULTIMA', array_column($lista, 'numero_serie'));
        $this->actingAs($this->admin)->getJson("/stock/{$this->equipo->id}/kardex?buscar_serie=zzz")
            ->assertJsonCount(1, 'series')->assertJsonPath('series.0.numero_serie', 'ZZZ-ULTIMA')->assertJsonPath('series_truncadas', false);

        $this->abrirCaja();
        $this->actingAs($this->admin)->getJson('/pos/series?producto_id='.$this->equipo->id)->assertJsonPath('series_truncadas', true);
    }

    public function test_c5_las_series_de_una_transferencia_se_muestran_una_vez_aunque_el_producto_salga_en_dos_filas(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->transferir(['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();

        // el mismo producto en dos filas de detalle (pasa cuando sale de capas con lotes distintos)
        $detalle = $transferencia->detalles()->firstOrFail();
        $detalle->update(['cantidad' => 1]);
        $transferencia->detalles()->create(['producto_id' => $this->equipo->id, 'lote_id' => null, 'cantidad' => 1]);

        $this->actingAs($this->admin)->get('/transferencias')->assertInertia(fn (Assert $p) => $p
            ->has('transferencias.data.0.detalles', 2)
            ->where('transferencias.data.0.detalles', fn ($detalles) => collect($detalles)->flatMap(fn ($d) => $d['series'])->sort()->values()->all() === ['IMEI-1', 'IMEI-2']));
    }

    public function test_c6_una_linea_de_venta_lleva_como_maximo_50_equipos_con_serie(): void
    {
        $this->abrirCaja();
        $ids = array_map(fn () => (string) Str::uuid(), range(1, SerieService::MAX_POR_LINEA_VENTA + 1));

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $this->equipo->presentaciones->first()->id, 'cantidad' => count($ids), 'series' => $ids]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => count($ids) * 100, 'referencia' => null]],
        ])->assertSessionHasErrors(['items.0.series' => 'Máximo 50 equipos con serie por línea; agrega otra línea o haz otra venta.']);

        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_c7_la_nota_de_credito_no_acepta_la_misma_linea_dos_veces(): void
    {
        $this->activarBoletas();
        $normal = $this->crearProducto(precio: 10.0, atributos: ['nombre' => 'Cable USB']);
        $this->darStock($normal, 10, 4);
        $this->abrirCaja(500);
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $normal->presentaciones->first()->id, 'cantidad' => 4]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 40, 'referencia' => null]],
        ])->assertSessionHas('success');
        $venta = $this->ultimaVenta();
        $detalleId = $venta->detalles()->value('id');

        // 3 + 3 de una línea de 4: cada una "cabe" por separado, juntas acreditarían 6
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", [
            'motivo' => '07', 'items' => [['detalle_id' => $detalleId, 'cantidad' => 3], ['detalle_id' => $detalleId, 'cantidad' => 3]],
        ])->assertSessionHasErrors('items.0.detalle_id');

        $this->assertSame(0, Comprobante::where('comprobante_ref_id', $venta->id)->count());
        $this->assertSame(6.0, $this->stockEn($this->sucursal->id, $normal));

        // una sola vez sigue funcionando igual
        $this->actingAs($this->admin)->post("/comprobantes/{$venta->id}/nota-credito", [
            'motivo' => '07', 'items' => [['detalle_id' => $detalleId, 'cantidad' => 3]],
        ])->assertSessionHas('success');
        $this->assertSame(9.0, $this->stockEn($this->sucursal->id, $normal));
    }

    public function test_c8_recibir_dos_veces_una_transferencia_con_series_no_duplica_nada(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->transferir(['IMEI-1'])->assertSessionHas('success');
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('error');
        // tampoco se puede anular una ya recibida
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/anular")->assertSessionHas('error');

        $this->assertSame('recibida', $transferencia->fresh()->estado);
        $this->assertSame($this->sede2->id, $this->serie('IMEI-1')->sucursal_id);
        $this->assertSame(1, $this->serie('IMEI-1')->movimientos()->where('tipo', 'transferencia_entrada')->count());
        $this->assertSame(0, $this->serie('IMEI-1')->movimientos()->where('tipo', 'transferencia_anulada')->count());
        $this->assertInvariante(1);
        $this->assertInvariante(1, $this->sede2->id);

        // y la ficha de una unidad en camino dice a dónde va
        $this->transferir(['IMEI-2'])->assertSessionHas('success');
        $this->actingAs($this->admin)->get('/series?numero=IMEI-2')->assertInertia(fn (Assert $p) => $p
            ->where('fichas.0.estado', 'en_transito')->where('fichas.0.sucursal', 'Principal')->where('fichas.0.destino', 'Sede 2'));
        $this->actingAs($this->admin)->get('/series?numero=IMEI-1')->assertInertia(fn (Assert $p) => $p->where('fichas.0.destino', null));
    }

    public function test_p1_el_pos_muestra_como_stock_de_un_equipo_solo_las_unidades_con_serie(): void
    {
        $this->comprar($this->equipo, ['IMEI-1', 'IMEI-2'])->assertSessionHas('success');
        $this->darStock($this->equipo, 1, 60); // una unidad más en stock, sin número
        $normal = $this->crearProducto(precio: 10.0, atributos: ['nombre' => 'Cable USB']);
        $this->darStock($normal, 7, 4);
        $this->abrirCaja();

        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (Assert $p) => $p
            ->where('productos', function ($productos) use ($normal) {
                $productos = collect($productos);

                // el equipo: 3 en stock pero 2 vendibles; el producto sin serie, su stock de siempre
                return (float) $productos->firstWhere('id', $this->equipo->id)['stock'] === 2.0
                    && (float) $productos->firstWhere('id', $normal->id)['stock'] === 7.0;
            }));
    }
}
