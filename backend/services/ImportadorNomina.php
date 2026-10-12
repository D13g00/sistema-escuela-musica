<?php
require_once __DIR__ . '/../lib/Texto.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/LectorXlsx.php';

/**
 * ImportadorNomina — lee la hoja de nómina de la escuela.
 *
 * La hoja no es una tabla: son bloques repartidos a lo ancho, cada uno
 * encabezado por "PROFESOR: <nombre>", con las columnas ALUMNO / CANTIDAD A
 * PAGAR / OBSERVACIONES debajo, y una fila TOTAL que cierra el bloque.
 * Este importador localiza esos encabezados y lee hacia abajo.
 *
 * Trabaja en dos pasos:
 *   1) previsualizar(): no toca la base. Devuelve lo que entendió,
 *      con los descuadres entre el TOTAL escrito y la suma real.
 *   2) importar(): escribe todo dentro de una transacción.
 *
 * Es idempotente: si vuelves a subir el mismo archivo y periodo, actualiza
 * los montos en vez de duplicar maestros, alumnos o renglones de nómina.
 */
class ImportadorNomina
{
    private PDO $db;

    /** Palabras que en español van en minúscula dentro de un apellido */
    private const PARTICULAS = ['de','del','la','las','los','y','e','da','do','van','von','di'];

    private const MESES = [
        'enero' => '01', 'febrero' => '02', 'marzo' => '03', 'abril' => '04',
        'mayo' => '05', 'junio' => '06', 'julio' => '07', 'agosto' => '08',
        'septiembre' => '09', 'setiembre' => '09', 'octubre' => '10',
        'noviembre' => '11', 'diciembre' => '12',
    ];

    public function __construct()
    {
        $this->db = Database::conexion();
    }

    // =================================================================
    // 1. LECTURA
    // =================================================================

    public function previsualizar(string $ruta, ?string $hoja = null): array
    {
        $lector  = new LectorXlsx($ruta);
        $hojas   = $lector->hojas();
        $rejilla = $lector->rejilla($hoja !== null && $hoja !== '' ? $hoja : 0);

        $bloques = $this->extraerBloques($rejilla);

        $totalCalculado = 0.0;
        $renglones = 0;
        $descuadres = [];

        foreach ($bloques as $b) {
            $totalCalculado += $b['total_calculado'];
            $renglones += count($b['alumnos']);
            if ($b['diferencia'] !== null && abs($b['diferencia']) > 0.009) {
                $descuadres[] = [
                    'maestro'    => $b['maestro'],
                    'en_la_hoja' => $b['total_hoja'],
                    'suma_real'  => $b['total_calculado'],
                    'diferencia' => $b['diferencia'],
                ];
            }
        }

        return [
            'hojas'           => $hojas,
            'hoja_leida'      => $hoja ?: ($hojas[0] ?? 'Hoja1'),
            'periodo_sugerido'=> $this->detectarPeriodo($rejilla, basename($ruta)),
            'bloques'         => $bloques,
            'resumen'         => [
                'maestros'        => count($bloques),
                'renglones'       => $renglones,
                'total_calculado' => round($totalCalculado, 2),
                'descuadres'      => $descuadres,
            ],
        ];
    }

