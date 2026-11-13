<?php

namespace Tests\Feature;

use App\IA\Herramientas\BuscarProductos;
use App\IA\Herramientas\DetalleProducto;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\Stock;
use App\Models\TipoAfectacionIgv;
use App\Services\CatalogoTiendaService;
use App\Services\VarianteService;
use App\Support\Tienda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Variantes (tallas y colores) en la tienda en línea: una tarjeta por modelo y "elige talla y color" en la página. */
class TiendaVariantesTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $urea;

    private Producto $glifosato;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->forceFill(['tienda_habilitada' => true, 'variantes_activo' => true])->save();

        $this->urea = $this->crearProducto(precio: 145, atributos: ['nombre' => 'Urea 46% x 50 kg', 'codigo_interno' => 'P0006']);
        $this->glifosato = $this->crearProducto(precio: 28, atributos: ['nombre' => 'Glifosato 480 SL x 1 L', 'codigo_interno' => 'P0001']);
        $this->darStock($this->urea, 10, 100);
        $this->darStock($this->glifosato, 5, 20);
    }

    private function publicar(array $config = [], string $slug = 'agro'): void
    {
        $this->empresa->update([
            'nombre_comercial' => 'Agro Campo',
            'tienda_slug' => $slug,
            'tienda_publicada' => true,
            'tienda_config' => ['mostrar_precios' => true, 'mostrar_stock' => true, 'whatsapp' => '987 654 321', ...$config],
        ]);
    }

    private function tienda(string $ruta = '/', string $slug = 'agro'): TestResponse
    {
        $respuesta = $this->get("http://{$slug}.tienda.test{$ruta}");
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    /** Polo básico en Talla S/M/L y Color Rojo/Azul (6 variantes a S/ 35), en la categoría Ropa y de la marca Inka. */
    private function crearPolo(array $extra = []): ProductoGrupo
    {
        return app(VarianteService::class)->guardarGrupo($this->admin, [
            'nombre' => 'Polo básico',
            'categoria_id' => Categoria::firstOrCreate(['empresa_id' => $this->empresa->id, 'nombre' => 'Ropa'])->id,
            'marca_id' => Marca::firstOrCreate(['empresa_id' => $this->empresa->id, 'nombre' => 'Inka'])->id,
            'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'precio_base' => 35,
            'costo' => null,
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
            'variantes' => [],
            ...$extra,
        ]);
    }

    private function variante(ProductoGrupo $grupo, string ...$valores): Producto
    {
        return $grupo->variantes()->with('presentaciones')->get()
            ->first(fn (Producto $p) => VarianteService::valoresDe($p, $grupo->atributos) === $valores);
    }

    /** Las opciones del selector de la página: "Talla:M" => [url, elegida, agotada, salta]. */
    private function opciones(string $html): array
    {
        preg_match_all('/<a href="([^"]+)" data-opcion-variante data-atributo="(\d+)" data-valor="([^"]+)"(.*?)class=/s', $html, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($o) => ["{$o[2]}:{$o[3]}" => [
            'url' => $o[1],
            'elegida' => str_contains($o[4], 'aria-current="true"'),
            'agotada' => str_contains($o[4], 'data-variante-agotada'),
            'salta' => str_contains($o[4], 'data-variante-salta'),
        ]])->all();
    }

    public function test_el_catalogo_muestra_una_tarjeta_por_modelo_y_los_totales_cuentan_modelos(): void
    {
        $this->publicar();
        $polo = $this->crearPolo(['variantes' => [['valores' => ['L', 'Azul'], 'precio_venta' => 39.9]]]);
        $this->darStock($this->variante($polo, 'M', 'Rojo'), 4, 10);

        $catalogo = app(CatalogoTiendaService::class);
        // urea, glifosato y el polo: 3 tarjetas aunque haya 8 productos a la venta
        $this->assertSame(3, $catalogo->total($this->empresa));
        $this->assertSame(1, $catalogo->categorias($this->empresa)->firstWhere('nombre', 'Ropa')->productos);
        $this->assertSame(1, $catalogo->marcas($this->empresa)->firstWhere('nombre', 'Inka')['productos']);

        $html = $this->tienda('/catalogo')->assertOk()
            ->assertSee('3 tallas · 2 colores')
            ->assertSee('Ver opciones')
            ->assertSee('3 productos')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-tarjeta-modelo="6"'));
        $this->assertSame(3, substr_count($html, '<article'));
        // el título es el del modelo y ninguna variante sale con su nombre suelto
        $this->assertStringContainsString('>Polo básico</h3>', $html);
        $this->assertStringNotContainsString('Polo básico S Rojo', $html);
        $this->assertStringNotContainsString('Polo básico M Rojo', $html);
        // lleva a la variante representativa: la primera con stock (M Rojo), no la primera de la lista (S Rojo)
        $this->assertSame(2, substr_count($html, 'href="/catalogo/polo-basico-m-rojo"'));
        $this->assertStringContainsString('data-elegir-modelo', $html);
        // con precios distintos dice "Desde" el más bajo
        $this->assertMatchesRegularExpression('/data-modelo-precio\s*>\s*<span[^>]*>Desde S\/<\/span>\s*<span[^>]*>35\.00<\/span>/', $html);
        // la tarjeta de un modelo no añade nada a ciegas: los botones de añadir son solo de los productos sueltos
        preg_match_all('/data-anadir="([^"]+)"/', $html, $botones);
        $this->assertEqualsCanonicalizing(
            ['Urea 46% x 50 kg', 'Glifosato 480 SL x 1 L'],
            collect($botones[1])->map(fn ($b) => json_decode(html_entity_decode($b), true)['nombre'])->all(),
        );

        // la categoría, la portada y la plantilla Mercado también traen una sola tarjeta del modelo
        $this->assertSame(1, substr_count($this->tienda('/categoria/ropa')->assertOk()->getContent(), 'data-tarjeta-modelo='));
        // (en la portada el modelo sale en los destacados y en la muestra del catálogo, una vez en cada sección)
        $portada = $this->tienda('/')->assertOk()->assertSee('Polo básico')->assertDontSee('Polo básico S Rojo')->assertDontSee('Polo básico M Rojo')->getContent();
        $this->assertSame(substr_count($portada, '>Glifosato 480 SL x 1 L</h3>'), substr_count($portada, 'data-tarjeta-modelo='));

        $this->publicar(['plantilla' => 'mercado']);
        $mercado = $this->tienda('/catalogo')->assertOk()->assertSee('3 tallas · 2 colores')->assertSee('Ver opciones')->getContent();
        $this->assertSame(1, substr_count($mercado, 'data-tarjeta-modelo="6"'));
        $this->assertSame(3, substr_count($mercado, 'data-tarjeta-mercado'));
        $this->assertMatchesRegularExpression('/Desde<\/span>\s*<span[^>]*>S\/ 35\.00<\/span>/', $mercado);
        // el filtro de marcas cuenta el modelo una vez
        $this->assertSame(1, substr_count($this->tienda('/catalogo?marca=Inka')->assertOk()->getContent(), '<article'));
    }

    public function test_un_modelo_sin_stock_se_ve_agotado_y_con_una_variante_disponible_ya_no(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();

        // nadie tiene stock: la tarjeta sale agotada y lleva a la primera variante
        $html = $this->tienda('/catalogo')->assertOk()->getContent();
        $this->assertStringContainsString('href="/catalogo/polo-basico-s-rojo"', $html);
        $this->assertSame(1, substr_count($html, '>Agotado</span>'));

        // basta una variante con stock para que el modelo esté disponible, aunque la primera siga agotada
        $this->darStock($this->variante($polo, 'L', 'Azul'), 1, 10);
        $html = $this->tienda('/catalogo')->assertOk()->getContent();
        $this->assertStringContainsString('href="/catalogo/polo-basico-l-azul"', $html);
        $this->assertSame(0, substr_count($html, '>Agotado</span>'));
    }

    public function test_el_buscador_encuentra_el_modelo_por_nombre_y_por_valor_sin_repetirlo(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();
        $this->darStock($this->variante($polo, 'S', 'Rojo'), 2, 10);

        // por nombre: una sola tarjeta y un solo resultado
        $html = $this->tienda('/catalogo?q=polo')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<article'));
        $this->assertSame(1, substr_count($html, 'data-tarjeta-modelo='));

        // por valor: "polo azul" encuentra el modelo y lleva a una variante azul
        $html = $this->tienda('/catalogo?q=polo+azul')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<article'));
        $this->assertStringContainsString('href="/catalogo/polo-basico-s-azul"', $html);

        // las sugerencias mientras se escribe: el modelo una vez, con su nombre
        $this->tienda('/buscar?q=polo')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'productos')
            ->assertJsonPath('productos.0.nombre', 'Polo básico')
            ->assertJsonPath('productos.0.url', '/catalogo/polo-basico-s-rojo')
            ->assertJsonPath('productos.0.precio', 35);
    }

    public function test_la_pagina_del_producto_deja_elegir_talla_y_color_con_su_disponibilidad(): void
    {
        $this->publicar();
        $polo = $this->crearPolo(['variantes' => [['valores' => ['L', 'Azul'], 'precio_venta' => 39.9]]]);
        $this->darStock($this->variante($polo, 'M', 'Rojo'), 4, 10);
        $this->darStock($this->variante($polo, 'L', 'Azul'), 2, 10);

        $respuesta = $this->tienda('/catalogo/polo-basico-m-rojo')->assertOk()
            ->assertSee('data-elegir-variante', false)
            ->assertSee('Elige talla:')
            ->assertSee('Elige color:')
            ->assertSee('Añadir al pedido');
        $html = $respuesta->getContent();

        // el título es el del modelo; la variante elegida viene marcada
        $this->assertMatchesRegularExpression('/<h1[^>]*>Polo básico<\/h1>/', $html);
        $opciones = $this->opciones($html);
        $this->assertSame(['0:S', '0:M', '0:L', '1:Rojo', '1:Azul'], array_keys($opciones));
        $this->assertSame(['0:M', '1:Rojo'], array_keys(array_filter($opciones, fn ($o) => $o['elegida'])));
        // ninguna S tiene stock: lleva a la que combina con lo elegido (S Rojo) y sale tachada
        $this->assertSame('/catalogo/polo-basico-s-rojo', $opciones['0:S']['url']);
        $this->assertSame(['0:S'], array_keys(array_filter($opciones, fn ($o) => $o['agotada'])));
        // L Rojo y M Azul están agotadas, pero hay una L y un Azul disponibles (L Azul): el toque lleva ahí,
        // no se tachan y se avisa que cambia también lo otro
        $this->assertSame('/catalogo/polo-basico-l-azul', $opciones['0:L']['url']);
        $this->assertSame('/catalogo/polo-basico-l-azul', $opciones['1:Azul']['url']);
        $this->assertSame(['0:L', '1:Azul'], array_keys(array_filter($opciones, fn ($o) => $o['salta'])));

        // desde L Azul, tocar "M" lleva a M Rojo (M Azul está agotada y M Rojo no); "Rojo" también
        $desdeLAzul = $this->opciones($this->tienda('/catalogo/polo-basico-l-azul')->assertOk()->getContent());
        $this->assertSame('/catalogo/polo-basico-m-rojo', $desdeLAzul['0:M']['url']);
        $this->assertSame('/catalogo/polo-basico-m-rojo', $desdeLAzul['1:Rojo']['url']);
        $this->assertFalse($desdeLAzul['0:M']['agotada']);
        $this->assertTrue($desdeLAzul['0:M']['salta']);
        // con stock en M Azul, "M" vuelve a ser la combinación exacta
        $this->darStock($this->variante($polo, 'M', 'Azul'), 1, 10);
        $desdeLAzul = $this->opciones($this->tienda('/catalogo/polo-basico-l-azul')->getContent());
        $this->assertSame('/catalogo/polo-basico-m-azul', $desdeLAzul['0:M']['url']);
        $this->assertFalse($desdeLAzul['0:M']['salta']);
        Stock::where('producto_id', $this->variante($polo, 'M', 'Azul')->id)->update(['cantidad' => 0]);

        // los datos con que tienda.js cambia de variante sin recargar: todas, con precio y disponibilidad
        $this->assertSame(1, preg_match('/<script type="application\/json" data-variantes>(.*?)<\/script>/s', $html, $json));
        $variantes = collect(json_decode($json[1], true));
        $this->assertCount(6, $variantes);
        $this->assertSame(
            ['S Rojo' => false, 'S Azul' => false, 'M Rojo' => true, 'M Azul' => false, 'L Rojo' => false, 'L Azul' => true],
            $variantes->mapWithKeys(fn ($v) => [implode(' ', $v['valores']) => $v['disponible']])->all(),
        );
        $lAzul = $variantes->firstWhere('url', '/catalogo/polo-basico-l-azul');
        $this->assertSame(39.9, $lAzul['precio']);
        $this->assertSame('Polo básico L Azul', $lAzul['anadir']['nombre']);
        $this->assertSame('Talla L, Color Azul', $lAzul['anadir']['variante']);
        $this->assertSame((string) $this->variante($polo, 'L', 'Azul')->id, $lAzul['anadir']['id']);
        $this->assertStringContainsString(rawurlencode("Polo básico L Azul\n(Talla L, Color Azul)\nhttp://agro.tienda.test/catalogo/polo-basico-l-azul"), $lAzul['pedido']);
        // nunca cuántas unidades hay
        $this->assertArrayNotHasKey('stock_total', $lAzul);

        // la página de una variante agotada se abre igual (enlaces ya compartidos), pero no deja añadir
        $agotada = $this->tienda('/catalogo/polo-basico-s-azul')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-variante-zona\s+hidden/', $agotada);
        $this->assertMatchesRegularExpression('/data-variante-agotado\s*>/', $agotada);
        $this->assertMatchesRegularExpression('/data-variante-disponible\s+hidden/', $agotada);
        $this->assertStringContainsString('Preguntar cuándo llega', $agotada);
        $this->assertSame(['0:S', '1:Azul'], array_keys(array_filter($this->opciones($agotada), fn ($o) => $o['elegida'])));

        // un producto suelto no tiene nada de esto
        $this->tienda('/catalogo/urea-46-x-50-kg')->assertOk()
            ->assertDontSee('data-elegir-variante', false)
            ->assertDontSee('data-variantes', false)
            ->assertDontSee('data-variante-', false);
    }

    public function test_el_carrito_y_whatsapp_reciben_la_variante_concreta_con_su_talla_y_color(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();
        $mRojo = $this->variante($polo, 'M', 'Rojo');
        $this->darStock($mRojo, 4, 10);

        $html = $this->tienda('/catalogo/polo-basico-m-rojo')->assertOk()->getContent();

        // "Añadir al pedido" lleva el producto de esa variante, con su nombre completo y su talla y color
        $this->assertSame(1, preg_match('/data-anadir="([^"]+)"/', $html, $boton));
        $anadir = json_decode(html_entity_decode($boton[1]), true);
        $this->assertSame((string) $mRojo->id, $anadir['id']);
        $this->assertSame('Polo básico M Rojo', $anadir['nombre']);
        $this->assertSame('Talla M, Color Rojo', $anadir['variante']);
        $this->assertSame(35, $anadir['precio']);
        $this->assertSame('/catalogo/polo-basico-m-rojo', $anadir['url']);

        // "Pedir por WhatsApp" dice el producto, la talla y el color, con el enlace de esa variante
        $this->assertStringContainsString(
            'https://wa.me/51987654321?text='.rawurlencode("Hola, me interesa este producto de Agro Campo:\nPolo básico M Rojo\n(Talla M, Color Rojo)\nhttp://agro.tienda.test/catalogo/polo-basico-m-rojo"),
            html_entity_decode($html),
        );

        // un producto suelto se añade igual que siempre, sin el dato de variante
        $suelto = $this->tienda('/catalogo/urea-46-x-50-kg')->getContent();
        preg_match('/data-anadir="([^"]+)"/', $suelto, $boton);
        $this->assertSame(['id', 'nombre', 'unidad', 'precio', 'imagen', 'url'], array_keys(json_decode(html_entity_decode($boton[1]), true)));
    }

    public function test_la_canonica_y_el_sitemap_apuntan_a_una_sola_direccion_por_modelo_que_no_cambia_con_el_stock(): void
    {
        $this->publicar();
        $polo = $this->crearPolo(['variantes' => [['valores' => ['L', 'Azul'], 'precio_venta' => 39.9]]]);
        $this->darStock($this->variante($polo, 'M', 'Rojo'), 4, 10);

        $comprobar = function () {
            // cualquier variante declara la dirección de la primera del modelo, tenga o no stock
            foreach (['polo-basico-s-rojo', 'polo-basico-m-rojo', 'polo-basico-l-azul'] as $slug) {
                $html = $this->tienda("/catalogo/{$slug}")->assertOk()
                    ->assertSee('<link rel="canonical" href="http://agro.tienda.test/catalogo/polo-basico-s-rojo">', false)
                    ->getContent();

                // los datos estructurados son del modelo y coinciden con la canónica: mismos en todas sus variantes
                preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $bloques);
                $producto = collect($bloques[1])->map(fn ($b) => json_decode($b, true))->firstWhere('@type', 'Product');
                $this->assertSame('Polo básico', $producto['name']);
                $this->assertArrayNotHasKey('sku', $producto);
                $this->assertSame([
                    '@type' => 'AggregateOffer',
                    'url' => 'http://agro.tienda.test/catalogo/polo-basico-s-rojo',
                    'priceCurrency' => 'PEN',
                    'lowPrice' => '35.00',
                    'highPrice' => '39.90',
                    'offerCount' => 6,
                    'availability' => 'https://schema.org/InStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                ], collect($producto['offers'])->except('seller')->all());
            }

            $mapa = $this->tienda('/sitemap.xml')->assertOk()->getContent();
            $this->assertSame(1, substr_count($mapa, '/catalogo/polo-basico-'));
            $this->assertStringContainsString('<loc>http://agro.tienda.test/catalogo/polo-basico-s-rojo</loc>', $mapa);
            $this->assertStringContainsString('<loc>http://agro.tienda.test/catalogo/urea-46-x-50-kg</loc>', $mapa);
            $this->assertSame(3, substr_count($mapa, '/catalogo/'));
        };

        $comprobar();
        // la tarjeta del catálogo sí lleva a la que tiene stock
        $this->assertStringContainsString('href="/catalogo/polo-basico-m-rojo"', $this->tienda('/catalogo')->getContent());

        // cambia el stock (se agota M Rojo, entra L Azul): la tarjeta cambia de enlace; canónica y sitemap, no
        Stock::where('producto_id', $this->variante($polo, 'M', 'Rojo')->id)->update(['cantidad' => 0]);
        $this->darStock($this->variante($polo, 'L', 'Azul'), 2, 10);
        $this->assertStringContainsString('href="/catalogo/polo-basico-l-azul"', $this->tienda('/catalogo')->getContent());
        $comprobar();

        // un producto suelto sigue con su dirección y su oferta simple
        $suelto = $this->tienda('/catalogo/urea-46-x-50-kg')->assertSee('<link rel="canonical" href="http://agro.tienda.test/catalogo/urea-46-x-50-kg">', false)->getContent();
        $this->assertStringContainsString('"@type":"Offer","url":"http://agro.tienda.test/catalogo/urea-46-x-50-kg"', $suelto);
        $this->assertStringContainsString('"sku":"P0006"', $suelto);
    }

    public function test_solo_cuentan_las_variantes_visibles_y_las_combinaciones_que_faltan_saltan_a_otra(): void
    {
        $this->publicar();
        // S solo existe en Rojo y L solo en Azul
        $polo = $this->crearPolo(['variantes' => [
            ['valores' => ['S', 'Azul'], 'omitir' => true],
            ['valores' => ['L', 'Rojo'], 'omitir' => true],
        ]]);
        foreach ($polo->variantes as $variante) {
            $this->darStock($variante, 1, 10);
        }
        // M Azul está oculta de la tienda y M Rojo desactivada
        $this->variante($polo, 'M', 'Azul')->update(['en_tienda' => false]);
        $this->variante($polo, 'M', 'Rojo')->update(['activo' => false]);

        $this->assertStringContainsString('data-tarjeta-modelo="2"', $this->tienda('/catalogo')->assertSee('2 tallas · 2 colores')->getContent());
        $this->tienda('/catalogo/polo-basico-m-azul')->assertNotFound();
        $this->tienda('/catalogo/polo-basico-m-rojo')->assertNotFound();

        $opciones = $this->opciones($this->tienda('/catalogo/polo-basico-s-rojo')->assertOk()->getContent());
        // la talla M no se ofrece: ninguna variante visible la tiene
        $this->assertSame(['0:S', '0:L', '1:Rojo', '1:Azul'], array_keys($opciones));
        // L Rojo no existe: tocar "L" o "Azul" lleva a la única variante que lo tiene (L Azul) y se avisa
        $this->assertSame('/catalogo/polo-basico-l-azul', $opciones['0:L']['url']);
        $this->assertSame('/catalogo/polo-basico-l-azul', $opciones['1:Azul']['url']);
        $this->assertTrue($opciones['0:L']['salta']);
        $this->assertTrue($opciones['1:Azul']['salta']);
        $this->assertFalse($opciones['0:S']['salta']);

        // con una sola variante a la vista ya no hay nada que elegir en la tarjeta: se añade directo
        $this->variante($polo, 'L', 'Azul')->update(['en_tienda' => false]);
        $html = $this->tienda('/catalogo')->assertOk()->assertDontSee('Ver opciones')->getContent();
        $this->assertStringContainsString('data-tarjeta-modelo="1"', $html);
        $this->assertStringContainsString('Polo básico S Rojo', html_entity_decode($html)); // en el botón de añadir
    }

    public function test_una_tienda_sin_variantes_se_ve_y_cuenta_igual_que_antes(): void
    {
        $this->publicar();
        $catalogo = app(CatalogoTiendaService::class);

        $this->assertFalse($catalogo->tieneModelos($this->empresa));
        $this->assertSame(2, $catalogo->total($this->empresa));
        // sin modelos la consulta del listado no se toca
        $consulta = $catalogo->productos($this->empresa);
        $sql = $consulta->toSql();
        $this->assertSame($sql, $catalogo->porModelo($this->empresa, $consulta)->toSql());

        $tarjeta = $catalogo->tarjeta($catalogo->encontrar($this->empresa, 'urea-46-x-50-kg'), Tienda::config($this->empresa));
        $this->assertNull($tarjeta['modelo']);
        $this->assertSame(['id', 'nombre', 'unidad', 'precio', 'imagen', 'url'], array_keys($tarjeta['para_pedido']));

        foreach (['/', '/catalogo', '/catalogo?q=urea', '/catalogo/urea-46-x-50-kg'] as $ruta) {
            $html = $this->tienda($ruta)->assertOk()->getContent();
            foreach (['data-tarjeta-modelo', 'data-modelo-', 'data-elegir-variante', 'data-elegir-modelo', 'data-variante', 'data-opcion-variante', 'Ver opciones', 'Desde S/'] as $nuevo) {
                $this->assertStringNotContainsString($nuevo, $html, "{$ruta} no debe traer {$nuevo}");
            }
        }
        $this->tienda('/catalogo')->assertSee('2 productos')->assertSee('Añadir');
        $this->assertSame(2, substr_count($this->tienda('/catalogo')->getContent(), 'data-anadir='));
        $this->assertSame(2, substr_count($this->tienda('/sitemap.xml')->getContent(), '/catalogo/'));
        $this->tienda('/buscar?q=urea')->assertJsonPath('total', 1)->assertJsonPath('productos.0.nombre', 'Urea 46% x 50 kg');

        // la plantilla Mercado tampoco cambia
        $this->publicar(['plantilla' => 'mercado']);
        $html = $this->tienda('/catalogo')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-tarjeta-modelo', $html);
        $this->assertSame(2, substr_count($html, 'data-anadir='));
    }

    public function test_no_se_mezclan_variantes_de_otra_empresa(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();
        $this->darStock($this->variante($polo, 'S', 'Rojo'), 3, 10);

        // otra empresa con su propia tienda y su propio "Polo básico"
        [$miEmpresa, $miAdmin, $miSucursal] = [$this->empresa, $this->admin, $this->sucursal];
        $this->crearEscenarioBase();
        $this->empresa->forceFill(['tienda_habilitada' => true, 'variantes_activo' => true])->save();
        $this->publicar(slug: 'otra');
        $ajeno = $this->crearPolo(['nombre' => 'Polo ajeno', 'atributos' => [['nombre' => 'Talla', 'valores' => ['XL', 'XXL']]]]);
        // un dato cruzado a propósito: un producto de la otra empresa que dice ser variante de mi modelo
        $intruso = $this->crearProducto(precio: 1, atributos: ['nombre' => 'Polo intruso', 'grupo_id' => $polo->id, 'variante_orden' => 0, 'atributos' => ['Talla' => 'XS', 'Color' => 'Negro']]);
        $this->darStock($intruso, 9, 1);
        $otraEmpresa = $this->empresa;
        [$this->empresa, $this->admin, $this->sucursal] = [$miEmpresa, $miAdmin, $miSucursal];

        // mi tienda: mis 6 variantes, ninguna ajena, y el precio "desde" no baja a S/ 1
        $html = $this->tienda('/catalogo/polo-basico-s-rojo')->assertOk()->assertDontSee('XXL')->assertDontSee('Negro')->assertDontSee('intruso')->getContent();
        preg_match('/<script type="application\/json" data-variantes>(.*?)<\/script>/s', $html, $json);
        $this->assertCount(6, json_decode($json[1], true));
        $this->assertSame(['0:S', '0:M', '0:L', '1:Rojo', '1:Azul'], array_keys($this->opciones($html)));
        $this->tienda('/catalogo')->assertSee('data-tarjeta-modelo="6"', false)->assertDontSee('Polo ajeno')->assertDontSee('intruso');
        $this->assertSame(3, app(CatalogoTiendaService::class)->total($miEmpresa));
        $this->tienda('/catalogo/polo-ajeno-xl')->assertNotFound();

        // la otra tienda: su modelo con sus 2 tallas, y el intruso como un producto suelto (mi modelo no es suyo)
        $otra = $this->tienda('/catalogo', 'otra')->assertOk()->assertSee('Polo ajeno')->assertSee('2 tallas')->assertDontSee('Polo básico')->getContent();
        $this->assertSame(1, substr_count($otra, 'data-tarjeta-modelo='));
        $this->assertStringContainsString('data-tarjeta-modelo="2"', $otra);
        $this->tienda('/catalogo/polo-intruso', 'otra')->assertOk()->assertDontSee('data-elegir-variante', false)->assertDontSee('Rojo');
        $this->assertNotNull($ajeno);
        $this->assertSame(2, app(CatalogoTiendaService::class)->total($otraEmpresa));
    }

    public function test_un_catalogo_con_modelos_no_consulta_mas_por_tener_mas_variantes(): void
    {
        $this->publicar();

        $consultas = function (string $ruta): array {
            $hechas = [];
            DB::listen(function ($consulta) use (&$hechas) {
                $hechas[] = $consulta->sql;
            });
            $this->tienda($ruta)->assertOk();
            DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

            return $hechas;
        };

        // sin variantes y con el módulo apagado: ninguna consulta habla de modelos
        $this->empresa->update(['variantes_activo' => false]);
        $sinModulo = $consultas('/catalogo');
        $this->assertSame([], array_values(array_filter($sinModulo, fn ($sql) => str_contains($sql, 'grupo_id') || str_contains($sql, 'producto_grupos'))));
        // con el módulo encendido y sin variantes, solo se pregunta una vez si hay alguna
        $this->empresa->update(['variantes_activo' => true]);
        $sinVariantes = $consultas('/catalogo');
        $this->assertCount(count($sinModulo) + 1, $sinVariantes);

        // un modelo de 6 variantes: el resumen de los modelos de la página sale en dos consultas
        $this->crearPolo();
        $conSeis = $consultas('/catalogo');
        $delModelo = fn (array $hechas) => array_values(array_filter($hechas, fn ($sql) => str_contains($sql, 'producto_grupos') || str_contains($sql, 'jsonb_each_text')));
        $this->assertCount(2, $delModelo($conSeis));
        // y ninguna trae las variantes del modelo una por una
        $leeVariantes = fn (array $hechas) => array_filter($hechas, fn ($sql) => str_starts_with($sql, 'select "productos".*') && str_contains($sql, '"productos"."grupo_id" in'));
        $this->assertSame([], $leeVariantes($conSeis));

        // otro modelo de 30 variantes y el primero ampliado a 18: las mismas consultas (no crecen con las variantes)
        app(VarianteService::class)->guardarGrupo($this->admin, [
            'nombre' => 'Casaca', 'categoria_id' => null, 'marca_id' => null, 'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'), 'precio_base' => 120, 'costo' => null,
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
                ['nombre' => 'Color', 'valores' => ['Negro', 'Azul', 'Gris', 'Verde', 'Rojo']],
            ],
            'variantes' => [],
        ]);
        $this->crearPoloEditado(ProductoGrupo::where('empresa_id', $this->empresa->id)->where('nombre', 'Polo básico')->firstOrFail());
        $conMuchas = $consultas('/catalogo');
        $this->assertCount(count($conSeis), $conMuchas);
        $this->assertCount(2, $delModelo($conMuchas));
        $this->assertSame([], $leeVariantes($conMuchas));

        $html = $this->tienda('/catalogo')->assertSee('6 tallas · 5 colores')->assertSee('3 tallas · 6 colores')->getContent();
        $this->assertSame(1, substr_count($html, 'data-tarjeta-modelo="30"'));
        $this->assertSame(1, substr_count($html, 'data-tarjeta-modelo="18"'));
        // en un listado no viajan las variantes una por una
        $tarjetas = app(CatalogoTiendaService::class)->tarjetas(
            app(CatalogoTiendaService::class)->productos($this->empresa)->whereNotNull('productos.grupo_id')->limit(2)->get(), Tienda::config($this->empresa), $this->empresa,
        );
        $this->assertArrayNotHasKey('variantes', $tarjetas[0]['modelo']);
        $this->assertArrayNotHasKey('selector', $tarjetas[0]['modelo']);
    }

    /** El mismo polo con más colores: de 6 a 18 variantes. */
    private function crearPoloEditado(ProductoGrupo $polo): void
    {
        app(VarianteService::class)->guardarGrupo($this->admin, [
            'nombre' => 'Polo básico', 'categoria_id' => $polo->categoria_id, 'marca_id' => $polo->marca_id, 'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'), 'precio_base' => 35, 'costo' => null,
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul', 'Negro', 'Blanco', 'Gris', 'Verde']],
            ],
            'variantes' => [],
        ], $polo);
    }

    public function test_con_el_modulo_variantes_apagado_la_tienda_no_agrupa(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();
        $mRojo = $this->variante($polo, 'M', 'Rojo');
        $this->darStock($mRojo, 4, 10);
        $this->empresa->update(['variantes_activo' => false]);

        $catalogo = app(CatalogoTiendaService::class);
        $empresa = $this->empresa->fresh();
        $this->assertFalse($catalogo->tieneModelos($empresa));
        // cada variante vuelve a ser un producto: 2 sueltos + 6 variantes
        $this->assertSame(8, $catalogo->total($empresa));
        $this->assertSame(6, $catalogo->categorias($empresa)->firstWhere('nombre', 'Ropa')->productos);
        $this->assertSame(6, $catalogo->marcas($empresa)->firstWhere('nombre', 'Inka')['productos']);

        $html = $this->tienda('/catalogo')->assertOk()->assertSee('8 productos')->assertSee('Polo básico M Rojo')->assertSee('Polo básico L Azul')->getContent();
        $this->assertSame(8, substr_count($html, '<article'));
        foreach (['data-tarjeta-modelo', 'data-modelo-', 'data-elegir-modelo', 'Ver opciones', '3 tallas'] as $nuevo) {
            $this->assertStringNotContainsString($nuevo, $html);
        }
        // se añade como cualquier producto, sin el dato de variante
        preg_match_all('/data-anadir="([^"]+)"/', $html, $botones);
        $anadir = collect($botones[1])->map(fn ($b) => json_decode(html_entity_decode($b), true))->firstWhere('nombre', 'Polo básico M Rojo');
        $this->assertSame(['id', 'nombre', 'unidad', 'precio', 'imagen', 'url'], array_keys($anadir));

        // la página de la variante es la de un producto suelto: su nombre, su canónica, sin selector
        $pagina = $this->tienda('/catalogo/polo-basico-m-rojo')->assertOk()
            ->assertSee('<link rel="canonical" href="http://agro.tienda.test/catalogo/polo-basico-m-rojo">', false)
            ->assertDontSee('data-elegir-variante', false)
            ->assertDontSee('data-variantes', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>Polo básico M Rojo<\/h1>/', $pagina);
        $this->assertStringContainsString('"@type":"Offer"', $pagina);
        // las otras tallas salen entre los relacionados, como productos
        $this->assertStringContainsString('Polo básico L Azul', $pagina);

        $this->assertSame(6, substr_count($this->tienda('/catalogo?q=polo')->getContent(), '<article'));
        $this->tienda('/buscar?q=polo')->assertJsonPath('total', 6);
        $this->assertSame(6, substr_count($this->tienda('/sitemap.xml')->getContent(), '/catalogo/polo-basico-'));
        $this->assertSame(6, substr_count($this->tienda('/categoria/ropa')->getContent(), '<article'));

        // el asistente tampoco habla de modelos
        $r = (new BuscarProductos($empresa, null, true))->handle(new ToolRequest(['consulta' => 'polo']));
        $this->assertGreaterThan(1, substr_count($r, 'Producto: '));
        $this->assertStringNotContainsString('Modelo:', $r);
        $this->assertStringNotContainsString('Variantes', $r);
        $this->assertStringNotContainsString('Modelo:', (new DetalleProducto($empresa, null, true))->handle(new ToolRequest(['codigo' => $mRojo->codigo_interno])));

        // al encenderlo de nuevo, vuelve a agrupar
        $this->empresa->update(['variantes_activo' => true]);
        $this->assertSame(1, substr_count($this->tienda('/catalogo')->assertSee('3 productos')->getContent(), 'data-tarjeta-modelo="6"'));
    }

    public function test_el_precio_del_modelo_en_la_portada_y_el_orden_por_precio_usan_el_mas_bajo(): void
    {
        // el polo va de S/ 20 (L Azul, sin stock) a S/ 200 (S Rojo, la única con stock y por eso la representativa)
        $polo = $this->crearPolo(['precio_base' => 200, 'variantes' => [['valores' => ['L', 'Azul'], 'precio_venta' => 20]]]);
        $this->darStock($this->variante($polo, 'S', 'Rojo'), 3, 10);
        foreach ($polo->variantes as $variante) {
            $variante->update(['imagen_url' => '/storage/productos/polo.jpg', 'destacado' => true]);
        }

        $orden = function (string $ruta): array {
            preg_match_all('/<h3[^>]*>([^<]+)<\/h3>/', $this->tienda($ruta)->assertOk()->getContent(), $titulos);

            return array_map('html_entity_decode', $titulos[1]);
        };

        $this->publicar();
        // de menor a mayor el polo va primero por sus S/ 20 (no por los S/ 200 de la variante que lo representa)
        $this->assertSame(['Polo básico', 'Glifosato 480 SL x 1 L', 'Urea 46% x 50 kg'], $orden('/catalogo?orden=menor'));
        // de mayor a menor también cuenta su precio más bajo: queda último
        $this->assertSame(['Urea 46% x 50 kg', 'Glifosato 480 SL x 1 L', 'Polo básico'], $orden('/catalogo?orden=mayor'));

        // la vitrina de destacados de la portada dice "Desde" el más bajo, igual que la tarjeta
        $portada = $this->tienda('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<p[^>]*data-modelo-precio>Desde S\/ 20\.00<\/p>/', $portada);
        $this->assertStringNotContainsString('S/ 200.00', $portada);

        $this->publicar(['plantilla' => 'mercado']);
        $portada = $this->tienda('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<span[^>]*data-modelo-precio>Desde S\/ 20\.00<\/span>/', $portada);
        $this->assertSame(['Polo básico', 'Glifosato 480 SL x 1 L', 'Urea 46% x 50 kg'], $orden('/catalogo?orden=menor'));
    }

    public function test_la_foto_de_una_variante_sin_foto_es_la_del_modelo(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();
        $this->variante($polo, 'L', 'Azul')->update(['imagen_url' => '/storage/productos/polo-azul.jpg']);

        // la página de una variante sin foto ya trae la imagen (la de otra variante) y el JSON la da para todas
        $html = $this->tienda('/catalogo/polo-basico-s-rojo')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<img src="\/storage\/productos\/polo-azul\.jpg"[^>]*data-variante-imagen/', $html);
        preg_match('/<script type="application\/json" data-variantes>(.*?)<\/script>/s', $html, $json);
        $this->assertSame(['/storage/productos/polo-azul.jpg'], collect(json_decode($json[1], true))->pluck('imagen')->unique()->values()->all());

        // con foto propia manda la suya; las demás siguen con la del modelo
        $this->variante($polo, 'S', 'Rojo')->update(['imagen_url' => '/storage/productos/polo-rojo.jpg']);
        $polo->update(['imagen_url' => '/storage/productos/polo-modelo.jpg']);
        $html = $this->tienda('/catalogo/polo-basico-m-rojo')->assertOk()->getContent();
        preg_match('/<script type="application\/json" data-variantes>(.*?)<\/script>/s', $html, $json);
        $fotos = collect(json_decode($json[1], true))->mapWithKeys(fn ($v) => [implode(' ', $v['valores']) => $v['imagen']]);
        $this->assertSame('/storage/productos/polo-rojo.jpg', $fotos['S Rojo']);
        $this->assertSame('/storage/productos/polo-azul.jpg', $fotos['L Azul']);
        $this->assertSame('/storage/productos/polo-modelo.jpg', $fotos['M Rojo']);
        $this->assertMatchesRegularExpression('/<img src="\/storage\/productos\/polo-modelo\.jpg"[^>]*data-variante-imagen/', $html);
        // y la tarjeta del catálogo también
        $this->assertStringContainsString('src="/storage/productos/polo-', $this->tienda('/catalogo')->getContent());
    }

    public function test_en_celular_el_boton_de_la_barra_pregunta_si_la_variante_esta_agotada(): void
    {
        $this->publicar();
        $polo = $this->crearPolo();
        $this->darStock($this->variante($polo, 'M', 'Rojo'), 4, 10);

        $this->tienda('/catalogo/polo-basico-m-rojo')->assertSee('<span data-variante-texto-barra>Pedir</span>', false);
        $this->tienda('/catalogo/polo-basico-s-azul')->assertSee('<span data-variante-texto-barra>Preguntar</span>', false);
        // el botón "Ver opciones" de la tarjeta mide 44 px en celular, en las dos plantillas
        $this->assertMatchesRegularExpression('/data-elegir-modelo\s+class="[^"]*\bh-11\b[^"]*\bsm:h-10\b/', $this->tienda('/catalogo')->getContent());
        $this->publicar(['plantilla' => 'mercado']);
        $this->assertMatchesRegularExpression('/data-elegir-modelo\s+class="[^"]*\bh-11\b[^"]*\bsm:h-10\b/', $this->tienda('/catalogo')->getContent());
    }

    public function test_nombres_y_valores_con_html_o_comillas_no_rompen_la_pagina(): void
    {
        $this->publicar();
        $polo = $this->crearPolo([
            'nombre' => 'Polo "Pro" </script><script>alert(1)</script>',
            'atributos' => [
                ['nombre' => 'Talla "T"', 'valores' => ['S', 'M</script><b>x']],
                ['nombre' => 'Color', 'valores' => ['Ro"jo', "Azul'<i>"]],
            ],
        ]);
        $variante = $polo->variantes()->get()->first(fn (Producto $p) => $p->atributos['Color'] === 'Ro"jo' && $p->atributos['Talla "T"'] === 'M</script><b>x');
        $this->darStock($variante, 2, 10);

        foreach (["/catalogo/{$variante->slug}", '/catalogo', '/'] as $ruta) {
            $html = $this->tienda($ruta)->assertOk()->getContent();
            // nada de lo escrito por el dueño llega como etiqueta
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('<b>x', $html);
            $this->assertStringNotContainsString('<i>', $html);
        }

        $html = $this->tienda("/catalogo/{$variante->slug}")->getContent();
        // el JSON del selector sigue siendo JSON válido y trae los valores tal cual
        $this->assertSame(1, preg_match('/<script type="application\/json" data-variantes>(.*?)<\/script>/s', $html, $json));
        $variantes = collect(json_decode($json[1], true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(4, $variantes);
        $actual = $variantes->firstWhere('id', (string) $variante->id);
        $this->assertSame(['M</script><b>x', 'Ro"jo'], $actual['valores']);
        $this->assertSame('Talla "T" M</script><b>x, Color Ro"jo', $actual['anadir']['variante']);
        // en los atributos va escapado, y al leerlo el navegador obtiene el mismo valor que en el JSON
        $this->assertStringContainsString('data-valor="M&lt;/script&gt;&lt;b&gt;x"', $html);
        $this->assertStringContainsString('data-valor="Ro&quot;jo"', $html);
        $this->assertStringContainsString('data-atributo-variante="Talla &quot;T&quot;"', $html);
        preg_match_all('/data-valor="([^"]*)"/', $html, $valores);
        $this->assertEqualsCanonicalizing(['S', 'M</script><b>x', 'Ro"jo', "Azul'<i>"], array_map(fn ($v) => html_entity_decode($v, ENT_QUOTES), $valores[1]));
        // el botón de añadir lleva JSON válido dentro del atributo
        preg_match('/data-anadir="([^"]+)"/', $html, $boton);
        $this->assertSame((string) $variante->id, json_decode(html_entity_decode($boton[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR)['id']);
        // los datos estructurados también
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $bloques);
        $producto = collect($bloques[1])->map(fn ($b) => json_decode($b, true, flags: JSON_THROW_ON_ERROR))->firstWhere('@type', 'Product');
        $this->assertSame('Polo "Pro" </script><script>alert(1)</script>', html_entity_decode($producto['name'], ENT_QUOTES));
    }

    public function test_sin_precios_ni_disponibilidad_el_selector_no_los_lleva(): void
    {
        $this->publicar(['mostrar_precios' => false, 'mostrar_stock' => false]);
        $polo = $this->crearPolo(['variantes' => [['valores' => ['L', 'Azul'], 'precio_venta' => 39.9]]]);
        $this->darStock($this->variante($polo, 'M', 'Rojo'), 4, 10);

        $html = $this->tienda('/catalogo/polo-basico-s-azul')->assertOk()
            ->assertSee('Consulta el precio')
            ->assertDontSee('Agotado')
            ->assertDontSee('data-variante-estado', false)
            ->assertDontSee('data-variante-agotada', false)
            ->getContent();

        preg_match('/<script type="application\/json" data-variantes>(.*?)<\/script>/s', $html, $json);
        $variantes = collect(json_decode($json[1], true));
        $this->assertCount(6, $variantes);
        // ni precio ni disponibilidad ni stock: nada que permita saber cuál hay
        $this->assertSame([null], $variantes->pluck('precio')->unique()->values()->all());
        $this->assertSame([null], $variantes->pluck('disponible')->unique()->values()->all());
        $this->assertSame([null], $variantes->pluck('mayorista')->unique()->values()->all());
        $this->assertSame([null], $variantes->pluck('anadir.precio')->unique()->values()->all());
        foreach ($variantes as $variante) {
            $this->assertSame(['id', 'nombre', 'valores', 'url', 'precio', 'mayorista', 'disponible', 'imagen', 'pedido', 'anadir'], array_keys($variante));
        }
        $this->assertStringNotContainsString('39.9', $json[1]);
        $this->assertStringNotContainsString('35', preg_replace('/"(id|pedido)":"[^"]*"/', '', $json[1]));
        // sin disponibilidad a la vista, cada opción lleva a la combinación exacta (no se salta por un stock que no se muestra)
        $opciones = $this->opciones($html);
        $this->assertSame('/catalogo/polo-basico-m-azul', $opciones['0:M']['url']);
        $this->assertSame('/catalogo/polo-basico-s-rojo', $opciones['1:Rojo']['url']);
        $this->assertSame([], array_filter($opciones, fn ($o) => $o['agotada'] || $o['salta']));
        // todas se pueden añadir
        $this->assertDoesNotMatchRegularExpression('/data-variante-zona\s+hidden/', $html);

        // la tarjeta del modelo tampoco muestra precio ni "Agotado"
        $catalogo = $this->tienda('/catalogo')->assertOk()->assertSee('3 tallas · 2 colores')->assertDontSee('Desde')->getContent();
        $this->assertStringNotContainsString('35.00', $catalogo);
        $this->assertStringNotContainsString('>Agotado</span>', $catalogo);
    }

    public function test_la_paginacion_con_muchos_modelos_y_un_filtro_no_repite_ni_pierde_ninguno(): void
    {
        $this->publicar();
        $ropa = Categoria::firstOrCreate(['empresa_id' => $this->empresa->id, 'nombre' => 'Ropa']);
        $afectacion = TipoAfectacionIgv::where('afecto', true)->value('codigo');

        // 30 modelos de 3 tallas en "Ropa" (90 productos) y 2 sueltos fuera: más de una página de 24
        for ($i = 1; $i <= 30; $i++) {
            $modelo = app(VarianteService::class)->guardarGrupo($this->admin, [
                'nombre' => sprintf('Camisa modelo %02d', $i), 'categoria_id' => $ropa->id, 'marca_id' => null, 'unidad_base_codigo' => 'NIU',
                'tipo_afectacion_codigo' => $afectacion, 'precio_base' => 50 + $i, 'costo' => null,
                'atributos' => [['nombre' => 'Talla', 'valores' => ['S', 'M', 'L']]],
                'variantes' => [],
            ]);
            // stock salteado: a veces la representativa es S, a veces L, a veces ninguna
            if ($i % 3 !== 0) {
                $this->darStock($modelo->variantes()->get()[$i % 3 === 1 ? 0 : 2], 1, 10);
            }
        }

        $titulos = function (string $ruta): array {
            preg_match_all('/<h3[^>]*>([^<]+)<\/h3>/', $this->tienda($ruta)->assertOk()->getContent(), $encontrados);

            return $encontrados[1];
        };

        foreach (['/categoria/ropa?', '/catalogo?q=camisa&', '/categoria/ropa?orden=menor&', '/catalogo?q=camisa+modelo&orden=mayor&'] as $base) {
            $primera = $titulos("{$base}page=1");
            $segunda = $titulos("{$base}page=2");
            $this->assertCount(24, $primera, $base);
            $this->assertCount(6, $segunda, $base);
            $todos = [...$primera, ...$segunda];
            $this->assertCount(30, array_unique($todos), "{$base}: ningún modelo repetido");
            $esperados = array_map(fn ($i) => sprintf('Camisa modelo %02d', $i), range(1, 30));
            $this->assertEqualsCanonicalizing($esperados, $todos, "{$base}: ningún modelo perdido");
            $this->tienda("{$base}page=3")->assertNotFound();
        }
        $this->tienda('/categoria/ropa')->assertSee('30 productos');
        $this->assertSame(32, app(CatalogoTiendaService::class)->total($this->empresa));
        // todo el catálogo: 32 tarjetas en dos páginas
        $this->assertCount(32, [...$titulos('/catalogo?page=1'), ...$titulos('/catalogo?page=2')]);
    }

    public function test_el_asistente_describe_las_variantes_del_modelo_sin_listar_cada_producto(): void
    {
        $this->publicar();
        $polo = $this->crearPolo(['variantes' => [['valores' => ['L', 'Azul'], 'precio_venta' => 39.9]]]);
        $mRojo = $this->variante($polo, 'M', 'Rojo');
        $this->darStock($mRojo, 4, 10);
        $this->darStock($this->variante($polo, 'L', 'Azul'), 2, 10);

        // en la tienda (público): un solo resultado para el modelo, con sus tallas, colores y cuáles hay
        $buscar = new BuscarProductos($this->empresa, null, true);
        $r = $buscar->handle(new ToolRequest(['consulta' => 'polo']));
        $this->assertSame(1, substr_count($r, 'Producto: '));
        $this->assertStringContainsString('Modelo: Polo básico', $r);
        $this->assertStringContainsString('Variantes: Talla S, M, L · Color Rojo, Azul', $r);
        $this->assertStringContainsString("Variantes con stock: M Rojo (código {$mRojo->codigo_interno}, S/ 35.00); L Azul (código ", $r);
        $this->assertMatchesRegularExpression('/Variantes agotadas: S Rojo \(código P\d+, S\/ 35\.00\); S Azul/', $r);
        // en público nunca cuántas unidades
        $this->assertStringNotContainsString('stock 4', $r);

        // "polo rojo talla m" lleva a esa variante, y su descripción sigue contando las demás
        $r = $buscar->handle(new ToolRequest(['consulta' => 'polo rojo m']));
        $this->assertSame(1, substr_count($r, 'Producto: '));
        $this->assertStringContainsString('Variantes: Talla S, M, L · Color Rojo, Azul', $r);

        // la ficha de una variante también lo dice
        $ficha = (new DetalleProducto($this->empresa, null, true))->handle(new ToolRequest(['codigo' => $mRojo->codigo_interno]));
        $this->assertStringContainsString('Producto: Polo básico M Rojo', $ficha);
        $this->assertStringContainsString('Variantes con stock: M Rojo', $ficha);

        // una variante oculta de la tienda no se le cuenta al cliente
        $this->variante($polo, 'S', 'Azul')->update(['en_tienda' => false]);
        $this->assertStringNotContainsString('S Azul', $buscar->handle(new ToolRequest(['consulta' => 'polo'])));

        // dentro del sistema (no público) el asistente ve las unidades de cada variante
        $interno = (new BuscarProductos($this->empresa, $this->sucursal))->handle(new ToolRequest(['consulta' => 'polo']));
        $this->assertSame(1, substr_count($interno, 'Producto: '));
        $this->assertStringContainsString("M Rojo (código {$mRojo->codigo_interno}, S/ 35.00, stock 4)", $interno);

        // un producto sin modelo se describe como siempre
        $r = $buscar->handle(new ToolRequest(['consulta' => 'urea']));
        $this->assertStringContainsString('Producto: Urea 46% x 50 kg (código P0006)', $r);
        $this->assertStringNotContainsString('Variantes', $r);
        $this->assertStringNotContainsString('Modelo:', $r);
    }
}
