<?php

namespace Tests\Concerns;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CapaCosto;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use Illuminate\Testing\TestResponse;

/**
 * Crea una empresa aislada con sucursal, caja y usuario admin para cada test.
 * Todo se revierte al terminar gracias a DatabaseTransactions.
 */
trait CreaEscenarioPos
{
    protected Empresa $empresa;

    protected Sucursal $sucursal;

    protected Caja $caja;

    protected Usuario $admin;

    protected function crearEscenarioBase(): void
    {
        $sufijo = (string) random_int(10000000, 99999999);

        $this->empresa = Empresa::create([
            'ruc' => "20{$sufijo}7",
            'razon_social' => "Empresa Test {$sufijo}",
            'regimen_tributario' => 'MYPE',
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'activo' => true,
        ]);

        $this->sucursal = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0000',
            'nombre' => 'Principal',
            'activo' => true,
        ]);

        $this->caja = Caja::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'nombre' => 'Caja 1',
            'activo' => true,
        ]);

        // sin suscripcion vigente el middleware bloquea todo: cada escenario arranca en prueba
        app(SuscripcionService::class)->iniciarPrueba($this->empresa);

        $this->admin = $this->crearUsuario('admin', "admin{$sufijo}@test.local");
    }

    protected function crearUsuario(string $rolCodigo, string $email): Usuario
    {
        return Usuario::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'rol_id' => Rol::where('codigo', $rolCodigo)->value('id'),
            'email' => $email,
            'password_hash' => 'secreto123',
            'nombre_completo' => ucfirst($rolCodigo).' Test',
            'activo' => true,
            'email_verificado_en' => now(),
        ]);
    }

    /** Producto gravado con IGV, con una presentacion "Unidad" (factor 1). */
    protected function crearProducto(float $precio = 10.0, array $atributos = []): Producto
    {
        $producto = Producto::create([
            'empresa_id' => $this->empresa->id,
            'codigo_interno' => 'T-'.random_int(100000, 999999),
            'nombre' => 'Producto Test '.random_int(1000, 9999),
            'unidad_base_codigo' => UnidadMedida::query()->value('codigo'),
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            ...$atributos,
        ]);

        $this->agregarPresentacion($producto, 'Unidad', 1, $precio, esDefault: true);

        return $producto->load('presentaciones');
    }

    protected function agregarPresentacion(Producto $producto, string $nombre, float $factor, float $precio, bool $esDefault = false): ProductoPresentacion
    {
        return $producto->presentaciones()->create([
            'empresa_id' => $this->empresa->id,
            'nombre' => $nombre,
            'unidad_codigo' => $producto->unidad_base_codigo,
            'factor_conversion' => $factor,
            'precio_venta' => $precio,
            'es_default' => $esDefault,
            'activo' => true,
        ]);
    }

    /** Crea una capa de costo y suma el stock correspondiente. */
    protected function darStock(Producto $producto, float $cantidad, float $costo, int $diasAtras = 0): CapaCosto
    {
        Stock::firstOrCreate(
            ['producto_id' => $producto->id, 'sucursal_id' => $this->sucursal->id],
            ['empresa_id' => $this->empresa->id, 'cantidad' => 0]
        )->increment('cantidad', $cantidad);

        return CapaCosto::create([
            'empresa_id' => $this->empresa->id,
            'producto_id' => $producto->id,
            'sucursal_id' => $this->sucursal->id,
            'cantidad_inicial' => $cantidad,
            'cantidad_restante' => $cantidad,
            'costo_unitario' => $costo,
            'fecha_ingreso' => now()->subDays($diasAtras),
        ]);
    }

    protected function stockDe(Producto $producto): float
    {
        return (float) Stock::where('producto_id', $producto->id)
            ->where('sucursal_id', $this->sucursal->id)
            ->value('cantidad');
    }

    protected function abrirCaja(float $montoInicial = 100): AperturaCaja
    {
        return AperturaCaja::create([
            'empresa_id' => $this->empresa->id,
            'caja_id' => $this->caja->id,
            'usuario_id' => $this->admin->id,
            'monto_inicial' => $montoInicial,
        ]);
    }

    protected function crearCliente(float $limiteCredito = 0): Cliente
    {
        return Cliente::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento_codigo' => '1',
            'numero_documento' => (string) random_int(10000000, 99999999),
            'nombre' => 'Cliente Test '.random_int(1000, 9999),
            'limite_credito' => $limiteCredito,
        ]);
    }

    /** Registra una venta al contado en efectivo por el total exacto. */
    protected function venderContado(ProductoPresentacion $presentacion, float $cantidad): TestResponse
    {
        $total = round($cantidad * (float) $presentacion->precio_venta, 2);

        return $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => $cantidad]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => $total, 'referencia' => null]],
        ]);
    }
}
