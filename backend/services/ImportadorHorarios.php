<?php
require_once __DIR__ . '/../lib/Texto.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/LectorXlsx.php';
require_once __DIR__ . '/../models/HorarioModel.php';
require_once __DIR__ . '/../models/ConfiguracionModel.php';
require_once __DIR__ . '/InscripcionService.php';

/**
 * ImportadorHorarios — lee la hoja de horarios de la escuela.
 *
 * La hoja es un tablero visual: un bloque por día, con los salones en columnas
 * y las medias horas en renglones. Cada celda dice "Alumno-Maestro", y una
 * clase de una hora ocupa dos renglones seguidos con el mismo texto.
 *
 * Lo difícil no es leerla, es casarla con la base: la hoja trae solo nombres
 * de pila ("Sofía-Laura") y a veces escritos distinto el mismo día
 * (Federico / Federco / Fede). Por eso cada clase se clasifica:
 *
 *   asignar  -> se encontró exactamente una inscripción que le corresponde
 *   ambigua  -> hay varias posibles; las decide una persona, no el sistema
 *   nueva    -> ese alumno con ese maestro no existe todavía
 *   problema -> fuera del horario de la escuela, sin maestro, o salón raro
 *
 * Nada se escribe hasta que se confirma.
 */
class ImportadorHorarios
{
    private PDO $db;
    private HorarioModel $horarios;

    private const DIAS = [
        'LUNES' => 'Lunes', 'MARTES' => 'Martes',
        'MIERCOLES' => 'Miercoles', 'MIÉRCOLES' => 'Miercoles',
        'JUEVES' => 'Jueves', 'VIERNES' => 'Viernes',
        'SABADO' => 'Sabado', 'SÁBADO' => 'Sabado',
    ];

    public function __construct()
    {
        $this->db = Database::conexion();
        $this->horarios = new HorarioModel();
    }

    // =================================================================
    // LECTURA
    // =================================================================

    public function previsualizar(string $ruta, ?string $hoja = null): array
    {
        $lector  = new LectorXlsx($ruta);
        $rejilla = $lector->rejilla($hoja !== null && $hoja !== '' ? $hoja : 0);

        $clases = $this->extraerClases($rejilla);
        $this->clasificar($clases);

        $cuenta = ['asignar' => 0, 'ambigua' => 0, 'nueva' => 0, 'problema' => 0];
        foreach ($clases as $c) { $cuenta[$c['estado']]++; }

        $salonesHoja = array_values(array_unique(array_column($clases, 'salon_texto')));
        $desconocidos = array_values(array_filter($salonesHoja, fn($s) => $this->buscarSalon($s) === null));

        return [
            'hojas'        => $lector->hojas(),
            'clases'       => $clases,
            'resumen'      => [
                'total'               => count($clases),
                'asignar'             => $cuenta['asignar'],
                'ambigua'             => $cuenta['ambigua'],
                'nueva'               => $cuenta['nueva'],
                'problema'            => $cuenta['problema'],
                'salones_nuevos'      => $desconocidos,
                'precio_hora_musica'  => $this->precioMusica(),
            ],
        ];
    }

