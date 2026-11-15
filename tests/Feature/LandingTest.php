<?php

namespace Tests\Feature;

use App\Services\SuscripcionService;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_un_visitante_ve_la_landing_en_html_con_los_planes(): void
    {
        $respuesta = $this->get('/')->assertOk()
            ->assertViewIs('landing')
            ->assertViewHas('diasPrueba', SuscripcionService::DIAS_PRUEBA)
            ->assertViewHas('planes', fn ($planes) => $planes->count() === 3
                && $planes->first()->codigo === 'emprendedor'
                // el plan de prueba no se ofrece y no se filtran ids internos
                && ! $planes->contains('codigo', 'prueba')
                && $planes->first()->id === null);

        // Blade puro: el contenido llega en el HTML, sin la aplicación de Inertia
        $html = $respuesta->getContent();
        $this->assertStringNotContainsString('data-page=', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $respuesta->assertSee('Sistema POS y facturación electrónica', false)
            ->assertSee('Empieza gratis '.SuscripcionService::DIAS_PRUEBA.' días')
            ->assertSee('href="/registro"', false)
            ->assertSee('href="/login"', false);
    }

    public function test_la_landing_lleva_lo_que_piden_los_buscadores(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $url = rtrim(url('/'), '/');

        $this->assertStringContainsString('<html lang="es-PE">', $html);
        $this->assertMatchesRegularExpression('#<title>[^<]{30,65}</title>#u', $html);
        $this->assertMatchesRegularExpression('#<meta name="description" content="[^"]{110,160}">#u', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'/">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index,follow', $html);
        $this->assertStringContainsString('property="og:image"', $html);

        // datos estructurados válidos: organización, sitio, aplicación con precios en soles y preguntas frecuentes
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $bloques);
        $tipos = [];
        foreach ($bloques[1] as $json) {
            $datos = json_decode($json, true);
            $this->assertIsArray($datos, 'JSON-LD inválido');
            $tipos[$datos['@type']] = $datos;
        }
        $this->assertEqualsCanonicalizing(['Organization', 'WebSite', 'SoftwareApplication', 'FAQPage'], array_keys($tipos));
        $this->assertSame('PEN', $tipos['SoftwareApplication']['offers']['priceCurrency']);
        $this->assertSame(3, $tipos['SoftwareApplication']['offers']['offerCount']);
        $this->assertGreaterThanOrEqual(5, count($tipos['FAQPage']['mainEntity']));
        // cada pregunta de los datos estructurados está visible en la página
        foreach ($tipos['FAQPage']['mainEntity'] as $pregunta) {
            $this->assertStringContainsString(e($pregunta['name']), $html);
        }
    }

    public function test_un_usuario_con_sesion_va_al_dashboard(): void
    {
        $this->actingAs($this->admin)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->component('Inicio'));
    }

    public function test_el_dashboard_exige_sesion(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
