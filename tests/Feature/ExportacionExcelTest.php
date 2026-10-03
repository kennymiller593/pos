<?php

namespace Tests\Feature;

use Tests\Concerns\CreaEscenarioPos;
use Tests\Concerns\LeeExcel;
use Tests\TestCase;

class ExportacionExcelTest extends TestCase
{
    use CreaEscenarioPos;
    use LeeExcel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_productos_exporta_la_lista_filtrada_con_sus_presentaciones(): void
    {
        $a = $this->crearProducto(precio: 12.5, atributos: ['nombre' => '=Abono peligroso']); // empieza con "=": no debe volverse formula
        $this->agregarPresentacion($a, 'Caja x12', 12, 140);
        $this->darStock($a, 30, 5);
        $b = $this->crearProducto(precio: 7, atributos: ['nombre' => 'Zapallo', 'activo' => false]);

        $libro = $this->abrirExcel($this->actingAs($this->admin)->get('/productos/exportar?estado=activo'));
        $filas = $this->filasDe($libro, 0);

        $this->assertSame('Productos', $filas[0][0]);
        $this->assertSame(['Código', 'Producto', 'Marca', 'Categoría', 'Unidad base', 'Presentación principal', 'Precio venta', 'Precio mayorista', 'Código de barras', 'Stock', 'Stock mínimo', 'Controla stock', 'Estado'], $filas[2]);
        $this->assertCount(4, $filas); // titulo, subtitulo, encabezado y un solo producto (el inactivo quedo fuera)
        $this->assertSame('=Abono peligroso', $filas[3][1]);
        $this->assertSame('Unidad', $filas[3][5]);
        $this->assertSame(12.5, $filas[3][6]);
        $this->assertSame(30.0, $filas[3][9]);
        $this->assertSame('Sí', $filas[3][11]);
        $this->assertSame('Activo', $filas[3][12]);

        $presentaciones = $this->filasDe($libro, 1);
        $this->assertSame('Presentaciones', $libro->getSheet(1)->getTitle());
        $this->assertSame(['Caja x12', 12.0, 140.0], [$presentaciones[4][2], $presentaciones[4][4], $presentaciones[4][5]]);

        // sin filtro entran los dos
        $this->assertCount(5, $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/productos/exportar')), 0));
        $this->assertSame('Zapallo', $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/productos/exportar?orden=producto&dir=desc')), 0)[3][1]);
    }

    public function test_comprobantes_exporta_la_lista_filtrada_sin_paginar(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $this->darStock($producto, 100, 4);
        $this->abrirCaja();
        foreach ([1, 2, 3] as $cantidad) {
            $this->venderContado($producto->presentaciones->first(), $cantidad)->assertSessionHas('success');
        }

        $libro = $this->abrirExcel($this->actingAs($this->admin)->get('/comprobantes/exportar?orden=total&dir=asc'));
        $filas = $this->filasDe($libro);

        $this->assertSame('Número', $filas[2][0]);
        $this->assertSame(['NV01-000001', 'Nota de venta'], [$filas[3][0], $filas[3][1]]);
        $this->assertSame([10.0, 20.0, 30.0], [$filas[3][11], $filas[4][11], $filas[5][11]]);
        $this->assertSame('No aplica', $filas[3][14]);
        $this->assertSame('Contado', $filas[3][12]);
        // resumen al pie
        $this->assertSame(['Comprobantes emitidos', 3], [$filas[6][0], $filas[6][1]]);
        $this->assertSame(60.0, $filas[7][1]);

        // con filtro de busqueda solo sale el que coincide
        $this->assertCount(6, $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/comprobantes/exportar?buscar=2'))));
    }

    public function test_otra_empresa_no_ve_nada_y_el_vendedor_puede_exportar(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(10000, 99999).'@test.local');
        $this->assertCount(4, $this->filasDe($this->abrirExcel($this->actingAs($vendedor)->get('/productos/exportar'))));

        $this->crearEscenarioBase();
        $this->assertCount(3, $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/productos/exportar'))));
        $this->assertNotContains($producto->nombre, array_map(fn ($f) => $f[1] ?? null, $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/productos/exportar')))));
    }
}
