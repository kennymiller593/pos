<?php

namespace Tests\Feature;

use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class UbigeoTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_busca_distritos_por_nombre(): void
    {
        $respuesta = $this->actingAs($this->admin)->getJson('/consultas/ubigeos?buscar=miraflores');

        $respuesta->assertOk();
        $codigos = collect($respuesta->json())->pluck('codigo');
        $this->assertTrue($codigos->contains('150122')); // Miraflores, Lima
    }

    public function test_resuelve_la_etiqueta_por_codigo(): void
    {
        $respuesta = $this->actingAs($this->admin)->getJson('/consultas/ubigeos?codigo=150101');

        $respuesta->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.codigo', '150101')
            ->assertJsonPath('0.etiqueta', 'Lima, Lima, Lima');
    }

    public function test_busquedas_cortas_no_devuelven_nada(): void
    {
        $this->actingAs($this->admin)->getJson('/consultas/ubigeos?buscar=m')
            ->assertOk()->assertJsonCount(0);
    }

    public function test_requiere_sesion(): void
    {
        $this->getJson('/consultas/ubigeos?buscar=lima')->assertStatus(401);
    }

    public function test_sucursal_rechaza_ubigeo_fuera_del_catalogo(): void
    {
        $this->actingAs($this->admin)->put("/sucursales/{$this->sucursal->id}", [
            'nombre' => $this->sucursal->nombre,
            'codigo_sunat' => trim($this->sucursal->codigo_sunat),
            'direccion' => 'Av. Test 123',
            'ubigeo' => '999999',
            'telefono' => null,
            'activo' => true,
        ])->assertSessionHasErrors('ubigeo');
    }
}
