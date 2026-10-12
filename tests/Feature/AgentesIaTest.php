<?php

namespace Tests\Feature;

use App\IA\AgenteDeRubro;
use App\IA\Herramientas\BuscarProductos;
use App\IA\Herramientas\DetalleProducto;
use App\IA\Herramientas\ListarCategorias;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\Categoria;
use App\Models\ConversacionIa;
use App\Models\Empresa;
use App\Models\MensajeIa;
use App\Models\ModeloIa;
use App\Models\ProveedorIa;
use App\Services\IA\ProveedorIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Agentes de IA: proveedores con clave cifrada, cadena de modelos con respaldo, activación por empresa y chat con el catálogo. */
class AgentesIaTest extends TestCase
{
    use CreaEscenarioPos;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        // el escenario comparte la base local: los proveedores vuelven a un estado conocido dentro de la transacción
        ProveedorIa::query()->update(['api_key' => null, 'activo' => false, 'fallos_seguidos' => 0, 'pausado_hasta' => null, 'url' => null]);
    }

    private function superadmin()
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();

        return $this->actingAs($this->admin->fresh());
    }

    private function proveedorListo(string $codigo = 'deepseek'): ProveedorIa
    {
        $p = ProveedorIa::findOrFail($codigo);
        $p->forceFill(['api_key' => 'sk-prueba-'.$codigo, 'activo' => true])->save();

        return $p;
    }

    private function crearAgente(array $cadena = [['proveedor' => 'deepseek', 'modelo' => 'deepseek-v4-pro']], array $extra = []): AgenteIa
    {
        return AgenteIa::create([
            'codigo' => 'agente_'.random_int(1000, 999999), 'nombre' => 'Agente de prueba', 'rubro_codigo' => $this->empresa->rubro_codigo,
            'instrucciones' => 'Eres un asesor de ventas.', 'cadena' => $cadena, 'limite_mensual' => 1000, 'max_pasos' => 4, 'activo' => true,
        ] + $extra);
    }

    private function activar(AgenteIa $agente, ?int $limite = null): AgenteEmpresa
    {
        return AgenteEmpresa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $agente->id, 'activo' => true, 'limite_mensual' => $limite, 'activado_en' => now()]);
    }

    // ---------- proveedores ----------

    public function test_el_superadmin_guarda_la_clave_cifrada_y_nunca_la_vuelve_a_ver(): void
    {
        $this->superadmin()->put('/admin/ia/proveedores/deepseek', ['api_key' => 'sk-secreta-1234', 'url' => '', 'activo' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        // en la base queda cifrada; el modelo la descifra
        $this->assertStringNotContainsString('sk-secreta', (string) DB::table('ia_proveedores')->where('codigo', 'deepseek')->value('api_key'));
        $this->assertSame('sk-secreta-1234', ProveedorIa::find('deepseek')->api_key);

        $this->superadmin()->get('/admin/ia/proveedores')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Admin/IA/Proveedores')
            ->where('proveedores.0.codigo', 'deepseek')
            ->where('proveedores.0.tiene_clave', true)
            ->where('proveedores.0.clave_final', '1234')
            ->missing('proveedores.0.api_key'));

        // guardar sin clave conserva la actual; quitarla la borra y desactiva
        $this->superadmin()->put('/admin/ia/proveedores/deepseek', ['api_key' => '', 'url' => 'https://api.deepseek.com/v1', 'activo' => true])->assertSessionHasNoErrors();
        $this->assertSame('sk-secreta-1234', ProveedorIa::find('deepseek')->api_key);
        $this->superadmin()->put('/admin/ia/proveedores/deepseek', ['quitar_clave' => true, 'url' => '', 'activo' => true])->assertSessionHasNoErrors();
        $p = ProveedorIa::find('deepseek');
        $this->assertNull($p->api_key);
        $this->assertFalse($p->activo);

        $this->superadmin()->put('/admin/ia/proveedores/deepseek', ['url' => 'no-es-url', 'activo' => true])->assertSessionHasErrors('url');
    }

    public function test_solo_el_superadmin_entra_a_proveedores_y_agentes(): void
    {
        $this->actingAs($this->admin)->get('/admin/ia/proveedores')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/ia/agentes')->assertForbidden();
        $this->actingAs($this->admin)->put('/admin/ia/proveedores/deepseek', ['api_key' => 'x', 'activo' => true])->assertForbidden();
    }

    public function test_el_fusible_pausa_al_proveedor_tras_tres_fallos_y_un_exito_lo_restablece(): void
    {
        $p = $this->proveedorListo();
        $servicio = app(ProveedorIaService::class);

        foreach (range(1, 3) as $i) {
            $servicio->registrarFallo($p, new \RuntimeException('Connection timed out with key sk-abcdefghijkl'));
        }
        $p->refresh();
        $this->assertTrue($p->enPausa());
        $this->assertFalse($p->disponible());
        $this->assertSame(3, $p->fallos_seguidos);
        // el resumen del error no deja la clave a la vista
        $this->assertStringNotContainsString('abcdefghijkl', $p->ultimo_error);

        $servicio->registrarExito($p);
        $p->refresh();
        $this->assertTrue($p->disponible());
        $this->assertSame(0, $p->fallos_seguidos);
    }

    public function test_el_superadmin_registra_precios_por_modelo(): void
    {
        $this->superadmin()->post('/admin/ia/proveedores/openai/modelos', ['modelo' => 'modelo-x', 'precio_entrada' => 1.5, 'precio_salida' => 6, 'nota' => 'prueba'])->assertSessionHasNoErrors();
        $m = ModeloIa::where('proveedor_codigo', 'openai')->where('modelo', 'modelo-x')->firstOrFail();
        $this->assertSame(0.0135, $m->costo(5000, 1000)); // 5000*1.5/1M + 1000*6/1M

        $this->superadmin()->delete("/admin/ia/modelos/{$m->id}")->assertSessionHas('success');
        $this->assertDatabaseMissing('ia_modelos', ['id' => $m->id]);
    }

    // ---------- agentes ----------

    public function test_el_superadmin_crea_un_agente_con_su_cadena_y_lo_activa_en_todo_el_rubro(): void
    {
        $this->proveedorListo();
        $datos = [
            'nombre' => 'Agente agrónomo', 'rubro_codigo' => $this->empresa->rubro_codigo, 'descripcion' => 'Asesora en campo',
            'instrucciones' => 'Eres agrónomo.', 'cadena' => [['proveedor' => 'deepseek', 'modelo' => ' deepseek-v4-pro '], ['proveedor' => 'anthropic', 'modelo' => 'claude-haiku-5-5']],
            'limite_mensual' => 500, 'max_pasos' => 5, 'sugerencias' => ['¿Qué aplico?', ''], 'activo' => true,
        ];

        $this->superadmin()->post('/admin/ia/agentes', $datos)->assertSessionHasNoErrors()->assertSessionHas('success');
        $agente = AgenteIa::where('nombre', 'Agente agrónomo')->firstOrFail();
        $this->assertStringStartsWith('agente_agronomo', $agente->codigo); // la base local puede tener ya uno con ese código
        $this->assertSame([['proveedor' => 'deepseek', 'modelo' => 'deepseek-v4-pro'], ['proveedor' => 'anthropic', 'modelo' => 'claude-haiku-5-5']], $agente->eslabones());
        $this->assertSame(['¿Qué aplico?'], $agente->sugerencias);

        // validación: sin modelos no se guarda
        $this->superadmin()->post('/admin/ia/agentes', ['cadena' => []] + $datos)->assertSessionHasErrors('cadena');
        $this->superadmin()->post('/admin/ia/agentes', ['cadena' => [['proveedor' => 'nadie', 'modelo' => 'x']]] + $datos)->assertSessionHasErrors('cadena.0.proveedor');

        // activar en todo el rubro: la empresa del escenario es de ese rubro
        $this->superadmin()->post("/admin/ia/agentes/{$agente->id}/activar-rubro")->assertSessionHas('success');
        $this->assertTrue(AgenteEmpresa::where('empresa_id', $this->empresa->id)->where('agente_id', $agente->id)->where('activo', true)->exists());

        // y la empresa ya ve el adicional en su sesión
        $this->actingAs($this->admin->fresh()->forceFill(['es_superadmin' => false]))->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('auth.user.empresa.ia_habilitada', true));

        $this->superadmin()->get('/admin/ia/agentes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Admin/IA/Agentes')
            ->where('agentes.0.codigo', fn ($c) => str_starts_with($c, 'agente_agronomo'))
            ->where('agentes.0.empresas_activas', fn ($n) => $n >= 1)
            ->has('plantillas.agro.instrucciones'));
    }

    public function test_la_ficha_de_la_empresa_activa_y_quita_un_agente(): void
    {
        $agente = $this->crearAgente();

        $this->superadmin()->post("/admin/empresas/{$this->empresa->id}/agentes/{$agente->id}", ['limite_mensual' => 300])->assertSessionHas('success');
        $activacion = AgenteEmpresa::where('empresa_id', $this->empresa->id)->where('agente_id', $agente->id)->firstOrFail();
        $this->assertTrue($activacion->activo);
        $this->assertSame(300, $activacion->limite_mensual);

        // la base local puede tener otros agentes: se busca el nuestro en la lista
        $fila = collect($this->superadmin()->get("/admin/empresas/{$this->empresa->id}")->assertOk()->viewData('page')['props']['agentes'])->firstWhere('id', $agente->id);
        $this->assertSame([true, 300], [$fila['activo'], $fila['limite_mensual']]);

        $this->superadmin()->post("/admin/empresas/{$this->empresa->id}/agentes/{$agente->id}")->assertSessionHas('success');
        $this->assertFalse($activacion->refresh()->activo);
        $this->assertNotNull($activacion->desactivado_en);

        $this->actingAs($this->admin->fresh()->forceFill(['es_superadmin' => false]))->get('/asistente')->assertRedirect('/dashboard');
    }

    // ---------- herramientas del catálogo ----------

    public function test_las_herramientas_buscan_solo_en_el_catalogo_de_la_empresa(): void
    {
        $categoria = Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Insecticidas']);
        $p = $this->crearProducto(38.5, ['nombre' => 'Clorpirifos 48 EC x 1 L', 'categoria_id' => $categoria->id, 'descripcion' => 'Insecticida para gusano cogollero en maíz. Dosis según etiqueta.']);
        $this->darStock($p, 12, 20);
        $sinStock = $this->crearProducto(14, ['nombre' => 'Cipermetrina 25 EC x 250 ml', 'categoria_id' => $categoria->id]);

        $buscar = new BuscarProductos($this->empresa, $this->sucursal);
        $r = $buscar->handle(new ToolRequest(['consulta' => 'cogollero maíz']));
        $this->assertStringContainsString('Clorpirifos 48 EC x 1 L', $r);
        $this->assertStringContainsString('S/ 38.50', $r);
        $this->assertStringContainsString('Stock: 12', $r);
        $this->assertStringContainsString('Categoría: Insecticidas', $r);
        $this->assertStringNotContainsString('Cipermetrina', $r);

        // por categoría encuentra los dos; el que no tiene stock lo dice
        $r = $buscar->handle(new ToolRequest(['consulta' => 'insecticida']));
        $this->assertStringContainsString('Cipermetrina 25 EC x 250 ml', $r);
        $this->assertStringContainsString('Stock: sin stock', $r);

        $this->assertStringContainsString('No hay productos', $buscar->handle(new ToolRequest(['consulta' => 'fungicida roya'])));

        // otra empresa no ve este catálogo
        $otra = Empresa::create(['ruc' => '20'.random_int(10000000, 99999999).'1', 'razon_social' => 'Otra SAC', 'regimen_tributario' => 'RUS', 'rubro_codigo' => $this->empresa->rubro_codigo, 'activo' => true]);
        $this->assertStringContainsString('No hay productos', (new BuscarProductos($otra))->handle(new ToolRequest(['consulta' => 'clorpirifos'])));

        $this->assertStringContainsString('Dosis según etiqueta', (new DetalleProducto($this->empresa, $this->sucursal))->handle(new ToolRequest(['codigo' => $p->codigo_interno])));
        $this->assertStringContainsString('No existe', (new DetalleProducto($this->empresa))->handle(new ToolRequest(['codigo' => 'NADA'])));
        $this->assertStringContainsString('Insecticidas (2 productos)', (new ListarCategorias($this->empresa))->handle(new ToolRequest([])));
    }

    // ---------- chat ----------

    public function test_el_usuario_consulta_y_la_respuesta_queda_guardada_con_proveedor_tokens_y_costo(): void
    {
        $this->proveedorListo();
        $agente = $this->crearAgente(extra: ['sugerencias' => ['¿Qué tienen para la roya?']]);
        $this->activar($agente);
        AgenteDeRubro::fake(['Te recomiendo **Clorpirifos 48 EC**: S/ 38.50, hay stock.']);

        $this->actingAs($this->admin)->get('/asistente')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Asistente/Index')
            ->where('agentes.0.nombre', 'Agente de prueba')
            ->where('agentes.0.sugerencias.0', '¿Qué tienen para la roya?')
            ->where('agentes.0.consultas_mes', 0)
            ->where('agentes.0.limite_mensual', 1000)
            ->where('actual', null));

        $r = $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $agente->id, 'texto' => '¿Qué le aplico al maíz con gusano cogollero?'])
            ->assertOk()
            ->assertJsonPath('mensaje.rol', 'assistant')
            ->assertJsonPath('mensaje.proveedor', 'deepseek')
            ->assertJsonPath('mensaje.modelo', 'deepseek-v4-pro')
            ->assertJsonPath('consultas_mes', 1)
            ->assertJsonPath('conversacion.titulo', '¿Qué le aplico al maíz con gusano cogollero?');

        $conversacion = ConversacionIa::findOrFail($r->json('conversacion.id'));
        $this->assertSame(['user', 'assistant'], $conversacion->mensajes->pluck('rol')->all());
        $respuesta = $conversacion->mensajes->last();
        $this->assertStringContainsString('Clorpirifos', $respuesta->contenido);
        $this->assertSame('deepseek', $respuesta->proveedor_codigo);
        $this->assertSame([], $respuesta->intentos);

        // la segunda consulta sigue en la misma conversación y recuerda el historial
        AgenteDeRubro::fake([fn ($prompt) => 'Segunda: '.$prompt]);
        $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['conversacion_id' => $conversacion->id, 'texto' => '¿y la dosis?'])
            ->assertOk()->assertJsonPath('consultas_mes', 2);
        $this->assertCount(4, $conversacion->mensajes()->get());

        $this->actingAs($this->admin)->get("/asistente?c={$conversacion->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('actual.id', $conversacion->id)->has('actual.mensajes', 4)->where('conversaciones.0.id', $conversacion->id));

        // otro usuario no puede seguir esta conversación
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(1000, 999999).'@test.local');
        $this->actingAs($vendedor)->postJson('/asistente/mensajes', ['conversacion_id' => $conversacion->id, 'texto' => 'hola'])->assertStatus(422);

        $this->actingAs($this->admin)->delete("/asistente/conversaciones/{$conversacion->id}")->assertRedirect('/asistente');
        $this->assertDatabaseMissing('ia_conversaciones', ['id' => $conversacion->id]);
    }

    public function test_la_cadena_salta_a_los_proveedores_sin_clave_o_en_pausa_y_anota_los_intentos(): void
    {
        // deepseek sin clave, anthropic en pausa, openrouter listo
        $this->proveedorListo('anthropic')->forceFill(['pausado_hasta' => now()->addMinutes(3), 'fallos_seguidos' => 3])->save();
        $this->proveedorListo('openrouter');
        $agente = $this->crearAgente([
            ['proveedor' => 'deepseek', 'modelo' => 'deepseek-v4-pro'],
            ['proveedor' => 'anthropic', 'modelo' => 'claude-haiku-5-5'],
            ['proveedor' => 'openrouter', 'modelo' => 'deepseek/deepseek-v4-pro'],
        ]);
        $this->activar($agente);
        AgenteDeRubro::fake(['Respuesta del respaldo']);

        $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $agente->id, 'texto' => 'hola'])
            ->assertOk()->assertJsonPath('mensaje.proveedor', 'openrouter')->assertJsonPath('mensaje.modelo', 'deepseek/deepseek-v4-pro');

        $respuesta = MensajeIa::where('empresa_id', $this->empresa->id)->where('rol', 'assistant')->latest('creado_en')->firstOrFail();
        $this->assertSame(['deepseek', 'anthropic'], array_column($respuesta->intentos, 'proveedor'));
        $this->assertSame('sin clave o desactivado', $respuesta->intentos[0]['error']);
        $this->assertSame('en pausa por fallos', $respuesta->intentos[1]['error']);
    }

    public function test_sin_proveedor_disponible_el_asistente_avisa_sin_guardar_nada(): void
    {
        $agente = $this->crearAgente();
        $this->activar($agente);

        $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $agente->id, 'texto' => 'hola'])
            ->assertStatus(422)->assertJsonPath('mensaje', 'El asistente no está disponible en este momento. Inténtalo de nuevo en unos minutos.');
        $this->assertSame(0, ConversacionIa::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_el_tope_mensual_corta_las_consultas(): void
    {
        $this->proveedorListo();
        $agente = $this->crearAgente();
        $this->activar($agente, limite: 1);
        AgenteDeRubro::fake(['ok', 'ok']);

        $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $agente->id, 'texto' => 'uno'])->assertOk();
        $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $agente->id, 'texto' => 'dos'])
            ->assertStatus(422)->assertJsonFragment(['mensaje' => 'Se alcanzó el tope de 1 consultas de este mes para "Agente de prueba". Vuelve a intentarlo el próximo mes o pide a la plataforma ampliarlo.']);
    }

    public function test_sin_agente_activo_no_se_puede_consultar(): void
    {
        $this->proveedorListo();
        $agente = $this->crearAgente();

        $this->actingAs($this->admin)->get('/asistente')->assertRedirect('/dashboard');
        $this->actingAs($this->admin)->postJson('/asistente/mensajes', ['agente_id' => $agente->id, 'texto' => 'hola'])->assertForbidden();
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('auth.user.empresa.ia_habilitada', false));
    }

    public function test_el_superadmin_prueba_un_agente_con_el_catalogo_de_una_empresa_sin_guardar(): void
    {
        $this->proveedorListo();
        $agente = $this->crearAgente();
        AgenteDeRubro::fake(['Probando con el catálogo']);

        $this->superadmin()->postJson("/admin/ia/agentes/{$agente->id}/probar", ['empresa_id' => $this->empresa->id, 'texto' => '¿qué venden?'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('contenido', 'Probando con el catálogo')->assertJsonPath('proveedor_codigo', 'deepseek');
        $this->assertSame(0, ConversacionIa::where('empresa_id', $this->empresa->id)->count());

        // sin proveedor con clave, responde el motivo
        ProveedorIa::query()->update(['api_key' => null]);
        $this->superadmin()->postJson("/admin/ia/agentes/{$agente->id}/probar", ['empresa_id' => $this->empresa->id, 'texto' => 'hola'])->assertStatus(422)->assertJsonPath('ok', false);
    }
}