    /** Localiza los encabezados "PROFESOR:" y lee cada bloque hacia abajo */
    private function extraerBloques(array $rejilla): array
    {
        $encabezados = [];
        foreach ($rejilla as $nf => $fila) {
            foreach ($fila as $nc => $valor) {
                if (!is_string($valor)) continue;
                $texto = trim($valor);
                if (stripos($texto, 'PROFESOR') !== 0) continue;

                // Si la celda de la izquierda dice lo mismo, es una celda
                // combinada: ya contamos este bloque.
                $izquierda = $rejilla[$nf][$nc - 1] ?? null;
                if (is_string($izquierda) && trim($izquierda) === $texto) continue;

                $nombre = trim(preg_replace('/^PROFESOR\s*:?\s*/iu', '', $texto));
                if ($nombre === '') continue;
                $encabezados[] = ['fila' => $nf, 'col' => $nc, 'nombre' => $nombre];
            }
        }

        $bloques = [];
        foreach ($encabezados as $e) {
            $alumnos = [];
            $totalHoja = null;
            $vacias = 0;
            $ultima = max(array_keys($rejilla));

            for ($f = $e['fila'] + 1; $f <= $ultima; $f++) {
                $celdaAlumno = $rejilla[$f][$e['col']] ?? null;
                $celdaMonto  = $rejilla[$f][$e['col'] + 1] ?? null;
                $celdaObs    = $rejilla[$f][$e['col'] + 2] ?? null;

                $texto = is_string($celdaAlumno) ? trim($celdaAlumno) : '';
                $arriba = mb_strtoupper($texto, 'UTF-8');

                if ($arriba === 'ALUMNO') { $vacias = 0; continue; }            // encabezado interno
                if (stripos($texto, 'PROFESOR') === 0) break;                   // empezó otro bloque
                if ($arriba === 'TOTAL' || str_starts_with($arriba, 'TOTAL')) { // cierre del bloque
                    $totalHoja = LectorXlsx::aNumero($celdaMonto);
                    break;
                }

                if ($texto === '' && $celdaMonto === null) {
                    if (++$vacias >= 4) break;   // hueco largo: se acabó el bloque
                    continue;
                }
                $vacias = 0;
                if ($texto === '') continue;

                $monto = LectorXlsx::aNumero($celdaMonto);
                $alumnos[] = [
                    'fila_excel'    => $f,
                    'nombre_crudo'  => $texto,
                    'nombre'        => $this->separarNombre($texto)['nombre'],
                    'apellido'      => $this->separarNombre($texto)['apellido'],
                    'monto'         => $monto,
                    'observaciones' => is_string($celdaObs) ? trim($celdaObs) : '',
                    'problema'      => $monto === null ? 'Sin cantidad a pagar' : null,
                ];
            }

            $suma = 0.0;
            foreach ($alumnos as $a) { $suma += (float) ($a['monto'] ?? 0); }

            $partes = $this->separarNombre($e['nombre']);
            $bloques[] = [
                'maestro'          => $partes['nombre'] . ' ' . $partes['apellido'],
                'maestro_nombre'   => $partes['nombre'],
                'maestro_apellido' => $partes['apellido'],
                'fila_excel'       => $e['fila'],
                'alumnos'          => $alumnos,
                'total_hoja'       => $totalHoja,
                'total_calculado'  => round($suma, 2),
                'diferencia'       => $totalHoja === null ? null : round($totalHoja - $suma, 2),
            ];
        }

        usort($bloques, fn($a, $b) => strcmp($a['maestro_apellido'], $b['maestro_apellido']));
        return $bloques;
    }

    // =================================================================
    // 2. ESCRITURA
    // =================================================================

