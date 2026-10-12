<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ConfiguracionModel.php';
require_once __DIR__ . '/NominaModel.php';
require_once __DIR__ . '/HistorialClaseModel.php';
require_once __DIR__ . '/FamiliaModel.php';
require_once __DIR__ . '/../lib/Validar.php';
require_once __DIR__ . '/../lib/Bitacora.php';

class PagoModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::conexion();
    }

    public function listarPorAlumno(int $idAlumno): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pagos WHERE id_alumno = ? ORDER BY fecha_pago DESC");
        $stmt->execute([$idAlumno]);
        return $stmt->fetchAll();
    }

    /**
     * Pagos registrados, del más reciente al más antiguo, por páginas.
     *
     * Sin parámetros devuelve los últimos 200, como siempre. Para seguir
     * (botón "Ver más") se manda la fecha y el id del último pago recibido:
     * así no se repite ni se salta ninguno aunque entren pagos nuevos mientras
     * tanto. Con $texto se busca por nombre en TODOS los pagos, no solo en los
     * que ya se ven.
     *
     * @param int         $limite     cuántos devolver (1 a 200)
     * @param string|null $antesFecha fecha_pago del último pago ya recibido
     * @param int|null    $antesId    id_pago del último pago ya recibido
     * @param string      $texto      nombre (o parte) de quien pagó
     */
    public function listarTodos(int $limite = 200, ?string $antesFecha = null, ?int $antesId = null,
                                string $texto = ''): array
    {
        $limite = max(1, min(200, $limite));
        $quien = "COALESCE(CONCAT_WS(' ', a.nombre, a.apellido), p.nombre_prospecto)";
        $donde = [];
        $params = [];

        // Seguir después del último recibido: mismo orden que la lista (fecha e id, de más nuevo a más viejo).
        if ($antesFecha !== null && $antesFecha !== '' && $antesId !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $antesFecha)) {
                throw new Exception('La fecha para seguir la lista no es válida.');
            }
            $donde[] = "(p.fecha_pago < ? OR (p.fecha_pago = ? AND p.id_pago < ?))";
            array_push($params, $antesFecha, $antesFecha, $antesId);
        }

        // Cada palabra escrita debe aparecer en el nombre (en cualquier orden).
        // Como mucho cinco palabras: una búsqueda larguísima solo arma una consulta enorme.
        foreach (array_slice(preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $palabra) {
            $palabra = mb_substr($palabra, 0, 40);
            $donde[] = "$quien LIKE ? ESCAPE '|'";
            $params[] = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $palabra) . '%';
        }

        // LEFT JOIN: una clase muestra no tiene alumno inscrito todavía.
        $sql = "SELECT p.*, (p.monto + p.recargo) AS total,
                       a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                       $quien AS quien
                FROM pagos p LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno"
             . ($donde ? ' WHERE ' . implode(' AND ', $donde) : '')
             . " ORDER BY p.fecha_pago DESC, p.id_pago DESC LIMIT $limite";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Registro de un pago normal (independiente de una alta).
     *
     * El recargo lo decide el servidor con la fecha del servidor, no el
     * navegador: así nadie lo esquiva cambiando la hora de su computadora.
     * Solo se puede condonar a propósito, mandando condonar_recargo.
     */
    public function registrar(array $d, ?string $usuario = null): array
    {
        // El monto tiene que ser un número positivo y con un techo sano: un
        // pago de la escuela no es de millones, y un valor gigante casi siempre
        // es un error de captura o un intento de ensuciar los reportes.
        $monto = filter_var($d['monto'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($monto === false || $monto <= 0) {
            throw new Exception('El monto debe ser un número mayor que cero.');
        }
        if ($monto > 1000000) {
            throw new Exception('El monto es demasiado alto. Revísalo.');
        }
        $d['monto'] = $monto;

        // El alumno tiene que existir de verdad.
        $existe = $this->db->prepare("SELECT COUNT(*) FROM alumnos WHERE id_alumno = ?");
        $existe->execute([(int) ($d['id_alumno'] ?? 0)]);
        if ((int) $existe->fetchColumn() === 0) {
            throw new Exception('Ese alumno no existe.');
        }

        $periodo = $d['periodo'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            $periodo = date('Y-m');   // periodo inválido -> mes en curso, no basura
        }
        $d['periodo'] = $periodo;
        $calculo = (new ConfiguracionModel())->recargoDe($periodo);

        $concepto = in_array($d['concepto'] ?? '', ['colegiatura','inscripcion','material','otro'], true)
            ? $d['concepto'] : 'colegiatura';

        // La inscripción es una cuota única: no vence ni lleva recargo.
        $recargo = ($calculo['aplica'] && empty($d['condonar_recargo']) && $concepto !== 'inscripcion')
            ? $calculo['monto'] : 0.0;

        // Si el pago señala una clase, tiene que ser de ese mismo alumno.
        $idInscripcion = Validar::idOpcional($d['id_inscripcion'] ?? null);
        if ($idInscripcion !== null) {
            $suya = $this->db->prepare("SELECT COUNT(*) FROM inscripciones WHERE id_inscripcion = ? AND id_alumno = ?");
            $suya->execute([$idInscripcion, (int) $d['id_alumno']]);
            if ((int) $suya->fetchColumn() === 0) throw new Exception('Esa clase no es de este alumno.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO pagos (id_alumno, id_inscripcion, concepto, monto, recargo, metodo, periodo, registrado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $d['id_alumno'], $idInscripcion, $concepto, $d['monto'], $recargo,
            Validar::metodo($d['metodo'] ?? 'efectivo'), $periodo, $usuario
        ]);

        return [
            'id_pago'     => (int) $this->db->lastInsertId(),
            'monto'       => (float) $d['monto'],
            'recargo'     => $recargo,
            'total'       => (float) $d['monto'] + $recargo,
            'dias_tarde'  => $calculo['dias_tarde'],
            'vencia'      => $calculo['vence'],
        ];
    }

    /**
     * Cobro de una clase muestra. La toma alguien que todavía no es alumno,
     * así que el pago se guarda con su nombre y teléfono en vez de un id.
     * Nunca lleva recargo: no es una colegiatura que venza.
     */
    public function registrarClaseMuestra(array $d, ?string $usuario = null): array
    {
        $nombre = trim($d['nombre'] ?? '');
        if ($nombre === '') throw new Exception('Escribe el nombre de quien toma la clase muestra.');

        $minutos = (int) ($d['duracion_min'] ?? 60);
        if (!in_array($minutos, [30, 60, 90, 120], true)) {
            throw new Exception('La duración debe ser de 30, 60, 90 o 120 minutos.');
        }

        $config = new ConfiguracionModel();
        $monto = isset($d['monto']) && $d['monto'] !== ''
            ? Validar::dinero($d['monto'], 'El monto', 100000)
            : $config->costoClaseMuestra($minutos);
        $metodo = Validar::metodo($d['metodo'] ?? 'efectivo');

        // Quién la impartió: se le paga en su nómina como una clase suya.
        $idMaestro = !empty($d['id_maestro']) ? (int) $d['id_maestro'] : null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO pagos
                    (id_alumno, concepto, nombre_prospecto, telefono_prospecto, duracion_min, id_maestro,
                     monto, recargo, metodo, periodo, registrado_por)
                 VALUES (?, 'clase_muestra', ?, ?, ?, ?, ?, 0, ?, ?, ?)"
            );
            $stmt->execute([
                !empty($d['id_alumno']) ? (int) $d['id_alumno'] : null,
                $nombre,
                $d['telefono'] ?? null,
                $minutos,
                $idMaestro,
                $monto,
                $metodo,
                date('Y-m'),
                $usuario,
            ]);
            $idPago = (int) $this->db->lastInsertId();

            $alMaestro = 0.0;
            if ($idMaestro) {
                require_once __DIR__ . '/AjusteNominaModel.php';
                $alMaestro = (new AjusteNominaModel())->registrarClaseMuestra(
                    $this->db, $idMaestro, date('Y-m-d'), $minutos, $nombre,
                    isset($d['pago_maestro']) && $d['pago_maestro'] !== '' ? (float) $d['pago_maestro'] : null
                );
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'id_pago'    => $idPago,
            'nombre'     => $nombre,
            'minutos'    => $minutos,
            'monto'      => $monto,
            'al_maestro' => $alMaestro,
        ];
    }

    /** Clases muestra dadas, para darles seguimiento */
    public function clasesMuestra(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    TRIM(CONCAT(COALESCE(m.nombre,''), ' ', COALESCE(m.apellido,''))) AS maestro,
                    (SELECT COUNT(*) FROM inscripciones ins
                      JOIN alumnos a2 ON a2.id_alumno = ins.id_alumno
                     WHERE CONCAT(a2.nombre,' ',a2.apellido) = p.nombre_prospecto
                       AND ins.estado <> 'baja') AS se_inscribio
               FROM pagos p
               LEFT JOIN maestros m ON m.id_maestro = p.id_maestro
              -- Una muestra anulada nunca se cobró: no hay a quién darle seguimiento.
              WHERE p.concepto = 'clase_muestra' AND p.anula_a IS NULL AND p.anulado = 0
              ORDER BY p.fecha_pago DESC, p.id_pago DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Cuánto debería pagar un alumno por un periodo: la suma de sus clases
     * vivas más el recargo si ya pasó la fecha límite.
     */
    public function cotizar(int $idAlumno, string $periodo): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(costo_mensual), 0) FROM inscripciones
              WHERE id_alumno = ? AND estado <> 'baja'"
        );
        $stmt->execute([$idAlumno]);
        $colegiatura = (float) $stmt->fetchColumn();
        // El precio que rige en ese mes: si una clase tiene otro tramo programado para después, no cuenta todavía.
        foreach (HistorialClase::diferenciasDelMes($periodo, $this->db) as $d) {
            if ($d['id_alumno'] === $idAlumno) $colegiatura += $d['diferencia'];
        }

        $yaPago = $this->db->prepare(
            // La inscripción no es colegiatura: no cuenta como lo ya pagado del mes.
            "SELECT COALESCE(SUM(monto + recargo), 0) FROM pagos
              WHERE id_alumno = ? AND periodo = ? AND concepto <> 'inscripcion'"
        );
        $yaPago->execute([$idAlumno, $periodo]);

        $calculo = (new ConfiguracionModel())->recargoDe($periodo);

        return [
            'periodo'     => $periodo,
            'colegiatura' => $colegiatura,
            'recargo'     => $calculo['monto'],
            'aplica'      => $calculo['aplica'],
            'vence'       => $calculo['vence'],
            'dias_tarde'  => $calculo['dias_tarde'],
            'total'       => $colegiatura + ($calculo['aplica'] ? $calculo['monto'] : 0),
            'ya_pagado'   => (float) $yaPago->fetchColumn(),
            'hoy'         => date('Y-m-d'),
        ];
    }

    /** Usado dentro de la transaccion del InscripcionService (pago inicial al dar de alta) */
    public function insertarEnTransaccion(PDO $db, array $d): int
    {
        $stmt = $db->prepare(
            "INSERT INTO pagos (id_alumno, id_inscripcion, concepto, monto, recargo, metodo, periodo, registrado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $d['id_alumno'], $d['id_inscripcion'], $d['concepto'] ?? 'colegiatura',
            $d['monto'], $d['recargo'] ?? 0,
            $d['metodo'] ?? 'efectivo', $d['periodo'] ?? null, $d['registrado_por'] ?? null
        ]);
        return (int) $db->lastInsertId();
    }

    // =================================================================
    // CORRECCIÓN DE PAGOS
    // =================================================================

    private const METODOS = ['efectivo', 'transferencia', 'tarjeta'];

    /**
     * Corrige un pago: su importe, su método, o solo confirma el importe.
     *
     * Nada se sobreescribe en silencio: cada campo que cambia deja en
     * pagos_cambios su valor anterior, el nuevo, quién y por qué. Cambiar el
     * importe de un pago que ya estaba confirmado exige decir el motivo; poner
     * el importe real de uno importado no, porque es justo lo que faltaba.
     *
     * @param array $d {monto?, metodo?, confirmar?, motivo?}
     */
    public function corregir(int $idPago, array $d, ?string $usuario): array
    {
        $motivo = isset($d['motivo']) ? mb_substr(trim((string) $d['motivo']), 0, 200) : '';
        $motivo = $motivo === '' ? null : $motivo;

        $this->db->beginTransaction();
        try {
            // FOR UPDATE: dos personas corrigiendo el mismo pago no se pisan.
            $s = $this->db->prepare("SELECT * FROM pagos WHERE id_pago = ? FOR UPDATE");
            $s->execute([$idPago]);
            $p = $s->fetch();
            if (!$p) throw new Exception('Ese pago no existe.');
            if ((int) $p['anulado'] === 1) throw new Exception('Ese pago está anulado: ya no se corrige.');
            if ($p['anula_a'] !== null) throw new Exception('Esa es la anulación de otro pago: no se corrige.');

            $cambios = [];

            if (array_key_exists('monto', $d) && $d['monto'] !== '' && $d['monto'] !== null) {
                $monto = filter_var($d['monto'], FILTER_VALIDATE_FLOAT);
                if ($monto === false || $monto <= 0) throw new Exception('El importe debe ser mayor que cero.');
                if ($monto > 1000000) throw new Exception('El importe es demasiado alto. Revísalo.');
                $monto = round($monto, 2);

                if (abs($monto - (float) $p['monto']) > 0.009) {
                    if ((int) $p['monto_confirmado'] === 1 && !$motivo) {
                        throw new Exception('Ese importe ya estaba confirmado. Escribe por qué se corrige.');
                    }
                    $cambios['monto'] = [number_format((float) $p['monto'], 2, '.', ''), number_format($monto, 2, '.', '')];
                }
                // Dar el importe es confirmarlo.
                if ((int) $p['monto_confirmado'] === 0) $cambios['monto_confirmado'] = ['0', '1'];
            }

            if (!empty($d['confirmar']) && (int) $p['monto_confirmado'] === 0) {
                $cambios['monto_confirmado'] = ['0', '1'];
            }

            // Lo que pagó es lo que paga cada mes: se corrige también el precio de
            // su clase (solo si tiene una; con varias se corrige desde su ficha).
            if (!empty($d['actualizar_colegiatura']) && isset($monto) && $p['id_alumno'] && $p['concepto'] === 'colegiatura') {
                $this->corregirColegiatura((int) $p['id_alumno'], $monto, $idPago, $usuario);
            }

            if (!empty($d['metodo']) && $d['metodo'] !== $p['metodo']) {
                if (!in_array($d['metodo'], self::METODOS, true)) {
                    throw new Exception('El método tiene que ser efectivo, transferencia o tarjeta.');
                }
                $cambios['metodo'] = [$p['metodo'], $d['metodo']];
            }

            if (!$cambios) {
                $this->db->commit();
                return ['cambios' => 0, 'pago' => $p];
            }

            $sets = []; $vals = [];
            foreach ($cambios as $campo => [, $nuevo]) { $sets[] = "$campo = ?"; $vals[] = $nuevo; }
            $vals[] = $idPago;
            $this->db->prepare("UPDATE pagos SET " . implode(', ', $sets) . " WHERE id_pago = ?")->execute($vals);

            $log = $this->db->prepare(
                "INSERT INTO pagos_cambios (id_pago, campo, valor_anterior, valor_nuevo, motivo, usuario)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($cambios as $campo => [$antes, $despues]) {
                $log->execute([$idPago, $campo, $antes, $despues, $motivo, $usuario]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $s = $this->db->prepare("SELECT * FROM pagos WHERE id_pago = ?");
        $s->execute([$idPago]);
        return ['cambios' => count($cambios), 'pago' => $s->fetch()];
    }

    /**
     * Anula un pago capturado por error. No se borra nada: se crea un
     * contramovimiento con el importe en negativo, con fecha de hoy, que
     * apunta al original (la base no deja anular dos veces), y el original
     * queda marcado como anulado. Así el estado de cuenta se corrige y los
     * reportes de meses pasados no cambian: la anulación cuenta en el mes en
     * que se hace, como en contabilidad.
     */
    public function anular(int $idPago, string $motivo, ?string $usuario): array
    {
        $motivo = mb_substr(trim($motivo), 0, 200);
        if (mb_strlen($motivo) < 4) throw new Exception('Escribe por qué se anula el pago.');

        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare("SELECT * FROM pagos WHERE id_pago = ? FOR UPDATE");
            $s->execute([$idPago]);
            $p = $s->fetch();
            if (!$p) throw new Exception('Ese pago no existe.');
            if ((int) $p['anulado'] === 1) throw new Exception('Ese pago ya estaba anulado.');
            if ($p['anula_a'] !== null) throw new Exception('Esa es la anulación de otro pago: no se anula.');

            $folio = Bitacora::folio();
            $this->db->prepare(
                "INSERT INTO pagos (id_alumno, id_inscripcion, concepto, nombre_prospecto, telefono_prospecto,
                                    duracion_min, id_maestro, monto, fecha_pago, recargo, metodo, monto_confirmado,
                                    periodo, origen, operacion, anula_a, registrado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 1, ?, ?, ?, ?, ?)"
            )->execute([$p['id_alumno'], $p['id_inscripcion'], $p['concepto'], $p['nombre_prospecto'],
                        $p['telefono_prospecto'], $p['duracion_min'], $p['id_maestro'], -(float) $p['monto'],
                        -(float) $p['recargo'], $p['metodo'], $p['periodo'], $p['origen'], $folio, $idPago, $usuario]);
            $idContra = (int) $this->db->lastInsertId();
            $this->db->prepare("UPDATE pagos SET anulado = 1 WHERE id_pago = ?")->execute([$idPago]);

            $this->db->prepare(
                "INSERT INTO pagos_cambios (id_pago, campo, valor_anterior, valor_nuevo, motivo, usuario)
                 VALUES (?, 'anulado', '0', '1', ?, ?)"
            )->execute([$idPago, $motivo, $usuario]);
            Bitacora::registrar($this->db, 'anular pago', 'pagos', $idPago, 'anulado',
                number_format((float) $p['monto'] + (float) $p['recargo'], 2, '.', ''), "anulado por el pago #$idContra",
                $motivo, $folio, $usuario);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['id_pago' => $idPago, 'id_anulacion' => $idContra, 'folio' => $folio,
                'monto' => (float) $p['monto'], 'recargo' => (float) $p['recargo']];
    }

    /** Todo lo que se le ha corregido a un pago, del más viejo al más nuevo */
    /**
     * El precio de la única clase viva de un alumno pasa a ser lo que paga.
     * Queda en la bitácora de la clase. Va dentro de la transacción de corregir().
     */
    private function corregirColegiatura(int $idAlumno, float $monto, int $idPago, ?string $usuario): void
    {
        $s = $this->db->prepare("SELECT id_inscripcion, costo_mensual FROM inscripciones WHERE id_alumno = ? AND estado <> 'baja'");
        $s->execute([$idAlumno]);
        $vivas = $s->fetchAll();
        if (count($vivas) !== 1) {
            throw new Exception('Tiene más de una clase: corrige el precio de cada una desde su ficha.');
        }
        if ((new FamiliaModel())->descuentoDe($idAlumno)) {
            throw new Exception('Tiene descuento familiar: lo que pagó no es el precio de la clase. Corrígelo desde su ficha.');
        }
        $antes = (float) $vivas[0]['costo_mensual'];
        if (abs($antes - $monto) < 0.01) return;
        $this->db->prepare("UPDATE inscripciones SET costo_mensual = ? WHERE id_inscripcion = ?")
                 ->execute([$monto, $vivas[0]['id_inscripcion']]);
        // Una corrección de precio rige el mes en curso completo, tenga o no historial de cambios la clase.
        HistorialClase::corregirPrecioDelMes($this->db, (int) $vivas[0]['id_inscripcion'], date('Y-m'), $monto, $usuario);
        $this->db->prepare(
            "INSERT INTO inscripciones_cambios (id_inscripcion, campo, valor_anterior, valor_nuevo, motivo, usuario)
             VALUES (?, 'costo_mensual', ?, ?, ?, ?)"
        )->execute([$vivas[0]['id_inscripcion'], number_format($antes, 2, '.', ''), number_format($monto, 2, '.', ''),
                    "Corregido al confirmar el pago #$idPago: es lo que paga cada mes", $usuario]);
    }

    public function cambios(int $idPago): array
    {
        $s = $this->db->prepare("SELECT * FROM pagos_cambios WHERE id_pago = ? ORDER BY fecha, id_cambio");
        $s->execute([$idPago]);
        return $s->fetchAll();
    }

    /**
     * Pagos cuyo importe falta confirmar, con una pista de cuánto confiar en
     * el sugerido. La hoja de pagos solo decía el mes; el importe lo calculó el
     * sistema con los precios de ese día, que no siempre eran los correctos.
     *
     * Niveles, de lo que más urge revisar a lo que menos:
     *   revisar    el importe no coincide con la colegiatura de hoy
     *   dudoso     coincide con la colegiatura, pero no con la lista de precios
     *   sin_lista  coincide con la colegiatura; no hay materia para comparar
     *   coincide   coincide con la colegiatura y con la lista de precios
     *
     * El sugerido es lo que el alumno debía pagar ese mes: el cargo de ese mes
     * si ya se generó; si no, la colegiatura que tiene registrada. Nunca el
     * importe importado, que a veces era lo que se le paga al maestro.
     *
     * @param int|null $idPago solo ese pago (para confirmarlo desde otra pantalla)
     */
    public function porConfirmar(?int $idPago = null): array
    {
        $s = $this->db->prepare(
            "SELECT p.id_pago, p.id_alumno, p.periodo, p.monto, p.metodo, p.fecha_pago, p.origen,
                    a.nombre, a.apellido,
                    (SELECT SUM(c.monto) FROM cargos_mensuales c
                      WHERE c.id_alumno = p.id_alumno AND c.periodo = p.periodo) AS cargo_mes,
                    COALESCE(col.colegiatura, 0) AS colegiatura,
                    lista.precio AS precio_lista, COALESCE(lista.sin_materia, 0) AS sin_materia
               FROM pagos p
               JOIN alumnos a ON a.id_alumno = p.id_alumno
               LEFT JOIN (SELECT id_alumno, SUM(costo_mensual) AS colegiatura
                            FROM inscripciones WHERE estado <> 'baja' GROUP BY id_alumno) col
                      ON col.id_alumno = p.id_alumno
               LEFT JOIN (SELECT i.id_alumno,
                                 SUM(ROUND(COALESCE(m.costo_mensual, 0) * COALESCE(i.duracion_min, 60) / 60, 2)) AS precio,
                                 SUM(i.id_instrumento IS NULL) AS sin_materia
                            FROM inscripciones i LEFT JOIN instrumentos m ON m.id_instrumento = i.id_instrumento
                           WHERE i.estado <> 'baja' GROUP BY i.id_alumno) lista
                      ON lista.id_alumno = p.id_alumno
              WHERE p.monto_confirmado = 0 AND p.anulado = 0 AND p.anula_a IS NULL AND (? IS NULL OR p.id_pago = ?)
              ORDER BY a.apellido, a.nombre, p.periodo"
        );
        $s->execute([$idPago, $idPago]);
        $filas = $s->fetchAll();

        // Sus clases vivas, con lo que se le paga al maestro por cada una. El
        // Excel de nómina trajo a veces como precio de la clase justo eso: lo
        // que cobra el maestro, no lo que paga el alumno.
        $clases = [];
        $ids = array_values(array_unique(array_map(fn($f) => (int) $f['id_alumno'], $filas)));
        if ($ids) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $c = $this->db->prepare(
                "SELECT i.id_alumno, i.id_inscripcion, i.costo_mensual, i.duracion_min, i.id_instrumento,
                        m.modo_pago, m.tarifa_por_hora, m.tarifa_por_alumno
                   FROM inscripciones i JOIN maestros m ON m.id_maestro = i.id_maestro
                  WHERE i.estado <> 'baja' AND i.id_alumno IN ($marcas)"
            );
            $c->execute($ids);
            foreach ($c->fetchAll() as $x) $clases[(int) $x['id_alumno']][] = $x;
        }
        $config = (new ConfiguracionModel())->todo();
        // El descuento familiar del mes de cada pago, con el precio que rige en ese mes (una vez por mes).
        $fam = new FamiliaModel();
        $descuentosDe = [];
        $descuentosEn = function (string $p) use ($fam, &$descuentosDe) { return $descuentosDe[$p] ??= $fam->descuentosPorAlumno(null, $p); };

        $orden = ['precio' => -1, 'revisar' => 0, 'dudoso' => 1, 'sin_lista' => 2, 'coincide' => 3];
        foreach ($filas as &$f) {
            $monto = (float) $f['monto'];
            $col   = (float) $f['colegiatura'];
            $hayLista = (int) $f['sin_materia'] === 0 && $f['precio_lista'] !== null;
            $lista = $hayLista ? (float) $f['precio_lista'] : null;

            $conCol   = abs($monto - $col) <= 0.01;
            $conLista = $hayLista && abs($monto - $lista) <= 0.01;

            if (!$conCol) {
                $f['nivel'] = 'revisar';
                $f['motivo'] = 'El importe no coincide con su colegiatura de hoy ($' . number_format($col, 2) . ').';
            } elseif (!$hayLista) {
                $f['nivel'] = 'sin_lista';
                $f['motivo'] = 'Coincide con su colegiatura. Alguna de sus clases no tiene materia, así que no se puede comparar con la lista de precios.';
            } elseif (!$conLista) {
                $f['nivel'] = 'dudoso';
                $f['motivo'] = 'Coincide con su colegiatura, pero la lista de precios dice $' . number_format($lista, 2) . '.';
            } else {
                $f['nivel'] = 'coincide';
                $f['motivo'] = 'Coincide con su colegiatura y con la lista de precios.';
            }
            // Qué proponer: lo que el alumno debía pagar ese mes.
            if ($f['cargo_mes'] !== null && (float) $f['cargo_mes'] > 0) {
                $f['sugerido'] = round((float) $f['cargo_mes'], 2); $f['fuente'] = 'cargo';
            } elseif ($col > 0) {
                $f['sugerido'] = round($col, 2); $f['fuente'] = 'colegiatura';
            } elseif ($hayLista && $lista > 0) {
                $f['sugerido'] = round($lista, 2); $f['fuente'] = 'lista';
            } else {
                $f['sugerido'] = round($monto, 2); $f['fuente'] = 'importado';
            }
            $f['precio_lista'] = $lista;

            // Descuento familiar: lo que tenía que pagar ya viene rebajado.
            $d = $descuentosEn((string) $f['periodo'])[(int) $f['id_alumno']] ?? null;
            if ($d && $f['periodo'] >= $d['desde'] && in_array($f['fuente'], ['colegiatura', 'lista'], true)) {
                $f['sugerido'] = round(max(0, $f['sugerido'] - $d['monto']), 2);
            }

            // ¿Su precio es en realidad el pago del maestro? Entonces no se propone.
            $mias = $clases[(int) $f['id_alumno']] ?? [];
            $f['clases_vivas'] = count($mias);
            $f['precio_dudoso'] = false;
            foreach ($mias as $x) {
                $maestro = NominaModel::montoDeMaestro($x, (int) $x['duracion_min'] ?: 60, $config);
                if ($x['id_instrumento'] === null && $maestro > 0 && abs((float) $x['costo_mensual'] - $maestro) < 0.01) {
                    $f['precio_dudoso'] = true;
                    $f['precio_maestro'] = $maestro;
                }
            }
            if ($f['precio_dudoso'] && $f['fuente'] === 'colegiatura') {
                $f['nivel'] = 'precio';
                $f['motivo'] = 'Su colegiatura registrada ($' . number_format($col, 2) . ') es igual a lo que se le paga '
                             . 'al maestro por su clase: seguramente vino así del Excel de nómina.';
                $f['sugerido'] = null;
                $f['fuente'] = 'dudoso';
            }
        }
        unset($f);

        usort($filas, fn($a, $b) => [$orden[$a['nivel']], $a['apellido'], $a['nombre'], $a['periodo']]
                                  <=> [$orden[$b['nivel']], $b['apellido'], $b['nombre'], $b['periodo']]);
        return $filas;
    }
}
