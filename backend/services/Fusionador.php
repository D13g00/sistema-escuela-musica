<?php
require_once __DIR__ . '/../lib/Texto.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/NominaModel.php';
require_once __DIR__ . '/../models/HorarioModel.php';
require_once __DIR__ . '/../lib/Bitacora.php';

/**
 * Fusionador — junta dos registros que en realidad son la misma persona.
 *
 * Al importar los horarios, un maestro escrito distinto en cada archivo
 * ("Fede" en la hoja de horarios, "Federico Juárez" en la de nómina)
 * termina como dos registros. Borrar uno no es opción: los dos tienen
 * alumnos, clases y nómina colgando. Hay que mover todo a uno solo.
 *
 * Reglas de la fusión:
 *   · Se conserva el registro "principal" (normalmente el del nombre completo).
 *   · Todo lo del duplicado se mueve al principal: inscripciones, horarios,
 *     nómina, pagos, materias que imparte.
 *   · Si las dos versiones tienen la MISMA clase (mismo alumno, mismo día y
 *     hora), esa clase está duplicada: se conserva una y la otra se borra,
 *     porque si no chocarían entre ellas al moverlas.
 *   · Los datos vacíos del principal se rellenan con los del duplicado
 *     (si al principal le falta el teléfono y el duplicado lo tiene, se queda).
 *   · Todo ocurre en una transacción: o se fusiona completo, o no se toca nada.
 */
class Fusionador
{
    private PDO $db;

    public function __construct() { $this->db = Database::conexion(); }

    // =================================================================
    // DETECCIÓN
    // =================================================================

    public function duplicadosMaestros(): array
    {
        return $this->detectar('maestros', 'id_maestro');
    }

    public function duplicadosAlumnos(): array
    {
        return $this->detectar('alumnos', 'id_alumno');
    }

