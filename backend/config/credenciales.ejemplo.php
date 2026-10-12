<?php
/**
 * Modelo de credenciales.php — datos de conexión de ESTA computadora.
 *
 * Cópialo como credenciales.php (en esta misma carpeta) y cambia solo lo que
 * sea distinto en tu instalación. Lo que no pongas toma el valor de un XAMPP
 * recién instalado. credenciales.php nunca viene en una actualización, así
 * que actualizar el sistema no lo pisa.
 */
return [
    'host'          => '127.0.0.1',
    'puerto'        => '3306',
    'base'          => 'escuela_demo',
    'usuario'       => 'root',     // de preferencia, un usuario propio del sistema (lo crea backend/herramientas/asegurar_base.php)
    'password'      => '',
    'modo_estricto' => true,       // false solo si un error del modo estricto impide trabajar
    'zona_horaria'  => 'America/Mexico_City',
    'sesion_minutos'=> 60,         // minutos sin actividad antes de cerrar la sesión

    // Equipos que pueden entrar al sistema (opcional). Lista de direcciones IP o rangos CIDR, IPv4 e IPv6. Si la
    // clave existe, cualquier otro equipo recibe "Este equipo no tiene acceso al sistema." (403) en la API y en las
    // herramientas de mantenimiento, y queda anotado en escuela_registros/accesos_denegados.log. Sin la clave,
    // entra cualquier equipo que llegue al servidor (como siempre). Ejemplo: el propio servidor
    // y los equipos de una red privada (por ejemplo, Tailscale).
    // 'redes_permitidas' => ['127.0.0.1', '::1', '100.64.0.0/10', 'fd7a:115c:a1e0::/48'],
];
