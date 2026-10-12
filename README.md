# Sistema administrativo para escuelas de música

Sistema web para administrar una escuela de música y arte: alumnos, clases,
horarios, cobranza y nómina de maestros. Una escuela real lo usa a diario
en recepción y administración; sustituyó a varios archivos de Excel sueltos
(horarios, pagos y nómina) que había que cuadrar a mano cada mes.

> **Copia de demostración.** Este repositorio es una versión pública con
> datos ficticios. No contiene información de la escuela ni de sus alumnos
> o maestros.

## Funciones

- **Alumnos e inscripciones**: ficha del alumno con datos del tutor, familias
  con descuento, lista de espera y altas que se pueden dejar en borrador.
- **Horarios sin empalmes**: detección de traslapes por salón y por maestro
  (incluidos los parciales, como 10:00–11:00 contra 10:30–11:30), clases en
  línea, clases compartidas y talleres en grupo, con un candado de
  concurrencia para que dos personas no ocupen el mismo hueco a la vez.
- **Cobranza y adeudos mes por mes**: cobro partido por tramos cuando una clase
  cambia de precio o de día a mitad de mes, recargos, abonos repartidos en
  varios meses y protección contra cobros duplicados.
- **Anulación de pagos con bitácora**: un pago nunca se borra ni se
  sobrescribe; se anula con un contramovimiento y queda registro de quién,
  cuándo y por qué.
- **Nómina histórica por periodo**: pago por alumno o por hora, faltas,
  suplencias, bonos de puntualidad y meses a medias; recibos imprimibles.
- **Importación de Excel** (horarios, pagos y nómina) con un **lector de
  .xlsx propio**, que no depende de la extensión zip de PHP, y una vista
  previa antes de guardar.
- **Reportes** de ingresos, adeudos y ocupación.
- **Roles de usuario**: administración, recepción y consulta.
- **Seguridad**: tokens CSRF, contraseñas con hash, límite de intentos de
  acceso, cierre de sesión por inactividad y lista opcional de equipos
  permitidos.
- **Respaldos verificables**: cada respaldo se restaura en una base temporal y
  se compara contra la original antes de darlo por bueno.

## Tecnología

- PHP 8, sin frameworks
- MySQL / MariaDB
- JavaScript sin frameworks (un solo archivo con un router por hash)
- Apache (XAMPP)

## Arquitectura

```
backend/
  api/        Endpoints JSON: uno por módulo (alumnos, pagos, nómina...)
  models/     Acceso a datos y reglas de cada entidad
  services/   Operaciones que cruzan varias tablas: inscripciones,
              importadores de Excel, fusión de duplicados, respaldos
  lib/        Utilidades sin estado: lector .xlsx, validación, bitácora
  config/     Conexión, sesión y autenticación, respuestas y errores
  herramientas/  Scripts de terminal: crear administrador, respaldos
frontend/     index.html, css/ y js/app.js
sql/          schema.sql y datos_demo.sql
```

## Instalación local con XAMPP

1. Copia el proyecto dentro de `htdocs`, por ejemplo en
   `C:\xampp\htdocs\escuela-musica`.
2. Enciende Apache y MySQL desde el panel de XAMPP.
3. En phpMyAdmin, importa `sql/schema.sql`: crea la base `escuela_demo` con
   sus tablas y catálogos.
4. Importa `sql/datos_demo.sql` sobre `escuela_demo` para tener maestros,
   alumnos, horarios y pagos de ejemplo.
5. Crea el primer administrador desde la terminal:

   ```
   cd C:\xampp\htdocs\escuela-musica\backend\herramientas
   C:\xampp\php\php.exe crear_admin.php
   ```

6. Abre `http://localhost/escuela-musica/frontend/` y entra con ese usuario.

Con un XAMPP recién instalado no hace falta configurar nada más. Si tu MySQL
usa otro usuario o contraseña, copia `backend/config/credenciales.ejemplo.php`
como `credenciales.php` y ajústalo.

## Sobre el desarrollo

Desarrollado con asistencia de IA (Claude): definí los requisitos y reglas del
negocio, revisé y probé cada cambio, y lo mantengo en producción.

---

© 2026 Diego Rodríguez. Código publicado como portafolio; todos los derechos reservados.
