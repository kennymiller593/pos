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
        'color' => 'esmeralda',
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
    ];

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

        if (! isset(config('tienda.colores')[$config['color']])) {
            $config['color'] = self::CONFIG['color'];
        }

        // el estilo "foto" sin foto no tiene qué mostrar: vuelve a la vitrina
        if (! in_array($config['portada_estilo'], self::ESTILOS, true) || ($config['portada_estilo'] === 'foto' && blank($config['portada_imagen']))) {
            $config['portada_estilo'] = self::CONFIG['portada_estilo'];
        }

        return $config;
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