    /**
     * Recorre la hoja bloque por bloque y junta los renglones consecutivos
     * que dicen lo mismo: dos renglones iguales son una clase de una hora.
     */
    private function extraerClases(array $rejilla): array
    {
        // 1. Dónde empieza cada día
        $bloques = [];
        foreach ($rejilla as $nf => $fila) {
            foreach ($fila as $valor) {
                if (!is_string($valor)) continue;
                $clave = mb_strtoupper(trim($valor), 'UTF-8');
                if (isset(self::DIAS[$clave])) { $bloques[$nf] = self::DIAS[$clave]; break; }
            }
        }
        if (!$bloques) return [];
        ksort($bloques);

        $filas = array_keys($rejilla);
        $ultima = $filas ? max($filas) : 0;
        $inicios = array_keys($bloques);

        // 2. Celdas con contenido, agrupadas por día y columna
        $porColumna = [];
        foreach ($inicios as $i => $filaDia) {
            $dia = $bloques[$filaDia];
            $fin = $inicios[$i + 1] ?? ($ultima + 1);

            // El renglón siguiente al día trae los nombres de los salones.
            $filaCab = $filaDia + 1;
            $salones = [];
            foreach (($rejilla[$filaCab] ?? []) as $col => $valor) {
                if (is_string($valor) && trim($valor) !== '') $salones[$col] = trim($valor);
            }
            if (!$salones) continue;

            for ($f = $filaCab + 1; $f < $fin; $f++) {
                $hora = $this->horaDe($rejilla[$f][1] ?? null);
                if ($hora === null) continue;

                foreach ($salones as $col => $salon) {
                    $texto = $rejilla[$f][$col] ?? null;
                    if (!is_string($texto) || trim($texto) === '') continue;
                    $porColumna["$dia|$salon"][] = ['fila' => $f, 'hora' => $hora, 'texto' => trim($texto)];
                }
            }
        }

        // 3. Fusionar renglones seguidos con el mismo texto
        $clases = [];
        foreach ($porColumna as $clave => $celdas) {
            [$dia, $salon] = explode('|', $clave, 2);
            usort($celdas, fn($a, $b) => $a['fila'] <=> $b['fila']);

            // Se compara el par alumno/maestro ya separado, no el texto tal cual:
            // en la hoja la misma clase aparece como "Andrés-Paula" en un
            // renglón y "Andrés- Paula" en el siguiente.
            $clave = function (string $texto): string {
                [$a, $m] = $this->partir($texto);
                return $this->normalizar($a) . '|' . $this->normalizar($m);
            };

            $i = 0;
            while ($i < count($celdas)) {
                $n = 1;
                while (isset($celdas[$i + $n])
                    && $celdas[$i + $n]['fila'] === $celdas[$i]['fila'] + $n
                    && $clave($celdas[$i + $n]['texto']) === $clave($celdas[$i]['texto'])) {
                    $n++;
                }

                [$alumno, $maestro] = $this->partir($celdas[$i]['texto']);
                $minutos = $n * 30;

                $clases[] = [
                    'dia'           => $dia,
                    'salon_texto'   => $salon,
                    'hora_inicio'   => $celdas[$i]['hora'] . ':00',
                    'hora_fin'      => $this->sumar($celdas[$i]['hora'], $minutos) . ':00',
                    'duracion_min'  => $minutos,
                    'texto'         => $celdas[$i]['texto'],
                    'alumno_texto'  => $alumno,
                    'maestro_texto' => $maestro,
                    'fila_excel'    => $celdas[$i]['fila'],
                ];
                $i += $n;
            }
        }

        usort($clases, function ($a, $b) {
            $orden = ['Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3, 'Jueves' => 4, 'Viernes' => 5, 'Sabado' => 6];
            return [$orden[$a['dia']] ?? 9, $a['hora_inicio'], $a['salon_texto']]
               <=> [$orden[$b['dia']] ?? 9, $b['hora_inicio'], $b['salon_texto']];
        });

        return $clases;
    }

    /**
     * Las horas de la tarde vienen escritas como 01:00 a 08:00 en la hoja.
     * La escuela no abre de madrugada, así que todo lo menor o igual a 8 es
     * de la tarde y se le suman 12 horas.
     */
    private function horaDe($valor): ?string
    {
        if ($valor === null) return null;

        if (is_float($valor) || is_int($valor)) {
            // Excel guarda una hora suelta como fracción de día.
            $minutos = (int) round(((float) $valor - floor((float) $valor)) * 1440);
            $h = intdiv($minutos, 60); $m = $minutos % 60;
        } elseif (is_string($valor) && preg_match('/^(\d{1,2}):(\d{2})/', trim($valor), $mm)) {
            $h = (int) $mm[1]; $m = (int) $mm[2];
        } else {
            return null;
        }

        if ($h === 0) return null;
        if ($h <= 8) $h += 12;
        if ($h > 22) return null;

        return sprintf('%02d:%02d', $h, $m);
    }

    private function sumar(string $hora, int $minutos): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        $t = $h * 60 + $m + $minutos;
        return sprintf('%02d:%02d', intdiv($t, 60) % 24, $t % 60);
    }

