<?php

namespace App\Services;

use App\Jobs\VerificarDominioTienda;
use App\Models\Empresa;
use App\Support\Tienda;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Dominio propio de una tienda (www.agrocampo.com). El dueño apunta un CNAME a nuestro origen
 * (TIENDA_ORIGEN, p. ej. tiendas.inkanet.pro) y Cloudflare emite el certificado para su dominio
 * ("Cloudflare for SaaS"). Aquí se comprueba el CNAME, se registra el dominio en Cloudflare y se
 * sigue el estado hasta que el certificado está activo.
 *
 * Con TIENDA_DOMINIOS=simulado (solo desarrollo) no se consulta DNS ni Cloudflare: todo queda activo al instante.
 */
class DominioTiendaService
{
    public const ESTADOS = ['pendiente', 'verificando', 'activo', 'error'];

    /** Segundos de nivel ("com", "org"...) bajo los que un dominio de dos o tres partes es un dominio raíz: agrocampo.com.pe */
    private const SEGUNDOS_NIVELES = ['com', 'org', 'net', 'edu', 'gob', 'gov', 'mil', 'nom', 'info', 'biz'];

    private const API = 'https://api.cloudflare.com/client/v4';

    /** @param  ?Closure(string): ?string  $resolverDns  devuelve el destino del CNAME de un host (las pruebas lo simulan) */
    public function __construct(private readonly ?Closure $resolverDns = null) {}

    /** ¿Está configurado el servicio (origen y proveedor)? Sin esto, la sección no se ofrece. */
    public static function disponible(): bool
    {
        return filled(config('tienda.origen')) && in_array(config('tienda.dominios'), ['cloudflare', 'simulado'], true);
    }

    public static function simulado(): bool
    {
        return config('tienda.dominios') === 'simulado';
    }

    // ---------------- el dominio que escribe el dueño ----------------

    /**
     * Lo que escribió el dueño, como nombre de host: "https://AgroCampo.com/" -> "www.agrocampo.com".
     * Un dominio raíz (agrocampo.com, agrocampo.com.pe) pasa a www, porque la raíz no admite CNAME.
     * Devuelve null si no es un dominio.
     */
    public function normalizar(string $texto): ?string
    {
        $host = mb_strtolower(trim($texto));
        $host = preg_replace('~^[a-z]+://~', '', $host);
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        $host = rtrim($host, '.');

        if ($host === '' || strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) {
            return null;
        }

        $partes = explode('.', $host);
        $esRaiz = count($partes) === 2 || (count($partes) === 3 && in_array($partes[1], self::SEGUNDOS_NIVELES, true));

        return $esRaiz ? "www.{$host}" : $host;
    }

    /** El dominio sin el "www.": agrocampo.com, el que el dueño redirige a la tienda desde su registrador. */
    public static function raiz(string $dominio): string
    {
        return preg_replace('/^www\./', '', $dominio);
    }

    /** Por qué no se acepta ese dominio para esta empresa, o null si se acepta. */
    public function rechazo(string $dominio, Empresa $empresa): ?string
    {
        $nuestro = (string) Tienda::dominio();
        $hostApp = (string) config('tienda.host_app');

        if ($dominio === $nuestro || str_ends_with($dominio, ".{$nuestro}") || $dominio === $hostApp || str_ends_with($dominio, ".{$hostApp}")) {
            return "Ese es un dominio de inkaPos. Tu dirección gratuita ya es {$empresa->tienda_slug}.{$nuestro}; aquí va tu propio dominio.";
        }

        if ($dominio === (string) config('tienda.origen')) {
            return 'Ese nombre es el destino del CNAME, no puede ser tu dominio.';
        }

        if (Empresa::where('tienda_dominio', $dominio)->whereKeyNot($empresa->id)->exists()) {
            return 'Ese dominio ya está en uso en otra tienda de inkaPos. Si es tuyo, escríbenos.';
        }

        return null;
    }

    // ---------------- estados ----------------

    /** Guarda el dominio (nuevo o cambiado) en estado pendiente e intenta verificarlo de una vez. */
    public function guardar(Empresa $empresa, string $dominio): void
    {
        if ($empresa->tienda_dominio !== $dominio) {
            // el dominio anterior deja de estar registrado en Cloudflare
            $this->eliminarEnCloudflare($empresa->tienda_dominio_externo_id);

            $empresa->forceFill([
                'tienda_dominio' => $dominio,
                'tienda_dominio_estado' => 'pendiente',
                'tienda_dominio_externo_id' => null,
                'tienda_dominio_detalle' => null,
                'tienda_dominio_activado_en' => null,
            ])->save();
        }

        $this->verificar($empresa);
    }

