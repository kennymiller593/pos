<?php

namespace Tests\Feature;

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

    public function test_un_visitante_ve_la_landing_con_los_planes(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Landing')
            ->where('diasPrueba', 14)
            ->has('planes', 3)
            ->where('planes.0.codigo', 'emprendedor')
            ->missing('planes.0.id'));
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
