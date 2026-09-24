<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #262626; padding: 28px; }

        .encabezado { display: table; width: 100%; margin-bottom: 18px; }
        .encabezado .col { display: table-cell; vertical-align: top; }
        .empresa-nombre { font-size: 15px; font-weight: 700; }
        .empresa-dato { color: #737373; margin-top: 1px; font-size: 10px; }
        .titulo { text-align: right; }
        .titulo h1 { font-size: 14px; letter-spacing: 1px; color: #059669; }
        .titulo .sub { color: #737373; margin-top: 3px; font-size: 10px; }

        .resumen { display: table; width: 100%; margin-bottom: 14px; border: 1px solid #e7e5e4; border-radius: 8px; }
        .resumen .celda { display: table-cell; padding: 8px 14px; border-right: 1px solid #e7e5e4; }
        .resumen .celda:last-child { border-right: none; }
        .resumen .etiqueta { color: #737373; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .resumen .valor { font-weight: 700; font-size: 12px; margin-top: 2px; }

        table.datos { width: 100%; border-collapse: collapse; }
        table.datos th {
            text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px;
            color: #737373; border-bottom: 2px solid #059669; padding: 5px 7px;
        }
        table.datos td { padding: 5px 7px; border-bottom: 1px solid #f5f5f4; }
        table.datos tr:nth-child(even) td { background: #fafaf9; }

        .vacio { text-align: center; color: #a3a3a3; padding: 30px 0; }
        .pie { margin-top: 20px; padding-top: 10px; border-top: 1px solid #e7e5e4; color: #a3a3a3; font-size: 9px; }
    </style>
</head>
<body>
    <div class="encabezado">
        <div class="col">
            <div class="empresa-nombre">{{ $empresa->nombre_comercial ?? $empresa->razon_social }}</div>
            <div class="empresa-dato">{{ $empresa->razon_social }} · RUC {{ $empresa->ruc }}</div>
        </div>
        <div class="col titulo">
            <h1>{{ mb_strtoupper($titulo) }}</h1>
            <div class="sub">{{ $subtitulo }}</div>
        </div>
    </div>

    @if (count($resumen))
        <div class="resumen">
            @foreach ($resumen as $celda)
                <div class="celda">
                    <div class="etiqueta">{{ $celda['etiqueta'] }}</div>
                    <div class="valor">{{ $celda['valor'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <table class="datos">
        <thead>
            <tr>
                @foreach ($columnas as $columna)
                    <th>{{ $columna }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($fila as $celda)
                        <td>{{ $celda }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columnas) }}" class="vacio">Sin datos en el rango seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pie">
        Generado el {{ now()->format('d/m/Y H:i') }} · {{ count($filas) }} registro{{ count($filas) === 1 ? '' : 's' }}
    </div>
</body>
</html>
