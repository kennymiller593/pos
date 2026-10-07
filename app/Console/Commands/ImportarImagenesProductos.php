<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Producto;
use App\Services\ImagenProductoService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Trae las fotos de productos de otro sistema (p. ej. el que usaba el cliente antes de inkaPos).
 *
 * Recibe una lista "nombre del producto -> archivo de su foto" y la carpeta donde están esas fotos.
 * Empareja por nombre (sin mayúsculas, tildes ni espacios raros), pasa cada foto por el mismo
 * proceso que una subida normal (4:3, fondo blanco, máx. 800x600) y la guarda como foto del producto.
 *
 * Del origen solo LEE: nunca mueve, cambia ni borra un archivo de esa carpeta.
 * Sin --ejecutar no escribe nada: muestra qué haría.
 */
class ImportarImagenesProductos extends Command
{
    protected $signature = 'productos:importar-imagenes
        {ruc : RUC de la empresa en inkaPos}
        {lista : Archivo de texto con una fila por producto: nombre, tabulación y archivo de la foto}
        {carpeta : Carpeta con las fotos del otro sistema (solo se lee)}
        {--ejecutar : Guarda las fotos. Sin esta opción solo simula y muestra el resultado}
        {--reemplazar : También cambia la foto de los productos que ya tienen una}
        {--max-compartida=3 : Una foto usada por más productos que este número se toma por genérica y se omite (0 = no omitir)}
        {--detalle : Lista cada producto y lo que se hizo con él}';

    protected $description = 'Importa las fotos de productos desde otro sistema, emparejando por nombre';

    public function handle(ImagenProductoService $imagenes): int
    {
        $empresa = Empresa::where('ruc', $this->argument('ruc'))->first();
        if (! $empresa) {
            $this->error("No hay una empresa con RUC {$this->argument('ruc')}.");

            return self::FAILURE;
        }

        $carpeta = rtrim((string) $this->argument('carpeta'), '/\\');
        if (! is_dir($carpeta) || ! is_readable($carpeta)) {
            $this->error("No se puede leer la carpeta {$carpeta}.");

            return self::FAILURE;
        }

        $origen = $this->leerLista((string) $this->argument('lista'));
        if ($origen === null) {
            return self::FAILURE;
        }

        $ejecutar = (bool) $this->option('ejecutar');
        $maxCompartida = max(0, (int) $this->option('max-compartida'));

        // una misma foto en muchos productos suele ser el "sin imagen" del otro sistema
        $usos = $origen->flatten(1)->countBy();
        $genericas = $maxCompartida > 0 ? $usos->filter(fn (int $veces) => $veces > $maxCompartida) : collect();

        $this->line(($ejecutar ? 'IMPORTANDO' : 'SIMULACIÓN (no se guarda nada)')." · {$empresa->razon_social} · fotos en ".($imagenes->enNube() ? 'la nube' : 'el servidor'));

        $resultado = ['importadas' => [], 'ya_tenian' => [], 'sin_pareja' => [], 'ambiguos' => [], 'genericas' => [], 'sin_archivo' => [], 'ilegibles' => []];

        // se cargan todos de una vez (son los productos de una sola empresa): asi el orden por nombre
        // del informe no depende de como se pagine mientras se van guardando fotos
        $productos = Producto::query()->where('empresa_id', $empresa->id)->orderBy('nombre')->orderBy('id')->get();

        foreach ($productos as $producto) {
            $resultado[$this->procesar($producto, $origen, $genericas, $carpeta, $imagenes, $empresa, $ejecutar)][] = $producto->nombre;
        }

        $this->informar($resultado, $genericas, $ejecutar);

        return self::SUCCESS;
    }

    /** Decide qué pasa con un producto y, si corresponde, guarda su foto. Devuelve la clave del resultado. */
    private function procesar(Producto $producto, Collection $origen, Collection $genericas, string $carpeta, ImagenProductoService $imagenes, Empresa $empresa, bool $ejecutar): string
    {
        if (filled($producto->imagen_url) && ! $this->option('reemplazar')) {
            return 'ya_tenian';
        }

        $archivos = $origen->get(self::normalizar($producto->nombre));
        if (! $archivos) {
            return 'sin_pareja';
        }

        // dos productos del otro sistema con el mismo nombre y fotos distintas: no se adivina cuál es
        if (count($archivos) > 1) {
            return 'ambiguos';
        }

        $archivo = $archivos[0];
        if ($genericas->has($archivo)) {
            return 'genericas';
        }

        $ruta = $carpeta.DIRECTORY_SEPARATOR.$archivo;
        if (! is_file($ruta)) {
            return 'sin_archivo';
        }

        $foto = $this->preparar($ruta, $imagenes);
        if ($foto === null) {
            return 'ilegibles';
        }

        if ($ejecutar) {
            $anterior = $producto->imagen_url;
            $producto->forceFill(['imagen_url' => $imagenes->subir($foto[0], $foto[1], $empresa->id)])->saveQuietly();

            if ($anterior) {
                $imagenes->eliminar($anterior); // solo borra fotos guardadas por inkaPos, nunca las del origen
            }
        }

        return 'importadas';
    }

