<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_la_landing_es_publica_y_el_dashboard_pide_sesion(): void
    {
        $this->get('/')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_el_login_carga(): void
    {
        $this->get('/login')->assertStatus(200);
    }
}
