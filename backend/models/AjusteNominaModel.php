<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ConfiguracionModel.php';
require_once __DIR__ . '/../lib/Bitacora.php';

/**
 * Faltas y suplencias de maestros.
 *
 * Cuando un maestro no viene, se le descuentan las clases de ese día. Si otro
 * las cubre, al suplente se le suman. Cada movimiento queda registrado con su
 * fecha, la clase afectada y quién lo capturó: nunca se "corrige" la nómina a
 * mano sin dejar rastro.
 *
 * También viven aquí el bono de puntualidad y asistencia (se pone solo cada
 * mes y se pierde si ese mes hay una falta) y las clases muestra que imparte
 * un maestro, que se le pagan como una clase suya.
 *
 * Cuánto vale una clase suelta:
 *   · maestro por hora   -> su tarifa por hora × la duración de esa clase
 *   · maestro por alumno -> su tarifa mensual por ese alumno ÷ clases al mes
 * Es el mismo criterio con el que se arma su nómina, así que descontar una
 * sesión quita exactamente lo que esa sesión aportaba. El monto se puede
 * ajustar antes de guardar si en algún caso se trata distinto.
 */
class AjusteNominaModel
{
    private PDO $db;
    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado'];

    public function __construct() { $this->db = Database::conexion(); }

