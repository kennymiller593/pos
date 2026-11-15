{{-- Logo de InkaPos en vector (la chakana con el check y el nombre). $claro = true para fondos oscuros. --}}
@php $claro = $claro ?? false; @endphp
<span class="inline-flex items-center gap-2.5 {{ $clase ?? '' }}">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" class="{{ $tamano ?? 'size-10' }} shrink-0" aria-hidden="true" focusable="false">
        <path fill="{{ $claro ? '#FFFFFF' : '#053C8F' }}" d="M48 6H72V18H84V30H96V48H114V72H96V90H84V102H72V114H48V102H36V90H24V72H6V48H24V30H36V18H48Z"/>
        <circle cx="60" cy="60" r="19" fill="{{ $claro ? '#053C8F' : '#FFFFFF' }}"/>
        <path d="M50.5 60.5 57 67 70 52" fill="none" stroke="#00B1C3" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <span class="font-display {{ $texto ?? 'text-2xl' }} leading-none font-extrabold tracking-tight"><span class="{{ $claro ? 'text-white' : 'text-marca' }}">Inka</span><span class="text-cian">Pos</span></span>
</span>
