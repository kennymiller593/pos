<?php

namespace Tests\Feature;

use App\Jobs\VerificarDominioTienda;
use App\Models\Empresa;
use App\Services\DominioTiendaService;
use App\Support\Tienda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Dominio propio de la tienda (www.agrocampo-prueba.net). El DNS se simula con un resolutor falso y
 * Cloudflare con Http::fake (phpunit.xml pone TIENDA_DOMINIOS=cloudflare y un token de prueba).
 */
class TiendaDominioTest extends TestCase
{
    use CreaEscenarioPos;

    private const API = 'https://api.cloudflare.com/client/v4/zones/zona-de-prueba/custom_hostnames';

    /** host => destino del CNAME, lo que "responde" el DNS en la prueba */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->crearProducto(precio: 145, atributos: ['nombre' => 'Urea 46% x 50 kg', 'codigo_interno' => 'P0006']);

        $this->app->instance(DominioTiendaService::class, new DominioTiendaService(fn (string $host) => $this->dns[$host] ?? null));
        Http::preventStrayRequests();

        $this->empresa->update(['nombre_comercial' => 'Agro Campo', 'tienda_slug' => 'agro', 'tienda_publicada' => true, 'tienda_config' => ['whatsapp' => '987 654 321']]);
        $this->empresa->forceFill(['tienda_habilitada' => true, 'tienda_dominio_habilitado' => true])->save();
    }

    private function visitar(string $url): TestResponse
    {
        $respuesta = $this->get($url);
        // una ruta relativa posterior se armaría con el ultimo host: se vuelve al de la app
        URL::setRequest(Request::create(config('app.url')));

        return $respuesta;
    }

    private function dueno()
    {
        return $this->actingAs($this->admin->fresh());
    }

    /**
     * Cloudflare: registra el hostname y luego responde cada consulta de estado, en orden:
     * 'pendiente' (aún sin certificado), 'falla' (pendiente con el motivo) o 'activo'.
     */
    private function cloudflare(array $consultas = ['activo']): void
    {
        $secuencia = Http::sequence();
        foreach ($consultas as $consulta) {
            $activo = $consulta === 'activo';
            $secuencia->push(['success' => true, 'result' => [
                'id' => 'cf-123',
                'status' => $activo ? 'active' : 'pending',
                'ssl' => ['status' => $activo ? 'active' : 'pending_validation', 'validation_errors' => $consulta === 'falla' ? [['message' => 'CNAME record does not point to the fallback origin.']] : []],
            ]]);
        }

        Http::fake([
            self::API => Http::response(['success' => true, 'result' => ['id' => 'cf-123', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']]], 201),
            self::API.'/cf-123' => $secuencia,
        ]);
    }

    public function test_el_dominio_se_normaliza_y_se_rechaza_lo_que_no_corresponde(): void
    {
        $servicio = app(DominioTiendaService::class);

        $this->assertSame('www.agrocampo.com', $servicio->normalizar(' https://AgroCampo.com/catalogo '));
        $this->assertSame('www.agrocampo.com.pe', $servicio->normalizar('agrocampo.com.pe'));
        $this->assertSame('www.agrocampo.com', $servicio->normalizar('www.agrocampo.com'));
        $this->assertSame('tienda.agrocampo.com', $servicio->normalizar('tienda.agrocampo.com')); // un subdominio se respeta
        $this->assertSame('agrocampo.com', DominioTiendaService::raiz('www.agrocampo.com'));
        foreach (['agrocampo', 'agro campo.com', 'agro_campo.com', '192.168.1.1', 'www.agrocampo-prueba.net:8080', ''] as $malo) {
            $this->assertNull($servicio->normalizar($malo), $malo);
        }

        // los nuestros no
        $this->assertNotNull($servicio->rechazo('otra.tienda.test', $this->empresa));
        $this->assertNotNull($servicio->rechazo('tienda.test', $this->empresa));
        $this->assertNotNull($servicio->rechazo('tiendas.tienda.test', $this->empresa));
        // ni uno que ya usa otra empresa
        Empresa::create(['ruc' => '20'.random_int(10000000, 99999999).'1', 'razon_social' => 'Otra', 'regimen_tributario' => 'MYPE', 'rubro_codigo' => $this->empresa->rubro_codigo, 'activo' => true])
            ->forceFill(['tienda_dominio' => 'www.ajeno.com'])->save();
        $this->assertNotNull($servicio->rechazo('www.ajeno.com', $this->empresa));
        $this->assertNull($servicio->rechazo('www.agrocampo-prueba.net', $this->empresa));
    }

    public function test_sin_el_adicional_no_hay_dominio_y_solo_la_plataforma_lo_activa(): void
    {
        $this->empresa->forceFill(['tienda_dominio_habilitado' => false])->save();

        $this->dueno()->get('/tienda-en-linea')->assertInertia(fn (Assert $p) => $p->where('tienda.dominio_propio.habilitado', false)->where('tienda.dominio_propio.disponible', true));
        $this->dueno()->post('/tienda-en-linea/dominio', ['dominio' => 'www.agrocampo-prueba.net'])->assertForbidden();
        $this->assertNull($this->empresa->fresh()->tienda_dominio);

        $superadmin = $this->crearUsuario('admin', 'plataforma'.random_int(10000, 99999).'@test.local');
        $superadmin->forceFill(['es_superadmin' => true])->save();

        $this->dueno()->post("/admin/empresas/{$this->empresa->id}/dominio")->assertForbidden();
        $this->actingAs($superadmin)->post("/admin/empresas/{$this->empresa->id}/dominio")->assertSessionHas('success');
        $this->assertTrue($this->empresa->fresh()->tienda_dominio_habilitado);
        $this->assertDatabaseHas('auditoria', ['accion' => 'plataforma.dominio_activado']);
        $this->dueno()->get('/tienda-en-linea')->assertInertia(fn (Assert $p) => $p->where('tienda.dominio_propio.habilitado', true));
    }

    public function test_el_dueno_registra_su_dominio_y_la_tienda_atiende_en_el(): void
    {
        Queue::fake();
        // una consulta de estado (activo) y la respuesta al borrarlo
        $this->cloudflare(['activo', 'activo']);

        // lo que escribe se normaliza; lo inválido o nuestro se rechaza
        $this->dueno()->post('/tienda-en-linea/dominio', ['dominio' => 'agro campo'])->assertSessionHasErrors('dominio');
        $this->dueno()->post('/tienda-en-linea/dominio', ['dominio' => 'otra.tienda.test'])->assertSessionHasErrors('dominio');

        // sin CNAME todavía: queda pendiente, con la explicación
        $this->dueno()->post('/tienda-en-linea/dominio', ['dominio' => 'https://AgroCampo-Prueba.net/'])->assertSessionHas('success');
        $empresa = $this->empresa->fresh();
        $this->assertSame(['www.agrocampo-prueba.net', 'pendiente'], [$empresa->tienda_dominio, $empresa->tienda_dominio_estado]);
        $this->assertStringContainsString('tiendas.tienda.test', $empresa->tienda_dominio_detalle);
        $this->assertDatabaseHas('auditoria', ['accion' => 'tienda.dominio']);
        Http::assertNothingSent();
        // ni la tienda atiende en él, ni el subdominio redirige
        $this->visitar('http://www.agrocampo-prueba.net/')->assertNotFound();
        $this->visitar('http://agro.tienda.test/')->assertOk();

        // el CNAME apunta a otro lado
        $this->dns['www.agrocampo-prueba.net'] = 'otro.hosting.com';
        $this->dueno()->post('/tienda-en-linea/dominio/verificar');
        $this->assertStringContainsString('otro.hosting.com', $this->empresa->fresh()->tienda_dominio_detalle);

        // el CNAME está bien: se registra en Cloudflare y se sigue el certificado en cola
        $this->dns['www.agrocampo-prueba.net'] = 'tiendas.tienda.test';
        $this->dueno()->post('/tienda-en-linea/dominio/verificar')->assertSessionHas('success');
        $empresa = $this->empresa->fresh();
        $this->assertSame(['verificando', 'cf-123'], [$empresa->tienda_dominio_estado, $empresa->tienda_dominio_externo_id]);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === self::API && $r['hostname'] === 'www.agrocampo-prueba.net' && in_array('Bearer token-de-prueba', $r->header('Authorization'), true));
        Queue::assertPushed(VerificarDominioTienda::class, fn ($job) => $job->empresaId === $this->empresa->id);
        $this->visitar('http://www.agrocampo-prueba.net/')->assertNotFound(); // aún sin certificado

        // el trabajo ve el certificado activo
        (new VerificarDominioTienda($this->empresa->id))->handle(app(DominioTiendaService::class));
        $empresa = $this->empresa->fresh();
        $this->assertSame('activo', $empresa->tienda_dominio_estado);
        $this->assertNotNull($empresa->tienda_dominio_activado_en);
        $this->assertTrue(Tienda::dominioActivo($empresa));
        $this->assertSame('http://www.agrocampo-prueba.net/', Tienda::urlDe($empresa));

        // la tienda atiende en su dominio, con sus enlaces apuntando a él
        $this->visitar('http://www.agrocampo-prueba.net/')->assertOk()
            ->assertSee('Agro Campo')
            ->assertSee('<link rel="canonical" href="http://www.agrocampo-prueba.net/">', false)
            ->assertSee('Urea 46% x 50 kg');
        $this->visitar('http://www.agrocampo-prueba.net/catalogo/urea-46-x-50-kg')->assertOk()
            ->assertSee('<link rel="canonical" href="http://www.agrocampo-prueba.net/catalogo/urea-46-x-50-kg">', false)
            ->assertSee(rawurlencode('http://www.agrocampo-prueba.net/catalogo/urea-46-x-50-kg'), false); // en el mensaje de WhatsApp
        $this->visitar('http://www.agrocampo-prueba.net/sitemap.xml')->assertSee('<loc>http://www.agrocampo-prueba.net/catalogo</loc>', false);
        // la dirección gratuita sigue, pero manda al dominio propio (con la ruta y lo demás)
        $this->visitar('http://agro.tienda.test/catalogo?q=urea')->assertStatus(301)->assertRedirect('http://www.agrocampo-prueba.net/catalogo?q=urea');
        // en el dominio del cliente no existe el sistema; y un dominio que nadie registró no es nada
        $this->visitar('http://www.agrocampo-prueba.net/login')->assertNotFound();
        $this->visitar('http://www.otro-negocio.com/')->assertNotFound();
        $this->visitar('http://agrocampo-prueba.net/')->assertNotFound();
        $this->dueno()->get('/tienda-en-linea')->assertInertia(fn (Assert $p) => $p
            ->where('tienda.url', 'http://www.agrocampo-prueba.net/')
            ->where('tienda.url_gratuita', 'http://agro.tienda.test/')
            ->where('tienda.dominio_propio.estado', 'activo'));

        // la vista previa del dueño se queda en la dirección gratuita, sin redirigir
        $respuesta = $this->dueno()->postJson('/tienda-en-linea/vista-previa', [
            'slug' => 'agro', 'publicada' => true, 'color' => 'azul', 'mostrar_precios' => true, 'mostrar_stock' => false, 'whatsapp' => '987 654 321', 'anuncio' => 'Borrador',
        ])->assertOk();
        $this->assertStringStartsWith('http://agro.tienda.test/?previa=', $respuesta->json('url'));
        $this->visitar($respuesta->json('url'))->assertOk()->assertSee('Borrador');

        // se quita: Cloudflare lo olvida y todo vuelve a la dirección gratuita
        $this->dueno()->delete('/tienda-en-linea/dominio')->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === self::API.'/cf-123');
        $empresa = $this->empresa->fresh();
        $this->assertNull($empresa->tienda_dominio);
        $this->assertNull($empresa->tienda_dominio_externo_id);
        $this->assertDatabaseHas('auditoria', ['accion' => 'tienda.dominio_quitado']);
        $this->visitar('http://agro.tienda.test/')->assertOk();
        $this->visitar('http://www.agrocampo-prueba.net/')->assertNotFound();
    }

    public function test_si_cloudflare_no_emite_el_certificado_el_dueno_ve_el_motivo_y_puede_reintentar(): void
    {
        Queue::fake();
        $this->dns['www.agrocampo-prueba.net'] = 'tiendas.tienda.test';
        // las consultas de estado, en orden: sigue pendiente, falla, (tras corregir) activo, y una pendiente más
        $this->cloudflare(['pendiente', 'falla', 'activo', 'pendiente']);

        $this->dueno()->post('/tienda-en-linea/dominio', ['dominio' => 'www.agrocampo-prueba.net'])->assertSessionHas('success');
        $this->assertSame('verificando', $this->empresa->fresh()->tienda_dominio_estado);

        // aún no: el trabajo se vuelve a encolar
        (new VerificarDominioTienda($this->empresa->id, 1))->handle(app(DominioTiendaService::class));
        $this->assertSame('verificando', $this->empresa->fresh()->tienda_dominio_estado);
        Queue::assertPushed(VerificarDominioTienda::class, fn ($job) => $job->intento === 2);

        // falló: queda en error con el motivo
        (new VerificarDominioTienda($this->empresa->id, 2))->handle(app(DominioTiendaService::class));
        $empresa = $this->empresa->fresh();
        $this->assertSame('error', $empresa->tienda_dominio_estado);
        $this->assertStringContainsString('CNAME record does not point', $empresa->tienda_dominio_detalle);
        $this->visitar('http://www.agrocampo-prueba.net/')->assertNotFound();

        // el dueño corrige y verifica: se reutiliza el registro de Cloudflare y vuelve a "verificando"
        $this->dueno()->post('/tienda-en-linea/dominio/verificar')->assertSessionHas('success');
        $this->assertSame('verificando', $this->empresa->fresh()->tienda_dominio_estado);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST')); // no se registra dos veces
        (new VerificarDominioTienda($this->empresa->id))->handle(app(DominioTiendaService::class));
        $this->assertSame('activo', $this->empresa->fresh()->tienda_dominio_estado);

        // demasiados intentos: se avisa y se deja de insistir
        $this->empresa->forceFill(['tienda_dominio_estado' => 'verificando'])->save();
        (new VerificarDominioTienda($this->empresa->id, VerificarDominioTienda::INTENTOS))->handle(app(DominioTiendaService::class));
        $this->assertSame('error', $this->empresa->fresh()->tienda_dominio_estado);
        $this->assertStringContainsString('tardando', $this->empresa->fresh()->tienda_dominio_detalle);
    }

    public function test_si_la_plataforma_quita_el_adicional_el_dominio_deja_de_atender(): void
    {
        $this->empresa->forceFill(['tienda_dominio' => 'www.agrocampo-prueba.net', 'tienda_dominio_estado' => 'activo', 'tienda_dominio_externo_id' => 'cf-123', 'tienda_dominio_activado_en' => now()])->save();
        $this->visitar('http://www.agrocampo-prueba.net/')->assertOk();
        $this->visitar('http://agro.tienda.test/')->assertStatus(301);

        $superadmin = $this->crearUsuario('admin', 'plataforma'.random_int(10000, 99999).'@test.local');
        $superadmin->forceFill(['es_superadmin' => true])->save();
        $this->actingAs($superadmin)->post("/admin/empresas/{$this->empresa->id}/dominio")->assertSessionHas('success');

        $this->visitar('http://www.agrocampo-prueba.net/')->assertNotFound();
        $this->visitar('http://agro.tienda.test/')->assertOk(); // sin redirección
        $this->dueno()->post('/tienda-en-linea/dominio/verificar')->assertForbidden();
        // la configuración se conserva para cuando se reactive
        $this->assertSame('www.agrocampo-prueba.net', $this->empresa->fresh()->tienda_dominio);

        // una tienda sin publicar tampoco atiende en su dominio
        $this->actingAs($superadmin)->post("/admin/empresas/{$this->empresa->id}/dominio");
        $this->visitar('http://www.agrocampo-prueba.net/')->assertOk();
        $this->empresa->update(['tienda_publicada' => false]);
        $this->visitar('http://www.agrocampo-prueba.net/')->assertNotFound();
    }
}
