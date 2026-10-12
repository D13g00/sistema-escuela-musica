<?php
require_once __DIR__ . '/../config/Database.php';

class NotaModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::conexion();
    }

    /** Trae primero los pendientes (no resueltos), luego los ya resueltos, más reciente primero */
    public function listar(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM bitacora_notas
             ORDER BY resuelto ASC, fecha_creacion DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function crear(string $autor, string $mensaje): int
    {
        $stmt = $this->db->prepare("INSERT INTO bitacora_notas (autor, mensaje) VALUES (?, ?)");
        $stmt->execute([$autor, $mensaje]);
        return (int) $this->db->lastInsertId();
    }

    public function marcarResuelta(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE bitacora_notas SET resuelto = 1, fecha_resuelto = NOW() WHERE id_nota = ?"
        );
        return $stmt->execute([$id]);
    }

    public function eliminar(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM bitacora_notas WHERE id_nota = ?");
        return $stmt->execute([$id]);
    }
}
