<?php

namespace App\Console\Commands;

use App\Support\CertificadoDigital;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Cifra los certificados digitales que quedaron en claro antes de que el
 * modelo Empresa los tratara como `encrypted`, y anota su vencimiento.
 * Es idempotente: lo ya cifrado se deja igual.
 */
class CifrarCertificados extends Command
{
    protected $signature = 'empresa:cifrar-certificados';

    protected $description = 'Cifra los certificados digitales guardados en claro y registra su fecha de vencimiento';

    public function handle(): int
    {
        $cifrados = 0;
        $fechas = 0;

        foreach (DB::table('empresas')->whereNotNull('certificado_digital')->get(['id', 'razon_social', 'certificado_digital', 'clave_certificado']) as $fila) {
            $contenido = $fila->certificado_digital;

            try {
                $contenido = Crypt::decryptString($fila->certificado_digital);
            } catch (DecryptException) {
                DB::table('empresas')->where('id', $fila->id)->update(['certificado_digital' => Crypt::encryptString($contenido)]);
                $cifrados++;
            }

            $clave = null;
            try {
                $clave = $fila->clave_certificado ? Crypt::decryptString($fila->clave_certificado) : null;
            } catch (DecryptException) {
                $clave = $fila->clave_certificado;
            }

            try {
                $vence = CertificadoDigital::analizar($contenido, $clave)['vence_en'];
                DB::table('empresas')->where('id', $fila->id)->update(['certificado_vence_en' => $vence->toDateString()]);
                $fechas++;
            } catch (\Throwable $e) {
                $this->warn("{$fila->razon_social}: certificado no legible ({$e->getMessage()}).");
            }
        }

        $this->info("Certificados cifrados: {$cifrados}. Vencimientos registrados: {$fechas}.");

        return self::SUCCESS;
    }
}
