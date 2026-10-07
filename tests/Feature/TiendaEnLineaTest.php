<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Suscripcion;
use App\Support\Tienda;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class TiendaEnLineaTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $urea;

    private Producto $glifosato;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        // la tienda es un adicional que activa la plataforma: aqui ya esta contratado
        $this->empresa->forceFill(['tienda_habilitada' => true])->save();

        $fertilizantes = Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Fertilizantes']);
        $farmex = Marca::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Farmex']);

        $this->urea = $this->crearProducto(precio: 145, atributos: ['nombre' => 'Urea 46% x 50 kg', 'codigo_interno' => 'P0006', 'categoria_id' => $fertilizantes->id, 'marca_id' => $farmex->id]);
        $this->glifosato = $this->crearProducto(precio: 28, atributos: ['nombre' => 'Glifosato 480 SL x 1 L', 'codigo_interno' => 'P0001']);
        $this->darStock($this->urea, 10, 100);
        $this->darStock($this->glifosato, 5, 20);
    }

    /** Publica la tienda de la empresa en agro.tienda.test */
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

        // en las pruebas, una ruta relativa se arma con el dominio del ultimo pedido:
        // se vuelve al de la app para que lo siguiente ("/pos/ventas", "/login"...) no caiga en la tienda
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    private function datosDeTienda(array $cambios = []): array
    {
        return [
            'slug' => 'agro-campo', 'publicada' => true, 'descripcion' => 'Insumos para tu campo', 'color' => 'azul',
            'mostrar_precios' => true, 'mostrar_stock' => true, 'whatsapp' => '987 654 321', 'telefono' => null,
            'email' => 'ventas@agrocampo.pe', 'direccion' => 'Av. Principal 123', 'horario' => 'Lunes a sábado',
            'facebook' => 'agrocampo', 'instagram' => null, 'tiktok' => null,
            ...$cambios,
        ];
    }

    // ---------------- configuración ----------------

    public function test_el_administrador_configura_y_publica_su_tienda(): void
    {
        $this->actingAs($this->admin)->get('/tienda-en-linea')->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Tienda/Configurar')
            ->where('tienda.publicada', false)
            ->where('tienda.slug', null)
            ->where('tienda.dominio', 'tienda.test')
            ->where('tienda.config.mostrar_precios', false) // los precios no se publican sin que el dueño lo decida
            ->where('tienda.config.mostrar_stock', false) // y la tienda no mira el stock salvo que él lo pida
            ->where('tienda.config.color', 'esmeralda')
            ->where('resumen.visibles', 2)
            ->has('productos.data', 2));

        $this->actingAs($this->admin)->put('/tienda-en-linea', $this->datosDeTienda())
            ->assertSessionHas('success', 'Tu tienda ya está publicada.');

        $empresa = $this->empresa->fresh();
        $this->assertSame('agro-campo', $empresa->tienda_slug);
        $this->assertTrue($empresa->tienda_publicada);
        $this->assertSame('azul', $empresa->tienda_config['color']);
        $this->assertSame('Insumos para tu campo', $empresa->tienda_config['descripcion']);
        $this->assertNull($empresa->tienda_config['instagram']);
        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->empresa->id, 'accion' => 'tienda.publicacion']);

        $this->tienda('/', 'agro-campo')->assertOk()->assertSee('Insumos para tu campo');

        $this->actingAs($this->admin)->put('/tienda-en-linea', $this->datosDeTienda(['publicada' => false]))
            ->assertSessionHas('success', 'Tu tienda dejó de estar visible.');
        $this->tienda('/', 'agro-campo')->assertNotFound();
    }

    public function test_la_direccion_debe_ser_valida_libre_y_no_reservada(): void
    {
        $guardar = fn (array $cambios) => $this->actingAs($this->admin)->put('/tienda-en-linea', $this->datosDeTienda($cambios));

        foreach (['ab', 'con espacio', 'ñandú', '-guion', 'guion-', 'doble--guion', 'pos', 'www', 'admin'] as $malo) {
            $guardar(['slug' => $malo])->assertSessionHasErrors('slug');
        }
        $this->assertNull($this->empresa->fresh()->tienda_slug);

        // mayusculas y espacios alrededor se corrigen solos
        $guardar(['slug' => '  Agro-Campo '])->assertSessionHasNoErrors();
        $this->assertSame('agro-campo', $this->empresa->fresh()->tienda_slug);

        // otra empresa no puede tomar la misma direccion
        $primera = $this->empresa;
        $this->crearEscenarioBase();
        $this->empresa->forceFill(['tienda_habilitada' => true])->save();
        $guardar(['slug' => 'agro-campo'])->assertSessionHasErrors(['slug' => 'Esa dirección ya la usa otro negocio. Prueba con otra.']);
        $this->assertSame('agro-campo', $primera->fresh()->tienda_slug);
    }

    public function test_no_se_publica_sin_un_contacto_y_solo_el_administrador_configura(): void
    {
        $this->actingAs($this->admin)->put('/tienda-en-linea', $this->datosDeTienda(['whatsapp' => null, 'telefono' => null]))
            ->assertSessionHasErrors('whatsapp');
        $this->assertFalse((bool) $this->empresa->fresh()->tienda_publicada);

        // sin publicar si se puede guardar a medias
        $this->actingAs($this->admin)->put('/tienda-en-linea', $this->datosDeTienda(['whatsapp' => null, 'publicada' => false]))
            ->assertSessionHasNoErrors();

        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $this->actingAs($cajero)->get('/tienda-en-linea')->assertForbidden();
        $this->actingAs($cajero)->put('/tienda-en-linea', $this->datosDeTienda(['slug' => 'robada']))->assertForbidden();
        $this->actingAs($cajero)->patch("/tienda-en-linea/productos/{$this->urea->id}", ['en_tienda' => false])->assertForbidden();
        $this->assertTrue($this->urea->fresh()->en_tienda);
    }

    public function test_ocultar_destacar_y_describir_productos(): void
    {
        $cambiar = fn (Producto $p, array $datos) => $this->actingAs($this->admin)->patch("/tienda-en-linea/productos/{$p->id}", $datos);

        $cambiar($this->urea, ['destacado' => true])->assertSessionHasNoErrors();
        $cambiar($this->urea, ['descripcion' => "  Fertilizante nitrogenado.\nSaco de 50 kg.  "]);
        $this->assertTrue($this->urea->fresh()->destacado);
        $this->assertSame("Fertilizante nitrogenado.\nSaco de 50 kg.", $this->urea->fresh()->descripcion);

        // ocultarlo le quita la estrella; destacar uno oculto lo vuelve a mostrar
        $cambiar($this->urea, ['en_tienda' => false]);
        $this->assertFalse($this->urea->fresh()->en_tienda);
        $this->assertFalse($this->urea->fresh()->destacado);
        $cambiar($this->urea, ['destacado' => true]);
        $this->assertTrue($this->urea->fresh()->en_tienda);

        // tope de destacados
        foreach (range(1, 7) as $i) {
            $this->crearProducto(atributos: ['nombre' => "Extra {$i}", 'destacado' => true]);
        }
        $cambiar($this->glifosato, ['destacado' => true])->assertSessionHas('error');
        $this->assertFalse($this->glifosato->fresh()->destacado);

        $this->actingAs($this->admin)->get('/tienda-en-linea?ver=destacados')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('productos.data', 8)
            ->where('resumen.destacados', 8));

        // un producto de otra empresa no se toca
        $ajeno = $this->glifosato;
        $this->crearEscenarioBase();
        $cambiar($ajeno, ['en_tienda' => false])->assertForbidden();
        $this->assertTrue($ajeno->fresh()->en_tienda);
    }

    // ---------------- tienda pública ----------------

    public function test_la_portada_muestra_el_catalogo_con_buscador_y_contactos(): void
    {
        $this->publicar(['descripcion' => 'Insumos para tu campo', 'telefono' => '(01) 246 1234', 'email' => 'ventas@agro.pe', 'direccion' => 'Av. Los Agricultores 245', 'horario' => 'Lunes a sábado', 'instagram' => '@agrocampo']);

        $this->tienda('/')
            ->assertOk()
            ->assertSee('<title>Agro Campo | Catálogo y pedidos en línea</title>', false)
            ->assertSee('Insumos para tu campo')
            ->assertSee('"@type":"Store"', false) // datos del negocio para el buscador

            ->assertSee('name="q"', false) // buscador
            ->assertSee('Productos destacados')
            ->assertSee('Urea 46% x 50 kg')
            ->assertSee('Glifosato 480 SL x 1 L')
            ->assertSee('145.00')
            ->assertSee('Farmex')
            ->assertSee('Fertilizantes')
            // contactos
            ->assertSee('Contáctanos')
            ->assertSee('https://wa.me/51987654321', false)
            ->assertSee('tel:012461234', false)
            ->assertSee('mailto:ventas@agro.pe', false)
            ->assertSee('Av. Los Agricultores 245')
            ->assertSee('Lunes a sábado')
            ->assertSee('https://www.instagram.com/agrocampo', false)
            // direcciones amigables: sin códigos ni identificadores
            ->assertSee('href="/producto/urea-46-x-50-kg"', false)
            ->assertSee('href="/categoria/fertilizantes"', false)
            ->assertDontSee('?categoria=', false)
            ->assertDontSee('/producto/P0006', false);
    }

    public function test_la_tienda_es_publica_sin_sesion_y_no_expone_el_sistema(): void
    {
        $this->publicar();

        $respuesta = $this->tienda('/');
        $respuesta->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertSame([], $respuesta->headers->getCookies(), 'La tienda no debe abrir sesión ni dejar cookies');

        // en la direccion de la tienda no existe el sistema
        foreach (['/login', '/registro', '/dashboard', '/pos', '/tienda-en-linea'] as $ruta) {
            $this->tienda($ruta)->assertNotFound();
        }
        $this->get('/login')->assertOk();
        $this->actingAs($this->admin)->get('http://agro.tienda.test/dashboard')->assertNotFound();
        URL::setRequest(Request::create(config('app.url')));

        // y el sistema sigue en su dominio
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
    }

    public function test_solo_se_ven_las_tiendas_publicadas_de_empresas_vigentes(): void
    {
        $this->tienda('/')->assertNotFound(); // aun sin direccion

        $this->publicar();
        $this->tienda('/')->assertOk();
        $this->tienda('/', 'no-existe')->assertNotFound()->assertSee('No encontramos esta página');

        // suscripcion vencida mas alla de la gracia
        Suscripcion::where('empresa_id', $this->empresa->id)->update([
            'fecha_inicio' => now()->subDays(40)->toDateString(), 'fecha_fin' => now()->subDays(10)->toDateString(),
        ]);
        $this->tienda('/')->assertNotFound();

        Suscripcion::where('empresa_id', $this->empresa->id)->update(['fecha_fin' => now()->addDays(10)->toDateString()]);
        $this->tienda('/')->assertOk();

        // empresa desactivada por la plataforma
        $this->empresa->update(['activo' => false]);
        $this->tienda('/')->assertNotFound();
    }

    public function test_no_aparecen_productos_ocultos_inactivos_ni_de_otra_empresa(): void
    {
        $this->publicar();
        $this->glifosato->update(['en_tienda' => false]);
        $inactivo = $this->crearProducto(atributos: ['nombre' => 'Producto descontinuado', 'activo' => false]);
        $sinPresentacion = $this->crearProducto(atributos: ['nombre' => 'Producto sin presentacion']);
        $sinPresentacion->presentaciones()->update(['activo' => false]);

        $miEmpresa = $this->empresa;
        $this->crearEscenarioBase();
        $ajeno = $this->crearProducto(atributos: ['nombre' => 'Producto de la competencia', 'codigo_interno' => 'AJENO1']);
        $this->empresa = $miEmpresa;

        $this->tienda('/')
            ->assertSee('Urea 46% x 50 kg')
            ->assertDontSee('Glifosato 480 SL x 1 L')
            ->assertDontSee('Producto descontinuado')
            ->assertDontSee('Producto sin presentacion')
            ->assertDontSee('Producto de la competencia');

        $this->tienda('/producto/P0001/glifosato')->assertNotFound();
        $this->tienda("/producto/{$inactivo->id}/x")->assertNotFound();
        $this->tienda('/producto/AJENO1/x')->assertNotFound();
        $this->tienda("/producto/{$ajeno->id}/x")->assertNotFound();
    }

    public function test_el_buscador_encuentra_por_palabras_marca_y_codigo_y_filtra_por_categoria(): void
    {
        $this->publicar();

        $this->tienda('/?q=urea+50')->assertOk()
            ->assertSee('Resultados para “urea 50”', false)
            ->assertSee('Urea 46% x 50 kg')
            ->assertDontSee('Glifosato 480 SL x 1 L')
            ->assertDontSee('Productos destacados')
            ->assertSee('noindex', false);

        $this->tienda('/?q=farmex')->assertSee('Urea 46% x 50 kg')->assertDontSee('Glifosato 480 SL x 1 L');
        $this->tienda('/?q=P0001')->assertSee('Glifosato 480 SL x 1 L')->assertDontSee('Urea 46% x 50 kg');
        // los comodines de LIKE se buscan como texto: "%" solo coincide con el nombre que lo tiene
        $this->tienda('/?q=%25')->assertSee('Urea 46% x 50 kg')->assertDontSee('Glifosato 480 SL x 1 L');
        $this->tienda('/?q=zzzz')->assertOk()->assertSee('No encontramos productos con esa búsqueda');

        $this->tienda('/categoria/fertilizantes')->assertOk()
            ->assertSee('<title>Fertilizantes · Agro Campo</title>', false)
            ->assertSee('<link rel="canonical" href="http://agro.tienda.test/categoria/fertilizantes">', false)
            ->assertSee('Urea 46% x 50 kg')
            ->assertDontSee('Glifosato 480 SL x 1 L');
        // dentro de una categoría también se busca y se ordena
        $this->tienda('/categoria/fertilizantes?q=urea&orden=mayor')->assertOk()->assertSee('Urea 46% x 50 kg');
        $this->tienda('/categoria/fertilizantes?q=glifosato')->assertOk()->assertDontSee('Glifosato 480 SL x 1 L');
        $this->tienda('/categoria/no-existe')->assertNotFound();

        // los enlaces de antes (/?categoria={id}) llevan a la dirección nueva, conservando lo demás
        $id = Categoria::where('empresa_id', $this->empresa->id)->value('id');
        $this->tienda("/?categoria={$id}")->assertStatus(301)->assertRedirect('http://agro.tienda.test/categoria/fertilizantes');
        $this->tienda("/?categoria={$id}&q=urea")->assertRedirect('http://agro.tienda.test/categoria/fertilizantes?q=urea');
        // una categoria inventada no rompe: muestra todo
        $this->tienda('/?categoria=no-es-un-id&orden=cualquiera&page=abc')->assertOk()->assertSee('Glifosato 480 SL x 1 L');
    }

    public function test_dos_categorias_con_el_mismo_nombre_tienen_direcciones_distintas(): void
    {
        $this->publicar();
        $otra = Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => 'FERTILIZANTES']);
        $this->glifosato->update(['categoria_id' => $otra->id]);

        $html = $this->tienda('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="/categoria/fertilizantes"', $html);
        $this->assertStringContainsString('href="/categoria/fertilizantes-2"', $html);

        // cada dirección abre solo lo suyo
        $una = $this->tienda('/categoria/fertilizantes')->assertOk()->getContent();
        $dos = $this->tienda('/categoria/fertilizantes-2')->assertOk()->getContent();
        $this->assertNotSame(str_contains($una, 'Glifosato 480 SL x 1 L'), str_contains($dos, 'Glifosato 480 SL x 1 L'));
    }

    public function test_los_destacados_son_los_elegidos_o_si_no_los_mas_vendidos(): void
    {
        $this->publicar();

        // tienda nueva sin ventas ni estrellas: lo mas reciente
        $this->tienda('/')->assertSee('Lo más reciente de nuestra tienda.');

        // con ventas: lo que mas se vende
        $this->abrirCaja();
        $this->venderContado($this->glifosato->presentaciones->first(), 2)->assertSessionHas('success');
        Cache::flush(); // el calculo de los mas vendidos se guarda unos minutos
        $this->tienda('/')->assertSee('Lo que más se llevan nuestros clientes.');

        // con estrellas: manda lo que eligio el dueño
        $this->urea->update(['destacado' => true]);
        $this->tienda('/')->assertSee('Lo que más recomendamos de nuestra tienda.');
    }

    public function test_los_precios_y_la_disponibilidad_se_muestran_solo_si_el_dueno_quiere(): void
    {
        $agotado = $this->crearProducto(precio: 68, atributos: ['nombre' => 'Azoxystrobin 250 SC', 'codigo_interno' => 'P0011']);

        $this->publicar(['mostrar_precios' => false, 'mostrar_stock' => false]);
        $this->tienda('/')->assertSee('Consultar precio')->assertDontSee('145.00')->assertDontSee('Agotado');
        $this->tienda('/producto/urea-46-x-50-kg')->assertSee('Consulta el precio')->assertDontSee('145.00')->assertDontSee('"offers"', false);

        $this->publicar(['mostrar_precios' => true, 'mostrar_stock' => true]);
        $this->tienda('/')->assertSee('145.00')->assertSee('Agotado');
        $this->tienda('/producto/azoxystrobin-250-sc')->assertSee('Agotado por ahora')->assertSee('Preguntar cuándo llega');
        $this->tienda('/producto/urea-46-x-50-kg')->assertSee('Disponible')->assertSee('"price":"145.00"', false);

        // nunca sale cuantas unidades hay ni lo que costo
        $this->tienda('/producto/urea-46-x-50-kg')->assertDontSee('10.000')->assertDontSee('100.00');
        $this->assertNotNull($agotado);
    }

    public function test_el_catalogo_muestra_primero_lo_disponible(): void
    {
        $this->publicar();
        // "Abono" va antes por nombre, pero no tiene stock
        $this->crearProducto(atributos: ['nombre' => 'Abono sin stock', 'codigo_interno' => 'P0900']);

        $html = $this->tienda('/')->assertOk()->getContent();
        $catalogo = substr($html, strpos($html, 'id="catalogo"'));
        $this->assertLessThan(strpos($catalogo, 'Abono sin stock'), strpos($catalogo, 'Urea 46% x 50 kg'));

        // si la tienda no muestra disponibilidad, el orden es solo por nombre
        $this->publicar(['mostrar_stock' => false]);
        $html = $this->tienda('/')->getContent();
        $catalogo = substr($html, strpos($html, 'id="catalogo"'));
        $this->assertLessThan(strpos($catalogo, 'Urea 46% x 50 kg'), strpos($catalogo, 'Abono sin stock'));
    }

    public function test_el_detalle_del_producto_trae_su_ficha_y_el_pedido_por_whatsapp(): void
    {
        $this->publicar(['telefono' => '(01) 246 1234']);
        $this->urea->update(['descripcion' => 'Fertilizante nitrogenado de alta concentración.']);
        $this->agregarPresentacion($this->urea, 'Saco x 50 kg', 50, 140);
        $hermano = $this->crearProducto(precio: 150, atributos: ['nombre' => 'Nitrato de amonio', 'categoria_id' => $this->urea->categoria_id]);

        $respuesta = $this->tienda('/producto/urea-46-x-50-kg');
        $respuesta->assertOk()
            ->assertSee('<title>Urea 46% x 50 kg · Agro Campo</title>', false)
            ->assertSee('Fertilizante nitrogenado de alta concentración.')
            ->assertSee('Farmex')
            ->assertSee('Fertilizantes')
            ->assertSee('Código P0006')
            ->assertSee('Presentaciones')
            ->assertSee('Saco x 50 kg')
            ->assertSee('S/ 140.00')
            ->assertSee('Pedir por WhatsApp')
            ->assertSee('Llamar')
            ->assertSee('También te puede interesar')
            ->assertSee($hermano->nombre)
            ->assertSee('application/ld+json', false)
            ->assertSee('<link rel="canonical" href="http://agro.tienda.test/producto/urea-46-x-50-kg">', false);

        // el mensaje de WhatsApp lleva el producto y su enlace
        preg_match('/href="(https:\/\/wa\.me\/51987654321\?text=[^"]*P0006[^"]*)"/', $respuesta->getContent(), $coincide);
        $mensaje = rawurldecode(html_entity_decode($coincide[1] ?? ''));
        $this->assertStringContainsString('Urea 46% x 50 kg (código P0006)', $mensaje);
        $this->assertStringContainsString('http://agro.tienda.test/producto/urea-46-x-50-kg', $mensaje);

        // la página enlaza a su categoría por su dirección amigable
        $respuesta->assertSee('href="/categoria/fertilizantes"', false);

        // los enlaces de antes, con el código o el id, llevan a la dirección nueva (ya se compartieron por WhatsApp)
        foreach (['/producto/P0006/urea-46-x-50-kg', '/producto/P0006/cualquier-cosa', '/producto/P0006', "/producto/{$this->urea->id}/x"] as $antiguo) {
            $this->tienda($antiguo)->assertStatus(301)->assertRedirect('http://agro.tienda.test/producto/urea-46-x-50-kg');
        }
        $this->tienda('/producto/NOEXISTE/x')->assertNotFound()->assertSee('No encontramos esta página');
        $this->tienda('/producto/no-existe')->assertNotFound();
    }

    public function test_cada_producto_tiene_un_nombre_de_enlace_unico_que_sigue_a_su_nombre(): void
    {
        $this->publicar();
        $this->assertSame('urea-46-x-50-kg', $this->urea->fresh()->slug);

        // mismo nombre en la misma empresa: el segundo se numera
        $gemelo = $this->crearProducto(atributos: ['nombre' => 'UREA 46% X 50 KG']);
        $this->assertSame('urea-46-x-50-kg-2', $gemelo->fresh()->slug);
        $this->tienda('/producto/urea-46-x-50-kg')->assertOk()->assertSee('Código P0006');
        $this->tienda('/producto/urea-46-x-50-kg-2')->assertOk()->assertSee("Código {$gemelo->codigo_interno}");

        // tildes, eñes y símbolos
        $this->assertSame('nandu-fosforo-1-2-litro', $this->crearProducto(atributos: ['nombre' => 'ÑANDÚ Fósforo 1/2 litro'])->slug);
        $this->assertSame('producto', $this->crearProducto(atributos: ['nombre' => '***'])->slug);

        // al cambiar el nombre cambia el enlace; guardar otra cosa no lo toca
        $this->urea->update(['nombre' => 'Urea granulada 50 kg']);
        $this->assertSame('urea-granulada-50-kg', $this->urea->fresh()->slug);
        $this->urea->update(['descripcion' => 'Nueva descripción']);
        $this->assertSame('urea-granulada-50-kg', $this->urea->fresh()->slug);
        $this->tienda('/producto/urea-granulada-50-kg')->assertOk();

        // otra empresa puede usar el mismo nombre sin chocar
        $miEmpresa = $this->empresa;
        $this->crearEscenarioBase();
        $this->assertSame('urea-granulada-50-kg', $this->crearProducto(atributos: ['nombre' => 'Urea granulada 50 kg'])->slug);
        $this->empresa = $miEmpresa;
    }

    public function test_un_nombre_con_html_no_se_ejecuta_en_la_tienda(): void
    {
        $this->publicar(['descripcion' => '<script>alert(1)</script>']);
        $this->urea->update(['nombre' => 'Urea <img src=x onerror=alert(1)>', 'descripcion' => '</script><script>alert(2)</script>']);

        foreach (['/', '/producto/'.$this->urea->fresh()->slug, '/?q=%3Cscript%3Ealert(3)%3C%2Fscript%3E'] as $ruta) {
            $html = $this->tienda($ruta)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
        }
    }

    public function test_el_mapa_del_sitio_lista_la_portada_y_los_productos(): void
    {
        $this->publicar();
        $this->glifosato->update(['en_tienda' => false]);

        $respuesta = $this->tienda('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $respuesta->headers->get('content-type'));
        $respuesta->assertSee('<loc>http://agro.tienda.test/</loc>', false)
            ->assertSee('<loc>http://agro.tienda.test/categoria/fertilizantes</loc>', false)
            ->assertSee('<loc>http://agro.tienda.test/producto/urea-46-x-50-kg</loc>', false)
            ->assertDontSee('glifosato');
    }

    public function test_sin_el_adicional_el_dueno_no_tiene_tienda_y_solo_la_plataforma_lo_activa(): void
    {
        $this->publicar();
        $this->tienda('/')->assertOk();

        $superadmin = $this->crearUsuario('admin', 'plataforma'.random_int(10000, 99999).'@test.local');
        $superadmin->forceFill(['es_superadmin' => true])->save();
        $alternar = fn ($usuario) => $this->actingAs($usuario)->post("/admin/empresas/{$this->empresa->id}/tienda");

        // el dueño no puede darse el adicional a si mismo
        $alternar($this->admin)->assertForbidden();
        $this->assertTrue($this->empresa->fresh()->tienda_habilitada);

        // la plataforma lo quita: la tienda deja de verse y el dueño pierde la pantalla y el menu
        $alternar($superadmin)->assertSessionHas('success');
        $this->assertFalse($this->empresa->fresh()->tienda_habilitada);
        $this->assertDatabaseHas('auditoria', ['accion' => 'plataforma.tienda_desactivada']);
        $this->tienda('/')->assertNotFound();

        $dueno = $this->admin->fresh();
        $this->actingAs($dueno)->get('/tienda-en-linea')->assertForbidden();
        $this->actingAs($dueno)->put('/tienda-en-linea', $this->datosDeTienda())->assertForbidden();
        $this->actingAs($dueno)->patch("/tienda-en-linea/productos/{$this->urea->id}", ['en_tienda' => false])->assertForbidden();
        $this->actingAs($dueno)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina->where('auth.user.empresa.tienda_habilitada', false));
        // conserva lo que habia configurado
        $this->assertSame('agro', $this->empresa->fresh()->tienda_slug);
        $this->assertTrue($this->empresa->fresh()->tienda_publicada);

        // la plataforma lo vuelve a activar: todo regresa como estaba
        $alternar($superadmin)->assertSessionHas('success');
        $this->tienda('/')->assertOk();
        $dueno = $this->admin->fresh();
        $this->actingAs($dueno)->get('/tienda-en-linea')->assertOk();
        $this->actingAs($dueno)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina->where('auth.user.empresa.tienda_habilitada', true));
    }

    public function test_una_empresa_nueva_nace_sin_el_adicional(): void
    {
        $this->crearEscenarioBase();

        $this->assertFalse((bool) $this->empresa->fresh()->tienda_habilitada);
        $this->actingAs($this->admin)->get('/tienda-en-linea')->assertForbidden();
        // y no se puede activar mandando el campo en un formulario del dueño
        $this->empresa->update(['tienda_habilitada' => true]);
        $this->assertFalse((bool) $this->empresa->fresh()->tienda_habilitada);
    }

    // ---------------- apariencia de la portada ----------------

    public function test_el_dueno_personaliza_el_estilo_los_textos_y_el_anuncio(): void
    {
        $this->publicar();
        // por defecto: vitrina, con el nombre del negocio como título
        $this->tienda('/')->assertSee('data-portada="vitrina"', false)->assertSee('Ver catálogo');

        $this->actingAs($this->admin)->put('/tienda-en-linea', $this->datosDeTienda([
            'slug' => 'agro', 'portada_estilo' => 'texto', 'portada_titulo' => 'Todo para tu campo',
            'portada_boton' => 'Mira lo que tenemos', 'anuncio' => 'Envíos a todo Huánuco',
        ]))->assertSessionHasNoErrors();

        $this->tienda('/')->assertOk()
            ->assertSee('data-portada="texto"', false)
            ->assertSee('Todo para tu campo')
            ->assertSee('Mira lo que tenemos')
            ->assertSee('Envíos a todo Huánuco');
        // el anuncio va en todas las páginas
        $this->tienda('/producto/urea-46-x-50-kg')->assertSee('Envíos a todo Huánuco');

        // guardar sin mandar la apariencia (un formulario viejo) no la borra
        $this->actingAs($this->admin->fresh())->put('/tienda-en-linea', collect($this->datosDeTienda(['slug' => 'agro']))->all())->assertSessionHasNoErrors();
        $this->assertSame('texto', $this->empresa->fresh()->tienda_config['portada_estilo']);
        $this->assertSame('Envíos a todo Huánuco', $this->empresa->fresh()->tienda_config['anuncio']);

        // valores fuera de lo permitido
        $this->actingAs($this->admin->fresh())->put('/tienda-en-linea', $this->datosDeTienda(['slug' => 'agro', 'portada_estilo' => 'carrusel']))->assertSessionHasErrors('portada_estilo');
        $this->actingAs($this->admin->fresh())->put('/tienda-en-linea', $this->datosDeTienda(['slug' => 'agro', 'anuncio' => str_repeat('a', 121)]))->assertSessionHasErrors('anuncio');
    }

    public function test_la_foto_de_portada_se_optimiza_se_reemplaza_y_se_quita(): void
    {
        Storage::fake('public');
        $this->publicar();
        $guardar = fn (array $cambios) => $this->actingAs($this->admin->fresh())->put('/tienda-en-linea', $this->datosDeTienda(['slug' => 'agro', ...$cambios]));

        // "Foto grande" sin foto no se puede
        $guardar(['portada_estilo' => 'foto'])->assertSessionHasErrors('portada_imagen');
        // y lo que se sube tiene que ser una imagen
        $guardar(['portada_estilo' => 'foto', 'portada_imagen' => UploadedFile::fake()->create('portada.pdf', 100, 'application/pdf')])->assertSessionHasErrors('portada_imagen');

        // una foto de celular, grande: se guarda reducida
        $guardar(['portada_estilo' => 'foto', 'portada_imagen' => UploadedFile::fake()->image('local.jpg', 4000, 3000)])->assertSessionHasNoErrors();
        $primera = $this->empresa->fresh()->tienda_config['portada_imagen'];
        $this->assertStringStartsWith('/storage/tienda/', $primera);
        $ruta = substr($primera, strlen('/storage/'));
        Storage::disk('public')->assertExists($ruta);
        [$ancho, $alto] = getimagesizefromstring(Storage::disk('public')->get($ruta));
        $this->assertSame([1600, 1200], [$ancho, $alto]); // cabe en 1920x1200 sin deformarse

        $this->tienda('/')->assertSee('data-portada="foto"', false)->assertSee($primera, false);

        // guardar otra cosa conserva la foto
        $guardar(['portada_estilo' => 'foto', 'anuncio' => 'Hola'])->assertSessionHasNoErrors();
        $this->assertSame($primera, $this->empresa->fresh()->tienda_config['portada_imagen']);

        // al reemplazarla, la anterior se borra
        $guardar(['portada_estilo' => 'foto', 'portada_imagen' => UploadedFile::fake()->image('otra.png', 1200, 500)])->assertSessionHasNoErrors();
        $segunda = $this->empresa->fresh()->tienda_config['portada_imagen'];
        $this->assertNotSame($primera, $segunda);
        Storage::disk('public')->assertMissing($ruta);
        $this->assertCount(1, Storage::disk('public')->allFiles('tienda'));

        // quitarla la borra, y la portada vuelve a la vitrina
        $guardar(['portada_estilo' => 'foto', 'portada_imagen_quitar' => true])->assertSessionHasErrors('portada_imagen');
        $guardar(['portada_estilo' => 'vitrina', 'portada_imagen_quitar' => true])->assertSessionHasNoErrors();
        $this->assertNull($this->empresa->fresh()->tienda_config['portada_imagen']);
        $this->assertSame([], Storage::disk('public')->allFiles('tienda'));
        $this->tienda('/')->assertSee('data-portada="vitrina"', false);
    }

    public function test_la_vista_previa_muestra_el_borrador_solo_a_quien_tiene_el_enlace(): void
    {
        Storage::fake('public');
        $this->publicar(['anuncio' => 'Anuncio guardado']);
        $guardado = $this->empresa->fresh()->tienda_config;

        $respuesta = $this->actingAs($this->admin)->postJson('/tienda-en-linea/vista-previa', $this->datosDeTienda([
            'slug' => 'agro', 'portada_estilo' => 'texto', 'portada_titulo' => 'Título en borrador', 'anuncio' => 'Anuncio en borrador',
        ]))->assertOk();
        $enlace = $respuesta->json('url');
        $this->assertStringStartsWith('http://agro.tienda.test/?previa=', $enlace);
        $clave = substr($enlace, strpos($enlace, '=') + 1);

        // nada se guardó: los visitantes siguen viendo lo de antes
        $this->assertSame($guardado, $this->empresa->fresh()->tienda_config);
        $this->tienda('/')->assertSee('Anuncio guardado')->assertDontSee('Anuncio en borrador')->assertDontSee('Vista previa');

        // con el enlace se ve el borrador, marcado como vista previa y sin que lo indexen ni lo guarden cachés
        $previa = $this->get($enlace)->assertOk()
            ->assertSee('Vista previa')
            ->assertSee('Título en borrador')
            ->assertSee('Anuncio en borrador')
            ->assertDontSee('Anuncio guardado')
            ->assertSee('data-portada="texto"', false)
            ->assertSee('noindex,nofollow', false)
            ->assertCookie('tienda_previa', $clave, false);
        $this->assertStringContainsString('no-store', (string) $previa->headers->get('Cache-Control'));

        // la clave viaja en una cookie: se puede seguir navegando por el borrador
        $this->withUnencryptedCookie('tienda_previa', $clave)->get('http://agro.tienda.test/producto/urea-46-x-50-kg')->assertOk()->assertSee('Vista previa')->assertSee('Anuncio en borrador');
        // "Salir" borra la cookie y vuelve a la tienda real
        $this->withUnencryptedCookie('tienda_previa', $clave)->get('http://agro.tienda.test/?previa=salir')->assertRedirect('http://agro.tienda.test')->assertCookieExpired('tienda_previa');

        // una clave inventada, o la de otra tienda, no sirve
        $this->get('http://agro.tienda.test/?previa='.str_repeat('x', 40))->assertOk()->assertDontSee('Anuncio en borrador');
        $this->get("http://otra.tienda.test/?previa={$clave}")->assertNotFound();
        URL::setRequest(Request::create(config('app.url')));
    }

    public function test_la_vista_previa_sirve_antes_de_publicar_y_con_una_foto_sin_guardarla(): void
    {
        Storage::fake('public');
        // tienda con el adicional, pero todavía sin dirección ni publicar
        $this->tienda('/', 'mi-tienda')->assertNotFound();

        $enlace = $this->actingAs($this->admin)->post('/tienda-en-linea/vista-previa', $this->datosDeTienda([
            'slug' => 'mi-tienda', 'publicada' => false, 'whatsapp' => null,
            'portada_estilo' => 'foto', 'portada_imagen' => UploadedFile::fake()->image('local.jpg', 2400, 1000),
        ]), ['Accept' => 'application/json'])->assertOk()->json('url');

        $this->get($enlace)->assertOk()
            ->assertSee('Vista previa')
            ->assertSee('data-portada="foto"', false)
            ->assertSee('src="data:image/', false) // la foto va dentro de la página: no se guardó en ningún lado
            ->assertSee('Urea 46% x 50 kg');
        URL::setRequest(Request::create(config('app.url')));

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($this->empresa->fresh()->tienda_slug);
        $this->assertFalse((bool) $this->empresa->fresh()->tienda_publicada);
        // sin el enlace sigue sin existir
        $this->tienda('/', 'mi-tienda')->assertNotFound();

        // los mismos avisos que al guardar
        $this->actingAs($this->admin)->postJson('/tienda-en-linea/vista-previa', $this->datosDeTienda(['slug' => 'pos']))->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->actingAs($this->admin)->postJson('/tienda-en-linea/vista-previa', $this->datosDeTienda(['portada_estilo' => 'foto']))->assertStatus(422)->assertJsonValidationErrors('portada_imagen');

        // solo quien puede configurar la tienda, y solo con el adicional
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $this->actingAs($cajero)->postJson('/tienda-en-linea/vista-previa', $this->datosDeTienda())->assertForbidden();
        $this->empresa->forceFill(['tienda_habilitada' => false])->save();
        $this->actingAs($this->admin->fresh())->postJson('/tienda-en-linea/vista-previa', $this->datosDeTienda())->assertForbidden();
        // y un borrador ya creado deja de verse si la plataforma quita el adicional
        $this->get($enlace)->assertNotFound();
        URL::setRequest(Request::create(config('app.url')));
    }

    public function test_varias_direcciones_y_horarios_van_uno_por_linea_con_su_mapa(): void
    {
        $this->sucursal->update(['direccion' => 'JR. SAN MARTIN N 1638 - HUANUCO']);
        $this->publicar([
            // lo que ya estaba escrito con punto y coma también se separa
            'direccion' => "Jr. San Martín N° 1638, Huánuco; Jr. Aguilar N° 347, Huánuco",
            'mapa_url' => 'https://maps.app.goo.gl/AbCdEf123456',
            'horario' => "Lunes a sábado, 8 a. m. a 6 p. m.\nDomingos, 8 a. m. a 1 p. m.",
        ]);

        $html = $this->tienda('/')->assertOk()
            ->assertSee('Jr. San Martín N° 1638, Huánuco')
            ->assertSee('Jr. Aguilar N° 347, Huánuco')
            // el local principal lleva al enlace que pegó el dueño; el otro se busca por su texto
            ->assertSee('href="https://maps.app.goo.gl/AbCdEf123456"', false)
            ->assertSee('maps/search/?api=1&amp;query=Jr.%20Aguilar', false)
            ->assertDontSee('query=Jr.%20San%20Mart', false)
            ->assertSee('Domingos, 8 a. m. a 1 p. m.')
            // la sucursal con esa misma dirección no se repite
            ->assertDontSee('JR. SAN MARTIN N 1638 - HUANUCO')
            ->getContent();

        $this->assertStringNotContainsString('Huánuco; Jr.', $html);
    }

    public function test_el_enlace_del_mapa_solo_puede_ser_de_google_maps(): void
    {
        $this->actingAs($this->admin);

        foreach (['https://maps.app.goo.gl/AbCdEf123456', 'https://www.google.com/maps/place/Agro/@-9.93,-76.24,17z', 'https://www.google.com.pe/maps?q=-9.93,-76.24', 'https://maps.google.com/?cid=123', 'https://goo.gl/maps/abc'] as $enlace) {
            $this->put('/tienda-en-linea', $this->datosDeTienda(['mapa_url' => $enlace]))->assertSessionHasNoErrors();
            $this->assertSame($enlace, $this->empresa->fresh()->tienda_config['mapa_url']);
        }

        foreach (['https://sitio-raro.com/maps', 'http://maps.app.goo.gl/abc', 'https://google.com.estafa.io/maps', 'https://www.google.com/search?q=x', 'javascript:alert(1)', 'mi local'] as $enlace) {
            $this->put('/tienda-en-linea', $this->datosDeTienda(['mapa_url' => $enlace]))->assertSessionHasErrors('mapa_url');
        }

        // se puede dejar vacío, y las líneas se guardan limpias
        $this->put('/tienda-en-linea', $this->datosDeTienda(['mapa_url' => '', 'direccion' => "  Av. Principal 123 \r\n\r\n Jr. Lima 45  ", 'horario' => "Lunes a sábado\n\n"]))->assertSessionHasNoErrors();
        $config = $this->empresa->fresh()->tienda_config;
        $this->assertNull($config['mapa_url']);
        $this->assertSame("Av. Principal 123\nJr. Lima 45", $config['direccion']);
        $this->assertSame('Lunes a sábado', $config['horario']);
    }

    public function test_la_direccion_sugerida_sale_del_nombre_del_negocio(): void
    {
        $empresa = new Empresa(['ruc' => '20123456786', 'razon_social' => 'AGRO EL SEMBRADOR S.A.C.']);
        $this->assertSame('agro-el-sembrador', Tienda::sugerirSlug($empresa));

        $empresa->nombre_comercial = 'Botica Ñandú & Cía.';
        $this->assertSame('botica-nandu-cia', Tienda::sugerirSlug($empresa));

        // un nombre reservado o demasiado corto cae en una direccion neutra
        $empresa->nombre_comercial = 'POS';
        $this->assertSame('tienda-456786', Tienda::sugerirSlug($empresa));

        // si ya esta tomada, se numera
        $this->empresa->update(['tienda_slug' => 'agro-el-sembrador']);
        $empresa->nombre_comercial = null;
        $this->assertSame('agro-el-sembrador-2', Tienda::sugerirSlug($empresa));

        $this->assertSame('51987654321', Tienda::numeroWhatsapp('987 654 321'));
        $this->assertSame('51987654321', Tienda::numeroWhatsapp('+51 987-654-321'));
        $this->assertNull(Tienda::numeroWhatsapp(''));
        $this->assertTrue(Tienda::esHost('agro.tienda.test'));
        $this->assertFalse(Tienda::esHost('localhost'));
        $this->assertTrue(Tienda::esHost('a.b.tienda.test')); // tampoco es el sistema
        $this->assertFalse(Tienda::esHost('tienda.test'));
        $this->assertFalse(Tienda::esHost('tienda.test.evil.com'));
    }

    public function test_el_dominio_de_la_app_nunca_se_toma_por_una_tienda(): void
    {
        // en produccion la app vive bajo el mismo dominio que las tiendas (pos.inkanet.pro)
        config(['tienda.host_app' => 'pos.tienda.test']);

        $esTienda = fn (string $host) => (bool) preg_match('/^(?P<tienda>'.Tienda::patronDeRuta().')\.tienda\.test$/', $host);

        $this->assertFalse($esTienda('pos.tienda.test'), 'La portada del sistema caería en la tienda');
        $this->assertTrue($esTienda('agro.tienda.test'));
        $this->assertTrue($esTienda('post.tienda.test')); // empieza igual pero es otro nombre
        $this->assertFalse($esTienda('a.b.tienda.test'));

        $this->assertFalse(Tienda::esHost('pos.tienda.test'));
        $this->assertTrue(Tienda::esHost('agro.tienda.test'));
    }
}
