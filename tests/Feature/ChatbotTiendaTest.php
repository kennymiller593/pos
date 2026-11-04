<?php

namespace Tests\Feature;

use App\IA\AgenteDeRubro;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\Categoria;
use App\Models\ConversacionIa;
use App\Models\MensajeIa;
use App\Models\ProveedorIa;
use App\Services\IA\AsistenteIaService;
use App\Support\Tienda;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Messages\UserMessage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** El chatbot de la tienda en línea: el dueño lo configura y los clientes conversan con el agente de la empresa. */
class ChatbotTiendaTest extends TestCase
{
    use CreaEscenarioPos;
    use DatabaseTransactions;

    private AgenteIa $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        Storage::fake('public');
        $this->empresa->forceFill(['tienda_habilitada' => true])->save();
        ProveedorIa::query()->update(['api_key' => null, 'activo' => false, 'fallos_seguidos' => 0, 'pausado_hasta' => null, 'url' => null]);
        ProveedorIa::findOrFail('deepseek')->forceFill(['api_key' => 'sk-prueba', 'activo' => true])->save();

        $fertilizantes = Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Fertilizantes']);
        $urea = $this->crearProducto(precio: 145, atributos: ['nombre' => 'Urea 46% x 50 kg', 'codigo_interno' => 'P0006', 'categoria_id' => $fertilizantes->id, 'imagen_url' => '/storage/productos/urea.jpg']);
        $this->darStock($urea, 10, 100);
        // un producto oculto de la tienda no debe salir aunque el agente lo nombre
        $this->crearProducto(precio: 28, atributos: ['nombre' => 'Glifosato 480 SL x 1 L', 'codigo_interno' => 'P0001', 'en_tienda' => false]);