    /** "Sofía - Laura" -> ['Sofía', 'Laura'] */
    private function partir(string $texto): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        $partes = preg_split('/\s*[-–—]\s*/u', $texto);
        if (count($partes) >= 2) {
            return [trim($partes[0]), trim(implode(' ', array_slice($partes, 1)))];
        }
        return [$texto, ''];
    }

    // =================================================================
    // EMPAREJAR CON LA BASE
    // =================================================================

    private function clasificar(array &$clases): void
    {
        $inscripciones = $this->db->query(
            "SELECT ins.id_inscripcion, ins.id_alumno, ins.id_maestro, ins.estado,
                    ins.dia_semana, ins.hora_inicio, ins.costo_mensual, ins.duracion_min,
                    a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    m.nombre AS maestro_nombre, m.apellido AS maestro_apellido
               FROM inscripciones ins
               JOIN alumnos a  ON a.id_alumno = ins.id_alumno
               JOIN maestros m ON m.id_maestro = ins.id_maestro
              WHERE ins.estado <> 'baja'"
        )->fetchAll();

        foreach ($clases as &$c) {
            $c['candidatos'] = [];
            $c['id_inscripcion'] = null;
            $c['motivo'] = null;

            if ($c['maestro_texto'] === '') {
                $c['estado'] = 'problema';
                $c['motivo'] = 'La celda no dice el maestro. Debe decir "Alumno-Maestro".';
                continue;
            }

            $fuera = $this->horarios->fueraDeHorario($c['dia'], $c['hora_inicio'], $c['hora_fin']);
            if ($fuera) {
                $c['estado'] = 'problema';
                $c['motivo'] = $fuera;
                continue;
            }

            $posibles = [];
            foreach ($inscripciones as $ins) {
                if ($this->parecido($c['alumno_texto'], $ins['alumno_nombre'])
                 && $this->parecido($c['maestro_texto'], $ins['maestro_nombre'])) {
                    $posibles[] = [
                        'id_inscripcion' => (int) $ins['id_inscripcion'],
                        'alumno'         => trim($ins['alumno_nombre'] . ' ' . $ins['alumno_apellido']),
                        'maestro'        => trim($ins['maestro_nombre'] . ' ' . $ins['maestro_apellido']),
                        'costo_mensual'  => (float) $ins['costo_mensual'],
                        'ya_tiene'       => $ins['dia_semana']
                            ? $ins['dia_semana'] . ' ' . substr($ins['hora_inicio'], 0, 5) : null,
                    ];
                }
            }

            if (count($posibles) === 1) {
                $c['estado'] = 'asignar';
                $c['id_inscripcion'] = $posibles[0]['id_inscripcion'];
                $c['candidatos'] = $posibles;
            } elseif (count($posibles) > 1) {
                $c['estado'] = 'ambigua';
                $c['candidatos'] = $posibles;
                $c['motivo'] = 'Hay ' . count($posibles) . ' alumnos que podrían ser. Asigna esta clase a mano.';
            } else {
                $c['estado'] = 'nueva';
                $c['motivo'] = 'Ese alumno con ese maestro no existe en el sistema.';
                $c['costo_sugerido'] = $this->precioPorDuracion($c['duracion_min']);
            }
        }
        unset($c);
    }

    /** Sin acentos, sin mayúsculas, sin espacios de más */
    private function normalizar(string $t): string
    {
        return Texto::normalizar($t);
    }

    /**
     * ¿El nombre corto de la hoja se refiere a este nombre completo?
     * Cubre abreviaturas (Fran -> Francisco, Fede -> Federico) y erratas de
     * una letra (Robreto -> Roberto, Federco -> Federico).
     */
    private function parecido(string $corto, string $completo): bool
    {
        $c = $this->normalizar($corto);
        $l = $this->normalizar($completo);
        if ($c === '' || $l === '') return false;

        foreach (explode(' ', $l) as $palabra) {
            if ($palabra === $c) return true;
            if (mb_strlen($c) >= 4 && str_starts_with($palabra, mb_substr($c, 0, max(4, mb_strlen($c) - 1)))) return true;
            if (mb_strlen($c) >= 5 && levenshtein($c, $palabra) <= 1) return true;
        }
        return false;
    }

    private function precioMusica(): float
    {
        $v = $this->db->query(
            "SELECT costo_mensual FROM instrumentos
              WHERE costo_mensual > 0 AND nombre NOT IN ('Pintura','Dibujo')
              ORDER BY costo_mensual DESC LIMIT 1"
        )->fetchColumn();
        return $v ? (float) $v : 900.0;
    }

    private function precioPorDuracion(int $minutos): float
    {
        return round($this->precioMusica() * ($minutos / 60), 2);
    }

    /** Caché de salones; se rearma con refrescarSalones() tras dar de alta uno nuevo */
    private ?array $salones = null;

    private function buscarSalon(string $nombre): ?int
    {
        if ($this->salones === null) $this->refrescarSalones();
        return $this->salones[$this->normalizar($nombre)] ?? null;
    }

    private function refrescarSalones(): void
    {
        $this->salones = [];
        foreach ($this->db->query("SELECT id_salon, nombre FROM salones") as $s) {
            $this->salones[$this->normalizar($s['nombre'])] = (int) $s['id_salon'];
        }
    }

    // =================================================================
    // ESCRITURA
    // =================================================================

    /**
     * Importa la hoja. Cada clase pasa por las mismas reglas que cuando alguien
     * la captura en pantalla (InscripcionService::resolverHueco y completar):
     * horario de la escuela, choques de salón y de maestro, cupo, taller,
     * clases compartidas y maestro o salón activos. Una clase que no cabe NO
     * tumba la importación: se salta, se dice su fila de Excel, el alumno y el
     * motivo (el mismo mensaje que vería en pantalla), y el resto entra.
     *
     * @param array $opciones crear_faltantes: también da de alta los alumnos,
     *                        maestros e inscripciones que no existan;
     *                        usuario: quién importa (queda en el historial de cada clase).
     * @return array asignadas, creadas, alumnos_nuevos, maestros_nuevos, salones_nuevos y
     *               omitidas (= omitidos): por cada una fila (de Excel), alumno, maestro, clase, cuando, motivo
     */
    public function importar(string $ruta, array $opciones = [], ?string $hoja = null, string $archivo = ''): array
    {
        // El horario se valida y se escribe con el candado de horario: mientras se
        // importa, nadie más guarda uno. Si en 5 segundos no se libera, se avisa.
        return HorarioModel::conCandado(fn() => $this->importarConCandado($ruta, $opciones, $hoja, $archivo));
    }

    private function importarConCandado(string $ruta, array $opciones, ?string $hoja, string $archivo): array
    {
        $previa = $this->previsualizar($ruta, $hoja);
        $clases = $previa['clases'];
        if (!$clases) throw new Exception('No se encontró ningún horario en la hoja.');

        $crear = !empty($opciones['crear_faltantes']);
        $usuario = $opciones['usuario'] ?? null;

        $asignadas = 0; $creadas = 0; $omitidas = [];
        $alumnosNuevos = 0; $maestrosNuevos = 0; $salonesNuevos = 0;

        // Una importación a la vez: si alguien más está importando, no se encima.
        if (!Database::candado('importar', 0)) {
            throw new Exception('Otra persona está importando un archivo en este momento. Espera a que termine y vuelve a intentarlo.');
        }
        $this->db->beginTransaction();
        try {
            // Los salones que la hoja usa y el sistema no conoce (la Terraza,
            // por ejemplo) se dan de alta como espacios exteriores.
            foreach ($previa['resumen']['salones_nuevos'] as $nombre) {
                $this->db->prepare("INSERT INTO salones (nombre, capacidad, tipo) VALUES (?, 4, 'exterior')")
                         ->execute([$nombre]);
                $salonesNuevos++;
            }
            $this->refrescarSalones();

            foreach ($clases as $c) {
                $omitir = function (string $motivo) use (&$omitidas, $c) {
                    $omitidas[] = ['fila' => $c['fila_excel'] ?? null, 'alumno' => $c['alumno_texto'],
                                   'maestro' => $c['maestro_texto'], 'clase' => $c['texto'],
                                   'cuando' => $c['dia'] . ' ' . substr($c['hora_inicio'], 0, 5), 'motivo' => $motivo];
                };
                if ($c['estado'] === 'problema' || $c['estado'] === 'ambigua') { $omitir((string) $c['motivo']); continue; }
                if ($c['estado'] === 'nueva' && !$crear) { $omitir('No existe y no se pidió crearla.'); continue; }

                $idSalon = $this->buscarSalon($c['salon_texto']);
                if (!$idSalon) { $omitir('No se reconoció el salón "' . $c['salon_texto'] . '".'); continue; }

                // Cada fila va en su propio punto de restauración: si no cabe, se deshace
                // solo esa (incluidas las personas que se hubieran creado) y sigue la siguiente.
                $this->db->exec('SAVEPOINT fila_horario');
                try {
                    $r = $c['estado'] === 'nueva'
                        ? $this->crearClase($c, $idSalon)
                        : $this->acomodarClase($c, $idSalon, $usuario);
                } catch (Throwable $e) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT fila_horario');
                    $this->db->exec('RELEASE SAVEPOINT fila_horario');
                    $omitir(self::motivoLegible($e));
                    continue;
                }
                $this->db->exec('RELEASE SAVEPOINT fila_horario');
                if ($r['creada']) $creadas++; else $asignadas++;
                $alumnosNuevos  += $r['alumno_nuevo'] ? 1 : 0;
                $maestrosNuevos += $r['maestro_nuevo'] ? 1 : 0;
            }

            $this->db->prepare(
                "INSERT INTO importaciones (tipo, archivo, filas_importadas, alumnos_nuevos, maestros_nuevos, usuario)
                 VALUES ('horarios', ?, ?, ?, ?, ?)"
            )->execute([
                $archivo ?: basename($ruta), $asignadas + $creadas,
                $alumnosNuevos, $maestrosNuevos, $usuario
            ]);

            $this->db->commit();
            Database::soltar('importar');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            Database::soltar('importar');
            throw new Exception('No se importó nada. ' . $e->getMessage());
        }

        return [
            'asignadas'       => $asignadas,
            'creadas'         => $creadas,
            'alumnos_nuevos'  => $alumnosNuevos,
            'maestros_nuevos' => $maestrosNuevos,
            'salones_nuevos'  => $salonesNuevos,
            'omitidas'        => $omitidas,
            'omitidos'        => $omitidas,
        ];
    }

    /** El motivo tal como se lee en pantalla: sin el prefijo de la operación y sin detalles de SQL */
    private static function motivoLegible(Throwable $e): string
    {
        // completar() envuelve los errores de SQL en una Exception normal: se reconocen por su texto.
        if ($e instanceof PDOException || preg_match('/SQLSTATE|Duplicate entry|Integrity constraint/i', $e->getMessage())) {
            error_log('Sistema Escuela: importar horarios: ' . $e->getMessage());
            return 'El horario cambió mientras se importaba y esa clase ya no cabe. Revísala a mano.';
        }
        $m = preg_replace('/^No se pudo (asignar el horario|completar el alta): /u', '', $e->getMessage());
        return preg_replace('/^CONFIRMAR_GRUPO: /u', '', $m);
    }

    /**
     * Una clase que ya existía (pendiente o con otro horario) toma el horario de
     * la hoja. Va por InscripcionService::completar, que es lo que usa la
     * edición: mismas reglas, historial de la clase (con el motivo «Importación
     * de horarios») y nómina recalculada si cambia la duración.
     */
    private function acomodarClase(array $c, int $idSalon, ?string $usuario): array
    {
        $s = $this->db->prepare("SELECT id_maestro, id_instrumento, comparte_con FROM inscripciones WHERE id_inscripcion = ?");
        $s->execute([(int) $c['id_inscripcion']]);
        $ins = $s->fetch();
        if (!$ins) throw new Exception('La clase ya no existe.');

        $idMaestro = (int) $ins['id_maestro'];
        $idInstr = $ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null;

        // Si la clase ya se compartía y la hoja la deja en el mismo hueco que su
        // titular, sigue compartiéndola (volver a importar el mismo archivo no debe
        // fallar). El taller no se comparte: su materia decide.
        $comparte = null;
        if ($ins['comparte_con'] && !$this->horarios->esGrupal($idInstr)) {
            $titular = $this->horarios->mismoHueco($idSalon, $idMaestro, $c['dia'], $c['hora_inicio'], $c['hora_fin']);
            if ($titular === (int) $ins['comparte_con']) $comparte = $titular;
        }

        $this->servicio()->completar((int) $c['id_inscripcion'], [
            'id_instrumento' => $idInstr, 'modalidad' => 'presencial', 'id_salon' => $idSalon,
            'dia_semana' => $c['dia'], 'hora_inicio' => $c['hora_inicio'], 'hora_fin' => $c['hora_fin'],
            'compartir_con' => $comparte, 'confirmar_grupo' => 1,
            // La hoja dice cómo es la clase este mes: si cambia de duración o de día, rige desde el día 1
            // (no se parte el mes como en un cambio a mitad de mes).
            'fecha_cambio' => date('Y-m-01'), 'rige_todo_el_mes' => true,
            'motivo' => 'Importación de horarios', 'usuario' => $usuario,
        ]);
        return ['creada' => false, 'alumno_nuevo' => false, 'maestro_nuevo' => false];
    }

    /** Alumno y/o maestro que no existían: se crean junto con su clase, si cabe */
    private function crearClase(array $c, int $idSalon): array
    {
        [$idAlumno, $nuevoA]  = $this->buscarOCrearPersona('alumnos', 'id_alumno', $c['alumno_texto']);
        [$idMaestro, $nuevoM] = $this->buscarOCrearPersona('maestros', 'id_maestro', $c['maestro_texto']);

        // Las mismas reglas que un alta en pantalla (maestro y salón activos, horario
        // de la escuela, choques y cupo). Si no cabe, lanza el motivo y la fila se salta.
        $this->servicio()->resolverHueco([
            'id_alumno' => $idAlumno, 'id_maestro' => $idMaestro, 'id_instrumento' => null,
            'modalidad' => 'presencial', 'id_salon' => $idSalon,
            'dia_semana' => $c['dia'], 'hora_inicio' => $c['hora_inicio'], 'hora_fin' => $c['hora_fin'],
        ]);

        $this->db->prepare(
            "INSERT INTO inscripciones
                (id_alumno, id_maestro, id_salon, dia_semana, hora_inicio, hora_fin,
                 duracion_min, costo_mensual, estado, origen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activa', 'importacion')"
        )->execute([$idAlumno, $idMaestro, $idSalon, $c['dia'], $c['hora_inicio'],
                    $c['hora_fin'], $c['duracion_min'], $c['costo_sugerido'] ?? 0]);
        $idInscripcion = (int) $this->db->lastInsertId();

        $this->db->prepare(
            "INSERT INTO horario_ocupado (id_inscripcion, id_salon, id_maestro, dia_semana, hora_inicio, hora_fin, en_taller)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$idInscripcion, $idSalon, $idMaestro, $c['dia'], $c['hora_inicio'], $c['hora_fin'],
                    $this->horarios->enTallerDe($idInscripcion)]);
        return ['creada' => true, 'alumno_nuevo' => $nuevoA, 'maestro_nuevo' => $nuevoM];
    }

    private ?InscripcionService $servicio = null;

    private function servicio(): InscripcionService
    {
        return $this->servicio ??= new InscripcionService();
    }

    private function buscarOCrearPersona(string $tabla, string $llave, string $nombre): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));
        $buscado = $this->normalizar($nombre);

        foreach ($this->db->query("SELECT $llave AS id, nombre, apellido FROM $tabla") as $p) {
            if ($this->parecido($nombre, $p['nombre'] . ' ' . $p['apellido'])
             && $this->normalizar($p['nombre']) === $buscado) {
                return [(int) $p['id'], false];
            }
        }
        foreach ($this->db->query("SELECT $llave AS id, nombre, apellido FROM $tabla") as $p) {
            if ($this->parecido($nombre, $p['nombre'])) return [(int) $p['id'], false];
        }

        $stmt = $this->db->prepare("INSERT INTO $tabla (nombre, apellido) VALUES (?, '')");
        $stmt->execute([$nombre]);
        return [(int) $this->db->lastInsertId(), true];
    }
}
