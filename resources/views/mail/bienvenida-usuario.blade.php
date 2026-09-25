<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0; padding:0; background:#f5f5f4; font-family:'Segoe UI', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:440px; background:#ffffff; border-radius:16px; padding:32px; border:1px solid #e7e5e4;">
                    <tr>
                        <td style="padding-bottom:20px;">
                            <span style="display:inline-block; background:#059669; color:#ffffff; font-weight:700; font-size:16px; border-radius:10px; padding:8px 12px;">{{ config('app.name') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:20px; font-weight:700; color:#171717; padding-bottom:8px;">
                            ¡Hola, {{ $nombre }}!
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px; color:#525252; line-height:1.6; padding-bottom:20px;">
                            {{ $creadoPor }} te creó una cuenta en <strong style="color:#171717;">{{ $empresa }}</strong>@if ($rol) con el rol de <strong style="color:#171717;">{{ $rol }}</strong>@endif.
                            Estos son tus datos para ingresar:
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-bottom:24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4; border-radius:12px; padding:16px;">
                                <tr>
                                    <td style="font-size:12px; color:#737373; padding-bottom:2px;">Correo</td>
                                </tr>
                                <tr>
                                    <td style="font-size:15px; font-weight:600; color:#171717; padding-bottom:12px; word-break:break-all;">{{ $email }}</td>
                                </tr>
                                <tr>
                                    <td style="font-size:12px; color:#737373; padding-bottom:2px;">Contraseña</td>
                                </tr>
                                <tr>
                                    <td style="font-size:15px; font-weight:600; color:#171717; font-family:Consolas, 'Courier New', monospace; word-break:break-all;">{{ $password }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-bottom:24px;">
                            <a href="{{ $urlLogin }}" style="display:inline-block; background:#059669; color:#ffffff; text-decoration:none; font-weight:600; font-size:14px; border-radius:12px; padding:12px 24px;">
                                Ingresar a {{ config('app.name') }}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:12px; color:#a3a3a3; line-height:1.6;">
                            Por seguridad, no compartas este correo. Puedes cambiar tu contraseña cuando quieras con
                            «¿La olvidaste?» en la pantalla de ingreso.
                            <br><br>
                            Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                            <a href="{{ $urlLogin }}" style="color:#059669; word-break:break-all;">{{ $urlLogin }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
