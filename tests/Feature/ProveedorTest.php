<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Proveedor;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ProveedorTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function crearProveedor(array $atributos = []): Proveedor
    {
        return Proveedor::create([
            'empresa_id' => $this->empresa->id,
            'razon_social' => 'Proveedor Test '.random_int(1000, 9999),
            'ruc' => '20'.random_int(100000000, 999999999),
            ...$atributos,
        ]);
    }

    public function test_crear_desde_la_pagina_y_desde_el_modal_json(): void
    {
        // flujo Inertia (pagina /proveedores)
        $this->actingAs($this->admin)->post('/proveedores', [
            'razon_social' => 'Distribuidora Pagina S.A.C.',
            'ruc' => '20111111111',
            'contacto' => null,
            'telefono' => null,
        ])->assertRedirect()->assertSessionHas('success');

        // flujo JSON (modal de compras)
        $respuesta = $this->actingAs($this->admin)->postJson('/proveedores', [
            'razon_social' => 'Distribuidora Modal S.A.C.',
            'ruc' => '20222222222',
            'contacto' => null,
            'telefono' => null,
        ]);

        $respuesta->assertCreated()->assertJsonStructure(['id', 'razon_social', 'ruc']);
        $this->assertSame(2, Proveedor::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_ruc_duplicado_es_rechazado(): void
    {
        $this->crearProveedor(['ruc' => '20333333333']);

        $respuesta = $this->actingAs($this->admin)->postJson('/proveedores', [
            'razon_social' => 'Otra',
            'ruc' => '20333333333',
        ]);

        $respuesta->assertUnprocessable()->assertJsonValidationErrors('ruc');
    }

    public function test_editar_proveedor(): void
    {
        $proveedor = $this->crearProveedor();

        $this->actingAs($this->admin)->put("/proveedores/{$proveedor->id}", [
            'razon_social' => 'Renombrado S.A.C.',
            'ruc' => trim($proveedor->ruc),
            'contacto' => 'Nuevo Contacto',
            'telefono' => '999888777',
        ])->assertSessionHas('success');

        $proveedor->refresh();
        $this->assertSame('Renombrado S.A.C.', $proveedor->razon_social);
        $this->assertSame('Nuevo Contacto', $proveedor->contacto);
    }

    public function test_eliminar_conserva_las_compras_historicas(): void
    {
        $proveedor = $this->crearProveedor();
        Compra::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'proveedor_id' => $proveedor->id,
            'usuario_id' => $this->admin->id,
            'fecha' => now()->toDateString(),
            'total' => 100,
            'es_credito' => false,
        ]);

        $this->actingAs($this->admin)->delete("/proveedores/{$proveedor->id}")->assertSessionHas('success');

        // soft delete: ya no aparece en listados, pero la compra sigue mostrando su nombre
        $this->assertNull(Proveedor::find($proveedor->id));
        $this->assertNotNull(Proveedor::withTrashed()->find($proveedor->id));

        $respuesta = $this->actingAs($this->admin)->get('/compras');
        $respuesta->assertOk();
        $this->assertStringContainsString($proveedor->razon_social, $respuesta->getContent());
    }

    public function test_no_puede_tocar_proveedores_de_otra_empresa(): void
    {
        $proveedor = $this->crearProveedor();
        $adminAjeno = $this->admin;

        $this->crearEscenarioBase(); // segunda empresa
        $intruso = $this->admin;

        $this->actingAs($intruso)->put("/proveedores/{$proveedor->id}", [
            'razon_social' => 'Hackeado',
            'ruc' => null,
        ])->assertForbidden();

        $this->actingAs($intruso)->delete("/proveedores/{$proveedor->id}")->assertForbidden();
    }

    public function test_busqueda_para_el_formulario_de_compras(): void
    {
        $this->crearProveedor(['razon_social' => 'Molitalia Andina S.A.']);

        $respuesta = $this->actingAs($this->admin)->getJson('/proveedores/buscar?buscar=Molitalia');

        $respuesta->assertOk();
        $this->assertCount(1, $respuesta->json());
    }
}
