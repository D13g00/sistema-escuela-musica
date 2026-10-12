<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/HistorialClaseModel.php';

/**
 * Familias: hermanos que paga una misma persona (padre, madre o tutor).
 *
 * Una familia sirve para dos cosas:
 *   · cobrarle de una vez al responsable, eligiendo qué clases de sus hijos
 *     está liquidando (ver CuentaModel::cobrarFamilia);
 *   · un descuento acordado para toda la familia, al mes.
 *
 * El descuento se reparte entre los alumnos de la familia en proporción a su
 * colegiatura: si la familia paga $3,600 por clases que suman $3,850, a cada
 * hijo se le rebaja su parte de los $250. Así el estado de cuenta de cada
 * alumno sigue siendo el suyo y la suma de la familia da lo acordado.
 *
 * Nadie queda en una familia sin que alguien lo confirme: el sistema solo
 * sugiere hermanos por el mismo tutor o teléfono.
 */
class FamiliaModel
{
    private PDO $db;

    public function __construct() { $this->db = Database::conexion(); }

    public function listar(): array
    {
        $familias = $this->db->query("SELECT * FROM familias ORDER BY nombre")->fetchAll();
        $miembros = $this->miembros();
        foreach ($familias as &$f) $f['miembros'] = $miembros[(int) $f['id_familia']] ?? [];
        unset($f);
        return $familias;
    }

    public function obtener(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM familias WHERE id_familia = ?");
        $s->execute([$id]);
        $f = $s->fetch();
        if (!$f) return null;
        $f['miembros'] = $this->miembros($id)[$id] ?? [];
        return $f;
    }

    /** Los alumnos de cada familia, con su colegiatura: [id_familia => [...]] */
    private function miembros(?int $idFamilia = null): array
    {
        $sql = "SELECT a.id_familia, a.id_alumno, a.nombre, a.apellido, a.activo, a.tutor_nombre, a.tutor_telefono,
                       a.requiere_factura, 0 AS colegiatura
                  FROM alumnos a WHERE a.id_familia IS NOT NULL";
        $par = [];
        if ($idFamilia) { $sql .= " AND a.id_familia = ?"; $par[] = $idFamilia; }
        $s = $this->db->prepare($sql . " ORDER BY a.nombre, a.apellido");
        $s->execute($par);
        $r = [];
        $colegiaturas = $this->colegiaturas(date('Y-m'));
        foreach ($s->fetchAll() as $m) {
            $m['colegiatura'] = $colegiaturas[(int) $m['id_alumno']] ?? 0.0;
            $r[(int) $m['id_familia']][] = $m;
        }
        return $r;
    }

    /**
     * Lo que paga cada alumno de una familia en un mes: la suma de sus clases vivas con el precio que
     * rige en ESE mes (HistorialClase), no la suma de costo_mensual. Con un cambio de precio programado
     * para el mes que entra, costo_mensual ya trae el precio nuevo y este mes todavía vale el de antes.
     *
     * @return array [id_alumno => float] (solo alumnos que son de una familia)
     */
    private function colegiaturas(string $periodo): array
    {
        // Un periodo que no es AAAA-MM (los hay en pagos importados) se calcula con el mes en curso.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) $periodo = date('Y-m');
        $r = [];
        $s = $this->db->query(
            "SELECT i.id_inscripcion, i.id_alumno, i.costo_mensual, i.duracion_min, i.dia_semana
               FROM inscripciones i JOIN alumnos a ON a.id_alumno = i.id_alumno
              WHERE a.id_familia IS NOT NULL AND i.estado <> 'baja'"
        );
        foreach ($s->fetchAll() as $i) {
            $id = (int) $i['id_alumno'];
            $r[$id] = ($r[$id] ?? 0.0) + HistorialClase::precioDelMes($i, $periodo);
        }
        return array_map(fn($x) => round($x, 2), $r);
    }

    /**
     * Crea o actualiza una familia y quiénes la forman.
     *
     * @param array $d {nombre, responsable?, telefono?, descuento_mensual?, descuento_desde?, notas?, miembros: [id_alumno]}
     */
    public function guardar(?int $id, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '') throw new Exception('Escribe el nombre de la familia.');
        $miembros = array_values(array_unique(array_filter(array_map('intval', (array) ($d['miembros'] ?? [])))));
        if (!$miembros) throw new Exception('Una familia necesita al menos un alumno.');

