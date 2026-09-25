<?php

use App\Http\Controllers\Admin\EmpresaController as AdminEmpresaController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RecuperacionPasswordController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\VerificacionCorreoController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\CuentaPorCobrarController;
use App\Http\Controllers\CuentaPorPagarController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\ImportacionProductoController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\SuscripcionController;
use App\Http\Controllers\TransferenciaController;
use App\Http\Controllers\UsuarioController;
use App\Http\Middleware\Superadmin;
use Illuminate\Support\Facades\Route;

// PDF de un comprobante para el cliente final (enlace firmado que se envia por WhatsApp)
Route::get('/c/{comprobante}', [ComprobanteController::class, 'publico'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('comprobantes.publico');

// pagina publica del producto (un usuario con sesion va directo a su dashboard)
Route::get('/', [LandingController::class, 'index'])->name('landing');

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
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');
    Route::get('/registro', [RegistroController::class, 'create'])->name('registro');
    Route::post('/registro', [RegistroController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/olvide-password', [RecuperacionPasswordController::class, 'solicitar'])->name('password.solicitar');
    Route::post('/olvide-password', [RecuperacionPasswordController::class, 'enviar'])->middleware('throttle:5,1');
    Route::get('/restablecer-password/{token}', [RecuperacionPasswordController::class, 'restablecer'])->name('password.restablecer');
    Route::post('/restablecer-password', [RecuperacionPasswordController::class, 'guardar'])->middleware('throttle:5,1');
});

// confirmacion del correo: el enlace firmado llega por correo (no exige sesion iniciada)
Route::get('/verificar-correo/{usuario}/{hash}', [VerificacionCorreoController::class, 'verificar'])
    ->middleware('throttle:10,1')
    ->name('verificacion.verificar');

// Cada ruta que muta datos lleva el permiso que exige (ver App\Support\Permisos).
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/verificar-correo', [VerificacionCorreoController::class, 'aviso'])->name('verificacion.aviso');
    Route::post('/verificar-correo/reenviar', [VerificacionCorreoController::class, 'reenviar'])->middleware('throttle:3,1')->name('verificacion.reenviar');

    Route::get('/dashboard', [InicioController::class, 'index'])->name('inicio');

    Route::post('/sucursal-activa', [SucursalController::class, 'cambiarActiva'])->name('sucursal.activa');
    Route::get('/notificaciones', [NotificacionController::class, 'index'])->name('notificaciones');

    // accesible aunque la suscripcion haya vencido (ver SuscripcionVigente)
    Route::get('/suscripcion', [SuscripcionController::class, 'index'])->name('suscripcion.index');

    // ---- panel de la plataforma (superadmin) ----
    Route::prefix('admin')->name('admin.')->middleware(Superadmin::class)->group(function () {
        Route::get('/', fn () => redirect()->route('admin.empresas.index'));
        Route::get('/empresas', [AdminEmpresaController::class, 'index'])->name('empresas.index');
        Route::get('/empresas/{empresa}', [AdminEmpresaController::class, 'show'])->name('empresas.show');
        Route::post('/empresas/{empresa}/plan', [AdminEmpresaController::class, 'activarPlan'])->name('empresas.plan');
        Route::post('/empresas/{empresa}/extender', [AdminEmpresaController::class, 'extender'])->name('empresas.extender');
        Route::post('/empresas/{empresa}/activo', [AdminEmpresaController::class, 'alternarActivo'])->name('empresas.activo');
        Route::get('/planes', [AdminPlanController::class, 'index'])->name('planes.index');
        Route::put('/planes/{plan}', [AdminPlanController::class, 'update'])->name('planes.update');
    });

    // ---- configuracion (solo admin) ----
    Route::middleware('can:empresa.gestionar')->group(function () {
        Route::get('/empresa', [EmpresaController::class, 'edit'])->name('empresa.edit');
        Route::put('/empresa', [EmpresaController::class, 'update'])->name('empresa.update');
        Route::post('/empresa/facturacion', [EmpresaController::class, 'alternarFacturacion'])->name('empresa.facturacion');
    });

    Route::get('/auditoria', [AuditoriaController::class, 'index'])->middleware('can:auditoria.ver')->name('auditoria.index');

    Route::middleware('can:reportes.ver')->group(function () {
        Route::get('/reportes', [ReporteController::class, 'index'])->name('reportes.index');
        Route::get('/reportes/exportar', [ReporteController::class, 'exportar'])->name('reportes.exportar');
    });

    Route::middleware('can:usuarios.gestionar')->group(function () {
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    });

    Route::middleware('can:sucursales.gestionar')->group(function () {
        Route::get('/sucursales', [SucursalController::class, 'index'])->name('sucursales.index');
        Route::post('/sucursales', [SucursalController::class, 'store'])->name('sucursales.store');
        Route::put('/sucursales/{sucursal}', [SucursalController::class, 'update'])->name('sucursales.update');
        Route::post('/sucursales/{sucursal}/cajas', [SucursalController::class, 'guardarCaja'])->name('sucursales.cajas');
        Route::post('/sucursales/{sucursal}/series', [SucursalController::class, 'guardarSerie'])->name('sucursales.series');
        Route::delete('/sucursales/{sucursal}/series/{serie}', [SucursalController::class, 'eliminarSerie'])->name('sucursales.series.eliminar');
    });

    // ---- productos y catalogos ----
    Route::get('/productos', [ProductoController::class, 'index'])->middleware('can:productos.ver')->name('productos.index');
    Route::post('/productos', [ProductoController::class, 'store'])->middleware('can:productos.gestionar')->name('productos.store');
    Route::middleware('can:productos.gestionar')->group(function () {
        Route::get('/productos/importar/plantilla', [ImportacionProductoController::class, 'plantilla'])->name('productos.importar.plantilla');
        Route::post('/productos/importar/previsualizar', [ImportacionProductoController::class, 'previsualizar'])->middleware('throttle:20,1')->name('productos.importar.previsualizar');
        Route::post('/productos/importar', [ImportacionProductoController::class, 'importar'])->name('productos.importar');
    });
    Route::put('/productos/{producto}', [ProductoController::class, 'update'])->middleware('can:productos.gestionar')->name('productos.update');
    Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])->middleware('can:productos.eliminar')->name('productos.destroy');

    Route::get('/catalogos', [CatalogoController::class, 'index'])->middleware('can:productos.ver')->name('catalogos.index');
    Route::middleware('can:catalogos.gestionar')->group(function () {
        Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
        Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');
        Route::post('/marcas', [MarcaController::class, 'store'])->name('marcas.store');
        Route::put('/marcas/{marca}', [MarcaController::class, 'update'])->name('marcas.update');
        Route::delete('/marcas/{marca}', [MarcaController::class, 'destroy'])->name('marcas.destroy');
    });

    // ---- POS y caja ----
    Route::middleware('can:pos.vender')->group(function () {
        Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
        Route::get('/pos/clientes', [PosController::class, 'clientes'])->name('pos.clientes');
        Route::post('/pos/clientes', [PosController::class, 'crearCliente'])->middleware('can:clientes.gestionar')->name('pos.clientes.crear');
        Route::post('/pos/ventas', [PosController::class, 'vender'])->name('pos.vender');
    });
    Route::get('/pos/productos/{producto}/historial', [PosController::class, 'historialProducto'])->middleware('can:stock.costos')->name('pos.historial');

    Route::middleware('can:caja.operar')->group(function () {
        Route::get('/caja', [CajaController::class, 'index'])->name('caja.index');
        Route::post('/caja/abrir', [CajaController::class, 'abrir'])->name('caja.abrir');
        Route::post('/caja/movimientos', [CajaController::class, 'movimiento'])->name('caja.movimiento');
        Route::post('/caja/cerrar', [CajaController::class, 'cerrar'])->name('caja.cerrar');
        Route::get('/caja/turnos/{apertura}/ticket', [CajaController::class, 'ticketCierre'])->name('caja.turnos.ticket');
    });

    // ---- comprobantes ----
    Route::middleware('can:comprobantes.ver')->group(function () {
        Route::get('/comprobantes', [ComprobanteController::class, 'index'])->name('comprobantes.index');
        Route::get('/comprobantes/{comprobante}/ticket', [ComprobanteController::class, 'ticket'])->name('comprobantes.ticket');
        Route::get('/comprobantes/{comprobante}/a4', [ComprobanteController::class, 'a4'])->name('comprobantes.a4');
        Route::get('/comprobantes/{comprobante}/xml', [ComprobanteController::class, 'xml'])->name('comprobantes.xml');
        Route::get('/comprobantes/{comprobante}/cdr', [ComprobanteController::class, 'cdr'])->name('comprobantes.cdr');
        Route::get('/comprobantes/{comprobante}/a5', [ComprobanteController::class, 'a5'])->name('comprobantes.a5');
        Route::get('/comprobantes/{comprobante}/estado-sunat', [ComprobanteController::class, 'estadoSunat'])->name('comprobantes.estado-sunat');
        Route::post('/comprobantes/{comprobante}/correo', [ComprobanteController::class, 'correo'])->middleware('throttle:30,1')->name('comprobantes.correo');
    });
    Route::post('/comprobantes/{comprobante}/sunat', [ComprobanteController::class, 'enviarSunat'])->middleware('can:comprobantes.sunat')->name('comprobantes.sunat');
    Route::post('/comprobantes/{comprobante}/reemitir', [ComprobanteController::class, 'reemitir'])->middleware('can:comprobantes.sunat')->name('comprobantes.reemitir');
    Route::post('/comprobantes/{comprobante}/convertir', [ComprobanteController::class, 'convertir'])->middleware('can:comprobantes.convertir')->name('comprobantes.convertir');
    Route::post('/comprobantes/{comprobante}/anular', [ComprobanteController::class, 'anular'])->middleware('can:comprobantes.anular')->name('comprobantes.anular');
    Route::post('/comprobantes/{comprobante}/nota-credito', [ComprobanteController::class, 'notaCredito'])->middleware('can:comprobantes.nota_credito')->name('comprobantes.nota-credito');

    // ---- compras y proveedores ----
    Route::get('/compras', [CompraController::class, 'index'])->middleware('can:compras.ver')->name('compras.index');
    Route::get('/compras/{compra}/pdf', [CompraController::class, 'pdf'])->middleware('can:compras.ver')->name('compras.pdf');
    Route::get('/compras/crear', [CompraController::class, 'crear'])->middleware('can:compras.gestionar')->name('compras.crear');
    Route::post('/compras', [CompraController::class, 'store'])->middleware('can:compras.gestionar')->name('compras.store');
    Route::post('/compras/{compra}/anular', [CompraController::class, 'anular'])->middleware('can:compras.anular')->name('compras.anular');

    Route::get('/proveedores', [ProveedorController::class, 'index'])->middleware('can:proveedores.ver')->name('proveedores.index');
    Route::get('/proveedores/buscar', [ProveedorController::class, 'buscar'])->middleware('can:proveedores.ver')->name('proveedores.buscar');
    Route::middleware('can:proveedores.gestionar')->group(function () {
        Route::post('/proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
        Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])->name('proveedores.update');
        Route::delete('/proveedores/{proveedor}', [ProveedorController::class, 'destroy'])->name('proveedores.destroy');
    });

    // ---- clientes y cuentas ----
    Route::get('/clientes', [ClienteController::class, 'index'])->middleware('can:clientes.ver')->name('clientes.index');
    Route::post('/clientes', [ClienteController::class, 'store'])->middleware('can:clientes.gestionar')->name('clientes.store');
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->middleware('can:clientes.gestionar')->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->middleware('can:clientes.eliminar')->name('clientes.destroy');

    Route::get('/cuentas-por-cobrar', [CuentaPorCobrarController::class, 'index'])->middleware('can:cuentas_cobrar.ver')->name('cuentas.index');
    Route::post('/cuentas-por-cobrar/{cuenta}/cobrar', [CuentaPorCobrarController::class, 'cobrar'])->middleware('can:cuentas_cobrar.cobrar')->name('cuentas.cobrar');

    Route::get('/cuentas-por-pagar', [CuentaPorPagarController::class, 'index'])->middleware('can:cuentas_pagar.ver')->name('cuentas-pagar.index');
    Route::post('/cuentas-por-pagar/{cuenta}/pagar', [CuentaPorPagarController::class, 'pagar'])->middleware('can:cuentas_pagar.pagar')->name('cuentas-pagar.pagar');

    // ---- stock y transferencias ----
    Route::get('/stock', [StockController::class, 'index'])->middleware('can:stock.ver')->name('stock.index');
    Route::get('/stock/{producto}/kardex', [StockController::class, 'kardex'])->middleware('can:stock.ver')->name('stock.kardex');
    Route::post('/stock/{producto}/ajustar', [StockController::class, 'ajustar'])->middleware('can:stock.ajustar')->name('stock.ajustar');

    Route::get('/transferencias', [TransferenciaController::class, 'index'])->middleware('can:transferencias.ver')->name('transferencias.index');
    Route::middleware('can:transferencias.gestionar')->group(function () {
        Route::get('/transferencias/crear', [TransferenciaController::class, 'crear'])->name('transferencias.crear');
        Route::post('/transferencias', [TransferenciaController::class, 'store'])->name('transferencias.store');
        Route::post('/transferencias/{transferencia}/recibir', [TransferenciaController::class, 'recibir'])->name('transferencias.recibir');
    });
    Route::post('/transferencias/{transferencia}/anular', [TransferenciaController::class, 'anular'])->middleware('can:transferencias.anular')->name('transferencias.anular');
});
