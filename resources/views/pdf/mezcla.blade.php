<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #262626; padding: 32px; }

        .encabezado { display: table; width: 100%; margin-bottom: 24px; }
        .encabezado .col { display: table-cell; vertical-align: top; }
        .empresa-nombre { font-size: 18px; font-weight: 700; }
        .empresa-dato { color: #737373; margin-top: 2px; }
        .titulo { text-align: right; }
        .titulo h1 { font-size: 16px; letter-spacing: 2px; color: #059669; }
        .titulo .fecha { color: #737373; margin-top: 4px; }
        .anulada { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 10px; background: #fee2e2; color: #b91c1c; font-size: 10px; font-weight: 700; }

        .info { width: 100%; border: 1px solid #e7e5e4; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
        .info table { width: 100%; border-collapse: collapse; }
        .info td { padding: 3px 0; vertical-align: top; }
        .info .etiqueta { color: #737373; width: 130px; }

        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #737373; margin: 18px 0 6px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th {
            text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
            color: #737373; border-bottom: 2px solid #059669; padding: 6px 8px;
        }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #f5f5f4; }
        table.items .num { text-align: right; }
        table.items .cant { text-align: center; }
        .presentacion { color: #737373; font-size: 11px; }

        .total-fila td { border-bottom: none; padding-top: 10px; }
        .total-etiqueta { text-align: right; font-weight: 700; }
        .total-valor { text-align: right; font-weight: 700; }
        .destacado { font-size: 15px; color: #059669; }

        .pie { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e7e5e4; color: #a3a3a3; font-size: 10px; }
    </style>
</head>
<body>
    @php
        $numero = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
        $pres = fn ($p) => $p && $p->nombre !== 'Unidad' ? ' '.$p->nombre : '';
    @endphp
    <div class="encabezado">
        <div class="col">
            <div class="empresa-nombre">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
            <div class="empresa-dato">{{ $empresa->razon_social }}</div>
            <div class="empresa-dato">RUC {{ $empresa->ruc }}</div>
        </div>
        <div class="col titulo">
            <h1>MEZCLA {{ $mezcla->codigo() }}</h1>
            <div class="fecha">{{ $mezcla->creado_en->format('d/m/Y H:i') }}</div>
            @if ($mezcla->estado === 'anulada')
                <span class="anulada">ANULADA</span>
            @endif
        </div>
    </div>

    <div class="info">
        <table>
            <tr>
                <td class="etiqueta">Producto obtenido</td>
                <td><strong>{{ $mezcla->producto?->nombre ?? '—' }}</strong></td>
                <td class="etiqueta">Cantidad</td>
                <td><strong>{{ $numero($mezcla->cantidad) }}{{ $pres($mezcla->presentacion) }}</strong>@if ((float) $mezcla->cantidad_base !== (float) $mezcla->cantidad) <span class="presentacion">({{ $numero($mezcla->cantidad_base) }} unid.)</span>@endif</td>
            </tr>
            <tr>
                <td class="etiqueta">Sucursal</td>
                <td>{{ $mezcla->sucursal?->nombre ?? '—' }}</td>
                <td class="etiqueta">Lote</td>
                <td>{{ $mezcla->lote?->numero_lote ?? '—' }}@if ($mezcla->lote?->fecha_vencimiento) <span class="presentacion">(vence {{ $mezcla->lote->fecha_vencimiento->format('d/m/Y') }})</span>@endif</td>
            </tr>
            <tr>
                <td class="etiqueta">Preparada por</td>
                <td>{{ $mezcla->usuario?->nombre_completo ?? '—' }}</td>
                <td class="etiqueta">Observación</td>
                <td>{{ $mezcla->observacion ?? '—' }}</td>
            </tr>
        </table>
    </div>

    <h2>Insumos usados</h2>
    <table class="items">
        <thead>
            <tr>
                <th class="cant">Cant.</th>
                <th>Insumo</th>
                @if ($verCostos)
                    <th class="num">Costo unit.</th>
                    <th class="num">Subtotal</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($mezcla->insumos as $insumo)
                <tr>
                    <td class="cant">{{ $numero($insumo->cantidad) }}{{ $pres($insumo->presentacion) }}</td>
                    <td>{{ $insumo->producto?->nombre ?? '—' }}</td>
                    @if ($verCostos)
                        <td class="num">S/ {{ number_format((float) $insumo->total / max((float) $insumo->cantidad, 0.001), 2) }}</td>
                        <td class="num">S/ {{ number_format((float) $insumo->total, 2) }}</td>
                    @endif
                </tr>
            @endforeach
            @if ($verCostos)
                <tr class="total-fila">
                    <td colspan="2"></td>
                    <td class="total-etiqueta">Insumos</td>
                    <td class="total-valor">S/ {{ number_format((float) $mezcla->costo_insumos, 2) }}</td>
                </tr>
                @if ((float) $mezcla->otros_costos > 0)
                    <tr class="total-fila">
                        <td colspan="2"></td>
                        <td class="total-etiqueta">Otros costos</td>
                        <td class="total-valor">S/ {{ number_format((float) $mezcla->otros_costos, 2) }}</td>
                    </tr>
                @endif
                <tr class="total-fila">
                    <td colspan="2"></td>
                    <td class="total-etiqueta">COSTO TOTAL</td>
                    <td class="total-valor destacado">S/ {{ number_format((float) $mezcla->costo_total, 2) }}</td>
                </tr>
                <tr class="total-fila">
                    <td colspan="2"></td>
                    <td class="total-etiqueta">Costo por unidad obtenida</td>
                    <td class="total-valor">S/ {{ number_format((float) $mezcla->costo_unitario, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="pie">
        Documento interno de control de mezclas y preparados · generado el {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
