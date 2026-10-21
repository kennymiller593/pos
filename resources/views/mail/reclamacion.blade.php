<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
</head>
@php
    $fila = fn (string $etiqueta, ?string $valor) => $valor === null || $valor === '' ? '' :
        '<tr><td style="color:#737373; padding:4px 12px 4px 0; vertical-align:top; white-space:nowrap;">'.e($etiqueta).'</td><td style="padding:4px 0;">'.nl2br(e($valor)).'</td></tr>';
    $tipo = $r->tipo === 'queja' ? 'Queja' : 'Reclamo';
@endphp
<body style="margin:0; padding:0; background:#f5f5f4; font-family:'Segoe UI', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:16px; padding:32px; border:1px solid #e7e5e4;">
                    <tr>
                        <td style="font-size:13px; color:#737373; padding-bottom:6px;">Libro de Reclamaciones · {{ $empresa->razon_social }} · RUC {{ $empresa->ruc }}</td>
                    </tr>
                    <tr>
                        <td style="font-size:20px; font-weight:700; color:#171717; padding-bottom:12px;">
                            @if ($para === 'respuesta') Respuesta a tu {{ mb_strtolower($tipo) }} N.º {{ $r->codigo() }} @else {{ $tipo }} N.º {{ $r->codigo() }} @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px; color:#525252; line-height:1.6; padding-bottom:20px;">
                            @if ($para === 'tienda')
                                Un cliente registró un {{ $r->tipo }} en el Libro de Reclamaciones de tu tienda en línea. Respóndelo desde inkaPos (Tienda en línea → Libro de Reclamaciones) a más tardar el <strong>{{ $r->venceEl()->format('d/m/Y') }}</strong>.
                            @elseif ($para === 'respuesta')
                                Hola {{ $r->nombre }}, esta es la respuesta de {{ $empresa->nombre_comercial ?: $empresa->razon_social }} a tu {{ $r->tipo }}:
                            @else
                                Hola {{ $r->nombre }}, registramos tu {{ $r->tipo }}. Esta es tu copia. Te responderemos a este correo a más tardar el <strong>{{ $r->venceEl()->format('d/m/Y') }}</strong>.
                            @endif
                        </td>
                    </tr>
                    @if ($para === 'respuesta')
                        <tr>
                            <td style="padding-bottom:20px;">
                                <div style="background:#ecfdf5; border-radius:12px; padding:16px; font-size:14px; color:#064e3b; line-height:1.6;">{!! nl2br(e($r->respuesta)) !!}</div>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4; border-radius:12px; padding:16px; font-size:14px; color:#171717;">
                                {!! $fila('Fecha', $r->creado_en->timezone('America/Lima')->format('d/m/Y H:i')) !!}
                                {!! $fila('Consumidor', $r->nombre) !!}
                                {!! $fila('Documento', "{$r->documento_tipo} {$r->documento_numero}") !!}
                                {!! $fila('Domicilio', $r->domicilio) !!}
                                {!! $fila('Correo', $r->email) !!}
                                {!! $fila('Teléfono', $r->telefono) !!}
                                {!! $fila('Apoderado', $r->menor_de_edad ? ($r->apoderado ?: '—') : null) !!}
                                {!! $fila('Bien', ucfirst($r->bien_tipo).': '.$r->bien_descripcion) !!}
                                {!! $fila('Monto', $r->monto !== null ? 'S/ '.number_format((float) $r->monto, 2) : null) !!}
                                {!! $fila('Tipo', $tipo) !!}
                                {!! $fila('Detalle', $r->detalle) !!}
                                {!! $fila('Pedido', $r->pedido) !!}
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:12px; color:#a3a3a3; line-height:1.5; padding-top:20px;">
                            La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