    /**
     * @param string $periodo 'YYYY-MM'
     * @return array resumen de lo que se guardó
     */
    public function importar(string $ruta, string $periodo, ?string $hoja = null, ?string $usuario = null, string $archivo = ''): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            throw new Exception('El periodo debe tener el formato AAAA-MM, por ejemplo 2026-02.');
        }

        $previa  = $this->previsualizar($ruta, $hoja);
        $bloques = $previa['bloques'];
        if (!$bloques) {
            throw new Exception('No se encontró ningún bloque "PROFESOR:" en la hoja. Revisa que sea el archivo correcto.');
        }

        $nuevosMaestros = 0; $nuevosAlumnos = 0; $renglones = 0; $total = 0.0;
        $omitidos = [];

        // Una importación a la vez: si alguien más está importando, no se encima.
        if (!Database::candado('importar', 0)) {
            throw new Exception('Otra persona está importando un archivo en este momento. Espera a que termine y vuelve a intentarlo.');
        }
        $this->db->beginTransaction();
        try {
            foreach ($bloques as $b) {
                if (!$b['alumnos']) continue;

                [$idMaestro, $creado] = $this->buscarOCrearMaestro($b['maestro_nombre'], $b['maestro_apellido']);
                if ($creado) $nuevosMaestros++;

                foreach ($b['alumnos'] as $a) {
                    if ($a['monto'] === null || $a['monto'] <= 0) {
                        $omitidos[] = ['fila' => $a['fila_excel'], 'alumno' => $a['nombre_crudo'], 'motivo' => 'sin monto'];
                        continue;
                    }

                    [$idAlumno, $creadoA] = $this->buscarOCrearAlumno($a['nombre'], $a['apellido']);
                    if ($creadoA) $nuevosAlumnos++;

                    $idInscripcion = $this->buscarOCrearInscripcion($idAlumno, $idMaestro, (float) $a['monto']);
                    if ($idInscripcion === null) {
                        $omitidos[] = ['fila' => $a['fila_excel'], 'alumno' => $a['nombre_crudo'],
                                       'motivo' => 'su clase con ese maestro está dada de baja: no se volvió a dar de alta '
                                                 . '(si regresó, reactívala desde su ficha)'];
                        continue;
                    }
                    // Un renglón ya pagado o ajustado a mano no lo cambia una importación.
                    $ya = $this->db->prepare(
                        "SELECT pagado, ajuste_manual FROM nomina_detalle WHERE id_inscripcion = ? AND periodo = ? AND id_maestro = ?"
                    );
                    $ya->execute([$idInscripcion, $periodo, $idMaestro]);
                    $previo = $ya->fetch();
                    if ($previo && ((int) $previo['pagado'] === 1 || (int) $previo['ajuste_manual'] === 1)) {
                        $omitidos[] = ['fila' => $a['fila_excel'], 'alumno' => $a['nombre_crudo'],
                                       'motivo' => (int) $previo['pagado'] === 1
                                           ? 'ese mes ya se le pagó al maestro: no se cambió'
                                           : 'ese renglón se ajustó a mano: no se cambió'];
                        continue;
                    }

                    // Una fila por inscripción y periodo; si ya existe (sin pagar), se actualiza el monto.
                    $this->db->prepare(
                        "INSERT INTO nomina_detalle (id_maestro, id_inscripcion, id_alumno, periodo, monto)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE monto = VALUES(monto), id_maestro = VALUES(id_maestro)"
                    )->execute([$idMaestro, $idInscripcion, $idAlumno, $periodo, $a['monto']]);

                    $renglones++;
                    $total += (float) $a['monto'];
                }

                // La tarifa del maestro se ajusta al monto más frecuente de sus alumnos.
                $this->actualizarTarifa($idMaestro, $b['alumnos']);
            }

            $this->db->prepare(
                "INSERT INTO importaciones (tipo, archivo, periodo, maestros_nuevos, alumnos_nuevos, filas_importadas, total, usuario)
                 VALUES ('nomina', ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $archivo ?: basename($ruta), $periodo,
                $nuevosMaestros, $nuevosAlumnos, $renglones, round($total, 2), $usuario
            ]);

            $this->db->commit();
            Database::soltar('importar');
        } catch (Throwable $e) {
            $this->db->rollBack();
            Database::soltar('importar');
            throw new Exception('No se importó nada. ' . $e->getMessage());
        }

        return [
            'periodo'          => $periodo,
            'maestros'         => count($bloques),
            'maestros_nuevos'  => $nuevosMaestros,
            'alumnos_nuevos'   => $nuevosAlumnos,
            'renglones'        => $renglones,
            'total'            => round($total, 2),
            'omitidos'         => $omitidos,
            'descuadres'       => $previa['resumen']['descuadres'],
        ];
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    /**
     * Separa un nombre completo escrito en una sola celda.
     * Reglas: 4 o más palabras -> 2 de nombre y el resto de apellido.
     *         3 palabras      -> 1 de nombre y 2 de apellido.
     *         2 palabras      -> 1 y 1.
     *         1 palabra       -> todo va en nombre.
     * Las partículas (de, del, la...) se pegan al apellido que las sigue.
     * Si viene "Apellido, Nombre", respeta la coma.
     */
    public function separarNombre(string $completo): array
    {
        $completo = trim(preg_replace('/\s+/u', ' ', $completo));
        if ($completo === '') return ['nombre' => '', 'apellido' => ''];

        if (str_contains($completo, ',')) {
            [$ape, $nom] = array_map('trim', explode(',', $completo, 2));
            return ['nombre' => $this->titular($nom), 'apellido' => $this->titular($ape)];
        }

        $palabras = explode(' ', $completo);
        $n = count($palabras);

        if ($n === 1) return ['nombre' => $this->titular($palabras[0]), 'apellido' => ''];
        if ($n === 2) return ['nombre' => $this->titular($palabras[0]), 'apellido' => $this->titular($palabras[1])];

        $corte = $n >= 4 ? 2 : 1;

        // Si la palabra donde cortaríamos es una partícula, el apellido empieza antes.
        while ($corte > 1 && in_array(mb_strtolower($palabras[$corte - 1], 'UTF-8'), self::PARTICULAS, true)) {
            $corte--;
        }

        $nombre   = implode(' ', array_slice($palabras, 0, $corte));
        $apellido = implode(' ', array_slice($palabras, $corte));
        return ['nombre' => $this->titular($nombre), 'apellido' => $this->titular($apellido)];
    }

    /** MAYÚSCULAS o minúsculas -> Formato Título, dejando las partículas en minúscula */
    private function titular(string $texto): string
    {
        $texto = mb_convert_case(mb_strtolower(trim($texto), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $palabras = explode(' ', $texto);
        foreach ($palabras as $i => $p) {
            if ($i > 0 && in_array(mb_strtolower($p, 'UTF-8'), self::PARTICULAS, true)) {
                $palabras[$i] = mb_strtolower($p, 'UTF-8');
            }
        }
        return implode(' ', $palabras);
    }

    /** Para comparar nombres sin importar acentos, mayúsculas ni espacios de más */
    private function clave(string $nombre, string $apellido): string
    {
        return Texto::normalizar($nombre . ' ' . $apellido);
    }

    private function buscarOCrearMaestro(string $nombre, string $apellido): array
    {
        $clave = $this->clave($nombre, $apellido);
        foreach ($this->db->query("SELECT id_maestro, nombre, apellido FROM maestros") as $m) {
            if ($this->clave($m['nombre'], $m['apellido']) === $clave) {
                return [(int) $m['id_maestro'], false];
            }
        }
        $stmt = $this->db->prepare("INSERT INTO maestros (nombre, apellido) VALUES (?, ?)");
        $stmt->execute([$nombre, $apellido]);
        return [(int) $this->db->lastInsertId(), true];
    }

    private function buscarOCrearAlumno(string $nombre, string $apellido): array
    {
        $clave = $this->clave($nombre, $apellido);
        foreach ($this->db->query("SELECT id_alumno, nombre, apellido FROM alumnos") as $a) {
            if ($this->clave($a['nombre'], $a['apellido']) === $clave) {
                return [(int) $a['id_alumno'], false];
            }
        }
        $stmt = $this->db->prepare("INSERT INTO alumnos (nombre, apellido) VALUES (?, ?)");
        $stmt->execute([$nombre, $apellido]);
        return [(int) $this->db->lastInsertId(), true];
    }

    /**
     * Busca una inscripción viva de ese alumno con ese maestro. Si no existe,
     * la crea como 'pendiente': el alumno ya paga y ya tiene maestro, pero
     * todavía no tiene día, hora ni salón. Esos datos llegan con el Excel
     * de horarios, o se capturan desde el módulo de Inscripciones.
     */
    /** @return int|null null si esa clase está dada de baja: una importación no la revive */
    private function buscarOCrearInscripcion(int $idAlumno, int $idMaestro, float $costo): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id_inscripcion FROM inscripciones
              WHERE id_alumno = ? AND id_maestro = ? AND estado <> 'baja'
              ORDER BY estado = 'activa' DESC, id_inscripcion DESC LIMIT 1"
        );
        $stmt->execute([$idAlumno, $idMaestro]);
        $id = $stmt->fetchColumn();
        if ($id) return (int) $id;

        // Si ya tuvo esa clase y se dio de baja, no se crea otra: así una hoja
        // vieja no devuelve a la nómina a alguien que ya no viene.
        $baja = $this->db->prepare("SELECT COUNT(*) FROM inscripciones WHERE id_alumno = ? AND id_maestro = ? AND estado = 'baja'");
        $baja->execute([$idAlumno, $idMaestro]);
        if ((int) $baja->fetchColumn() > 0) return null;

        // OJO: $costo es lo que se le paga al MAESTRO, no lo que paga el alumno.
        // La colegiatura depende de la materia y la duración, que esta hoja no
        // trae, así que se deja en cero y aparece en Pendientes para ponerla.
        $stmt = $this->db->prepare(
            "INSERT INTO inscripciones (id_alumno, id_maestro, costo_mensual, estado, origen)
             VALUES (?, ?, 0, 'pendiente', 'importacion')"
        );
        $stmt->execute([$idAlumno, $idMaestro]);
        return (int) $this->db->lastInsertId();
    }

    private function actualizarTarifa(int $idMaestro, array $alumnos): void
    {
        $montos = array_filter(array_column($alumnos, 'monto'), fn($m) => $m !== null && $m > 0);
        if (!$montos) return;
        $conteo = array_count_values(array_map('strval', $montos));
        arsort($conteo);
        $frecuente = (float) array_key_first($conteo);
        $this->db->prepare("UPDATE maestros SET tarifa_por_alumno = ? WHERE id_maestro = ? AND tarifa_por_alumno = 0")
                 ->execute([$frecuente, $idMaestro]);
    }

    /** Busca el mes y el año en el título de la hoja o en el nombre del archivo */
    private function detectarPeriodo(array $rejilla, string $archivo): string
    {
        $texto = mb_strtolower($archivo, 'UTF-8') . ' ';
        $filas = 0;
        foreach ($rejilla as $fila) {
            foreach ($fila as $v) { if (is_string($v)) $texto .= ' ' . mb_strtolower($v, 'UTF-8'); }
            if (++$filas > 8) break;
        }

        $mes = null;
        foreach (self::MESES as $nombre => $num) {
            if (str_contains($texto, $nombre)) { $mes = $num; break; }
        }
        preg_match('/(20\d{2})/', $texto, $m);
        $anio = $m[1] ?? date('Y');

        return $mes ? "$anio-$mes" : date('Y-m');
    }
}
