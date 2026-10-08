<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La caché de borde de Cloudflare guarda las páginas de las tiendas unos segundos. Cuando el dueño
 * cambia algo, se le pide a Cloudflare que olvide esas páginas para que el cambio se vea al momento.
 * Si no está configurado (o falla), no pasa nada: la caché caduca sola en un minuto.
 */
class CloudflareService
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /** Cloudflare acepta hasta 30 direcciones por pedido. */
    private const POR_PEDIDO = 30;

    public static function configurado(): bool
    {
        return (bool) config('tienda.purga')
            && filled(config('services.cloudflare.token'))
            && filled(config('services.cloudflare.zona'));
    }

    /** @param  list<string>  $urls  direcciones completas (https://tienda.ejemplo.com/catalogo) */
    public function purgar(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));

        if ($urls === [] || ! self::configurado()) {
            return;
        }

        foreach (array_chunk($urls, self::POR_PEDIDO) as $grupo) {
            try {
                $respuesta = Http::withToken((string) config('services.cloudflare.token'))
                    ->acceptJson()
                    ->timeout(8)
                    ->post(self::API.'/zones/'.config('services.cloudflare.zona').'/purge_cache', ['files' => $grupo]);

                if (! $respuesta->successful()) {
                    // lo más común: el token no tiene el permiso "Cache Purge". La caché caduca sola igual.
                    Log::warning('Cloudflare no purgó la caché de la tienda', ['estado' => $respuesta->status(), 'errores' => $respuesta->json('errors')]);
                }
            } catch (\Throwable $e) {
                Log::warning('Cloudflare no respondió al purgar la caché de la tienda', ['error' => $e->getMessage()]);
            }
        }
    }
}
