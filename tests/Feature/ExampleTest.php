<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_un_invitado_es_redirigido_al_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_el_login_carga(): void
    {
        $this->get('/login')->assertStatus(200);
    }
}
