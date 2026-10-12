<?php
/**
 * Bitácora financiera: todo cambio de dinero que no es un cobro normal deja
 * aquí quién, cuándo, qué registro, el valor de antes, el de después y por
 * qué. Pagos y clases siguen teniendo además su propia bitácora
 * (pagos_cambios, inscripciones_cambios); esta cubre lo demás: cargos del
 * mes, nómina marcada o ajustada, tarifas, configuración de cobros,
 * anulaciones, facturas, fusiones y precios recalculados.
 *
 * Va dentro de la transacción de quien llama: si la operación se deshace,
 * su renglón de bitácora también.
 */
final class Bitacora
{
    /**
     * @param string $operacion qué se hizo, en palabras: 'anular pago', 'desmarcar nómina'…
     * @param string $origen    pantalla | importacion | sistema | terminal
     */
    public static function registrar(PDO $db, string $operacion, string $tabla, ?int $idRegistro, string $campo,
                                     $antes, $despues, ?string $motivo = null, ?string $folio = null,
                                     ?string $usuario = null, string $origen = 'pantalla'): void
    {
        $usuario = $usuario ?? self::quien();
        $texto = fn($v) => $v === null ? null : mb_substr(is_bool($v) ? ($v ? '1' : '0') : (string) $v, 0, 255);
        $db->prepare(
            "INSERT INTO bitacora_financiera (operacion, tabla, id_registro, campo, valor_anterior, valor_nuevo,
                                              motivo, folio, usuario, origen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([mb_substr($operacion, 0, 60), $tabla, $idRegistro, mb_substr($campo, 0, 60), $texto($antes),
                    $texto($despues), $motivo === null || trim($motivo) === '' ? null : mb_substr(trim($motivo), 0, 255),
                    $folio, $usuario === null ? null : mb_substr($usuario, 0, 100), $origen]);
    }

    /** Un folio para agrupar los renglones de una misma operación */
    public static function folio(): string
    {
        return date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /** Quién hace el cambio: el usuario de la sesión, o la Terminal */
    private static function quien(): ?string
    {
        if (PHP_SAPI === 'cli') return 'Terminal';
        if (class_exists('Auth', false)) return Auth::usuarioActual()['nombre'] ?? null;
        return null;
    }
}
