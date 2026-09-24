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

        .info { width: 100%; border: 1px solid #e7e5e4; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
        .info table { width: 100%; border-collapse: collapse; }
        .info td { padding: 3px 0; vertical-align: top; }
        .info .etiqueta { color: #737373; width: 130px; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th {
            text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
            color: #737373; border-bottom: 2px solid #059669; padding: 6px 8px;
        }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #f5f5f4; }
        table.items .num { text-align: right; }
        table.items .cant { text-align: center; }
        .presentacion { color: #737373; font-size: 11px; }

        .total-fila td { border-bottom: none; padding-top: 12px; }
        .total-etiqueta { text-align: right; font-weight: 700; }
        .total-valor { text-align: right; font-weight: 700; font-size: 15px; color: #059669; }

        .pie { margin-top: 28px; padding-top: 12px; border-top: 1px solid #e7e5e4; color: #a3a3a3; font-size: 10px; }
    </style>
</head>
<body>
    <div class="encabezado">
        <div class="col">
            <div class="empresa-nombre">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
            <div class="empresa-dato">{{ $empresa->razon_social }}</div>
            <div class="empresa-dato">RUC {{ $empresa->ruc }}</div>
        </div>
        <div class="col titulo">
            <h1>REGISTRO DE COMPRA</h1>
            <div class="fecha">{{ $compra->fecha->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="info">
        <table>
            <tr>
                <td class="etiqueta">Proveedor</td>
                <td><strong>{{ $compra->proveedor?->razon_social ?? 'Sin proveedor' }}</strong></td>
                <td class="etiqueta">Documento</td>
                <td>{{ $compra->serie_numero ?? '—' }}</td>
            </tr>
            <tr>
                <td class="etiqueta">RUC proveedor</td>
                <td>{{ $compra->proveedor?->ruc ? trim($compra->proveedor->ruc) : '—' }}</td>
                <td class="etiqueta">Condición</td>
                <td>{{ $compra->es_credito ? 'Crédito' : 'Contado' }}</td>
            </tr>
            <tr>
                <td class="etiqueta">Sucursal</td>
                <td>{{ $compra->sucursal?->nombre ?? '—' }}</td>
                <td class="etiqueta">Registrada por</td>
                <td>{{ $compra->usuario?->nombre_completo ?? '—' }}</td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="cant">Cant.</th>
                <th>Producto</th>
                <th class="num">Costo unit.</th>
                <th class="num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($compra->detalles as $detalle)
                <tr>
                    <td class="cant">{{ rtrim(rtrim(number_format($detalle->cantidad, 3), '0'), '.') }}</td>
                    <td>
                        {{ $detalle->producto?->nombre ?? '—' }}
                        @if ($detalle->presentacion && $detalle->presentacion->nombre !== 'Unidad')
                            <span class="presentacion">({{ $detalle->presentacion->nombre }})</span>
                        @endif
                    </td>
                    <td class="num">S/ {{ number_format($detalle->costo_unitario, 2) }}</td>
                    <td class="num">S/ {{ number_format($detalle->total, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-fila">
                <td colspan="2"></td>
                <td class="total-etiqueta">TOTAL</td>
                <td class="total-valor">S/ {{ number_format($compra->total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="pie">
        Documento interno de control de compras · generado el {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
