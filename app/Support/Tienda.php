<?php

namespace App\Support;

use App\Models\Empresa;
use Illuminate\Support\Str;

/** Reglas de la tienda en línea: su dominio, qué direcciones son válidas y cómo se arma cada enlace. */
class Tienda
{
    /** Letras, números y guiones; empieza y termina en letra o número. */
    public const PATRON_SLUG = '[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])';

    /** Claves de tienda_config con su valor por defecto. */
    public const CONFIG = [
        'descripcion' => null,
        'color' => 'esmeralda', // uno de la paleta, o "propio"
        'color_propio' => null, // #RRGGBB, cuando color es "propio"
        'color_texto' => null, // texto encima del color propio: "claro" u "oscuro" (sin elegir, el que mejor se lea)
        'mostrar_precios' => false,
        // apagado: la tienda es un catálogo y no mira el stock (nada sale como "Agotado") salvo que el dueño lo pida
        'mostrar_stock' => false,
        'whatsapp' => null,
        'telefono' => null,
        'email' => null,
        'direccion' => null, // una por línea
        'mapa_url' => null, // enlace de Google Maps del local principal
        'mapa_lat' => null, // coordenadas sacadas del enlace (para los datos estructurados del negocio)
        'mapa_lng' => null,
        'horario' => null, // una línea por horario
        'facebook' => null,
        'instagram' => null,
        'tiktok' => null,
        // apariencia de la portada
        'portada_estilo' => 'vitrina',
        'portada_imagen' => null,
        'portada_imagen_movil' => null, // la misma foto, más chica, para el celular
        'favicon' => null, // ícono de la pestaña del navegador (PNG cuadrado); sin él se usa el logo
        'portada_titulo' => null,
        'portada_boton' => null,
        'anuncio' => null,
        // banners de la portada: [imagen, titulo, destino, valor]
        'banners' => [],
        // contenido: páginas de texto de la tienda
        'nosotros' => null,
        'envios' => null,
        'devoluciones' => null,
        'pagos' => null,
        'preguntas' => [], // [pregunta, respuesta]
        // para los buscadores
        'seo_titulo' => null, // título de la portada en Google (si no, el nombre + "Catálogo y pedidos en línea")
        'seo_descripcion' => null, // descripción de la portada en Google (si no, la presentación)
        'categorias_texto' => [], // id de categoría => texto que presenta la categoría
        // "UREA 46% X 50 KG" se muestra como "Urea 46% x 50 kg"
        'nombres_bonitos' => true,
    ];

    public const MAX_BANNERS = 5;

    public const MAX_PREGUNTAS = 12;

    /** A dónde puede llevar un banner al tocarlo. */
    public const DESTINOS = ['ninguno', 'catalogo', 'categoria', 'whatsapp', 'url'];

    /** Estilos de portada que puede elegir la tienda. */
    public const ESTILOS = ['vitrina', 'foto', 'texto'];

    public static function dominio(): ?string
    {
        return config('tienda.dominio');
    }

    /**
     * ¿Este host pertenece a las tiendas y no al sistema? Es cualquier nombre bajo el dominio que no
     * sea la propia app: ahí nunca se atiende el login ni el POS, exista o no esa tienda.
     */
    public static function esHost(string $host): bool
    {
        $dominio = self::dominio();
        $host = mb_strtolower($host);

        return $dominio
            && $host !== mb_strtolower((string) config('tienda.host_app'))
            && str_ends_with($host, ".{$dominio}");
    }

    /** Expresión para la ruta: cualquier nombre bajo el dominio, salvo el de la app. */
    public static function patronDeRuta(): string
    {
        $dominio = (string) self::dominio();
        $hostApp = (string) config('tienda.host_app');

        if (str_ends_with($hostApp, ".{$dominio}")) {
            $propio = preg_quote(substr($hostApp, 0, -strlen(".{$dominio}")), '/');

            // "pos." y no "pos$": el nombre va seguido del resto del dominio ("post" si es una tienda valida)
            return "(?!{$propio}\.)[a-z0-9-]+";
        }

        return '[a-z0-9-]+';
    }

