<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\SerieCorrelativo;
use App\Models\Sucursal;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class SucursalTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_crear_sucursal_genera_su_caja_inicial(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/sucursales', [
            'nombre' => 'Sucursal Centro',
            'codigo_sunat' => '0001',
            'direccion' => 'Av. Test 123',
            'ubigeo' => '150101',
            'telefono' => null,
            'activo' => true,
        ]);

        $respuesta->assertSessionHas('success');

        $sucursal = Sucursal::where('empresa_id', $this->empresa->id)->where('nombre', 'Sucursal Centro')->firstOrFail();
        $this->assertSame('0001', trim($sucursal->codigo_sunat));
        $this->assertSame(1, Caja::where('sucursal_id', $sucursal->id)->count());
    }

    public function test_codigo_sunat_es_unico_por_empresa(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->from('/sucursales')
            ->post('/sucursales', [
                'nombre' => 'Duplicada',
                'codigo_sunat' => '0000', // ya usado por la Principal del escenario
                'direccion' => null,
                'ubigeo' => null,
                'telefono' => null,
                'activo' => true,
            ]);

        $respuesta->assertSessionHasErrors('codigo_sunat');
        $this->assertSame(1, Sucursal::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_no_se_puede_desactivar_la_unica_sucursal_activa(): void
    {
        $respuesta = $this->actingAs($this->admin)->put("/sucursales/{$this->sucursal->id}", [
            'nombre' => $this->sucursal->nombre,
            'codigo_sunat' => trim($this->sucursal->codigo_sunat),
            'direccion' => null,
            'ubigeo' => null,
            'telefono' => null,
            'activo' => false,
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertTrue($this->sucursal->fresh()->activo);
    }

    public function test_gestion_de_cajas_de_la_sucursal(): void
    {
        // crear caja nueva con tiketera de 58mm
        $this->actingAs($this->admin)->post("/sucursales/{$this->sucursal->id}/cajas", [
            'caja_id' => null,
            'nombre' => 'Caja 2',
            'ancho_ticket' => 58,
            'activo' => true,
        ])->assertSessionHas('success');

        $caja2 = Caja::where('sucursal_id', $this->sucursal->id)->where('nombre', 'Caja 2')->firstOrFail();
        $this->assertSame(58, (int) $caja2->ancho_ticket);

        // nombre duplicado rechazado
        $this->actingAs($this->admin)->post("/sucursales/{$this->sucursal->id}/cajas", [
            'caja_id' => null,
            'nombre' => 'Caja 2',
            'ancho_ticket' => 80,
            'activo' => true,
        ])->assertSessionHas('error');

        // renombrar y cambiar la tiketera a 80mm
        $this->actingAs($this->admin)->post("/sucursales/{$this->sucursal->id}/cajas", [
            'caja_id' => $caja2->id,
            'nombre' => 'Caja Rápida',
            'ancho_ticket' => 80,
            'activo' => true,
        ])->assertSessionHas('success');

        $caja2->refresh();
        $this->assertSame('Caja Rápida', $caja2->nombre);
        $this->assertSame(80, (int) $caja2->ancho_ticket);
    }

    public function test_no_se_desactiva_una_caja_con_turno_abierto(): void
    {
        $this->abrirCaja(100); // abre la caja del escenario con el admin

        $respuesta = $this->actingAs($this->admin)->post("/sucursales/{$this->sucursal->id}/cajas", [
            'caja_id' => $this->caja->id,
            'nombre' => $this->caja->nombre,
            'ancho_ticket' => 80,
            'activo' => false,
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertTrue($this->caja->fresh()->activo);
    }

    public function test_gestion_de_series_de_la_sucursal(): void
    {
        $url = "/sucursales/{$this->sucursal->id}/series";

        // crear serie general de boleta
        $this->actingAs($this->admin)->post($url, [
            'serie_id' => null,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B005',
            'caja_id' => null,
            'correlativo' => 100,
        ])->assertSessionHas('success');

        $serie = SerieCorrelativo::where('sucursal_id', $this->sucursal->id)->where('serie', 'B005')->firstOrFail();
        $this->assertSame(100, $serie->correlativo);

        // boleta con prefijo invalido rechazada
        $this->actingAs($this->admin)->post($url, [
            'serie_id' => null,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'X001',
            'caja_id' => null,
            'correlativo' => 0,
        ])->assertSessionHas('error');

        // segunda serie general del mismo tipo rechazada
        $this->actingAs($this->admin)->post($url, [
            'serie_id' => null,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B006',
            'caja_id' => null,
            'correlativo' => 0,
        ])->assertSessionHas('error');

        // el correlativo no puede retroceder
        $this->actingAs($this->admin)->post($url, [
            'serie_id' => $serie->id,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B005',
            'caja_id' => null,
            'correlativo' => 50,
        ])->assertSessionHas('error');

        // no se elimina una serie con comprobantes emitidos
        $this->actingAs($this->admin)->delete("{$url}/{$serie->id}")->assertSessionHas('error');
        $this->assertNotNull($serie->fresh());

        // una serie sin emisiones si se elimina
        $sinUso = SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F009',
            'correlativo' => 0,
        ]);
        $this->actingAs($this->admin)->delete("{$url}/{$sinUso->id}")->assertSessionHas('success');
        $this->assertNull($sinUso->fresh());
    }

    public function test_la_serie_es_unica_en_toda_la_empresa(): void
    {
        // serie B005 ya existe en la principal
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B005',
            'correlativo' => 0,
        ]);

        $secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);

        $this->actingAs($this->admin)->post("/sucursales/{$secundaria->id}/series", [
            'serie_id' => null,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B005',
            'caja_id' => null,
            'correlativo' => 0,
        ])->assertSessionHas('error');
    }

    public function test_solo_admin_gestiona_sucursales(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->get('/sucursales')->assertForbidden();
        $this->actingAs($cajero)->post('/sucursales', [])->assertForbidden();
    }
}
