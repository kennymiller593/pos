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
                            Cotización {{ $cotizacion->codigo() }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px; color:#525252; line-height:1.6; padding-bottom:20px;">
                            Hola{{ $cotizacion->cliente_nombre ? ' ' . $cotizacion->cliente_nombre : '' }}, adjuntamos la cotización que nos pediste en PDF.
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-bottom:20px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4; border-radius:12px; padding:16px; font-size:14px; color:#171717;">
                                <tr>
                                    <td style="color:#737373; padding:4px 0;">Fecha</td>
                                    <td align="right" style="padding:4px 0;">{{ $cotizacion->fecha_emision->format('d/m/Y') }}</td>
                                </tr>
                                <tr>
                                    <td style="color:#737373; padding:4px 0;">Válida hasta</td>
                                    <td align="right" style="padding:4px 0;">{{ $cotizacion->valida_hasta->format('d/m/Y') }}</td>
                                </tr>
                                <tr>
                                    <td style="color:#737373; padding:4px 0; font-weight:700;">Total</td>
                                    <td align="right" style="padding:4px 0; font-weight:700;">S/ {{ number_format((float) $cotizacion->total, 2) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:12px; color:#a3a3a3; line-height:1.6;">
                            Este correo se generó automáticamente desde el sistema de ventas de {{ $empresa->razon_social }}.
                            Los precios se mantienen hasta la fecha de validez indicada.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
