<?php
/**
 * LectorZipSimple — abre un .zip sin la extensión ZipArchive de PHP.
 *
 * Un .xlsx es un ZIP. Si en el XAMPP de la escuela no está activada la
 * extensión zip, el importador dejaría de funcionar por algo que no tiene
 * que ver con la escuela. Esta clase lee el ZIP a mano: localiza el
 * directorio central al final del archivo, y descomprime cada entrada con
 * gzinflate(), que viene de zlib y está activo por defecto en PHP.
 *
 * Solo soporta los dos métodos que usa Excel: sin comprimir (0) y deflate (8).
 */
class LectorZipSimple
{
    /** @var array<string,array{inicio:int,comprimido:int,original:int,metodo:int}> */
    private array $entradas = [];
    private $puntero;

    public function __construct(string $ruta)
    {
        $this->puntero = @fopen($ruta, 'rb');
        if (!$this->puntero) {
            throw new Exception('No se pudo abrir el archivo.');
        }
        $this->leerDirectorio();
    }

    public function __destruct()
    {
        if (is_resource($this->puntero)) fclose($this->puntero);
    }

    public function existe(string $nombre): bool
    {
        return isset($this->entradas[$nombre]);
    }

    /** Devuelve el contenido de un archivo interno, o false si no está */
    public function leer(string $nombre)
    {
        if (!isset($this->entradas[$nombre])) return false;
        $e = $this->entradas[$nombre];

        // La cabecera local trae su propio tamaño de nombre y de campos extra,
        // que puede no coincidir con el del directorio central.
        fseek($this->puntero, $e['inicio']);
        $cabecera = fread($this->puntero, 30);
        if (strlen($cabecera) < 30 || substr($cabecera, 0, 4) !== "PK\x03\x04") {
            return false;
        }
        $d = unpack('vnombre/vextra', substr($cabecera, 26, 4));
        fseek($this->puntero, $e['inicio'] + 30 + $d['nombre'] + $d['extra']);

        $datos = $e['comprimido'] > 0 ? fread($this->puntero, $e['comprimido']) : '';
        if ($datos === false) return false;

        if ($e['metodo'] === 0) return $datos;
        if ($e['metodo'] === 8) {
            // Tope contra bombas de descompresión: un fragmento de kilobytes que
            // se infla a cientos de MB se corta aquí. 80 MB descomprimidos cubre
            // cualquier .xlsx real; gzinflate acepta el límite como tercer arg.
            $salida = @gzinflate($datos, 80 * 1024 * 1024);
            return $salida === false ? false : $salida;
        }
        return false; // método de compresión que Excel no usa
    }

    /** Localiza el directorio central, que vive al final del archivo */
    private function leerDirectorio(): void
    {
        $tamano = filesize2($this->puntero);
        $cola = min(65557, $tamano);          // 64 KB de comentario máximo + 22
        fseek($this->puntero, $tamano - $cola);
        $bloque = fread($this->puntero, $cola);

        $pos = strrpos($bloque, "PK\x05\x06");
        if ($pos === false) {
            throw new Exception('El archivo no parece un .xlsx válido.');
        }

        $fin = unpack('ventradas/Vtamano/Vinicio', substr($bloque, $pos + 10, 12));
        fseek($this->puntero, $fin['inicio']);
        $central = fread($this->puntero, $fin['tamano']);

        $p = 0;
        for ($i = 0; $i < $fin['entradas']; $i++) {
            if (substr($central, $p, 4) !== "PK\x01\x02") break;

            $c = unpack(
                'vmetodo/vhora/vfecha/Vcrc/Vcomprimido/Voriginal/vnombre/vextra/vcomentario',
                substr($central, $p + 10, 26)
            );
            $desplazamiento = unpack('V', substr($central, $p + 42, 4))[1];
            $nombre = substr($central, $p + 46, $c['nombre']);

            // Descarta entradas cuyo tamaño declarado sugiere una bomba: más de
            // 1000x de inflado, o más de 200 MB sin comprimir.
            $ratioSano = $c['comprimido'] === 0
                || ($c['original'] / max(1, $c['comprimido']) <= 1000 && $c['original'] <= 200 * 1024 * 1024);
            if ($ratioSano) {
                $this->entradas[$nombre] = [
                    'inicio'     => $desplazamiento,
                    'comprimido' => $c['comprimido'],
                    'original'   => $c['original'],
                    'metodo'     => $c['metodo'],
                ];
            }

            $p += 46 + $c['nombre'] + $c['extra'] + $c['comentario'];
        }
    }
}

if (!function_exists('filesize2')) {
    /** Tamaño real del archivo abierto, sin depender de la ruta */
    function filesize2($puntero): int
    {
        $estado = fstat($puntero);
        return (int) $estado['size'];
    }
}
