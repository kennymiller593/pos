<?php

namespace App\IA;

use App\Models\AgenteIa;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Mira la foto de un cultivo y dice qué tiene (el /diagnostico de la tienda en línea).
 * No conversa ni ve el catálogo: devuelve el diagnóstico y las palabras con que la tienda busca
 * los productos en su propio catálogo (sin gastar tokens en listar productos).
 *
 * Las instrucciones de agrónomo las edita la plataforma en el agente; el formato de la respuesta
 * es fijo porque la tienda lo lee. Se pide JSON en texto (no "salida estructurada") para que Gemini
 * y OpenAI respondan igual; si llega mal formado se pasa al siguiente modelo de la cadena.
 */
class DiagnosticoDeCultivo implements Agent
{
    use Promptable;

    public const TIPOS = ['plaga', 'enfermedad', 'deficiencia', 'estres', 'maleza', 'sano', 'indeterminado'];

    public function __construct(private readonly AgenteIa $agente) {}

    public function instructions(): string
    {
        return trim($this->agente->instrucciones)."\n\n".<<<'TXT'
        FORMATO DE RESPUESTA (obligatorio): responde SOLO con un objeto JSON válido, sin texto antes ni después y sin bloques de código, con estas claves:
        {
          "es_cultivo": true o false (true solo si la foto muestra una planta o cultivo),
          "cultivo": "cultivo identificado o cadena vacía",
          "problema": "nombre común del problema, o \"Planta sana\"",
          "tipo": uno de "plaga", "enfermedad", "deficiencia", "estres", "maleza", "sano", "indeterminado",
          "severidad": uno de "ninguna", "leve", "moderada", "severa",
          "confianza": uno de "alta", "media", "baja",
          "resumen": "una o dos oraciones para el agricultor, en español sencillo",
          "sintomas": ["síntoma observado", ...] (máximo 4),
          "recomendaciones": ["paso de manejo", ...] (máximo 4),
          "busqueda": ["término", ...] (de 0 a 3 términos para buscar productos; vacío si está sana o no es un cultivo)
        }
        TXT;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    /** Holgado: en Gemini 2.5 el razonamiento interno también gasta de este tope. */
    public function maxTokens(): int
    {
        return 4000;
    }

    /**
     * Lee el JSON de la respuesta; null si no sirve (vacío, cortado o sin los campos que la tienda necesita).
     *
     * @return array{es_cultivo: bool, cultivo: string, problema: string, tipo: string, severidad: string, confianza: string, resumen: string, sintomas: list<string>, recomendaciones: list<string>, busqueda: list<string>}|null
     */
    public static function leer(string $texto): ?array
    {
        $texto = trim($texto);
        // algunos modelos envuelven el JSON en ```json ... ``` o le agregan una frase
        if (preg_match('/\{.*\}/s', $texto, $m)) {
            $texto = $m[0];
        }
        $datos = json_decode($texto, true);
        if (! is_array($datos) || ! array_key_exists('es_cultivo', $datos)) {
            return null;
        }

        $cadena = fn ($v, int $max) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
        $lista = fn ($v, int $cuantos, int $max) => collect(is_array($v) ? $v : [])
            ->filter(fn ($x) => is_scalar($x) && trim((string) $x) !== '')
            ->map(fn ($x) => $cadena($x, $max))
            ->unique()->take($cuantos)->values()->all();
        $opcion = fn ($v, array $validas, string $porDefecto) => in_array($v = mb_strtolower($cadena($v, 30)), $validas, true) ? $v : $porDefecto;

        $esCultivo = filter_var($datos['es_cultivo'], FILTER_VALIDATE_BOOL);
        $problema = $cadena($datos['problema'] ?? '', 120);
        if ($esCultivo && $problema === '') {
            return null;
        }

        return [
            'es_cultivo' => $esCultivo,
            'cultivo' => $cadena($datos['cultivo'] ?? '', 60),
            'problema' => $problema,
            'tipo' => $opcion($datos['tipo'] ?? '', self::TIPOS, 'indeterminado'),
            'severidad' => $opcion($datos['severidad'] ?? '', ['ninguna', 'leve', 'moderada', 'severa'], 'leve'),
            'confianza' => $opcion($datos['confianza'] ?? '', ['alta', 'media', 'baja'], 'media'),
            'resumen' => $cadena($datos['resumen'] ?? '', 400),
            'sintomas' => $lista($datos['sintomas'] ?? [], 4, 200),
            'recomendaciones' => $lista($datos['recomendaciones'] ?? [], 4, 240),
            'busqueda' => $esCultivo ? $lista($datos['busqueda'] ?? [], 3, 40) : [],
        ];
    }
}
