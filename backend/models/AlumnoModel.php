<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/HistorialClaseModel.php';

class AlumnoModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::conexion();
    }

    /**
     * Lista de alumnos con sus clases vivas resumidas: qué toma, con quién y
     * cuándo. Se arma con un GROUP_CONCAT para no hacer una consulta por alumno.
     */
    /** '' -> NULL, para que una fecha vacía no reviente y los campos queden limpios */
    private static function limpio($valor)
    {
        if ($valor === null) return null;
        $valor = trim((string) $valor);
        return $valor === '' ? null : $valor;
    }

    public function listar(bool $soloActivos = false): array
    {
        $sql = "SELECT a.*,
                       TIMESTAMPDIFF(YEAR, a.fecha_nacimiento, CURDATE()) AS edad,
                       (SELECT COALESCE(SUM(costo_mensual), 0) FROM inscripciones
                         WHERE id_alumno = a.id_alumno AND estado <> 'baja') AS colegiatura,
                       (SELECT COUNT(*) FROM inscripciones
                         WHERE id_alumno = a.id_alumno AND estado <> 'baja') AS num_clases,
                       (SELECT GROUP_CONCAT(
                                 CONCAT_WS('|',
                                   COALESCE(i.nombre, 'Por asignar'),
                                   CONCAT(m.nombre, ' ', m.apellido),
                                   COALESCE(ins.dia_semana, ''),
                                   COALESCE(LEFT(ins.hora_inicio, 5), ''),
                                   COALESCE(LEFT(ins.hora_fin, 5), ''),
                                   COALESCE(s.nombre, ''),
                                   ins.estado,
                                   ins.id_inscripcion)
                                 ORDER BY ins.dia_semana, ins.hora_inicio SEPARATOR ';;')
                          FROM inscripciones ins
                          JOIN maestros m ON m.id_maestro = ins.id_maestro
                          LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
                          LEFT JOIN salones s ON s.id_salon = ins.id_salon
                         WHERE ins.id_alumno = a.id_alumno AND ins.estado <> 'baja') AS clases_crudo
                  FROM alumnos a";
        if ($soloActivos) $sql .= " WHERE a.activo = 1";
        $sql .= " ORDER BY a.apellido, a.nombre";

        $filas = $this->db->query($sql)->fetchAll();

        // La colegiatura de la lista es la de este mes: si una clase tiene un cambio de precio programado para después,
        // costo_mensual ya trae el precio nuevo y todavía no toca.
        $ajuste = [];
        foreach (HistorialClase::diferenciasDelMes(date('Y-m'), $this->db) as $d) {
            $ajuste[$d['id_alumno']] = ($ajuste[$d['id_alumno']] ?? 0.0) + $d['diferencia'];
        }

        foreach ($filas as &$f) {
            if (isset($ajuste[(int) $f['id_alumno']])) $f['colegiatura'] = round((float) $f['colegiatura'] + $ajuste[(int) $f['id_alumno']], 2);
            $f['clases'] = [];
            foreach (explode(';;', (string) $f['clases_crudo']) as $trozo) {
                if ($trozo === '') continue;
                $c = explode('|', $trozo);
                $f['clases'][] = [
                    'materia'        => $c[0] ?? '',
                    'maestro'        => $c[1] ?? '',
                    'dia'            => $c[2] ?? '',
                    'hora_inicio'    => $c[3] ?? '',
                    'hora_fin'       => $c[4] ?? '',
                    'salon'          => $c[5] ?? '',
                    'estado'         => $c[6] ?? '',
                    'id_inscripcion' => (int) ($c[7] ?? 0),
                ];
            }
            unset($f['clases_crudo']);
        }
        unset($f);

        return $filas;
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM alumnos WHERE id_alumno = ?");
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        return $fila ?: null;
    }

    public function crear(array $datos): int
    {
        $this->validarFacturacion($datos);
        $stmt = $this->db->prepare(
            "INSERT INTO alumnos
                (nombre, apellido, telefono, email, fecha_nacimiento,
                 tutor_nombre, tutor_telefono, tutor_parentesco, direccion, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $datos['nombre'], $datos['apellido'],
            self::limpio($datos['telefono'] ?? null), self::limpio($datos['email'] ?? null),
            self::limpio($datos['fecha_nacimiento'] ?? null),
            self::limpio($datos['tutor_nombre'] ?? null), self::limpio($datos['tutor_telefono'] ?? null),
            self::limpio($datos['tutor_parentesco'] ?? null), self::limpio($datos['direccion'] ?? null),
            self::limpio($datos['notas'] ?? null)
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->guardarFacturacion($id, $datos);
        return $id;
    }

    /**
     * Si requiere factura y con qué datos. Solo se toca si viene en los datos:
     * quien no manda nada de facturación no borra lo que ya estaba.
     */
    /** RFC y correo de facturación bien formados, o un mensaje claro */
    private function validarFacturacion(array $d): void
    {
        if (!array_key_exists('requiere_factura', $d)) return;
        $rfc = strtoupper(preg_replace('/\s+/', '', (string) ($d['rfc'] ?? '')));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $rfc)) {
            throw new Exception('El RFC no parece válido: son 12 o 13 letras y números (por ejemplo XAXX010101000).');
        }
        $correo = trim((string) ($d['correo_factura'] ?? ''));
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new Exception('El correo para la factura no es válido.');
    }

    private function guardarFacturacion(int $id, array $d): void
    {
        if (!array_key_exists('requiere_factura', $d)) return;
        $requiere = !empty($d['requiere_factura']) && $d['requiere_factura'] !== '0' ? 1 : 0;
        $rfc = strtoupper(preg_replace('/\s+/', '', (string) ($d['rfc'] ?? '')));
        $correo = trim((string) ($d['correo_factura'] ?? ''));
        $this->db->prepare(
            "UPDATE alumnos SET requiere_factura = ?, rfc = ?, razon_social = ?, correo_factura = ?, datos_fiscales = ?
              WHERE id_alumno = ?"
        )->execute([$requiere, $rfc ?: null, self::limpio(mb_substr((string) ($d['razon_social'] ?? ''), 0, 200)),
                    $correo ?: null, self::limpio(mb_substr((string) ($d['datos_fiscales'] ?? ''), 0, 300)), $id]);
    }

    public function actualizar(int $id, array $datos): bool
    {
        $this->validarFacturacion($datos);   // antes de tocar nada: o se guarda todo o nada
        $stmt = $this->db->prepare(
            "UPDATE alumnos SET nombre = ?, apellido = ?, telefono = ?, email = ?,
                    fecha_nacimiento = ?, tutor_nombre = ?, tutor_telefono = ?,
                    tutor_parentesco = ?, direccion = ?, notas = ?
              WHERE id_alumno = ?"
        );
        $ok = $stmt->execute([
            $datos['nombre'], $datos['apellido'],
            self::limpio($datos['telefono'] ?? null), self::limpio($datos['email'] ?? null),
            self::limpio($datos['fecha_nacimiento'] ?? null),
            self::limpio($datos['tutor_nombre'] ?? null), self::limpio($datos['tutor_telefono'] ?? null),
            self::limpio($datos['tutor_parentesco'] ?? null), self::limpio($datos['direccion'] ?? null),
            self::limpio($datos['notas'] ?? null), $id
        ]);
        $this->guardarFacturacion($id, $datos);
        return $ok;
    }

    /** Baja general del alumno (distinto de la baja de una inscripción puntual) */
    public function desactivar(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE alumnos SET activo = 0 WHERE id_alumno = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Ficha completa de un alumno: sus datos, sus clases con horario y
     * maestro, y sus últimos pagos. Es lo que se ve al hacer clic en su fila.
     */
    public function ficha(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM alumnos WHERE id_alumno = ?");
        $stmt->execute([$id]);
        $alumno = $stmt->fetch();
        if (!$alumno) throw new Exception('Ese alumno no existe.');

        $clases = $this->db->prepare(
            "SELECT ins.id_inscripcion, ins.dia_semana, ins.hora_inicio, ins.hora_fin,
                    ins.duracion_min, ins.costo_mensual, ins.estado, ins.fecha_alta, ins.fecha_baja,
                    ins.id_instrumento, ins.id_salon, ins.id_maestro, ins.modalidad, ins.comparte_con,
                    COALESCE(i.nombre, 'Por asignar') AS instrumento,
                    COALESCE(s.nombre, IF(ins.modalidad = 'online', 'En línea', '')) AS salon,
                    m.nombre AS maestro_nombre, m.apellido AS maestro_apellido
               FROM inscripciones ins
               JOIN maestros m ON m.id_maestro = ins.id_maestro
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
               LEFT JOIN salones s ON s.id_salon = ins.id_salon
              WHERE ins.id_alumno = ?
              ORDER BY ins.estado = 'baja', FIELD(ins.dia_semana,
                       'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ins.hora_inicio"
        );
        $clases->execute([$id]);

        $pagos = $this->db->prepare(
            "SELECT * FROM pagos WHERE id_alumno = ? ORDER BY fecha_pago DESC, id_pago DESC LIMIT 12"
        );
        $pagos->execute([$id]);

        $todas = $clases->fetchAll();
        $vivas = array_values(array_filter($todas, fn($c) => $c['estado'] !== 'baja'));

        // Su familia, o los hermanos probables si todavía no tiene.
        require_once __DIR__ . '/FamiliaModel.php';
        $fm = new FamiliaModel();
        $familia = !empty($alumno['id_familia']) ? $fm->obtener((int) $alumno['id_familia']) : null;
        $sugerida = null;
        if (!$familia) {
            foreach ($fm->sugerencias() as $s) {
                if (in_array($id, array_column($s['alumnos'], 'id_alumno'), true)) { $sugerida = $s; break; }
            }
        }

        return [
            'alumno'      => $alumno,
            'clases'      => $todas,
            // Lo que paga hoy: el precio que rige este mes en cada clase (un cambio programado para después no cuenta todavía).
            'colegiatura' => round(array_sum(array_map(fn($c) => HistorialClase::precioDelMes($c, date('Y-m')), $vivas)), 2),
            'pagos'       => $pagos->fetchAll(),
            'familia'     => $familia,
            'descuento_familiar' => $familia ? $fm->descuentoDe($id) : null,
            'familia_sugerida'   => $sugerida,
            'facturas'    => (function () use ($id) {
                require_once __DIR__ . '/FacturaModel.php';
                return (new FacturaModel())->deAlumno($id);
            })(),
        ];
    }

    public function reactivar(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE alumnos SET activo = 1 WHERE id_alumno = ?");
        return $stmt->execute([$id]);
    }
}
