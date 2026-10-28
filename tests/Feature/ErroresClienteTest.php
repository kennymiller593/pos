<?php

namespace Tests\Feature;

use App\Models\ErrorCliente;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Lo que falla en el navegador se avisa al servidor y la plataforma lo revisa. */
class ErroresClienteTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_el_navegador_avisa_un_error_y_queda_con_usuario_empresa_y_log(): void
    {
        Log::shouldReceive('channel')->with('cliente')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn ($mensaje, $contexto) => str_starts_with($mensaje, '[respuesta] El servidor respondió 403') && $contexto['usuario'] === $this->admin->email);

        $this->actingAs($this->admin)->withHeaders(['User-Agent' => 'Chrome de prueba'])->postJson('/errores-cliente', [
            'tipo' => 'respuesta',
            'mensaje' => 'El servidor respondió 403 Forbidden',
            'pantalla' => '/compras/crear',
            'accion' => 'POST /compras',
            'detalle' => ['estado' => 403, 'respuesta' => str_repeat('x', 3000)],
        ])->assertNoContent();

        $error = ErrorCliente::where('usuario_id', $this->admin->id)->firstOrFail();
        $this->assertSame($this->empresa->id, $error->empresa_id);
        $this->assertSame('respuesta', $error->tipo);
        $this->assertSame('/compras/crear', $error->pantalla);
        $this->assertSame('POST /compras', $error->accion);
        $this->assertSame(403, $error->detalle['estado']);
        $this->assertSame(1501, mb_strlen($error->detalle['respuesta'])); // recortado
        $this->assertSame('Chrome de prueba', $error->navegador);
        $this->assertNotNull($error->ip);
    }

    public function test_un_aviso_mal_formado_o_sin_sesion_no_se_guarda(): void
    {
        $this->postJson('/errores-cliente', ['tipo' => 'js', 'mensaje' => 'sin sesión'])->assertStatus(401);
        $this->actingAs($this->admin)->postJson('/errores-cliente', ['tipo' => 'otro', 'mensaje' => 'x'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/errores-cliente', ['tipo' => 'js'])->assertStatus(422);
        $this->assertSame(0, ErrorCliente::where('empresa_id', $this->empresa->id)->count());

        // un formulario rechazado (422) también se registra
        $this->actingAs($this->admin)->postJson('/errores-cliente', ['tipo' => 'validacion', 'mensaje' => 'La compra a crédito necesita un proveedor.', 'detalle' => ['campos' => ['proveedor_id']]])->assertNoContent();
        $this->assertSame('validacion', ErrorCliente::where('empresa_id', $this->empresa->id)->firstOrFail()->tipo);
        ErrorCliente::where('empresa_id', $this->empresa->id)->delete();

        // un mensaje larguísimo se recorta a 500
        $this->actingAs($this->admin)->postJson('/errores-cliente', ['tipo' => 'js', 'mensaje' => str_repeat('a', 1500)])->assertNoContent();
        $this->assertSame(500, mb_strlen(ErrorCliente::where('empresa_id', $this->empresa->id)->firstOrFail()->mensaje));
    }

    public function test_solo_el_superadmin_ve_los_errores_y_puede_filtrarlos(): void
    {
        ErrorCliente::create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->admin->id, 'tipo' => 'js', 'mensaje' => 'Cannot read properties of undefined']);
        ErrorCliente::create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->admin->id, 'tipo' => 'red', 'mensaje' => 'Network Error']);

        $this->actingAs($this->admin)->get('/admin/errores')->assertForbidden();

        // la base local puede tener avisos de otras empresas: se mira la de este escenario
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $e = $this->empresa->id;
        $this->actingAs($this->admin)->get("/admin/errores?empresa={$e}")->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Errores/Index')
            ->where('errores.total', 2)
            ->where('errores.data.0.mensaje', 'Network Error')
            ->where('errores.data.0.usuario', $this->admin->nombre_completo.' · '.$this->admin->email)
            ->where('empresas', fn ($lista) => collect($lista)->contains('id', $e)));
        $this->actingAs($this->admin)->get("/admin/errores?empresa={$e}&tipo=js")->assertInertia(fn (Assert $p) => $p->where('errores.total', 1)->where('filtros.tipo', 'js'));
        $this->actingAs($this->admin)->get("/admin/errores?empresa={$e}&desde=2030-01-01")->assertInertia(fn (Assert $p) => $p->where('errores.total', 0));
        // un filtro mal formado se ignora
        $this->actingAs($this->admin)->get('/admin/errores?tipo=zzz&empresa=xx&desde=ayer')->assertInertia(fn (Assert $p) => $p->where('errores.total', fn ($t) => $t >= 2)->where('filtros', []));
    }
}