        $this->agente = AgenteIa::create([
            'codigo' => 'agente_'.random_int(1000, 999999), 'nombre' => 'Agente agrónomo', 'rubro_codigo' => $this->empresa->rubro_codigo,
            'instrucciones' => 'Eres agrónomo.', 'cadena' => [['proveedor' => 'deepseek', 'modelo' => 'deepseek-v4-pro']], 'limite_mensual' => 1000, 'max_pasos' => 4,
            'sugerencias' => ['¿Qué tienen para la roya?'], 'activo' => true,
        ]);
        AgenteEmpresa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'activo' => true, 'activado_en' => now()]);
    }

    private function publicar(array $config = []): void
    {
        $this->empresa->update([
            'nombre_comercial' => 'Agro Campo', 'tienda_slug' => 'agro', 'tienda_publicada' => true,
            'tienda_config' => ['mostrar_precios' => true, 'mostrar_stock' => true, 'whatsapp' => '987 654 321', 'chatbot_activo' => true, 'chatbot_nombre' => 'Dania', ...$config],
        ]);
    }

    private function tienda(string $ruta = '/'): TestResponse
    {
        $respuesta = $this->get("http://agro.tienda.test{$ruta}");
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    private function chat(array $datos): TestResponse
    {
        $respuesta = $this->postJson('http://agro.tienda.test/asistente', ['visitante' => 'visitante-prueba-01'] + $datos);
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    public function test_el_chatbot_aparece_en_la_tienda_solo_cuando_el_dueno_lo_enciende(): void
    {
        $this->publicar(['chatbot_activo' => false]);
        $this->tienda()->assertOk()->assertDontSee('data-asistente', false);

        $this->publicar(['chatbot_color' => '#7C3AED', 'chatbot_saludo' => 'Hola, soy Dania, ¿qué buscas?']);
        $html = $this->tienda()->assertOk()->getContent();
        $this->assertStringContainsString('data-asistente', $html);
        $this->assertStringContainsString('Pregúntale a Dania', $html);
        $this->assertStringContainsString('--bot: #7C3AED', $html);
        $this->assertStringContainsString('Hola, soy Dania, ¿qué buscas?', $html);
        $this->assertStringContainsString('¿Qué tienen para la roya?', $html);

        // si la plataforma quita el agente, el chat desaparece aunque siga encendido
        AgenteEmpresa::where('empresa_id', $this->empresa->id)->update(['activo' => false]);
        $this->tienda()->assertOk()->assertDontSee('data-asistente', false);
        $this->chat(['texto' => 'hola'])->assertNotFound();
    }

    public function test_el_cliente_conversa_y_recibe_tarjetas_de_los_productos_recomendados(): void
    {
        $this->publicar();
        AgenteDeRubro::fake(["Para el maíz te recomiendo la **Urea 46%**.\n\nPRODUCTOS: P0006, P0001, NOEXISTE"]);

        $r = $this->chat(['texto' => '¿Qué fertilizante tienen para maíz?'])->assertOk();
        $this->assertSame('Para el maíz te recomiendo la **Urea 46%**.', $r->json('mensaje.contenido'));
        $this->assertCount(1, $r->json('mensaje.productos')); // el oculto y el inexistente no salen
        $tarjeta = $r->json('mensaje.productos.0');
        $this->assertSame('Urea 46% x 50 kg', $tarjeta['nombre']);
        $this->assertSame('Fertilizantes', $tarjeta['categoria']);
        $this->assertEquals(145, $tarjeta['precio']);
        $this->assertSame('/catalogo/urea-46-x-50-kg', $tarjeta['url']);
        $this->assertStringContainsString('wa.me/51987654321', $tarjeta['pedido']);
        $this->assertTrue($tarjeta['disponible']);

        $conversacion = ConversacionIa::findOrFail($r->json('conversacion_id'));
        $this->assertSame(['tienda', 'visitante-prueba-01', null], [$conversacion->canal, $conversacion->visitante, $conversacion->usuario_id]);
        $this->assertSame(['P0006', 'P0001', 'NOEXISTE'], $conversacion->mensajes->last()->productos);

        // la segunda pregunta sigue en la misma conversación y cuenta para el tope de la empresa
        AgenteDeRubro::fake(['Claro, se aplica según la etiqueta.']);
        $this->chat(['texto' => '¿y cuánto se aplica?', 'conversacion_id' => $conversacion->id])->assertOk()->assertJsonPath('conversacion_id', $conversacion->id);
        $this->assertCount(4, $conversacion->mensajes()->get());
        $this->assertSame(2, app(AsistenteIaService::class)->consultasDelMes($this->empresa));

        // otro visitante no puede colgarse de esa conversación: empieza una nueva
        AgenteDeRubro::fake(['Hola']);
        $otra = $this->postJson('http://agro.tienda.test/asistente', ['visitante' => 'otro-visitante-99', 'texto' => 'hola', 'conversacion_id' => $conversacion->id])->assertOk();
        URL::setRequest(Request::create(config('app.url')));
        $this->assertNotSame($conversacion->id, $otra->json('conversacion_id'));

        $this->chat(['texto' => ''])->assertStatus(422);
        $this->postJson('http://agro.tienda.test/asistente', ['visitante' => 'x', 'texto' => 'hola'])->assertStatus(422);
        URL::setRequest(Request::create(config('app.url')));
    }

    public function test_cuando_no_sabe_deriva_al_whatsapp_del_negocio_con_la_pregunta_escrita(): void
    {
        $this->publicar();
        AgenteDeRubro::fake(["La dosis exacta te la confirma nuestro asesor por WhatsApp, te dejo el botón aquí abajo.
DERIVAR
PRODUCTOS: P0006"]);

        $r = $this->chat(['texto' => '¿Cuántas cucharas por 20 litros en papa?'])->assertOk();
        // la marca no se ve y la tarjeta sigue saliendo
        $this->assertSame('La dosis exacta te la confirma nuestro asesor por WhatsApp, te dejo el botón aquí abajo.', $r->json('mensaje.contenido'));
        $this->assertCount(1, $r->json('mensaje.productos'));
        $enlace = $r->json('mensaje.whatsapp');
        $this->assertStringStartsWith('https://wa.me/51987654321?text=', $enlace);
        $this->assertSame('Hola, consulté en la tienda en línea: ¿Cuántas cucharas por 20 litros en papa?', rawurldecode(substr($enlace, strpos($enlace, '=') + 1)));

        $conversacion = ConversacionIa::findOrFail($r->json('conversacion_id'));
        $this->assertTrue($conversacion->mensajes->last()->derivar);

        // al volver a cargar la conversación el botón sigue en ese mensaje, y una respuesta normal no lo trae
        AgenteDeRubro::fake(['Gracias a ti.']);
        $this->chat(['texto' => 'gracias', 'conversacion_id' => $conversacion->id])->assertOk()->assertJsonPath('mensaje.whatsapp', null);
        $mensajes = $this->getJson("http://agro.tienda.test/asistente/mensajes?visitante=visitante-prueba-01&conversacion_id={$conversacion->id}")->assertOk()->json('mensajes');
        URL::setRequest(Request::create(config('app.url')));
        $this->assertSame($enlace, $mensajes[0]['whatsapp']);
        $this->assertNull($mensajes[1]['whatsapp']);
    }

    public function test_sin_whatsapp_configurado_no_hay_boton_y_las_reglas_piden_el_contacto(): void
    {
        $this->publicar(['whatsapp' => null]);
        AgenteDeRubro::fake(["No tengo ese dato.
DERIVAR"]);

        $r = $this->chat(['texto' => '¿Hacen envíos a Tingo María?'])->assertOk();
        $this->assertSame('No tengo ese dato.', $r->json('mensaje.contenido'));
        $this->assertNull($r->json('mensaje.whatsapp'));

        // las reglas de la tienda ya no mandan a "revisar la etiqueta": derivan a una persona (una sola vez)
        $config = Tienda::config($this->empresa->fresh());
        $conWhatsapp = (new AgenteDeRubro($this->agente, $this->empresa, null, 'tienda', ['whatsapp' => true]))->instructions();
        $this->assertStringContainsString('CUANDO NO TENGAS EL DATO', $conWhatsapp);
        $this->assertStringContainsString('"DERIVAR"', $conWhatsapp);
        $this->assertStringContainsString('Deriva una sola vez por conversación', $conWhatsapp);
        $this->assertStringNotContainsString('indica que se revise la etiqueta', $conWhatsapp);
        $sinWhatsapp = (new AgenteDeRubro($this->agente, $this->empresa, null, 'tienda', ['whatsapp' => false]))->instructions();
        $this->assertStringContainsString('teléfono o el correo', $sinWhatsapp);
        $this->assertStringNotContainsString('"DERIVAR"', $sinWhatsapp);
        // en la app (el trabajador en el mostrador) sigue la ficha técnica
        $app = (new AgenteDeRubro($this->agente, $this->empresa, null, 'app'))->instructions();
        $this->assertStringContainsString('indica que se revise la etiqueta', $app);
        $this->assertNotNull($config);
    }

    public function test_el_modelo_recibe_la_conversacion_en_orden_y_solo_los_ultimos_mensajes(): void
    {
        $this->publicar();
        $conversacion = ConversacionIa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'canal' => 'tienda', 'visitante' => 'visitante-orden-01']);
        // todos con la misma hora, como pasa con la pregunta y su respuesta guardadas en una transacción
        for ($i = 1; $i <= 25; $i++) {
            MensajeIa::create(['conversacion_id' => $conversacion->id, 'empresa_id' => $this->empresa->id, 'rol' => $i % 2 ? 'user' : 'assistant', 'contenido' => "mensaje {$i}"]);
        }

        $historial = app(AsistenteIaService::class)->historialDe($conversacion->fresh());

        // los 20 últimos, del más antiguo al más reciente: el modelo lee la conversación en orden
        $this->assertCount(20, $historial);
        $this->assertSame('mensaje 6', $historial[0]->content);
        $this->assertSame('mensaje 25', $historial[19]->content);
        $this->assertInstanceOf(UserMessage::class, $historial[19]);
    }

    public function test_sin_precios_ni_whatsapp_las_tarjetas_no_los_muestran(): void
    {
        $this->publicar(['mostrar_precios' => false, 'whatsapp' => null, 'telefono' => '01 234 5678']);
        AgenteDeRubro::fake(["Tenemos **Urea 46% x 50 kg**.\nPRODUCTOS: P0006"]);

        $tarjeta = $this->chat(['texto' => 'urea'])->assertOk()->json('mensaje.productos.0');
        $this->assertNull($tarjeta['precio']);
        $this->assertNull($tarjeta['pedido']);
    }

    public function test_el_dueno_configura_el_chatbot_con_imagen_y_color(): void
    {
        $this->publicar();
        $datos = [
            'chatbot_activo' => true, 'chatbot_agente' => $this->agente->id, 'chatbot_nombre' => 'Dania', 'chatbot_saludo' => 'Hola, soy Dania.',
            'chatbot_color' => '#7c3aed', 'chatbot_avatar' => UploadedFile::fake()->image('dania.png', 400, 400),
        ];

        // la página del asistente: accesos, uso del mes y la configuración del chatbot
        $this->actingAs($this->admin)->get('/asistente')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Asistente/Configurar')
            ->where('agentes.0.id', $this->agente->id)->where('chatbot.activo', true)->where('chatbot.nombre', 'Dania')->where('tienda.habilitada', true));

        $this->actingAs($this->admin)->put('/asistente', $datos)->assertSessionHasNoErrors();
        $config = Tienda::config($this->empresa->fresh());
        $this->assertSame([true, $this->agente->id, 'Dania', 'Hola, soy Dania.', '#7C3AED'], [$config['chatbot_activo'], $config['chatbot_agente'], $config['chatbot_nombre'], $config['chatbot_saludo'], $config['chatbot_color']]);
        $this->assertNotNull($config['chatbot_avatar']);
        $this->assertStringContainsString('--bot: #7C3AED', $this->tienda()->getContent());

        // color inválido y agente ajeno se rechazan; quitar la imagen la borra
        $this->actingAs($this->admin)->put('/asistente', ['chatbot_color' => 'morado'] + $datos)->assertSessionHasErrors('chatbot_color');
        $this->actingAs($this->admin)->put('/asistente', ['chatbot_agente' => (string) Str::uuid()] + $datos)->assertSessionHasErrors('chatbot_agente');
        $this->actingAs($this->admin)->put('/asistente', ['chatbot_avatar' => null, 'chatbot_avatar_quitar' => true] + $datos)->assertSessionHasNoErrors();

        // guardar la tienda en línea no toca lo del chatbot
        $this->actingAs($this->admin)->put('/tienda-en-linea', ['slug' => 'agro', 'publicada' => true, 'color' => 'azul', 'mostrar_precios' => true, 'mostrar_stock' => true, 'whatsapp' => '987 654 321', 'chatbot_nombre' => 'Otra'])->assertSessionHasNoErrors();
        $this->assertSame('Dania', Tienda::config($this->empresa->fresh())['chatbot_nombre']);

        // el vendedor no configura el chatbot, pero sí consulta al asistente
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(1000, 999999).'@test.local');
        $this->actingAs($vendedor)->get('/asistente')->assertForbidden();
        $this->actingAs($vendedor)->get('/asistente/consultas')->assertOk();
        $this->assertNull(Tienda::config($this->empresa->fresh())['chatbot_avatar']);
    }

    public function test_en_la_app_las_respuestas_tambien_traen_tarjetas(): void
    {
        AgenteDeRubro::fake(["Te sirve la **Urea 46% x 50 kg**, hay 10 sacos.\n**PRODUCTOS:** P0006"]);

        $r = $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $this->agente->id, 'texto' => 'fertilizante para maíz'])->assertOk();
        $this->assertSame('Te sirve la **Urea 46% x 50 kg**, hay 10 sacos.', $r->json('mensaje.contenido'));
        $this->assertSame('P0006', $r->json('mensaje.productos.0.codigo'));
        $this->assertSame('/productos?buscar=P0006', $r->json('mensaje.productos.0.url'));
        $this->assertTrue($r->json('mensaje.productos.0.disponible'));
    }
}
