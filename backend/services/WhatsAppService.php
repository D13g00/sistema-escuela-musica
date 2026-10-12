<?php
/**
 * WhatsAppService: ejemplo de integración con la API oficial de WhatsApp
 * (Meta Cloud API). Está DESACTIVADO por defecto (HABILITADO = false)
 * porque requiere credenciales reales que solo tú puedes generar:
 *
 *   1. Crea una cuenta de Meta for Developers: https://developers.facebook.com
 *   2. Crea una App de tipo "Business" y agrega el producto "WhatsApp"
 *   3. Ahí obtendrás: PHONE_NUMBER_ID y un ACCESS_TOKEN temporal (o permanente
 *      si generas un token de sistema)
 *   4. Verifica un número de teléfono dedicado para tu escuela
 *   5. Para enviar texto libre fuera de la ventana de 24h desde el último
 *      mensaje del usuario, necesitas crear y que Meta apruebe una
 *      "plantilla de mensaje" (Message Template)
 *
 * Alternativa más simple de iniciar (aunque de pago por mensaje): Twilio
 * WhatsApp API (https://www.twilio.com/docs/whatsapp).
 *
 * Una vez tengas tus credenciales, pon HABILITADO = true y rellena las
 * constantes de abajo. Este servicio ya está enganchado (de forma opcional
 * y silenciosa) en InscripcionService::darDeAlta().
 */
class WhatsAppService
{
    private const HABILITADO = false; // <-- cámbialo a true cuando tengas credenciales

    private const PHONE_NUMBER_ID = 'TU_PHONE_NUMBER_ID_AQUI';
    private const ACCESS_TOKEN    = 'TU_ACCESS_TOKEN_AQUI';
    private const API_VERSION     = 'v20.0';

    /**
     * Envía un mensaje de texto libre. Solo funciona si el destinatario te
     * escribió en las últimas 24 horas; de lo contrario Meta lo rechaza y
     * hay que usar una plantilla aprobada (sendTemplate).
     */
    public static function enviarTexto(string $telefono, string $mensaje): bool
    {
        if (!self::HABILITADO) return false;

        $url = "https://graph.facebook.com/" . self::API_VERSION . "/" . self::PHONE_NUMBER_ID . "/messages";

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => self::normalizarTelefono($telefono),
            'type' => 'text',
            'text' => ['body' => $mensaje],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::ACCESS_TOKEN,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $codigo >= 200 && $codigo < 300;
    }

    /**
     * Ejemplo de envío con plantilla aprobada (necesaria fuera de la ventana de 24h).
     * Debes crear la plantilla "confirmacion_alta" en el Administrador de Meta
     * con una variable {{1}} para el nombre del instrumento, por ejemplo.
     */
    public static function enviarPlantillaConfirmacionAlta(string $telefono, string $nombreAlumno, string $instrumento): bool
    {
        if (!self::HABILITADO) return false;

        $url = "https://graph.facebook.com/" . self::API_VERSION . "/" . self::PHONE_NUMBER_ID . "/messages";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => self::normalizarTelefono($telefono),
            'type' => 'template',
            'template' => [
                'name' => 'confirmacion_alta',
                'language' => ['code' => 'es_MX'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreAlumno],
                        ['type' => 'text', 'text' => $instrumento],
                    ]
                ]]
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::ACCESS_TOKEN,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $codigo >= 200 && $codigo < 300;
    }

    private static function normalizarTelefono(string $telefono): string
    {
        // WhatsApp espera formato internacional sin signos, ej: 5215512345678
        return preg_replace('/\D/', '', $telefono);
    }
}
