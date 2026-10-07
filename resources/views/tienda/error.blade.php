{{-- Página de error en la dirección de una tienda: no depende de que la tienda exista --}}
@php
    $mensajes = [
        404 => ['No encontramos esta página', 'Puede que el producto ya no esté en el catálogo o que la tienda no esté disponible.'],
        429 => ['Demasiadas visitas seguidas', 'Espera un momento y vuelve a intentarlo.'],
        503 => ['Volvemos en un momento', 'Estamos actualizando la tienda. Inténtalo de nuevo en unos minutos.'],
    ];
    [$titulo, $detalle] = $mensajes[$estado] ?? ['Algo salió mal', 'No pudimos mostrar esta página. Inténtalo de nuevo en unos minutos.'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $titulo }}</title>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-50 p-6 font-sans text-slate-900 antialiased">
    <main class="w-full max-w-md rounded-3xl bg-white p-10 text-center shadow-xl ring-1 shadow-slate-900/5 ring-slate-200">
        <p class="text-6xl font-semibold tracking-tight text-slate-200">{{ $estado }}</p>
        <h1 class="mt-3 text-xl font-semibold tracking-tight">{{ $titulo }}</h1>
        <p class="mt-2 text-slate-500">{{ $detalle }}</p>
        <a href="/" class="mt-7 inline-flex h-12 items-center rounded-xl bg-slate-900 px-6 text-sm font-semibold text-white transition-colors hover:bg-slate-700">Ir al inicio de la tienda</a>
    </main>
</body>
</html>
