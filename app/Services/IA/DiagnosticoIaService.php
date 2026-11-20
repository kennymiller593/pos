<?php

namespace App\Services\IA;

use App\Exceptions\ErrorDeNegocio;
use App\IA\DiagnosticoDeCultivo;
use App\Models\AgenteIa;
use App\Models\ConversacionIa;
use App\Models\Empresa;
use App\Models\MensajeIa;
use App\Models\ModeloIa;
use App\Models\ProveedorIa;
use App\Services\BusquedaProductos;
use App\Services\CatalogoTiendaService;
use App\Services\ImagenProductoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image;
use RuntimeException;
use Throwable;

/**
 * El diagnóstico por foto de la tienda en línea: el cliente sube la foto de su cultivo, un modelo con visión
 * (la cadena del agente: Gemini, OpenAI...) dice qué tiene y la tienda busca en su catálogo lo que lo trata.
 * Cada análisis queda como una conversación de canal "diagnostico", con proveedor, tokens y costo, y cuenta
 * para el tope mensual del agente en esa empresa (igual que el chatbot).
 */
class DiagnosticoIaService
{
    /** Segundos máximos por proveedor: una foto tarda más que un texto. */
    public const TIMEOUT_POR_PROVEEDOR = 40;

    /** Ancho al que se reduce la foto antes de enviarla: suficiente para ver una mancha y barato en tokens. */
    public const ANCHO_FOTO = 1024;

    public const MAX_PRODUCTOS = 4;

    /** Hasta cuántos píxeles se achica la foto en el servidor (24 MP ≈ 100 MB de memoria en GD). */
    private const PIXELES_MAXIMOS_GD = 24_000_000;

    /** Qué categorías del catálogo tratan cada tipo de problema (si las palabras de la IA no encuentran nada). */
    private const CATEGORIAS_POR_TIPO = [
        'enfermedad' => ['fungicida'],
        'plaga' => ['insecticida', 'acaricida'],
        'maleza' => ['herbicida'],
        'deficiencia' => ['fertilizante', 'foliar', 'abono', 'nutricion'],
        'estres' => ['bioestimulante', 'enraizador', 'foliar'],
    ];

    /** Categorías que nunca se recomiendan para un cultivo (por nombre, sin mayúsculas). */
    private const CATEGORIAS_AJENAS = ['veterinari', 'mascota', 'domestic', 'doméstic'];

    public function __construct(
        private readonly ProveedorIaService $proveedores,
        private readonly AsistenteIaService $asistente,
        private readonly CatalogoTiendaService $catalogo,
        private readonly ImagenProductoService $imagenes,
    ) {}

    /** El agente de diagnóstico que la plataforma activó para la empresa, si lo hay. */
    public function agenteDe(Empresa $empresa): ?AgenteIa
    {
        return AgenteIa::query()
            ->where('activo', true)
            ->where('tipo', AgenteIa::TIPO_DIAGNOSTICO)
            ->whereHas('activaciones', fn ($q) => $q->where('empresa_id', $empresa->id)->where('activo', true))
            ->orderBy('nombre')
            ->first();
    }

