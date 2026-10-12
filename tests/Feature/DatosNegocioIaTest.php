<?php

namespace Tests\Feature;

use App\IA\AgenteDeRubro;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\ConversacionIa;
use App\Models\MensajeIa;
use App\Models\Sucursal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** El agente conoce los datos del negocio, y la campanita avisa de los chats de la tienda sin leer. */
class DatosNegocioIaTest extends TestCase
{
    use CreaEscenarioPos;
    use DatabaseTransactions;

    private AgenteIa $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->agente = AgenteIa::create([
            'codigo' => 'agente_'.random_int(1000, 999999), 'nombre' => 'Agente', 'instrucciones' => 'Eres asesor.',
            'cadena' => [['proveedor' => 'deepseek', 'modelo' => 'deepseek-v4-pro']], 'limite_mensual' => 1000, 'max_pasos' => 4, 'activo' => true,
        ]);
    }

    public function test_el_agente_recibe_ruc_sucursales_y_datos_de_la_tienda(): void
    {
        // el adicional de la tienda lo activa la plataforma (no es fillable a propósito)
        $this->empresa->forceFill(['tienda_habilitada' => true])->save();
        $this->empresa->update(['nombre_comercial' => 'Agro Campo', 'tienda_slug' => 'agro', 'tienda_config' => [
            'whatsapp' => '987 654 321', 'direccion' => "Av. Principal 123, Huaral\nJr. Comercio 45, Chancay", 'horario' => 'Lunes a sábado 8 a. m. a 6 p. m.',
            'envios' => 'Enviamos a todo el Perú por Shalom.', 'pagos' => 'Yape, Plin y transferencia BCP.',
            'preguntas' => [['pregunta' => '¿Hacen factura?', 'respuesta' => 'Sí, boleta y factura electrónica.']],
        ]]);
        $this->sucursal->update(['direccion' => 'Av. Principal 123, Huaral', 'telefono' => '01 234 5678']);
        Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Chancay', 'direccion' => 'Jr. Comercio 45, Chancay', 'activo' => true]);
        Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0002', 'nombre' => 'Cerrada', 'direccion' => 'Calle Vieja 1', 'activo' => false]);

        $instrucciones = (new AgenteDeRubro($this->agente, $this->empresa->fresh(), $this->sucursal))->instructions();
        $this->assertStringContainsString("RUC {$this->empresa->ruc}", $instrucciones);
        $this->assertStringContainsString('Sucursal Principal: Av. Principal 123, Huaral · teléfono 01 234 5678', $instrucciones);
        $this->assertStringContainsString('Sucursal Chancay: Jr. Comercio 45, Chancay', $instrucciones);
        $this->assertStringNotContainsString('Calle Vieja', $instrucciones);
        $this->assertStringContainsString('WhatsApp: 987 654 321', $instrucciones);
        $this->assertStringContainsString('Horario de atención: Lunes a sábado 8 a. m. a 6 p. m.', $instrucciones);
        $this->assertStringContainsString('Envíos y entregas: Enviamos a todo el Perú por Shalom.', $instrucciones);
        $this->assertStringContainsString('Pregunta frecuente: ¿Hacen factura? → Sí, boleta y factura electrónica.', $instrucciones);
        $this->assertStringContainsString('nunca inventes direcciones, horarios ni condiciones', $instrucciones);

        // en la tienda pública además sabe su propia dirección web
        $publico = (new AgenteDeRubro($this->agente, $this->empresa->fresh(), null, 'tienda', ['nombre' => 'Dania']))->instructions();
        $this->assertStringContainsString('Te llamas "Dania"', $publico);
        $this->assertStringContainsString('Esta tienda en línea: http://agro.tienda.test', $publico);
        $this->assertStringContainsString('Máximo 3 frases cortas', $publico);
        $this->assertStringContainsString('No te presentes ni saludes', $publico);
        $this->assertStringContainsString('Si te agradecen, se despiden', $publico);
        // en la tienda, como mucho tres búsquedas y la respuesta
        $this->assertSame(4, (new AgenteDeRubro($this->agente->forceFill(['max_pasos' => 8]), $this->empresa->fresh(), null, 'tienda'))->maxSteps());
        $this->assertSame(8, (new AgenteDeRubro($this->agente, $this->empresa->fresh(), $this->sucursal))->maxSteps());
        $this->assertStringContainsString('Si te agradecen, se despiden', $instrucciones);
    }

    public function test_la_campanita_avisa_de_los_chats_de_la_tienda_sin_leer(): void
    {
        AgenteEmpresa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'activo' => true, 'activado_en' => now()]);
        $conversacion = ConversacionIa::create(['empresa_id' => $this->empresa->id, 'agente_id' => $this->agente->id, 'canal' => 'tienda', 'visitante' => 'visitante-prueba-01']);
        MensajeIa::create(['conversacion_id' => $conversacion->id, 'empresa_id' => $this->empresa->id, 'rol' => 'user', 'contenido' => '¿Tienen urea?']);

        $items = collect($this->actingAs($this->admin)->getJson('/notificaciones')->assertOk()->json('items'));
        $chat = $items->firstWhere('clave', 'chats_tienda');
        $this->assertSame(['Chats de la tienda', 1, '/asistente/tienda'], [$chat['titulo'] ?? null, $chat['cantidad'] ?? null, $chat['url'] ?? null]);

        // al abrirlo deja de contar; si el cliente vuelve a escribir, vuelve a avisar
        $this->actingAs($this->admin)->get("/asistente/tienda?c={$conversacion->id}")->assertOk();
        $this->assertNull(collect($this->actingAs($this->admin)->getJson('/notificaciones')->json('items'))->firstWhere('clave', 'chats_tienda'));

        $this->travel(1)->minutes();
        MensajeIa::create(['conversacion_id' => $conversacion->id, 'empresa_id' => $this->empresa->id, 'rol' => 'user', 'contenido' => '¿y el precio?']);
        $this->assertSame(1, collect($this->actingAs($this->admin)->getJson('/notificaciones')->json('items'))->firstWhere('clave', 'chats_tienda')['cantidad']);
    }
}
