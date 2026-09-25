<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Services\ImportacionProductosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Carga masiva de productos desde Excel (plantilla, vista previa, importación). */
class ImportacionProductoController extends Controller
{
    public function __construct(private readonly ImportacionProductosService $importacion) {}

    public function plantilla(): StreamedResponse
    {
        $libro = $this->importacion->plantilla();

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            'plantilla-productos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Sube el archivo y devuelve la evaluación fila por fila (no guarda nada). */
    public function previsualizar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ], [
            'archivo.required' => 'Elige el archivo de Excel.',
            'archivo.mimes' => 'El archivo debe ser .xlsx (usa la plantilla).',
            'archivo.max' => 'El archivo no debe pesar más de 5 MB.',
        ]);

        try {
            return response()->json($this->importacion->previsualizar($request->file('archivo')->getRealPath(), $request->user()));
        } catch (ErrorDeNegocio $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Importa las filas válidas de una vista previa. */
    public function importar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['token' => ['required', 'uuid']]);

        $sucursalId = $this->sucursalDeTrabajo($request);
        if (! $sucursalId) {
            return back()->with('error', 'No tienes una sucursal asignada para cargar el stock.');
        }

        try {
            $r = $this->importacion->importar($datos['token'], $request->user(), $sucursalId);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Importación lista: {$r['creados']} productos nuevos, {$r['actualizados']} actualizados"
            .($r['omitidos'] ? ", {$r['omitidos']} filas con errores omitidas" : '').'.');
    }
}