        $descuento = round((float) ($d['descuento_mensual'] ?? 0), 2);
        if ($descuento < 0) throw new Exception('El descuento no puede ser negativo.');
        $desde = trim((string) ($d['descuento_desde'] ?? ''));
        if ($descuento > 0) {
            if ($desde === '') $desde = date('Y-m');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $desde)) throw new Exception('El mes del descuento no es válido.');
            // Los meses ya cerrados no se reescriben: el descuento corre de este mes en adelante.
            if ($desde < date('Y-m')) throw new Exception('El descuento puede empezar este mes o después, no en uno ya cerrado.');
        } else {
            $desde = null;
        }

        $marcas = implode(',', array_fill(0, count($miembros), '?'));
        $e = $this->db->prepare("SELECT COUNT(*) FROM alumnos WHERE id_alumno IN ($marcas)");
        $e->execute($miembros);
        if ((int) $e->fetchColumn() !== count($miembros)) throw new Exception('Alguno de esos alumnos no existe.');
        // Un alumno que ya es de otra familia no se cambia en silencio: su descuento y sus cobros son de aquella.
        $otra = $this->db->prepare(
            "SELECT a.nombre, a.apellido, f.nombre AS familia FROM alumnos a JOIN familias f ON f.id_familia = a.id_familia
              WHERE a.id_alumno IN ($marcas) AND a.id_familia IS NOT NULL AND a.id_familia <> ?"
        );
        $otra->execute([...$miembros, $id ?? 0]);
        if ($x = $otra->fetch()) {
            throw new Exception(trim($x['nombre'] . ' ' . $x['apellido']) . ' ya es de la ' . $x['familia']
                . '. Quítalo primero de esa familia.');
        }

        $campos = [mb_substr($nombre, 0, 120),
                   mb_substr(trim((string) ($d['responsable'] ?? '')), 0, 120) ?: null,
                   mb_substr(trim((string) ($d['telefono'] ?? '')), 0, 20) ?: null,
                   $descuento, $desde,
                   mb_substr(trim((string) ($d['notas'] ?? '')), 0, 500) ?: null];

        $this->db->beginTransaction();
        try {
            if ($id) {
                if (!$this->obtener($id)) throw new Exception('Esa familia no existe.');
                $this->db->prepare(
                    "UPDATE familias SET nombre = ?, responsable = ?, telefono = ?, descuento_mensual = ?,
                            descuento_desde = ?, notas = ? WHERE id_familia = ?"
                )->execute([...$campos, $id]);
                // Quien ya no está en la lista deja la familia (no se borra nada más).
                $this->db->prepare("UPDATE alumnos SET id_familia = NULL WHERE id_familia = ? AND id_alumno NOT IN ($marcas)")
                         ->execute([$id, ...$miembros]);
            } else {
                $this->db->prepare(
                    "INSERT INTO familias (nombre, responsable, telefono, descuento_mensual, descuento_desde, notas)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute($campos);
                $id = (int) $this->db->lastInsertId();
            }
            $this->db->prepare("UPDATE alumnos SET id_familia = ? WHERE id_alumno IN ($marcas)")->execute([$id, ...$miembros]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Deshace la familia: sus alumnos quedan sueltos. Pagos y cuentas no cambian. */
    public function deshacer(int $id): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE alumnos SET id_familia = NULL WHERE id_familia = ?")->execute([$id]);
            $this->db->prepare("DELETE FROM familias WHERE id_familia = ?")->execute([$id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Hermanos probables: alumnos activos con el mismo tutor (nombre o
     * teléfono) que todavía no están en una familia. Si alguno de ellos ya
     * está en una, se sugiere sumar a los demás a esa familia.
     */
    public function sugerencias(): array
    {
        $alumnos = $this->db->query(
            "SELECT a.id_alumno, a.nombre, a.apellido, a.tutor_nombre, a.tutor_telefono, a.id_familia,
                    f.nombre AS familia
               FROM alumnos a LEFT JOIN familias f ON f.id_familia = a.id_familia
              WHERE a.activo = 1"
        )->fetchAll();

        // Unión de los que comparten tutor o teléfono del tutor.
        $padre = [];
        $raiz = function ($x) use (&$padre, &$raiz) { return $padre[$x] === $x ? $x : ($padre[$x] = $raiz($padre[$x])); };
        $porLlave = [];
        foreach ($alumnos as $a) {
            $id = (int) $a['id_alumno'];
            $padre[$id] = $id;
            $nombre = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $a['tutor_nombre'])));
            $tel = preg_replace('/\D/', '', (string) $a['tutor_telefono']);
            if (mb_strlen($nombre) >= 5) $porLlave['n:' . $nombre][] = $id;
            if (strlen($tel) >= 10) $porLlave['t:' . substr($tel, -10)][] = $id;
        }
        foreach ($porLlave as $ids) {
            for ($i = 1; $i < count($ids); $i++) {
                $a = $raiz($ids[0]); $b = $raiz($ids[$i]);
                if ($a !== $b) $padre[$b] = $a;
            }
        }
        $grupos = [];
        foreach ($alumnos as $a) $grupos[$raiz((int) $a['id_alumno'])][] = $a;

        $sugerencias = [];
        foreach ($grupos as $g) {
            if (count($g) < 2) continue;
            $sueltos = array_values(array_filter($g, fn($a) => $a['id_familia'] === null));
            if (!$sueltos) continue;                                   // ya están todos en su familia
            $familias = array_values(array_unique(array_filter(array_column($g, 'id_familia'))));
            if (count($familias) > 1) continue;                        // ya se separaron a propósito
            $tutor = trim((string) ($g[0]['tutor_nombre'] ?? '')) ?: null;
            foreach ($g as $a) if (!$tutor && trim((string) $a['tutor_nombre']) !== '') $tutor = trim($a['tutor_nombre']);
            $tel = null;
            foreach ($g as $a) if (!$tel && trim((string) $a['tutor_telefono']) !== '') $tel = trim($a['tutor_telefono']);
            $apellido = explode(' ', trim((string) $g[0]['apellido']))[0] ?? '';
            $conFamilia = array_values(array_filter($g, fn($a) => $a['id_familia'] !== null));
            $sugerencias[] = [
                'id_familia'  => $familias ? (int) $familias[0] : null,
                'familia'     => $conFamilia ? $conFamilia[0]['familia'] : null,
                'nombre'      => $familias ? null : trim('Familia ' . $apellido),
                'responsable' => $tutor,
                'telefono'    => $tel,
                'alumnos'     => array_map(fn($a) => ['id_alumno' => (int) $a['id_alumno'], 'nombre' => $a['nombre'],
                                                      'apellido' => $a['apellido'], 'en_familia' => $a['id_familia'] !== null], $g),
            ];
        }
        return $sugerencias;
    }

    /**
     * La parte del descuento familiar que le toca a cada alumno, al mes, y
     * desde cuándo. Se reparte en proporción a su colegiatura de ESE mes (con el
     * precio que rige en él, no el de un cambio programado para después); el
     * centavo que sobre del redondeo va al último, para que la familia sume
     * exactamente lo acordado.
     *
     * @param int[]|null $ids solo esos alumnos (null = todos)
     * @param string|null $periodo AAAA-MM del mes que se calcula (null = el mes en curso)
     * @return array [id_alumno => ['monto' => float, 'desde' => 'AAAA-MM', 'familia' => string]]
     */
    public function descuentosPorAlumno(?array $ids = null, ?string $periodo = null): array
    {
        $filas = $this->db->query(
            "SELECT f.id_familia, f.nombre, f.descuento_mensual, f.descuento_desde, a.id_alumno, 0 AS colegiatura
               FROM familias f JOIN alumnos a ON a.id_familia = f.id_familia
              WHERE f.descuento_mensual > 0 AND f.descuento_desde IS NOT NULL
              ORDER BY f.id_familia, a.id_alumno"
        )->fetchAll();
        if ($filas) {
            $colegiaturas = $this->colegiaturas($periodo ?? date('Y-m'));
            foreach ($filas as &$fila) $fila['colegiatura'] = $colegiaturas[(int) $fila['id_alumno']] ?? 0.0;
            unset($fila);
        }

        $porFamilia = [];
        foreach ($filas as $f) $porFamilia[(int) $f['id_familia']][] = $f;

        $r = [];
        foreach ($porFamilia as $miembros) {
            $total = array_sum(array_map(fn($m) => (float) $m['colegiatura'], $miembros));
            if ($total <= 0) continue;
            $descuento = min((float) $miembros[0]['descuento_mensual'], $total);
            $con = array_values(array_filter($miembros, fn($m) => (float) $m['colegiatura'] > 0));
            $repartido = 0.0;
            foreach ($con as $k => $m) {
                $parte = $k === count($con) - 1
                    ? round($descuento - $repartido, 2)
                    : round($descuento * (float) $m['colegiatura'] / $total, 2);
                $repartido = round($repartido + $parte, 2);
                $r[(int) $m['id_alumno']] = ['monto' => $parte, 'desde' => $m['descuento_desde'], 'familia' => $m['nombre']];
            }
        }
        if ($ids !== null) $r = array_intersect_key($r, array_flip(array_map('intval', $ids)));
        return $r;
    }

    /** La parte de un alumno en un mes (null = el mes en curso), o null si no tiene descuento familiar */
    public function descuentoDe(int $idAlumno, ?string $periodo = null): ?array
    {
        return $this->descuentosPorAlumno([$idAlumno], $periodo)[$idAlumno] ?? null;
    }
}
