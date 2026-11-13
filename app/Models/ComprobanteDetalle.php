<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComprobanteDetalle extends Model
{
    use HasUuids;

    protected $table = 'comprobante_detalles';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id',
        'comprobante_id',
        'producto_id',
        'presentacion_id',
        'lote_id',
        'descripcion',
        'unidad_codigo',
        'tipo_afectacion_codigo',
        'cantidad',
        'valor_unitario',
        'precio_unitario',
        'costo_unitario',
        'descuento',
        'igv',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'valor_unitario' => 'decimal:6',
            'precio_unitario' => 'decimal:6',
            'costo_unitario' => 'decimal:6',
            'descuento' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_codigo', 'codigo');
    }

    public function tipoAfectacion(): BelongsTo
    {
        return $this->belongsTo(TipoAfectacionIgv::class, 'tipo_afectacion_codigo', 'codigo');
    }

    public function consumosCapas(): HasMany
    {
        return $this->hasMany(DetalleConsumoCapa::class, 'detalle_id');
    }

    /**
     * Unidades con serie / IMEI que figuran en esta línea: las que se vendieron con ella o, en una nota de
     * crédito, las que devuelve. Sale del historial de la serie y no de su estado actual: el comprobante sigue
     * mostrando sus series aunque la venta se anule y el equipo se venda de nuevo.
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(ProductoSerie::class, 'serie_movimientos', 'comprobante_detalle_id', 'serie_id')
            ->wherePivotIn('tipo', ['venta', 'devolucion'])
            ->orderBy('producto_series.numero_serie');
    }

    /**
     * La descripción como se imprime y se envía a SUNAT: con las series de las unidades entregadas
     * ("Samsung A15 128GB S/N: 356789012345678 / 356789012345686"). `descripcion` queda limpia en la BD.
     * $conAlterna: junto a cada serie, su segundo IMEI si lo tiene.
     */
    public function descripcionCompleta(bool $conAlterna = true): string
    {
        $series = $this->textoSeries($conAlterna);

        return $series === '' ? (string) $this->descripcion : "{$this->descripcion} {$series}";
    }

    /** Solo las series de la línea ("S/N: 3567..., 3568..."), o '' si no lleva: los impresos las ponen en su propio renglón. */
    public function textoSeries(bool $conAlterna = true): string
    {
        $series = $this->series
            ->map(fn (ProductoSerie $s) => $conAlterna && filled($s->serie_alterna) ? "{$s->numero_serie} / {$s->serie_alterna}" : $s->numero_serie)
            ->unique()
            ->values();

        return $series->isEmpty() ? '' : 'S/N: '.$series->implode(', ');
    }

    /**
     * Las series de la línea en piezas que el ticket no debe partir: cada número (y cada segundo IMEI) con su
     * separador pegado. ["356789012345671 /", "356789012345681,", "356789012345672"]
     *
     * @return list<string>
     */
    public function piezasSeries(): array
    {
        $piezas = [];
        $series = $this->series->unique('id')->values();

        foreach ($series as $i => $serie) {
            $fin = $i < $series->count() - 1 ? ',' : '';
            if (filled($serie->serie_alterna)) {
                $piezas[] = "{$serie->numero_serie} /";
                $piezas[] = $serie->serie_alterna.$fin;
            } else {
                $piezas[] = $serie->numero_serie.$fin;
            }
        }

        return $piezas;
    }

    /**
     * La descripción para el XML de SUNAT, que tiene un largo máximo: lleva solo la serie principal de cada unidad
     * y nunca corta una serie a la mitad; si no entran todas termina con " y N más" (el impreso sí las lleva todas).
     */
    public function descripcionParaXml(int $maximo = 500): string
    {
        $base = (string) $this->descripcion;
        $series = $this->series->pluck('numero_serie')->unique()->values();

        if ($series->isEmpty()) {
            return mb_substr($base, 0, $maximo);
        }

        $completa = "{$base} S/N: ".$series->implode(', ');
        if (mb_strlen($completa) <= $maximo) {
            return $completa;
        }

        $texto = "{$base} S/N: ";
        $puestas = 0;
        foreach ($series as $i => $serie) {
            $pieza = ($puestas > 0 ? ', ' : '').$serie;
            $resto = $series->count() - ($i + 1);
            $cola = $resto > 0 ? " y {$resto} más" : '';
            if (mb_strlen($texto.$pieza.$cola) > $maximo) {
                break;
            }
            $texto .= $pieza;
            $puestas++;
        }

        $faltan = $series->count() - $puestas;

        return $puestas === 0
            ? mb_substr($base, 0, $maximo)
            : $texto.($faltan > 0 ? " y {$faltan} más" : '');
    }
}
