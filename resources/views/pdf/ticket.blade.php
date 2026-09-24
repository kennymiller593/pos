<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: {{ $ancho === 58 ? '8.5px' : '9.5px' }};
            color: #000;
        }
        .centro { text-align: center; }
        .negrita { font-weight: 700; }
        .separador { border-top: 1px dashed #000; margin: 6px 0; }
        .titulo { font-size: 11px; font-weight: 700; }
        .doc { font-size: 10.5px; font-weight: 700; margin-top: 4px; }
        .fila { display: table; width: 100%; }
        .fila .izq { display: table-cell; text-align: left; }
        .fila .der { display: table-cell; text-align: right; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items td { padding: 1.5px 0; vertical-align: top; }
        .cant { width: 12%; }
        .precio { width: 20%; text-align: right; }
        .importe { width: 22%; text-align: right; }
        .totales { margin-top: 4px; }
        .total-final { font-size: 12px; font-weight: 700; }
        .anulado {
            border: 2px solid #000; text-align: center; font-size: 13px; font-weight: 700;
            padding: 4px; margin: 6px 0; letter-spacing: 3px;
        }
        .pie { margin-top: 8px; text-align: center; font-size: 8.5px; }
    </style>
</head>
<body>
    {{-- Encabezado --}}
    <div class="centro">
        @if ($logo)
            <img src="{{ $logo }}" style="max-width: {{ $ancho === 58 ? '26mm' : '34mm' }}; max-height: 16mm; margin-bottom: 4px;">
        @endif
        <div class="titulo">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
        <div>{{ $empresa->razon_social }}</div>
        <div>RUC {{ $empresa->ruc }}</div>
        @if ($comprobante->sucursal?->direccion)
            <div>{{ $comprobante->sucursal->direccion }}</div>
        @endif
        <div class="doc">
            {{ ['00' => 'NOTA DE VENTA', '01' => 'FACTURA', '03' => 'BOLETA DE VENTA', '07' => 'NOTA DE CRÉDITO'][$comprobante->tipo_comprobante_codigo] ?? 'COMPROBANTE' }}
        </div>
        <div class="negrita">{{ $numero }}</div>
        @if ($comprobante->comprobanteRef)
            <div>
                Modifica: {{ ['01' => 'FACTURA', '03' => 'BOLETA'][$comprobante->comprobanteRef->tipo_comprobante_codigo] ?? '' }}
                {{ $comprobante->comprobanteRef->serie }}-{{ str_pad($comprobante->comprobanteRef->correlativo, 6, '0', STR_PAD_LEFT) }}
            </div>
        @endif
    </div>

    @if ($comprobante->estado === 'anulado')
        <div class="anulado">ANULADO</div>
    @endif

    <div class="separador"></div>

    {{-- Datos --}}
    <div class="fila">
        <span class="izq">Fecha: {{ $comprobante->fecha_emision->format('d/m/Y') }} {{ substr($comprobante->hora_emision, 0, 5) }}</span>
    </div>
    <div>Cajero: {{ $comprobante->usuario?->nombre_completo ?? '—' }}</div>
    @if ($comprobante->cliente_nombre)
        <div>Cliente: {{ $comprobante->cliente_nombre }}</div>
        @if ($comprobante->cliente_numero_doc)
            <div>Doc: {{ $comprobante->cliente_numero_doc }}</div>
        @endif
    @endif

    <div class="separador"></div>

    {{-- Items --}}
    <table class="items">
        @foreach ($comprobante->detalles as $detalle)
            <tr>
                <td class="cant">{{ rtrim(rtrim(number_format($detalle->cantidad, 3), '0'), '.') }}</td>
                <td>{{ $detalle->descripcion }}</td>
                <td class="precio">{{ number_format($detalle->precio_unitario, 2) }}</td>
                <td class="importe">{{ number_format($detalle->total, 2) }}</td>
            </tr>
            @if ((float) $detalle->descuento > 0)
                <tr>
                    <td></td>
                    <td colspan="2">DSCTO</td>
                    <td class="importe">-{{ number_format($detalle->descuento, 2) }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <div class="separador"></div>

    {{-- Totales --}}
    <div class="totales">
        @if ((float) $comprobante->total_descuentos > 0)
            <div class="fila"><span class="izq">DESCUENTOS</span><span class="der">-S/ {{ number_format($comprobante->total_descuentos, 2) }}</span></div>
        @endif
        @if ((float) $comprobante->total_gravado > 0)
            <div class="fila"><span class="izq">OP. GRAVADA</span><span class="der">S/ {{ number_format($comprobante->total_gravado, 2) }}</span></div>
            <div class="fila"><span class="izq">IGV (18%)</span><span class="der">S/ {{ number_format($comprobante->total_igv, 2) }}</span></div>
        @endif
        @if ((float) $comprobante->total_exonerado > 0)
            <div class="fila"><span class="izq">OP. EXONERADA</span><span class="der">S/ {{ number_format($comprobante->total_exonerado, 2) }}</span></div>
        @endif
        @if ((float) $comprobante->total_inafecto > 0)
            <div class="fila"><span class="izq">OP. INAFECTA</span><span class="der">S/ {{ number_format($comprobante->total_inafecto, 2) }}</span></div>
        @endif
        <div class="fila total-final"><span class="izq">TOTAL</span><span class="der">S/ {{ number_format($comprobante->total, 2) }}</span></div>
    </div>

    {{-- Pagos --}}
    @if ($comprobante->es_credito)
        <div class="separador"></div>
        <div class="centro negrita">VENTA AL CRÉDITO</div>
    @elseif ($comprobante->pagos->isNotEmpty())
        <div class="separador"></div>
        @foreach ($comprobante->pagos as $pago)
            <div class="fila">
                <span class="izq">{{ $pago->medioPago?->nombre ?? $pago->medio_pago_codigo }}</span>
                <span class="der">S/ {{ number_format($pago->monto, 2) }}</span>
            </div>
        @endforeach
    @endif

    {{-- QR y hash del comprobante electronico --}}
    @if (!empty($qr))
        <div class="separador"></div>
        <div class="centro">
            <img src="{{ $qr }}" style="width: {{ $ancho === 58 ? '20mm' : '24mm' }}; height: {{ $ancho === 58 ? '20mm' : '24mm' }};">
            @if (!empty($hash))
                <div style="font-size: 7.5px; word-break: break-all;">Hash: {{ $hash }}</div>
            @endif
            <div style="font-size: 7.5px; margin-top: 2px;">
                Representación impresa de la
                {{ ['01' => 'Factura', '03' => 'Boleta de Venta', '07' => 'Nota de Crédito'][$comprobante->tipo_comprobante_codigo] ?? 'Comprobante' }} Electrónica.
                <br>Consulte su comprobante en www.sunat.gob.pe
            </div>
        </div>
    @endif

    <div class="pie">
        ¡Gracias por su compra!
        @if ($comprobante->tipo_comprobante_codigo === '00')
            <br>Documento interno — no es comprobante de pago
        @endif
    </div>
</body>
</html>
