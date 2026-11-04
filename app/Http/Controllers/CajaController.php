<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\AperturaCaja;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Services\CajaService;
use App\Services\MovimientosCajaService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CajaController extends Controller
{
    public function __construct(private readonly CajaService $caja, private readonly MovimientosCajaService $movimientos) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        $cajas = Caja::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->when($usuario->sucursalesPermitidas(), fn ($q, $ids) => $q->whereIn('sucursal_id', $ids))
            ->where('activo', true)
            ->with(['aperturaAbierta.usuario:id,nombre_completo', 'sucursal:id,nombre'])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'sucursal_id']);

        $apertura = $this->caja->aperturaDe($usuario);
        $hoy = now()->toDateString();

        return Inertia::render('Caja/Index', [
            'cajas' => $cajas->map(fn ($caja) => [
                'id' => $caja->id,
                'nombre' => $caja->nombre,
                'sucursal' => $caja->sucursal?->nombre,
                'ocupada_por' => $caja->aperturaAbierta?->usuario?->nombre_completo,
            ]),
            'apertura' => $apertura ? [
                'id' => $apertura->id,
                'caja' => $apertura->caja->nombre
                    .($apertura->caja->loadMissing('sucursal:id,nombre')->sucursal ? " · {$apertura->caja->sucursal->nombre}" : ''),
                'abierta_en' => $apertura->abierta_en,
                'monto_inicial' => (float) $apertura->monto_inicial,
                'resumen' => $this->caja->resumen($apertura),
                // sin el medio interno 'adelanto' (al entregar un pedido): ese dinero ya se contó el día que lo dejaron
                'medios' => array_values(array_filter($this->caja->resumenPorMedio($apertura), fn ($m) => ! in_array($m['codigo'], MedioPago::INTERNOS, true))),
                // todo lo que entró y salió en el turno, fila por fila y con su medio (misma lista que /caja/movimientos)
                'movimientos' => $this->movimientos->listar($usuario, null, $apertura, $hoy, $hoy),
            ] : null,
            'denominaciones' => CajaService::DENOMINACIONES,
            // para decir por dónde entró o salió el dinero de un ingreso/egreso (Yape, Plin, transferencia...)
            'mediosPago' => MedioPago::elegibles()->orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
        ]);
    }

    /** Historial de turnos cerrados: Entró · Salió · Saldo · Efectivo al cerrar · Estado, con la Suma del rango. */
    public function historial(Request $request): Response
    {
        $usuario = $request->user();
        $veTodas = $usuario->can('caja.ver_todas');

        // rango de fechas del historial (por día de apertura del turno)
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;

        // turnos cerrados: quien puede ver todas las cajas ve todos; los demas solo los suyos
        $turnos = AperturaCaja::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->whereNotNull('cerrada_en')
            ->when(! $veTodas, fn ($q) => $q->where('usuario_id', $usuario->id))
            // la sucursal elegida en el selector (null = todas las que puede ver)
            ->when($this->sucursalesConsulta($request), fn ($q, $ids) => $q->whereHas('caja', fn ($c) => $c->whereIn('sucursal_id', $ids)))
            ->when($desde, fn ($q, $d) => $q->whereDate('abierta_en', '>=', $d))
            ->when($hasta, fn ($q, $h) => $q->whereDate('abierta_en', '<=', $h));

        $suma = $this->sumaHistorial((clone $turnos)->select('id'));

        $historial = (clone $turnos)
            ->with(['caja:id,nombre,sucursal_id', 'caja.sucursal:id,nombre', 'usuario:id,nombre_completo', 'cierresMedios.medioPago:codigo,nombre'])
            ->withSum(['movimientos as ingresos' => fn ($q) => $q->where('tipo', 'ingreso')->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['movimientos as egresos' => fn ($q) => $q->where('tipo', 'egreso')->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['pagos as ventas_efectivo' => fn ($q) => $q->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['cobros as cobros_efectivo' => fn ($q) => $q->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['pagosProveedor as pagos_proveedor_efectivo' => fn ($q) => $q->where('medio_pago_codigo', 'efectivo')], 'monto')
            // entró / salió por todos los medios (sin el adelanto interno: ese dinero ya entró el día que lo dejaron)
            ->withSum(['pagos as entro_ventas' => fn ($q) => $q->whereNotIn('medio_pago_codigo', MedioPago::INTERNOS)], 'monto')
            ->withSum('cobros as entro_cobros', 'monto')
            ->withSum(['movimientos as entro_ingresos' => fn ($q) => $q->where('tipo', 'ingreso')], 'monto')
            ->withSum(['movimientos as salio_egresos' => fn ($q) => $q->where('tipo', 'egreso')], 'monto')
            ->withSum('pagosProveedor as salio_proveedores', 'monto')
            ->latest('cerrada_en')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($turno) => [
                'id' => $turno->id,
                'caja' => $turno->caja
                    ? $turno->caja->nombre.($turno->caja->sucursal ? " · {$turno->caja->sucursal->nombre}" : '')
                    : null,
                'usuario' => $turno->usuario?->nombre_completo,
                'abierta_en' => $turno->abierta_en,
                'cerrada_en' => $turno->cerrada_en,
                'monto_inicial' => (float) $turno->monto_inicial,
                'ingresos' => (float) ($turno->ingresos ?? 0),
                'egresos' => round((float) ($turno->egresos ?? 0) + (float) ($turno->pagos_proveedor_efectivo ?? 0), 2),
                'ventas_efectivo' => round((float) ($turno->ventas_efectivo ?? 0) + (float) ($turno->cobros_efectivo ?? 0), 2),
                'monto_sistema' => (float) $turno->monto_sistema,
                'monto_cierre' => (float) $turno->monto_cierre,
                'diferencia' => round((float) $turno->monto_cierre - (float) $turno->monto_sistema, 2),
                // cuadre por medio (turnos cerrados antes de esta funcion no lo tienen)
                'medios' => $turno->cierresMedios->map(fn ($m) => [
                    'codigo' => $m->medio_pago_codigo,
                    'nombre' => $m->medioPago?->nombre ?? $m->medio_pago_codigo,
                    'esperado' => (float) $m->esperado,
                    'declarado' => $m->declarado !== null ? (float) $m->declarado : null,
                    'diferencia' => (float) $m->diferencia,
                ])->values(),
                'diferencia_total' => round((float) $turno->cierresMedios->sum('diferencia') ?: ((float) $turno->monto_cierre - (float) $turno->monto_sistema), 2),
                'observaciones' => $turno->observaciones,
                // lo que dejó el turno por todos los medios (efectivo, Yape, transferencia...), sin el monto inicial
                'total_turno' => $this->totalTurno($turno),
                // lo que entró y salió por todos los medios, y el saldo del turno (entró − salió), como la lista de movimientos
                'entro' => $entro = round((float) ($turno->entro_ventas ?? 0) + (float) ($turno->entro_cobros ?? 0) + (float) ($turno->entro_ingresos ?? 0), 2),
                'salio' => $salio = round((float) ($turno->salio_egresos ?? 0) + (float) ($turno->salio_proveedores ?? 0), 2),
                'saldo' => round($entro - $salio, 2),
                // cada ingreso y egreso del turno (ventas sueltas, taxi, compras pagadas con caja...) para revisarlo después de cerrado
                'movimientos' => $this->movimientosDelTurno($turno, null),
            ]);

        return Inertia::render('Caja/Historial', [
            'historial' => $historial,
            'suma' => $suma,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta],
            'cajaAbierta' => ($abierta = $this->caja->aperturaDe($usuario)) ? ['id' => $abierta->id, 'caja' => $abierta->caja?->nombre] : null,
        ]);
    }

    /**
     * Todo lo que salió o entró a mano en el turno: los movimientos manuales y también los pagos a proveedor
     * (deudas pagadas y compras al contado descontadas de caja), que antes solo se veían en el total.
     */
    private function movimientosDelTurno(AperturaCaja $apertura, ?int $limite = 50): array
    {
        $manuales = $apertura->movimientos()
            ->with('usuario:id,nombre_completo')
            ->get(['id', 'tipo', 'concepto', 'monto', 'medio_pago_codigo', 'referencia', 'usuario_id', 'creado_en'])
            ->map(fn ($m) => [
                'id' => $m->id, 'tipo' => $m->tipo, 'origen' => 'manual', 'concepto' => $m->concepto, 'monto' => (float) $m->monto,
                'medio_pago_codigo' => $m->medio_pago_codigo, 'referencia' => $m->referencia, 'usuario' => $m->usuario?->only(['id', 'nombre_completo']), 'creado_en' => $m->creado_en,
            ]);

        $pagos = $apertura->pagosProveedor()
            ->with(['usuario:id,nombre_completo', 'cuenta.proveedor:id,razon_social', 'compra:id,proveedor_id,serie_numero', 'compra.proveedor' => fn ($q) => $q->withTrashed()->select(['id', 'razon_social'])])
            ->get()
            ->map(function ($p) {
                $proveedor = $p->compra_id ? $p->compra?->proveedor?->razon_social : $p->cuenta?->proveedor?->razon_social;
                $concepto = $p->compra_id
                    ? 'Compra'.($p->compra?->serie_numero ? " {$p->compra->serie_numero}" : '').($proveedor ? " · {$proveedor}" : '')
                    : 'Pago a proveedor'.($proveedor ? ": {$proveedor}" : '');

                return [
                    'id' => $p->id, 'tipo' => 'egreso', 'origen' => $p->compra_id ? 'compra' : 'cuenta_por_pagar', 'concepto' => $concepto, 'monto' => (float) $p->monto,
                    'medio_pago_codigo' => $p->medio_pago_codigo, 'referencia' => $p->referencia, 'usuario' => $p->usuario?->only(['id', 'nombre_completo']), 'creado_en' => $p->creado_en,
                ];
            });

        $todos = $manuales->concat($pagos)->sortByDesc('creado_en');

        return ($limite ? $todos->take($limite) : $todos)->values()->all();
    }

    public function abrir(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        $datos = $request->validate([
            'caja_id' => ['required', 'uuid'],
            'monto_inicial' => ['required', 'numeric', 'min:0'],
        ], [
            'caja_id.required' => 'Elige una caja.',
            'monto_inicial.required' => 'Ingresa el monto inicial.',
            'monto_inicial.min' => 'El monto no puede ser negativo.',
        ]);

        if ($this->caja->aperturaDe($usuario)) {
            return back()->with('error', 'Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
        }

        $caja = Caja::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->where('activo', true)
            ->find($datos['caja_id']);

        if (! $caja) {
            return back()->with('error', 'La caja elegida no existe.');
        }

        $permitidas = $usuario->sucursalesPermitidas();
        if ($permitidas && ! in_array($caja->sucursal_id, $permitidas, true)) {
            return back()->with('error', 'No estás asignado a la sucursal de esa caja.');
        }

        $ocupadaPor = $caja->aperturaAbierta?->usuario?->nombre_completo;
        if ($ocupadaPor) {
            return back()->with('error', "La caja ya está abierta por {$ocupadaPor}.");
        }

        try {
            AperturaCaja::create([
                'empresa_id' => $usuario->empresa_id,
                'caja_id' => $caja->id,
                'usuario_id' => $usuario->id,
                'monto_inicial' => $datos['monto_inicial'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // la BD garantiza una apertura por caja y una por usuario aunque lleguen dos clics a la vez
            return back()->with('error', 'Esa caja (o tu usuario) ya tiene un turno abierto.');
        }

        // al abrir caja se pasa a ver esa sucursal: lo que vende y lo que consulta coinciden
        $request->session()->put('sucursal_activa_id', $caja->sucursal_id);

        return back()->with('success', "Caja {$caja->nombre} abierta.");
    }

    public function movimiento(Request $request): RedirectResponse
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return back()->with('error', 'No tienes una caja abierta.');
        }

        $datos = $request->validate([
            'tipo' => ['required', 'in:ingreso,egreso'],
            'concepto' => ['required', 'string', 'max:200'],
            'monto' => ['required', 'numeric', 'gt:0'],
            // por dónde entró o salió el dinero; si no lo mandan (versiones viejas de la pantalla) es efectivo
            'medio_pago_codigo' => ['nullable', 'string', Rule::exists('medios_pago', 'codigo')->whereNotIn('codigo', MedioPago::INTERNOS)],
            'referencia' => ['nullable', 'string', 'max:100'],
        ], [
            'concepto.required' => 'Describe el motivo del movimiento.',
            'monto.required' => 'Ingresa el monto.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'medio_pago_codigo.exists' => 'Elige un medio de pago válido.',
        ]);
        $datos['medio_pago_codigo'] = $datos['medio_pago_codigo'] ?? 'efectivo';
        $datos['referencia'] = $datos['medio_pago_codigo'] === 'efectivo' ? null : ($datos['referencia'] ?? null);

        // un egreso mayor al efectivo disponible no se bloquea: el dinero salió del negocio igual (pago del local,
        // de un proveedor) y el cierre mostrará el negativo. La pantalla avisa antes de guardar.
        if ($datos['tipo'] === 'egreso' && ! $request->user()->can('caja.egresos')) {
            return back()->with('error', 'Tu rol no puede registrar egresos de caja.');
        }

        $movimiento = MovimientoCaja::create([
            'empresa_id' => $apertura->empresa_id,
            'apertura_id' => $apertura->id,
            'usuario_id' => $request->user()->id,
            ...$datos,
        ]);

        // el dinero que sale de caja deja constancia de quien lo saco y por que
        if ($datos['tipo'] === 'egreso') {
            Auditoria::registrar($request->user(), 'caja.egreso', 'movimiento_caja', $movimiento->id, [
                'caja' => $apertura->caja?->nombre,
                'concepto' => $datos['concepto'],
                'monto' => (float) $datos['monto'],
                'medio' => $datos['medio_pago_codigo'],
            ]);
        }

        $medio = $datos['medio_pago_codigo'] === 'efectivo' ? '' : ' por '.(MedioPago::whereKey($datos['medio_pago_codigo'])->value('nombre') ?? $datos['medio_pago_codigo']);

        return back()->with('success', ($datos['tipo'] === 'ingreso' ? 'Ingreso' : 'Egreso')." de S/ ".number_format((float) $datos['monto'], 2)."{$medio} registrado.");
    }

    /**
     * Elimina un ingreso o egreso registrado a mano, solo mientras su turno sigue abierto y es del propio usuario.
     * Los que nacieron de otra operación (adelanto o devolución de un pedido, devolución por nota de crédito)
     * se deshacen desde su pantalla. Desaparece de la caja, pero queda constancia en la auditoría.
     */
    public function eliminarMovimiento(Request $request, MovimientoCaja $movimiento): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($movimiento->empresa_id === $usuario->empresa_id, 403);

        $apertura = $this->caja->aperturaDe($usuario);
        if (! $apertura || $movimiento->apertura_id !== $apertura->id) {
            return back()->with('error', 'Solo puedes eliminar movimientos de tu caja abierta. Un turno cerrado ya no se modifica.');
        }

        if ($movimiento->tipo === 'egreso' && ! $usuario->can('caja.egresos')) {
            return back()->with('error', 'Tu rol no puede eliminar egresos de caja.');
        }

        $vinculado = \App\Models\PedidoAdelanto::where('movimiento_caja_id', $movimiento->id)->exists()
            || \App\Models\Pedido::where('devolucion_movimiento_id', $movimiento->id)->exists()
            || str_starts_with($movimiento->concepto, 'Devolución por nota de crédito');
        if ($vinculado) {
            return back()->with('error', 'Este movimiento viene de un pedido o de una nota de crédito: se corrige desde esa pantalla.');
        }

        Auditoria::registrar($usuario, 'caja.movimiento_eliminado', 'movimiento_caja', $movimiento->id, [
            'caja' => $apertura->caja?->nombre,
            'tipo' => $movimiento->tipo,
            'concepto' => $movimiento->concepto,
            'monto' => (float) $movimiento->monto,
            'medio' => $movimiento->medio_pago_codigo,
            'registrado_en' => $movimiento->creado_en?->toDateTimeString(),
        ]);
        $movimiento->delete();

        return back()->with('success', ($movimiento->tipo === 'ingreso' ? 'Ingreso' : 'Egreso').' de S/ '.number_format((float) $movimiento->monto, 2).' eliminado.');
    }

    /** Lo que dejó un turno por todos los medios, sin el monto inicial (turnos antiguos sin cuadre por medio: solo efectivo). */
    private function totalTurno(AperturaCaja $turno): float
    {
        $esperado = $turno->cierresMedios->isNotEmpty()
            ? (float) $turno->cierresMedios->sum('esperado')
            : (float) $turno->monto_sistema;

        return round($esperado - (float) $turno->monto_inicial, 2);
    }

    /**
     * Totales de todos los turnos del filtro (no solo de la página visible), como la fila "Suma" del sistema anterior.
     * Una sola consulta con subconsultas por turno.
     */
    private function sumaHistorial($ids): array
    {
        $porTurno = DB::table('aperturas_caja as a')
            ->whereIn('a.id', $ids)
            ->selectRaw("a.monto_inicial, a.monto_sistema, a.monto_cierre,
                (SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE apertura_id = a.id AND medio_pago_codigo = 'efectivo')
                  + (SELECT COALESCE(SUM(monto), 0) FROM cobros WHERE apertura_id = a.id AND medio_pago_codigo = 'efectivo') AS ventas_efectivo,
                (SELECT COALESCE(SUM(monto), 0) FROM movimientos_caja WHERE apertura_id = a.id AND tipo = 'ingreso' AND medio_pago_codigo = 'efectivo') AS ingresos,
                (SELECT COALESCE(SUM(monto), 0) FROM movimientos_caja WHERE apertura_id = a.id AND tipo = 'egreso' AND medio_pago_codigo = 'efectivo')
                  + (SELECT COALESCE(SUM(monto), 0) FROM pagos_proveedor WHERE apertura_id = a.id AND medio_pago_codigo = 'efectivo') AS egresos,
                COALESCE((SELECT SUM(esperado) FROM cierres_caja_medios WHERE apertura_id = a.id), a.monto_sistema) - a.monto_inicial AS total_turno,
                (SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE apertura_id = a.id AND medio_pago_codigo NOT IN ('adelanto'))
                  + (SELECT COALESCE(SUM(monto), 0) FROM cobros WHERE apertura_id = a.id)
                  + (SELECT COALESCE(SUM(monto), 0) FROM movimientos_caja WHERE apertura_id = a.id AND tipo = 'ingreso') AS entro,
                (SELECT COALESCE(SUM(monto), 0) FROM movimientos_caja WHERE apertura_id = a.id AND tipo = 'egreso')
                  + (SELECT COALESCE(SUM(monto), 0) FROM pagos_proveedor WHERE apertura_id = a.id) AS salio,
                COALESCE((SELECT SUM(diferencia) FROM cierres_caja_medios WHERE apertura_id = a.id), a.monto_cierre - a.monto_sistema) AS diferencia");

        $t = DB::query()->fromSub($porTurno, 't')->selectRaw('COUNT(*) AS turnos,
            COALESCE(SUM(monto_inicial), 0) AS inicial, COALESCE(SUM(ventas_efectivo), 0) AS ventas_efectivo,
            COALESCE(SUM(ingresos), 0) AS ingresos, COALESCE(SUM(egresos), 0) AS egresos,
            COALESCE(SUM(monto_sistema), 0) AS esperado, COALESCE(SUM(monto_cierre), 0) AS contado,
            COALESCE(SUM(diferencia), 0) AS diferencia, COALESCE(SUM(total_turno), 0) AS total_turno,
            COALESCE(SUM(entro), 0) AS entro, COALESCE(SUM(salio), 0) AS salio, COALESCE(SUM(entro) - SUM(salio), 0) AS saldo')->first();

        return collect((array) $t)->map(fn ($v, $k) => $k === 'turnos' ? (int) $v : round((float) $v, 2))->all();
    }

    public function cerrar(Request $request): RedirectResponse
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return back()->with('error', 'No tienes una caja abierta.');
        }

        // el efectivo contado y lo declarado por medio son opcionales: sin dato se cierra con lo que el sistema espera
        // (como el sistema anterior de los clientes). Pueden ser negativos porque el saldo del turno puede quedar en negativo.
        $datos = $request->validate([
            'monto_cierre' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            'conteo' => ['nullable', 'array'],
            'conteo.*' => ['nullable', 'integer', 'min:0'],
            'declarados' => ['nullable', 'array'],
            'declarados.*' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ], [
            'monto_cierre.numeric' => 'El efectivo contado debe ser un monto.',
            'declarados.*.numeric' => 'Lo declarado debe ser un monto.',
            'observaciones.max' => 'La observación no puede pasar de 500 caracteres.',
        ]);

        // el conteo por billetes/monedas, si viene, manda sobre el total escrito a mano
        $conteo = collect($datos['conteo'] ?? [])
            ->only(CajaService::DENOMINACIONES)
            ->map(fn ($n) => (int) $n)
            ->filter();
        $efectivo = $conteo->isNotEmpty()
            ? round($conteo->reduce(fn ($total, $n, $valor) => $total + $n * (float) $valor, 0.0), 2)
            : (isset($datos['monto_cierre']) && $datos['monto_cierre'] !== '' ? (float) $datos['monto_cierre'] : null);

        $declarados = collect($datos['declarados'] ?? [])
            ->map(fn ($v) => $v === null || $v === '' ? null : (float) $v)
            ->put('efectivo', $efectivo)
            ->all();
        $observaciones = filled($datos['observaciones'] ?? null) ? trim($datos['observaciones']) : null;

        try {
            $cuadre = DB::transaction(function () use ($apertura, $declarados, $conteo, $observaciones) {
                // con la apertura bloqueada, las ventas en curso esperan y lo esperado es lo real
                $bloqueada = AperturaCaja::lockForUpdate()->findOrFail($apertura->id);

                if ($bloqueada->cerrada_en) {
                    throw new ErrorDeNegocio('Esta caja ya fue cerrada.');
                }

                return $this->caja->cerrar($bloqueada, $declarados, $conteo->isNotEmpty() ? $conteo->all() : null, $observaciones);
            });
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $conDiferencia = collect($cuadre)->filter(fn ($m) => abs($m['diferencia']) >= 0.005);

        if ($conDiferencia->isNotEmpty()) {
            $efectivoCuadre = collect($cuadre)->firstWhere('codigo', 'efectivo');
            Auditoria::registrar($request->user(), 'caja.cierre_con_diferencia', 'apertura_caja', $apertura->id, [
                'caja' => $apertura->caja?->nombre,
                'esperado' => $efectivoCuadre['esperado'],
                'contado' => $efectivoCuadre['declarado'],
                'diferencia' => $efectivoCuadre['diferencia'],
                'medios' => $conDiferencia->map(fn ($m) => [
                    'medio' => $m['nombre'],
                    'esperado' => $m['esperado'],
                    'declarado' => $m['declarado'],
                    'diferencia' => $m['diferencia'],
                ])->values()->all(),
            ]);
        }

        $detalle = $conDiferencia->isEmpty()
            ? 'Caja cuadrada, sin diferencias.'
            : $conDiferencia->map(fn ($m) => ($m['diferencia'] > 0 ? 'sobran' : 'faltan').' S/ '.number_format(abs($m['diferencia']), 2)." en {$m['nombre']}")
                ->implode(', ').'.';

        return back()
            ->with('success', 'Caja cerrada. '.ucfirst($detalle))
            ->with('ticket', route('caja.turnos.ticket', $apertura));
    }

    /** Ticket de cierre del turno (resumen por medio, conteo y diferencias) para la impresora térmica. */
    public function ticketCierre(Request $request, AperturaCaja $apertura)
    {
        $usuario = $request->user();

        abort_unless($apertura->empresa_id === $usuario->empresa_id, 403);
        abort_unless($apertura->usuario_id === $usuario->id || $usuario->can('caja.ver_todas'), 403);
        abort_unless($apertura->cerrada_en !== null, 404);

        $apertura->load(['caja:id,nombre,sucursal_id,ancho_ticket', 'caja.sucursal:id,nombre', 'usuario:id,nombre_completo', 'cierresMedios.medioPago:codigo,nombre']);

        $medios = $this->caja->resumenPorMedio($apertura);
        $cierres = $apertura->cierresMedios->keyBy('medio_pago_codigo');
        $ancho = (int) ($apertura->caja?->ancho_ticket ?? 80);
        $empresa = $usuario->empresa;

        $pdf = SnappyPdf::loadView('pdf.cierre-caja', [
            'apertura' => $apertura,
            'empresa' => $empresa,
            'medios' => $medios,
            'cierres' => $cierres,
            'ventas' => $apertura->comprobantes()->where('estado', 'emitido')->where('tipo_comprobante_codigo', '!=', '07')
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS total')->first(),
            'anuladas' => $apertura->comprobantes()->where('estado', 'anulado')->count(),
            'ancho' => $ancho,
        ])
            ->setOption('page-width', "{$ancho}mm")
            ->setOption('page-height', (int) ceil(110 + count($medios) * 18 + count($apertura->conteo_efectivo ?? []) * 4 + (filled($apertura->observaciones) ? 6 + ceil(mb_strlen($apertura->observaciones) / ($ancho === 58 ? 36 : 50)) * 4 : 0)).'mm')
            ->setOption('margin-top', '3')
            ->setOption('margin-bottom', '3')
            ->setOption('margin-left', '4')
            ->setOption('margin-right', '4')
            ->setOption('encoding', 'utf-8');

        return $pdf->inline('cierre-'.$apertura->cerrada_en->format('Y-m-d-His').'.pdf');
    }
}
