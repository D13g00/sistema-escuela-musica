<?php
/**
 * LectorXlsx — lee un archivo .xlsx sin librerías externas.
 *
 * Un .xlsx es un ZIP con XML adentro. PHP ya trae ZipArchive y SimpleXML
 * en XAMPP, así que no hace falta Composer ni PhpSpreadsheet.
 *
 * Devuelve la hoja como una rejilla asociativa:
 *   $rejilla[fila][columna] = valor      (fila y columna empiezan en 1)
 *
 * Lo que resuelve:
 *   · cadenas compartidas (sharedStrings.xml) y cadenas en línea
 *   · celdas combinadas: el valor queda en la celda superior izquierda
 *   · fechas de Excel (número de serie) -> 'YYYY-MM-DD'
 *   · números con formato de moneda -> float limpio
 */
require_once __DIR__ . '/LectorZipSimple.php';

class LectorXlsx
{
    private array $cadenas = [];
    private array $hojas = [];
    /** ZipArchive si la extensión está activa; si no, el lector propio */
    private $zip = null;
    private bool $zipNativo = true;
    private array $formatosFecha = [];
    /** Color de relleno de cada estilo: índice de cellXfs -> 'rgb:RRGGBB' | 'theme:N:tinte' */
    private array $colorDeEstilo = [];

    public function __construct(string $ruta)
    {
        if (!is_readable($ruta)) {
            throw new Exception('No se puede leer el archivo.');
        }
        if (class_exists('ZipArchive')) {
            $this->zip = new ZipArchive();
            if ($this->zip->open($ruta) !== true) {
                throw new Exception('El archivo no es un .xlsx válido. Si es .xls antiguo, ábrelo en Excel y guárdalo como .xlsx.');
            }
        } else {
            // Sin la extensión zip de PHP: se usa el lector propio, que solo
            // necesita zlib. Así el sistema no depende de php.ini.
            if (!function_exists('gzinflate')) {
                throw new Exception('Este PHP no puede abrir archivos .xlsx: le faltan las extensiones zip y zlib. Actívalas en php.ini.');
            }
            $this->zipNativo = false;
            $this->zip = new LectorZipSimple($ruta);
        }

        $this->cargarCadenas();
        $this->cargarFormatosFecha();
        $this->cargarHojas();
    }

    public function __destruct()
    {
        if ($this->zip && $this->zipNativo) { @$this->zip->close(); }
    }

    /** Cómo se abrió el archivo, para poder reportarlo */
    public function motor(): string
    {
        return $this->zipNativo ? 'ZipArchive' : 'lector propio';
    }

    /** Nombres de las hojas del libro, en orden */
    public function hojas(): array
    {
        return array_column($this->hojas, 'nombre');
    }

    /**
     * Devuelve la rejilla de una hoja.
     * @param int|string $hoja índice (0 = primera) o nombre de la hoja
     */
    public function rejilla($hoja = 0): array
    {
        $destino = null;
        foreach ($this->hojas as $i => $h) {
            if ((is_int($hoja) && $i === $hoja) || (is_string($hoja) && $h['nombre'] === $hoja)) {
                $destino = $h; break;
            }
        }
        if (!$destino) throw new Exception('No se encontró esa hoja en el archivo.');

        $xml = $this->leerXml($destino['ruta']);
        if (!$xml) throw new Exception('La hoja está vacía o dañada.');

        $rejilla = [];
        $filasLeidas = 0;
        foreach ($xml->sheetData->row as $fila) {
            // Tope defensivo: ninguna hoja de la escuela pasa de unos cientos de
            // filas. 50 000 es un techo que corta un archivo diseñado para colgar.
            if (++$filasLeidas > 50000) break;
            $nf = (int) $fila['r'];
            foreach ($fila->c as $celda) {
                [$nc, ] = $this->referencia((string) $celda['r']);
                $valor = $this->valorCelda($celda);
                if ($valor !== null && $valor !== '') {
                    $rejilla[$nf][$nc] = $valor;
                }
            }
        }

        // Celdas combinadas: replica el valor de la esquina al resto del rango,
        // para que un bloque no pierda su encabezado.
        if (isset($xml->mergeCells)) {
            foreach ($xml->mergeCells->mergeCell as $m) {
                $rango = (string) $m['ref'];
                if (!str_contains($rango, ':')) continue;
                [$ini, $fin] = explode(':', $rango, 2);
                [$c1, $f1] = $this->referencia($ini);
                [$c2, $f2] = $this->referencia($fin);
                $origen = $rejilla[$f1][$c1] ?? null;
                if ($origen === null) continue;
                // Un rango combinado enorme (A1:XFD1048576) rellenaría billones
                // de celdas y agotaría la memoria. Se ignora lo desproporcionado:
                // una celda combinada real de la escuela abarca pocas casillas.
                if (($f2 - $f1) * ($c2 - $c1) > 10000) continue;
                for ($f = $f1; $f <= $f2; $f++) {
                    for ($c = $c1; $c <= $c2; $c++) {
                        if (!isset($rejilla[$f][$c])) $rejilla[$f][$c] = $origen;
                    }
                }
            }
        }

        ksort($rejilla);
        foreach ($rejilla as &$f) { ksort($f); }
        return $rejilla;
    }

