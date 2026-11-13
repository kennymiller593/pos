<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\Producto;
use App\Models\ProductoSerie;
use App\Models\SerieMovimiento;
use App\Models\Stock;
use App\Models\Transferencia;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Series / IMEI: una capa ENCIMA del inventario. Cada unidad de un producto que "controla serie" tiene su
 * número; el stock, las capas de costo y el FIFO no se tocan aquí (los mueve InventarioService como siempre).
 * Invariante: las series 'disponible' de un producto en una sucursal son tantas como su stock.
 *
 * Todos los métodos que escriben asumen una transacción abierta, igual que InventarioService.
 */
class SerieService
{
    public const MAX_LARGO = 60;

    /** Cuántas unidades disponibles se listan de una vez; con más, la pantalla busca en el servidor. */
    public const LIMITE_LISTA = 500;

    /** Tope de equipos con serie en una línea de venta (el ticket lista cada serie). */
    public const MAX_POR_LINEA_VENTA = 50;

    /** Lo que puede llevar una serie ya normalizada: nada que rompa el XML de SUNAT ni la impresión. */
    public const PATRON = '/^[A-Za-z0-9\-\/._]+$/';

    public const MENSAJE_PATRON = 'La serie solo puede tener letras, números, guion, punto, barra o guion bajo.';