    /**
     * Busca parejas sospechosas. Dos casos:
     *   · el mismo nombre completo escrito igual salvo acentos o mayúsculas;
     *   · uno sin apellido cuyo nombre corresponde al otro (lo que deja la
     *     importación de horarios, que solo trae nombres de pila).
     */
    private function detectar(string $tabla, string $llave): array
    {
        $filas = $this->db->query(
            "SELECT t.$llave AS id, t.nombre, t.apellido, t.telefono, t.activo,
                    (SELECT COUNT(*) FROM inscripciones i
                      WHERE i." . ($tabla === 'maestros' ? 'id_maestro' : 'id_alumno') . " = t.$llave
                        AND i.estado <> 'baja') AS clases
               FROM $tabla t ORDER BY t.$llave"
        )->fetchAll();

        $parejas = [];
        $n = count($filas);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $filas[$i];
                $b = $filas[$j];
                $motivo = $this->sonLaMisma($a, $b, $tabla);
                if (!$motivo) continue;

                // El principal es el que tiene apellido; si los dos tienen,
                // el que más clases cargue.
                $principal = $a; $duplicado = $b;
                $aCompleto = trim($a['apellido']) !== '';
                $bCompleto = trim($b['apellido']) !== '';
                if (!$aCompleto && $bCompleto) { $principal = $b; $duplicado = $a; }
                elseif ($aCompleto === $bCompleto && (int) $b['clases'] > (int) $a['clases']) {
                    $principal = $b; $duplicado = $a;
                }

                $parejas[] = [
                    'motivo'    => $motivo,
                    'principal' => $principal,
                    'duplicado' => $duplicado,
                    'choques'   => $this->clasesRepetidas($tabla, (int) $principal['id'], (int) $duplicado['id']),
                    'empalmes'  => $tabla === 'maestros'
                        ? $this->empalmes((int) $principal['id'], (int) $duplicado['id']) : 0,
                ];
            }
        }
        return $parejas;
    }

    private function sonLaMisma(array $a, array $b, string $tabla = 'maestros'): ?string
    {
        $na = $this->norm($a['nombre']); $aa = $this->norm($a['apellido']);
        $nb = $this->norm($b['nombre']); $ab = $this->norm($b['apellido']);

        if ($na . ' ' . $aa === $nb . ' ' . $ab) return 'Mismo nombre completo';

        if ($tabla === 'alumnos') return $this->mismoAlumno($a, $b, $na, $aa, $nb, $ab);

        // Uno viene sin apellido (así lo deja la hoja de horarios). Pero OJO:
        // a veces el campo nombre trae el nombre completo ("Mario Ramírez"),
        // y entonces la segunda palabra ES un apellido. Si ese apellido no
        // corresponde al del otro registro, son personas distintas.
        if ($aa === '' xor $ab === '') {
            $corto    = $aa === '' ? $na : $nb;          // el que no tiene apellido
            $nombreL  = $aa === '' ? $nb : $na;          // nombre del completo
            $apellidoL= $aa === '' ? $ab : $aa;          // apellido del completo

            $palabras = explode(' ', $corto);
            if (!$this->parecido($palabras[0], $nombreL)) return null;

            if (count($palabras) > 1) {
                // Lo que sigue al primer nombre tiene que coincidir con el
                // apellido del otro, o con su segundo nombre. Si no, es otra persona.
                $resto = implode(' ', array_slice($palabras, 1));
                $completo = trim($nombreL . ' ' . $apellidoL);
                $casa = false;
                foreach (explode(' ', $resto) as $p) {
                    if (mb_strlen($p) < 3) continue;
                    foreach (explode(' ', $completo) as $q) {
                        if ($p === $q || (mb_strlen($p) >= 4 && $this->prefijoComun($p, $q) >= 4)) { $casa = true; }
                    }
                }
                if (!$casa) return null;
            }
            return 'Uno quedó sin apellido y el nombre corresponde';
        }

        // Mismo nombre de pila y mismo apellido, escrito distinto.
        if ($na === $nb && $aa !== '' && $ab !== '' && levenshtein($aa, $ab) <= 1) {
            return 'El apellido cambia por una letra';
        }
        return null;
    }

    /**
     * Con alumnos el parecido de nombre NO basta. Hay decenas de niños y se
     * repiten los nombres: Marisol no es Marisa, Daniel no es Daniela, y dos
     * Mario con apellidos distintos son dos personas. Juntar a dos alumnos
     * distintos mezcla sus clases, sus pagos y la nómina de sus maestros.
     *
     * Por eso solo se propone la fusión cuando hay evidencia:
     *   · uno quedó sin apellido (lo deja la hoja de horarios),
     *   · su nombre coincide EXACTO con el primer nombre del otro, y
     *   · los dos toman clase con el mismo maestro.
     * Si los dos tienen apellido y son distintos, nunca se proponen.
     */
    private function mismoAlumno(array $a, array $b, string $na, string $aa, string $nb, string $ab): ?string
    {
        if ($aa !== '' && $ab !== '') return null;   // dos apellidos: dos personas
        if ($aa === '' && $ab === '') return null;   // dos nombres sueltos: no hay con qué comparar

        $corto = $aa === '' ? $na : $nb;
        $largo = $aa === '' ? $nb . ' ' . $ab : $na . ' ' . $aa;

        // El nombre corto puede traer apellido pegado ("Mario Ramirez" en una
        // sola celda). Si es así, ese apellido tiene que estar en el otro.
        $partesCorto = explode(' ', $corto);
        $partesLargo = explode(' ', $largo);
        if ($partesCorto[0] !== $partesLargo[0]) return null;
        foreach (array_slice($partesCorto, 1) as $extra) {
            if (!in_array($extra, $partesLargo, true)) return null;
        }

        if (!$this->compartenMaestro((int) $a['id'], (int) $b['id'])) return null;

        return 'Mismo nombre y mismo maestro; a uno le falta el apellido';
    }

    private function compartenMaestro(int $alumnoA, int $alumnoB): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones x
               JOIN inscripciones y ON y.id_maestro = x.id_maestro
              WHERE x.id_alumno = ? AND y.id_alumno = ?
                AND x.estado <> 'baja' AND y.estado <> 'baja'"
        );
        $stmt->execute([$alumnoA, $alumnoB]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function norm(?string $t): string
    {
        return Texto::normalizar($t);
    }

    /**
     * Criterio deliberadamente estrecho. Proponer una fusión equivocada es peor
     * que no proponerla: junta a dos personas distintas y eso no se deshace.
     * Por eso solo cuenta como la misma si el nombre corto es principio del
     * largo (Fede -> Federico) o cambia por una sola letra (Robreto -> Roberto).
     * "Nancy" y "Mandy" cambian por dos y no se proponen.
     */
    private function parecido(string $corto, string $largo): bool
    {
        $c = $this->norm($corto);
        if (mb_strlen($c) < 4) return false;

        foreach (explode(' ', $this->norm($largo)) as $palabra) {
            if (mb_strlen($palabra) < 4) continue;
            if ($palabra === $c) return true;

            $comun = $this->prefijoComun($c, $palabra);
            $distancia = levenshtein($c, $palabra);

            // Apodo o recorte: comparten las primeras cuatro letras
            // (Fede / Federico, Fran / Francisco, Mari / Mariana).
            if ($comun >= 4) return true;

            // Errata de una o dos letras, pero empezando igual
            // (Robreto / Roberto). "Nancy" y "Mandy" no comparten inicio.
            if (mb_strlen($c) >= 5 && $distancia <= 2 && $comun >= 2) return true;
        }
        return false;
    }

    private function prefijoComun(string $a, string $b): int
    {
        $n = min(mb_strlen($a), mb_strlen($b));
        for ($i = 0; $i < $n; $i++) {
            if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) return $i;
        }
        return $n;
    }

    /**
     * Clases que existen en los dos registros.
     *
     * Antes se exigía el mismo día y hora, y además se borraba la del
     * PRINCIPAL por un parámetro invertido. Con eso, al fusionar se perdía el
     * renglón de nómina de la clase que se conservaba. Ahora se considera
     * repetida la misma pareja alumno-maestro aunque una no tenga horario, y
     * la consolidación decide cuál conservar sin perder nada (ver consolidar()).
     */
    private function clasesRepetidas(string $tabla, int $principal, int $duplicado): array
    {
        // a = clase del PRINCIPAL, b = clase del DUPLICADO. Mismo alumno y mismo
        // maestro; si las dos tienen horario, además el mismo día y hora (dos
        // clases con horario distinto son dos clases de verdad, no una repetida).
        $mismoHorario = "(a.dia_semana IS NULL OR b.dia_semana IS NULL
                          OR (a.dia_semana = b.dia_semana AND a.hora_inicio = b.hora_inicio))";
        if ($tabla === 'maestros') {
            $sql = "SELECT a.id_inscripcion AS id_a, b.id_inscripcion AS id_b,
                           al.nombre, al.apellido, COALESCE(a.dia_semana, b.dia_semana) AS dia_semana,
                           COALESCE(a.hora_inicio, b.hora_inicio) AS hora_inicio
                      FROM inscripciones a
                      JOIN inscripciones b ON b.id_alumno = a.id_alumno AND b.id_maestro = ?
                      JOIN alumnos al ON al.id_alumno = a.id_alumno
                     WHERE a.id_maestro = ? AND a.estado <> 'baja' AND b.estado <> 'baja'
                       AND $mismoHorario";
            $par = [$duplicado, $principal];
        } else {
            $sql = "SELECT a.id_inscripcion AS id_a, b.id_inscripcion AS id_b,
                           m.nombre, m.apellido, COALESCE(a.dia_semana, b.dia_semana) AS dia_semana,
                           COALESCE(a.hora_inicio, b.hora_inicio) AS hora_inicio
                      FROM inscripciones a
                      JOIN inscripciones b ON b.id_maestro = a.id_maestro AND b.id_alumno = ?
                      JOIN maestros m ON m.id_maestro = a.id_maestro
                     WHERE a.id_alumno = ? AND a.estado <> 'baja' AND b.estado <> 'baja'
                       AND $mismoHorario";
            $par = [$duplicado, $principal];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($par);
        return $stmt->fetchAll();
    }

    /** Clases de los dos que caen a la misma hora con alumnos distintos */
    private function empalmes(int $principal, int $duplicado): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM horario_ocupado hd
               JOIN horario_ocupado hp
                 ON hp.id_maestro = ? AND hp.dia_semana = hd.dia_semana
                AND hp.hora_inicio < hd.hora_fin AND hp.hora_fin > hd.hora_inicio
              WHERE hd.id_maestro = ?"
        );
        $stmt->execute([$principal, $duplicado]);
        return (int) $stmt->fetchColumn();
    }

    // =================================================================
    // SEPARAR: un registro que en realidad son varias personas
    // =================================================================

    /**
     * La hoja de horarios a veces pone a dos o tres alumnos en una sola celda
     * ("Ana y Luz Pérez López", "Beto/Caro/Memo") y quedaron como UN alumno.
     * Aquí se detectan y se propone cómo separarlos.
     */
    public function variasPersonas(): array
    {
        $r = [];
        foreach ($this->db->query(
            "SELECT a.id_alumno, a.nombre, a.apellido,
                    (SELECT COUNT(*) FROM inscripciones i WHERE i.id_alumno = a.id_alumno AND i.estado <> 'baja') AS clases
               FROM alumnos a WHERE a.activo = 1"
        ) as $a) {
            $completo = trim($a['nombre'] . ' ' . $a['apellido']);
            $propuesta = self::proponerSeparacion($completo);
            if (count($propuesta) > 1) {
                $r[] = ['id' => (int) $a['id_alumno'], 'nombre' => $completo,
                        'clases' => (int) $a['clases'], 'propuesta' => $propuesta];
            }
        }
        return $r;
    }

    /**
     * "Ana y Luz Pérez López" -> ["Ana Pérez López", "Luz Pérez López"]
     * "Beto/Caro/Memo"         -> ["Beto", "Caro", "Memo"]
     * El apellido, si viene al final, se reparte a todos.
     */
    public static function proponerSeparacion(string $completo): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $completo));
        $partes = preg_split('/\s*\/\s*|\s*,\s*|\s+y\s+/iu', $t);
        $partes = array_values(array_filter(array_map('trim', $partes), fn($x) => $x !== ''));
        if (count($partes) < 2) return [$t];

        // Apellido compartido: lo que sigue al primer nombre del último trozo.
        $ultimo = explode(' ', end($partes));
        $apellido = count($ultimo) > 1 ? implode(' ', array_slice($ultimo, 1)) : '';

        $r = [];
        foreach ($partes as $i => $p) {
            $palabras = explode(' ', $p);
            $nombre = $palabras[0];
            $r[] = trim($nombre . ' ' . (count($palabras) > 1 && $i < count($partes) - 1
                ? implode(' ', array_slice($palabras, 1)) : $apellido));
        }
        return $r;
    }

    /**
     * Separa el registro: el primero conserva el registro original (y sus
     * pagos); los demás se crean y entran a las mismas clases como compañeros
     * del grupo, cada uno con su propia colegiatura y su renglón de nómina.
     */
    public function separar(int $idAlumno, array $nombres): array
    {
        // Como toda operación que toca el horario, corre con el candado: dos personas a la vez se ponen en fila.
        return HorarioModel::conCandado(fn() => $this->separarConCandado($idAlumno, $nombres));
    }

    private function separarConCandado(int $idAlumno, array $nombres): array
    {
        $nombres = array_values(array_filter(array_map(fn($n) => trim(preg_replace('/\s+/u', ' ', (string) $n)), $nombres)));
        if (count($nombres) < 2) throw new Exception('Hacen falta al menos dos nombres para separar.');
        if (count($nombres) > 8) throw new Exception('Demasiados nombres.');
        foreach ($nombres as $n) {
            if (mb_strlen($n) < 2 || mb_strlen($n) > 120) throw new Exception('Revisa los nombres: alguno está vacío o es demasiado largo.');
        }

        $orig = $this->db->prepare("SELECT * FROM alumnos WHERE id_alumno = ?");
        $orig->execute([$idAlumno]);
        if (!$orig->fetch()) throw new Exception('Ese alumno ya no existe.');

        require_once __DIR__ . '/ImportadorNomina.php';
        $separador = new ImportadorNomina();

        $clases = $this->db->prepare("SELECT * FROM inscripciones WHERE id_alumno = ? AND estado <> 'baja'");
        $clases->execute([$idAlumno]);
        $clases = $clases->fetchAll();

        // Antes de tocar nada: ¿caben todos? Una materia de grupo respeta el cupo
        // del salón; cualquier otra es clase compartida, de máximo 2 (en línea,
        // además, su propio cupo). Si no caben, no se separa a medias: no se separa.
        require_once __DIR__ . '/../models/HorarioModel.php';
        $horario = new HorarioModel();
        $nuevos = count($nombres) - 1;
        foreach ($clases as $c) {
            if ($c['estado'] === 'pendiente') continue;          // sin horario: cada quien la suya
            $titular = $c['comparte_con'] ? (int) $c['comparte_con'] : (int) $c['id_inscripcion'];
            $total = $horario->tamanoGrupo($titular) + $nuevos;
            if ($horario->esGrupal($c['id_instrumento'] ? (int) $c['id_instrumento'] : null)) {
                $cupo = $horario->capacidadSalon((int) $c['id_salon']);
                if ($total > $cupo) throw new Exception("No caben: el grupo quedaría de $total y el salón es para $cupo.");
            } else {
                $cupo = ($c['modalidad'] ?? 'presencial') === 'online' ? min(2, $horario->cupoOnline()) : 2;
                if ($total > $cupo) {
                    throw new Exception('Esa clase no es de grupo: una clase compartida es de máximo 2 alumnos'
                        . ($cupo === 1 ? ', y las clases en línea son individuales' : '')
                        . '. Para separar a más personas, la materia tiene que ser de grupo.');
                }
            }
        }

        $creados = [];
        $this->db->beginTransaction();
        try {
            // El primero se queda con el registro original.
            $p = $separador->separarNombre($nombres[0]);
            $this->db->prepare("UPDATE alumnos SET nombre = ?, apellido = ? WHERE id_alumno = ?")
                     ->execute([$p['nombre'], $p['apellido'], $idAlumno]);

            foreach (array_slice($nombres, 1) as $n) {
                $p = $separador->separarNombre($n);
                $this->db->prepare("INSERT INTO alumnos (nombre, apellido) VALUES (?, ?)")
                         ->execute([$p['nombre'], $p['apellido']]);
                $nuevo = (int) $this->db->lastInsertId();

                foreach ($clases as $c) {
                    // Entra a la misma clase como compañero: no ocupa el salón
                    // otra vez, pero sí paga y cuenta en la nómina. Una clase
                    // pendiente no tiene horario que compartir: cada quien la suya.
                    $pendiente = $c['estado'] === 'pendiente';
                    $this->db->prepare(
                        "INSERT INTO inscripciones
                            (id_alumno, id_maestro, id_instrumento, id_salon, modalidad, dia_semana, hora_inicio, hora_fin,
                             duracion_min, costo_mensual, estado, origen, comparte_con)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $nuevo, $c['id_maestro'], $c['id_instrumento'], $c['id_salon'], $c['modalidad'] ?? 'presencial',
                        $c['dia_semana'], $c['hora_inicio'], $c['hora_fin'], $c['duracion_min'], $c['costo_mensual'],
                        $c['estado'], $c['origen'], $pendiente ? null : ($c['comparte_con'] ?: $c['id_inscripcion']),
                    ]);
                }
                $creados[] = $nuevo;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw new Exception('No se separó nada. ' . $e->getMessage());
        }

        (new NominaModel())->sincronizarMes();
        return ['conservado' => $idAlumno, 'creados' => $creados, 'clases_copiadas' => count($clases) * count($creados)];
    }

    // =================================================================
    // FUSIÓN
    // =================================================================

    // Las fusiones mueven y borran ocupaciones del horario: corren con el candado de horario, como
    // cualquier alta o cambio, para no cruzarse con quien está acomodando una clase.
    public function fusionarMaestros(int $principal, int $duplicado): array
    {
        return HorarioModel::conCandado(fn() => $this->fusionar('maestros', 'id_maestro', $principal, $duplicado));
    }

    public function fusionarAlumnos(int $principal, int $duplicado): array
    {
        return HorarioModel::conCandado(fn() => $this->fusionar('alumnos', 'id_alumno', $principal, $duplicado));
    }

    private function fusionar(string $tabla, string $columna, int $principal, int $duplicado): array
    {
        if ($principal === $duplicado) {
            throw new Exception('Son el mismo registro.');
        }
        foreach ([$principal, $duplicado] as $id) {
            $existe = $this->db->prepare("SELECT COUNT(*) FROM $tabla WHERE $columna = ?");
            $existe->execute([$id]);
            if ((int) $existe->fetchColumn() === 0) throw new Exception("El registro $id ya no existe.");
        }

        $repetidas = 0; $movidas = 0; $sinHorario = 0;

        $this->db->beginTransaction();
        try {
            // 1. Las clases que existen en los dos se CONSOLIDAN en una: se
            //    conserva la que tiene horario y se le pasan la nómina, los
            //    pagos y los ajustes de la otra. Nada se borra sin moverse antes.
            foreach ($this->clasesRepetidas($tabla, $principal, $duplicado) as $r) {
                $this->consolidar((int) $r['id_a'], (int) $r['id_b']);
                $repetidas++;
            }

            // 2. Si los dos tienen clase a la misma hora pero con alumnos
            //    distintos, no se pueden mover las dos: el maestro no puede
            //    estar en dos lados. Esa clase se queda sin horario para que
            //    una persona decida, en vez de perderla o romper la fusión.
            if ($tabla === 'maestros') {
                $conflictos = $this->db->prepare(
                    "SELECT hd.id_inscripcion, hd.dia_semana, hd.hora_inicio
                       FROM horario_ocupado hd
                       JOIN horario_ocupado hp
                         ON hp.id_maestro = ? AND hp.dia_semana = hd.dia_semana
                        AND hp.hora_inicio < hd.hora_fin AND hp.hora_fin > hd.hora_inicio
                      WHERE hd.id_maestro = ?"
                );
                $conflictos->execute([$principal, $duplicado]);

                foreach ($conflictos->fetchAll() as $c) {
                    $this->db->prepare("DELETE FROM horario_ocupado WHERE id_inscripcion = ?")
                             ->execute([$c['id_inscripcion']]);
                    $this->db->prepare(
                        "UPDATE inscripciones
                            SET estado = 'pendiente', dia_semana = NULL, hora_inicio = NULL, hora_fin = NULL,
                                id_salon = NULL
                          WHERE id_inscripcion = ?"
                    )->execute([$c['id_inscripcion']]);
                    $sinHorario++;
                }
            }

            // 3. Lo que queda del duplicado se mueve al principal.
            $mover = $this->db->prepare("UPDATE inscripciones SET $columna = ? WHERE $columna = ?");
            $mover->execute([$principal, $duplicado]);
            $movidas = $mover->rowCount();

            // horario_ocupado guarda el maestro, no el alumno: el alumno se
            // sabe a través de la inscripción, que ya se movió.
            if ($tabla === 'maestros') {
                $this->db->prepare("UPDATE horario_ocupado SET id_maestro = ? WHERE id_maestro = ?")
                         ->execute([$principal, $duplicado]);
            }
            $this->db->prepare("UPDATE nomina_detalle SET $columna = ? WHERE $columna = ?")
                     ->execute([$principal, $duplicado]);

            if ($tabla === 'alumnos') {
                $this->db->prepare("UPDATE pagos SET id_alumno = ? WHERE id_alumno = ?")
                         ->execute([$principal, $duplicado]);
                // Lo que el duplicado debía pasa al principal. Fusionar dos
                // registros de la misma persona no le perdona nada: las clases
                // ya se movieron arriba, y sus cargos van con ellas.
                $this->db->prepare("UPDATE cargos_mensuales SET id_alumno = ? WHERE id_alumno = ?")
                         ->execute([$principal, $duplicado]);
                // Si el principal no tiene familia y el duplicado sí, la hereda.
                $this->db->prepare(
                    "UPDATE alumnos p JOIN alumnos d ON d.id_alumno = ?
                        SET p.id_familia = COALESCE(p.id_familia, d.id_familia) WHERE p.id_alumno = ?"
                )->execute([$duplicado, $principal]);
                // Sus solicitudes de lista de espera y sus borradores también.
                foreach (["UPDATE lista_espera SET id_alumno = ? WHERE id_alumno = ?",
                          "UPDATE borradores_alumnos SET id_alumno = ? WHERE id_alumno = ?",
                          "UPDATE borradores_alumnos SET id_alumno_resultante = ? WHERE id_alumno_resultante = ?"] as $sql) {
                    $this->db->prepare($sql)->execute([$principal, $duplicado]);
                }
            } else {
                // Faltas, suplencias, bonos y clases muestra del duplicado.
                $this->db->prepare("UPDATE nomina_ajustes SET id_maestro = ? WHERE id_maestro = ?")
                         ->execute([$principal, $duplicado]);
                $this->db->prepare("UPDATE pagos SET id_maestro = ? WHERE id_maestro = ?")
                         ->execute([$principal, $duplicado]);
                $this->db->prepare("UPDATE lista_espera SET id_maestro = ? WHERE id_maestro = ?")
                         ->execute([$principal, $duplicado]);
                // Las materias que imparte se suman, sin repetir.
                $this->db->prepare(
                    "INSERT IGNORE INTO maestro_instrumento (id_maestro, id_instrumento)
                     SELECT ?, id_instrumento FROM maestro_instrumento WHERE id_maestro = ?"
                )->execute([$principal, $duplicado]);
                $this->db->prepare("DELETE FROM maestro_instrumento WHERE id_maestro = ?")->execute([$duplicado]);
            }

            // 4. Rellenar los huecos del principal con lo que traiga el duplicado.
            $this->completarVacios($tabla, $columna, $principal, $duplicado);
            if ($tabla === 'alumnos') $this->moverFacturas($principal, $duplicado);
            Bitacora::registrar($this->db, 'fusionar ' . $tabla, $tabla, $principal, 'registro repetido',
                "#$duplicado", "unido a #$principal", "Se movieron $movidas clases");

            // 5. Fuera el duplicado.
            $this->db->prepare("DELETE FROM $tabla WHERE $columna = ?")->execute([$duplicado]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw new Exception('No se fusionó nada. ' . $e->getMessage());
        }

        // La nómina del mes queda al día con el resultado de la fusión.
        (new NominaModel())->sincronizarMes();

        return [
            'id_conservado'     => $principal,
            'clases_movidas'    => $movidas,
            'clases_repetidas'  => $repetidas,
            'sin_horario'       => $sinHorario,
        ];
    }

    /**
     * Junta dos inscripciones que son la misma clase.
     *
     * Se conserva la que tiene horario asignado (la que aparece en la rejilla);
     * si ninguna o ambas lo tienen, la más antigua. De la otra se mueven, en
     * este orden: renglones de nómina, pagos, faltas y suplencias. Si las dos
     * tienen renglón del mismo mes, se queda uno solo: el pagado si lo hay, si
     * no el de mayor monto. Solo al final, cuando ya no le cuelga nada, la
     * inscripción sobrante se borra.
     *
     * Debe llamarse dentro de una transacción.
     */
    public function consolidar(int $idA, int $idB): int
    {
        $info = $this->db->prepare(
            "SELECT i.id_inscripcion, i.costo_mensual, i.id_instrumento, i.fecha_alta,
                    (SELECT COUNT(*) FROM horario_ocupado h WHERE h.id_inscripcion = i.id_inscripcion) AS con_horario
               FROM inscripciones i WHERE i.id_inscripcion IN (?, ?)"
        );
        $info->execute([$idA, $idB]);
        $filas = [];
        foreach ($info->fetchAll() as $f) { $filas[(int) $f['id_inscripcion']] = $f; }
        if (count($filas) < 2) return $idA;

        [$queda, $sale] = [$idA, $idB];
        $a = $filas[$idA]; $b = $filas[$idB];
        if ((int) $b['con_horario'] > (int) $a['con_horario']
            || ((int) $b['con_horario'] === (int) $a['con_horario'] && $b['fecha_alta'] < $a['fecha_alta'])) {
            [$queda, $sale] = [$idB, $idA];
        }
        $q = $filas[$queda]; $x = $filas[$sale];

        // Nómina: mes por mes, sin chocar con la clave (inscripción, periodo).
        $mios = $this->db->prepare("SELECT id_detalle, periodo, monto, pagado FROM nomina_detalle WHERE id_inscripcion = ?");
        $mios->execute([$queda]);
        $porMes = [];
        foreach ($mios->fetchAll() as $n) { $porMes[$n['periodo']] = $n; }

        $suyos = $this->db->prepare("SELECT id_detalle, periodo, monto, pagado FROM nomina_detalle WHERE id_inscripcion = ?");
        $suyos->execute([$sale]);
        foreach ($suyos->fetchAll() as $n) {
            $ya = $porMes[$n['periodo']] ?? null;
            if (!$ya) {
                $this->db->prepare("UPDATE nomina_detalle SET id_inscripcion = ? WHERE id_detalle = ?")
                         ->execute([$queda, $n['id_detalle']]);
                continue;
            }
            // Los dos tienen ese mes: gana el pagado; si ninguno, el de mayor monto.
            $ganaSuyo = ((int) $n['pagado'] && !(int) $ya['pagado'])
                     || ((int) $n['pagado'] === (int) $ya['pagado'] && (float) $n['monto'] > (float) $ya['monto']);
            // Sobra el renglón que pierde. Lo ya pagado nunca se quita: si los dos meses ya se
            // pagaron, no se puede decidir solo cuál sobra y se pide revisar antes de unirlas.
            $pierde = $ganaSuyo ? $ya : $n;
            if (!NominaModel::borrarRenglones($this->db, "nd.id_detalle = ?", [$pierde['id_detalle']],
                    'Se unió con otro renglón de la misma clase (tenía un ajuste manual)')) {
                throw new Exception('La nómina de ' . $n['periodo'] . ' ya está pagada en las dos clases repetidas: '
                    . 'revísalo antes de unirlas.');
            }
            if ($ganaSuyo) {
                $this->db->prepare("UPDATE nomina_detalle SET id_inscripcion = ? WHERE id_detalle = ?")
                         ->execute([$queda, $n['id_detalle']]);
            }
        }

        $this->db->prepare("UPDATE pagos SET id_inscripcion = ? WHERE id_inscripcion = ?")->execute([$queda, $sale]);
        $this->db->prepare("UPDATE IGNORE nomina_ajustes SET id_inscripcion = ? WHERE id_inscripcion = ?")
                 ->execute([$queda, $sale]);

        // Cargos congelados: son la misma clase, así que un mes no puede quedar
        // cobrado dos veces. Si los dos tienen ese mes, se conserva el mayor,
        // con el mismo criterio que la nómina de arriba.
        $cargosQueda = [];
        $c = $this->db->prepare("SELECT id_cargo, periodo, monto FROM cargos_mensuales WHERE id_inscripcion = ?");
        $c->execute([$queda]);
        foreach ($c->fetchAll() as $f) $cargosQueda[$f['periodo']] = $f;
        $c->execute([$sale]);
        foreach ($c->fetchAll() as $f) {
            $ya = $cargosQueda[$f['periodo']] ?? null;
            if (!$ya) {
                $this->db->prepare("UPDATE cargos_mensuales SET id_inscripcion = ? WHERE id_cargo = ?")
                         ->execute([$queda, $f['id_cargo']]);
            } elseif ((float) $f['monto'] > (float) $ya['monto']) {
                $this->db->prepare("DELETE FROM cargos_mensuales WHERE id_cargo = ?")->execute([$ya['id_cargo']]);
                $this->db->prepare("UPDATE cargos_mensuales SET id_inscripcion = ? WHERE id_cargo = ?")
                         ->execute([$queda, $f['id_cargo']]);
            } else {
                $this->db->prepare("DELETE FROM cargos_mensuales WHERE id_cargo = ?")->execute([$f['id_cargo']]);
            }
        }

        // Historial de precio y duración con fecha: si la que se queda no tiene, hereda el de la que sale (sin él, el mes
        // partido de esa clase se cobraría con el precio de hoy para todo el mes). Si las dos tienen, se conserva el de la
        // que se queda y queda constancia en la bitácora de que el otro se descartó.
        try {
            $cuenta = $this->db->prepare("SELECT COUNT(*) FROM precios_clase WHERE id_inscripcion = ?");
            $cuenta->execute([$queda]); $filasQueda = (int) $cuenta->fetchColumn();
            $cuenta->execute([$sale]);  $filasSale = (int) $cuenta->fetchColumn();
            if ($filasSale > 0 && $filasQueda === 0) {
                $this->db->prepare("UPDATE precios_clase SET id_inscripcion = ? WHERE id_inscripcion = ?")->execute([$queda, $sale]);
                HistorialClase::olvidar();
            } elseif ($filasSale > 0) {
                Bitacora::registrar($this->db, 'fusionar clases', 'inscripciones', $queda, 'historial de precio',
                    "#$sale: $filasSale " . ($filasSale === 1 ? 'cambio' : 'cambios') . ' con fecha', "#$queda: $filasQueda",
                    'Las dos clases tenían historial de precio y duración: se conservó el de la que se queda y se descartó el de la repetida');
                HistorialClase::olvidar();
            }
        } catch (PDOException $e) {
            // base todavía sin migrar: no hay historial que mover
        }

        // La que se queda hereda lo que le falte: precio y materia.
        if ((float) $q['costo_mensual'] <= 0 && (float) $x['costo_mensual'] > 0) {
            $this->db->prepare("UPDATE inscripciones SET costo_mensual = ? WHERE id_inscripcion = ?")
                     ->execute([$x['costo_mensual'], $queda]);
        }
        if (!$q['id_instrumento'] && $x['id_instrumento']) {
            $this->db->prepare("UPDATE inscripciones SET id_instrumento = ? WHERE id_inscripcion = ?")
                     ->execute([$x['id_instrumento'], $queda]);
            // Si la materia heredada es del taller, su clase en el horario también.
            $this->db->prepare("UPDATE horario_ocupado ho
                                   JOIN instrumentos i ON i.id_instrumento = ?
                                    SET ho.en_taller = i.es_grupal
                                  WHERE ho.id_inscripcion = ?")->execute([$x['id_instrumento'], $queda]);
        }

        // Quien compartía o iba en grupo con la que sale no se queda huérfano:
        // pasa a depender de la que se queda (o, si la que sale era titular en
        // otro hueco, la integrante más antigua hereda ese horario).
        $mismoHueco = $this->db->prepare(
            "SELECT COUNT(*) FROM horario_ocupado a JOIN horario_ocupado b
               ON a.id_salon <=> b.id_salon AND a.id_maestro = b.id_maestro AND a.dia_semana = b.dia_semana
              AND a.hora_inicio = b.hora_inicio AND a.hora_fin = b.hora_fin
             WHERE a.id_inscripcion = ? AND b.id_inscripcion = ?"
        );
        $mismoHueco->execute([$queda, $sale]);
        if ((int) $mismoHueco->fetchColumn() > 0) {
            $this->db->prepare("UPDATE inscripciones SET comparte_con = ? WHERE comparte_con = ? AND id_inscripcion <> ?")
                     ->execute([$queda, $sale, $queda]);
        } else {
            $hijas = $this->db->prepare(
                "SELECT id_inscripcion FROM inscripciones WHERE comparte_con = ? AND estado <> 'baja' ORDER BY id_inscripcion"
            );
            $hijas->execute([$sale]);
            $ids = array_map('intval', $hijas->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $nueva = array_shift($ids);
                $this->db->prepare("UPDATE horario_ocupado SET id_inscripcion = ? WHERE id_inscripcion = ?")->execute([$nueva, $sale]);
                $this->db->prepare("UPDATE inscripciones SET comparte_con = NULL WHERE id_inscripcion = ?")->execute([$nueva]);
                $this->db->prepare("UPDATE inscripciones SET comparte_con = ? WHERE comparte_con = ? AND id_inscripcion <> ?")
                         ->execute([$nueva, $sale, $nueva]);
            }
        }

        // Su historia pasa a la que se queda: la bitácora de cambios y lo que
        // apuntaba a ella desde la lista de espera o un borrador.
        foreach (["UPDATE inscripciones_cambios SET id_inscripcion = ? WHERE id_inscripcion = ?",
                  "UPDATE lista_espera SET id_inscripcion_resultante = ? WHERE id_inscripcion_resultante = ?",
                  "UPDATE borradores_alumnos SET id_inscripcion_resultante = ? WHERE id_inscripcion_resultante = ?"] as $sql) {
            $this->db->prepare($sql)->execute([$queda, $sale]);
        }

        // Ya no le cuelga nada: fuera.
        $this->db->prepare("DELETE FROM horario_ocupado WHERE id_inscripcion = ?")->execute([$sale]);
        $this->db->prepare("DELETE FROM inscripciones WHERE id_inscripcion = ?")->execute([$sale]);
        return $queda;
    }

    /**
     * Inscripciones vivas repetidas dentro del MISMO alumno: la misma pareja
     * alumno-maestro dos veces. Es lo que queda cuando una clase entró una vez
     * por el Excel de nómina y otra por el de horarios.
     */
    public function inscripcionesRepetidas(): array
    {
        return $this->db->query(
            "SELECT a.id_inscripcion AS id_a, b.id_inscripcion AS id_b,
                    al.nombre AS alumno_nombre, al.apellido AS alumno_apellido,
                    m.nombre AS maestro_nombre, m.apellido AS maestro_apellido,
                    a.estado AS estado_a, b.estado AS estado_b,
                    COALESCE(a.dia_semana, b.dia_semana) AS dia_semana,
                    COALESCE(a.hora_inicio, b.hora_inicio) AS hora_inicio,
                    (SELECT COUNT(*) FROM nomina_detalle WHERE id_inscripcion IN (a.id_inscripcion, b.id_inscripcion)) AS renglones_nomina
               FROM inscripciones a
               JOIN inscripciones b
                 ON b.id_alumno = a.id_alumno AND b.id_maestro = a.id_maestro
                AND b.id_inscripcion > a.id_inscripcion
               JOIN alumnos al ON al.id_alumno = a.id_alumno
               JOIN maestros m ON m.id_maestro = a.id_maestro
              WHERE a.estado <> 'baja' AND b.estado <> 'baja'
                AND a.comparte_con IS NULL AND b.comparte_con IS NULL
                AND (a.dia_semana IS NULL OR b.dia_semana IS NULL
                     OR (a.dia_semana = b.dia_semana AND a.hora_inicio = b.hora_inicio))
              ORDER BY al.apellido, al.nombre"
        )->fetchAll();
    }

    /** Une un par de inscripciones repetidas, en su propia transacción */
    public function unirInscripciones(int $idA, int $idB): array
    {
        return HorarioModel::conCandado(fn() => $this->unirConCandado($idA, $idB));
    }

    private function unirConCandado(int $idA, int $idB): array
    {
        $this->db->beginTransaction();
        try {
            $queda = $this->consolidar($idA, $idB);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw new Exception('No se unió nada. ' . $e->getMessage());
        }
        return ['id_conservado' => $queda];
    }

    /** Copia al principal los campos que tiene vacíos y el duplicado sí trae */
    /**
     * Las facturas del duplicado pasan al principal. Si los dos tienen la del
     * mismo mes, queda una: hecha si cualquiera de las dos lo estaba, con el
     * folio que exista.
     */
    private function moverFacturas(int $principal, int $duplicado): void
    {
        $s = $this->db->prepare("SELECT * FROM facturas WHERE id_alumno = ?");
        $s->execute([$duplicado]);
        foreach ($s->fetchAll() as $f) {
            $ya = $this->db->prepare("SELECT * FROM facturas WHERE id_alumno = ? AND periodo = ?");
            $ya->execute([$principal, $f['periodo']]);
            $suya = $ya->fetch();
            if ($suya) {
                $this->db->prepare("UPDATE facturas SET hecha = GREATEST(hecha, ?), folio = COALESCE(folio, ?) WHERE id_factura = ?")
                         ->execute([(int) $f['hecha'], $f['folio'], $suya['id_factura']]);
                $this->db->prepare("DELETE FROM facturas WHERE id_factura = ?")->execute([$f['id_factura']]);
            } else {
                $this->db->prepare("UPDATE facturas SET id_alumno = ? WHERE id_factura = ?")->execute([$principal, $f['id_factura']]);
            }
        }
    }

    private function completarVacios(string $tabla, string $columna, int $principal, int $duplicado): void
    {
        $campos = $tabla === 'maestros'
            ? ['apellido', 'telefono', 'email']
            : ['apellido', 'telefono', 'email', 'fecha_nacimiento',
               'tutor_nombre', 'tutor_telefono', 'tutor_parentesco', 'direccion', 'notas',
               // Datos de factura: el RFC o la razón social del duplicado no se pierden.
               'rfc', 'razon_social', 'correo_factura', 'datos_fiscales'];

        $stmt = $this->db->prepare("SELECT * FROM $tabla WHERE $columna IN (?, ?)");
        $stmt->execute([$principal, $duplicado]);
        $filas = [];
        foreach ($stmt->fetchAll() as $f) { $filas[(int) $f[$columna]] = $f; }

        $p = $filas[$principal] ?? [];
        $d = $filas[$duplicado] ?? [];
        $set = []; $valores = [];

        foreach ($campos as $campo) {
            if (!array_key_exists($campo, $p)) continue;
            $vacio = $p[$campo] === null || trim((string) $p[$campo]) === '';
            $tiene = isset($d[$campo]) && trim((string) $d[$campo]) !== '';
            if ($vacio && $tiene) { $set[] = "$campo = ?"; $valores[] = $d[$campo]; }
        }

        // Si cualquiera de los dos pedía factura, el que queda la pide.
        if ($tabla === 'alumnos' && array_key_exists('requiere_factura', $p)
            && (int) $p['requiere_factura'] === 0 && (int) ($d['requiere_factura'] ?? 0) === 1) {
            $set[] = "requiere_factura = ?"; $valores[] = 1;
        }

        if ($tabla === 'maestros') {
            // Si al principal no le pusieron tarifa y al duplicado sí, se queda la que exista.
            foreach (['tarifa_por_alumno', 'tarifa_por_hora'] as $campo) {
                if ((float) ($p[$campo] ?? 0) == 0 && (float) ($d[$campo] ?? 0) > 0) {
                    $set[] = "$campo = ?"; $valores[] = $d[$campo];
                }
            }
        }

        if (!$set) return;
        $valores[] = $principal;
        $this->db->prepare("UPDATE $tabla SET " . implode(', ', $set) . " WHERE $columna = ?")
                 ->execute($valores);
    }
}
