<?php

namespace Tests\Feature;

use App\IA\DiagnosticoDeCultivo;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\Categoria;
use App\Models\ConversacionIa;
use App\Models\MensajeIa;
use App\Models\ModeloIa;
use App\Models\ProveedorIa;
use App\Services\IA\AsistenteIaService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Diagnóstico de cultivos por foto en la tienda en línea (/diagnostico), activado por la plataforma. */
class DiagnosticoTiendaTest extends TestCase
{
    use CreaEscenarioPos;

    private AgenteIa $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->forceFill(['tienda_habilitada' => true])->save();
        $this->empresa->update([
            'nombre_comercial' => 'Agro Campo', 'tienda_slug' => 'agro', 'tienda_publicada' => true,
            'tienda_config' => ['mostrar_precios' => true, 'mostrar_stock' => true, 'whatsapp' => '987 654 321'],
        ]);

        ProveedorIa::query()->update(['api_key' => null, 'activo' => false, 'fallos_seguidos' => 0, 'pausado_hasta' => null, 'url' => null]);
        ProveedorIa::findOrFail('gemini')->forceFill(['api_key' => 'clave-gemini', 'activo' => true])->save();
        ProveedorIa::findOrFail('openai')->forceFill(['api_key' => 'sk-prueba', 'activo' => true])->save();

        $fungicidas = Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Fungicidas']);
        $mancozeb = $this->crearProducto(precio: 38, atributos: ['nombre' => 'Mancozeb 80 PM x kg', 'codigo_interno' => 'P0100', 'categoria_id' => $fungicidas->id, 'descripcion' => 'Fungicida de contacto para la rancha de la papa.']);
        $cimoxanil = $this->crearProducto(precio: 55, atributos: ['nombre' => 'Curzate M8 x kg', 'codigo_interno' => 'P0101', 'categoria_id' => $fungicidas->id, 'descripcion' => 'Cimoxanil + mancozeb, sistémico.']);
        $this->darStock($mancozeb, 5, 20);
        $this->darStock($cimoxanil, 5, 30);
        $this->crearProducto(precio: 145, atributos: ['nombre' => 'Urea 46% x 50 kg', 'codigo_interno' => 'P0006']);

