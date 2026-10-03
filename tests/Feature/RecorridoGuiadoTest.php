<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class RecorridoGuiadoTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_el_usuario_nuevo_ve_el_recorrido_una_sola_vez(): void
    {
        $pendiente = fn ($usuario, bool $esperado) => $this->actingAs($usuario)->get('/dashboard')
            ->assertInertia(fn (Assert $pagina) => $pagina->where('auth.user.recorrido_pendiente', $esperado));

        $pendiente($this->admin, true);

        $this->actingAs($this->admin)->from('/dashboard')->post('/recorrido-visto')->assertRedirect('/dashboard');
        $this->assertNotNull($this->admin->fresh()->recorrido_visto_en);
        $pendiente($this->admin->fresh(), false);

        // repetirlo no cambia la fecha en que lo vio
        $fecha = $this->admin->fresh()->recorrido_visto_en;
        $this->travel(2)->days();
        $this->actingAs($this->admin->fresh())->post('/recorrido-visto');
        $this->assertTrue($fecha->equalTo($this->admin->fresh()->recorrido_visto_en));
    }

    public function test_cada_usuario_lleva_su_propia_marca(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($this->admin)->post('/recorrido-visto');

        $this->assertNotNull($this->admin->fresh()->recorrido_visto_en);
        $this->assertNull($cajero->fresh()->recorrido_visto_en);
        $this->actingAs($cajero)->get('/dashboard')
            ->assertInertia(fn (Assert $pagina) => $pagina->where('auth.user.recorrido_pendiente', true));
    }

    public function test_sin_sesion_no_se_puede_marcar(): void
    {
        $this->post('/recorrido-visto')->assertRedirect('/login');
    }
}
