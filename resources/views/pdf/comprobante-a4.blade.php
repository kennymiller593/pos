<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #1c1917; }
        table { border-collapse: collapse; width: 100%; }

        .encabezado { width: 100%; }
        .encabezado td { vertical-align: top; }
        .empresa-nombre { font-size: 16px; font-weight: 700; }
        .empresa-datos { margin-top: 3px; color: #44403c; line-height: 1.5; }

        .cuadro-doc {
            width: 220px; border: 2px solid #1c1917; border-radius: 6px;
            text-align: center; padding: 10px 8px;
        }
        .cuadro-doc .ruc { font-size: 12px; font-weight: 700; }
        .cuadro-doc .tipo { font-size: 12px; font-weight: 700; margin: 6px 0; padding: 5px 0; background: #1c1917; color: #fff; border-radius: 4px; }
        .cuadro-doc .numero { font-size: 13px; font-weight: 700; letter-spacing: 1px; }

        .seccion { margin-top: 14px; }
        .caja-cliente { border: 1px solid #d6d3d1; border-radius: 6px; padding: 8px 10px; }
        .caja-cliente td { padding: 2px 4px; vertical-align: top; }
        .etiqueta { color: #78716c; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

        .items th {
            background: #f5f5f4; border: 1px solid #d6d3d1; padding: 6px 8px;
            font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #57534e;
        }
        .items td { border: 1px solid #e7e5e4; padding: 5px 8px; }
        .num { text-align: right; white-space: nowrap; }
        .centrado { text-align: center; }

        .letras { margin-top: 10px; border: 1px solid #d6d3d1; border-radius: 6px; padding: 6px 10px; font-size: 10px; }

        .totales td { padding: 3px 8px; }
        .totales .fila-total td { border-top: 2px solid #1c1917; font-size: 13px; font-weight: 700; padding-top: 6px; }

        .pie-electronico { margin-top: 16px; }
        .pie-electronico td { vertical-align: top; }
        .hash { font-size: 9px; color: #57534e; word-break: break-all; margin-top: 4px; }
        .leyenda { font-size: 9px; color: #78716c; margin-top: 4px; line-height: 1.5; }

        .anulado {
            margin-top: 14px; border: 3px solid #dc2626; color: #dc2626; text-align: center;
            font-size: 20px; font-weight: 700; letter-spacing: 8px; padding: 8px; border-radius: 6px;
        }
        .ref-nota { margin-top: 8px; font-size: 11px; }
    </style>
</head>
<body>
    {{-- Encabezado: datos del emisor + cuadro del documento --}}
    <table class="encabezado">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 60px; max-width: 180px; margin-bottom: 6px;">
                @endif
                <div class="empresa-nombre">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
                <div class="empresa-datos">
                    {{ $empresa->razon_social }}<br>
                    @if ($comprobante->sucursal?->direccion)
                        {{ $comprobante->sucursal->direccion }}<br>
                    @endif
                </div>
            </td>
            <td style="width: 230px; text-align: right;">
                <div class="cuadro-doc">
                    <div class="ruc">RUC {{ $empresa->ruc }}</div>
                    <div class="tipo">
                        {{ ['00' => 'NOTA DE VENTA', '01' => 'FACTURA ELECTRÓNICA', '03' => 'BOLETA DE VENTA ELECTRÓNICA', '07' => 'NOTA DE CRÉDITO ELECTRÓNICA'][$comprobante->tipo_comprobante_codigo] ?? 'COMPROBANTE' }}
                    </div>
                    <div class="numero">{{ $numero }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if ($comprobante->estado === 'anulado')
        <div class="anulado">ANULADO</div>
    @elseif ($comprobante->sunat?->estado === 'rechazado')
        <div class="anulado">RECHAZADO POR SUNAT · SIN VALIDEZ TRIBUTARIA</div>
    @elseif ($comprobante->tipo_comprobante_codigo !== '00' && $empresa->entorno_sunat !== 'produccion')
        <div class="anulado">AMBIENTE DE PRUEBAS SUNAT · SIN VALOR TRIBUTARIO</div>
    @endif

    @if ($comprobante->comprobanteRef)
        <div class="ref-nota">
            <span class="etiqueta">Documento que modifica:</span>
            <strong>
                {{ ['01' => 'Factura', '03' => 'Boleta'][$comprobante->comprobanteRef->tipo_comprobante_codigo] ?? '' }}
                {{ $comprobante->comprobanteRef->serie }}-{{ str_pad($comprobante->comprobanteRef->correlativo, 6, '0', STR_PAD_LEFT) }}
            </strong>
            @if ($motivoNota)
                &nbsp;·&nbsp; <span class="etiqueta">Motivo:</span> <strong>{{ $motivoNota }}</strong>
            @endif
        </div>
    @endif

    {{-- Cliente y datos de emision --}}
    <div class="seccion caja-cliente">
        <table>
            <tr>
                <td class="etiqueta" style="width: 90px;">Señor(es)</td>
                <td>{{ $comprobante->cliente_nombre ?? 'Público general' }}</td>
                <td class="etiqueta" style="width: 110px;">Fecha de emisión</td>
                <td style="width: 120px;">{{ $comprobante->fecha_emision->format('d/m/Y') }} {{ substr($comprobante->hora_emision, 0, 5) }}</td>
            </tr>
            <tr>
                <td class="etiqueta">{{ trim((string) $comprobante->cliente_tipo_doc) === '6' ? 'RUC' : 'Documento' }}</td>
                <td>{{ $comprobante->cliente_numero_doc ?? '—' }}</td>
                <td class="etiqueta">Moneda</td>
                <td>Soles (PEN)</td>
            </tr>
            <tr>
                <td class="etiqueta">Dirección</td>
                <td>{{ $comprobante->cliente_direccion ?? '—' }}</td>
                <td class="etiqueta">Condición</td>
                <td>
                    @if ($comprobante->es_credito)
                        Crédito{{ $comprobante->fecha_vencimiento ? ' — vence ' . $comprobante->fecha_vencimiento->format('d/m/Y') : '' }}
                    @else
                        Contado
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Items --}}
    <div class="seccion">
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 55px;">Cant.</th>
                    <th style="width: 50px;">Unidad</th>
                    <th>Descripción</th>
                    <th style="width: 75px;">P. Unit.</th>
                    <th style="width: 65px;">Dscto.</th>
                    <th style="width: 85px;">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($comprobante->detalles as $detalle)
                    <tr>
                        <td class="centrado">{{ rtrim(rtrim(number_format($detalle->cantidad, 3), '0'), '.') }}</td>
                        <td class="centrado">{{ trim((string) $detalle->unidad_codigo) ?: 'NIU' }}</td>
                        <td>{{ $detalle->descripcion }}</td>
                        <td class="num">{{ number_format($detalle->precio_unitario, 2) }}</td>
                        <td class="num">{{ (float) $detalle->descuento > 0 ? number_format($detalle->descuento, 2) : '—' }}</td>
                        <td class="num">{{ number_format($detalle->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Importe en letras --}}
    <div class="letras"><strong>SON:</strong> {{ $letras }}</div>

    {{-- Totales --}}
    <table style="margin-top: 8px;">
        <tr>
            <td></td>
            <td style="width: 240px;">
                <table class="totales">
                    @if ((float) $comprobante->total_gravado > 0)
                        <tr><td class="etiqueta">Op. gravada</td><td class="num">S/ {{ number_format($comprobante->total_gravado, 2) }}</td></tr>
                    @endif
                    @if ((float) $comprobante->total_exonerado > 0)
                        <tr><td class="etiqueta">Op. exonerada</td><td class="num">S/ {{ number_format($comprobante->total_exonerado, 2) }}</td></tr>
                    @endif
                    @if ((float) $comprobante->total_inafecto > 0)
                        <tr><td class="etiqueta">Op. inafecta</td><td class="num">S/ {{ number_format($comprobante->total_inafecto, 2) }}</td></tr>
                    @endif
                    @if ((float) $comprobante->total_descuentos > 0)
                        <tr><td class="etiqueta">Descuentos</td><td class="num">-S/ {{ number_format($comprobante->total_descuentos, 2) }}</td></tr>
                    @endif
                    <tr><td class="etiqueta">IGV (18%)</td><td class="num">S/ {{ number_format($comprobante->total_igv, 2) }}</td></tr>
                    <tr class="fila-total"><td>TOTAL</td><td class="num">S/ {{ number_format($comprobante->total, 2) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Pagos --}}
    @if (! $comprobante->es_credito && $comprobante->pagos->isNotEmpty())
        <div class="seccion" style="font-size: 10px; color: #57534e;">
            <span class="etiqueta">Forma de pago:</span>
            {{ $comprobante->pagos->map(fn ($p) => ($p->medioPago?->nombre ?? $p->medio_pago_codigo) . ' S/ ' . number_format($p->monto, 2))->implode(' · ') }}
        </div>
    @endif

    {{-- QR + hash (solo comprobantes electronicos) --}}
    @if (!empty($qr))
        <table class="pie-electronico">
            <tr>
                <td style="width: 100px;">
                    <img src="{{ $qr }}" style="width: 90px; height: 90px;">
                </td>
                <td style="padding-left: 10px; padding-top: 8px;">
                    @if (!empty($hash))
                        <div class="hash"><strong>Hash:</strong> {{ $hash }}</div>
                    @endif
                    <div class="leyenda">
                        Representación impresa de la
                        {{ ['01' => 'Factura', '03' => 'Boleta de Venta', '07' => 'Nota de Crédito'][$comprobante->tipo_comprobante_codigo] ?? 'Comprobante' }} Electrónica.
                        <br>Consulte su comprobante en www.sunat.gob.pe
                    </div>
                </td>
            </tr>
        </table>
    @endif
</body>
</html>
