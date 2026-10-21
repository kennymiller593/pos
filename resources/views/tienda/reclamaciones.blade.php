@extends($layoutTienda ?? 'tienda.layout')

{{-- Libro de Reclamaciones virtual (Ley 29571). Sin sesión: los errores vuelven en $errores y lo escrito en $anterior --}}
@php
    $errores ??= [];
    $anterior ??= [];
    $valor = fn (string $campo, $porDefecto = '') => $anterior[$campo] ?? $porDefecto;
    $claseCampo = fn (string $campo) => 'mt-1 block w-full rounded-xl border bg-white px-3 py-2.5 text-[15px] placeholder-slate-400 focus:ring-4 focus:outline-none '
        .(isset($errores[$campo]) ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : 'border-slate-300 focus:border-(--marca) focus:ring-(--marca)/15');
    $error = fn (string $campo) => isset($errores[$campo]) ? '<p class="mt-1 text-sm text-red-600">'.e($errores[$campo][0]).'</p>' : '';
@endphp

@section('titulo', "Libro de Reclamaciones · {$tienda['nombre']}")
@section('resumen', "Libro de Reclamaciones virtual de {$tienda['nombre']} ({$legal['razon_social']}, RUC {$legal['ruc']}). Registra un reclamo o una queja y recibe la respuesta en tu correo.")
@section('canonica', rtrim($tienda['url'], '/').'/libro-de-reclamaciones')