    /** ¿Este host es el del sistema (login, POS...)? En local también valen localhost y la IP. */
    public static function esHostDeLaApp(string $host): bool
    {
        $host = mb_strtolower($host);

        return $host === mb_strtolower((string) config('tienda.host_app'))
            || in_array($host, array_map('mb_strtolower', (array) config('app.trusted_hosts')), true)
            || $host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** ¿Este host es el de una tienda (dirección gratuita o dominio propio) y no el del sistema? */
    public static function esHostDeTienda(string $host): bool
    {
        return self::esHost($host) || (self::conDominiosPropios() && ! self::esHostDeLaApp($host));
    }

    /**
     * Latitud y longitud dentro de un enlace de Google Maps ya desplegado (no el corto):
     * ".../@-9.93,-76.24,17z", "?q=-9.93,-76.24", "!3d-9.93!4d-76.24" o "ll=".
     *
     * @return array{0: float, 1: float}|null
     */
    public static function coordenadasDeMapa(string $url): ?array
    {
        $numero = '(-?\d{1,3}\.\d{2,})';

        foreach (["/@{$numero},{$numero}/", "/[?&](?:q|query|ll|center)={$numero}(?:,|%2C){$numero}/", "/!3d{$numero}!4d{$numero}/"] as $patron) {
            if (preg_match($patron, $url, $m) && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180) {
                return [(float) $m[1], (float) $m[2]];
            }
        }

        return null;
    }

    /**
     * Horario escrito por el dueño -> OpeningHoursSpecification de schema.org. Entiende lo que se
     * escribe normalmente: "Lunes a sábado, 8 a. m. a 6 p. m.", "Lun-Vie 8:00-18:00", "Domingos 8 am - 1 pm".
     * Las líneas que no se entienden se omiten (mejor nada que un horario inventado).
     *
     * @param  list<string>  $lineas
     */
    public static function horarioEstructurado(array $lineas): array
    {
        $dias = ['lunes' => 'Monday', 'martes' => 'Tuesday', 'miercoles' => 'Wednesday', 'jueves' => 'Thursday', 'viernes' => 'Friday', 'sabado' => 'Saturday', 'domingo' => 'Sunday'];
        $orden = array_values($dias);
        $resultado = [];

        foreach ($lineas as $linea) {
            $texto = mb_strtolower(Str::ascii($linea));
            $texto = preg_replace('/\b(lun|mar|mie|jue|vie|sab|dom)\b\.?/', '$1xx', $texto); // abreviaturas
            $texto = str_replace(['lunxx', 'marxx', 'miexx', 'juexx', 'viexx', 'sabxx', 'domxx'], ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'], $texto);

            // qué días
            preg_match_all('/\b(lunes|martes|miercoles|jueves|viernes|sabado|domingo)s?\b/', $texto, $encontrados);
            $nombres = array_values(array_unique($encontrados[1]));
            if (preg_match('/todos los dias|diario|lunes a domingo/', $texto)) {
                $elegidos = $orden;
            } elseif (count($nombres) >= 2 && preg_match('/\b'.$nombres[0].'s?\s*(a|al|-|–|hasta)\s*(el\s+)?'.$nombres[1].'/', $texto)) {
                $desde = array_search($dias[$nombres[0]], $orden, true);
                $hasta = array_search($dias[$nombres[1]], $orden, true);
                $elegidos = $hasta >= $desde ? array_slice($orden, $desde, $hasta - $desde + 1) : [...array_slice($orden, $desde), ...array_slice($orden, 0, $hasta + 1)];
            } else {
                $elegidos = array_map(fn ($n) => $dias[$n], $nombres);
            }

            // qué horas: dos horas (con o sin minutos, con o sin a. m. / p. m.)
            $hora = '(\d{1,2})(?::(\d{2}))?\s*(a\.?\s?m\.?|p\.?\s?m\.?|am|pm|hrs?\.?|h\b)?';
            if (! $elegidos || ! preg_match("/{$hora}\s*(?:a|-|–|hasta)\s*{$hora}/", $texto, $h)) {
                continue;
            }

            $aHora = function (string $hh, string $mm, string $sufijo, ?int $referencia) {
                $n = (int) $hh;
                $sufijo = preg_replace('/[^ap]/', '', $sufijo);
                if ($sufijo === 'p' && $n < 12) {
                    $n += 12;
                } elseif ($sufijo === 'a' && $n === 12) {
                    $n = 0;
                } elseif ($sufijo === '' && $referencia !== null && $n <= 12 && $n < $referencia) {
                    $n += 12; // "8 a 6" sin a. m. / p. m.: la segunda es de la tarde
                }

                return $n <= 23 ? sprintf('%02d:%02d', $n, (int) ($mm ?: 0)) : null;
            };

            $abre = $aHora($h[1], $h[2] ?? '', $h[3] ?? '', null);
            $cierra = $aHora($h[4], $h[5] ?? '', $h[6] ?? '', (int) $h[1]);

            if ($abre && $cierra && $cierra > $abre) {
                $resultado[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $elegidos, 'opens' => $abre, 'closes' => $cierra];
            }
        }

        return $resultado;
    }

    /** ¿Se atienden dominios propios (www.agrocampo.com)? Solo si el servicio está configurado. */
    public static function conDominiosPropios(): bool
    {
        return filled(config('tienda.origen')) && in_array(config('tienda.dominios'), ['cloudflare', 'simulado'], true);
    }

    /**
     * Expresión para la ruta de un dominio propio: cualquier nombre con terminación de letras
     * (nunca una IP ni localhost) que no sea nuestro dominio ni uno bajo él, ni el host del sistema.
     */
    public static function patronDeDominioPropio(): string
    {
        $nuestro = preg_quote((string) self::dominio(), '/');
        $hostApp = preg_quote((string) config('tienda.host_app'), '/');

        return "(?!(?:[a-z0-9-]+\.)*{$nuestro}$)(?!{$hostApp}$)[a-z0-9][a-z0-9.-]*\.[a-z]{2,24}";
    }

    /** Hosts de confianza que agrega la tienda (expresiones, como las espera TrustHosts). */
    public static function hostsDeConfianza(): array
    {
        $dominio = self::dominio();
        $patrones = $dominio ? ['^[a-z0-9-]+\.'.preg_quote($dominio).'$'] : [];

        // con dominios propios cualquier host puede ser una tienda: el que no esté registrado cae en 404,
        // y las rutas del sistema solo se atienden en su propio host (SoloDominioPrincipal)
        if ($dominio && self::conDominiosPropios()) {
            $patrones[] = '^[a-z0-9.-]+$';
        }

        return $patrones;
    }

    /** ¿La empresa tiene su dominio propio contratado, registrado y con certificado? */
    public static function dominioActivo(Empresa $empresa): bool
    {
        return self::conDominiosPropios()
            && (bool) $empresa->tienda_dominio_habilitado
            && filled($empresa->tienda_dominio)
            && $empresa->tienda_dominio_estado === 'activo';
    }

    /** Enlace absoluto de la tienda de una empresa: su dominio propio si está activo, si no su dirección gratuita. */
    public static function urlDe(Empresa $empresa, string $ruta = '/'): ?string
    {
        if (! self::dominioActivo($empresa)) {
            return self::url($empresa->tienda_slug, $ruta);
        }

        return self::esquemaYPuerto()[0]."://{$empresa->tienda_dominio}".self::esquemaYPuerto()[1].'/'.ltrim($ruta, '/');
    }

    /** @return array{0: string, 1: string} esquema de la app y ":puerto" si corre en uno distinto del estándar */
    private static function esquemaYPuerto(): array
    {
        $esquema = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        // en local la app corre en un puerto (127.0.0.1:8017): la tienda usa el mismo
        $puerto = request()?->getPort();

        return [$esquema, $puerto && ! in_array($puerto, [80, 443], true) ? ":{$puerto}" : ''];
    }

    /**
     * Texto para una dirección: "ÑANDÚ Fósforo 1/2 litro" -> "nandu-fosforo-1-2-litro".
     * Todo lo que no es letra ni número separa palabras (una barra no pega "1/2" en "12").
     */
    public static function slug(string $texto): string
    {
        return Str::slug((string) preg_replace('/[^\pL\pN]+/u', ' ', $texto));
    }

    public static function slugValido(string $slug): bool
    {
        return (bool) preg_match('/^'.self::PATRON_SLUG.'$/', $slug) && ! str_contains($slug, '--');
    }

    public static function reservado(string $slug): bool
    {
        return in_array($slug, config('tienda.reservados', []), true);
    }

    /** Dirección sugerida a partir del nombre del negocio: "Agro El Sembrador S.A.C." -> "agro-el-sembrador". */
    public static function sugerirSlug(Empresa $empresa): string
    {
        $nombre = $empresa->nombre_comercial ?: $empresa->razon_social;
        // la forma societaria no aporta a la dirección
        $nombre = preg_replace('/\b(s\.?\s?a\.?\s?c\.?|e\.?\s?i\.?\s?r\.?\s?l\.?|s\.?\s?r\.?\s?l\.?|s\.?\s?a\.?)\s*$/i', '', (string) $nombre);
        $base = trim(Str::limit(Str::slug($nombre), 30, ''), '-');

        if (! self::slugValido($base) || self::reservado($base)) {
            $base = 'tienda-'.substr((string) $empresa->ruc, -6);
        }

        $slug = $base;
        for ($i = 2; Empresa::where('tienda_slug', $slug)->whereKeyNot($empresa->id)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Enlace absoluto de la tienda de una empresa (null si aún no eligió dirección). */
    public static function url(?string $slug, string $ruta = '/'): ?string
    {
        $dominio = self::dominio();

        if (! $slug || ! $dominio) {
            return null;
        }

        [$esquema, $conPuerto] = self::esquemaYPuerto();

        return "{$esquema}://{$slug}.{$dominio}{$conPuerto}/".ltrim($ruta, '/');
    }

    /** tienda_config con todos sus valores (lo que falte toma el valor por defecto). */
    public static function config(Empresa $empresa): array
    {
        $config = array_merge(self::CONFIG, array_intersect_key((array) $empresa->tienda_config, self::CONFIG));

        $propio = $config['color'] === 'propio' && self::esColor((string) $config['color_propio']);

        if (! $propio && ! isset(config('tienda.colores')[$config['color']])) {
            $config['color'] = self::CONFIG['color'];
        }

        foreach (['banners', 'preguntas'] as $lista) {
            $config[$lista] = array_values(array_filter((array) $config[$lista], 'is_array'));
        }
        $config['categorias_texto'] = array_filter((array) $config['categorias_texto'], 'is_string');

        // el estilo "foto" sin foto no tiene qué mostrar: vuelve a la vitrina
        if (! in_array($config['portada_estilo'], self::ESTILOS, true) || ($config['portada_estilo'] === 'foto' && blank($config['portada_imagen']))) {
            $config['portada_estilo'] = self::CONFIG['portada_estilo'];
        }

        return $config;
    }

    /** Texto que va encima del color de la tienda: blanco u oscuro. */
    private const SOBRE = ['claro' => '#FFFFFF', 'oscuro' => '#0F172A'];

    /**
     * Los tonos de la tienda: [principal, al pasar el cursor, tinte suave,
     * texto que va encima del principal, el principal usado como texto sobre fondo blanco].
     */
    public static function colores(array $config): array
    {
        if ($config['color'] === 'propio' && self::esColor((string) $config['color_propio'])) {
            $color = strtoupper($config['color_propio']);
            $sobre = self::SOBRE[$config['color_texto'] ?? ''] ?? self::SOBRE[self::textoQueSeLee($color)];

            // un color claro (amarillo, verde limón) no se lee como texto sobre blanco: ahí va más oscuro
            $comoTexto = $color;
            for ($i = 0; $i < 12 && self::contraste($comoTexto, '#FFFFFF') < 4.5; $i++) {
                $comoTexto = self::mezclar($comoTexto, '#000000', 0.1);
            }

            return [$color, self::mezclar($color, '#000000', 0.14), self::mezclar($color, '#FFFFFF', 0.92), $sobre, $comoTexto];
        }

        $paleta = config('tienda.colores')[$config['color']] ?? config('tienda.colores')[self::CONFIG['color']];

        return [...$paleta, self::SOBRE['claro'], $paleta[0]];
    }

    public static function esColor(string $color): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    /** "claro" u "oscuro": el texto que mejor se lee encima de ese color. */
    public static function textoQueSeLee(string $color): string
    {
        return self::contraste($color, self::SOBRE['claro']) >= self::contraste($color, self::SOBRE['oscuro']) ? 'claro' : 'oscuro';
    }

    /** Contraste entre dos colores, de 1 (iguales) a 21 (negro sobre blanco). */
    public static function contraste(string $a, string $b): float
    {
        $luminancia = function (string $color) {
            [$r, $g, $v] = array_map(function (string $par) {
                $c = hexdec($par) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($color, 1), 2));

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $v;
        };

        [$clara, $oscura] = [max($luminancia($a), $luminancia($b)), min($luminancia($a), $luminancia($b))];

        return ($clara + 0.05) / ($oscura + 0.05);
    }

    /** Mezcla dos colores: 0 deja el primero, 1 deja el segundo. */
    private static function mezclar(string $color, string $con, float $cuanto): string
    {
        $a = array_map('hexdec', str_split(substr($color, 1), 2));
        $b = array_map('hexdec', str_split(substr($con, 1), 2));

        return sprintf('#%02X%02X%02X', ...array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $cuanto), $a, $b));
    }

    /** Unidades y códigos de formulación que se escriben de una forma fija. */
    private const UNIDADES = ['ML' => 'ml', 'KG' => 'kg', 'G' => 'g', 'GR' => 'gr', 'LT' => 'lt', 'L' => 'L', 'CC' => 'cc', 'UND' => 'und', 'UN' => 'un', 'X' => 'x'];

    private const CODIGOS = ['SC', 'EC', 'WP', 'WG', 'SL', 'EW', 'OD', 'CS', 'FS', 'SG', 'DF', 'ME', 'ZC', 'SE', 'SP', 'WDG', 'UV', 'PVC', 'HDPE', 'LED', 'ATV', 'NPK'];

    /**
     * Un nombre escrito todo en mayúsculas ("UREA 46% X 50 KG") se muestra como oración
     * ("Urea 46% x 50 kg"). Los que ya tienen minúsculas se dejan como están.
     */
    public static function nombreBonito(string $nombre): string
    {
        if (! preg_match('/\p{Lu}/u', $nombre) || preg_match('/\p{Ll}/u', $nombre)) {
            return $nombre;
        }

        $palabras = preg_split('/(\s+)/u', trim($nombre), -1, PREG_SPLIT_DELIM_CAPTURE);
        $primera = true;

        foreach ($palabras as $i => $palabra) {
            if (trim($palabra) === '') {
                continue;
            }
            $limpia = trim($palabra, '()[],.;:');

            if (isset(self::UNIDADES[$limpia])) {
                $palabras[$i] = str_replace($limpia, self::UNIDADES[$limpia], $palabra);
            } elseif (in_array($limpia, self::CODIGOS, true) || preg_match('/\d/', $limpia)) {
                // "250 SC", "100ML", "5X1": se quedan como están
            } else {
                $minuscula = mb_strtolower($palabra, 'UTF-8');
                $palabras[$i] = $primera ? mb_strtoupper(mb_substr($minuscula, 0, 1), 'UTF-8').mb_substr($minuscula, 1) : $minuscula;
            }
            $primera = false;
        }

        return implode('', $palabras);
    }

    /** El nombre tal como lo muestra la tienda, según la preferencia del dueño. */
    public static function nombreVisible(string $nombre, array $config): string
    {
        return ($config['nombres_bonitos'] ?? true) ? self::nombreBonito($nombre) : $nombre;
    }

    /** "a; b\n c" -> ["a", "b", "c"]: una dirección u horario por línea (también se acepta el punto y coma). */
    public static function lineas(?string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string) $texto))));
    }

    /** ¿Es un enlace de Google Maps? Solo se aceptan esos: el campo no sirve para enlazar a cualquier sitio. */
    public static function esEnlaceDeMapa(string $url): bool
    {
        $partes = parse_url($url);
        $host = mb_strtolower((string) ($partes['host'] ?? ''));

        if (($partes['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        // enlaces cortos de "Compartir" y la página del negocio
        if (in_array($host, ['maps.app.goo.gl', 'goo.gl', 'g.page', 'g.co'], true)) {
            return true;
        }

        // maps.google.com, o google.com/maps (y sus dominios por país: google.com.pe)
        return (bool) preg_match('/^maps\.google\.[a-z.]{2,10}$/', $host)
            || (preg_match('/^(www\.)?google\.[a-z.]{2,10}$/', $host) && str_starts_with((string) ($partes['path'] ?? ''), '/maps'));
    }

    /** Número listo para wa.me: solo dígitos y con el 51 de Perú si es un celular de 9 dígitos. */
    public static function numeroWhatsapp(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);

        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) === 9 && $digitos[0] === '9' ? "51{$digitos}" : $digitos;
    }

    public static function enlaceWhatsapp(?string $telefono, string $mensaje = ''): ?string
    {
        $numero = self::numeroWhatsapp($telefono);

        return $numero ? "https://wa.me/{$numero}".($mensaje !== '' ? '?text='.rawurlencode($mensaje) : '') : null;
    }
}