    /**
     * Analiza la foto y devuelve el diagnóstico con los códigos de los productos recomendados.
     *
     * @return array{diagnostico: array<string, mixed>, productos: list<string>, conversacion_id: string}
     *
     * @throws ErrorDeNegocio
     */
    public function diagnosticar(Empresa $empresa, UploadedFile $foto, string $visitante, string $nota = ''): array
    {
        $agente = $this->agenteDe($empresa);
        if (! $agente) {
            throw new ErrorDeNegocio('El diagnóstico con IA no está disponible en esta tienda.');
        }

        $limite = $this->asistente->limiteDe($empresa, $agente);
        if ($this->asistente->consultasDelMes($empresa, $agente) >= $limite) {
            throw new ErrorDeNegocio('El diagnóstico alcanzó su límite de este mes. Envíanos la foto por WhatsApp y te ayudamos.');
        }

        // un proveedor lento y el siguiente de la cadena no caben en los 30 s de PHP: solo este pedido tiene más
        // (sin límite, como en la consola, no se pone uno)
        $segundos = self::TIMEOUT_POR_PROVEEDOR * max(1, count($agente->eslabones())) + 20;
        if ((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < $segundos) {
            set_time_limit($segundos);
        }
        $resultado = $this->consultar($agente, $this->imagen($foto), trim($nota));
        $diagnostico = $resultado['diagnostico'];
        $productos = $diagnostico ? $this->productos($empresa, $diagnostico) : [];

        $conversacion = DB::transaction(function () use ($empresa, $agente, $visitante, $nota, $resultado, $diagnostico, $productos) {
            $conversacion = ConversacionIa::create([
                'empresa_id' => $empresa->id,
                'agente_id' => $agente->id,
                'canal' => 'diagnostico',
                'visitante' => $visitante,
                'titulo' => $diagnostico
                    ? Str::limit(trim(($diagnostico['cultivo'] ? $diagnostico['cultivo'].': ' : '').($diagnostico['problema'] ?: 'Foto sin cultivo')), 80, '…')
                    : 'Foto sin diagnóstico (respuesta ilegible)',
            ]);
            MensajeIa::create([
                'conversacion_id' => $conversacion->id, 'empresa_id' => $empresa->id, 'rol' => 'user',
                'contenido' => '[Foto del cultivo]'.(trim($nota) !== '' ? ' '.Str::limit(trim($nota), 500) : ''),
            ]);
            MensajeIa::create([
                'conversacion_id' => $conversacion->id, 'empresa_id' => $empresa->id, 'rol' => 'assistant',
                'contenido' => $diagnostico ? json_encode($diagnostico, JSON_UNESCAPED_UNICODE) : '',
                'productos' => $productos,
            ] + $resultado['consumo']);

            return $conversacion;
        });

        if (! $diagnostico) {
            throw new ErrorDeNegocio('No pudimos analizar la foto en este momento. Inténtalo de nuevo en unos minutos.');
        }

        return ['diagnostico' => $diagnostico, 'productos' => $productos, 'conversacion_id' => $conversacion->id];
    }

    /** La foto achicada (webp o jpg) lista para adjuntar; si no se puede procesar, la original. */
    private function imagen(UploadedFile $foto): Base64Image
    {
        // abrir con GD una foto enorme (50 MP) agotaría la memoria de PHP: esa se envía tal cual
        $medidas = @getimagesize($foto->getRealPath());
        $optimizada = $medidas && $medidas[0] * $medidas[1] <= self::PIXELES_MAXIMOS_GD
            ? $this->imagenes->optimizar((string) file_get_contents($foto->getRealPath()), self::ANCHO_FOTO, 80, enCaja: false)
            : null;

        return $optimizada
            ? Image::fromBase64(base64_encode($optimizada[0]), $optimizada[1] === 'webp' ? 'image/webp' : 'image/jpeg')
            : Image::fromUpload($foto);
    }

    /**
     * Recorre la cadena del agente hasta que un modelo devuelva un diagnóstico que se pueda leer.
     *
     * @return array{diagnostico: array<string, mixed>, consumo: array<string, mixed>}
     *
     * @throws ErrorDeNegocio
     */
    private function consultar(AgenteIa $agente, Base64Image $imagen, string $nota): array
    {
        $eslabones = $agente->eslabones();
        $proveedores = ProveedorIa::whereIn('codigo', array_column($eslabones, 'proveedor'))->get()->keyBy('codigo');
        $pedido = 'Diagnostica el cultivo de esta fotografía.'.($nota !== '' ? "\nEl agricultor comenta: \"".Str::limit($nota, 500).'"' : '');
        $intentos = [];
        $inicioTotal = hrtime(true);
        // lo que cobraron los modelos que respondieron sin el formato pedido: también se paga y se registra
        $gastado = ['tokens_entrada' => 0, 'tokens_salida' => 0, 'costo_usd' => null, 'proveedor_codigo' => null, 'modelo' => null];
        $sumar = function ($respuesta, ProveedorIa $proveedor, string $modelo) use (&$gastado) {
            $entrada = (int) $respuesta->usage->inputTokens;
            $salida = (int) $respuesta->usage->outputTokens;
            $precio = ModeloIa::where('proveedor_codigo', $proveedor->codigo)->whereIn('modelo', array_unique([$modelo, $respuesta->meta->model ?: $modelo]))->first();
            $gastado['tokens_entrada'] += $entrada;
            $gastado['tokens_salida'] += $salida;
            if ($precio) {
                $gastado['costo_usd'] = ($gastado['costo_usd'] ?? 0) + $precio->costo($entrada, $salida);
            }
            $gastado['proveedor_codigo'] = $proveedor->codigo;
            $gastado['modelo'] = $modelo;
        };

        foreach ($eslabones as $eslabon) {
            $proveedor = $proveedores->get($eslabon['proveedor']);
            if (! $proveedor || ! $proveedor->disponible()) {
                $intentos[] = ['proveedor' => $eslabon['proveedor'], 'modelo' => $eslabon['modelo'], 'error' => ! $proveedor ? 'no existe' : ($proveedor->enPausa() ? 'en pausa por fallos' : 'sin clave o desactivado')];

                continue;
            }

            $inicio = hrtime(true);
            try {
                $respuesta = (new DiagnosticoDeCultivo($agente))->paraModelo($eslabon['modelo'])->prompt(
                    $pedido,
                    attachments: [$imagen],
                    provider: $this->proveedores->construir($proveedor),
                    model: $eslabon['modelo'],
                    timeout: self::TIMEOUT_POR_PROVEEDOR,
                );
            } catch (Throwable $e) {
                $this->proveedores->registrarFallo($proveedor, $e);
                $intentos[] = ['proveedor' => $proveedor->codigo, 'modelo' => $eslabon['modelo'], 'error' => ProveedorIaService::resumirError($e), 'ms' => (int) ((hrtime(true) - $inicio) / 1_000_000)];

                continue;
            }

            $this->proveedores->registrarExito($proveedor);
            $sumar($respuesta, $proveedor, $eslabon['modelo']);
            $diagnostico = DiagnosticoDeCultivo::leer($respuesta->text);
            if ($diagnostico === null) {
                // contestó, pero no con el formato que la tienda sabe leer: se prueba con el siguiente modelo
                $intentos[] = ['proveedor' => $proveedor->codigo, 'modelo' => $eslabon['modelo'], 'error' => 'respuesta ilegible: '.Str::limit(trim($respuesta->text), 120), 'ms' => (int) ((hrtime(true) - $inicio) / 1_000_000)];

                continue;
            }

            return ['diagnostico' => $diagnostico, 'consumo' => [...$gastado, 'duracion_ms' => (int) ((hrtime(true) - $inicioTotal) / 1_000_000), 'intentos' => $intentos]];
        }

        // alguno respondió (y cobró) pero nadie dio un diagnóstico legible: se registra para el costo y el tope
        if ($gastado['proveedor_codigo'] !== null) {
            report(new RuntimeException('Diagnóstico por foto ilegible: '.json_encode($intentos, JSON_UNESCAPED_UNICODE)));

            return ['diagnostico' => null, 'consumo' => [...$gastado, 'duracion_ms' => (int) ((hrtime(true) - $inicioTotal) / 1_000_000), 'intentos' => $intentos]];
        }

        report(new RuntimeException('Diagnóstico por foto sin respuesta: '.json_encode($intentos, JSON_UNESCAPED_UNICODE)));

        throw new ErrorDeNegocio('No pudimos analizar la foto en este momento. Inténtalo de nuevo en unos minutos.');
    }

    /**
     * Los productos del catálogo de la tienda que tratan el problema: primero por las palabras que dio la IA
     * (problema, ingrediente activo) y, si nada coincide, los de la categoría que corresponde al tipo de problema.
     *
     * @param  array<string, mixed>  $diagnostico
     * @return list<string> códigos internos
     */
    public function productos(Empresa $empresa, array $diagnostico): array
    {
        if (! $diagnostico['es_cultivo'] || in_array($diagnostico['tipo'], ['sano', 'indeterminado'], true)) {
            return [];
        }

        $busqueda = app(BusquedaProductos::class);
        $elegidos = collect();
        $agregar = function ($productos, int $tope = self::MAX_PRODUCTOS) use (&$elegidos) {
            $puestos = 0;
            foreach ($productos as $p) {
                // de un modelo con tallas, o de una marca en varios tamaños ("Affly x 250 ml", "Affly x litro"), basta uno
                $clave = $p->grupo_id ?? (preg_match('/^\p{L}{3,}/u', mb_strtolower(trim($p->nombre)), $m) ? $m[0] : $p->id);
                if ($puestos < $tope && $elegidos->count() < self::MAX_PRODUCTOS && ! $elegidos->has($clave) && $p->presentaciones->isNotEmpty()) {
                    $elegidos->put($clave, $p->codigo_interno);
                    $puestos++;
                }
            }
        };

        // cada término (el problema, cada ingrediente activo) aporta lo suyo: primero se reparten los lugares
        // entre todos y luego se completan con lo que sobró, así "rancha" no tapa al cimoxanil ni al mancozeb
        $categorias = self::CATEGORIAS_POR_TIPO[$diagnostico['tipo']] ?? [];
        // un cultivo no se trata con productos para mascotas ni insecticidas de casa ("pulgón" ≠ "Rey Pulgón cama")
        $base = fn () => $this->catalogo->productos($empresa)->where(fn ($q) => $q->whereNull('productos.categoria_id')
            ->orWhereHas('categoria', fn ($c) => collect(self::CATEGORIAS_AJENAS)->each(fn ($a) => $c->where('nombre', 'NOT ILIKE', "%{$a}%"))));
        $delTipo = fn () => $base()->whereHas('categoria', fn ($q) => $q->where(fn ($w) => collect($categorias)->each(fn ($c) => $w->orWhere('nombre', 'ILIKE', "%{$c}%"))));
        $buscar = fn ($consulta, array $palabras) => $busqueda->aplicar($consulta, $palabras, count($palabras), conDescripcion: true)->limit(self::MAX_PRODUCTOS * 2)->get();

        $resultados = collect($diagnostico['busqueda'])
            ->map(fn ($termino) => BusquedaProductos::palabras($termino))
            ->filter()
            // primero lo de las categorías que tratan ese tipo de problema (insecticidas para una plaga), luego el resto de la tienda
            ->map(fn ($palabras) => ($categorias ? $buscar($delTipo(), $palabras) : collect())->concat($buscar($base(), $palabras)))
            ->filter(fn ($productos) => $productos->isNotEmpty())
            ->values();
        $porTermino = $resultados->isEmpty() ? 0 : (int) max(1, intdiv(self::MAX_PRODUCTOS, $resultados->count()));
        $resultados->each(fn ($productos) => $agregar($productos, $porTermino));
        $resultados->each(fn ($productos) => $agregar($productos));

        // nada coincidió con las palabras: lo que la tienda tiene en la categoría del problema, primero lo destacado y con stock
        if ($elegidos->isEmpty() && $categorias) {
            $agregar($delTipo()
                ->orderByDesc('productos.destacado')
                ->orderByRaw('(CASE WHEN NOT productos.controla_stock OR COALESCE((SELECT SUM(s.cantidad) FROM stock s WHERE s.producto_id = productos.id), 0) > 0 THEN 0 ELSE 1 END)')
                ->orderBy('productos.nombre')
                ->limit(self::MAX_PRODUCTOS * 2)->get());
        }

        return $elegidos->filter()->values()->all();
    }
}
