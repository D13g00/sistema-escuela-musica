<?php
require_once __DIR__ . '/../lib/Texto.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/LectorXlsx.php';
require_once __DIR__ . '/ImportadorNomina.php';

/**
 * ImportadorPagos — lee la hoja de pagos de la escuela.
 *
 * La hoja es un tablero por día con los MAESTROS en columnas (dos filas de
 * maestros por día: mañana y tarde) y las medias horas en renglones. Cada
 * celda trae el nombre del alumno, y su COLOR indica hasta qué mes ha pagado.
 * La guía de colores está en la propia hoja: una celda por mes con su nombre.
 *
 * Reglas para no ensuciar la base:
 *   · El color se compara contra la guía del propio archivo, no contra una
 *     lista fija: varios meses usan colores del tema de Excel, no RGB.
 *   · Se crea UN pago por alumno: el del último mes marcado. Nada de inventar
 *     meses anteriores que la hoja no dice.
 *   · El pago queda "por confirmar": se sabe que pagó, no cómo. Se completa
 *     después desde Pagos.
 *   · Si el alumno ya tiene un pago de colegiatura ese mes (capturado a mano
 *     o importado antes), no se crea otro. Subir el archivo dos veces no
 *     duplica nada.
 *   · No se crean alumnos: si un nombre no se reconoce, se reporta.
 */
class ImportadorPagos
{
    private PDO $db;

    private const MESES = [
        'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6,
        'JULIO' => 7, 'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9,
        'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
    ];

    public function __construct() { $this->db = Database::conexion(); }

    // =================================================================
    // LECTURA
    // =================================================================

