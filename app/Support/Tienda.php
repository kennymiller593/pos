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
        'horario' => null, // una línea por horario
        'facebook' => null,
        'instagram' => null,
        'tiktok' => null,
        // apariencia de la portada
        'portada_estilo' => 'vitrina',
        'portada_imagen' => null,
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

    /** Hosts de confianza que agrega la tienda (expresiones, como las espera TrustHosts). */
    public static function hostsDeConfianza(): array
    {
        $dominio = self::dominio();

        return $dominio ? ['^[a-z0-9-]+\.'.preg_quote($dominio).'$'] : [];
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

        $base = (string) config('app.url');
        $esquema = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        // en local la app corre en un puerto (127.0.0.1:8017): la tienda usa el mismo
        $puerto = request()?->getPort();
        $conPuerto = $puerto && ! in_array($puerto, [80, 443], true) ? ":{$puerto}" : '';

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
