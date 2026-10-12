<?php

namespace Tests\Feature;

use App\IA\AgenteDeRubro;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\ConversacionIa;
use App\Models\ProveedorIa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** El negocio lee los chats de su tienda, toma la conversación y le responde al cliente. */
class ChatsTiendaTest extends TestCase
{
    use CreaEscenarioPos;
    use DatabaseTransactions;

    private AgenteIa $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->forceFill(['tienda_habilitada' => true])->save();
        ProveedorIa::query()->update(['api_key' => null, 'activo' => false, 'fallos_seguidos' => 0, 'pausado_hasta' => null, 'url' => null]);
        ProveedorIa::findOrFail('deepseek')->forceFill(['api_key' => 'sk-prueba', 'activo' => true])->save();
        $this->crearProducto(precio: 145, atributos: ['nombre' => 'Urea 46% x 50 kg', 'codigo_interno' => 'P0006']);

        $this->agente = AgenteIa::create([
            'codigo' => 'agente_'.random_int(1000, 999999), 'nombre' => 'Agente agrónomo', 'rubro_codigo' => $this->empresa->rubro_codigo,
            'instrucciones' => 'Eres agrónomo.', 'cadena' => [['proveedor' => 'deepseek', 'modelo' => 'deepseek-v4-pro']], 'limite_mensual' => 1000, 'max_pasos' => 4, 'activo' => true,
        ]);
        AgenteEmpresa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'activo' => true, 'activado_en' => now()]);
        $this->empresa->update([
            'nombre_comercial' => 'Agro Campo', 'tienda_slug' => 'agro', 'tienda_publicada' => true,
            'tienda_config' => ['mostrar_precios' => true, 'mostrar_stock' => true, 'whatsapp' => '987 654 321', 'chatbot_activo' => true, 'chatbot_nombre' => 'Dania'],
        ]);
    }

    private function cliente(string $metodo, string $ruta, array $datos = []): TestResponse
    {
        $respuesta = $metodo === 'GET' ? $this->getJson('http://agro.tienda.test'.$ruta) : $this->postJson('http://agro.tienda.test'.$ruta, $datos);
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    public function test_con_el_chatbot_activo_el_whatsapp_flotante_deja_paso_al_asistente(): void
    {
        $html = $this->cliente('GET', '/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-whatsapp-flotante', $html);
        $this->assertStringContainsString('data-bot-abrir', $html);

        $this->empresa->update(['tienda_config' => ['whatsapp' => '987 654 321', 'chatbot_activo' => false]]);
        $this->assertStringContainsString('data-whatsapp-flotante', $this->cliente('GET', '/')->getContent());
    }

    public function test_el_negocio_toma_la_conversacion_y_el_cliente_recibe_su_respuesta(): void
    {
        AgenteDeRubro::fake(['Claro, tenemos **Urea 46%**.']);
        $r = $this->cliente('POST', '/asistente', ['visitante' => 'visitante-prueba-01', 'texto' => '¿Tienen urea?'])->assertOk()->assertJsonPath('modo', 'bot');
        $id = $r->json('conversacion_id');

        // el dueño ve el chat con la marca de "sin leer" y lo abre
        $this->actingAs($this->admin)->get('/asistente/tienda')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Asistente/Chats')
            ->where('conversaciones.0.id', $id)
            ->where('conversaciones.0.sin_leer', true)
            ->where('conversaciones.0.modo', 'bot')
            ->where('actual', null));
        $this->actingAs($this->admin)->get("/asistente/tienda?c={$id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('actual.id', $id)->has('actual.mensajes', 2)->where('conversaciones.0.sin_leer', false));

        // toma la conversación: el bot calla, lo que escribe el cliente queda esperando
        $this->actingAs($this->admin)->postJson("/asistente/tienda/{$id}/tomar")->assertOk()->assertJsonPath('modo', 'humano');
        AgenteDeRubro::fake([fn () => throw new \RuntimeException('el bot no debe responder')]);
        $this->cliente('POST', '/asistente', ['visitante' => 'visitante-prueba-01', 'texto' => '¿Me hacen delivery?', 'conversacion_id' => $id])
            ->assertOk()->assertJsonPath('modo', 'humano')->assertJsonPath('mensaje', null);

        // el dueño responde y el cliente lo recibe al consultar lo nuevo
        $this->actingAs($this->admin)->postJson("/asistente/tienda/{$id}/responder", ['texto' => 'Sí, hacemos delivery en Huaral.'])
            ->assertOk()->assertJsonPath('mensaje.rol', 'humano')->assertJsonPath('atendida_por', $this->admin->nombre_completo);
        $nuevos = $this->cliente('GET', "/asistente/mensajes?conversacion_id={$id}&visitante=visitante-prueba-01")->assertOk()->assertJsonPath('modo', 'humano');
        $this->assertSame(['assistant', 'humano'], array_column($nuevos->json('mensajes'), 'rol'));
        $this->assertSame('Sí, hacemos delivery en Huaral.', $nuevos->json('mensajes.1.contenido'));

        // solo lo posterior a "desde"
        $desde = $nuevos->json('mensajes.0.id');
        $this->assertCount(1, $this->cliente('GET', "/asistente/mensajes?conversacion_id={$id}&visitante=visitante-prueba-01&desde=".urlencode($desde))->json('mensajes'));

        // la pantalla del dueño también ve lo nuevo del cliente
        $this->actingAs($this->admin)->getJson("/asistente/tienda/{$id}/mensajes")->assertOk()->assertJsonPath('mensajes.2.contenido', '¿Me hacen delivery?');

        // devuelve al asistente y el bot vuelve a responder
        $this->actingAs($this->admin)->postJson("/asistente/tienda/{$id}/devolver")->assertOk()->assertJsonPath('modo', 'bot');
        AgenteDeRubro::fake(['De vuelta: sí, tenemos urea.']);
        $this->cliente('POST', '/asistente', ['visitante' => 'visitante-prueba-01', 'texto' => 'gracias', 'conversacion_id' => $id])
            ->assertOk()->assertJsonPath('modo', 'bot')->assertJsonPath('mensaje.contenido', 'De vuelta: sí, tenemos urea.');
        $this->assertSame(['user', 'assistant', 'user', 'humano', 'user', 'assistant'], ConversacionIa::findOrFail($id)->mensajes->pluck('rol')->all());
    }

    public function test_otra_empresa_o_visitante_no_ven_la_conversacion(): void
    {
        $conversacion = ConversacionIa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'canal' => 'tienda', 'visitante' => 'visitante-prueba-01']);

        $this->cliente('GET', "/asistente/mensajes?conversacion_id={$conversacion->id}&visitante=otro-visitante-99")->assertNotFound();

        $this->admin->forceFill(['es_superadmin' => false])->save();
        $ajeno = Usuario::where('empresa_id', '!=', $this->empresa->id)->where('activo', true)->first();
        if ($ajeno) {
            $this->actingAs($ajeno)->postJson("/asistente/tienda/{$conversacion->id}/tomar")->assertNotFound();
        }

        // una conversación de la app (no de la tienda) tampoco se atiende por aquí
        $interna = ConversacionIa::create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->admin->id, 'agente_id' => $this->agente->id, 'canal' => 'app']);
        $this->actingAs($this->admin)->postJson("/asistente/tienda/{$interna->id}/tomar")->assertNotFound();
    }
}
