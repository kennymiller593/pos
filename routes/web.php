<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RecuperacionPasswordController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CuentaPorCobrarController;
use App\Http\Controllers\CuentaPorPagarController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\TransferenciaController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// consulta RUC en SUNAT: disponible tambien para invitados (la usa el registro)
Route::get('/consultas/ruc/{numero}', [ConsultaController::class, 'ruc'])
    ->where('numero', '\d{11}')
    ->middleware('throttle:20,1')
    ->name('consultas.ruc');

// consulta DNI en RENIEC: solo usuarios autenticados
Route::get('/consultas/dni/{numero}', [ConsultaController::class, 'dni'])
    ->where('numero', '\d{8}')
    ->middleware(['auth', 'throttle:20,1'])
    ->name('consultas.dni');

// autocompletado de ubigeos (catalogo local, sin API externa)
Route::get('/consultas/ubigeos', [ConsultaController::class, 'ubigeos'])
    ->middleware('auth')
    ->name('consultas.ubigeos');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/registro', [RegistroController::class, 'create'])->name('registro');
    Route::post('/registro', [RegistroController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/olvide-password', [RecuperacionPasswordController::class, 'solicitar'])->name('password.solicitar');
    Route::post('/olvide-password', [RecuperacionPasswordController::class, 'enviar'])->middleware('throttle:5,1');
    Route::get('/restablecer-password/{token}', [RecuperacionPasswordController::class, 'restablecer'])->name('password.restablecer');
    Route::post('/restablecer-password', [RecuperacionPasswordController::class, 'guardar'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/', [InicioController::class, 'index'])->name('inicio');

    Route::post('/sucursal-activa', [SucursalController::class, 'cambiarActiva'])->name('sucursal.activa');
    Route::get('/notificaciones', [NotificacionController::class, 'index'])->name('notificaciones');

    Route::get('/empresa', [EmpresaController::class, 'edit'])->name('empresa.edit');
    Route::put('/empresa', [EmpresaController::class, 'update'])->name('empresa.update');
    Route::post('/empresa/facturacion', [EmpresaController::class, 'alternarFacturacion'])->name('empresa.facturacion');

    Route::middleware('admin')->group(function () {
        Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');

        Route::get('/reportes', [ReporteController::class, 'index'])->name('reportes.index');
        Route::get('/reportes/exportar', [ReporteController::class, 'exportar'])->name('reportes.exportar');

        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');

        Route::get('/sucursales', [SucursalController::class, 'index'])->name('sucursales.index');
        Route::post('/sucursales', [SucursalController::class, 'store'])->name('sucursales.store');
        Route::put('/sucursales/{sucursal}', [SucursalController::class, 'update'])->name('sucursales.update');
        Route::post('/sucursales/{sucursal}/cajas', [SucursalController::class, 'guardarCaja'])->name('sucursales.cajas');
        Route::post('/sucursales/{sucursal}/series', [SucursalController::class, 'guardarSerie'])->name('sucursales.series');
        Route::delete('/sucursales/{sucursal}/series/{serie}', [SucursalController::class, 'eliminarSerie'])->name('sucursales.series.eliminar');
    });

    Route::get('/productos', [ProductoController::class, 'index'])->name('productos.index');
    Route::post('/productos', [ProductoController::class, 'store'])->name('productos.store');
    Route::put('/productos/{producto}', [ProductoController::class, 'update'])->name('productos.update');
    Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])->name('productos.destroy');

    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/clientes', [PosController::class, 'clientes'])->name('pos.clientes');
    Route::post('/pos/clientes', [PosController::class, 'crearCliente'])->name('pos.clientes.crear');
    Route::get('/pos/productos/{producto}/historial', [PosController::class, 'historialProducto'])->name('pos.historial');
    Route::post('/pos/ventas', [PosController::class, 'vender'])->name('pos.vender');

    Route::get('/caja', [CajaController::class, 'index'])->name('caja.index');
    Route::post('/caja/abrir', [CajaController::class, 'abrir'])->name('caja.abrir');
    Route::post('/caja/movimientos', [CajaController::class, 'movimiento'])->name('caja.movimiento');
    Route::post('/caja/cerrar', [CajaController::class, 'cerrar'])->name('caja.cerrar');

    Route::get('/comprobantes', [ComprobanteController::class, 'index'])->name('comprobantes.index');
    Route::get('/comprobantes/{comprobante}/ticket', [ComprobanteController::class, 'ticket'])->name('comprobantes.ticket');
    Route::get('/comprobantes/{comprobante}/a4', [ComprobanteController::class, 'a4'])->name('comprobantes.a4');
    Route::post('/comprobantes/{comprobante}/anular', [ComprobanteController::class, 'anular'])->name('comprobantes.anular');
    Route::post('/comprobantes/{comprobante}/sunat', [ComprobanteController::class, 'enviarSunat'])->name('comprobantes.sunat');
    Route::get('/comprobantes/{comprobante}/xml', [ComprobanteController::class, 'xml'])->name('comprobantes.xml');
    Route::get('/comprobantes/{comprobante}/cdr', [ComprobanteController::class, 'cdr'])->name('comprobantes.cdr');
    Route::post('/comprobantes/{comprobante}/nota-credito', [ComprobanteController::class, 'notaCredito'])->name('comprobantes.nota-credito');

    Route::get('/compras', [CompraController::class, 'index'])->name('compras.index');
    Route::get('/compras/crear', [CompraController::class, 'crear'])->name('compras.crear');
    Route::post('/compras', [CompraController::class, 'store'])->name('compras.store');
    Route::get('/compras/{compra}/pdf', [CompraController::class, 'pdf'])->name('compras.pdf');
    Route::get('/proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
    Route::get('/proveedores/buscar', [ProveedorController::class, 'buscar'])->name('proveedores.buscar');
    Route::post('/proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
    Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])->name('proveedores.update');
    Route::delete('/proveedores/{proveedor}', [ProveedorController::class, 'destroy'])->name('proveedores.destroy');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');

    Route::get('/cuentas-por-cobrar', [CuentaPorCobrarController::class, 'index'])->name('cuentas.index');
    Route::post('/cuentas-por-cobrar/{cuenta}/cobrar', [CuentaPorCobrarController::class, 'cobrar'])->name('cuentas.cobrar');

    Route::get('/cuentas-por-pagar', [CuentaPorPagarController::class, 'index'])->name('cuentas-pagar.index');
    Route::post('/cuentas-por-pagar/{cuenta}/pagar', [CuentaPorPagarController::class, 'pagar'])->name('cuentas-pagar.pagar');

    Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('/stock/{producto}/kardex', [StockController::class, 'kardex'])->name('stock.kardex');
    Route::post('/stock/{producto}/ajustar', [StockController::class, 'ajustar'])->name('stock.ajustar');

    Route::get('/transferencias', [TransferenciaController::class, 'index'])->name('transferencias.index');
    Route::get('/transferencias/crear', [TransferenciaController::class, 'crear'])->name('transferencias.crear');
    Route::post('/transferencias', [TransferenciaController::class, 'store'])->name('transferencias.store');
    Route::post('/transferencias/{transferencia}/recibir', [TransferenciaController::class, 'recibir'])->name('transferencias.recibir');
    Route::post('/transferencias/{transferencia}/anular', [TransferenciaController::class, 'anular'])->name('transferencias.anular');

    Route::get('/catalogos', [CatalogoController::class, 'index'])->name('catalogos.index');
    Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
    Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
    Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');
    Route::post('/marcas', [MarcaController::class, 'store'])->name('marcas.store');
    Route::put('/marcas/{marca}', [MarcaController::class, 'update'])->name('marcas.update');
    Route::delete('/marcas/{marca}', [MarcaController::class, 'destroy'])->name('marcas.destroy');
});
