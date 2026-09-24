<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0; padding:0; background:#f5f5f4; font-family:'Segoe UI', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background:#ffffff; border-radius:16px; padding:32px; border:1px solid #e7e5e4;">
                    <tr>
                        <td style="padding-bottom:20px;">
                            <span style="display:inline-block; background:#059669; color:#ffffff; font-weight:700; font-size:16px; border-radius:10px; padding:8px 12px;">
                                {{ $empresa->nombre_comercial ?: $empresa->razon_social }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:20px; font-weight:700; color:#171717; padding-bottom:8px;">
                            {{ $tipo }} {{ $numero }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px; color:#525252; line-height:1.6; padding-bottom:20px;">
                            Hola{{ $comprobante->cliente_nombre ? ' ' . $comprobante->cliente_nombre : '' }}, adjuntamos tu comprobante en PDF
                            @if ($comprobante->sunat?->xml_url)
                                y el XML firmado que fue enviado a SUNAT
                            @endif
                            .
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-bottom:20px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4; border-radius:12px; padding:16px; font-size:14px; color:#171717;">
                                <tr>
                                    <td style="color:#737373; padding:4px 0;">Fecha</td>
                                    <td align="right" style="padding:4px 0;">{{ $comprobante->fecha_emision->format('d/m/Y') }}</td>
                                </tr>
                                <tr>
                                    <td style="color:#737373; padding:4px 0;">Emisor</td>
                                    <td align="right" style="padding:4px 0;">{{ $empresa->razon_social }} · RUC {{ $empresa->ruc }}</td>
                                </tr>
                                <tr>
                                    <td style="color:#737373; padding:4px 0; font-weight:700;">Total</td>
                                    <td align="right" style="padding:4px 0; font-weight:700;">S/ {{ number_format((float) $comprobante->total, 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:12px; color:#a3a3a3; line-height:1.6;">
                            Este correo se generó automáticamente desde el sistema de ventas de {{ $empresa->razon_social }}.
                            @if (in_array($comprobante->tipo_comprobante_codigo, ['01', '03', '07'], true))
                                Puedes verificar el comprobante en <a href="https://www.sunat.gob.pe" style="color:#059669;">sunat.gob.pe</a>.
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