    /**
     * Igual que rejilla(), pero cada celda trae también su color de relleno.
     * Incluye celdas vacías con color, porque en algunas hojas el color ES
     * el dato (por ejemplo, el mes pagado).
     *
     * @return array [fila][columna] => ['valor' => ..., 'color' => 'rgb:..'|'theme:..'|null]
     */
    public function rejillaConColor($hoja = 0): array
    {
        $destino = null;
        foreach ($this->hojas as $i => $h) {
            if ((is_int($hoja) && $i === $hoja) || (is_string($hoja) && $h['nombre'] === $hoja)) {
                $destino = $h; break;
            }
        }
        if (!$destino) throw new Exception('No se encontró esa hoja en el archivo.');
        $xml = $this->leerXml($destino['ruta']);
        if (!$xml) throw new Exception('La hoja está vacía o dañada.');

        $rejilla = [];
        $filasLeidas = 0;
        foreach ($xml->sheetData->row as $fila) {
            if (++$filasLeidas > 50000) break;
            $nf = (int) $fila['r'];
            foreach ($fila->c as $celda) {
                [$nc, ] = $this->referencia((string) $celda['r']);
                $valor = $this->valorCelda($celda);
                $estilo = isset($celda['s']) ? (int) $celda['s'] : -1;
                $color = $this->colorDeEstilo[$estilo] ?? null;
                if (($valor !== null && $valor !== '') || $color !== null) {
                    $rejilla[$nf][$nc] = ['valor' => $valor, 'color' => $color];
                }
            }
        }
        ksort($rejilla);
        foreach ($rejilla as &$f) { ksort($f); }
        return $rejilla;
    }

    // ----------------------------------------------------------------

    private function leerXml(string $ruta)
    {
        $contenido = $this->zipNativo ? $this->zip->getFromName($ruta) : $this->zip->leer($ruta);
        if ($contenido === false) return null;
        $previo = libxml_use_internal_errors(true);
        // LIBXML_NONET bloquea entidades externas (XXE); sin LIBXML_NOENT no se
        // expanden entidades internas (billion-laughs). El .xlsx no las usa.
        $xml = simplexml_load_string($contenido, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previo);
        return $xml ?: null;
    }

    private function cargarCadenas(): void
    {
        $xml = $this->leerXml('xl/sharedStrings.xml');
        if (!$xml) return;
        foreach ($xml->si as $si) {
            $texto = '';
            if (isset($si->t)) {
                $texto = (string) $si->t;
            } else {
                foreach ($si->r as $r) { $texto .= (string) $r->t; }
            }
            $this->cadenas[] = $texto;
        }
    }

    /** Identifica qué estilos corresponden a fechas, para convertir el número de serie */
    private function cargarFormatosFecha(): void
    {
        $xml = $this->leerXml('xl/styles.xml');
        if (!$xml) return;

        $codigosFecha = [14,15,16,17,18,19,20,21,22,27,30,36,45,46,47,50,57];
        $personalizados = [];
        if (isset($xml->numFmts)) {
            foreach ($xml->numFmts->numFmt as $f) {
                $codigo = (string) $f['formatCode'];
                if (preg_match('/[dmyhs]/i', $codigo) && !str_contains($codigo, '"')) {
                    $personalizados[] = (int) $f['numFmtId'];
                }
            }
        }
        // Rellenos: cada <fill> puede traer el color como RGB fijo o como
        // referencia al tema del libro (theme + tint). Las dos formas se
        // guardan tal cual, para compararlas contra la guía del propio archivo.
        $rellenos = [];
        if (isset($xml->fills)) {
            foreach ($xml->fills->fill as $f) {
                $color = null;
                if (isset($f->patternFill) && (string) $f->patternFill['patternType'] === 'solid'
                    && isset($f->patternFill->fgColor)) {
                    $fg = $f->patternFill->fgColor;
                    if (isset($fg['rgb'])) {
                        $color = 'rgb:' . strtoupper(substr((string) $fg['rgb'], -6));
                    } elseif (isset($fg['theme'])) {
                        $tinte = isset($fg['tint']) ? round((float) $fg['tint'], 3) : 0;
                        $color = 'theme:' . (int) $fg['theme'] . ':' . ($tinte == 0 ? '0' : $tinte);
                    }
                }
                $rellenos[] = $color;
            }
        }

        if (isset($xml->cellXfs)) {
            $i = 0;
            foreach ($xml->cellXfs->xf as $xf) {
                $id = (int) $xf['numFmtId'];
                if (in_array($id, $codigosFecha, true) || in_array($id, $personalizados, true)) {
                    $this->formatosFecha[$i] = true;
                }
                $fill = (int) $xf['fillId'];
                $this->colorDeEstilo[$i] = $rellenos[$fill] ?? null;
                $i++;
            }
        }
    }