    /** Valor de una sola sesión de clase para un maestro */
    public function valorDeSesion(array $maestro, int $duracionMin, ?string $periodo = null): float
    {
        // El mismo cálculo que arma la nómina (media hora cuenta como hora), con las clases al mes de ese mes.
        require_once __DIR__ . '/NominaModel.php';
        $config = $periodo ? ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo) : null;
        return NominaModel::valorDeSesion($maestro, $duracionMin, $config);
    }

    /** Las clases que ese maestro tenía un día concreto */
    public function clasesDelDia(int $idMaestro, string $fecha): array
    {
        $this->validarFecha($fecha);
        $dia = self::DIAS[(int) date('N', strtotime($fecha))] ?? null;
        if (!$dia) {
            return ['dia' => 'Domingo', 'clases' => [], 'sin_horario' => [],
                    'maestro' => $this->maestro($idMaestro)];
        }

        $maestro = $this->maestro($idMaestro);

        $stmt = $this->db->prepare(
            "SELECT ins.id_inscripcion, ins.dia_semana, ins.hora_inicio, ins.hora_fin, ins.duracion_min,
                    a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    COALESCE(i.nombre, 'Por asignar') AS instrumento,
                    CASE WHEN ins.modalidad = 'online' THEN 'En línea' ELSE s.nombre END AS salon,
                    (SELECT id_ajuste FROM nomina_ajustes
                      WHERE tipo = 'falta' AND id_inscripcion = ins.id_inscripcion AND fecha = ?) AS ya_descontada
               FROM inscripciones ins
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
               LEFT JOIN salones s ON s.id_salon = ins.id_salon
              WHERE ins.id_maestro = ? AND ins.estado IN ('activa', 'pendiente')
                AND (ins.dia_semana = ? OR ins.dia_semana IS NULL)
                -- Una clase capturada a mano no existía antes de su alta. Las
                -- importadas sí: su fecha de alta es el día de la importación,
                -- no el día en que empezaron, así que ese filtro no aplica.
                AND (ins.origen = 'importacion' OR ins.fecha_alta <= ?)
              ORDER BY ins.dia_semana IS NULL, ins.hora_inicio"
        );
        $stmt->execute([$fecha, $idMaestro, $dia, $fecha]);

        // Las clases importadas todavía sin día ni hora sí se imparten: el
        // maestro las da y las cobra. Van aparte, sin marcar, porque el sistema
        // no puede saber si a esa clase le tocaba ese día.
        $clases = []; $sinHorario = [];
        foreach ($stmt->fetchAll() as $c) {
            $c['valor'] = $this->valorDeSesion($maestro, (int) $c['duracion_min'], substr($fecha, 0, 7));
            if ($c['dia_semana'] === null) $sinHorario[] = $c; else $clases[] = $c;
        }

        return ['dia' => $dia, 'maestro' => $maestro, 'clases' => $clases, 'sin_horario' => $sinHorario];
    }

    /**
     * Registra una falta y, si hay suplente, su suplencia. Todo o nada.
     *
     * @param array $clases lista de {id_inscripcion, monto?} a descontar
     */
    public function registrarFalta(int $idMaestro, string $fecha, array $clases,
                                   ?int $idSuplente, ?string $motivo, ?string $usuario): array
    {
        $this->validarFecha($fecha);
        if (!$clases) throw new Exception('Elige al menos una clase de ese día.');
        if ($idSuplente === $idMaestro) throw new Exception('El suplente no puede ser el mismo maestro.');

        $periodo = substr($fecha, 0, 7);
        $this->comprobarNoPagado($idMaestro, $periodo);
        if ($idSuplente) $this->comprobarNoPagado($idSuplente, $periodo);

        $titular  = $this->maestro($idMaestro);
        $suplente = $idSuplente ? $this->maestro($idSuplente) : null;
        $delDia   = $this->clasesDelDia($idMaestro, $fecha);
        $validas  = array_column(array_merge($delDia['clases'], $delDia['sin_horario']), null, 'id_inscripcion');

        $descontado = 0.0; $sumado = 0.0; $n = 0;
        $motivo = $motivo !== null ? mb_substr(trim($motivo), 0, 200) : null;

        $this->db->beginTransaction();
        try {
            foreach ($clases as $c) {
                $id = (int) ($c['id_inscripcion'] ?? 0);
                // Solo se descuentan clases que ese maestro realmente tenía ese día.
                if (!isset($validas[$id])) {
                    throw new Exception('Una de las clases no corresponde a ese maestro ese día.');
                }
                if ($validas[$id]['ya_descontada']) {
                    throw new Exception('La clase de ' . $validas[$id]['alumno_nombre']
                        . ' ya estaba descontada ese día. No se descuenta dos veces.');
                }

                $valor = isset($c['monto']) && $c['monto'] !== ''
                    ? abs((float) $c['monto'])
                    : (float) $validas[$id]['valor'];
                if ($valor > 100000) throw new Exception('Un monto de descuento es desproporcionado.');
                $minutos = (int) $validas[$id]['duracion_min'];

                $this->db->prepare(
                    "INSERT INTO nomina_ajustes
                        (id_maestro, periodo, fecha, tipo, id_inscripcion, minutos, monto, motivo, registrado_por)
                     VALUES (?, ?, ?, 'falta', ?, ?, ?, ?, ?)"
                )->execute([$idMaestro, $periodo, $fecha, $id, $minutos, -$valor, $motivo, $usuario]);
                $idFalta = (int) $this->db->lastInsertId();
                $descontado += $valor;

                if ($suplente) {
                    $pagoSuplente = isset($c['monto_suplente']) && $c['monto_suplente'] !== ''
                        ? abs((float) $c['monto_suplente'])
                        : $this->valorDeSesion($suplente, $minutos, $periodo);

                    $this->db->prepare(
                        "INSERT INTO nomina_ajustes
                            (id_maestro, periodo, fecha, tipo, id_inscripcion, minutos, monto, motivo,
                             cubre_falta, registrado_por)
                         VALUES (?, ?, ?, 'suplencia', ?, ?, ?, ?, ?, ?)"
                    )->execute([$idSuplente, $periodo, $fecha, $id, $minutos, $pagoSuplente,
                                'Cubre a ' . trim($titular['nombre'] . ' ' . $titular['apellido']),
                                $idFalta, $usuario]);
                    $sumado += $pagoSuplente;
                }
                $n++;
            }
            // El bono de puntualidad y asistencia se pierde con la falta.
            $bono = $this->sincronizarBono($idMaestro, $periodo);
            if ($idSuplente) $this->sincronizarBono($idSuplente, $periodo);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['clases' => $n, 'descontado' => round($descontado, 2),
                'sumado_suplente' => round($sumado, 2), 'bono_perdido' => $bono['perdido'] ?? 0.0];
    }

    /** Ajustes de un maestro en un mes, para la nómina y el recibo */
    public function deMaestro(int $idMaestro, string $periodo): array
    {
        $stmt = $this->db->prepare(
            "SELECT aj.*, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido
               FROM nomina_ajustes aj
               LEFT JOIN inscripciones ins ON ins.id_inscripcion = aj.id_inscripcion
               LEFT JOIN alumnos a ON a.id_alumno = ins.id_alumno
              WHERE aj.id_maestro = ? AND aj.periodo = ?
              ORDER BY aj.fecha, aj.id_ajuste"
        );
        $stmt->execute([$idMaestro, $periodo]);
        return $stmt->fetchAll();
    }

    /** Suma de ajustes por maestro en un mes */
    public function totalesDelMes(string $periodo): array
    {
        $stmt = $this->db->prepare(
            "SELECT id_maestro, SUM(monto) AS total, COUNT(*) AS movimientos
               FROM nomina_ajustes WHERE periodo = ? GROUP BY id_maestro"
        );
        $stmt->execute([$periodo]);
        $r = [];
        foreach ($stmt->fetchAll() as $f) { $r[(int) $f['id_maestro']] = $f; }
        return $r;
    }

    /** Anula una falta (y la suplencia que la cubría) o un ajuste suelto */
    public function eliminar(int $idAjuste): void
    {
        $stmt = $this->db->prepare("SELECT id_maestro, periodo, tipo, cubre_falta, fecha, monto FROM nomina_ajustes WHERE id_ajuste = ?");
        $stmt->execute([$idAjuste]);
        $a = $stmt->fetch();
        if (!$a) throw new Exception('Ese movimiento ya no existe.');

        $this->comprobarNoPagado((int) $a['id_maestro'], $a['periodo']);

        if ($a['tipo'] === 'bono') {
            throw new Exception('El bono de puntualidad se pone solo: se pierde cuando el mes tiene una '
                . 'falta registrada. Si ese maestro ya no lleva bono, déjalo en cero en su ficha.');
        }

        // Lo que se anula queda en la bitácora: desaparece de la nómina, no de la historia.
        Bitacora::registrar($this->db, 'anular ' . $a['tipo'], 'maestros', (int) $a['id_maestro'],
            $a['tipo'] . ' ' . $a['fecha'], $a['monto'], null);
        $sup = $this->db->prepare("SELECT id_maestro, fecha, monto FROM nomina_ajustes WHERE cubre_falta = ?");
        $sup->execute([$idAjuste]);
        foreach ($sup->fetchAll() as $s) {
            Bitacora::registrar($this->db, 'anular suplencia', 'maestros', (int) $s['id_maestro'],
                'suplencia ' . $s['fecha'], $s['monto'], null, 'Se anuló la falta que cubría');
        }

        // Borrar una suplencia sola deja la falta; borrar la falta arrastra su
        // suplencia (ON DELETE CASCADE). Así nunca queda un suplente cobrando
        // una clase que al final sí se dio.
        $this->db->prepare("DELETE FROM nomina_ajustes WHERE id_ajuste = ?")->execute([$idAjuste]);

        // Sin faltas en el mes, el bono vuelve.
        if ($a['tipo'] === 'falta') $this->sincronizarBono((int) $a['id_maestro'], $a['periodo']);
    }

    /**
     * Pone al día el bono de un maestro en un mes.
     *
     * Lo lleva quien tenga bono configurado en su ficha y no tenga ninguna
     * falta registrada ese mes. Se puede llamar las veces que sea. Un mes ya
     * pagado no se toca: ese dinero ya se entregó.
     *
     * @return array {monto, perdido} lo que quedó y lo que se le quitó
     */
    public function sincronizarBono(int $idMaestro, string $periodo): array
    {
        $m = $this->db->prepare("SELECT bono_puntualidad FROM maestros WHERE id_maestro = ? AND activo = 1");
        $m->execute([$idMaestro]);
        $bono = round((float) ($m->fetchColumn() ?: 0), 2);

        $actual = $this->db->prepare(
            "SELECT id_ajuste, monto, pagado FROM nomina_ajustes
              WHERE id_maestro = ? AND periodo = ? AND tipo = 'bono'"
        );
        $actual->execute([$idMaestro, $periodo]);
        $fila = $actual->fetch();
        if ($fila && (int) $fila['pagado'] === 1) return ['monto' => (float) $fila['monto'], 'perdido' => 0.0];

        $faltas = $this->db->prepare(
            "SELECT COUNT(*) FROM nomina_ajustes WHERE id_maestro = ? AND periodo = ? AND tipo = 'falta'"
        );
        $faltas->execute([$idMaestro, $periodo]);
        $leToca = $bono > 0 && (int) $faltas->fetchColumn() === 0;

        if (!$leToca) {
            if ($fila) {
                $this->db->prepare("DELETE FROM nomina_ajustes WHERE id_ajuste = ?")->execute([$fila['id_ajuste']]);
                return ['monto' => 0.0, 'perdido' => round((float) $fila['monto'], 2)];
            }
            return ['monto' => 0.0, 'perdido' => 0.0];
        }

        if ($fila) {
            $this->db->prepare("UPDATE nomina_ajustes SET monto = ? WHERE id_ajuste = ?")
                     ->execute([$bono, $fila['id_ajuste']]);
        } else {
            // El bono es del mes entero: se fecha el día primero.
            $this->db->prepare(
                "INSERT INTO nomina_ajustes (id_maestro, periodo, fecha, tipo, minutos, monto, motivo)
                 VALUES (?, ?, ?, 'bono', 0, ?, 'Puntualidad y asistencia')"
            )->execute([$idMaestro, $periodo, $periodo . '-01', $bono]);
        }
        return ['monto' => $bono, 'perdido' => 0.0];
    }

    /** Pone al día el bono de todos los maestros activos en un mes */
    public function sincronizarBonos(string $periodo): int
    {
        $n = 0;
        foreach ($this->db->query("SELECT id_maestro FROM maestros WHERE activo = 1 AND bono_puntualidad > 0")
                          ->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($this->sincronizarBono((int) $id, $periodo)['monto'] > 0) $n++;
        }
        // Un maestro al que le quitaron el bono de su ficha deja de llevarlo.
        foreach ($this->db->query(
            "SELECT aj.id_maestro FROM nomina_ajustes aj JOIN maestros m ON m.id_maestro = aj.id_maestro
              WHERE aj.tipo = 'bono' AND aj.pagado = 0 AND aj.periodo = " . $this->db->quote($periodo) . "
                AND (m.bono_puntualidad = 0 OR m.activo = 0)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->sincronizarBono((int) $id, $periodo);
        }
        return $n;
    }

    /**
     * Una clase muestra que impartió un maestro: se le paga como una clase
     * suya. Va dentro de la transacción de PagoModel.
     */
    public function registrarClaseMuestra(PDO $db, int $idMaestro, string $fecha, int $minutos,
                                          string $quien, ?float $monto = null): float
    {
        $m = $db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
        $m->execute([$idMaestro]);
        $maestro = $m->fetch();
        if (!$maestro) throw new Exception('Ese maestro no existe.');

        $valor = $monto !== null ? abs($monto) : $this->valorDeSesion($maestro, $minutos, substr($fecha, 0, 7));
        if ($valor > 100000) throw new Exception('El pago al maestro por la clase muestra es desproporcionado.');

        $db->prepare(
            "INSERT INTO nomina_ajustes (id_maestro, periodo, fecha, tipo, minutos, monto, motivo)
             VALUES (?, ?, ?, 'muestra', ?, ?, ?)"
        )->execute([$idMaestro, substr($fecha, 0, 7), $fecha, $minutos, $valor,
                    'Clase muestra de ' . mb_substr($quien, 0, 80)]);
        return round($valor, 2);
    }

    private const MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    /** "septiembre" (con el año si no es el de hoy): para los mensajes */
    public static function nombreDeMes(string $periodo): string
    {
        [$a, $m] = array_map('intval', explode('-', $periodo));
        return (self::MESES[$m - 1] ?? $periodo) . ($a !== (int) date('Y') ? " $a" : '');
    }

    /**
     * ¿La nómina de ese maestro en ese mes ya está marcada como pagada? Es la misma regla que usa
     * la pantalla: tiene renglones o movimientos y TODOS están pagados.
     */
    public static function nominaPagada(PDO $db, int $idMaestro, string $periodo): bool
    {
        $r = $db->prepare("SELECT COUNT(*), COALESCE(SUM(pagado), 0) FROM nomina_detalle WHERE id_maestro = ? AND periodo = ?");
        $r->execute([$idMaestro, $periodo]);
        [$renglones, $pagadosR] = array_map('intval', $r->fetch(PDO::FETCH_NUM));
        $a = $db->prepare("SELECT COUNT(*), COALESCE(SUM(pagado), 0) FROM nomina_ajustes WHERE id_maestro = ? AND periodo = ?");
        $a->execute([$idMaestro, $periodo]);
        [$movs, $pagadosA] = array_map('intval', $a->fetch(PDO::FETCH_NUM));
        return ($renglones + $movs) > 0 && $pagadosR === $renglones && $pagadosA === $movs;
    }

    /** El mensaje con que se niega corregir una nómina ya pagada */
    public static function mensajePagada(PDO $db, int $idMaestro, string $periodo): string
    {
        $m = $db->prepare("SELECT nombre, apellido FROM maestros WHERE id_maestro = ?");
        $m->execute([$idMaestro]);
        $f = $m->fetch();
        $nombre = $f ? trim($f['nombre'] . ' ' . ($f['apellido'] ?? '')) : 'ese maestro';
        return 'La nómina de ' . self::nombreDeMes($periodo) . ' de ' . $nombre . ' ya está marcada como pagada. '
            . 'Regrésala a pendiente primero (pide el motivo) si de verdad hay que corregirla.';
    }

    /** Se niega si la nómina de ese maestro en ese mes ya está pagada */
    public static function exigirNoPagada(PDO $db, int $idMaestro, string $periodo): void
    {
        if (self::nominaPagada($db, $idMaestro, $periodo)) throw new Exception(self::mensajePagada($db, $idMaestro, $periodo));
    }

    /**
     * Una clase extra: el maestro dio una clase que no estaba en su horario (de cualquier mes, también
     * pasado). Se le suma como un ajuste 'otro' con importe positivo; en pantalla y en el recibo se
     * reconoce porque su motivo empieza con "Clase extra". NO toca lo que debe ningún alumno.
     *
     * @param string      $fecha    día en que se dio (de ahí sale el mes de la nómina)
     * @param int         $minutos  duración, de 15 a 240
     * @param int|null    $idAlumno a quién se la dio (opcional)
     * @param float|null  $monto    null = lo que vale una sesión de esa duración con la tarifa del maestro
     * @return array {id_ajuste, periodo, monto}
     */
    public function registrarClaseExtra(int $idMaestro, string $fecha, int $minutos, ?int $idAlumno, ?float $monto,
                                        string $motivo, ?string $usuario): array
    {
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$d || $d->format('Y-m-d') !== $fecha) throw new Exception('La fecha no es válida.');
        if ($minutos < 15 || $minutos > 240) throw new Exception('La clase debe durar entre 15 y 240 minutos.');
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 4) throw new Exception('Escribe brevemente el motivo: por qué se agrega esta clase.');
        $periodo = substr($fecha, 0, 7);
        $maestro = $this->maestro($idMaestro);
        if ((string) $maestro['lleva_nomina'] === '0') throw new Exception('Ese maestro no lleva nómina.');

        $valor = $monto !== null ? round($monto, 2) : $this->valorDeSesion($maestro, $minutos, $periodo);
        if (!is_finite($valor) || $valor <= 0) throw new Exception('El importe de la clase extra debe ser mayor a cero.');
        if ($valor > 100000) throw new Exception('Ese importe es desproporcionado. Revísalo.');

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            self::exigirNoPagada($this->db, $idMaestro, $periodo);

            $idIns = null; $quien = '';
            if ($idAlumno) {
                $a = $this->db->prepare("SELECT nombre, apellido FROM alumnos WHERE id_alumno = ?");
                $a->execute([$idAlumno]);
                $al = $a->fetch();
                if (!$al) throw new Exception('Ese alumno no existe.');
                $quien = trim($al['nombre'] . ' ' . ($al['apellido'] ?? ''));
                // Una clase de ese alumno con este maestro, si existe: así el recibo la muestra con su nombre.
                $i = $this->db->prepare(
                    "SELECT id_inscripcion FROM inscripciones WHERE id_alumno = ? AND id_maestro = ?
                      ORDER BY (estado = 'baja'), id_inscripcion DESC LIMIT 1");
                $i->execute([$idAlumno, $idMaestro]);
                $idIns = $i->fetchColumn() ?: null;
                // La misma clase no puede repetirse el mismo día con el mismo tipo (llave única): se guarda sin ligar.
                if ($idIns) {
                    $dup = $this->db->prepare("SELECT 1 FROM nomina_ajustes WHERE tipo = 'otro' AND id_inscripcion = ? AND fecha = ?");
                    $dup->execute([$idIns, $fecha]);
                    if ($dup->fetchColumn()) $idIns = null;
                }
            }
            $texto = 'Clase extra' . ($quien !== '' && !$idIns ? " ($quien)" : '') . ': ' . $motivo;
            $texto = mb_substr($texto, 0, 200);

            $this->db->prepare(
                "INSERT INTO nomina_ajustes (id_maestro, periodo, fecha, tipo, id_inscripcion, minutos, monto, motivo, registrado_por)
                 VALUES (?, ?, ?, 'otro', ?, ?, ?, ?, ?)"
            )->execute([$idMaestro, $periodo, $fecha, $idIns, $minutos, $valor, $texto, $usuario]);
            $id = (int) $this->db->lastInsertId();
            Bitacora::registrar($this->db, 'clase extra', 'nomina_ajustes', $id, "nómina $periodo", null,
                number_format($valor, 2, '.', ''), $texto, null, $usuario);
            if ($propia) $this->db->commit();
            return ['id_ajuste' => $id, 'periodo' => $periodo, 'monto' => $valor];
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------

    private function maestro(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) throw new Exception('Ese maestro no existe.');
        return $m;
    }

    private function validarFecha(string $fecha): void
    {
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$d || $d->format('Y-m-d') !== $fecha) throw new Exception('La fecha no es válida.');
        if ($fecha > date('Y-m-d', strtotime('+31 days'))) {
            throw new Exception('No se registran faltas con más de un mes de anticipación.');
        }
    }

    /** Un mes ya pagado no se toca: el dinero ya se entregó en el sobre */
    private function comprobarNoPagado(int $idMaestro, string $periodo): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS renglones, COALESCE(SUM(pagado), 0) AS pagados
               FROM nomina_detalle WHERE id_maestro = ? AND periodo = ?"
        );
        $stmt->execute([$idMaestro, $periodo]);
        $f = $stmt->fetch();
        if ((int) $f['renglones'] > 0 && (int) $f['pagados'] === (int) $f['renglones']) {
            throw new Exception('La nómina de ese maestro para ese mes ya está marcada como pagada. '
                . 'Desmárcala primero si de verdad hay que corregirla.');
        }
    }
}
