<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\SerieCorrelativo;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class FacturacionTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function completarRequisitos(): void
    {
        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA',
            'clave_certificado' => 'clave123',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos',
        ]);

        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B001',
            'correlativo' => 0,
        ]);
    }

    public function test_no_se_activa_sin_requisitos(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/empresa/facturacion');

        $respuesta->assertSessionHas('error', fn ($m) => str_contains($m, 'certificado digital'));
        $this->assertFalse($this->empresa->fresh()->facturacion_electronica);
    }

    public function test_se_activa_con_requisitos_y_deja_constancia(): void
    {
        $this->completarRequisitos();

        $this->actingAs($this->admin)->post('/empresa/facturacion')->assertSessionHas('success');

        $this->assertTrue($this->empresa->fresh()->facturacion_electronica);
        $this->assertSame(1, Auditoria::where('empresa_id', $this->empresa->id)
            ->where('accion', 'empresa.facturacion_activada')->count());

        // desactivar tambien funciona y queda registrado
        $this->actingAs($this->admin)->post('/empresa/facturacion')->assertSessionHas('success');
        $this->assertFalse($this->empresa->fresh()->facturacion_electronica);
    }

    public function test_sin_facturacion_el_pos_solo_emite_notas_de_venta(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();

        $vender = fn (string $tipo) => $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => $tipo,
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 5.00, 'referencia' => null]],
        ]);

        // boleta bloqueada; nota de venta permitida
        $vender('03')->assertSessionHasErrors('tipo_comprobante_codigo');
        $vender('00')->assertSessionHas('success');

        // con facturacion activa, la boleta pasa
        $this->completarRequisitos();
        $this->empresa->update(['facturacion_electronica' => true]);
        $this->admin->unsetRelation('empresa'); // el modelo cachea la relacion entre requests del test

        $vender('03')->assertSessionHas('success');
    }

    public function test_la_factura_exige_cliente_con_ruc(): void
    {
        $this->completarRequisitos();
        $this->empresa->update(['facturacion_electronica' => true]);
        $this->admin->unsetRelation('empresa');

        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();

        $vender = fn (?string $clienteId) => $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '01',
            'cliente_id' => $clienteId,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10.00, 'referencia' => null]],
        ]);

        // sin cliente o con cliente de DNI la factura se rechaza
        $vender(null)->assertSessionHas('error', fn ($m) => str_contains($m, 'RUC'));
        $vender($this->crearCliente()->id)->assertSessionHas('error', fn ($m) => str_contains($m, 'RUC'));

        $clienteRuc = Cliente::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento_codigo' => '6',
            'numero_documento' => '20123456786',
            'nombre' => 'Empresa Cliente SAC',
            'limite_credito' => 0,
        ]);

        $vender($clienteRuc->id)->assertSessionHas('success');

        // la serie F001 se crea sola y la venta queda a nombre del cliente
        $factura = Comprobante::where('empresa_id', $this->empresa->id)
            ->where('tipo_comprobante_codigo', '01')
            ->firstOrFail();
        $this->assertSame('F001', $factura->serie);
        $this->assertSame('Empresa Cliente SAC', $factura->cliente_nombre);
    }

    public function test_las_claves_se_guardan_cifradas_y_solo_escritura(): void
    {
        $this->actingAs($this->admin)->put('/empresa', [
            'razon_social' => $this->empresa->razon_social,
            'nombre_comercial' => null,
            'regimen_tributario' => $this->empresa->regimen_tributario,
            'rubro_codigo' => $this->empresa->rubro_codigo,
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'secreta123',
            'clave_certificado' => null,
            'certificado_digital' => null,
            'entorno_sunat' => 'beta',
        ])->assertSessionHas('success');

        $empresa = $this->empresa->fresh();
        $this->assertSame('secreta123', $empresa->clave_sol); // descifrada por el cast
        $this->assertNotSame('secreta123', $empresa->getRawOriginal('clave_sol')); // cifrada en la BD

        // enviar el campo vacio no borra la clave guardada
        $this->actingAs($this->admin)->put('/empresa', [
            'razon_social' => $this->empresa->razon_social,
            'nombre_comercial' => null,
            'regimen_tributario' => $this->empresa->regimen_tributario,
            'rubro_codigo' => $this->empresa->rubro_codigo,
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => null,
            'clave_certificado' => null,
            'certificado_digital' => null,
            'entorno_sunat' => null,
        ])->assertSessionHas('success');

        $this->assertSame('secreta123', $this->empresa->fresh()->clave_sol);
    }
}
