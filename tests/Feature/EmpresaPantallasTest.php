<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Los datos de la empresa y la facturación electrónica van en pantallas separadas, cada una con su botón:
 * así el dueño no cree que debe llenar las credenciales de SUNAT para cambiar su logo o su nombre comercial.
 */
class EmpresaPantallasTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_datos_generales_y_facturacion_se_editan_por_separado(): void
    {
        $this->actingAs($this->admin)->get('/empresa')->assertInertia(fn (Assert $p) => $p
            ->component('Empresa/Editar')
            ->has('empresa.facturacion_electronica')
            ->missing('empresa.usuario_sol')
            ->missing('requisitosFacturacion'));

        $this->actingAs($this->admin)->get('/empresa/facturacion-electronica')->assertInertia(fn (Assert $p) => $p
            ->component('Empresa/Facturacion')
            ->has('empresa.usuario_sol')
            ->has('requisitosFacturacion.credenciales_sol'));

        // guardar datos generales no toca las credenciales, aunque vengan en la petición
        $this->actingAs($this->admin)->put('/empresa', [
            'razon_social' => 'Agro Prueba SAC', 'nombre_comercial' => 'Agro Prueba', 'regimen_tributario' => 'MYPE',
            'rubro_codigo' => $this->empresa->rubro_codigo, 'usuario_sol' => 'NOVALE',
        ])->assertSessionHas('success', 'Datos de la empresa actualizados.');
        $empresa = $this->empresa->fresh();
        $this->assertSame('Agro Prueba', $empresa->nombre_comercial);
        $this->assertNull($empresa->usuario_sol);

        // la facturación se guarda en su pantalla, sin pedir razón social ni rubro
        $this->actingAs($this->admin)->put('/empresa/facturacion-electronica', ['usuario_sol' => 'MODDATOS', 'clave_sol' => 'moddatos'])
            ->assertSessionHas('success', 'Datos de facturación electrónica guardados.');
        $empresa = $this->empresa->fresh();
        $this->assertSame('MODDATOS', $empresa->usuario_sol);
        $this->assertSame('moddatos', $empresa->clave_sol);
        $this->assertSame('Agro Prueba', $empresa->nombre_comercial);

        // lo que no se escribe se conserva
        $this->actingAs($this->admin)->put('/empresa/facturacion-electronica', ['usuario_sol' => 'MODDATOS', 'clave_sol' => ''])->assertSessionHas('success');
        $this->assertSame('moddatos', $this->empresa->fresh()->clave_sol);

        // solo quien gestiona la empresa
        $cajero = $this->crearUsuario('cajero', 'caja'.random_int(1000, 9999).'@test.local');
        $this->actingAs($cajero)->get('/empresa/facturacion-electronica')->assertForbidden();
        $this->actingAs($cajero)->put('/empresa/facturacion-electronica', ['usuario_sol' => 'X'])->assertForbidden();
    }
}