    /**
     * Lee la foto del origen y la deja en el formato de inkaPos: [binario, extensión].
     * null si el archivo no es una imagen que el servidor pueda abrir.
     */
    private function preparar(string $ruta, ImagenProductoService $imagenes): ?array
    {
        $contenido = (string) @file_get_contents($ruta);
        if ($contenido === '') {
            return null;
        }

        $lista = $imagenes->optimizar($contenido);
        if ($lista !== null) {
            return $lista;
        }

        // AVIF y algún otro formato no los reconoce la lectura genérica: se abren con su función propia
        $abrir = ['avif' => 'imagecreatefromavif', 'webp' => 'imagecreatefromwebp', 'bmp' => 'imagecreatefrombmp'][strtolower(pathinfo($ruta, PATHINFO_EXTENSION))] ?? null;
        $imagen = $abrir && function_exists($abrir) ? @$abrir($ruta) : false;
        if (! $imagen) {
            return null;
        }

        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        imagedestroy($imagen);

        return $imagenes->optimizar($png);
    }

    /**
     * La lista del otro sistema: nombre normalizado => archivos distintos que tiene ese nombre.
     *
     * @return Collection<string, list<string>>|null
     */
    private function leerLista(string $ruta): ?Collection
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            $this->error("No se puede leer la lista {$ruta}.");

            return null;
        }

        $lista = collect();

        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($ruta)) as $linea) {
            $campos = explode("\t", $linea);
            if (count($campos) < 2) {
                continue;
            }

            $nombre = self::normalizar($campos[0]);
            // "producto/1725762776.png" -> "1725762776.png": solo el archivo, nunca una ruta que salga de la carpeta
            $archivo = basename(str_replace('\\', '/', trim($campos[1])));

            if ($nombre === '' || $archivo === '' || $archivo === 'NULL') {
                continue;
            }

            $lista[$nombre] = array_values(array_unique([...($lista[$nombre] ?? []), $archivo]));
        }

        if ($lista->isEmpty()) {
            $this->error('La lista está vacía. Cada fila debe tener el nombre del producto, una tabulación y el archivo de su foto.');

            return null;
        }

        return $lista;
    }

    /** "  Úrea 46%\u{A0}x 50 KG " -> "urea 46 x 50 kg": así se comparan los nombres de los dos sistemas. */
    public static function normalizar(string $nombre): string
    {
        $nombre = mb_strtolower($nombre);
        $nombre = strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $nombre));
    }

    private function informar(array $resultado, Collection $genericas, bool $ejecutar): void
    {
        $etiquetas = [
            'importadas' => $ejecutar ? 'Fotos importadas' : 'Fotos que se importarían',
            'ya_tenian' => 'Ya tenían foto (no se tocan)',
            'sin_pareja' => 'Sin producto con ese nombre en el otro sistema',
            'ambiguos' => 'Nombre repetido en el otro sistema con fotos distintas',
            'genericas' => 'Foto genérica compartida (omitida)',
            'sin_archivo' => 'El archivo de la foto no está en la carpeta',
            'ilegibles' => 'El archivo no es una imagen legible',
        ];

        $this->newLine();
        $this->table(['Resultado', 'Productos'], collect($etiquetas)->map(fn ($texto, $clave) => [$texto, count($resultado[$clave])])->values()->all());

        foreach ($genericas as $archivo => $veces) {
            $this->line("Foto genérica: {$archivo} (la usan {$veces} productos del otro sistema)");
        }

        // lo que no entró siempre se lista: es lo que hay que revisar a mano
        foreach (['sin_pareja', 'ambiguos', 'sin_archivo', 'ilegibles', 'genericas'] as $clave) {
            if ($resultado[$clave] === [] || ($clave === 'genericas' && ! $this->option('detalle'))) {
                continue;
            }
            $this->newLine();
            $this->line("<comment>{$etiquetas[$clave]}:</comment>");
            foreach ($resultado[$clave] as $nombre) {
                $this->line("  - {$nombre}");
            }
        }

        if ($this->option('detalle')) {
            foreach (['importadas', 'ya_tenian'] as $clave) {
                $this->newLine();
                $this->line("<comment>{$etiquetas[$clave]}:</comment>");
                foreach ($resultado[$clave] as $nombre) {
                    $this->line("  - {$nombre}");
                }
            }
        }

        if (! $ejecutar) {
            $this->newLine();
            $this->info('No se guardó nada. Repite el comando con --ejecutar para importar.');
        }
    }
}
