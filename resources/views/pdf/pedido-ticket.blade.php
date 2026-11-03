<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $pedido->codigo() }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Consolas', 'Courier New', monospace; font-size: {{ $ancho === 58 ? '8.5px' : '9.5px' }}; color: #000; }
        .centro { text-align: center; }
        .negrita { font-weight: 700; }
        .titulo { font-size: 11px; font-weight: 700; }
        .grande { font-size: 12px; font-weight: 700; }
        .separador { border-top: 1px dashed #000; margin: 6px 0; }
        .fila { display: table; width: 100%; }
        .fila .izq { display: table-cell; text-align: left; }
        .fila .der { display: table-cell; text-align: right; white-space: nowrap; }
        .item { margin-top: 3px; }
        .sub { padding-left: 6px; }
        .recuadro { border: 1px solid #000; padding: 4px; margin-top: 6px; }
        .pie { margin-top: 10px; text-align: center; font-size: 8.5px; }
        @if (!empty($imprimirDirecto))
            /* impresion directa desde el navegador: el papel es del ancho de la caja */
            @page { size: {{ $ancho }}mm auto; margin: 3mm 4mm; }
            html, body { width: {{ $ancho - 8 }}mm; }
            @media screen { body { margin: 8mm auto; } }
        @endif
    </style>
    @if (!empty($imprimirDirecto))
        <script>
            // se imprime apenas carga; con Chrome en modo --kiosk-printing sale sin dialogo
            window.addEventListener('load', function () {
                window.print();
                window.addEventListener('afterprint', function () { window.close(); });
            });
        </script>
    @endif
</head>
<body>
    @php
        $s = fn ($n) => 'S/ '.number_format((float) $n, 2);
        $cant = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    @endphp

    <div class="centro">
        @if ($logo)
            <img src="{{ $logo }}" style="max-width: {{ $ancho === 58 ? '40mm' : '56mm' }}; max-height: {{ $ancho === 58 ? '24mm' : '30mm' }}; margin-bottom: 4px;">
        @endif
        <div class="titulo">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</div>
        @if ($pedido->sucursal)
            <div>{{ $pedido->sucursal->nombre }}{{ $pedido->sucursal->direccion ? ' · '.$pedido->sucursal->direccion : '' }}</div>
        @endif
        <div class="grande" style="margin-top: 5px;">ORDEN DE PEDIDO</div>
        <div class="grande">{{ $pedido->codigo() }}</div>
    </div>

    <div class="separador"></div>
    <div>Cliente: {{ $pedido->cliente_nombre }}</div>
    @if ($pedido->cliente_numero_doc)
        <div>Doc.: {{ $pedido->cliente_numero_doc }}</div>
    @endif
    @if ($pedido->cliente_telefono)
        <div>Cel.: {{ $pedido->cliente_telefono }}</div>
    @endif
    <div>Pedido el: {{ $pedido->fecha_pedido->format('d/m/Y') }}{{ $pedido->usuario ? ' · '.$pedido->usuario->nombre_completo : '' }}</div>
    <div class="negrita">ENTREGA: {{ $pedido->fecha_entrega->format('d/m/Y') }}{{ $pedido->horaEntrega() ? ' '.$pedido->horaEntrega() : '' }}</div>
    <div>Estado: {{ ['pendiente' => 'Pendiente de alistar', 'listo' => 'Listo para entregar', 'entregado' => 'Entregado', 'anulado' => 'ANULADO'][$pedido->estado] ?? $pedido->estado }}</div>

    <div class="separador"></div>
    <div class="negrita">PRODUCTOS A ALISTAR</div>
    @foreach ($pedido->detalles as $d)
        <div class="item">
            <div>{{ $d->descripcion }}</div>
            <div class="fila sub">
                <span class="izq">{{ $cant($d->cantidad) }} x {{ $s($d->precio_unitario) }}{{ (float) $d->descuento > 0 ? ' (dto. '.$s($d->descuento).')' : '' }}</span>
                <span class="der">{{ $s($d->total) }}</span>
            </div>
        </div>
    @endforeach

    <div class="separador"></div>
    <div class="fila negrita"><span class="izq">TOTAL</span><span class="der">{{ $s($pedido->total) }}</span></div>
    @if ($pedido->adelantos->isNotEmpty())
        @foreach ($pedido->adelantos as $a)
            <div class="fila sub">
                <span class="izq">Adelanto {{ $a->creado_en->format('d/m') }} ({{ $a->medioPago?->nombre ?? $a->medio_pago_codigo }})</span>
                <span class="der">-{{ $s($a->monto) }}</span>
            </div>
        @endforeach
    @endif
    <div class="recuadro">
        <div class="fila grande"><span class="izq">SALDO A COBRAR</span><span class="der">{{ $s($saldo) }}</span></div>
        @if ($adelantos > 0 && $saldo <= 0.005)
            <div class="centro">Pagado por completo con el adelanto</div>
        @endif
    </div>

    @if (filled($pedido->observaciones))
        <div class="separador"></div>
        <div class="negrita">OBSERVACIONES</div>
        <div>{{ $pedido->observaciones }}</div>
    @endif

    <div class="pie">
        Documento interno: no es comprobante de pago.<br>
        La boleta o nota de venta se emite al entregar.
    </div>
</body>
</html>