        $this->agente = new AgenteIa([
            'codigo' => 'diag_'.random_int(1000, 999999), 'nombre' => 'Diagnóstico de cultivos por foto', 'rubro_codigo' => $this->empresa->rubro_codigo,
            'instrucciones' => 'Eres agrónomo.', 'cadena' => [['proveedor' => 'gemini', 'modelo' => 'gemini-2.5-flash'], ['proveedor' => 'openai', 'modelo' => 'gpt-4.1-mini']],
            'limite_mensual' => 300, 'max_pasos' => 2, 'activo' => true,
        ]);
        $this->agente->forceFill(['tipo' => AgenteIa::TIPO_DIAGNOSTICO])->save();
    }

    private function activar(): void
    {
        AgenteEmpresa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'activo' => true, 'activado_en' => now()]);
    }

    private function tienda(string $ruta = '/'): TestResponse
    {
        $respuesta = $this->get("http://agro.tienda.test{$ruta}");
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    private function analizar(array $datos = []): TestResponse
    {
        $respuesta = $this->post('http://agro.tienda.test/diagnostico', [
            'foto' => UploadedFile::fake()->image('hoja.jpg', 1600, 1200),
            'visitante' => 'visitante-prueba-01',
            ...$datos,
        ]);
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    private static function respuesta(array $cambios = []): string
    {
        return json_encode([
            'es_cultivo' => true, 'cultivo' => 'papa', 'problema' => 'Rancha (tizón tardío)', 'tipo' => 'enfermedad',
            'severidad' => 'moderada', 'confianza' => 'alta', 'resumen' => 'Las hojas tienen manchas marrones con borde claro.',
            'sintomas' => ['Manchas marrones en el borde de la hoja'], 'recomendaciones' => ['Aplica un fungicida preventivo cada 7 días'],
            'busqueda' => ['rancha', 'mancozeb'],
            ...$cambios,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_solo_existe_si_la_plataforma_lo_activo_para_la_empresa(): void
    {
        $this->tienda('/diagnostico')->assertRedirect('http://agro.tienda.test');
        $this->tienda('/')->assertOk()->assertDontSee('data-diagnostico-promo', false);
        $this->tienda('/sitemap.xml')->assertDontSee('/diagnostico');

        $this->activar();
        $this->tienda('/diagnostico')->assertOk()
            ->assertSee('Diagnóstico de cultivos por foto')
            ->assertSee('data-diagnostico-form', false)
            ->assertSee('<link rel="canonical" href="http://agro.tienda.test/diagnostico">', false);
        $this->tienda('/')->assertOk()->assertDontSee('data-menu-diagnostico', false)->assertSee('data-diagnostico-promo', false)->assertSee('Diagnóstico de cultivos con IA');
        $this->tienda('/sitemap.xml')->assertSee('http://agro.tienda.test/diagnostico');

        // en las otras plantillas también
        foreach (['mercado', 'plaza'] as $plantilla) {
            $this->empresa->update(['tienda_config' => [...$this->empresa->tienda_config, 'plantilla' => $plantilla]]);
            $this->tienda('/')->assertOk()->assertDontSee('data-menu-diagnostico', false)->assertSee('data-diagnostico-promo', false)->assertSee('Diagnóstico de cultivos con IA');
            $this->tienda('/diagnostico')->assertOk()->assertSee('data-diagnostico-form', false);
        }
    }

    public function test_analiza_la_foto_y_recomienda_productos_del_catalogo(): void
    {
        $this->activar();
        ModeloIa::updateOrCreate(['proveedor_codigo' => 'gemini', 'modelo' => 'gemini-2.5-flash'], ['precio_entrada' => 0.30, 'precio_salida' => 2.50]);
        DiagnosticoDeCultivo::fake(['```json'."\n".self::respuesta()."\n```"]);

        $this->analizar(['nota' => 'Papa de dos meses'])->assertOk()
            ->assertSee('Rancha (tizón tardío)')
            ->assertSee('Papa')
            ->assertSee('Daño moderado')
            ->assertSee('Aplica un fungicida preventivo cada 7 días')
            ->assertSee('Mancozeb 80 PM x kg')
            ->assertDontSee('Urea 46% x 50 kg')
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('https://wa.me/51987654321?text=', false);

        // la foto y el comentario llegan al modelo
        DiagnosticoDeCultivo::assertPrompted(fn (AgentPrompt $p) => $p->attachments->count() === 1 && str_contains($p->prompt, 'Papa de dos meses'));

        // queda registrado como consumo del agente (cuenta para el tope y el costo del mes)
        $conversacion = ConversacionIa::where('empresa_id', $this->empresa->id)->where('canal', 'diagnostico')->sole();
        $this->assertSame('papa: Rancha (tizón tardío)', $conversacion->titulo);
        $respuesta = MensajeIa::where('conversacion_id', $conversacion->id)->where('rol', 'assistant')->sole();
        $this->assertSame('gemini', $respuesta->proveedor_codigo);
        $this->assertContains('P0100', $respuesta->productos);
        $this->assertSame(1, app(AsistenteIaService::class)->consultasDelMes($this->empresa, $this->agente));
    }

    public function test_si_un_proveedor_falla_o_responde_mal_usa_el_siguiente_de_la_cadena(): void
    {
        $this->activar();
        $llamadas = 0;
        DiagnosticoDeCultivo::fake(function () use (&$llamadas) {
            $llamadas++;
            if ($llamadas === 1) {
                throw new RuntimeException('503 Service Unavailable');
            }

            return self::respuesta();
        });

        $this->analizar()->assertOk()->assertSee('Rancha (tizón tardío)');
        $mensaje = MensajeIa::where('empresa_id', $this->empresa->id)->where('rol', 'assistant')->sole();
        $this->assertSame('openai', $mensaje->proveedor_codigo);
        $this->assertSame('gemini', $mensaje->intentos[0]['proveedor']);
        $this->assertSame(1, ProveedorIa::find('gemini')->fallos_seguidos);

        // una respuesta que no es el JSON pedido también pasa al siguiente
        DiagnosticoDeCultivo::fake(['Lo siento, no puedo ver la imagen.', self::respuesta(['problema' => 'Roya amarilla'])]);
        $this->analizar()->assertOk()->assertSee('Roya amarilla');

        // si ninguno responde, se avisa sin romper la página
        DiagnosticoDeCultivo::fake(fn () => throw new RuntimeException('timeout'));
        $this->analizar()->assertStatus(503)->assertSee('No pudimos analizar la foto en este momento');
    }

    public function test_si_las_palabras_no_encuentran_productos_recomienda_de_la_categoria_del_problema(): void
    {
        $this->activar();
        DiagnosticoDeCultivo::fake([self::respuesta(['busqueda' => ['phytophthora infestans']])]);

        $this->analizar()->assertOk()->assertSee('Mancozeb 80 PM x kg')->assertSee('Curzate M8 x kg');
    }

    public function test_una_foto_que_no_es_un_cultivo_o_una_planta_sana_no_recomienda_productos(): void
    {
        $this->activar();
        DiagnosticoDeCultivo::fake([json_encode(['es_cultivo' => false, 'resumen' => 'La foto muestra una persona.'])]);
        $this->analizar()->assertOk()->assertSee('No vemos un cultivo en esta foto')->assertDontSee('Productos que te pueden ayudar');

        DiagnosticoDeCultivo::fake([self::respuesta(['tipo' => 'sano', 'problema' => 'Planta sana', 'severidad' => 'ninguna', 'busqueda' => ['mancozeb']])]);
        $this->analizar()->assertOk()->assertSee('Planta sana')->assertDontSee('Productos que te pueden ayudar');
    }

    public function test_valida_la_foto_y_respeta_el_tope_mensual(): void
    {
        $this->activar();
        DiagnosticoDeCultivo::fake([self::respuesta()]);

        $this->analizar(['foto' => null])->assertStatus(422)->assertSee('Elige o toma una foto de tu cultivo.');
        $this->analizar(['foto' => UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf')])->assertStatus(422)->assertSee('La foto debe ser JPG, PNG o WEBP.');
        DiagnosticoDeCultivo::assertPromptedTimes(0);

        AgenteEmpresa::where('empresa_id', $this->empresa->id)->update(['limite_mensual' => 1]);
        $this->analizar()->assertOk();
        $this->analizar()->assertStatus(503)->assertSee('alcanzó su límite de este mes');
    }

    public function test_otra_web_no_puede_gastar_el_cupo_y_cada_persona_tiene_un_tope_diario(): void
    {
        $this->activar();
        DiagnosticoDeCultivo::fake([self::respuesta()]);

        // un formulario puesto en otra página no puede mandar fotos con el cupo de esta tienda
        $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->analizar()->assertForbidden();
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://otra-web.com'])->analizar()->assertForbidden();
        DiagnosticoDeCultivo::assertPromptedTimes(0);
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'http://agro.tienda.test'])->analizar()->assertOk();

        // tope del día por persona (IP real de Cloudflare)
        $clave = "diagnostico:{$this->empresa->id}:203.0.113.9";
        \Illuminate\Support\Facades\RateLimiter::clear($clave);
        for ($i = 0; $i < 15; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit($clave, 86400);
        }
        $this->withHeaders(['CF-Connecting-IP' => '203.0.113.9'])->analizar()->assertStatus(429)->assertSee('Ya hiciste varios diagnósticos hoy');
        \Illuminate\Support\Facades\RateLimiter::clear($clave);
    }

    public function test_una_foto_gigante_se_rechaza_sin_abrirla(): void
    {
        $this->activar();
        DiagnosticoDeCultivo::fake([self::respuesta()]);
        // PNG que dice medir 10000 x 6000 (60 MP): GD lo descomprimiría entero en memoria. Basta su cabecera
        $ihdr = 'IHDR'.pack('NNCCCCC', 10000, 6000, 8, 2, 0, 0, 0);
        $ruta = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($ruta, chr(137).'PNG'.chr(13).chr(10).chr(26).chr(10).pack('N', 13).$ihdr.pack('N', crc32($ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND')));

        $this->analizar(['foto' => new UploadedFile($ruta, 'grande.png', 'image/png', null, true)])->assertStatus(422)->assertSee('La foto es demasiado grande');
        DiagnosticoDeCultivo::assertPromptedTimes(0);
    }

    public function test_si_ningun_modelo_responde_en_formato_igual_se_registra_lo_gastado(): void
    {
        $this->activar();
        ModeloIa::updateOrCreate(['proveedor_codigo' => 'gemini', 'modelo' => 'gemini-2.5-flash'], ['precio_entrada' => 0.30, 'precio_salida' => 2.50]);
        DiagnosticoDeCultivo::fake(['Hola, veo una planta.', 'No sé.']);

        $this->analizar()->assertStatus(503)->assertSee('No pudimos analizar la foto');
        // se pagó: cuenta para el tope del mes y queda con su costo
        $this->assertSame(1, app(AsistenteIaService::class)->consultasDelMes($this->empresa, $this->agente));
        $mensaje = MensajeIa::where('empresa_id', $this->empresa->id)->where('rol', 'assistant')->sole();
        $this->assertSame('', $mensaje->contenido);
        $this->assertCount(2, $mensaje->intentos);
    }

    public function test_el_agente_de_fotos_no_aparece_como_asistente_de_chat(): void
    {
        $this->activar();
        $servicio = app(AsistenteIaService::class);
        $this->assertFalse($servicio->tieneAgentes($this->empresa));
        $this->assertTrue($servicio->agentesDe($this->empresa)->isEmpty());

        // el botón "Probar" del superadmin es para agentes de chat
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $this->actingAs($this->admin->fresh())->postJson("/admin/ia/agentes/{$this->agente->id}/probar", ['empresa_id' => $this->empresa->id, 'texto' => 'hola'])
            ->assertStatus(422)->assertJsonPath('mensaje', 'Este agente analiza fotos: pruébalo en /diagnostico de una tienda que lo tenga activo.');
    }

    public function test_lee_el_json_aunque_venga_envuelto_y_descarta_el_que_no_sirve(): void
    {
        $this->assertSame('Rancha (tizón tardío)', DiagnosticoDeCultivo::leer("Aquí tienes:\n".self::respuesta())['problema']);
        $this->assertSame('indeterminado', DiagnosticoDeCultivo::leer(self::respuesta(['tipo' => 'virus raro']))['tipo']);
        $this->assertNull(DiagnosticoDeCultivo::leer(''));
        $this->assertNull(DiagnosticoDeCultivo::leer('{"es_cultivo": true, "problema": ""}'));
        $this->assertNull(DiagnosticoDeCultivo::leer('{"es_cultivo": true, "problema": "Rancha"'));
    }
}