    public function previsualizar(string $ruta, ?string $referencia = null): array
    {
        $referencia = $this->validarReferencia($referencia);
        $lector  = new LectorXlsx($ruta);
        $rejilla = $lector->rejillaConColor(0);

        [$guia, $filasGuia] = $this->leerGuia($rejilla);
        if (count($guia) < 3) {
            throw new Exception('No encontré la guía de colores por mes en la hoja. '
                . 'Debe haber celdas con el nombre del mes pintadas del color que le corresponde.');
        }

        $celdas = $this->leerCeldas($rejilla, $guia, $filasGuia);

        // Por alumno: el mes más reciente en todas sus celdas.
        $porAlumno = [];
        foreach ($celdas as $c) {
            $clave = $this->norm($c['alumno']);
            if (!isset($porAlumno[$clave])) {
                $porAlumno[$clave] = ['alumno' => $c['alumno'], 'maestros' => [], 'meses' => []];
            }
            $porAlumno[$clave]['maestros'][$c['maestro']] = true;
            $porAlumno[$clave]['meses'][] = $c['mes'];
        }

        $alumnos    = $this->db->query("SELECT id_alumno, nombre, apellido, activo FROM alumnos")->fetchAll();
        $maestros   = $this->db->query("SELECT id_maestro, nombre, apellido FROM maestros")->fetchAll();
        $conMaestro = [];
        foreach ($this->db->query(
            "SELECT id_alumno, id_maestro FROM inscripciones WHERE estado <> 'baja'"
        ) as $i) { $conMaestro[(int) $i['id_maestro']][(int) $i['id_alumno']] = true; }

        $filas = [];
        foreach ($porAlumno as $a) {
            $meses = $a['meses'];
            $ultimo = $this->masReciente($meses, $referencia);
            $periodo = $this->periodoDe($ultimo, $referencia);
            $distintos = count(array_unique($meses)) > 1;

            // 1º entre los alumnos de sus maestros; 2º entre todos.
            $candidatos = [];
            foreach (array_keys($a['maestros']) as $textoMaestro) {
                foreach ($maestros as $m) {
                    if (!$this->esMaestro($textoMaestro, $m['nombre'] . ' ' . $m['apellido'])) continue;
                    foreach ($alumnos as $al) {
                        if (isset($conMaestro[(int) $m['id_maestro']][(int) $al['id_alumno']])
                            && $this->coincide($a['alumno'], $al['nombre'] . ' ' . $al['apellido'], true)) {
                            $candidatos[(int) $al['id_alumno']] = $al;
                        }
                    }
                }
            }
            // Si entre sus maestros hay uno con nombre completo, ese gana a los
            // que solo tienen nombre de pila (suelen ser el mismo, repetido).
            if (count($candidatos) > 1) {
                $completos = array_filter($candidatos, fn($x) => trim((string) $x['apellido']) !== '');
                $sueltos   = array_filter($candidatos, fn($x) => trim((string) $x['apellido']) === '');
                if (count($completos) === 1 && $sueltos) $candidatos = $completos;
            }
            if (!$candidatos) {
                $exacto = $this->porNombreExacto($a['alumno']);
                if ($exacto) {
                    foreach ($alumnos as $al) {
                        if ((int) $al['id_alumno'] === $exacto) $candidatos[$exacto] = $al;
                    }
                }
            }
            if (!$candidatos) {
                foreach ($alumnos as $al) {
                    if ((int) $al['activo'] && $this->coincide($a['alumno'], $al['nombre'] . ' ' . $al['apellido'])) {
                        $candidatos[(int) $al['id_alumno']] = $al;
                    }
                }
            }

            $nota    = self::nota($a['alumno']);
            $adeudo  = self::adeudoDeNota($nota);
            $fila = [
                'texto'     => self::sinNota($a['alumno']),
                'nota'      => $nota,
                'adeudo'    => $adeudo,
                'completar' => null,
                'maestros'  => array_keys($a['maestros']),
                'mes'       => $ultimo,
                'periodo'   => $periodo,
                'distintos' => $distintos,
                'id_alumno' => null,
                'id_maestro'=> null,
                'maestro_sistema' => null,
                'alumno'    => null,
                'estado'    => null,
                'motivo'    => null,
            ];

            if (count($candidatos) === 1) {
                $al = reset($candidatos);
                $fila['id_alumno'] = (int) $al['id_alumno'];
                $fila['alumno'] = trim($al['nombre'] . ' ' . $al['apellido']);
                // El sistema solo tenía el nombre de pila y la hoja trae el completo.
                if (trim((string) $al['apellido']) === '' && count($this->palabras($a['alumno'])) > 1) {
                    $fila['completar'] = self::sinNota($a['alumno']);
                }
                $ya = $this->yaPagado((int) $al['id_alumno'], $periodo);
                $fila['estado'] = $ya ? 'ya_estaba' : 'nuevo';
                if ($ya) $fila['motivo'] = 'Ya tiene pago registrado de ese mes.';
            } elseif (count($candidatos) > 1) {
                $fila['estado'] = 'ambiguo';
                $fila['motivo'] = 'Podría ser: ' . implode(', ', array_map(
                    fn($x) => trim($x['nombre'] . ' ' . $x['apellido']), array_values($candidatos)));
            } else {
                $fila['estado'] = 'no_encontrado';
                $fila['motivo'] = 'No hay un alumno con ese nombre en el sistema.';
                // Para poder darlo de alta hace falta saber, sin dudas, su maestro.
                $posibles = [];
                foreach (array_keys($a['maestros']) as $textoMaestro) {
                    foreach ($maestros as $m) {
                        if ($this->esMaestro($textoMaestro, $m['nombre'] . ' ' . $m['apellido'])) {
                            $posibles[(int) $m['id_maestro']] = trim($m['nombre'] . ' ' . $m['apellido']);
                        }
                    }
                }
                if (count($posibles) === 1) {
                    $fila['id_maestro'] = array_key_first($posibles);
                    $fila['maestro_sistema'] = reset($posibles);
                } else {
                    $fila['motivo'] .= count($posibles) > 1
                        ? ' Y su maestro podría ser más de uno, así que no se puede dar de alta solo.'
                        : ' Y no se reconoce a su maestro.';
                }
            }
            $filas[] = $fila;
        }

        usort($filas, fn($x, $y) => strcmp($x['periodo'] . $x['texto'], $y['periodo'] . $y['texto']));

        $cuenta = ['nuevo' => 0, 'ya_estaba' => 0, 'ambiguo' => 0, 'no_encontrado' => 0,
                   'con_adeudo' => 0, 'por_completar' => 0, 'con_nota' => 0, 'sin_precio' => 0];
        $porMes = [];
        foreach ($filas as $f) {
            $cuenta[$f['estado']]++;
            if ($f['adeudo']) $cuenta['con_adeudo']++;
            if ($f['completar']) $cuenta['por_completar']++;
            if ($f['nota'] && !$f['adeudo']) $cuenta['con_nota']++;
            if ($f['id_alumno'] && $this->colegiatura($f['id_alumno']) <= 0) {
                $cuenta['sin_precio'] = ($cuenta['sin_precio'] ?? 0) + 1;
            }
            $porMes[$f['periodo']] = ($porMes[$f['periodo']] ?? 0) + 1;
        }
        krsort($porMes);

        return [
            'referencia' => $referencia,
            'guia'       => array_values(array_unique($guia)),
            'filas'      => $filas,
            'resumen'    => $cuenta + ['total' => count($filas), 'por_mes' => $porMes],
        ];
    }

