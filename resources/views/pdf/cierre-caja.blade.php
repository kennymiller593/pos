<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Consolas', 'Courier New', monospace; font-size: {{ $ancho === 58 ? '8.5px' : '9.5px' }}; color: #000; }
        .centro { text-align: center; }
        .negrita { font-weight: 700; }
        .titulo { font-size: 11px; font-weight: 700; }
        .separador { border-top: 1px dashed #000; margin: 6px 0; }
        .fila { display: table; width: 100%; }
        .fila .izq { display: table-cell; text-align: left; }
        .fila .der { display: table-cell; text-align: right; }
        .medio { margin-top: 5px; }
        .sub { padding-left: 6px; }
        .alerta { font-weight: 700; }
        .pie { margin-top: 10px; text-align: center; font-size: 8.5px; }
        .firma { margin-top: 22px; border-top: 1px solid #000; width: 70%; margin-left: auto; margin-right: auto; padding-top: 2px; text-align: center; }
    </style>
</head>
<body>
    @php
        $s = fn ($n) => 'S/ '.number_format((float) $n, 2);
        $diferenciaTotal = $cierres->sum(fn ($c) => (float) $c->diferencia);
    @endphp

    <div class="centro">
        <div class="titulo">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</div>
        <div>RUC {{ $empresa->ruc }}</div>
        <div class="negrita" style="margin-top: 4px;">CIERRE DE CAJA</div>
    </div>

    <div class="separador"></div>
    <div>Caja: {{ $apertura->caja?->nombre }}{{ $apertura->caja?->sucursal ? ' · '.$apertura->caja->sucursal->nombre : '' }}</div>
    <div>Cajero: {{ $apertura->usuario?->nombre_completo }}</div>
    <div>Apertura: {{ $apertura->abierta_en?->format('d/m/Y H:i') }}</div>
    <div>Cierre: {{ $apertura->cerrada_en?->format('d/m/Y H:i') }}</div>
    <div>Ventas: {{ (int) $ventas->n }} comprobantes · {{ $s($ventas->total) }}</div>
    @if ($anuladas > 0)
        <div>Anulados en el turno: {{ $anuladas }}</div>
    @endif

    <div class="separador"></div>
    <div class="negrita">CUADRE POR MEDIO DE PAGO</div>

    @foreach ($medios as $m)
        @php $cierre = $cierres->get($m['codigo']); @endphp
        <div class="medio">
            <div class="negrita">{{ mb_strtoupper($m['nombre']) }}</div>
            @if ($m['inicial'] > 0)<div class="fila sub"><span class="izq">Inicial</span><span class="der">{{ $s($m['inicial']) }}</span></div>@endif
            @if ($m['ventas'] > 0)<div class="fila sub"><span class="izq">Ventas</span><span class="der">{{ $s($m['ventas']) }}</span></div>@endif
            @if ($m['cobros'] > 0)<div class="fila sub"><span class="izq">Cobros de crédito</span><span class="der">{{ $s($m['cobros']) }}</span></div>@endif
            @if ($m['ingresos'] > 0)<div class="fila sub"><span class="izq">Ingresos</span><span class="der">{{ $s($m['ingresos']) }}</span></div>@endif
            @if ($m['egresos'] > 0)<div class="fila sub"><span class="izq">Egresos / devoluciones</span><span class="der">-{{ $s($m['egresos']) }}</span></div>@endif
            @if ($m['pagos_proveedor'] > 0)<div class="fila sub"><span class="izq">Pagos a proveedores</span><span class="der">-{{ $s($m['pagos_proveedor']) }}</span></div>@endif
            <div class="fila sub"><span class="izq">Esperado</span><span class="der">{{ $s($cierre?->esperado ?? $m['esperado']) }}</span></div>
            <div class="fila sub">
                <span class="izq">{{ $m['codigo'] === 'efectivo' ? 'Contado' : 'Declarado' }}</span>
                <span class="der">{{ $cierre?->declarado !== null ? $s($cierre->declarado) : 'no verificado' }}</span>
            </div>
            @if ($cierre && abs((float) $cierre->diferencia) >= 0.005)
                <div class="fila sub alerta">
                    <span class="izq">{{ $cierre->diferencia > 0 ? 'SOBRA' : 'FALTA' }}</span>
                    <span class="der">{{ $s(abs($cierre->diferencia)) }}</span>
                </div>
            @endif
        </div>
    @endforeach

    @if (! empty($apertura->conteo_efectivo))
        <div class="separador"></div>
        <div class="negrita">CONTEO DE EFECTIVO</div>
        {{-- en orden de denominacion (la BD guarda el JSON con otro orden) --}}
        @foreach (collect(\App\Services\CajaService::DENOMINACIONES)->filter(fn ($d) => isset($apertura->conteo_efectivo[$d]))->mapWithKeys(fn ($d) => [$d => $apertura->conteo_efectivo[$d]]) as $valor => $cantidad)
            <div class="fila sub">
                <span class="izq">{{ $cantidad }} × S/ {{ number_format((float) $valor, 2) }}</span>
                <span class="der">{{ $s($cantidad * (float) $valor) }}</span>
            </div>
        @endforeach
    @endif

    <div class="separador"></div>
    <div class="fila negrita">
        <span class="izq">{{ abs($diferenciaTotal) < 0.005 ? 'CAJA CUADRADA' : ($diferenciaTotal > 0 ? 'SOBRANTE TOTAL' : 'FALTANTE TOTAL') }}</span>
        <span class="der">{{ abs($diferenciaTotal) < 0.005 ? '' : $s(abs($diferenciaTotal)) }}</span>
    </div>

    <div class="firma">Firma del cajero</div>
    <div class="pie">Impreso el {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
