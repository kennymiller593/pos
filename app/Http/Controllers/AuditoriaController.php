<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditoriaController extends Controller
{
    public const ACCIONES = [
        'comprobante.anulado' => 'Venta anulada',
        'venta.precio_modificado' => 'Venta con precio modificado',
        'producto.precio_actualizado' => 'Precio de producto cambiado',
        'usuario.creado' => 'Usuario creado',
        'usuario.actualizado' => 'Usuario actualizado',
        'caja.cierre_con_diferencia' => 'Cierre de caja con diferencia',
        'soporte.ingreso' => 'Soporte de inkaPos entró a la cuenta',
        'soporte.salida' => 'Soporte de inkaPos salió de la cuenta',
    ];

    private const ETIQUETAS_CAMPO = [
        'nombre_completo' => 'nombre',
        'email' => 'correo',
        'activo' => 'activo',
        'rol' => 'rol',
        'sucursales' => 'sucursales asignadas',
    ];

    public function index(Request $request): Response
    {
        return $this->listar($request, $request->user()->empresa_id);
    }

    /** Auditoria de una empresa vista desde el panel de la plataforma. */
    public function deEmpresa(Request $request, Empresa $empresa): Response
    {
        return $this->listar($request, $empresa->id, [
            'empresa' => ['id' => $empresa->id, 'razon_social' => $empresa->razon_social],
            'base' => "/admin/empresas/{$empresa->id}/auditoria",
        ]);
    }

    private function listar(Request $request, string $empresaId, array $extra = []): Response
    {
        $filtros = $request->only(['accion', 'usuario_id', 'desde', 'hasta']);

        $registros = Auditoria::query()
            ->where('empresa_id', $empresaId)
            ->with('usuario:id,nombre_completo')
            ->when($filtros['accion'] ?? null, fn ($q, $accion) => $q->where('accion', $accion))
            ->when($filtros['usuario_id'] ?? null, fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->whereDate('creado_en', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->whereDate('creado_en', '<=', $hasta))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($registro) => [
                'id' => $registro->id,
                'fecha' => $registro->creado_en,
                'usuario' => $registro->usuario?->nombre_completo ?? '—',
                'accion' => $registro->accion,
                'accion_label' => self::ACCIONES[$registro->accion] ?? $registro->accion,
                'descripcion' => $this->describir($registro).(isset($registro->detalle['_soporte']) ? " — hecho por soporte de inkaPos ({$registro->detalle['_soporte']})" : ''),
            ]);

        return Inertia::render('Auditoria/Index', [
            'registros' => $registros,
            'filtros' => $filtros,
            'acciones' => collect(self::ACCIONES)->map(fn ($label, $valor) => ['valor' => $valor, 'label' => $label])->values(),
            'usuarios' => Usuario::where('empresa_id', $empresaId)->orderBy('nombre_completo')->get(['id', 'nombre_completo']),
            ...$extra,
        ]);
    }

    private function describir(Auditoria $registro): string
    {
        $d = $registro->detalle ?? [];
        $soles = fn ($n) => 'S/ ' . number_format((float) $n, 2);

        return match ($registro->accion) {
            'comprobante.anulado' => sprintf(
                '%s por %s — motivo: %s',
                $d['comprobante'] ?? '?', $soles($d['total'] ?? 0), $d['motivo'] ?? 'sin motivo'
            ),
            'venta.precio_modificado' => ($d['comprobante'] ?? '?') . ': ' . collect($d['lineas'] ?? [])
                ->map(fn ($l) => sprintf('%s de %s a %s', $l['producto'], $soles($l['precio_lista']), $soles($l['precio_cobrado'])))
                ->implode('; '),
            'producto.precio_actualizado' => ($d['producto'] ?? '?') . ': ' . collect($d['cambios'] ?? [])
                ->map(fn ($c) => sprintf('%s de %s a %s', $c['presentacion'], $soles($c['de']), $soles($c['a'])))
                ->implode('; '),
            'usuario.creado' => sprintf(
                '%s (%s) con rol %s', $d['nombre'] ?? '?', $d['email'] ?? '?', $d['rol'] ?? '?'
            ),
            'usuario.actualizado' => ($d['usuario'] ?? '?') . ': ' . collect($d['cambios'] ?? [])
                ->map(function ($cambio, $campo) {
                    $etiqueta = self::ETIQUETAS_CAMPO[$campo] ?? $campo;
                    if (! is_array($cambio)) {
                        return "{$etiqueta} {$cambio}";
                    }
                    $mostrar = fn ($v) => is_bool($v) ? ($v ? 'sí' : 'no') : $v;

                    return sprintf('%s de "%s" a "%s"', $etiqueta, $mostrar($cambio['de']), $mostrar($cambio['a']));
                })
                ->implode('; '),
            'caja.cierre_con_diferencia' => sprintf(
                '%s: esperado %s, contado %s (%s %s)',
                $d['caja'] ?? 'Caja',
                $soles($d['esperado'] ?? 0),
                $soles($d['contado'] ?? 0),
                ($d['diferencia'] ?? 0) > 0 ? 'sobran' : 'faltan',
                $soles(abs($d['diferencia'] ?? 0)),
            ),
            'soporte.ingreso' => sprintf('%s entró como %s para dar soporte', $d['soporte'] ?? 'Soporte', $d['como'] ?? '?'),
            'soporte.salida' => sprintf('%s salió de la cuenta de %s', $d['soporte'] ?? 'Soporte', $d['como'] ?? '?'),
            default => json_encode(array_diff_key($d, ['_soporte' => 1]), JSON_UNESCAPED_UNICODE) ?: '',
        };
    }
}
