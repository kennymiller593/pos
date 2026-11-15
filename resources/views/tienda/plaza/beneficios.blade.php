{{-- Plantilla Plaza: los tres beneficios (pagos, envíos y compra con confianza). Van bajo la campaña de la portada y, en las demás páginas, antes del pie --}}
@php
    $entrega = $entrega ?? ['envios' => null, 'pagos' => null];
    $beneficios = [
        ['tarjeta', 'Paga como prefieras', $entrega['pagos'] ?: 'Te confirmamos el total y el medio de pago antes de pagar.'],
        ['camion', 'Envíos a todo el Perú', $entrega['envios'] ?: 'Coordinamos la entrega contigo al confirmar tu pedido.'],
        ['escudo', 'Compra con confianza', $whatsapp
            ? 'Te asesoramos por WhatsApp y confirmamos tu pedido antes de pagar.'
            : ($contactos['telefono'] ? 'Llámanos al '.$contactos['telefono'].' y te orientamos.' : 'Escríbenos y te orientamos antes de comprar.')],
    ];
@endphp
{{-- en celular, una sola tarjeta con tres filas (no alarga la página); desde tablet, tres tarjetas --}}
<ul class="divide-y divide-slate-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 shadow-slate-900/5 ring-slate-200/70 md:grid md:grid-cols-3 md:gap-3 md:divide-y-0 md:overflow-visible md:rounded-none md:bg-transparent md:shadow-none md:ring-0" data-beneficios>
    @foreach ($beneficios as [$icono, $tituloBeneficio, $detalle])
        <li class="flex items-center gap-3 px-4 py-3 md:h-full md:gap-3.5 md:rounded-xl md:bg-white md:px-5 md:py-4 md:shadow-sm md:ring-1 md:shadow-slate-900/5 md:ring-slate-200/70">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-(--marca-suave) text-(--marca-texto) md:size-11">@include('tienda.plaza.icono', ['n' => $icono, 'clase' => 'size-4.5 md:size-5'])</span>
            <span class="min-w-0">
                <span class="block text-sm leading-tight font-bold md:text-[15px]">{{ $tituloBeneficio }}</span>
                <span class="mt-0.5 line-clamp-1 text-[13px] leading-snug text-slate-500 md:line-clamp-2">{{ $detalle }}</span>
            </span>
        </li>
    @endforeach
</ul>