    /** Las celdas de la columna A con nombre de mes y color son la guía */
    private function leerGuia(array $rejilla): array
    {
        $guia = []; $filas = [];
        foreach ($rejilla as $nf => $fila) {
            foreach ($fila as $nc => $celda) {
                if (!is_string($celda['valor']) || !$celda['color']) continue;
                $mes = self::MESES[mb_strtoupper(trim($celda['valor']), 'UTF-8')] ?? null;
                if ($mes && $nc <= 2) {
                    $guia[$celda['color']] = $mes;
                    $filas[$nf] = true;
                }
            }
        }
        return [$guia, $filas];
    }

    /**
     * Recorre la hoja. Una fila con hora en la columna A es de clases; una
     * fila sin hora pero con nombres es una fila de maestros (hay dos por día).
     */
    private function leerCeldas(array $rejilla, array $guia, array $filasGuia): array
    {
        $celdas = [];
        $maestros = [];

        foreach ($rejilla as $nf => $fila) {
            if (isset($filasGuia[$nf])) continue;

            $a = $fila[1]['valor'] ?? null;
            $esHora = is_string($a) && preg_match('/^\d{1,2}:\d{2}/', $a);

            // Encabezado de día: reinicia los maestros.
            $b = $fila[2]['valor'] ?? null;
            if (is_string($b) && stripos($b, 'PAGOS') !== false) { $maestros = []; continue; }

            if (!$esHora) {
                $nuevos = [];
                foreach ($fila as $nc => $celda) {
                    if ($nc < 2) continue;
                    if (is_string($celda['valor']) && trim($celda['valor']) !== '') {
                        $nuevos[$nc] = trim($celda['valor']);
                    }
                }
                if ($nuevos) $maestros = $nuevos;
                continue;
            }

            foreach ($maestros as $nc => $maestro) {
                $celda = $fila[$nc] ?? null;
                if (!$celda || !is_string($celda['valor']) || trim($celda['valor']) === '') continue;
                $mes = $guia[$celda['color'] ?? ''] ?? null;
                if ($mes === null) continue;   // sin color de la guía: no se sabe que pagó
                $celdas[] = ['alumno' => trim($celda['valor']), 'maestro' => $maestro, 'mes' => $mes];
            }
        }
        return $celdas;
    }

    // =================================================================
    // ESCRITURA
    // =================================================================

