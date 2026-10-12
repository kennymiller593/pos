{{-- Chatbot de la tienda: botón flotante y panel de conversación (lo maneja tienda.js con los datos de #tienda-datos) --}}
@php
    $colorBot = $asistente['color'] ?: 'var(--marca)';
    $sobreBot = $asistente['sobre'] ?? 'var(--sobre-marca)';
@endphp
<div id="asistente" data-asistente style="--bot: {{ $colorBot }}; --sobre-bot: {{ $sobreBot }};">
    {{-- en celular va encima del botón de WhatsApp; en PC, encima de WhatsApp y del pedido --}}
    <button type="button" data-bot-abrir data-necesita-bot aria-label="Hablar con {{ $asistente['nombre'] }}" title="Hablar con {{ $asistente['nombre'] }}"
        class="group fixed right-4 bottom-[6rem] z-40 flex h-14 cursor-pointer items-center rounded-full bg-(--bot) text-(--sobre-bot) shadow-lg ring-4 shadow-black/25 ring-white transition-transform hover:scale-105 sm:right-6 sm:bottom-[11.5rem]">
        <span class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-full">
            @if ($asistente['avatar'])
                <img src="{{ $asistente['avatar'] }}" alt="" class="size-14 object-cover">
            @else
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-7" aria-hidden="true"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
            @endif
        </span>
        <span class="hidden max-w-0 overflow-hidden text-sm font-semibold whitespace-nowrap transition-[max-width,padding] duration-300 group-hover:max-w-56 group-hover:pr-5 group-focus-visible:max-w-56 group-focus-visible:pr-5 lg:block">Pregúntale a {{ $asistente['nombre'] }}</span>
    </button>

    <section data-bot-panel class="fixed inset-0 z-50 hidden flex-col bg-white shadow-2xl sm:inset-auto sm:right-6 sm:bottom-6 sm:h-[min(42rem,calc(100vh-3rem))] sm:w-[24rem] sm:rounded-3xl sm:ring-1 sm:ring-black/10 [&:not(.hidden)]:flex" role="dialog" aria-modal="true" aria-label="Asistente virtual">
        <header class="flex items-center gap-3 bg-(--bot) px-4 py-3 text-(--sobre-bot) sm:rounded-t-3xl">
            <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-full bg-white/20 ring-2 ring-white/60">
                @if ($asistente['avatar'])
                    <img src="{{ $asistente['avatar'] }}" alt="" class="size-11 object-cover">
                @else
                    <span class="text-lg font-bold">{{ $asistente['inicial'] }}</span>
                @endif
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold leading-tight">{{ $asistente['nombre'] }}</p>
                <p class="flex items-center gap-1.5 text-xs opacity-90"><span class="size-2 rounded-full bg-emerald-300"></span> En línea</p>
            </div>
            <button type="button" data-bot-nueva class="grid size-9 cursor-pointer place-items-center rounded-full transition-colors hover:bg-white/20" title="Empezar de nuevo" aria-label="Empezar de nuevo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
            </button>
            <button type="button" data-bot-cerrar class="grid size-9 cursor-pointer place-items-center rounded-full transition-colors hover:bg-white/20" title="Cerrar" aria-label="Cerrar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </header>

        <div data-bot-mensajes class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 px-3 py-4" aria-live="polite"></div>

        <form data-bot-form class="flex items-end gap-2 border-t border-slate-200 bg-white p-3 sm:rounded-b-3xl">
            <textarea data-bot-texto rows="1" maxlength="2000" placeholder="Escribe tu consulta…" aria-label="Escribe tu consulta"
                class="max-h-32 min-h-11 w-full resize-none rounded-2xl border border-slate-300 px-4 py-2.5 text-sm placeholder-slate-400 focus:border-(--bot) focus:ring-2 focus:ring-(--bot)/30 focus:outline-none"></textarea>
            <button type="submit" data-bot-enviar class="grid size-11 shrink-0 cursor-pointer place-items-center rounded-full bg-(--bot) text-(--sobre-bot) transition-opacity disabled:cursor-not-allowed disabled:opacity-50" aria-label="Enviar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true"><path d="M3.714 3.048a.498.498 0 0 0-.683.627l2.843 7.627a2 2 0 0 1 0 1.396l-2.842 7.627a.498.498 0 0 0 .682.627l18-8.5a.5.5 0 0 0 0-.904z"/><path d="M6 12h16"/></svg>
            </button>
        </form>
    </section>
</div>