    private function cargarHojas(): void
    {
        $libro = $this->leerXml('xl/workbook.xml');
        $rels  = $this->leerXml('xl/_rels/workbook.xml.rels');
        $mapa = [];
        if ($rels) {
            foreach ($rels->Relationship as $r) {
                $mapa[(string) $r['Id']] = 'xl/' . ltrim((string) $r['Target'], '/');
            }
        }
        if ($libro) {
            foreach ($libro->sheets->sheet as $h) {
                $rid = (string) $h->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $this->hojas[] = [
                    'nombre' => (string) $h['name'],
                    'ruta'   => $mapa[$rid] ?? 'xl/worksheets/sheet1.xml',
                ];
            }
        }
        if (!$this->hojas) {
            $this->hojas[] = ['nombre' => 'Hoja1', 'ruta' => 'xl/worksheets/sheet1.xml'];
        }
    }

    /** 'C12' -> [3, 12]  (columna, fila) */
    private function referencia(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m);
        if (!$m) return [0, 0];
        $col = 0;
        foreach (str_split(strtoupper($m[1])) as $letra) {
            $col = $col * 26 + (ord($letra) - 64);
        }
        return [$col, (int) $m[2]];
    }

    private function valorCelda(SimpleXMLElement $celda)
    {
        $tipo = (string) $celda['t'];

        if ($tipo === 's') {
            $i = (int) $celda->v;
            return $this->cadenas[$i] ?? '';
        }
        if ($tipo === 'inlineStr') {
            $texto = '';
            if (isset($celda->is->t)) $texto = (string) $celda->is->t;
            else foreach ($celda->is->r as $r) $texto .= (string) $r->t;
            return $texto;
        }
        if ($tipo === 'b') {
            return ((string) $celda->v) === '1' ? 'VERDADERO' : 'FALSO';
        }
        if ($tipo === 'e') {
            return null; // celda con error (#REF!, #N/A...)
        }

        if (!isset($celda->v) || (string) $celda->v === '') return null;
        $crudo = (string) $celda->v;

        $estilo = isset($celda['s']) ? (int) $celda['s'] : -1;
        if (isset($this->formatosFecha[$estilo]) && is_numeric($crudo)) {
            $n = (float) $crudo;
            // Excel guarda la parte entera como fecha y la fracción como hora.
            // Una hora suelta (17:30) es solo fracción: vale menos que 1 y no
            // debe convertirse en una fecha de 1899.
            if ($n < 1)        return self::serieAHora($n);
            if (floor($n) != $n) return self::serieAFecha($n) . ' ' . self::serieAHora($n - floor($n));
            return self::serieAFecha($n);
        }

        return is_numeric($crudo) ? (float) $crudo : $crudo;
    }

    /** Convierte el número de serie de Excel a 'YYYY-MM-DD' */
    public static function serieAFecha(float $serie): string
    {
        // Excel cuenta desde el 1900-01-01 y arrastra el bug del 29/02/1900.
        $marca = ($serie - 25569) * 86400;
        return gmdate('Y-m-d', (int) round($marca));
    }

    /** La fracción de día de Excel a 'HH:MM:SS' */
    public static function serieAHora(float $fraccion): string
    {
        $segundos = (int) round($fraccion * 86400);
        $segundos = max(0, min(86399, $segundos));
        return sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    }

    /** '$1,350.00' o '1 350' -> 1350.0 ; devuelve null si no hay número */
    public static function aNumero($valor): ?float
    {
        if ($valor === null || $valor === '') return null;
        if (is_int($valor) || is_float($valor)) return (float) $valor;
        $limpio = preg_replace('/[^0-9,.\-]/', '', (string) $valor);
        $limpio = str_replace(',', '', $limpio);
        if ($limpio === '' || !is_numeric($limpio)) return null;
        return (float) $limpio;
    }
}
