<?php

namespace Database\Seeders;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Marca;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\Usuario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $empresa = Empresa::firstOrCreate(
            ['ruc' => '20123456789'],
            [
                'razon_social' => 'Mi Negocio S.A.C.',
                'nombre_comercial' => 'Mi Negocio',
                'regimen_tributario' => 'RUS',
                'rubro_codigo' => 'minimarket',
                'activo' => true,
            ]
        );

        $sucursal = Sucursal::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Principal'],
            ['codigo_sunat' => '0000', 'activo' => true]
        );

        Caja::firstOrCreate(
            ['empresa_id' => $empresa->id, 'sucursal_id' => $sucursal->id, 'nombre' => 'Caja 1'],
            ['activo' => true]
        );

        foreach (['Abarrotes', 'Bebidas', 'Limpieza', 'Snacks', 'Lácteos'] as $categoria) {
            Categoria::firstOrCreate(['empresa_id' => $empresa->id, 'nombre' => $categoria]);
        }

        foreach (['Gloria', 'Coca-Cola', 'Laive'] as $marca) {
            Marca::firstOrCreate(['empresa_id' => $empresa->id, 'nombre' => $marca]);
        }

        Usuario::firstOrCreate(
            ['email' => 'admin@pos.test'],
            [
                'empresa_id' => $empresa->id,
                'sucursal_id' => $sucursal->id,
                'rol_id' => Rol::where('codigo', 'admin')->value('id'),
                'password_hash' => 'admin123',
                'nombre_completo' => 'Administrador',
                'activo' => true,
            ]
        );
    }
}
