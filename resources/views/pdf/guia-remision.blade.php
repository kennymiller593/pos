<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #1c1917; }
        table { border-collapse: collapse; width: 100%; }

        .encabezado td { vertical-align: top; }
        .empresa-nombre { font-size: 16px; font-weight: 700; }
        .empresa-datos { margin-top: 3px; color: #44403c; line-height: 1.5; }

        .cuadro-doc { width: 230px; border: 2px solid #1c1917; border-radius: 6px; text-align: center; padding: 10px 8px; }
        .cuadro-doc .ruc { font-size: 12px; font-weight: 700; }
        .cuadro-doc .tipo { font-size: 11px; font-weight: 700; margin: 6px 0; padding: 5px 4px; background: #1c1917; color: #fff; border-radius: 4px; line-height: 1.3; }
        .cuadro-doc .numero { font-size: 13px; font-weight: 700; letter-spacing: 1px; }

        .seccion { margin-top: 12px; }
        .caja { border: 1px solid #d6d3d1; border-radius: 6px; padding: 8px 10px; }
        .caja td { padding: 2px 4px; vertical-align: top; }
        .titulo { font-size: 9.5px; font-weight: 700; color: #1c1917; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
        .etiqueta { color: #78716c; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

        .items th { background: #f5f5f4; border: 1px solid #d6d3d1; padding: 6px 8px; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #57534e; }
        .items td { border: 1px solid #e7e5e4; padding: 5px 8px; }
        .centrado { text-align: center; }
        .num { text-align: right; white-space: nowrap; }

        .leyenda { font-size: 9px; color: #78716c; line-height: 1.5; }
        .hash { font-size: 9px; color: #57534e; word-break: break-all; margin-bottom: 4px; }
        .aviso { margin-top: 12px; border: 3px solid #dc2626; color: #dc2626; text-align: center; font-size: 15px; font-weight: 700; letter-spacing: 3px; padding: 8px; border-radius: 6px; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 60px; max-width: 180px; margin-bottom: 6px;">
                @endif
                <div class="empresa-nombre">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
                <div class="empresa-datos">
                    {{ $empresa->razon_social }}<br>
                    @if ($guia->sucursal?->direccion)
                        {{ $guia->sucursal->direccion }}<br>
                    @endif
                </div>
            </td>
            <td style="width: 240px; text-align: right;">
                <div class="cuadro-doc">
                    <div class="ruc">RUC {{ $empresa->ruc }}</div>
                    <div class="tipo">GUÍA DE REMISIÓN ELECTRÓNICA<br>REMITENTE</div>
                    <div class="numero">{{ $guia->numero() }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if ($guia->estado === 'anulada')
        <div class="aviso">ANULADA</div>
    @elseif ($guia->estado_sunat === 'rechazado')
        <div class="aviso">RECHAZADA POR SUNAT · SIN VALIDEZ</div>
    @elseif (! in_array($guia->estado_sunat, ['aceptado', 'observado'], true))
        <div class="aviso">AÚN NO ACEPTADA POR SUNAT</div>
    @elseif ($empresa->entorno_sunat !== 'produccion')
        <div class="aviso">AMBIENTE DE PRUEBAS · SIN VALIDEZ</div>
    @endif

    {{-- Destinatario y datos del traslado --}}
    <div class="seccion caja">
        <table>
            <tr>
                <td class="etiqueta" style="width: 95px;">Destinatario</td>
                <td>{{ $guia->destinatario_nombre }}</td>
                <td class="etiqueta" style="width: 125px;">Fecha de emisión</td>
                <td style="width: 120px;">{{ $guia->fecha_emision->format('d/m/Y') }} {{ substr((string) $guia->hora_emision, 0, 5) }}</td>
            </tr>
            <tr>
                <td class="etiqueta">{{ trim($guia->destinatario_tipo_doc) === '6' ? 'RUC' : 'Documento' }}</td>
                <td>{{ $guia->destinatario_numero_doc }}</td>
                <td class="etiqueta">Inicio del traslado</td>
                <td><strong>{{ $guia->fecha_traslado->format('d/m/Y') }}</strong></td>
            </tr>
            <tr>
                <td class="etiqueta">Motivo</td>
                <td>
                    {{ \App\Models\GuiaRemision::MOTIVOS[trim($guia->motivo_codigo)] ?? 'Otros' }}@if ($guia->motivo_descripcion): {{ $guia->motivo_descripcion }}@endif
                </td>
                <td class="etiqueta">Peso bruto total</td>
                <td>{{ rtrim(rtrim(number_format($guia->peso_bruto, 3), '0'), '.') }} KGM @if ($guia->bultos) · {{ $guia->bultos }} bulto(s) @endif</td>
            </tr>
            @if ($guia->comprobante)
                <tr>
                    <td class="etiqueta">Doc. relacionado</td>
                    <td colspan="3">
                        {{ ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta'][$guia->comprobante->tipo_comprobante_codigo] ?? 'Comprobante' }}
                        {{ $guia->comprobante->serie }}-{{ str_pad($guia->comprobante->correlativo, 6, '0', STR_PAD_LEFT) }}
                    </td>
                </tr>
            @endif
        </table>
    </div>

    {{-- Ruta --}}
    <table class="seccion">
        <tr>
            <td style="width: 50%; padding-right: 6px; vertical-align: top;">
                <div class="caja">
                    <div class="titulo">Punto de partida</div>
                    {{ $guia->partida_direccion }}<br>
                    <span style="color: #57534e;">{{ $partidaLugar }}</span>
                </div>
            </td>
            <td style="width: 50%; padding-left: 6px; vertical-align: top;">
                <div class="caja">
                    <div class="titulo">Punto de llegada</div>
                    {{ $guia->llegada_direccion }}<br>
                    <span style="color: #57534e;">{{ $llegadaLugar }}</span>
                </div>
            </td>
        </tr>
    </table>

    {{-- Transporte --}}
    <div class="seccion caja">
        <div class="titulo">Transporte {{ trim($guia->modalidad) === '01' ? 'público' : 'privado' }}</div>
        <table>
            @if (trim($guia->modalidad) === '01')
                <tr>
                    <td class="etiqueta" style="width: 95px;">Transportista</td>
                    <td>{{ $guia->transportista_nombre }}</td>
                    <td class="etiqueta" style="width: 125px;">RUC</td>
                    <td style="width: 120px;">{{ $guia->transportista_ruc }}</td>
                </tr>
                @if ($guia->transportista_mtc)
                    <tr>
                        <td class="etiqueta">Registro MTC</td>
                        <td colspan="3">{{ $guia->transportista_mtc }}</td>
                    </tr>
                @endif
            @else
                <tr>
                    <td class="etiqueta" style="width: 95px;">Vehículo</td>
                    <td>
                        {{ $guia->vehiculo_placa ? 'Placa '.$guia->vehiculo_placa : '—' }}
                        @if ($guia->vehiculo_menor) · Vehículo menor (categoría M1 o L) @endif
                    </td>
                    <td class="etiqueta" style="width: 125px;">Licencia</td>
                    <td style="width: 120px;">{{ $guia->conductor_licencia ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Conductor</td>
                    <td>{{ $guia->conductor_numero_doc ? trim($guia->conductor_nombres.' '.$guia->conductor_apellidos) : '—' }}</td>
                    <td class="etiqueta">Documento</td>
                    <td>{{ $guia->conductor_numero_doc ?? '—' }}</td>
                </tr>
            @endif
        </table>
    </div>

    {{-- Bienes --}}
    <div class="seccion">
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 90px;">Código</th>
                    <th>Descripción</th>
                    <th style="width: 60px;">Unidad</th>
                    <th style="width: 80px;">Cantidad</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($guia->detalles as $i => $detalle)
                    <tr>
                        <td class="centrado">{{ $i + 1 }}</td>
                        <td>{{ $detalle->codigo }}</td>
                        <td>{{ $detalle->descripcion }}</td>
                        <td class="centrado">{{ trim((string) $detalle->unidad_codigo) ?: 'NIU' }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format($detalle->cantidad, 3), '0'), '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($guia->observaciones)
        <div class="seccion caja">
            <div class="titulo">Observaciones</div>
            {{ $guia->observaciones }}
        </div>
    @endif

    {{-- QR de consulta (lo entrega SUNAT al aceptar la guía) --}}
    <table class="seccion">
        <tr>
            @if ($qr)
                <td style="width: 100px;"><img src="{{ $qr }}" style="width: 90px; height: 90px;"></td>
            @endif
            <td style="{{ $qr ? 'padding-left: 10px;' : '' }} vertical-align: middle;">
                @if ($guia->hash_cpe)
                    <div class="hash"><strong>Hash:</strong> {{ $guia->hash_cpe }}</div>
                @endif
                <div class="leyenda">
                    Representación impresa de la Guía de Remisión Electrónica Remitente.
                    @if ($qr) Escanea el código para verificarla en SUNAT. @endif
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