    /**
     * @param array $opciones
     *   crear_faltantes   -> da de alta a quien está en la hoja y no en el sistema,
     *                        con una clase sin horario con su maestro
     *   completar_nombres -> a quien el sistema solo conocía por su nombre de pila
     *                        ("Sofía"), le pone el nombre completo de la hoja
     */
    public function importar(string $ruta, ?string $referencia, ?string $usuario, string $archivo,
                             array $opciones = []): array
    {
        $previa = $this->previsualizar($ruta, $referencia);
        $crear = !empty($opciones['crear_faltantes']);
        $completar = !empty($opciones['completar_nombres']);
        $ponerPrecio = !empty($opciones['poner_precio']);
        $alumnosSinPrecio = [];

        $creados = 0; $saltados = 0; $total = 0.0; $sinPrecio = 0;
        $alumnosNuevos = 0; $nombresCompletados = 0; $conAdeudo = 0;
        $separador = new ImportadorNomina();

        // Una importación a la vez: si alguien más está importando, no se encima.
        if (!Database::candado('importar', 0)) {
            throw new Exception('Otra persona está importando un archivo en este momento. Espera a que termine y vuelve a intentarlo.');
        }
        $this->db->beginTransaction();
        try {
            foreach ($previa['filas'] as $f) {
                $idAlumno = $f['id_alumno'];

                // Alumno que está en la hoja y no en el sistema
                if ($f['estado'] === 'no_encontrado') {
                    if (!$crear || !($f['id_maestro'] ?? null)) { $saltados++; continue; }

                    // Última revisión antes de crear: ¿ya existe alguien con ese
                    // nombre exacto? (pudo crearse o completarse en este mismo
                    // archivo, unas filas antes). Así nunca se duplica.
                    $idAlumno = $this->porNombreExacto($f['texto']);

                    if (!$idAlumno) {
                        $partes = $separador->separarNombre($f['texto']);
                        $this->db->prepare("INSERT INTO alumnos (nombre, apellido) VALUES (?, ?)")
                                 ->execute([$partes['nombre'], $partes['apellido']]);
                        $idAlumno = (int) $this->db->lastInsertId();
                        $this->db->prepare(
                            "INSERT INTO inscripciones (id_alumno, id_maestro, costo_mensual, estado, origen)
                             VALUES (?, ?, 0, 'pendiente', 'importacion')"
                        )->execute([$idAlumno, $f['id_maestro']]);
                        $alumnosNuevos++;
                    }
                } elseif ($f['estado'] !== 'nuevo') {
                    $saltados++;
                    continue;
                }

                if ($completar && $f['completar']) {
                    $partes = $separador->separarNombre($f['completar']);
                    $this->db->prepare(
                        "UPDATE alumnos SET nombre = ?, apellido = ? WHERE id_alumno = ? AND (apellido IS NULL OR apellido = '')"
                    )->execute([$partes['nombre'], $partes['apellido'], $idAlumno]);
                    $nombresCompletados++;
                }

                // Se revisa otra vez dentro de la transacción: si alguien capturó
                // el pago mientras se revisaba la vista previa, no se duplica.
                if ($this->yaPagado($idAlumno, $f['periodo'])) { $saltados++; continue; }

                $colegiatura = $this->colegiatura($idAlumno);

                // Sin precio no hay pago que registrar: un pago de $0 dejaría el
                // mes "pagado" y, al ponerle precio después, aparecería debiendo
                // aunque la hoja diga que pagó. O se le pone precio, o se espera.
                if ($colegiatura <= 0 && $ponerPrecio) {
                    $this->ponerPrecio($idAlumno);
                    $colegiatura = $this->colegiatura($idAlumno);
                }
                if ($colegiatura <= 0) {
                    $sinPrecio++;
                    $alumnosSinPrecio[] = $f['alumno'] ?? $f['texto'];
                    continue;
                }

                // "Deben $800": el mes que marca el color sí está pagado, pero
                // arrastra una deuda de antes. Se guarda como adeudo anterior,
                // que se cobra antes que cualquier mes. No se resta del mes:
                // $800 no cabe en una colegiatura de $450.
                if ($f['adeudo']) {
                    $this->db->prepare(
                        "UPDATE alumnos SET adeudo_anterior = ?, adeudo_anterior_nota = ?
                          WHERE id_alumno = ? AND adeudo_anterior = 0"
                    )->execute([$f['adeudo'], 'Hoja de pagos: «' . $f['nota'] . '»', $idAlumno]);
                    $conAdeudo++;
                }
                $monto = $colegiatura;

                $this->db->prepare(
                    // La hoja solo dice QUE pagó ese mes, no cuánto: el importe es una
                    // sugerencia y queda por confirmar (nunca un importe inventado como real).
                    "INSERT INTO pagos (id_alumno, concepto, monto, recargo, metodo, monto_confirmado, periodo, origen,
                                        fecha_pago, registrado_por)
                     VALUES (?, 'colegiatura', ?, 0, 'por_confirmar', 0, ?, 'importacion', CURDATE(), ?)"
                )->execute([$idAlumno, $monto, $f['periodo'], $usuario]);
                $creados++;
                $total += $monto;
            }

            $this->db->prepare(
                "INSERT INTO importaciones (tipo, archivo, periodo, filas_importadas, alumnos_nuevos, total, usuario)
                 VALUES ('pagos', ?, ?, ?, ?, ?, ?)"
            )->execute([$archivo, $previa['referencia'], $creados, $alumnosNuevos, round($total, 2), $usuario]);

            $this->db->commit();
            Database::soltar('importar');
        } catch (Throwable $e) {
            $this->db->rollBack();
            Database::soltar('importar');
            throw new Exception('No se importó nada. ' . $e->getMessage());
        }