    /**
     * Regla de validación para cada serie que llega de un formulario (se comprueba ya sin espacios, como se guarda).
     * $opcional: admite vacío (segundo IMEI).
     */
    public static function regla(bool $opcional = false): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falla) use ($opcional) {
            $numero = is_string($valor) ? ProductoSerie::normalizar($valor) : null;
            if ($opcional && ($valor === null || $numero === '')) {
                return;
            }
            if ($numero === null || ! preg_match(self::PATRON, $numero)) {
                $falla(self::MENSAJE_PATRON);
            }
        };
    }

    /**
     * Junta las series que manda un formulario (lista de números y, en paralelo, sus segundos IMEI opcionales)
     * en la forma que espera ingresar(): [['numero' => ..., 'alterna' => ...|null], ...]. Las vacías se descartan.
     *
     * @return list<array{numero: string, alterna: ?string}>
     */
    public static function unir(?array $numeros, ?array $alternas = null): array
    {
        $unidas = [];
        foreach (array_values((array) $numeros) as $i => $numero) {
            $numero = ProductoSerie::normalizar($numero);
            if ($numero === '') {
                continue;
            }
            $alterna = ProductoSerie::normalizar(array_values((array) $alternas)[$i] ?? '');
            $unidas[] = ['numero' => $numero, 'alterna' => $alterna !== '' ? $alterna : null];
        }

        return $unidas;
    }

    /**
     * Registra las unidades que entran (compra o ajuste de entrada). No acepta una serie repetida en el pedido ni
     * una que ya esté en el negocio (disponible o en tránsito). Una serie del mismo producto que salió (vendida o
     * de baja) y vuelve a entrar se reactiva en vez de duplicarse, y su historia continúa.
     *
     * @param  list<array{numero: string, alterna: ?string}|string>  $numeros
     * @return Collection<int, ProductoSerie>
     *
     * @throws ErrorDeNegocio
     */
    public function ingresar(
        Producto $producto,
        string $sucursalId,
        array $numeros,
        float $costo,
        string $tipo,
        ?string $referenciaId,
        ?string $compraDetalleId,
        Usuario $usuario,
    ): Collection {
        $unidades = [];
        $vistos = [];
        foreach ($numeros as $unidad) {
            $numero = ProductoSerie::normalizar(is_array($unidad) ? ($unidad['numero'] ?? '') : $unidad);
            $alterna = ProductoSerie::normalizar(is_array($unidad) ? ($unidad['alterna'] ?? '') : '');

            if ($numero === '') {
                throw new ErrorDeNegocio("Hay una serie vacía en \"{$producto->nombre}\".");
            }
            foreach (array_filter([$numero, $alterna]) as $valor) {
                if (! preg_match(self::PATRON, $valor)) {
                    throw new ErrorDeNegocio(self::MENSAJE_PATRON);
                }
                if (mb_strlen($valor) > self::MAX_LARGO) {
                    throw new ErrorDeNegocio("La serie {$valor} pasa de ".self::MAX_LARGO.' caracteres.');
                }
                if (isset($vistos[$valor])) {
                    throw new ErrorDeNegocio("La serie {$valor} está repetida en \"{$producto->nombre}\".");
                }
                $vistos[$valor] = true;
            }

            $unidades[] = ['numero' => $numero, 'alterna' => $alterna !== '' ? $alterna : null];
        }

        if ($unidades === []) {
            return collect();
        }

        // dos ingresos simultáneos del mismo producto se ponen en fila: el segundo ya ve las series del primero
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["series:{$producto->id}"]);

        $valores = array_map('strval', array_keys($vistos));
        $existentes = ProductoSerie::query()
            ->where('empresa_id', $producto->empresa_id)
            ->where('producto_id', $producto->id)
            ->where(fn ($q) => $q->whereIn('numero_serie', $valores)->orWhereIn('serie_alterna', $valores))
            ->lockForUpdate()
            ->get();

        $ingresadas = collect();
        foreach ($unidades as $unidad) {
            // comparación estricta: "0123" y "123" son series distintas
            $propia = $existentes->first(fn (ProductoSerie $s) => $s->numero_serie === $unidad['numero']);

            // el mismo número como serie o como segundo IMEI de OTRA unidad que sigue en el negocio
            $choque = $existentes->first(fn (ProductoSerie $s) => $s->id !== $propia?->id
                && in_array($s->estado, ['disponible', 'en_transito'], true)
                && array_intersect(array_filter([$s->numero_serie, $s->serie_alterna]), array_filter([$unidad['numero'], $unidad['alterna']])) !== []);
            if ($choque) {
                throw new ErrorDeNegocio("La serie {$unidad['numero']} choca con la unidad {$choque->numero_serie} de \"{$producto->nombre}\", que ya está registrada.");
            }

            if ($propia && in_array($propia->estado, ['disponible', 'en_transito'], true)) {
                throw new ErrorDeNegocio("La serie {$unidad['numero']} de \"{$producto->nombre}\" ya está registrada".($propia->estado === 'en_transito' ? ' (en tránsito).' : '.'));
            }

            // vendida con una venta cuya anulación está en proceso en SUNAT: al confirmarse la baja la serie vuelve
            // sola; si reingresara ahora, esa confirmación repondría stock sin encontrar la serie
            if ($propia && $propia->estado === 'vendida' && $propia->comprobante_detalle_id && DB::table('comprobante_detalles as d')
                ->join('comprobantes_sunat as s', 's.comprobante_id', '=', 'd.comprobante_id')
                ->where('d.id', $propia->comprobante_detalle_id)
                ->where('s.estado', 'baja_pendiente')
                ->exists()) {
                throw new ErrorDeNegocio("La venta de la serie {$unidad['numero']} está en proceso de anulación en SUNAT: espera la confirmación, la serie volverá sola.");
            }

            if ($propia) {
                // salió (vendida o de baja) y vuelve a entrar: se reactiva y su historia continúa
                $propia->update([
                    'sucursal_id' => $sucursalId,
                    'estado' => 'disponible',
                    'serie_alterna' => $unidad['alterna'] ?? $propia->serie_alterna,
                    'costo_unitario' => round($costo, 6),
                    'compra_detalle_id' => $compraDetalleId,
                    'comprobante_detalle_id' => null,
                ]);
                $serie = $propia;
            } else {
                $serie = ProductoSerie::create([
                    'empresa_id' => $producto->empresa_id,
                    'producto_id' => $producto->id,
                    'sucursal_id' => $sucursalId,
                    'numero_serie' => $unidad['numero'],
                    'serie_alterna' => $unidad['alterna'],
                    'estado' => 'disponible',
                    'costo_unitario' => round($costo, 6),
                    'compra_detalle_id' => $compraDetalleId,
                ]);
            }

            $this->movimiento($serie, $tipo, $sucursalId, $usuario->id, $referenciaId);
            $ingresadas->push($serie);
        }

        return $ingresadas;
    }

    /**
     * Toma con bloqueo las unidades elegidas para una línea de venta: deben ser de ese producto, estar en esa
     * sucursal y seguir disponibles. Dos cajas con la misma unidad se serializan aquí y la segunda la ve vendida.
     *
     * @param  list<string>  $serieIds
     * @return Collection<int, ProductoSerie>
     *
     * @throws ErrorDeNegocio
     */
    public function reservarParaVenta(array $serieIds, string $productoId, string $sucursalId): Collection
    {
        $series = ProductoSerie::query()
            ->whereIn('id', $serieIds)
            ->where('producto_id', $productoId)
            ->orderBy('id') // orden fijo: dos ventas con varias unidades en común no se bloquean entre sí
            ->lockForUpdate()
            ->get();

        if ($series->count() !== count(array_unique($serieIds))) {
            throw new ErrorDeNegocio('Una de las series elegidas no existe o no es de ese producto. Vuelve a elegirla.');
        }

        foreach ($series as $serie) {
            if ($serie->estado !== 'disponible' || $serie->sucursal_id !== $sucursalId) {
                throw new ErrorDeNegocio("La serie {$serie->numero_serie} ya no está disponible.");
            }
        }

        return $series;
    }

    /**
     * Marca vendidas las unidades ya reservadas, enlazadas a la línea del comprobante.
     *
     * @param  iterable<ProductoSerie>  $series
     */
    public function vender(iterable $series, ComprobanteDetalle $detalle, Comprobante $comprobante, Usuario $usuario): void
    {
        foreach ($series as $serie) {
            // con condición: la misma unidad en dos líneas (petición manipulada) solo se vende una vez
            $vendidas = ProductoSerie::whereKey($serie->id)->where('estado', 'disponible')
                ->update(['estado' => 'vendida', 'comprobante_detalle_id' => $detalle->id, 'actualizado_en' => now()]);
            if ($vendidas !== 1) {
                throw new ErrorDeNegocio("La serie {$serie->numero_serie} ya no está disponible.");
            }
            $this->movimiento($serie, 'venta', $comprobante->sucursal_id, $usuario->id, $comprobante->id, $detalle->id);
        }
    }

    /**
     * Las unidades vendidas en esas líneas vuelven a estar disponibles en la sucursal de la venta (anulación o
     * nota de crédito total). $detalleNotaPorOriginal: [id de la línea original => id de la línea de la nota],
     * para que la nota también muestre las series que devuelve. Devuelve cuántas unidades volvieron.
     *
     * $soloSeries: en una devolución por ítem, los ids de las unidades que vuelven (las demás siguen vendidas).
     *
     * @param  list<string>  $detalleIds
     * @param  array<string, string>  $detalleNotaPorOriginal
     * @param  list<string>|null  $soloSeries
     */
    public function devolver(array $detalleIds, string $tipo, string $sucursalId, ?string $referenciaId, Usuario $usuario, array $detalleNotaPorOriginal = [], ?array $soloSeries = null): int
    {
        if ($detalleIds === []) {
            return 0;
        }

        $series = ProductoSerie::query()
            ->whereIn('comprobante_detalle_id', $detalleIds)
            ->where('estado', 'vendida')
            ->when($soloSeries !== null, fn ($q) => $q->whereIn('id', $soloSeries))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($series as $serie) {
            $detalleNota = $detalleNotaPorOriginal[$serie->comprobante_detalle_id] ?? null;
            $serie->update(['estado' => 'disponible', 'sucursal_id' => $sucursalId, 'comprobante_detalle_id' => null]);
            $this->movimiento($serie, $tipo, $sucursalId, $usuario->id, $referenciaId, $detalleNota);
        }

        return $series->count();
    }

    /**
     * Unidades vendidas ahora mismo con esas líneas, agrupadas por línea (para elegir cuál vuelve en una nota de crédito).
     *
     * @return Collection<string, Collection<int, ProductoSerie>>
     */
    public function vendidasPorDetalle(array $detalleIds): Collection
    {
        if ($detalleIds === []) {
            return collect();
        }

        return ProductoSerie::query()
            ->whereIn('comprobante_detalle_id', $detalleIds)
            ->where('estado', 'vendida')
            ->orderBy('numero_serie')
            ->get(['id', 'numero_serie', 'serie_alterna', 'comprobante_detalle_id'])
            ->groupBy('comprobante_detalle_id');
    }

    /** Líneas (de las indicadas) que se vendieron eligiendo serie, según el historial. @return list<string> */
    public function detallesVendidosConSerie(array $detalleIds): array
    {
        if ($detalleIds === []) {
            return [];
        }

        return SerieMovimiento::query()
            ->whereIn('comprobante_detalle_id', $detalleIds)
            ->where('tipo', 'venta')
            ->distinct()
            ->pluck('comprobante_detalle_id')
            ->all();
    }

    /**
     * Antes de anular una venta o emitirle una nota de crédito (y ANTES de avisar a SUNAT): ninguna unidad vendida
     * con esas líneas puede haber vuelto al stock por otro documento (una compra, un ajuste o una regularización
     * que reingresó la misma serie). Si pasó, reponer el stock otra vez dejaría una unidad sin serie.
     * Mira el historial de cada unidad, no su estado actual: después de la venta solo puede seguir vendida o
     * haber vuelto por la anulación / nota de crédito de esa misma venta.
     *
     * @throws ErrorDeNegocio
     */
    public function verificarSinReingresos(array $detalleIds): void
    {
        if ($detalleIds === []) {
            return;
        }

        $ventas = SerieMovimiento::query()
            ->whereIn('comprobante_detalle_id', $detalleIds)
            ->where('tipo', 'venta')
            ->get(['id', 'serie_id']);

        if ($ventas->isEmpty()) {
            return;
        }

        $historias = SerieMovimiento::query()
            ->whereIn('serie_id', $ventas->pluck('serie_id')->unique())
            ->orderBy('creado_en')
            ->orderBy('id')
            ->get(['id', 'serie_id', 'tipo'])
            ->groupBy('serie_id');

        foreach ($ventas as $venta) {
            $historia = $historias->get($venta->serie_id)->values();
            $siguiente = $historia->get($historia->search(fn ($m) => $m->id === $venta->id) + 1);

            if ($siguiente && ! in_array($siguiente->tipo, ['venta_anulada', 'devolucion'], true)) {
                $numero = ProductoSerie::whereKey($venta->serie_id)->value('numero_serie');

                throw new ErrorDeNegocio("El equipo S/N {$numero} de esta venta ya volvió a ingresar al stock por otro documento (compra, ajuste o regularización). Corrige ese ingreso antes de anular o emitir la nota de crédito.");
            }
        }
    }

    // ------------------------------------------------------------------ unidades sin serie (regularización)

    /**
     * Unidades de un producto con serie que están en el stock de la sucursal SIN número registrado
     * (stock − series disponibles): pasa al activar la serie con stock, o al anular una venta anterior a activarla.
     */
    public function sinSerie(Producto $producto, string $sucursalId, bool $bloquear = false): int
    {
        if (! $producto->controla_serie) {
            return 0;
        }

        $stock = (float) Stock::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->value('cantidad');

        $disponibles = ProductoSerie::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->where('estado', 'disponible')
            ->count();

        return max(0, (int) floor($stock + 0.0005) - $disponibles);
    }

    /**
     * Regularización: registra la serie de unidades que YA están en el stock sin número. No mueve stock ni capas;
     * nunca deja registrar más series que unidades sin número haya.
     *
     * @param  list<array{numero: string, alterna: ?string}|string>  $numeros
     * @return Collection<int, ProductoSerie>
     *
     * @throws ErrorDeNegocio
     */
    public function regularizar(Producto $producto, string $sucursalId, array $numeros, Usuario $usuario): Collection
    {
        if (! $producto->controla_serie) {
            throw new ErrorDeNegocio('Este producto no controla serie / IMEI.');
        }
        if ($numeros === []) {
            throw new ErrorDeNegocio('Registra al menos una serie.');
        }

        // con la fila de stock bloqueada: una venta o un ajuste simultáneo no cambian la diferencia a medias
        $sinSerie = $this->sinSerie($producto, $sucursalId, bloquear: true);
        if (count($numeros) > $sinSerie) {
            throw new ErrorDeNegocio($sinSerie === 0
                ? 'Este producto ya no tiene unidades sin serie en esta sucursal.'
                : sprintf('Solo hay %d %s sin serie: no puedes registrar %d.', $sinSerie, $sinSerie === 1 ? 'unidad' : 'unidades', count($numeros)));
        }

        // informativo: el costo promedio de lo que queda en las capas
        $costo = (float) DB::table('capas_costo')
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad_restante', '>', 0)
            ->selectRaw('COALESCE(SUM(cantidad_restante * costo_unitario) / NULLIF(SUM(cantidad_restante), 0), 0) as costo')
            ->value('costo');

        return $this->ingresar($producto, $sucursalId, $numeros, $costo, 'regularizacion', null, null, $usuario);
    }

    // ------------------------------------------------------------------ transferencias entre sucursales

    /**
     * Las unidades elegidas salen del origen: quedan "en tránsito" (no cuentan en ninguna sucursal, igual que el
     * stock). Qué unidades viajan en cada transferencia queda en su movimiento (referencia = la transferencia).
     *
     * @param  list<string>  $serieIds
     *
     * @throws ErrorDeNegocio
     */
    public function enviarEnTransferencia(array $serieIds, Producto $producto, Transferencia $transferencia, Usuario $usuario): void
    {
        $series = $this->reservarParaVenta($serieIds, $producto->id, $transferencia->sucursal_origen_id);

        foreach ($series as $serie) {
            $serie->update(['estado' => 'en_transito']);
            $this->movimiento($serie, 'transferencia_salida', $transferencia->sucursal_origen_id, $usuario->id, $transferencia->id);
        }
    }

    /** Las unidades que viajan (o viajaron) en una transferencia. @return Collection<int, ProductoSerie> */
    public function deTransferencia(string $transferenciaId, bool $bloquear = false): Collection
    {
        return ProductoSerie::query()
            ->whereIn('id', SerieMovimiento::query()
                ->where('referencia_id', $transferenciaId)
                ->where('tipo', 'transferencia_salida')
                ->select('serie_id'))
            ->orderBy('id')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /** La transferencia llegó: sus unidades quedan disponibles en el destino. */
    public function recibirTransferencia(Transferencia $transferencia, Usuario $usuario): int
    {
        return $this->cerrarTransferencia($transferencia, $transferencia->sucursal_destino_id, 'transferencia_entrada', $usuario);
    }

    /** La transferencia se anuló en el camino: sus unidades vuelven a estar disponibles en el origen. */
    public function anularTransferencia(Transferencia $transferencia, Usuario $usuario): int
    {
        return $this->cerrarTransferencia($transferencia, $transferencia->sucursal_origen_id, 'transferencia_anulada', $usuario);
    }

    private function cerrarTransferencia(Transferencia $transferencia, string $sucursalId, string $tipo, Usuario $usuario): int
    {
        // solo las que siguen en tránsito: nada más puede tocar una unidad en ese estado
        $series = $this->deTransferencia($transferencia->id, bloquear: true)->where('estado', 'en_transito');

        foreach ($series as $serie) {
            $serie->update(['estado' => 'disponible', 'sucursal_id' => $sucursalId]);
            $this->movimiento($serie, $tipo, $sucursalId, $usuario->id, $transferencia->id);
        }

        return $series->count();
    }

    /** Líneas (de las indicadas) que tienen unidades con serie vendidas ahora mismo. @return list<string> */
    public function detallesConSeries(array $detalleIds): array
    {
        if ($detalleIds === []) {
            return [];
        }

        return ProductoSerie::query()
            ->whereIn('comprobante_detalle_id', $detalleIds)
            ->where('estado', 'vendida')
            ->distinct()
            ->pluck('comprobante_detalle_id')
            ->all();
    }

    /**
     * Da de baja unidades disponibles de esa sucursal (ajuste de salida: equipo dañado, perdido...).
     *
     * @param  list<string>  $serieIds
     *
     * @throws ErrorDeNegocio
     */
    public function darDeBaja(array $serieIds, Producto $producto, string $sucursalId, Usuario $usuario, ?string $referenciaId = null): int
    {
        $series = $this->reservarParaVenta($serieIds, $producto->id, $sucursalId);

        foreach ($series as $serie) {
            $serie->update(['estado' => 'baja']);
            $this->movimiento($serie, 'baja', $sucursalId, $usuario->id, $referenciaId);
        }

        return $series->count();
    }

    /**
     * Anulación de una compra: sus unidades salen (quedan de baja) solo si TODAS siguen disponibles en la sucursal
     * que las recibió. Si alguna ya se vendió, se dio de baja o se movió, la compra no se puede anular.
     *
     * @throws ErrorDeNegocio
     */
    public function anularCompra(string $compraDetalleId, Producto $producto, string $sucursalId, float $cantidad, ?string $compraId, Usuario $usuario): void
    {
        $series = ProductoSerie::query()
            ->where('compra_detalle_id', $compraDetalleId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $fuera = $series->first(fn (ProductoSerie $s) => $s->estado !== 'disponible' || $s->sucursal_id !== $sucursalId);
        if ($fuera) {
            $que = ['vendida' => 'ya se vendió', 'baja' => 'ya se dio de baja', 'en_transito' => 'está en tránsito'][$fuera->estado] ?? 'ya no está en esta sucursal';

            throw new ErrorDeNegocio("No se puede anular: la serie {$fuera->numero_serie} de \"{$producto->nombre}\" {$que}. Registra un ajuste de stock en su lugar.");
        }

        // compra anterior al control de serie (ninguna serie cuelga de ella): se puede anular si en la sucursal
        // quedan unidades SIN número que cubran su cantidad; esas son las que salen, sin tocar series
        if ($producto->controla_serie && $series->isEmpty()) {
            if ($this->sinSerie($producto, $sucursalId, bloquear: true) < (int) round($cantidad)) {
                throw new ErrorDeNegocio("No se puede anular: esta compra de \"{$producto->nombre}\" es anterior al control de serie y sus unidades ya tienen número registrado o ya salieron: usa un ajuste de salida.");
            }

            return;
        }

        // una unidad de esta compra que salió y volvió a entrar por otro documento ya no cuelga de aquí
        if ($producto->controla_serie && $series->count() !== (int) round($cantidad)) {
            throw new ErrorDeNegocio("No se puede anular: alguna unidad de \"{$producto->nombre}\" de esta compra ya se vendió o se movió. Registra un ajuste de stock en su lugar.");
        }

        foreach ($series as $serie) {
            $serie->update(['estado' => 'baja']);
            $this->movimiento($serie, 'compra_anulada', $sucursalId, $usuario->id, $compraId);
        }
    }

    /**
     * Unidades disponibles de un producto en una sucursal (para elegir al vender o al dar de baja).
     *
     * @return Collection<int, ProductoSerie>
     */
    public function disponibles(string $productoId, string $sucursalId, ?string $buscar = null, int $limite = self::LIMITE_LISTA): Collection
    {
        $buscar = ProductoSerie::normalizar($buscar);
        $patron = '%'.addcslashes($buscar, '%_\\').'%';

        return ProductoSerie::query()
            ->where('producto_id', $productoId)
            ->where('sucursal_id', $sucursalId)
            ->where('estado', 'disponible')
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w->where('numero_serie', 'like', $patron)->orWhere('serie_alterna', 'like', $patron)))
            ->orderBy('numero_serie')
            ->limit($limite)
            ->get(['id', 'producto_id', 'numero_serie', 'serie_alterna']);
    }

    /**
     * Busca una serie exacta (o un segundo IMEI) en TODA la empresa, esté donde esté y en el estado que esté.
     *
     * @return Collection<int, ProductoSerie>
     */
    public function buscar(string $empresaId, string $texto): Collection
    {
        $numero = ProductoSerie::normalizar($texto);
        if ($numero === '') {
            return collect();
        }

        return ProductoSerie::query()
            ->where('empresa_id', $empresaId)
            ->where(fn ($q) => $q->where('numero_serie', $numero)->orWhere('serie_alterna', $numero))
            ->orderByDesc('actualizado_en')
            ->limit(20)
            ->get();
    }

    private function movimiento(ProductoSerie $serie, string $tipo, string $sucursalId, ?string $usuarioId, ?string $referenciaId, ?string $comprobanteDetalleId = null): void
    {
        SerieMovimiento::create([
            'empresa_id' => $serie->empresa_id,
            'serie_id' => $serie->id,
            'sucursal_id' => $sucursalId,
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'referencia_id' => $referenciaId,
            'comprobante_detalle_id' => $comprobanteDetalleId,
        ]);
    }
}
