<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** QR de cobro (Yape, Plin): el admin los sube y el POS los muestra al cobrar. */
class CobrosQrTest extends TestCase
{
    use CreaEscenarioPos;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        Storage::fake('public');
    }

    public function test_el_admin_sube_su_qr_de_yape_con_numero_y_titular(): void
    {
        $this->actingAs($this->admin)->post('/empresa/cobros-qr', [
            'ambito' => 'empresa',
            'medio' => 'yape',
            'numero' => '987 654 321',
            'titular' => 'Carla Rojas',
            'qr' => UploadedFile::fake()->image('yape.jpg', 1400, 1400),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $yape = $this->empresa->refresh()->cobros_qr['yape'];
        $this->assertSame(['987654321', 'Carla Rojas'], [$yape['numero'], $yape['titular']]);

        // se guarda reducida y en PNG (el QR no debe perder nitidez)
        $ruta = substr($yape['qr_url'], strlen('/storage/'));
        Storage::disk('public')->assertExists($ruta);
        $this->assertStringEndsWith('.png', $ruta);
        $this->assertSame([900, 900], array_slice(getimagesizefromstring(Storage::disk('public')->get($ruta)), 0, 2));

        $this->actingAs($this->admin)->get('/empresa/cobros-qr')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Empresa/CobrosQr')
            ->where('empresa.yape.numero', '987654321')
            ->where('empresa.plin', null)
            ->has('sucursales', 1));
    }

    public function test_reemplazar_o_quitar_el_qr_borra_el_archivo_anterior(): void
    {
        $subir = fn (array $extra) => $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => 'empresa', 'medio' => 'plin'] + $extra)->assertSessionHasNoErrors();

        $subir(['qr' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $primera = substr($this->empresa->refresh()->cobros_qr['plin']['qr_url'], strlen('/storage/'));

        $subir(['qr' => UploadedFile::fake()->image('b.png', 300, 300)]);
        $segunda = substr($this->empresa->refresh()->cobros_qr['plin']['qr_url'], strlen('/storage/'));
        Storage::disk('public')->assertMissing($primera);
        Storage::disk('public')->assertExists($segunda);

        // sin imagen, sin número y sin titular: la billetera se quita
        $subir(['qr_eliminar' => true]);
        Storage::disk('public')->assertMissing($segunda);
        $this->assertNull($this->empresa->refresh()->cobros_qr);
    }

    public function test_el_qr_de_la_sucursal_manda_sobre_el_de_la_empresa_en_el_pos(): void
    {
        $otra = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sede Norte', 'activo' => true]);
        $this->empresa->update(['cobros_qr' => ['yape' => ['qr_url' => '/storage/cobros-qr/empresa.png', 'numero' => '911111111', 'titular' => 'Empresa'],
            'plin' => ['qr_url' => null, 'numero' => '922222222', 'titular' => null]]]);

        $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => $this->sucursal->id, 'medio' => 'yape', 'numero' => '933333333', 'titular' => 'Local centro'])
            ->assertSessionHasNoErrors();

        AperturaCaja::create(['empresa_id' => $this->empresa->id, 'caja_id' => $this->caja->id, 'usuario_id' => $this->admin->id, 'monto_inicial' => 0, 'abierta_en' => now()]);

        // la caja es de la sucursal principal: Yape propio, Plin heredado de la empresa
        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Pos/Index')
            ->where('cobrosQr.yape.numero', '933333333')
            ->where('cobrosQr.yape.qr_url', null)
            ->where('cobrosQr.plin.numero', '922222222'));

        $this->assertNull($otra->refresh()->cobros_qr);

        // vaciar lo de la sucursal la devuelve al QR de la empresa
        $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => $this->sucursal->id, 'medio' => 'yape'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (AssertableInertia $p) => $p->where('cobrosQr.yape.qr_url', '/storage/cobros-qr/empresa.png'));
    }

    public function test_valida_el_celular_la_imagen_y_que_la_sucursal_sea_de_la_empresa(): void
    {
        $ajena = Sucursal::where('empresa_id', '!=', $this->empresa->id)->value('id');

        $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => 'empresa', 'medio' => 'yape', 'numero' => '12345'])->assertSessionHasErrors('numero');
        $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => 'empresa', 'medio' => 'efectivo', 'numero' => '987654321'])->assertSessionHasErrors('medio');
        $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => 'empresa', 'medio' => 'yape', 'qr' => UploadedFile::fake()->create('qr.pdf', 50, 'application/pdf')])->assertSessionHasErrors('qr');
        if ($ajena) {
            $this->actingAs($this->admin)->post('/empresa/cobros-qr', ['ambito' => $ajena, 'medio' => 'yape', 'numero' => '987654321'])->assertSessionHasErrors('ambito');
        }

        $this->assertNull($this->empresa->refresh()->cobros_qr);
    }

    public function test_solo_el_admin_configura_los_qr(): void
    {
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(1000, 999999).'@test.local');

        $this->actingAs($vendedor)->get('/empresa/cobros-qr')->assertForbidden();
        $this->actingAs($vendedor)->post('/empresa/cobros-qr', ['ambito' => 'empresa', 'medio' => 'yape', 'numero' => '987654321'])->assertForbidden();
    }
}
