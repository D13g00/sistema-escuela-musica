<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/Bitacora.php';

/**
 * Facturas: qué alumnos piden factura y si ya se les hizo la de cada mes.
 *
 * El sistema no timbra facturas: solo lleva la cuenta de a quién hay que
 * hacérsela y lo recuerda antes de que se acabe el mes. Quien la hace en el
 * portal del SAT (o donde sea) la marca aquí como hecha, con su folio.
 */
class FacturaModel
{
    private PDO $db;

    public function __construct() { $this->db = Database::conexion(); }

    private static function periodoValido(?string $p): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $p) ? $p : date('Y-m');
    }

    /**
     * Los alumnos activos que requieren factura, con lo que pagaron ese mes y
     * si ya se les hizo. Los que faltan van primero.
     */
    public function delMes(?string $periodo = null): array
    {
        $periodo = self::periodoValido($periodo);
        $s = $this->db->prepare(
            "SELECT a.id_alumno, a.nombre, a.apellido, a.rfc, a.razon_social, a.correo_factura, a.datos_fiscales,
                    a.tutor_nombre, f.nombre AS familia,
                    COALESCE((SELECT SUM(p.monto + p.recargo) FROM pagos p
                               WHERE p.id_alumno = a.id_alumno AND p.periodo = ?), 0) AS pagado_mes,
                    fa.hecha, fa.folio, fa.usuario, fa.fecha
               FROM alumnos a
               LEFT JOIN familias f ON f.id_familia = a.id_familia
               LEFT JOIN facturas fa ON fa.id_alumno = a.id_alumno AND fa.periodo = ?
              WHERE a.activo = 1 AND a.requiere_factura = 1
              ORDER BY COALESCE(fa.hecha, 0), a.apellido, a.nombre"
        );
        $s->execute([$periodo, $periodo]);
        $filas = $s->fetchAll();
        foreach ($filas as &$f) $f['hecha'] = (int) ($f['hecha'] ?? 0) === 1;
        unset($f);
        return $filas;
    }

    /** Resumen para el recordatorio: cuántas faltan y cuántos días le quedan al mes */
    public function recordatorio(?string $periodo = null): array
    {
        $periodo = self::periodoValido($periodo);
        $lista = $this->delMes($periodo);
        $faltan = array_values(array_filter($lista, fn($f) => !$f['hecha']));
        $ultimo = date('Y-m-t', strtotime($periodo . '-01'));
        $dias = (int) floor((strtotime($ultimo) - strtotime(date('Y-m-d'))) / 86400);
        return [
            'periodo'      => $periodo,
            'requieren'    => count($lista),
            'faltan'       => count($faltan),
            'dias_restantes' => max(0, $dias),
            'nombres'      => array_map(fn($f) => trim($f['nombre'] . ' ' . $f['apellido']), array_slice($faltan, 0, 5)),
        ];
    }

    /** Marca (o desmarca) la factura de un alumno en un mes */
    public function marcar(int $idAlumno, ?string $periodo, bool $hecha, ?string $folio, ?string $usuario): array
    {
        $periodo = self::periodoValido($periodo);
        $e = $this->db->prepare("SELECT requiere_factura FROM alumnos WHERE id_alumno = ?");
        $e->execute([$idAlumno]);
        if ($e->fetchColumn() === false) throw new Exception('Ese alumno no existe.');
        $folio = mb_substr(trim((string) $folio), 0, 40) ?: null;
        $previa = $this->db->prepare("SELECT hecha FROM facturas WHERE id_alumno = ? AND periodo = ?");
        $previa->execute([$idAlumno, $periodo]);
        $antes = $previa->fetchColumn();
        Bitacora::registrar($this->db, $hecha ? 'marcar factura hecha' : 'desmarcar factura', 'alumnos', $idAlumno,
            "factura $periodo", $antes === false ? 'pendiente' : ((int) $antes ? 'hecha' : 'pendiente'),
            $hecha ? 'hecha' . ($folio ? " (folio $folio)" : '') : 'pendiente', null, null, $usuario);
        $this->db->prepare(
            "INSERT INTO facturas (id_alumno, periodo, hecha, folio, usuario) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE hecha = VALUES(hecha), folio = COALESCE(VALUES(folio), folio), usuario = VALUES(usuario)"
        )->execute([$idAlumno, $periodo, $hecha ? 1 : 0, $folio, $usuario]);
        return ['id_alumno' => $idAlumno, 'periodo' => $periodo, 'hecha' => $hecha];
    }

    /** Las facturas de un alumno, de la más reciente a la más vieja */
    public function deAlumno(int $idAlumno): array
    {
        $s = $this->db->prepare("SELECT periodo, hecha, folio, usuario, fecha FROM facturas WHERE id_alumno = ? ORDER BY periodo DESC");
        $s->execute([$idAlumno]);
        return $s->fetchAll();
    }
}