@section('contenido')
    <div class="mx-auto max-w-3xl px-4 pt-8 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Ruta">
            <a href="/" class="hover:text-(--marca-texto)">Inicio</a>
            @include('tienda.icono', ['n' => 'derecha', 'clase' => 'size-3.5'])
            <span class="text-slate-800" aria-current="page">Libro de Reclamaciones</span>
        </nav>

        <h1 class="mt-5 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">Libro de Reclamaciones</h1>

        {{-- datos del proveedor --}}
        <dl class="mt-5 grid gap-x-6 gap-y-2 rounded-2xl bg-white p-5 text-sm ring-1 ring-slate-200 sm:grid-cols-2" data-proveedor>
            <div><dt class="text-slate-500">Razón social</dt><dd class="font-semibold">{{ $legal['razon_social'] }}</dd></div>
            <div><dt class="text-slate-500">RUC</dt><dd class="font-semibold tabular-nums">{{ $legal['ruc'] }}</dd></div>
            @if ($contactos['direccion'])
                <div class="sm:col-span-2"><dt class="text-slate-500">Dirección</dt><dd class="font-semibold">{{ $contactos['direccion'] }}</dd></div>
            @endif
        </dl>

        @isset($registrada)
            {{-- confirmación --}}
            <section class="mt-8 rounded-2xl bg-emerald-50 p-6 ring-1 ring-emerald-200" role="status" data-reclamacion-registrada>
                <p class="text-sm font-semibold tracking-wide text-emerald-800 uppercase">{{ $registrada->tipo === 'queja' ? 'Queja' : 'Reclamo' }} registrado</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-emerald-950">Hoja N.º {{ $registrada->codigo() }}</p>
                <p class="mt-3 leading-relaxed text-emerald-900">
                    Registramos tu {{ $registrada->tipo }} el {{ $registrada->creado_en->timezone('America/Lima')->format('d/m/Y \a \l\a\s H:i') }}.
                    Te enviamos una copia a <strong>{{ $registrada->email }}</strong> y te responderemos a ese correo a más tardar el
                    <strong>{{ $registrada->venceEl()->format('d/m/Y') }}</strong> ({{ \App\Models\Reclamacion::PLAZO_DIAS_HABILES }} días hábiles).
                </p>
                <p class="mt-3 text-sm text-emerald-800">Guarda el número de hoja para cualquier consulta.</p>
            </section>
        @else
            <p class="mt-6 leading-relaxed text-slate-600">
                Conforme al Código de Protección y Defensa del Consumidor, contamos con un Libro de Reclamaciones virtual.
                Completa el formulario y te enviaremos una copia a tu correo. Te responderemos en un plazo no mayor a
                {{ \App\Models\Reclamacion::PLAZO_DIAS_HABILES }} días hábiles.
            </p>
            <ul class="mt-4 space-y-1 text-sm text-slate-600">
                <li><strong>Reclamo:</strong> disconformidad con el producto o servicio que compraste.</li>
                <li><strong>Queja:</strong> malestar con la atención recibida, que no tiene que ver con el producto o servicio.</li>
            </ul>

            @if ($errores)
                <p class="mt-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200" role="alert">Revisa los datos marcados en rojo.</p>
            @endif

            <form action="/libro-de-reclamaciones" method="post" class="mt-6 space-y-8" data-libro-reclamaciones novalidate>
                {{-- campo trampa: las personas no lo ven; si viene lleno, es un robot --}}
                <div class="hidden" aria-hidden="true"><label>No llenar <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>

                <fieldset>
                    <legend class="text-lg font-semibold tracking-tight">1. Tus datos</legend>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="nombre" class="text-sm font-medium">Nombre completo</label>
                            <input id="nombre" name="nombre" value="{{ $valor('nombre') }}" maxlength="150" autocomplete="name" required class="{{ $claseCampo('nombre') }}">
                            {!! $error('nombre') !!}
                        </div>
                        <div>
                            <label for="documento_tipo" class="text-sm font-medium">Documento</label>
                            <select id="documento_tipo" name="documento_tipo" class="{{ $claseCampo('documento_tipo') }}">
                                @foreach (['DNI' => 'DNI', 'CE' => 'Carné de extranjería', 'PASAPORTE' => 'Pasaporte', 'RUC' => 'RUC'] as $codigo => $texto)
                                    <option value="{{ $codigo }}" @selected($valor('documento_tipo', 'DNI') === $codigo)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="documento_numero" class="text-sm font-medium">Número de documento</label>
                            <input id="documento_numero" name="documento_numero" value="{{ $valor('documento_numero') }}" maxlength="20" inputmode="numeric" required class="{{ $claseCampo('documento_numero') }}">
                            {!! $error('documento_numero') !!}
                        </div>
                        <div class="sm:col-span-2">
                            <label for="domicilio" class="text-sm font-medium">Domicilio</label>
                            <input id="domicilio" name="domicilio" value="{{ $valor('domicilio') }}" maxlength="250" autocomplete="street-address" required class="{{ $claseCampo('domicilio') }}">
                            {!! $error('domicilio') !!}
                        </div>
                        <div>
                            <label for="email" class="text-sm font-medium">Correo electrónico</label>
                            <input id="email" type="email" name="email" value="{{ $valor('email') }}" maxlength="150" autocomplete="email" required class="{{ $claseCampo('email') }}">
                            {!! $error('email') !!}
                        </div>
                        <div>
                            <label for="telefono" class="text-sm font-medium">Teléfono <span class="font-normal text-slate-400">(opcional)</span></label>
                            <input id="telefono" type="tel" name="telefono" value="{{ $valor('telefono') }}" maxlength="30" autocomplete="tel" class="{{ $claseCampo('telefono') }}">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="menor_de_edad" value="1" @checked($valor('menor_de_edad')) class="size-4 accent-(--marca)"> Soy menor de edad</label>
                            <label for="apoderado" class="mt-3 block text-sm font-medium">Nombre del padre, madre o apoderado <span class="font-normal text-slate-400">(solo si eres menor de edad)</span></label>
                            <input id="apoderado" name="apoderado" value="{{ $valor('apoderado') }}" maxlength="150" class="{{ $claseCampo('apoderado') }}">
                            {!! $error('apoderado') !!}
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-lg font-semibold tracking-tight">2. Lo que compraste</legend>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <span class="text-sm font-medium">Es un</span>
                            <div class="mt-2 flex gap-4">
                                @foreach (['producto' => 'Producto', 'servicio' => 'Servicio'] as $codigo => $texto)
                                    <label class="inline-flex items-center gap-2 text-[15px]"><input type="radio" name="bien_tipo" value="{{ $codigo }}" @checked($valor('bien_tipo', 'producto') === $codigo) class="size-4 accent-(--marca)"> {{ $texto }}</label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label for="monto" class="text-sm font-medium">Monto pagado <span class="font-normal text-slate-400">(opcional)</span></label>
                            <div class="relative">
                                <span class="pointer-events-none absolute top-1/2 left-3 mt-0.5 -translate-y-1/2 text-sm text-slate-500">S/</span>
                                <input id="monto" name="monto" value="{{ $valor('monto') }}" inputmode="decimal" maxlength="12" class="{{ $claseCampo('monto') }} pl-9">
                            </div>
                            {!! $error('monto') !!}
                        </div>
                        <div class="sm:col-span-2">
                            <label for="bien_descripcion" class="text-sm font-medium">¿Qué producto o servicio fue?</label>
                            <input id="bien_descripcion" name="bien_descripcion" value="{{ $valor('bien_descripcion') }}" maxlength="500" required placeholder="Nombre del producto, fecha de compra, número de boleta..." class="{{ $claseCampo('bien_descripcion') }}">
                            {!! $error('bien_descripcion') !!}
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-lg font-semibold tracking-tight">3. Tu reclamo o queja</legend>
                    <div class="mt-4 grid gap-4">
                        <div class="flex flex-wrap gap-4">
                            @foreach (['reclamo' => 'Reclamo', 'queja' => 'Queja'] as $codigo => $texto)
                                <label class="inline-flex items-center gap-2 text-[15px] font-medium"><input type="radio" name="tipo" value="{{ $codigo }}" @checked($valor('tipo', 'reclamo') === $codigo) class="size-4 accent-(--marca)"> {{ $texto }}</label>
                            @endforeach
                        </div>
                        <div>
                            <label for="detalle" class="text-sm font-medium">Detalle</label>
                            <textarea id="detalle" name="detalle" rows="5" maxlength="3000" required class="{{ $claseCampo('detalle') }}">{{ $valor('detalle') }}</textarea>
                            {!! $error('detalle') !!}
                        </div>
                        <div>
                            <label for="solicitud" class="text-sm font-medium">¿Qué solicitas?</label>
                            <textarea id="solicitud" name="pedido" rows="3" maxlength="1500" required placeholder="Cambio del producto, devolución del dinero, una disculpa..." class="{{ $claseCampo('pedido') }}">{{ $valor('pedido') }}</textarea>
                            {!! $error('pedido') !!}
                        </div>
                    </div>
                </fieldset>

                <div>
                    <label class="flex items-start gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="acepto" value="1" @checked($valor('acepto')) class="mt-0.5 size-4 shrink-0 accent-(--marca)">
                        <span>Declaro que los datos son verdaderos y acepto que se usen solo para atender este reclamo o queja.</span>
                    </label>
                    {!! $error('acepto') !!}
                    <button type="submit" class="mt-5 inline-flex h-12 w-full items-center justify-center rounded-xl bg-(--marca) px-6 text-sm font-bold text-(--sobre-marca) transition hover:brightness-110 sm:w-auto">Enviar</button>
                    <p class="mt-4 text-xs leading-relaxed text-slate-500">
                        La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para
                        interponer una denuncia ante el INDECOPI. El proveedor deberá dar respuesta al reclamo en un plazo no mayor a
                        {{ \App\Models\Reclamacion::PLAZO_DIAS_HABILES }} días hábiles.
                    </p>
                </div>
            </form>
        @endisset
    </div>
@endsection
