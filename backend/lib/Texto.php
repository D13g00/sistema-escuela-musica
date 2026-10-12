<?php
/**
 * Texto — una sola forma de comparar nombres en todo el sistema.
 *
 * Antes cada importador tenía su propia versión y solo quitaban los acentos
 * agudos (á é í ó ú). "Pèrez", con acento grave, no se reconocía como "Pérez"
 * y el importador creaba a la misma alumna dos veces. Aquí se quita cualquier
 * marca: aguda, grave, circunflejo, diéresis, tilde.
 */
final class Texto
{
    private const SIN_MARCAS = [
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y',
    ];

    /** "  Dolores  Pèrez SÁNCHEZ " -> "dolores perez sanchez" */
    public static function normalizar(?string $t): string
    {
        $t = mb_strtolower(trim((string) $t), 'UTF-8');
        $t = strtr($t, self::SIN_MARCAS);
        // Por si llega algún carácter raro que el mapa no cubre.
        if (preg_match('/[^\x00-\x7F]/', $t) && function_exists('iconv')) {
            $t2 = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            if ($t2 !== false && $t2 !== '') $t = strtolower($t2);
        }
        $t = preg_replace('/[^a-z0-9 .\/\-]/', '', $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }
}