        return [
            'creados'             => $creados,
            'saltados'            => $saltados,
            'total'               => round($total, 2),
            'sin_precio'          => $sinPrecio,
            'alumnos_nuevos'      => $alumnosNuevos,
            'nombres_completados' => $nombresCompletados,
            'con_adeudo'          => $conAdeudo,
            'alumnos_sin_precio'  => array_slice(array_values(array_unique($alumnosSinPrecio)), 0, 80),
            'resumen'             => $previa['resumen'],
        ];
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    /** Alumno con ese nombre exacto (sin importar acentos ni mayúsculas) */
    private function porNombreExacto(string $texto): ?int
    {
        $buscado = Texto::normalizar(self::sinNota($texto));
        foreach ($this->db->query("SELECT id_alumno, nombre, apellido FROM alumnos") as $a) {
            if (Texto::normalizar($a['nombre'] . ' ' . $a['apellido']) === $buscado) return (int) $a['id_alumno'];
        }
        return null;
    }

    /**
     * Pone precio a las clases que no lo tienen: el de su materia según la
     * duración; si no tiene materia, el de música (900 la hora, 450 la media).
     */
    private function ponerPrecio(int $idAlumno): void
    {
        $musica = (float) ($this->db->query(
            "SELECT costo_mensual FROM instrumentos WHERE costo_mensual > 0 AND es_grupal = 0
              ORDER BY costo_mensual DESC LIMIT 1")->fetchColumn() ?: 900);
        $this->db->prepare(
            "UPDATE inscripciones ins
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
                SET ins.costo_mensual = ROUND(
                        COALESCE(NULLIF(i.costo_mensual, 0), ?) * COALESCE(ins.duracion_min, 60) / 60, 2)
              WHERE ins.id_alumno = ? AND ins.estado <> 'baja' AND ins.costo_mensual = 0"
        )->execute([$musica, $idAlumno]);
    }

    private function yaPagado(int $idAlumno, string $periodo): bool
    {
        $s = $this->db->prepare(
            "SELECT COUNT(*) FROM pagos
              WHERE id_alumno = ? AND periodo = ? AND concepto = 'colegiatura' AND anulado = 0 AND anula_a IS NULL"
        );
        $s->execute([$idAlumno, $periodo]);
        return (int) $s->fetchColumn() > 0;
    }

    private function colegiatura(int $idAlumno): float
    {
        $s = $this->db->prepare(
            "SELECT COALESCE(SUM(costo_mensual), 0) FROM inscripciones
              WHERE id_alumno = ? AND estado <> 'baja' AND comparte_con IS NULL
                 OR (id_alumno = ? AND estado <> 'baja' AND comparte_con IS NOT NULL)"
        );
        $s->execute([$idAlumno, $idAlumno]);
        return round((float) $s->fetchColumn(), 2);
    }

    private function validarReferencia(?string $ref): string
    {
        if ($ref && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ref)) return $ref;
        return date('Y-m');
    }

    /** El mes más reciente, contado respecto al mes de referencia */
    private function masReciente(array $meses, string $referencia): int
    {
        $mejor = null; $mejorPeriodo = '';
        foreach (array_unique($meses) as $m) {
            $p = $this->periodoDe($m, $referencia);
            if ($p > $mejorPeriodo) { $mejorPeriodo = $p; $mejor = $m; }
        }
        return $mejor;
    }

    /**
     * La hoja dice "agosto", no "agosto de 2026". Se elige el año que deja
     * ese mes más cerca de la fecha de referencia, sin ir más de dos meses al
     * futuro (alguien puede adelantar un pago, pero no un año).
     */
    public function periodoDe(int $mes, string $referencia): string
    {
        [$anio, $mesRef] = array_map('intval', explode('-', $referencia));
        $mejor = null; $distancia = PHP_INT_MAX;
        foreach ([$anio - 1, $anio, $anio + 1] as $a) {
            $d = ($a * 12 + $mes) - ($anio * 12 + $mesRef);
            if ($d > 2) continue;
            if (abs($d) < $distancia) { $distancia = abs($d); $mejor = $a; }
        }
        return sprintf('%04d-%02d', $mejor, $mes);
    }

    private function norm(string $t): string
    {
        return Texto::normalizar($t);
    }

    /** Quita notas entre paréntesis: "Juana López (Deben $800)" -> "Juana López" */
    public static function sinNota(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/\([^)]*\)/u', ' ', $texto)));
    }

