{{-- Aviso de la portada que lleva al diagnóstico por foto (solo si la tienda lo tiene activo). $clase: márgenes de cada plantilla --}}
<section class="{{ $clase ?? '' }}" aria-label="Diagnóstico de cultivos con IA" data-diagnostico-promo>
    <a href="/diagnostico" class="group relative flex items-center gap-4 overflow-hidden rounded-2xl bg-(--marca) p-4 text-(--sobre-marca) shadow-sm transition hover:brightness-105 sm:gap-5 sm:p-5">
        <span class="pointer-events-none absolute -top-10 -right-8 size-40 rounded-full bg-white/10" aria-hidden="true"></span>
        <span class="relative grid size-12 shrink-0 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/25 sm:size-14">
            @include('tienda.icono', ['n' => 'camara', 'clase' => 'size-6 sm:size-7'])
        </span>
        <span class="relative min-w-0 flex-1">
            <span class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wider uppercase opacity-80">@include('tienda.icono', ['n' => 'chispa', 'clase' => 'size-3.5'])Nuevo<span class="hidden sm:inline"> · con inteligencia artificial</span><span class="sm:hidden"> · con IA</span></span>
            <span class="mt-0.5 block text-base leading-snug font-semibold sm:text-lg">¿Tu cultivo tiene plagas o manchas? Envía una foto y te decimos qué tiene</span>
        </span>
        <span class="relative hidden h-10 shrink-0 items-center gap-1.5 rounded-xl bg-white px-4 text-sm font-semibold text-(--marca-texto) sm:inline-flex">Diagnosticar @include('tienda.icono', ['n' => 'flecha', 'clase' => 'size-4 transition-transform group-hover:translate-x-0.5'])</span>
        @include('tienda.icono', ['n' => 'derecha', 'clase' => 'relative size-5 sm:hidden'])
    </a>
</section>
