<?php

namespace App\Support;

/**
 * Matriz de permisos por rol. Los roles viven en la tabla `roles` (admin,
 * cajero, vendedor, almacenero); qué puede hacer cada uno se define aquí y
 * se aplica con Gates (`can:` en rutas, `$usuario->can()` en código) y en el
 * frontend con la lista compartida en `auth.user.permisos`.
 */
final class Permisos
{
    /** Descripción de cada permiso, para mostrarla al asignar roles. */
    public const DESCRIPCIONES = [
        'pos.vender' => 'Vender en el POS',
        'pos.precio_manual' => 'Cambiar el precio de lista en una venta',
        'caja.operar' => 'Abrir y cerrar su caja, registrar ingresos',
        'caja.egresos' => 'Registrar egresos de caja',
        'caja.ver_todas' => 'Ver los turnos de caja de todos los usuarios',
        'comprobantes.ver' => 'Ver e imprimir comprobantes',
        'comprobantes.sunat' => 'Reenviar comprobantes a SUNAT',
        'comprobantes.anular' => 'Anular comprobantes',
        'comprobantes.nota_credito' => 'Emitir notas de crédito',
        'clientes.ver' => 'Ver clientes',
        'clientes.gestionar' => 'Crear y editar clientes',
        'clientes.credito' => 'Asignar línea de crédito a clientes',
        'clientes.eliminar' => 'Eliminar clientes',
        'cuentas_cobrar.ver' => 'Ver cuentas por cobrar',
        'cuentas_cobrar.cobrar' => 'Registrar cobros de clientes',
        'productos.ver' => 'Ver productos',
        'productos.gestionar' => 'Crear y editar productos',
        'productos.precios' => 'Cambiar precios de venta',
        'productos.eliminar' => 'Eliminar productos',
        'catalogos.gestionar' => 'Gestionar categorías y marcas',
        'stock.ver' => 'Ver stock',
        'stock.costos' => 'Ver costos y valor del inventario',
        'stock.ajustar' => 'Registrar entradas y salidas de stock',
        'compras.ver' => 'Ver compras',
        'compras.gestionar' => 'Registrar compras',
        'cuentas_pagar.ver' => 'Ver deudas con proveedores',
        'cuentas_pagar.pagar' => 'Pagar a proveedores',
        'proveedores.ver' => 'Ver proveedores',
        'proveedores.gestionar' => 'Crear y editar proveedores',
        'transferencias.ver' => 'Ver transferencias entre sucursales',
        'transferencias.gestionar' => 'Enviar y recibir transferencias',
        'transferencias.anular' => 'Anular transferencias',
        'dashboard.finanzas' => 'Ver margen, ventas del mes y deudas en el dashboard',
        'reportes.ver' => 'Ver reportes',
        'auditoria.ver' => 'Ver la auditoría',
        'usuarios.gestionar' => 'Gestionar usuarios',
        'sucursales.gestionar' => 'Gestionar sucursales, cajas y series',
        'empresa.gestionar' => 'Configurar la empresa y la facturación',
    ];

    /** '*' = todos los permisos. */
    private const POR_ROL = [
        'admin' => '*',
        'cajero' => [
            'pos.vender', 'pos.precio_manual',
            'caja.operar', 'caja.egresos',
            'comprobantes.ver', 'comprobantes.sunat',
            'clientes.ver', 'clientes.gestionar',
            'cuentas_cobrar.ver', 'cuentas_cobrar.cobrar',
            'cuentas_pagar.ver', 'cuentas_pagar.pagar',
            'productos.ver', 'stock.ver',
        ],
        'vendedor' => [
            'pos.vender',
            'caja.operar',
            'comprobantes.ver',
            'clientes.ver', 'clientes.gestionar',
            'cuentas_cobrar.ver',
            'productos.ver', 'stock.ver',
        ],
        'almacenero' => [
            'productos.ver', 'productos.gestionar',
            'catalogos.gestionar',
            'stock.ver', 'stock.costos', 'stock.ajustar',
            'compras.ver', 'compras.gestionar',
            'cuentas_pagar.ver',
            'proveedores.ver', 'proveedores.gestionar',
            'transferencias.ver', 'transferencias.gestionar',
        ],
    ];

    /** @return list<string> */
    public static function deRol(?string $rol): array
    {
        $permisos = self::POR_ROL[$rol] ?? [];

        return $permisos === '*' ? array_keys(self::DESCRIPCIONES) : $permisos;
    }

    public static function tiene(?string $rol, string $permiso): bool
    {
        return in_array($permiso, self::deRol($rol), true);
    }

    /** Permisos de cada rol con su descripción, para la pantalla de usuarios. */
    public static function resumenPorRol(): array
    {
        return collect(array_keys(self::POR_ROL))
            ->mapWithKeys(fn ($rol) => [$rol => array_map(
                fn ($p) => self::DESCRIPCIONES[$p],
                self::deRol($rol),
            )])
            ->all();
    }
}