    /** La nota entre paréntesis, si la hay: "Deben $800" */
    public static function nota(string $texto): ?string
    {
        return preg_match('/\(([^)]*)\)/u', $texto, $m) ? trim($m[1]) : null;
    }

    /** Un adeudo escrito en la nota: "Deben $800" -> 800.0 */
    public static function adeudoDeNota(?string $nota): ?float
    {
        if (!$nota || !preg_match('/deb|resta|falta|pend|adeud/iu', $nota)) return null;
        if (!preg_match('/\$?\s*([\d,]+(?:\.\d+)?)/', $nota, $m)) return null;
        $v = (float) str_replace(',', '', $m[1]);
        return $v > 0 && $v < 100000 ? $v : null;
    }

    private function palabras(string $t): array
    {
        $t = $this->norm(self::sinNota($t));
        $t = str_replace('.', '', $t);
        return array_values(array_filter(explode(' ', $t), fn($p) => $p !== ''
            && !in_array($p, ['de', 'del', 'la', 'los', 'las', 'y'], true)));
    }

    /** ¿Dos palabras son la misma? Tolera acentos, una errata y las iniciales (B = Beatriz) */
    private function mismaPalabra(string $a, string $b): bool
    {
        if ($a === $b) return true;
        if (mb_strlen($a) === 1) return str_starts_with($b, $a);
        if (mb_strlen($b) === 1) return str_starts_with($a, $b);
        return mb_strlen($a) >= 5 && mb_strlen($b) >= 5 && levenshtein($a, $b) <= 1;
    }

    /**
     * ¿El nombre de la hoja y el del sistema son la misma persona?
     *
     * El primer nombre tiene que coincidir, y luego todas las palabras del
     * nombre MÁS CORTO tienen que estar en el más largo. Así:
     *   "Sofía Beatriz Luna Mora"      =  "Sofía B. Luna Mora"       (B = Beatriz)
     *   "Tomás Salas Pérez"            =  "Tomás Salas"              (le falta un apellido)
     *   "Mario Ramírez"                ≠  "Mario Rodríguez"          (apellido distinto)
     *
     * $soloNombre permite casar con un registro que no tiene apellido ("Sofía"
     * sola, como la dejó la hoja de horarios). Solo se usa entre los alumnos
     * del mismo maestro, que es donde esa coincidencia es confiable.
     */
    private function coincide(string $texto, string $completo, bool $soloNombre = false): bool
    {
        $h = $this->palabras($texto);
        $s = $this->palabras($completo);
        if (!$h || !$s) return false;
        if (!$this->mismaPalabra($h[0], $s[0])) return false;

        if (count($s) === 1) return $soloNombre;          // el sistema solo sabe el nombre de pila
        if (count($h) === 1) return false;                 // la hoja solo dice el nombre: no alcanza

        [$corto, $largo] = count($h) <= count($s) ? [$h, $s] : [$s, $h];
        foreach (array_slice($corto, 1) as $p) {
            $esta = false;
            foreach (array_slice($largo, 1) as $q) {
                if ($this->mismaPalabra($p, $q)) { $esta = true; break; }
            }
            if (!$esta) return false;
        }
        return true;
    }

    /**
     * ¿La columna de la hoja es este maestro? Las hojas usan apodos y segundos
     * nombres ("Juan Pablo" en una, "Pablo" en otra), así que basta con
     * que coincida cualquiera de sus nombres, de cuatro letras o más.
     */
    private function esMaestro(string $texto, string $completo): bool
    {
        foreach ($this->palabras($texto) as $a) {
            if (mb_strlen($a) < 4) continue;
            foreach ($this->palabras($completo) as $b) {
                if (mb_strlen($b) < 4) continue;
                if ($a === $b || (mb_strlen($a) >= 5 && levenshtein($a, $b) <= 1)) return true;
                // Apodo: comparten las primeras cuatro letras (Fede / Federico).
                if (mb_substr($a, 0, 4) === mb_substr($b, 0, 4)) return true;
            }
        }
        return false;
    }
}
