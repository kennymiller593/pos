<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** La cámara del menú lateral cambia solo el logo, sin tocar los demás datos. */
    public function test_cambiar_logo_desde_el_menu(): void
    {
        Storage::fake('public');
        $antes = $this->empresa->fresh()->only(['razon_social', 'nombre_comercial', 'rubro_codigo', 'variantes_activo']);

        $this->actingAs($this->admin)->post('/empresa/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
            ->assertSessionHas('success');
        $empresa = $this->empresa->fresh();
        $this->assertStringStartsWith('/storage/logos/', $empresa->logo_url);
        Storage::disk('public')->assertExists(substr($empresa->logo_url, strlen('/storage/')));
        $this->assertSame($antes, $empresa->only(array_keys($antes)));

        // reemplazar borra el archivo anterior
        $anterior = substr($empresa->logo_url, strlen('/storage/'));
        $this->actingAs($this->admin)->post('/empresa/logo', ['logo' => UploadedFile::fake()->image('otro.jpg', 100, 100)])->assertSessionHas('success');
        Storage::disk('public')->assertMissing($anterior);

        // solo imágenes y solo quien gestiona la empresa
        $this->actingAs($this->admin)->post('/empresa/logo', ['logo' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->actingAs($this->admin)->post('/empresa/logo', [])->assertSessionHasErrors('logo');
        $cajero = $this->crearUsuario('cajero', 'caja'.random_int(1000, 9999).'@test.local');
        $this->actingAs($cajero)->post('/empresa/logo', ['logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
    }
}