    /**
     * Avanza el dominio todo lo que se pueda ahora mismo: comprueba el CNAME, lo registra en
     * Cloudflare si hace falta y deja un trabajo en cola siguiendo la emisión del certificado.
     */
    public function verificar(Empresa $empresa): void
    {
        $dominio = (string) $empresa->tienda_dominio;

        if ($dominio === '') {
            return;
        }

        if (self::simulado()) {
            $this->activar($empresa);

            return;
        }

        $origen = (string) config('tienda.origen');
        $destino = $this->cname($dominio);

        if ($destino !== $origen) {
            $empresa->forceFill([
                'tienda_dominio_estado' => 'pendiente',
                'tienda_dominio_detalle' => $destino === null
                    ? "Todavía no encontramos el registro CNAME de {$dominio}. Créalo apuntando a {$origen} y espera unos minutos: los cambios de DNS tardan en propagarse."
                    : "El CNAME de {$dominio} apunta a {$destino}, pero debe apuntar a {$origen}.",
            ])->save();

            return;
        }

        try {
            if (! $empresa->tienda_dominio_externo_id) {
                $empresa->forceFill(['tienda_dominio_externo_id' => $this->registrar($dominio)])->save();
            }

            $empresa->forceFill(['tienda_dominio_estado' => 'verificando', 'tienda_dominio_detalle' => null])->save();
            VerificarDominioTienda::dispatch($empresa->id);
        } catch (RequestException $e) {
            report($e);
            $empresa->forceFill([
                'tienda_dominio_estado' => 'error',
                'tienda_dominio_detalle' => 'No pudimos registrar tu dominio en este momento. Vuelve a intentarlo en unos minutos.',
            ])->save();
        }
    }

    /**
     * Consulta a Cloudflare si el certificado ya está emitido y actualiza el estado.
     * Devuelve true cuando ya no hay nada que esperar (activo o error).
     */
    public function revisar(Empresa $empresa): bool
    {
        if ($empresa->tienda_dominio_estado !== 'verificando' || ! $empresa->tienda_dominio_externo_id) {
            return true;
        }

        try {
            $resultado = $this->cf()->get('/custom_hostnames/'.$empresa->tienda_dominio_externo_id)->throw()->json('result');
        } catch (RequestException $e) {
            report($e);

            return false;
        }

        if (($resultado['status'] ?? null) === 'active' && ($resultado['ssl']['status'] ?? null) === 'active') {
            $this->activar($empresa);

            return true;
        }

        $fallas = [...($resultado['ssl']['validation_errors'] ?? []), ...($resultado['verification_errors'] ?? [])];
        $mensajes = array_values(array_filter(array_map(fn ($f) => is_array($f) ? ($f['message'] ?? null) : $f, $fallas)));

        if ($mensajes) {
            $empresa->forceFill([
                'tienda_dominio_estado' => 'error',
                'tienda_dominio_detalle' => 'No se pudo emitir el certificado: '.implode(' ', $mensajes).' Revisa el CNAME y vuelve a verificar.',
            ])->save();

            return true;
        }

        return false;
    }

    /** El dominio dejó de usarse: se borra de Cloudflare y la tienda vuelve a su dirección gratuita. */
    public function quitar(Empresa $empresa): void
    {
        $this->eliminarEnCloudflare($empresa->tienda_dominio_externo_id);

        $empresa->forceFill([
            'tienda_dominio' => null,
            'tienda_dominio_estado' => null,
            'tienda_dominio_externo_id' => null,
            'tienda_dominio_detalle' => null,
            'tienda_dominio_activado_en' => null,
        ])->save();
    }

    private function activar(Empresa $empresa): void
    {
        $empresa->forceFill([
            'tienda_dominio_estado' => 'activo',
            'tienda_dominio_detalle' => null,
            'tienda_dominio_activado_en' => $empresa->tienda_dominio_activado_en ?? now(),
        ])->save();
    }

    // ---------------- DNS ----------------

    /** A dónde apunta el CNAME de un host (en minúsculas, sin punto final), o null si no tiene. */
    public function cname(string $host): ?string
    {
        if ($this->resolverDns) {
            return ($this->resolverDns)($host);
        }

        $registros = @dns_get_record($host, DNS_CNAME) ?: [];
        $destino = $registros[0]['target'] ?? null;

        return $destino ? mb_strtolower(rtrim($destino, '.')) : null;
    }

    // ---------------- Cloudflare ----------------

    private function cf(): PendingRequest
    {
        return Http::withToken((string) config('services.cloudflare.token'))
            ->acceptJson()
            ->timeout(15)
            ->baseUrl(self::API.'/zones/'.config('services.cloudflare.zona'));
    }

    /** Registra el dominio como "custom hostname" y devuelve su id. Si ya existía (de un intento anterior), reutiliza ese. */
    private function registrar(string $dominio): string
    {
        $respuesta = $this->cf()->post('/custom_hostnames', [
            'hostname' => $dominio,
            'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
        ]);

        if ($respuesta->successful()) {
            return (string) $respuesta->json('result.id');
        }

        // 1407: "Duplicate custom hostname found"
        if (collect($respuesta->json('errors', []))->contains(fn ($e) => ($e['code'] ?? null) === 1407)) {
            $existente = $this->cf()->get('/custom_hostnames', ['hostname' => $dominio])->throw()->json('result.0.id');

            if ($existente) {
                return (string) $existente;
            }
        }

        $respuesta->throw();

        return ''; // no se llega: throw() lanza
    }

    private function eliminarEnCloudflare(?string $id): void
    {
        if (! $id || self::simulado()) {
            return;
        }

        try {
            $this->cf()->delete('/custom_hostnames/'.$id);
        } catch (\Throwable $e) {
            // si no se pudo borrar queda huérfano en Cloudflare; no impide seguir
            report($e);
        }
    }
}
