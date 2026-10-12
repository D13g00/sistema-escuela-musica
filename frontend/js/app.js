/* =====================================================================
   Sistema Escuela — interfaz (JavaScript sin frameworks)
   Frontend sin dependencias. Un solo archivo, un router por hash y un
   cliente de API que centraliza el manejo de la sesión.
   ===================================================================== */

const API = '../backend/api';

/**
 * Versión del frontend. Se muestra en la pantalla de acceso y en Ajustes.
 * Si después de reemplazar los archivos aquí sigue apareciendo un número
 * viejo, lo que estás viendo es la copia guardada en tu navegador:
 * recarga con Ctrl+F5 (Cmd+Shift+R en Mac).
 */
const VERSION = '5.14';
const VERSION_FECHA = '8 de octubre de 2026';

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

const vista     = $('#vista');
const pantAcceso= $('#acceso');
const app       = $('#app');

let usuarioActual = null;
let catalogos = null;   // instrumentos, salones, bloques, horario de la escuela
let tokenCsrf = null;   // se recibe al entrar y viaja en cada escritura

/*
 * Roles. Quién puede hacer qué lo decide el SERVIDOR; aquí solo se evita
 * mostrar lo que alguien no puede usar. Los botones de administración llevan
 * data-solo-admin y los de captura data-escribe: la hoja de estilos los
 * oculta según el rol (body[data-rol]).
 */
const NOMBRE_ROL = { admin: 'Administración', recepcion: 'Recepción', consulta: 'Consulta' };
const rolActual = () => usuarioActual?.rol || 'consulta';
const esAdmin = () => rolActual() === 'admin';
const VISTAS_DEL_ROL = {
  admin: null,   // todas
  recepcion: ['inicio', 'alumnos', 'maestros', 'pendientes', 'inscripciones', 'espera', 'horarios', 'pagos', 'bitacora', 'ajustes'],
  consulta: ['inicio', 'alumnos', 'maestros', 'pendientes', 'inscripciones', 'espera', 'horarios', 'pagos', 'nomina',
             'reportes', 'bitacora', 'ajustes']
};
const OPCIONES_ROL = [
  { valor: 'admin', texto: 'Administración: todo' },
  { valor: 'recepcion', texto: 'Recepción: el trabajo del día' },
  { valor: 'consulta', texto: 'Consulta: solo ver' }
];
const vistaPermitida = (nombre) => !VISTAS_DEL_ROL[rolActual()] || VISTAS_DEL_ROL[rolActual()].includes(nombre);

function aplicarRol() {
  document.body.dataset.rol = rolActual();
  $$('#menu a').forEach(a => a.classList.toggle('oculto', !vistaPermitida(a.dataset.vista)));
}

/* ------------------------------------------------------------- Utilidades */

const esc = (t) => String(t ?? '').replace(/[&<>"']/g, c =>
  ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

// Sin centavos se dice "$900"; con centavos, siempre dos decimales ("$41,002.50", nunca "$41,002.5").
const dinero = (n) => {
  const v = Number(n) || 0;
  const entero = Math.abs(v - Math.round(v)) < 0.005;
  return new Intl.NumberFormat('es-MX', {
    style: 'currency', currency: 'MXN', minimumFractionDigits: entero ? 0 : 2, maximumFractionDigits: 2
  }).format(entero ? Math.round(v) : v);
};

const hora = (h) => (h || '').slice(0, 5);

const nombreCompleto = (o, pre = '') =>
  `${o[pre + 'nombre'] ?? ''} ${o[pre + 'apellido'] ?? ''}`.trim();

const mesLargo = (periodo) => {
  if (!periodo || !periodo.includes('-')) return periodo || '';
  const [a, m] = periodo.split('-');
  const meses = ['enero','febrero','marzo','abril','mayo','junio','julio',
                 'agosto','septiembre','octubre','noviembre','diciembre'];
  return `${meses[Number(m) - 1] ?? m} ${a}`;
};

/** Hoy en la hora de la escuela (toISOString da UTC y en la noche ya es mañana) */
const hoyLocal = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// El mes en curso en la hora de la escuela: con UTC, la noche del último día ya era el mes siguiente.
const periodoActual = () => hoyLocal().slice(0, 7);

/**
 * Clases que vale un mes completo. Sin periodo, las de este mes; con un periodo AAAA-MM, las de ese mes
 * (un cambio programado rige desde el mes que entra: este mes se queda con las de siempre).
 */
const clasesAlMes = (periodo) => {
  const c = catalogos?.configuracion;
  const mes = periodo ? String(periodo).slice(0, 7) : '';
  // Con todos los cambios guardados se sabe cuántas valía CUALQUIER mes (uno pasado, este o uno futuro).
  const h = c?.semanas_cambios;
  if (mes && h && Array.isArray(h.cambios) && h.cambios.length) {
    let v = Number(h.antes);
    for (const x of h.cambios) { if (x.desde <= mes) v = Number(x.semanas); else break; }
    return v || 4;
  }
  const p = c?.semanas_proximas;
  if (mes && p && mes >= p.desde) return Number(p.semanas) || 4;
  return Number(c?.semanas_por_mes) || 4;
};

/**
 * Identificador único de un cobro: si la misma clave llega dos veces (doble clic,
 * red lenta), el servidor no lo guarda otra vez. crypto.randomUUID no existe en
 * direcciones de red con http simple, de ahí el respaldo.
 */
const nuevaClave = () => (crypto.randomUUID?.() || (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12)));

/**
 * Clases que le tocan en su primer mes a quien empieza en `desde` (misma
 * cuenta que Calendario::tramo en el servidor). null = mes completo.
 */
const clasesDelPrimerMes = (dia, desde) => {
  const numero = { Lunes: 1, Martes: 2, Miercoles: 3, Jueves: 4, Viernes: 5, Sabado: 6 }[dia];
  if (!numero || !/^\d{4}-\d{2}-\d{2}$/.test(desde || '')) return null;
  const [a, m, d] = desde.split('-').map(Number);
  if (d === 1) return null;
  const ultimo = new Date(a, m, 0).getDate();
  let n = 0;
  for (let x = d; x <= ultimo; x++) if ((new Date(a, m - 1, x).getDay() || 7) === numero) n++;
  const total = clasesAlMes(desde.slice(0, 7));
  return n >= total ? null : n;
};

/** Cuántas clases le quedan en lo que resta del mes de `desde` (incluido ese día). null = no se sabe. */
const clasesQueQuedan = (dia, desde) => {
  const numero = { Lunes: 1, Martes: 2, Miercoles: 3, Jueves: 4, Viernes: 5, Sabado: 6 }[dia];
  if (!numero || !/^\d{4}-\d{2}-\d{2}$/.test(desde || '')) return null;
  const [a, m, d] = desde.split('-').map(Number);
  const ultimo = new Date(a, m, 0).getDate();
  let n = 0;
  for (let x = d; x <= ultimo; x++) if ((new Date(a, m - 1, x).getDay() || 7) === numero) n++;
  return n;
};

/** '2026-09-29' -> '2026-10-01' */
const primeroDelMesSiguiente = (fecha) => {
  const [a, m] = fecha.split('-').map(Number);
  return m === 12 ? `${a + 1}-01-01` : `${a}-${String(m + 1).padStart(2, '0')}-01`;
};

const NOMBRE_MES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

/** Solo el primer nombre, para los avisos: no obliga a adivinar el género de nadie */
const primerNombre = (nombre) => String(nombre ?? '').trim().split(/\s+/)[0] || '';

/** '2026-09-14' -> '14 sep' */
const diaMes = (f) => {
  if (!f) return '';
  const [, m, d] = f.split('-');
  return `${Number(d)} ${['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][Number(m) - 1]}`;
};

/** Leyenda de un renglón de nómina a medias: "2 de 4 clases · desde el 14 sep" */
const leyendaTramo = (d) => {
  if (d.clases === null || d.clases === undefined) return '';
  const partes = [`${d.clases} de ${d.semanas_mes || clasesAlMes(d.periodo)} clases`];
  if (d.hasta) partes.push(`hasta el ${diaMes(d.hasta)}${d.paso_a ? ', pasó con ' + d.paso_a : ''}`);
  else if (d.desde) partes.push(`desde el ${diaMes(d.desde)}`);
  return partes.join(' · ');
};

// >>> agrupar-nomina
/**
 * Un renglón por alumno. Si un maestro tiene al mismo alumno en varias clases (piano y canto, o dos
 * días), la nómina, el recibo y el CSV lo muestran UNA vez, con la SUMA. Los datos siguen guardados
 * por clase (nomina_detalle): esto es solo presentación.
 *
 * @param {Array} detalle renglones de NominaModel::detalleDeMaestro
 * @returns {Array} [{ id_alumno, nombre, monto, paga_el_alumno, clases: [renglones], varias }] en el
 *   orden en que cada alumno aparece por primera vez
 */
const agruparPorAlumno = (detalle) => {
  const grupos = new Map();
  for (const d of detalle || []) {
    const llave = String(d.id_alumno);
    if (!grupos.has(llave)) {
      grupos.set(llave, { id_alumno: d.id_alumno, nombre: nombreCompleto(d, 'alumno_'), monto: 0, paga_el_alumno: 0, clases: [] });
    }
    const g = grupos.get(llave);
    g.monto += Number(d.monto) || 0;
    g.paga_el_alumno += Number(d.paga_el_alumno) || 0;
    g.clases.push(d);
  }
  return [...grupos.values()].map(g => ({
    ...g, monto: Math.round(g.monto * 100) / 100, paga_el_alumno: Math.round(g.paga_el_alumno * 100) / 100, varias: g.clases.length > 1
  }));
};

const ABREV_DIA = { Lunes: 'lun', Martes: 'mar', Miercoles: 'mié', Jueves: 'jue', Viernes: 'vie', Sabado: 'sáb' };

/** "Piano mar 17:00 $560": una clase dentro del renglón de un alumno con varias */
const desgloseClase = (d) => `${d.instrumento} ${d.dia_semana ? `${ABREV_DIA[d.dia_semana] || d.dia_semana} ${hora(d.hora_inicio)}` : 'sin horario'} ${dinero(d.monto)}`;
// <<< agrupar-nomina

/**
 * Abre WhatsApp con el mensaje escrito. Usa el enlace oficial wa.me, así que
 * funciona con el WhatsApp normal de la escuela, sin contratar nada: solo
 * prepara el mensaje y quien cobra le da enviar.
 */
function avisarPorWhatsApp(telefono, mensaje) {
  const numero = String(telefono || '').replace(/\D/g, '');
  if (numero.length < 10) {
    aviso('Ese alumno no tiene un teléfono válido registrado.', 'error');
    return;
  }
  const conLada = numero.length === 10 ? '52' + numero : numero;
  window.open(`https://wa.me/${conLada}?text=${encodeURIComponent(mensaje)}`, '_blank', 'noopener');
}

const mensajeCobro = (alumno, monto, periodo) =>
  `Hola, buen día. Le escribimos de la escuela. Le recordamos la colegiatura de ` +
  `${mensajeCobro.mes(periodo)} de ${alumno}, por ${new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(monto)}. ` +
  `Quedamos atentos, gracias.`;
mensajeCobro.mes = (p) => {
  if (!p || !p.includes('-')) return 'este mes';
  const [a, m] = p.split('-');
  const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  return `${meses[Number(m) - 1] ?? m} ${a}`;
};

function aviso(mensaje, tipo = '') {
  const div = document.createElement('div');
  div.className = 'aviso ' + tipo;
  div.textContent = mensaje;
  $('#avisos').appendChild(div);
  setTimeout(() => div.remove(), tipo === 'error' ? 6000 : 3500);
}

/* ------------------------------------------------------------ Cliente API */

/**
 * Lo que se le dice a la persona cuando el servidor contesta algo que no es del sistema (casi siempre una página
 * de error de PHP o de Apache, por ejemplo un 500). Si la respuesta trae un folio, se agrega para que
 * administración pueda buscarlo en el registro de errores.
 */
function textoErrorServidor(texto) {
  const folio = /folio\s+([0-9A-F]{6})\b/i.exec(String(texto ?? ''))?.[1];
  return 'El servidor tuvo un problema y no respondió bien. Avisa a administración.' + (folio ? ` (folio ${folio.toUpperCase()})` : '');
}

/** Escribe un mensaje en el formulario de acceso (si la pantalla de entrada es la que se está viendo) */
function mostrarErrorAcceso(mensaje) {
  const errorAcceso = $('#error-acceso');
  if (!errorAcceso || !mensaje) return;
  errorAcceso.textContent = mensaje;
  errorAcceso.classList.remove('oculto');
}

async function api(ruta, opciones = {}) {
  const config = { credentials: 'include', ...opciones };

  if (config.cuerpo !== undefined) {
    config.method  = config.method || 'POST';
    config.headers = { 'Content-Type': 'application/json', ...(config.headers || {}) };
    config.body    = JSON.stringify(config.cuerpo);
    delete config.cuerpo;
  }

  // El token CSRF acompaña toda operación que cambie datos.
  const escribe = ['POST', 'PUT', 'DELETE'].includes((config.method || 'GET').toUpperCase());
  if (escribe && tokenCsrf) {
    config.headers = { ...(config.headers || {}), 'X-CSRF-Token': tokenCsrf };
  }

  let respuesta;
  try {
    respuesta = await fetch(`${API}/${ruta}`, config);
  } catch {
    throw new Error('No hay conexión con el servidor. ¿Está encendido Apache en XAMPP?');
  }

  const texto = await respuesta.text();
  let datos;
  try {
    datos = JSON.parse(texto);
  } catch {
    // La API respondió algo que no es JSON: casi siempre un error de PHP. La persona ve un mensaje claro
    // (y, si la respuesta trae folio, el folio); el detalle técnico queda en la consola del navegador.
    console.error('Respuesta no JSON de', ruta, respuesta.status, texto.slice(0, 500));
    const falla = new Error(textoErrorServidor(texto));
    falla.status = respuesta.status;
    falla.servidor = true;
    throw falla;
  }

  if (respuesta.status === 401) {
    // La sesión terminó (inactividad, cambio de contraseña o de acceso): se
    // explica en la pantalla de entrada, que es donde la persona va a mirar.
    mostrarAcceso();
    mostrarErrorAcceso(datos.error);
    throw new Error(datos.error || 'Tu sesión terminó. Vuelve a entrar.');
  }
  if (!respuesta.ok || datos.ok === false) {
    // El código viaja con el error: 409 = el servidor pide confirmar algo.
    const error = new Error(datos.error || 'No se pudo completar la operación.');
    error.status = respuesta.status;
    throw error;
  }
  return datos.datos;
}

/* ----------------------------------------------------------------- Modal */

/**
 * @param {Array} extras botones adicionales a la izquierda del pie:
 *   [{ texto, alPulsar(datos, cerrar) }]. Reciben lo capturado igual que Aceptar.
 * @param {Function} alCancelar se llama cuando la persona cierra sin aceptar (Cancelar, Esc, clic afuera):
 *   sirve para volver a la ventana de la que venía.
 */
function modal({ titulo, campos, textoAceptar = 'Guardar', alAceptar, ancho, extras = [], textoCancelar = 'Cancelar',
                sinCancelar = false, protegerCambios = false, hayCambiosExtra = null, campoDeError = null,
                soloLectura = false, alCancelar = null }) {
  const capa = $('#capa-modal');
  bloqueosAceptar.clear();
  capa.innerHTML = `
    <div class="fondo-modal">
      <div class="modal" ${ancho ? `style="max-width:${ancho}"` : ''} role="dialog" aria-modal="true">
        <header><h2>${esc(titulo)}</h2></header>
        <form class="cuerpo" novalidate>${campos}</form>
        <div class="error-formulario oculto" id="error-modal" role="alert"></div>
        <footer>
          ${extras.map((x, i) => `<button type="button" class="pildora pildora-linea" data-extra="${i}"
              ${i === 0 ? 'style="margin-right:auto"' : ''}>${esc(x.texto)}</button>`).join('')}
          ${sinCancelar || soloLectura ? '' : `<button type="button" class="pildora pildora-linea" data-cerrar>${esc(textoCancelar)}</button>`}
          <button type="button" class="pildora pildora-azul" data-aceptar>${esc(soloLectura ? 'Cerrar' : textoAceptar)}</button>
        </footer>
        <p class="pista-boton oculto" id="pista-boton"></p>
      </div>
    </div>`;

  const fondo = $('.fondo-modal', capa);
  const form  = $('form', capa);
  const cerrar = () => { capa.innerHTML = ''; document.removeEventListener('keydown', porTecla); };

  // Con protegerCambios, cerrar a mano (Cancelar, Esc, clic afuera) pregunta antes si ya se
  // capturó algo. Solo cuenta lo que hizo una persona: lo que la pantalla rellena sola no.
  const fotoDelForm = () => JSON.stringify([...form.elements].map(el =>
    (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value));
  let base = fotoDelForm();
  let tocado = false;
  let interactuo = false;
  // Muchas pantallas terminan de armarse solas después de abrir (maestros, vista previa, costo).
  // Por eso "lo que traía al abrir" se toma justo antes de la primera acción de la persona.
  const primeraAccion = () => { if (!interactuo) { interactuo = true; base = fotoDelForm(); } };
  form.addEventListener('pointerdown', primeraAccion, true);
  form.addEventListener('keydown', primeraAccion, true);
  const alTocar = (e) => { if (e.isTrusted) { tocado = true; ocultarErrorFormulario(); } };
  form.addEventListener('input', alTocar);
  form.addEventListener('change', alTocar);
  const hayCambios = () => protegerCambios && ((tocado && fotoDelForm() !== base) || Boolean(hayCambiosExtra?.()));
  // La pantalla lo llama cuando termina de armarse sola (solo si la persona aún no ha tocado nada).
  cerrar.marcarLimpio = () => { if (!interactuo) base = fotoDelForm(); };

  let preguntando = false;
  const intentarCerrar = async () => {
    if (preguntando) return;
    if (!hayCambios()) { cerrar(); alCancelar?.(); return; }
    preguntando = true;
    const descartar = await confirmarDescartar();
    preguntando = false;
    if (descartar && document.body.contains(form)) { cerrar(); alCancelar?.(); }
  };

  const porTecla = (e) => {
    // Un modal que ya se quitó de la pantalla (otra pantalla lo reemplazó) no debe reaccionar.
    if (!document.body.contains(form)) { document.removeEventListener('keydown', porTecla); return; }
    if (e.key === 'Escape' && !$('#capa-confirmar')) intentarCerrar();
  };
  document.addEventListener('keydown', porTecla);

  fondo.addEventListener('click', (e) => { if (e.target === fondo) intentarCerrar(); });
  $('[data-cerrar]', capa)?.addEventListener('click', intentarCerrar);

  // Un error que no es de un campo va en un recuadro dentro del formulario (no en un aviso que
  // tapa los botones); si el servidor habla de un campo reconocible, se marca ese campo.
  const mostrarError = (e) => {
    if (!document.body.contains(form)) { aviso(e.message, 'error'); return; }
    const campo = campoDeError?.(e.message);
    // Junto al campo va solo lo que importa: sin el "No se pudo asignar el horario:" de la operación.
    if (campo) errorEnCampo(campo, sinPrefijoDeOperacion(e.message));
    else errorEnFormulario(e.message);
  };

  const aceptar = async () => {
    const boton = $('[data-aceptar]', capa);
    if (!boton || boton.dataset.bloqueado === '1') return;
    const datos = Object.fromEntries(new FormData(form).entries());
    ocultarErrorFormulario();
    boton.disabled = true;
    try {
      await alAceptar(datos, cerrar);
    } catch (e) {
      mostrarError(e);
    } finally {
      if (document.body.contains(boton)) boton.disabled = boton.dataset.bloqueado === '1';
    }
  };

  $('[data-aceptar]', capa).addEventListener('click', aceptar);
  $$('[data-extra]', capa).forEach(b => b.addEventListener('click', async () => {
    b.disabled = true;
    ocultarErrorFormulario();
    try {
      await extras[Number(b.dataset.extra)].alPulsar(Object.fromEntries(new FormData(form).entries()), cerrar);
    } catch (e) {
      mostrarError(e);
    } finally {
      if (document.body.contains(b)) b.disabled = false;
    }
  }));
  form.addEventListener('submit', (e) => { e.preventDefault(); aceptar(); });
  setTimeout(() => $('input,select,textarea', form)?.focus(), 30);
  return cerrar;
}

/**
 * Un problema con el mensaje COMPLETO del servidor (puede traer varias líneas: qué clases quedarían fuera y
 * por qué) en una ventana, para lo que no tiene un formulario abierto donde mostrarlo. Los saltos de línea se respetan.
 */
function mostrarProblema(titulo, mensaje) {
  modal({
    titulo, textoAceptar: 'Entendido', sinCancelar: true, ancho: '560px',
    campos: `<p style="white-space:pre-line;margin:0;font-size:14px;line-height:1.5">${esc(mensaje)}</p>`,
    alAceptar: async (_datos, cerrar) => cerrar()
  });
}

/** El mensaje sin el "No se pudo ...:" con que el servidor nombra la operación que falló */
const sinPrefijoDeOperacion = (m) =>
  String(m || '').replace(/^No se pudo (asignar el horario|completar el alta|completar la baja|corregir la baja): /, '');

/** Recuadro de error dentro del formulario abierto, encima de los botones */
function errorEnFormulario(mensaje) {
  const caja = $('#error-modal');
  if (!caja) { aviso(mensaje, 'error'); return; }
  caja.textContent = mensaje;
  caja.classList.remove('oculto');
  caja.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function ocultarErrorFormulario() {
  const caja = $('#error-modal');
  if (caja) { caja.classList.add('oculto'); caja.textContent = ''; }
}

/**
 * Deshabilita el botón principal del formulario abierto y explica debajo por qué
 * (o lo habilita). Mientras esté bloqueado, ni Enter ni un clic lo activan.
 *
 * Varias cosas pueden bloquearlo a la vez (el horario, el cálculo del precio): cada una se identifica con
 * su `fuente` y el botón solo se habilita cuando ninguna lo bloquea.
 */
const bloqueosAceptar = new Map();
function bloquearAceptar(bloqueado, pista = '', fuente = 'horario', textoBoton = null) {
  const boton = $('#capa-modal [data-aceptar]');
  if (!boton) return;
  if (bloqueado) bloqueosAceptar.set(fuente, { pista, textoBoton }); else bloqueosAceptar.delete(fuente);
  const hay = bloqueosAceptar.size > 0;
  const primera = hay ? [...bloqueosAceptar.values()][0] : null;
  boton.dataset.bloqueado = hay ? '1' : '';
  boton.disabled = hay;
  // El botón dice qué espera mientras calcula; al soltarse vuelve a su texto.
  if (boton.dataset.textoOriginal === undefined) boton.dataset.textoOriginal = boton.textContent;
  const conTexto = [...bloqueosAceptar.values()].find(x => x.textoBoton);
  boton.textContent = conTexto ? conTexto.textoBoton : boton.dataset.textoOriginal;
  const nota = $('#pista-boton');
  if (nota) { nota.textContent = hay ? primera.pista : ''; nota.classList.toggle('oculto', !hay); }
}

/**
 * "¿Descartar lo que capturaste?" con el mismo estilo de los demás formularios.
 * Se abre encima del formulario, que se queda como estaba. Resuelve true si descartan.
 */
function confirmarDescartar() {
  return new Promise((resolver) => {
    const previo = document.activeElement;
    const formulario = $('#capa-modal');
    const capa = document.createElement('div');
    capa.id = 'capa-confirmar';
    capa.innerHTML = `
      <div class="fondo-modal">
        <div class="modal" style="max-width:400px" role="alertdialog" aria-modal="true" aria-labelledby="t-descartar">
          <header><h2 id="t-descartar">¿Descartar lo que capturaste?</h2></header>
          <div class="cuerpo"><p class="suave" style="margin:0;font-size:14px">Todavía no se guardó nada.</p></div>
          <footer>
            <button type="button" class="pildora pildora-azul" data-seguir>Seguir capturando</button>
            <button type="button" class="pildora pildora-roja" data-descartar>Descartar</button>
          </footer>
        </div>
      </div>`;
    document.body.appendChild(capa);
    if (formulario) formulario.inert = true;

    const salir = (descartar) => {
      document.removeEventListener('keydown', tecla, true);
      capa.remove();
      if (formulario) formulario.inert = false;
      if (!descartar && previo && document.body.contains(previo)) previo.focus();
      resolver(descartar);
    };
    const tecla = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); salir(false); }
    };
    document.addEventListener('keydown', tecla, true);
    $('[data-seguir]', capa).addEventListener('click', () => salir(false));
    $('[data-descartar]', capa).addEventListener('click', () => salir(true));
    $('.fondo-modal', capa).addEventListener('click', (e) => { if (e.target === e.currentTarget) salir(false); });
    setTimeout(() => $('[data-seguir]', capa)?.focus(), 20);
  });
}

const campoTexto = (nombre, etiqueta, opciones = {}) => `
  <div>
    <label class="etiqueta" for="c-${nombre}">${esc(etiqueta)}</label>
    <input class="campo" id="c-${nombre}" name="${nombre}" type="${opciones.tipo || 'text'}"
           value="${esc(opciones.valor ?? '')}" placeholder="${esc(opciones.pista ?? '')}"
           ${opciones.paso ? `step="${opciones.paso}"` : ''} ${opciones.requerido ? 'required' : ''}>
    ${opciones.ayuda ? `<p class="nota-campo" style="margin-top:6px">${esc(opciones.ayuda)}</p>` : ''}
  </div>`;

const campoSelect = (nombre, etiqueta, opciones, valor = '') => `
  <div>
    <label class="etiqueta" for="c-${nombre}">${esc(etiqueta)}</label>
    <select class="campo" id="c-${nombre}" name="${nombre}">
      ${opciones.map(o => `<option value="${esc(o.valor)}" ${String(o.valor) === String(valor) ? 'selected' : ''}>${esc(o.texto)}</option>`).join('')}
    </select>
  </div>`;

/* ------------------------------------------------------------- Sesión */

function mostrarAcceso() {
  app.classList.add('oculto');
  pantAcceso.classList.remove('oculto');
  usuarioActual = null;
}

function mostrarApp() {
  pantAcceso.classList.add('oculto');
  app.classList.remove('oculto');
}

$('#form-acceso').addEventListener('submit', async (e) => {
  e.preventDefault();
  const error = $('#error-acceso');
  error.classList.add('oculto');
  const boton = $('#form-acceso button');
  boton.disabled = true;
  try {
    usuarioActual = await api('auth.php?accion=login', {
      cuerpo: { usuario: $('#usuario').value.trim(), password: $('#clave').value }
    });
    tokenCsrf = usuarioActual?.csrf ?? null;
    $('#clave').value = '';
    aplicarRol();
    mostrarApp();
    await cargarCatalogos();
    navegar(location.hash || '#inicio');
  } catch (err) {
    error.textContent = err.message;
    error.classList.remove('oculto');
  } finally {
    boton.disabled = false;
  }
});

/* ------------------------------------------------------------ Apariencia */

// 'auto' sigue al sistema; 'claro' y 'oscuro' se quedan fijos en esta computadora.
const temaGuardado = () => { try { return localStorage.getItem('escuela-tema') || 'auto'; } catch { return 'auto'; } };
const oscuroAhora = () => {
  const t = temaGuardado();
  return t === 'oscuro' || (t === 'auto' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);
};
const ICONO_SOL = `<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
  stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/></svg>`;
const ICONO_LUNA = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
  stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></svg>`;

function aplicarTema(t) {
  try { if (t === 'auto') localStorage.removeItem('escuela-tema'); else localStorage.setItem('escuela-tema', t); } catch {}
  if (t === 'auto') delete document.documentElement.dataset.tema; else document.documentElement.dataset.tema = t;
  pintarBotonTema();
  $$('[data-tema-opcion]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.temaOpcion === t)));
}

function pintarBotonTema() {
  const b = $('#btn-tema');
  if (!b) return;
  const oscuro = oscuroAhora();
  b.innerHTML = oscuro ? ICONO_SOL : ICONO_LUNA;
  b.title = oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
  b.setAttribute('aria-label', b.title);
}

$('#btn-tema')?.addEventListener('click', () => aplicarTema(oscuroAhora() ? 'claro' : 'oscuro'));
window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', pintarBotonTema);
pintarBotonTema();

$('#btn-salir').addEventListener('click', async () => {
  try { await api('auth.php?accion=logout', { method: 'POST' }); } catch {}
  mostrarAcceso();
});

$('#menu-movil').addEventListener('click', () => {
  const menu = $('#menu');
  const abierto = menu.classList.toggle('abierto');
  $('#menu-movil').setAttribute('aria-expanded', String(abierto));
});

async function cargarCatalogos() {
  try { catalogos = await api('catalogos.php?tipo=todo'); } catch { catalogos = null; }
}

/**
 * Si alguien dejó notas sin resolver en la bitácora, lo primero que hay que
 * ver al entrar es eso. Se muestra una vez por sesión del navegador.
 */
let avisoBitacoraMostrado = false;
async function avisarBitacora() {
  if (avisoBitacoraMostrado) return '';
  try {
    const notas = (await api('notas.php')).filter(n => !Number(n.resuelto));
    avisoBitacoraMostrado = true;
    if (!notas.length) return '';
    return `
      <div class="franja-aviso" id="franja-bitacora">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" style="color:var(--ambar);flex-shrink:0;margin-top:2px">
          <path d="M12 8v5M12 16.5v.01"/><circle cx="12" cy="12" r="9"/></svg>
        <div>
          <strong>${notas.length === 1 ? 'Hay una nota del turno anterior' : `Hay ${notas.length} notas del turno anterior`}</strong>
          ${notas.slice(0, 3).map(n => `
            <p style="margin:6px 0 0;font-size:13.5px">${esc(n.mensaje)}
              <span class="suave">· ${esc(n.autor)}</span></p>`).join('')}
          ${notas.length > 3 ? `<p style="margin:6px 0 0"><a href="#bitacora">Ver las ${notas.length}</a></p>` : ''}
        </div>
        <button class="cerrar" aria-label="Cerrar aviso" onclick="this.parentElement.remove()">&times;</button>
      </div>`;
  } catch {
    avisoBitacoraMostrado = true;
    return '';
  }
}

/* ------------------------------------------------------------- Router */

const vistas = {};

let vistaActual = 'inicio';
// Horarios: día, vista, salón y desplazamiento que se estaban viendo. Se olvida al cambiar de pantalla
// (navegar) y se conserva al refrescar después de guardar un cambio.
let memoriaHorarios = null;

/**
 * Vuelve a dibujar la vista en la que ya estás, conservando lo que habías
 * escrito en los buscadores y el punto donde ibas leyendo. Es lo que se usa
 * después de guardar algo: la lista se actualiza, pero la pantalla no salta
 * al principio como si acabaras de entrar.
 */
async function refrescar() {
  const scroll = window.scrollY;
  const escrito = {};
  $$('#vista input').forEach(i => { if (i.id && i.value) escrito[i.id] = i.value; });

  try {
    await (vistas[vistaActual] || vistas.inicio)();
  } catch (e) {
    aviso(e.message, 'error');
    return;
  }

  Object.entries(escrito).forEach(([id, valor]) => {
    const campo = document.getElementById(id);
    if (campo && 'value' in campo) {
      campo.value = valor;
      campo.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  window.scrollTo(0, scroll);
}

function navegar(hash) {
  // Acepta '#nomina=2026-02': el nombre de la vista es lo que va antes del '='.
  let nombre = (hash || '#inicio').replace('#', '').split('=')[0] || 'inicio';
  // Una pantalla que no es de su rol (un enlace viejo, un marcador) lleva a Inicio.
  if (!vistaPermitida(nombre)) nombre = 'inicio';
  vistaActual = vistas[nombre] ? nombre : 'inicio';
  memoriaHorarios = null;
  $$('#menu a').forEach(a => a.classList.toggle('activo', a.dataset.vista === nombre));
  $('#menu').classList.remove('abierto');

  const render = vistas[nombre] || vistas.inicio;
  vista.innerHTML = '<div class="cargando">Cargando…</div>';
  render().catch(e => {
    vista.innerHTML = `<div class="tarjeta"><div class="mensaje-vacio">${esc(e.message)}</div></div>`;
  });
}

window.addEventListener('hashchange', () => navegar(location.hash));

/* ====================================================================
   VISTA · INICIO
   ==================================================================== */

const ICONOS = {
  alta:'<circle cx="10" cy="8" r="3.4"/><path d="M3.5 19.5c.7-3.4 3.3-5.2 6.5-5.2 1.1 0 2.2.2 3.1.6"/><path d="M17 14.5v6M14 17.5h6"/>',
  reloj:'<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3"/>',
  pago:'<rect x="2.8" y="6.5" width="18.4" height="11" rx="3"/><circle cx="12" cy="12" r="2.6"/>',
  gente:'<circle cx="12" cy="7.5" r="3.4"/><path d="M5 19.5c.9-3.6 3.7-5.4 7-5.4s6.1 1.8 7 5.4"/>',
  grafica:'<path d="M4 20V4M4 20h16"/><rect x="7.5" y="12" width="3.4" height="5" rx="1"/><rect x="13.2" y="8" width="3.4" height="9" rx="1"/>',
  subir:'<path d="M12 3.5v10M12 3.5l-3.5 3.5M12 3.5l3.5 3.5"/><path d="M4.5 15v3.5A2 2 0 0 0 6.5 20.5h11a2 2 0 0 0 2-2V15"/>',
};
const icono = (clave) => `<svg width="22" height="22" viewBox="0 0 24 24" fill="none"
  stroke="currentColor" stroke-width="1.5" stroke-linecap="round">${ICONOS[clave]}</svg>`;

vistas.inicio = async () => {
  const [d, franja] = await Promise.all([api('dashboard.php'), avisarBitacora()]);

  const clases = d.clases_hoy.length
    ? `<div class="tabla-scroll"><table>
        <thead><tr><th>Hora</th><th>Alumno</th><th>Maestro</th><th>Salón</th><th>Estado</th></tr></thead>
        <tbody>${d.clases_hoy.map(c => `
          <tr>
            <td class="cifra">${hora(c.hora_inicio)}</td>
            <td>${esc(nombreCompleto(c, 'alumno_'))}</td>
            <td class="suave">${esc(nombreCompleto(c, 'maestro_'))} · ${esc(c.instrumento)}</td>
            <td class="suave">${esc(c.salon)}</td>
            <td>${Number(c.pagos_mes) > 0
                  ? '<span class="verde">Al corriente</span>'
                  : '<span class="rojo">Adeuda el mes</span>'}</td>
          </tr>`).join('')}</tbody></table></div>`
    : `<div class="mensaje-vacio">No hay clases con horario asignado para hoy.<br>
        Asigna horarios desde <a href="#inscripciones">Inscripciones</a>.</div>`;

  const deudores = d.deudores.length
    ? `<ul class="lista-simple">${d.deudores.map(a => `
        <li>
          <span>${esc(nombreCompleto(a))}${a.exalumno
            ? ' <span class="etiqueta-estado suave" title="Ya no está inscrito, pero todavía debe">Exalumno</span>' : ''}</span>
          <span style="display:flex;align-items:center;gap:10px">
            <span class="cifra">${dinero(a.adeudo)}</span>
            ${a.telefono ? `<button class="pildora pildora-linea pildora-chica" data-wa="${a.id_alumno}"
              title="Mandar recordatorio por WhatsApp">WhatsApp</button>` : ''}
            <button class="pildora pildora-linea pildora-chica" data-escribe data-cobrar="${a.id_alumno}">Cobrar</button>
          </span>
        </li>`).join('')}
       </ul>`
    : `<div class="mensaje-vacio">Nadie debe el mes. Bien ahí.</div>`;

  const notas = d.notas.length
    ? `<ul class="lista-simple" style="display:block">${d.notas.map(n => `
        <li style="display:block">
          <p style="margin:0">${esc(n.mensaje)}</p>
          <p class="suave" style="margin:6px 0 0;font-size:12.5px">${esc(n.autor)} · ${esc((n.fecha_creacion || '').slice(0, 16))}</p>
        </li>`).join('')}</ul>`
    : `<div class="mensaje-vacio">Sin pendientes del turno.</div>`;

  const modulo = (destino, clave, nombre, desc) => `
    <a class="tarjeta modulo" href="#${destino}">
      ${icono(clave)}
      <span><span class="nombre">${esc(nombre)}</span><span class="desc">${esc(desc)}</span></span>
    </a>`;

  // Facturas: se recuerdan en los últimos diez días del mes, mientras falte alguna.
  const fac = d.facturas;
  const franjaFacturas = fac && fac.faltan > 0 && fac.dias_restantes <= 10 ? `
    <div class="franja-aviso">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" style="color:var(--ambar);flex-shrink:0;margin-top:2px">
        <path d="M7 3.5h7l4 4v13H7z"/><path d="M14 3.5v4h4M10 12h5M10 15.5h5"/></svg>
      <div>
        <strong>${fac.faltan === 1 ? 'Falta 1 factura' : `Faltan ${fac.faltan} facturas`} de ${esc(mesLargo(fac.periodo))}</strong>
        <span class="suave">· ${fac.dias_restantes === 0 ? 'hoy termina el mes' : `quedan ${fac.dias_restantes} ${fac.dias_restantes === 1 ? 'día' : 'días'} del mes`}</span>
        <p class="suave" style="margin:5px 0 0;font-size:13.5px">${esc(fac.nombres.join(', '))}${fac.faltan > fac.nombres.length ? ' y más' : ''}.
          <a href="#pagos">Ver facturas</a></p>
      </div>
    </div>` : '';

  vista.innerHTML = `
    ${franja}
    ${franjaFacturas}
    <section class="panel-negro">
      <p class="fecha">${esc(diaBonito(d.dia))} ${d.fecha ? Number(String(d.fecha).slice(8, 10)) + ' ' : ''}${mesLargo(d.periodo)}</p>
      <h1>${d.clases_hoy.length} ${d.clases_hoy.length === 1 ? 'clase hoy' : 'clases hoy'}</h1>
      <p>${d.deudores_total
            ? `${d.deudores_total} alumnos deben el mes, ${dinero(d.deudores_monto)} en total.`
            : 'Todos los alumnos están al corriente este mes.'}</p>
      ${d.nomina_urgentes ? `<p style="font-size:15px;margin-top:8px">
        Hay ${d.nomina_urgentes} ${d.nomina_urgentes === 1 ? 'maestro al que ya le toca cobrar' : 'maestros a los que ya les toca cobrar'}.
      </p>` : ''}
      <div class="acciones">
        <button class="pildora pildora-azul" data-escribe id="b-inscribir">Inscribir alumno</button>
        <a class="pildora pildora-clara" href="#horarios">Ver los horarios</a>
      </div>
    </section>

    <section class="rejilla k4" style="margin-bottom:18px">
      <div class="tarjeta dato"><div class="valor cifra">${d.alumnos_activos}</div><div class="nombre">Alumnos activos</div></div>
      <div class="tarjeta dato"><div class="valor cifra">${d.maestros_activos}</div><div class="nombre">Maestros en plantilla</div></div>
      <div class="tarjeta dato"><div class="valor cifra ${d.deudores_total ? 'rojo' : ''}">${d.deudores_total}</div><div class="nombre">Pagos pendientes</div></div>
      <div class="tarjeta dato"><div class="valor cifra">${dinero(d.ingresos_mes)}</div><div class="nombre">Ingresos del mes</div></div>
    </section>

    ${d.pendientes ? `
      <div class="tarjeta" style="margin-bottom:18px">
        <div class="cuerpo" style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center">
          <div>
            <strong>${d.pendientes} inscripciones sin horario</strong>
            <p class="suave" style="margin:4px 0 0">Vienen del Excel importado: ya tienen maestro y costo, les falta día, hora y salón.</p>
          </div>
          <a class="pildora pildora-linea pildora-chica" href="#inscripciones">Asignarlos</a>
        </div>
      </div>` : ''}

    <h2 style="font-size:21px;margin:26px 0 14px">Qué quieres hacer</h2>
    <section class="rejilla k3" style="margin-bottom:22px">
      ${modulo('inscripciones','alta','Inscribir alumno','Alta con maestro, instrumento, salón y horario.')}
      ${modulo('horarios','reloj','Ver horarios','Ocupación por salón y día de la semana.')}
      ${modulo('pagos','pago','Registrar pago','Colegiatura, inscripción o clase suelta.')}
      ${modulo('maestros','gente','Maestros','Altas, tarifas por alumno, bajas y reingresos.')}
      ${modulo('reportes','grafica','Reportes','Ingresos del mes comparados con los anteriores.')}
      ${modulo('importar','subir','Importar desde Excel','Sube la hoja de nómina y carga todo de golpe.')}
    </section>

    ${d.nomina_pendientes ? `
    <div class="tarjeta" style="margin-bottom:18px">
      <header>
        <h2>Nómina por pagar</h2>
        <span class="suave" style="font-size:13.5px">
          ${d.nomina_pendientes} ${d.nomina_pendientes === 1 ? 'maestro' : 'maestros'} ·
          ${dinero(d.nomina_monto)} en total
        </span>
      </header>
      <ul class="lista-simple">
        ${d.nomina_por_pagar.map(m => {
          const urgente = m.urgencia === 'vencido' || m.urgencia === 'hoy';
          const dias = Number(m.dias_para_pago);
          const texto = m.urgencia === 'vencido' ? 'ya le tocaba'
                      : m.urgencia === 'hoy' ? 'le toca hoy'
                      : dias === 1 ? 'mañana'
                      : `en ${dias} días`;
          return `
          <li>
            <span>
              ${esc(nombreCompleto(m))}
              <span class="suave" style="font-size:12.5px">· ${esc(textoDias(m) === '—' ? 'sin horario' : textoDias(m))}</span>
            </span>
            <span style="display:flex;align-items:center;gap:10px">
              <span class="${urgente ? 'rojo' : 'suave'}" style="font-size:13px">${texto}</span>
              <span class="cifra">${dinero(m.total_a_pagar)}</span>
            </span>
          </li>`; }).join('')}
        ${d.nomina_pendientes > d.nomina_por_pagar.length
          ? `<li><a href="#nomina">Ver los ${d.nomina_pendientes}</a></li>` : ''}
      </ul>
    </div>` : ''}

    <section class="rejilla principal">
      <div class="tarjeta">
        <header><h2>Clases de hoy</h2><a href="#horarios">Ver la semana</a></header>
        ${clases}
      </div>
      <div>
        <div class="tarjeta">
          <header><h2>Pendientes de cobro</h2></header>
          ${deudores}
          ${d.deudores_total > d.deudores.length
            ? `<div class="cuerpo"><a href="#pagos">Ver los ${d.deudores_total}</a></div>` : ''}
        </div>
        <div class="tarjeta">
          <header><h2>Bitácora del turno</h2><a href="#bitacora">Abrir</a></header>
          ${notas}
        </div>
      </div>
    </section>`;

  $('#b-inscribir').addEventListener('click', () => formularioAlta());
  $('.modulo[href="#inscripciones"]')?.addEventListener('click', (e) => { e.preventDefault(); formularioAlta(); });

  $$('[data-cobrar]').forEach(b => b.addEventListener('click', () => {
    const alumno = d.deudores.find(a => String(a.id_alumno) === b.dataset.cobrar);
    registrarPago(alumno, alumno.adeudo, () => refrescar());
  }));

  $$('[data-wa]').forEach(b => b.addEventListener('click', () => {
    const a = d.deudores.find(x => String(x.id_alumno) === b.dataset.wa);
    avisarPorWhatsApp(a.telefono, mensajeCobro(nombreCompleto(a), a.adeudo, d.periodo));
  }));
};

/* --------------------------------------- Buscador de alumnos (varias vistas) */

/**
 * Abre un buscador y devuelve el alumno elegido, o null si se cancela.
 * Con 135 alumnos, un <select> es inservible: esto filtra mientras escribes.
 */
function elegirAlumno(lista, titulo = 'Buscar alumno') {
  return new Promise((resolver) => {
    let resuelto = false;
    const cerrar = modal({
      titulo,
      textoAceptar: 'Cancelar',
      alAceptar: (_d, cerrarModal) => { cerrarModal(); if (!resuelto) resolver(null); },
      alCancelar: () => { if (!resuelto) { resuelto = true; resolver(null); } },
      campos: `
        <input class="campo" id="buscar-alumno" placeholder="Escribe el nombre" autocomplete="off">
        <div id="resultados-alumno"
             style="max-height:290px;overflow-y:auto;border:1px solid var(--linea);border-radius:12px"></div>`
    });

    const caja = $('#resultados-alumno');

    const pintar = (filtro) => {
      const t = filtro.toLowerCase().trim();
      const encontrados = (t
        ? lista.filter(a => nombreCompleto(a).toLowerCase().includes(t))
        : lista).slice(0, 60);

      caja.innerHTML = encontrados.length ? encontrados.map(a => `
        <button type="button" data-elegir="${a.id_alumno}"
                style="display:block;width:100%;text-align:left;background:none;border:0;
                       border-bottom:1px solid var(--linea);padding:11px 15px;font:inherit;
                       color:inherit;cursor:pointer">
          ${esc(nombreCompleto(a))}${a.activo !== undefined && Number(a.activo) === 0 ? '<span class="suave" style="font-size:12.5px"> · inactivo</span>' : ''}
          ${a.num_clases ? `<span class="suave" style="font-size:12.5px"> · ${a.num_clases} ${a.num_clases === 1 ? 'clase' : 'clases'} · ${dinero(a.colegiatura)}/mes</span>` : ''}
        </button>`).join('')
        : '<p class="suave" style="padding:16px;margin:0">Nadie con ese nombre.</p>';

      $$('[data-elegir]', caja).forEach(b => {
        b.addEventListener('mouseenter', () => { b.style.background = 'var(--sup2)'; });
        b.addEventListener('mouseleave', () => { b.style.background = 'none'; });
        b.addEventListener('click', () => {
          resuelto = true;
          cerrar();
          resolver(lista.find(a => String(a.id_alumno) === b.dataset.elegir));
        });
      });
    };

    pintar('');
    const entrada = $('#buscar-alumno');
    entrada.addEventListener('input', (e) => pintar(e.target.value));
    entrada.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); $('[data-elegir]', caja)?.click(); }
    });
  });
}

/* ----------------------------------------------- Cobro (se usa en varias vistas) */

/**
 * Cobrar a un alumno. Muestra lo que debe mes por mes, propone cobrar el mes
 * más atrasado, y enseña ANTES de guardar cómo se va a repartir el dinero:
 * primero la deuda anterior, luego del mes más viejo al más nuevo (con su
 * recargo, una sola vez por mes), y lo que sobre se adelanta.
 */
async function registrarPago(alumno, _montoSugerido, alTerminar) {
  let cuenta;
  try { cuenta = await api(`pagos.php?accion=cuenta&alumno=${alumno.id_alumno}`); }
  catch (e) { aviso(e.message, 'error'); return; }

  const debe = cuenta.meses.filter(m => m.saldo > 0 && !m.futuro);
  const anterior = cuenta.anterior?.saldo || 0;
  const recargo = Number(cuenta.recargo_mes) || 0;
  const primero = debe[0];
  const mesMasViejo = anterior > 0 ? anterior
    : primero ? primero.saldo + (primero.vencido && !primero.recargo_cobrado ? recargo : 0)
    : cuenta.colegiatura;
  const todo = cuenta.adeudo + debe.filter(m => m.vencido && !m.recargo_cobrado).length * recargo;
  const descuento = cuenta.descuento_familiar && periodoActual() >= cuenta.descuento_familiar.desde
    ? Number(cuenta.descuento_familiar.monto) : 0;
  const unMes = Math.max(0, cuenta.colegiatura - descuento);
  const clave = nuevaClave();   // una por cobro: solo viaja al guardar de verdad
  // Qué se cobra: la colegiatura (lo de siempre) o la inscripción (cuota única, no abona a ningún mes).
  let queCobra = 'colegiatura';
  const cuotaInsc = Number(catalogos?.configuracion?.costo_inscripcion) || 0;
  const yaInsc = cuenta.inscripcion || { pagada: 0, fecha: null };

  modal({
    titulo: `Cobrar a ${nombreCompleto(alumno)}`,
    textoAceptar: 'Registrar cobro',
    ancho: '620px',
    extras: cuenta.alumno?.id_familia ? [{
      texto: 'Cobrar a toda la familia',
      alPulsar: (_d, cerrar) => { cerrar(); cobrarFamilia(Number(cuenta.alumno.id_familia)); }
    }] : [],
    campos: `
      <div>
        <label class="etiqueta">¿Qué se cobra?</label>
        <div class="pestanas" id="que-cobra">
          <button type="button" data-que="colegiatura" aria-selected="true">Colegiatura</button>
          <button type="button" data-que="inscripcion" aria-selected="false">Inscripción</button>
        </div>
      </div>

      <div data-insc class="oculto">
        ${campoTexto('monto_inscripcion', 'Importe de la inscripción',
          { tipo: 'number', paso: '0.01', valor: cuotaInsc > 0 ? cuotaInsc : '', pista: 'Escribe cuánto es la inscripción' })}
        <p class="nota-campo" style="margin-top:6px">Es una cuota única: no se abona a ninguna colegiatura.</p>
        ${yaInsc.pagada > 0 ? `
          <div class="vista-hueco" style="border-left:3px solid var(--ambar);margin-top:8px">
            Ya tiene ${dinero(yaInsc.pagada)} de inscripción pagada${yaInsc.fecha ? ` (${esc(yaInsc.fecha)})` : ''}.
            Cóbrala otra vez solo si de verdad es otra.
          </div>` : ''}
      </div>

      <div data-cole style="border:1px solid var(--linea);border-radius:12px;overflow:hidden">
        <div style="display:flex;justify-content:space-between;padding:11px 15px;background:var(--sup2);font-size:13.5px">
          <span>Colegiatura: <strong>${dinero(cuenta.colegiatura)}</strong> al mes${
            descuento ? ` <span class="verde">− ${dinero(descuento)} descuento familiar</span>` : ''}</span>
          <span>${cuenta.adeudo > 0 ? `Debe <strong class="rojo">${dinero(cuenta.adeudo)}</strong>` : '<span class="verde">Al corriente</span>'}</span>
        </div>
        ${anterior > 0 ? `
          <div style="display:flex;justify-content:space-between;padding:9px 15px;border-top:1px solid var(--linea);font-size:13.5px">
            <span>Deuda anterior <span class="suave">· ${esc(cuenta.anterior.nota || '')}</span></span>
            <span class="rojo cifra">${dinero(anterior)}</span>
          </div>` : ''}
        ${cuenta.meses.slice(-6).map(m => `
          <div style="display:flex;justify-content:space-between;gap:10px;padding:9px 15px;border-top:1px solid var(--linea);font-size:13.5px">
            <span style="text-transform:capitalize">${esc(mesLargo(m.periodo))}</span>
            <span class="cifra ${m.estado === 'pagado' ? 'verde'
                                  : m.estado === 'por_confirmar' || m.estado === 'sin_confirmar' ? 'ambar'
                                  : m.estado === 'adelantado' ? 'suave' : 'rojo'}">
              ${m.estado === 'pagado' ? 'Pagado'
                : m.estado === 'por_confirmar' ? 'Pagado · monto por confirmar'
                : m.estado === 'sin_confirmar' ? 'Por confirmar · ¿hasta qué mes pagó?'
                : m.estado === 'adelantado' ? `Adelantado · faltan ${dinero(m.saldo)}`
                : m.estado === 'abonado' ? `Abonó ${dinero(m.abonado)} · faltan ${dinero(m.saldo)}`
                : m.estado === 'sin_cargo' ? '—'
                : `Debe ${dinero(m.saldo)}`}${m.estado !== 'sin_confirmar' && m.vencido && !m.recargo_cobrado ? ` + ${dinero(recargo)} de recargo` : ''}
            </span>
          </div>`).join('')}
      </div>

      <div data-cole>
        <label class="etiqueta" for="c-monto">¿Cuánto trae?</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input class="campo" id="c-monto" name="monto" type="number" step="0.01" min="0"
                 value="${mesMasViejo > 0 ? mesMasViejo : ''}" style="max-width:170px">
          ${debe.length || anterior ? `
            <button type="button" class="pildora pildora-linea pildora-chica" data-monto="${mesMasViejo}">Lo más atrasado</button>
            <button type="button" class="pildora pildora-linea pildora-chica" data-monto="${todo}">Todo lo que debe</button>` : ''}
          <button type="button" class="pildora pildora-linea pildora-chica" data-monto="${unMes}">Un mes</button>
        </div>
        <p class="suave" style="font-size:12.5px;margin:6px 0 0">
          Puede ser un abono: si trae menos, se aplica a lo más atrasado y el resto queda pendiente.
        </p>
      </div>

      <div class="fila-campos">
        ${campoSelect('metodo', 'Método', [
          { valor:'efectivo', texto:'Efectivo' },
          { valor:'transferencia', texto:'Transferencia' },
          { valor:'tarjeta', texto:'Tarjeta' }])}
        <label data-cole style="display:flex;gap:8px;align-items:center;font-size:13.5px;margin-top:22px">
          <input type="checkbox" id="condonar"> Perdonar los recargos esta vez
        </label>
      </div>

      <div id="reparto" data-cole></div>`,
    alAceptar: async (datos, cerrar) => {
      if (queCobra === 'inscripcion') {
        const insc = Number($('#c-monto_inscripcion').value);
        if (!(insc > 0)) { errorEnCampo($('#c-monto_inscripcion'), 'Escribe cuánto es la inscripción.'); return; }
        if (insc > 100000) { errorEnCampo($('#c-monto_inscripcion'), 'Es demasiado alto. Revísalo.'); return; }
        const ri = await api('pagos.php?accion=inscripcion', {
          cuerpo: { id_alumno: Number(alumno.id_alumno), monto: insc, metodo: datos.metodo, clave }
        });
        if (ri.repetido) { aviso(ri.aviso, 'info'); cerrar(); alTerminar?.(); return; }
        aviso(`Inscripción de ${primerNombre(alumno.nombre)}: ${dinero(ri.monto)} registrada, folio ${ri.folio}.`, 'exito');
        cerrar();
        alTerminar?.();
        return;
      }
      const monto = Number($('#c-monto').value);
      if (!(monto > 0)) throw new Error('Escribe cuánto trae.');
      const r = await api('pagos.php?accion=cobrar', {
        cuerpo: { id_alumno: Number(alumno.id_alumno), monto, metodo: datos.metodo,
                  condonar_recargo: $('#condonar').checked ? 1 : 0, clave }
      });
      // Ya se había registrado (doble clic o red lenta): no se cobra de nuevo.
      if (r.repetido) {
        aviso(r.aviso, 'info');
        cerrar();
        alTerminar?.();
        return;
      }
      aviso(`Cobro de ${primerNombre(alumno.nombre)}, folio ${r.folio}: ${r.adeudo_despues > 0
        ? `le quedan ${dinero(r.adeudo_despues)} por pagar.` : 'queda al corriente.'}`, 'exito');
      if (Number(cuenta.alumno?.requiere_factura) === 1) {
        aviso(`${nombreCompleto(alumno)} requiere factura: cuando la hagas, márcala en Pagos → Facturas del mes.`, 'info');
      }
      cerrar();
      alTerminar?.();
    }
  });

  // Vista previa del reparto: se le pregunta al servidor, que es quien decide.
  let turno = 0;
  const previa = async () => {
    const monto = Number($('#c-monto')?.value);
    const caja = $('#reparto');
    if (!caja) return;
    if (!(monto > 0)) { caja.innerHTML = ''; return; }
    const mio = ++turno;
    try {
      const r = await api('pagos.php?accion=cobrar', {
        cuerpo: { id_alumno: Number(alumno.id_alumno), monto, metodo: $('#c-metodo').value,
                  condonar_recargo: $('#condonar').checked ? 1 : 0, simular: 1 }
      });
      if (mio !== turno) return;   // llegó una respuesta vieja: se ignora
      caja.innerHTML = `
        <div style="border:1px solid var(--linea);border-left:3px solid var(--azul);border-radius:12px;padding:12px 15px;font-size:13.5px">
          <strong>Así se aplica</strong>
          ${r.reparto.map(x => `
            <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px">
              <span>${x.anterior ? 'Deuda anterior' : `<span style="text-transform:capitalize">${esc(mesLargo(x.periodo))}</span>${x.adelanto ? ' <span class="suave">(adelanto)</span>' : ''}`}</span>
              <span class="cifra">${dinero(x.monto)}${x.recargo ? ` + ${dinero(x.recargo)} recargo` : ''}
                <span class="suave">${x.liquida ? '· liquida' : `· quedan ${dinero(x.queda)}`}</span></span>
            </div>`).join('')}
          <div style="margin-top:9px;padding-top:8px;border-top:1px solid var(--linea)">
            ${r.adeudo_despues > 0
              ? `Después de este cobro le quedan <strong class="rojo">${dinero(r.adeudo_despues)}</strong> por pagar.`
              : '<strong class="verde">Queda al corriente.</strong>'}
            ${r.aviso ? `<div class="rojo" style="margin-top:4px">${esc(r.aviso)}</div>` : ''}
          </div>
        </div>`;
    } catch (e) {
      if (mio === turno) caja.innerHTML = `<p class="rojo" style="margin:0">${esc(e.message)}</p>`;
    }
  };
  let espera;
  const programar = () => { clearTimeout(espera); espera = setTimeout(previa, 250); };
  // Colegiatura o inscripción: se muestra solo lo que corresponde; el método se comparte.
  const elegirQue = (que) => {
    queCobra = que;
    $$('#que-cobra button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.que === que)));
    $$('[data-cole]').forEach(x => x.classList.toggle('oculto', que !== 'colegiatura'));
    $$('[data-insc]').forEach(x => x.classList.toggle('oculto', que !== 'inscripcion'));
    const aceptar = $('[data-aceptar]');
    if (aceptar) aceptar.textContent = que === 'inscripcion' ? 'Registrar inscripción' : 'Registrar cobro';
    (que === 'inscripcion' ? $('#c-monto_inscripcion') : $('#c-monto'))?.focus();
  };
  $$('#que-cobra button').forEach(b => b.addEventListener('click', () => elegirQue(b.dataset.que)));
  $('#c-monto').addEventListener('input', programar);
  $('#condonar').addEventListener('change', previa);
  $$('[data-monto]').forEach(b => b.addEventListener('click', () => {
    $('#c-monto').value = Math.round(Number(b.dataset.monto) * 100) / 100;
    previa();
  }));
  previa();
}

/* ------------------------------------------------------------- Facturas */

/**
 * Facturas del mes (en Pagos): a quién hay que hacérsela, si ya pagó y si ya
 * se hizo. Se marca en el renglón, con el folio si se quiere.
 */
async function pintarFacturas(periodo) {
  const caja = $('#tarjeta-facturas');
  if (!caja) return;
  let lista;
  try { lista = await api(`facturas.php?periodo=${periodo}`); } catch { return; }
  if (!lista.length) { caja.innerHTML = ''; return; }
  const faltan = lista.filter(f => !f.hecha).length;
  const meses = [0, 1, 2].map(k => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - k);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; });
  caja.innerHTML = `
    <div class="tarjeta">
      <header style="gap:10px">
        <h2>Facturas <span class="suave" style="font-weight:400">· ${faltan ? `${faltan === 1 ? 'falta' : 'faltan'} ${faltan} de ${lista.length}` : 'todas hechas'}</span></h2>
        <div class="pestanas" id="mes-facturas">
          ${meses.map(p => `<button type="button" data-mes="${p}" aria-selected="${p === periodo}"
             style="text-transform:capitalize">${esc(mesLargo(p).split(' ')[0])}</button>`).join('')}
        </div>
      </header>
      <div class="tabla-scroll">
        <table style="min-width:680px">
          <thead><tr><th>Alumno</th><th>Datos fiscales</th><th>Pagó el mes</th><th>Factura</th><th></th></tr></thead>
          <tbody>${lista.map(f => `
            <tr>
              <td>${esc(nombreCompleto(f))}${f.familia ? `<div class="suave" style="font-size:12px">${esc(f.familia)}</div>` : ''}</td>
              <td class="suave" style="font-size:13px">${f.rfc ? esc(f.rfc) : '<span class="ambar">Sin RFC</span>'}${
                f.razon_social ? `<div>${esc(f.razon_social)}</div>` : ''}</td>
              <td class="cifra ${Number(f.pagado_mes) > 0 ? '' : 'suave'}">${Number(f.pagado_mes) > 0 ? dinero(f.pagado_mes) : 'Aún no'}</td>
              <td>${f.hecha ? `<span class="verde">Hecha</span>${f.folio ? `<div class="suave" style="font-size:12px">Folio ${esc(f.folio)}</div>` : ''}`
                            : '<span class="ambar">Pendiente</span>'}</td>
              <td class="derecha" style="white-space:nowrap">${f.hecha
                ? `<button class="pildora pildora-linea pildora-chica" data-solo-admin data-factura="${f.id_alumno}" data-hecha="0">Desmarcar</button>`
                : `<input class="campo" data-folio="${f.id_alumno}" placeholder="Folio (opcional)"
                          style="max-width:130px;padding:6px 10px;font-size:13px">
                   <button class="pildora pildora-azul pildora-chica" data-solo-admin data-factura="${f.id_alumno}" data-hecha="1">Marcar hecha</button>`}</td>
            </tr>`).join('')}</tbody>
        </table>
      </div>
    </div>`;
  $$('#mes-facturas [data-mes]').forEach(b => b.addEventListener('click', () => pintarFacturas(b.dataset.mes)));
  $$('[data-factura]', caja).forEach(b => b.addEventListener('click', async () => {
    const hecha = b.dataset.hecha === '1';
    try {
      await api('facturas.php', { method: 'PUT', cuerpo: { id_alumno: Number(b.dataset.factura), periodo, hecha: hecha ? 1 : 0,
        folio: hecha ? ($(`[data-folio="${b.dataset.factura}"]`, caja)?.value || null) : null } });
      aviso(hecha ? 'Factura marcada como hecha.' : 'Factura marcada como pendiente.', 'exito');
      pintarFacturas(periodo);
    } catch (e) { aviso(e.message, 'error'); }
  }));
}

/* ------------------------------------------------------------- Familias */

/** Lo que la ficha del alumno muestra de su familia (o la sugerencia de crearla) */
function bloqueFamilia(f) {
  const fam = f.familia;
  if (fam) {
    const hermanos = fam.miembros.filter(m => String(m.id_alumno) !== String(f.alumno.id_alumno));
    return `
      <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px;margin-top:14px">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
          <div>
            <strong>${esc(fam.nombre)}</strong>
            ${fam.responsable ? `<span class="suave">· paga ${esc(fam.responsable)}</span>` : ''}
            <div class="suave" style="font-size:13px;margin-top:3px">
              ${hermanos.length ? `Con ${esc(hermanos.map(h => h.nombre).join(', '))}` : 'Es el único alumno de la familia'}
              ${Number(fam.descuento_mensual) > 0 ? ` · descuento familiar de ${dinero(fam.descuento_mensual)} al mes` : ''}
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="pildora pildora-linea pildora-chica" data-escribe data-editar-familia>Editar familia</button>
            <button type="button" class="pildora pildora-azul pildora-chica" data-escribe data-cobrar-familia>Cobrar a la familia</button>
          </div>
        </div>
      </div>`;
  }
  const s = f.familia_sugerida;
  const otros = s ? s.alumnos.filter(x => String(x.id_alumno) !== String(f.alumno.id_alumno)) : [];
  return `
    <div style="border:1px dashed var(--linea);border-radius:12px;padding:12px 15px;margin-top:14px;font-size:13.5px;
                display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
      <span>${s
        ? `Parece hermano de <strong>${esc(otros.map(x => x.nombre).join(', '))}</strong> (mismo tutor).
           ${s.id_familia ? `Ellos ya son la ${esc(s.familia)}.` : ''}`
        : '<span class="suave">No está en una familia. Sirve para cobrarle junto a sus hermanos.</span>'}</span>
      <button type="button" class="pildora pildora-linea pildora-chica" data-escribe data-crear-familia>${
        s?.id_familia ? `Agregar a la ${esc(s.familia)}` : s ? 'Crear la familia' : 'Agregar a una familia'}</button>
    </div>`;
}

/**
 * Crear o editar una familia: quién paga, qué alumnos son y el descuento
 * acordado. El descuento se reparte entre sus alumnos por su colegiatura.
 *
 * @param {object|null} familia la que se edita
 * @param {object} previo {nombre, responsable, telefono, miembros, agregar}
 */
async function formularioFamilia(familia, previo = {}) {
  let alumnos;
  try { alumnos = await api('alumnos.php?activos=1'); }
  catch (e) { aviso(e.message, 'error'); return; }
  const v = { ...(familia || {}), ...previo };
  const marcados = new Set([...(familia ? familia.miembros.map(m => String(m.id_alumno)) : []),
                            ...(previo.miembros || []).map(String), ...(previo.agregar || []).map(String)]);
  const colegiaturaDe = (id) => Number(alumnos.find(a => String(a.id_alumno) === String(id))?.colegiatura) || 0;

  modal({
    titulo: familia ? `Editar ${familia.nombre}` : 'Nueva familia',
    textoAceptar: 'Guardar familia',
    ancho: '640px',
    extras: familia ? [{
      texto: 'Deshacer familia',
      alPulsar: async (_d, cerrar) => {
        if (!confirm(`¿Deshacer la ${familia.nombre}? Sus alumnos quedan sueltos y el descuento familiar deja de aplicarse. `
          + 'Sus pagos y lo que deben no cambian.')) return;
        await api(`familias.php?id=${familia.id_familia}`, { method: 'DELETE' });
        aviso('Familia deshecha.', 'exito');
        cerrar(); refrescar();
      }
    }] : [],
    campos: `
      <div class="fila-campos">
        ${campoTexto('nombre', 'Nombre de la familia', { valor: v.nombre ?? '', pista: 'Familia Pérez' })}
        ${campoTexto('responsable', 'Quién paga', { valor: v.responsable ?? '', pista: 'Padre, madre o tutor' })}
        ${campoTexto('telefono', 'Teléfono', { valor: v.telefono ?? '' })}
      </div>
      <div>
        <label class="etiqueta" for="buscar-miembro">Alumnos de la familia</label>
        <input class="campo" id="buscar-miembro" placeholder="Escribe para buscar y agregar" autocomplete="off">
        <div id="lista-miembros" class="fichas" style="margin-top:8px;max-height:190px;overflow-y:auto"></div>
      </div>
      <div class="fila-campos">
        ${campoTexto('descuento_mensual', 'Descuento familiar al mes', { tipo: 'number', paso: '0.01',
          valor: Number(v.descuento_mensual) > 0 ? v.descuento_mensual : '', pista: '0 si no hay' })}
        ${campoTexto('descuento_desde', 'Desde el mes', { tipo: 'month', valor: v.descuento_desde || periodoActual() })}
      </div>
      <div id="resumen-familia" class="vista-hueco suave"></div>
      ${campoTexto('notas', 'Notas', { valor: v.notas ?? '' })}`,
    alAceptar: async (datos, cerrar) => {
      const miembros = [...marcados].map(Number);
      if (!datos.nombre.trim()) { errorEnCampo($('#c-nombre'), 'Escribe el nombre de la familia.'); return; }
      if (!miembros.length) throw new Error('Marca al menos un alumno.');
      const cuerpo = { nombre: datos.nombre, responsable: datos.responsable, telefono: datos.telefono,
                       descuento_mensual: Number(datos.descuento_mensual) || 0,
                       descuento_desde: datos.descuento_desde || null, notas: datos.notas, miembros };
      await api(familia ? `familias.php?id=${familia.id_familia}` : 'familias.php',
                { method: familia ? 'PUT' : 'POST', cuerpo });
      aviso(familia ? 'Familia actualizada.' : 'Familia creada.', 'exito');
      cerrar();
      refrescar();
    }
  });

  const pintarMiembros = (filtro = '') => {
    const t = filtro.toLowerCase().trim();
    const lista = alumnos.filter(a => marcados.has(String(a.id_alumno))
      || (t && nombreCompleto(a).toLowerCase().includes(t))).slice(0, 40);
    $('#lista-miembros').innerHTML = lista.map(a => `
      <label><input type="checkbox" data-miembro="${a.id_alumno}" ${marcados.has(String(a.id_alumno)) ? 'checked' : ''}>
        <span>${esc(nombreCompleto(a))}</span></label>`).join('')
      || '<p class="suave" style="margin:0;font-size:13px">Escribe un nombre para agregarlo.</p>';
    $$('[data-miembro]').forEach(c => c.addEventListener('change', () => {
      if (c.checked) marcados.add(c.dataset.miembro); else marcados.delete(c.dataset.miembro);
      pintarResumen();
    }));
  };
  const pintarResumen = () => {
    const total = [...marcados].reduce((s, id) => s + colegiaturaDe(id), 0);
    const desc = Number($('#c-descuento_mensual').value) || 0;
    $('#resumen-familia').innerHTML = `${marcados.size} ${marcados.size === 1 ? 'alumno' : 'alumnos'} · sus clases suman
      <strong>${dinero(total)}</strong> al mes${desc > 0
        ? ` · con el descuento pagan <strong class="verde">${dinero(Math.max(0, total - desc))}</strong>.
           El descuento se reparte entre ellos según lo que paga cada uno.` : '.'}`;
  };
  $('#buscar-miembro').addEventListener('input', (e) => pintarMiembros(e.target.value));
  $('#c-descuento_mensual').addEventListener('input', pintarResumen);
  pintarMiembros();
  pintarResumen();
}

/** Elegir a qué familia se le cobra (desde Pagos) */
async function elegirFamiliaYCobrar() {
  let familias;
  try { familias = await api('familias.php'); }
  catch (e) { aviso(e.message, 'error'); return; }
  if (!familias.length) {
    aviso('Todavía no hay familias. Créalas desde la ficha de un alumno o desde Pendientes.', 'info');
    return;
  }
  modal({
    titulo: 'Cobrar a una familia',
    textoAceptar: 'Cerrar',
    sinCancelar: true,
    ancho: '520px',
    campos: `<ul class="lista-simple" style="display:block">${familias.map(f => `
      <li style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <span><strong>${esc(f.nombre)}</strong>
          <span class="suave">· ${esc(f.miembros.map(m => m.nombre).join(', '))}</span></span>
        <button type="button" class="pildora pildora-azul pildora-chica" data-escribe data-familia="${f.id_familia}">Cobrar</button>
      </li>`).join('')}</ul>`,
    alAceptar: (_d, cerrar) => cerrar()
  });
  $$('[data-familia]').forEach(b => b.addEventListener('click', () => cobrarFamilia(Number(b.dataset.familia))));
}

/**
 * Cobrar a una familia. Se ven todas las clases de todos los hijos con lo que
 * deben, marcadas de inicio; se desmarca la que no está pagando. Antes de
 * guardar se ve cómo se aplica el dinero.
 */
async function cobrarFamilia(idFamilia, conSiguiente = false) {
  let c;
  try { c = await api(`familias.php?id=${idFamilia}&accion=cuenta${conSiguiente ? '&siguiente=1' : ''}`); }
  catch (e) { aviso(e.message, 'error'); return; }
  const mesQueEntra = (() => { const [a, m] = periodoActual().split('-').map(Number);
    return m === 12 ? `${a + 1}-01` : `${a}-${String(m + 1).padStart(2, '0')}`; })();
  const recargoMes = Number(c.recargo_mes) || 0;
  const fam = c.familia;
  const clave = nuevaClave();   // una por cobro: solo viaja al guardar de verdad

  const fila = (a, f) => {
    const debe = Number(f.debe) > 0;
    return `
      <label style="display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-top:1px solid var(--linea);
                    ${debe ? '' : 'opacity:.55'}">
        <input type="checkbox" data-clase="${esc(f.clave)}" ${debe ? 'checked' : 'disabled'} style="margin-top:3px">
        <span style="flex:1;min-width:0">
          <strong>${esc(f.que)}</strong> <span class="suave">· ${esc(f.detalle || '')}</span>
          <span class="suave" style="display:block;font-size:12.5px">${debe
            ? f.meses.map(m => `${m.periodo === 'anterior' ? 'anterior' : mesLargo(m.periodo)} ${dinero(m.monto)}${
                m.vencido && !Number(m.recargo_cobrado) ? ' (vencido)' : m.adelanto ? ' (adelanto)' : ''}`).join(' · ')
            : 'Al corriente'}</span>
        </span>
        <span class="cifra ${debe ? '' : 'suave'}">${debe ? dinero(f.debe) : '—'}</span>
      </label>`;
  };

  modal({
    titulo: `Cobrar a la ${fam.nombre}`,
    textoAceptar: 'Registrar cobro',
    ancho: '680px',
    campos: `
      <p style="margin:0;font-size:14px">
        ${fam.responsable ? `Paga <strong>${esc(fam.responsable)}</strong> · ` : ''}
        clases por ${dinero(c.colegiatura)} al mes${Number(fam.descuento_mensual) > 0
          ? ` <span class="verde">− ${dinero(fam.descuento_mensual)} de descuento familiar</span>` : ''} ·
        ${c.adeudo > 0 ? `deben <strong class="rojo">${dinero(c.adeudo)}</strong>` : '<span class="verde">al corriente</span>'}
      </p>
      <p class="suave" style="margin:0;font-size:13px">
        Están marcadas todas las clases que deben. Desmarca la que no esté pagando.
      </p>
      <label style="display:flex;gap:8px;align-items:center;font-size:13.5px">
        <input type="checkbox" id="con-siguiente" ${conSiguiente ? 'checked' : ''}>
        Incluir ${esc(mesLargo(mesQueEntra))} como adelanto
      </label>
      ${c.alumnos.map(a => `
        <div style="border:1px solid var(--linea);border-radius:12px;padding:6px 14px 4px">
          <div style="display:flex;justify-content:space-between;padding:6px 0">
            <strong>${esc(nombreCompleto(a))}</strong>
            <span class="suave" style="font-size:13px">${a.adeudo > 0 ? `debe ${dinero(a.adeudo)}` : 'al corriente'}</span>
          </div>
          ${a.filas.map(f => fila(a, f)).join('') || '<p class="suave" style="margin:4px 0 8px">Sin clases.</p>'}
        </div>`).join('')}
      <div id="total-familia" class="vista-hueco"></div>
      <div class="fila-campos">
        <div>
          <label class="etiqueta" for="c-recibido">¿Cuánto trae?</label>
          <input class="campo" id="c-recibido" name="recibido" type="number" step="0.01" min="0">
        </div>
        ${campoSelect('metodo', 'Método', METODOS_PAGO)}
      </div>
      <label style="display:flex;gap:8px;align-items:center;font-size:13.5px">
        <input type="checkbox" id="condonar-familia"> Perdonar los recargos esta vez
      </label>
      <div id="reparto-familia"></div>`,
    alAceptar: async (datos, cerrar) => {
      const clases = $$('[data-clase]:checked').map(x => x.dataset.clase);
      const monto = Number($('#c-recibido').value);
      if (!clases.length) throw new Error('Marca al menos una clase que esté pagando.');
      if (!(monto > 0)) { errorEnCampo($('#c-recibido'), 'Escribe cuánto trae.'); return; }
      const r = await api('familias.php?accion=cobrar', {
        cuerpo: { id_familia: idFamilia, clases, monto, metodo: datos.metodo,
                  condonar_recargo: $('#condonar-familia').checked ? 1 : 0, siguiente: conSiguiente ? 1 : 0, clave }
      });
      // Ya se había registrado (doble clic o red lenta): no se cobra de nuevo.
      if (r.repetido) {
        aviso(r.aviso, 'info');
        cerrar();
        refrescar();
        return;
      }
      aviso(`Cobro a la familia, folio ${r.folio}: ${r.adeudo_despues > 0
        ? `le quedan ${dinero(r.adeudo_despues)} por pagar.` : 'queda al corriente.'}`, 'exito');
      const conFactura = c.alumnos.filter(x => Number(x.requiere_factura) === 1).map(x => x.nombre);
      if (conFactura.length) {
        aviso(`Requieren factura: ${conFactura.join(', ')}. Márcalas en Pagos → Facturas del mes cuando las hagas.`, 'info');
      }
      cerrar();
      refrescar();
    }
  });

  // Total de lo marcado, con los recargos que tocan (uno por mes vencido de cada hijo).
  const sugerido = () => {
    const marcadas = new Set($$('[data-clase]:checked').map(x => x.dataset.clase));
    let total = 0; const conRecargo = new Set();
    c.alumnos.forEach(a => a.filas.forEach(f => {
      if (!marcadas.has(f.clave)) return;
      f.meses.forEach(m => {
        total += Number(m.monto);
        if (m.vencido && !Number(m.recargo_cobrado)) conRecargo.add(`${a.id_alumno}|${m.periodo}`);
      });
    }));
    const recargos = $('#condonar-familia').checked ? 0 : conRecargo.size * recargoMes;
    return { total: Math.round(total * 100) / 100, recargos };
  };
  const pintarTotal = () => {
    const s = sugerido();
    $('#total-familia').innerHTML = `Lo marcado suma <strong>${dinero(s.total)}</strong>${
      s.recargos ? ` + <span class="rojo">${dinero(s.recargos)}</span> de recargos = <strong>${dinero(s.total + s.recargos)}</strong>` : ''}.`;
    $('#c-recibido').value = (s.total + s.recargos).toFixed(2);
    previa();
  };

  let turno = 0, espera;
  const previa = async () => {
    const clases = $$('[data-clase]:checked').map(x => x.dataset.clase);
    const monto = Number($('#c-recibido').value);
    const caja = $('#reparto-familia');
    if (!caja) return;
    if (!clases.length || !(monto > 0)) { caja.innerHTML = ''; return; }
    const mio = ++turno;
    try {
      const r = await api('familias.php?accion=cobrar', {
        cuerpo: { id_familia: idFamilia, clases, monto, metodo: $('#c-metodo').value,
                  condonar_recargo: $('#condonar-familia').checked ? 1 : 0, simular: 1, siguiente: conSiguiente ? 1 : 0 }
      });
      if (mio !== turno) return;
      caja.innerHTML = `
        <div style="border:1px solid var(--linea);border-left:3px solid var(--azul);border-radius:12px;padding:12px 15px;font-size:13.5px">
          <strong>Así se aplica</strong>
          ${r.reparto.map(x => `
            <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px">
              <span>${esc(x.quien)} · ${esc(x.que)} · <span style="text-transform:capitalize">${
                x.periodo === 'anterior' ? 'deuda anterior' : esc(mesLargo(x.periodo))}</span>${
                x.adelanto ? ' <span class="suave">(adelanto)</span>' : ''}</span>
              <span class="cifra">${dinero(x.monto)}${x.recargo ? ` + ${dinero(x.recargo)} recargo` : ''}
                <span class="suave">${x.liquida ? '· liquida' : `· quedan ${dinero(x.queda)}`}</span></span>
            </div>`).join('')}
          <div style="margin-top:9px;padding-top:8px;border-top:1px solid var(--linea)">
            ${r.adeudo_despues > 0
              ? `Después de este cobro a la familia le quedan <strong class="rojo">${dinero(r.adeudo_despues)}</strong>.`
              : '<strong class="verde">La familia queda al corriente.</strong>'}
            ${r.aviso ? `<div class="rojo" style="margin-top:4px">${esc(r.aviso)}</div>` : ''}
          </div>
        </div>`;
    } catch (e) {
      if (mio === turno) caja.innerHTML = `<p class="rojo" style="margin:0">${esc(e.message)}</p>`;
    }
  };
  $$('[data-clase]').forEach(x => x.addEventListener('change', pintarTotal));
  $('#condonar-familia').addEventListener('change', pintarTotal);
  // Con o sin el mes que entra, la cuenta cambia: se vuelve a abrir con la nueva.
  $('#con-siguiente').addEventListener('change', (e) => cobrarFamilia(idFamilia, e.target.checked));
  $('#c-recibido').addEventListener('input', () => { clearTimeout(espera); espera = setTimeout(previa, 250); });
  pintarTotal();
}

/** Estado de cuenta en una ventana: mes por mes, con lo abonado y lo que falta */
async function estadoDeCuenta(alumno) {
  let c;
  try { c = await api(`pagos.php?accion=cuenta&alumno=${alumno.id_alumno}`); }
  catch (e) { aviso(e.message, 'error'); return; }
  const soloVer = rolActual() === 'consulta';
  const sinConfirmar = c.meses.filter(m => m.estado === 'sin_confirmar');
  modal({
    titulo: `Estado de cuenta · ${nombreCompleto(alumno)}`,
    textoAceptar: soloVer ? 'Cerrar' : 'Cobrar',
    // Quien solo mira ve un único botón "Cerrar"; quien cobra, "Cerrar" y "Cobrar" (nunca "Cancelar" en una ventana de lectura).
    soloLectura: soloVer,
    textoCancelar: 'Cerrar',
    ancho: '620px',
    campos: tablaCuenta(c),
    extras: c.meses_sin_confirmar > 0 && esAdmin() ? [{
      texto: '¿Hasta qué mes pagó?',
      alPulsar: (_d, cerrar) => {
        cerrar();
        formularioHastaQueMes({ ...alumno, ...(c.alumno || {}) },
          sinConfirmar.map(m => ({ periodo: m.periodo, cargo: m.cargo })), c.colegiatura);
      }
    }] : [],
    alAceptar: (_d, cerrar) => {
      cerrar();
      if (!soloVer) registrarPago(alumno, null, () => refrescar());
    }
  });
}

/** "abril a agosto · 5 meses" (el año solo se dice si no es el actual) */
function rangoMeses(meses) {
  const n = meses.length;
  const cuenta = `${n} ${n === 1 ? 'mes' : 'meses'}`;
  const nombre = (p) => p.slice(0, 4) === periodoActual().slice(0, 4) ? mesLargo(p).split(' ')[0] : mesLargo(p);
  if (n === 1) return `${nombre(meses[0].periodo)} · ${cuenta}`;
  return `${nombre(meses[0].periodo)} a ${nombre(meses[n - 1].periodo)} · ${cuenta}`;
}

/** "abril a agosto" → "Abril a agosto" (solo la primera letra). */
const primeraMayuscula = (t) => t.charAt(0).toUpperCase() + t.slice(1);

/**
 * ¿Hasta qué mes pagó? Los meses de antes de septiembre no tienen registro de
 * pago. Se elige el ÚLTIMO mes que sí pagó: los anteriores quedan pagados por
 * lo que paga el alumno y los siguientes quedan como deuda. Antes de guardar
 * se ve cuántos meses y cuánto dinero es cada cosa.
 * @param {Object} alumno {id_alumno, nombre, apellido}
 * @param {Array} meses [{periodo, cargo}] de más viejo a más nuevo
 */
function formularioHastaQueMes(alumno, meses, colegiatura, alTerminar) {
  const lista = (meses || []).slice().sort((a, b) => a.periodo.localeCompare(b.periodo));
  if (!lista.length) { aviso('Este alumno no tiene meses por confirmar.', 'info'); return; }
  const cuantos = (n) => `${n} ${n === 1 ? 'mes' : 'meses'}`;
  const cargoDe = (m) => Number(m.cargo ?? colegiatura) || 0;
  const suma = (l) => l.reduce((s, m) => s + cargoDe(m), 0);

  modal({
    titulo: `¿Hasta qué mes pagó ${nombreCompleto(alumno)}?`,
    textoAceptar: 'Confirmar',
    ancho: '540px',
    campos: `
      <p style="margin:0;font-size:14px">
        De estos meses, anteriores a septiembre, no hay registro de si se pagaron.
        Elige el <strong>último mes que sí pagó</strong>: los anteriores quedan pagados por lo que paga cada mes
        y los siguientes quedan como deuda.
      </p>
      ${campoSelect('hasta', 'Último mes que pagó', [
        { valor: '', texto: 'No pagó ninguno' },
        ...lista.map(m => ({ valor: m.periodo, texto: mesLargo(m.periodo) }))
      ], lista[lista.length - 1].periodo)}
      <div id="resumen-hasta" class="vista-hueco"></div>`,
    alAceptar: async (datos, cerrar) => {
      const r = await api('pagos.php?accion=confirmar_historico', {
        cuerpo: { id_alumno: Number(alumno.id_alumno), hasta: datos.hasta || null }
      });
      const p = Number(r.pagados) || 0, d = Number(r.adeudados) || 0;
      aviso(p && d ? `Listo: ${cuantos(p)} ${p === 1 ? 'pagado' : 'pagados'} y ${cuantos(d)} como deuda.`
          : p ? `Listo: ${cuantos(p)} ${p === 1 ? 'quedó pagado' : 'quedaron pagados'}.`
          : `Listo: ${cuantos(d)} ${d === 1 ? 'quedó' : 'quedaron'} como deuda.`, 'exito');
      cerrar();
      (alTerminar || refrescar)();
    }
  });

  const pintar = () => {
    const hasta = $('#c-hasta').value;
    const pagados = lista.filter(m => hasta && m.periodo <= hasta);
    const debe = lista.filter(m => !hasta || m.periodo > hasta);
    $('#resumen-hasta').innerHTML = `
      <div>Quedan pagados: <strong>${cuantos(pagados.length)}</strong>${pagados.length ? ` (total ${dinero(suma(pagados))})` : ''}</div>
      <div style="margin-top:4px">Queda debiendo: <strong class="${debe.length ? 'rojo' : ''}">${cuantos(debe.length)}</strong>${
        debe.length ? ` (total ${dinero(suma(debe))})` : ''}</div>`;
  };
  $('#c-hasta').addEventListener('change', pintar);
  pintar();
}

/* ------------------------------------------------ Corrección de pagos */

const METODOS_PAGO = [
  { valor: 'efectivo', texto: 'Efectivo' },
  { valor: 'transferencia', texto: 'Transferencia' },
  { valor: 'tarjeta', texto: 'Tarjeta' },
];

/** Cómo se llama cada concepto de un pago (la inscripción no es colegiatura) */
const ETIQUETA_CONCEPTO = {
  colegiatura: 'Colegiatura', inscripcion: 'Inscripción', clase_muestra: 'Clase muestra',
  material: 'Material', otro: 'Otro', adeudo_anterior: 'Deuda anterior'
};

const etiquetaMetodo = (m) => m === 'por_confirmar'
  ? '<span class="ambar">Por confirmar</span>'
  : esc((METODOS_PAGO.find(x => x.valor === m) || { texto: m || '—' }).texto);

/** Marca junto al importe de un pago cuyo monto no se ha confirmado */
const marcaMonto = (p) => Number(p.monto_confirmado) === 0
  ? '<div class="ambar" style="font-size:11.5px;font-weight:500">monto por confirmar</div>' : '';

/**
 * Marca un campo con error en su lugar (borde rojo y el motivo debajo) y lo
 * enfoca. Se limpia solo en cuanto se cambia el campo.
 */
function errorEnCampo(campo, mensaje, enfocar = true) {
  if (!campo) return;
  const caja = campo.parentElement;
  caja.querySelector('.error-campo')?.remove();
  campo.classList.add('con-error');
  caja.insertAdjacentHTML('beforeend', `<div class="error-campo">${esc(mensaje)}</div>`);
  if (enfocar) campo.focus();
  const limpiar = () => { campo.classList.remove('con-error'); caja.querySelector('.error-campo')?.remove(); };
  campo.addEventListener('input', limpiar, { once: true });
  campo.addEventListener('change', limpiar, { once: true });
}

/** Importe de un pago; si se anuló, va tachado y con su etiqueta */
const importePago = (p) => Number(p.anulado) === 1
  ? `<span style="text-decoration:line-through">${dinero(p.monto)}</span> <span class="etiqueta-estado suave">Anulado</span>`
  : dinero(p.monto);

/** Un pago anulado o una anulación ya no se corrigen: no lleva botón */
const sePuedeCorregir = (p) => Number(p.anulado) !== 1 && !p.anula_a;

/** Texto que explica de dónde sale el importe propuesto */
const deDondeSugerido = (p) => ({
  cargo: 'lo que debía ese mes', colegiatura: 'su colegiatura', lista: 'la lista de precios'
}[p.fuente] || 'lo registrado');

/**
 * Corregir o confirmar un pago. Nada se sobreescribe en silencio: el sistema
 * guarda el valor anterior, el nuevo, quién y por qué, y aquí mismo se ve la
 * bitácora. Un importe que ya estaba confirmado solo se cambia diciendo el motivo.
 *
 * Un pago por confirmar abre con lo que el alumno paga (su colegiatura o el
 * cargo de ese mes), no con el importe importado, que a veces era lo que se le
 * paga al maestro. Si entregó otra cantidad, se escribe y listo.
 */
async function formularioCorregirPago(pago, alTerminar) {
  const confirmado = Number(pago.monto_confirmado) === 1;
  const [bitacora, pista] = await Promise.all([
    api(`pagos.php?accion=cambios&id=${pago.id_pago}`).catch(() => []),
    confirmado || pago.sugerido !== undefined
      ? Promise.resolve(pago)
      : api(`pagos.php?accion=por_confirmar&id=${pago.id_pago}`).catch(() => null)
  ]);
  const sugerido = !confirmado && pista?.sugerido ? Number(pista.sugerido) : null;
  const importado = Number(pago.monto);
  const difiere = sugerido !== null && Math.abs(sugerido - importado) > 0.01;
  // Su precio registrado es el pago del maestro: no se propone, se pregunta.
  const precioDudoso = !confirmado && pista && pista.sugerido === null;
  const unaClase = Number(pista?.clases_vivas) === 1;

  modal({
    titulo: confirmado ? 'Corregir pago' : 'Confirmar pago',
    textoAceptar: confirmado ? 'Guardar corrección' : 'Confirmar pago',
    ancho: '540px',
    extras: esAdmin() && sePuedeCorregir(pago) ? [{
      texto: 'Anular pago',
      alPulsar: (_d, cerrar) => { cerrar(); formularioAnularPago(pago, alTerminar); }
    }] : [],
    campos: `
      <p style="margin:0;font-size:14px">
        <strong>${esc(pago.quien || nombreCompleto(pago) || '')}</strong> ·
        <span style="text-transform:capitalize">${esc(mesLargo(pago.periodo))}</span>
        ${pago.fecha_pago ? `<span class="suave">· registrado el ${esc(pago.fecha_pago)}</span>` : ''}
      </p>
      ${confirmado ? '' : `
        <div class="vista-hueco" style="border-left:3px solid var(--ambar)">
          Se sabe que pagó este mes; falta anotar <strong>cuánto</strong> y <strong>cómo</strong>.
          ${sugerido !== null ? `<div style="margin-top:6px">
            Te propongo <strong>${dinero(sugerido)}</strong>, que es ${deDondeSugerido(pista)}.
            ${difiere ? `<span class="suave">La importación había puesto ${dinero(importado)}.</span>` : ''}
            Si entregó otra cantidad, cámbiala.</div>` : ''}
          ${precioDudoso ? `<div style="margin-top:6px">${esAdmin() ? `
            Su colegiatura registrada (<strong>${dinero(pista.colegiatura)}</strong>) es igual a lo que se le paga al
            maestro: seguramente vino así del Excel de nómina. Escribe lo que de verdad pagó.` : `
            Su colegiatura registrada (<strong>${dinero(pista.colegiatura)}</strong>) parece no ser la correcta:
            escribe lo que de verdad pagó.`}</div>` : ''}
        </div>`}
      <div class="fila-campos">
        ${campoTexto('monto', confirmado ? 'Importe' : 'Cuánto pagó',
          { tipo: 'number', paso: '0.01', valor: precioDudoso ? '' : (sugerido ?? importado).toFixed(2) })}
        ${campoSelect('metodo', 'Cómo pagó',
          pago.metodo === 'por_confirmar'
            ? [{ valor: '', texto: '— Elige —' }, ...METODOS_PAGO] : METODOS_PAGO,
          pago.metodo === 'por_confirmar' ? '' : pago.metodo)}
      </div>
      ${precioDudoso && unaClase ? `
        <label style="display:flex;gap:8px;align-items:center;font-size:13.5px">
          <input type="checkbox" id="c-actualizar-colegiatura" checked>
          Es lo que paga cada mes: corregir también su colegiatura
        </label>` : ''}
      ${campoTexto('motivo', confirmado ? 'Motivo de la corrección' : 'Nota (opcional)',
        { pista: confirmado ? 'Obligatorio si cambias el importe' : 'Recibo, transferencia, etc.' })}
      ${bitacora.length ? `
        <div>
          <label class="etiqueta">Cambios anteriores</label>
          <div style="border:1px solid var(--linea);border-radius:12px;overflow:hidden;font-size:13px">
            ${bitacora.map(b => `
              <div style="padding:8px 13px;border-bottom:1px solid var(--linea)">
                <strong>${esc({ monto: 'Importe', metodo: 'Método', monto_confirmado: 'Confirmación' }[b.campo] || b.campo)}</strong>:
                ${esc(b.campo === 'monto_confirmado' ? 'por confirmar' : b.valor_anterior ?? '—')}
                → ${esc(b.campo === 'monto_confirmado' ? 'confirmado' : b.valor_nuevo ?? '—')}
                <span class="suave">· ${esc(b.usuario || '')} · ${esc(String(b.fecha).slice(0, 16))}
                ${b.motivo ? `· «${esc(b.motivo)}»` : ''}</span>
              </div>`).join('')}
          </div>
        </div>` : ''}`,
    alAceptar: async (datos, cerrar) => {
      if (!(Number(datos.monto) > 0)) { errorEnCampo($('#c-monto'), 'Escribe cuánto pagó.'); return; }
      if (!datos.metodo) { errorEnCampo($('#c-metodo'), 'Elige cómo pagó: efectivo, transferencia o tarjeta.'); return; }
      if (confirmado && Math.abs(Number(datos.monto) - importado) > 0.009 && !datos.motivo.trim()) {
        errorEnCampo($('#c-motivo'), 'Este importe ya estaba confirmado: escribe por qué se corrige.');
        return;
      }
      const cuerpo = { monto: datos.monto, metodo: datos.metodo, motivo: datos.motivo || null };
      if (!confirmado) cuerpo.confirmar = 1;
      if ($('#c-actualizar-colegiatura')?.checked) cuerpo.actualizar_colegiatura = 1;
      const r = await api(`pagos.php?id=${pago.id_pago}`, { method: 'PUT', cuerpo });
      aviso(r.cambios
        ? (confirmado ? 'Pago corregido. Queda registrado en su bitácora.' : 'Pago confirmado.')
        : 'No había nada que cambiar.', 'exito');
      cerrar();
      (alTerminar || refrescar)();
    }
  });
}
/**
 * Anular un pago (solo administración). No se borra nada: queda en el historial
 * marcado como anulado y deja de contar en la cuenta del alumno. Se pide el motivo.
 */
function formularioAnularPago(pago, alTerminar) {
  modal({
    titulo: 'Anular este pago',
    textoAceptar: 'Anular pago',
    ancho: '540px',
    campos: `
      <p style="margin:0;font-size:14px">
        <strong>${esc(pago.quien || nombreCompleto(pago) || '')}</strong> ·
        <span style="text-transform:capitalize">${esc(mesLargo(pago.periodo))}</span> ·
        <strong>${dinero(pago.monto)}</strong>
      </p>
      <div class="vista-hueco" style="border-left:3px solid var(--ambar)">
        El pago <strong>no se borra</strong>: queda en el historial marcado como anulado y deja de contar en la
        cuenta del alumno. En el reporte de ingresos la anulación resta en el mes de hoy; los meses pasados no cambian.
        ${pago.concepto === 'clase_muestra' ? `<div style="margin-top:6px">Lo que se sumó a la nómina del maestro por esta
          clase muestra no se quita solo: si hace falta, se quita aparte en Nómina.</div>` : ''}
      </div>
      ${campoTexto('motivo', 'Por qué se anula', { pista: 'Se cobró dos veces, era de otro alumno…' })}`,
    alAceptar: async (datos, cerrar) => {
      const motivo = (datos.motivo || '').trim();
      if (motivo.length < 4) { errorEnCampo($('#c-motivo'), 'Escribe brevemente por qué se anula.'); return; }
      await api(`pagos.php?id=${pago.id_pago}&accion=anular`, { method: 'PUT', cuerpo: { motivo } });
      aviso('Pago anulado. Queda en el historial.', 'exito');
      cerrar();
      (alTerminar || refrescar)();
    }
  });
}

/** Tabla de estado de cuenta, reutilizada en la ficha del alumno */
/** "Septiembre: 2 clases a $900 y 2 a $450." para los meses en que una clase cambió de precio */
function lineasDeReparto(meses) {
  const lineas = [];
  for (const m of (meses || []).slice().reverse()) {
    for (const clase of (m.reparto || [])) {
      // "2 clases a $900 y 2 a $450": la palabra "clases" solo va en la primera parte.
      const partes = clase.map((t, i) => `${t.clases}${i === 0 ? (t.clases === 1 ? ' clase' : ' clases') : ''} a ${dinero(t.precio)}`);
      const texto = partes.length > 1 ? `${partes.slice(0, -1).join(', ')} y ${partes[partes.length - 1]}` : partes[0];
      lineas.push(`<p class="suave" style="margin:0;font-size:13px">${esc(primeraMayuscula(mesLargo(m.periodo).split(' ')[0]))}: ${esc(texto)}.</p>`);
    }
  }
  return lineas.join('');
}

function tablaCuenta(c) {
  const etiqueta = { pagado: ['Pagado', 'verde'], abonado: ['Abonado', 'ambar'], pendiente: ['Debe', 'rojo'],
                     adelantado: ['Adelantado', 'suave'], sin_cargo: ['—', 'suave'],
                     // Se sabe que pagó ese mes, pero no cuánto: no genera deuda.
                     por_confirmar: ['Pagado · monto por confirmar', 'ambar'],
                     // Antes del corte y sin registro: no es deuda hasta que se confirme.
                     sin_confirmar: ['Por confirmar', 'ambar'] };
  return `
    <p style="margin:0;font-size:14px">
      Colegiatura de <strong>${dinero(c.colegiatura)}</strong> al mes ·
      ${c.adeudo > 0 ? `debe <strong class="rojo">${dinero(c.adeudo)}</strong> (${c.meses_debe} ${c.meses_debe === 1 ? 'mes' : 'meses'})`
                     : '<strong class="verde">al corriente</strong>'}
    </p>
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Mes</th><th class="derecha">Cargo</th><th class="derecha">Abonado</th><th class="derecha">Falta</th><th>Estado</th></tr></thead>
        <tbody>
          ${c.anterior?.monto > 0 ? `
            <tr>
              <td>Deuda anterior <div class="suave" style="font-size:12px">${esc(c.anterior.nota || '')}</div></td>
              <td class="derecha cifra">${dinero(c.anterior.monto)}</td>
              <td class="derecha cifra">${dinero(c.anterior.abonado)}</td>
              <td class="derecha cifra ${c.anterior.saldo > 0 ? 'rojo' : ''}">${dinero(c.anterior.saldo)}</td>
              <td>${c.anterior.saldo > 0 ? '<span class="rojo">Debe</span>' : '<span class="verde">Pagado</span>'}</td>
            </tr>` : ''}
          ${c.meses.slice().reverse().map(m => m.estado === 'sin_confirmar' ? `
            <tr>
              <td style="text-transform:capitalize">${esc(mesLargo(m.periodo))}</td>
              <td class="derecha cifra">${dinero(m.cargo)}</td>
              <td class="derecha cifra suave">—</td>
              <td class="derecha cifra suave">—</td>
              <td><span class="${etiqueta.sin_confirmar[1]}">${etiqueta.sin_confirmar[0]}</span></td>
            </tr>` : `
            <tr>
              <td style="text-transform:capitalize">${esc(mesLargo(m.periodo))}</td>
              <td class="derecha cifra">${dinero(m.cargo)}</td>
              <td class="derecha cifra">${dinero(m.abonado)}${m.recargo_cobrado ? `<div class="suave" style="font-size:12px">+${dinero(m.recargo_cobrado)} recargo</div>` : ''}</td>
              <td class="derecha cifra ${m.saldo > 0 && !m.futuro ? 'rojo' : ''}">${dinero(m.saldo)}</td>
              <td><span class="${etiqueta[m.estado]?.[1] || ''}">${etiqueta[m.estado]?.[0] || m.estado}</span></td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>
    ${c.inscripcion && Number(c.inscripcion.pagada) > 0 ? `<p class="suave" style="margin:0;font-size:13px">
      Inscripción pagada: ${dinero(c.inscripcion.pagada)}${c.inscripcion.fecha ? ` (${esc(c.inscripcion.fecha)})` : ''}.
      No cuenta como abono a ningún mes.</p>` : ''}
    ${lineasDeReparto(c.meses)}
    ${c.meses_sin_confirmar > 0 ? `
      <p class="suave" style="margin:0;font-size:13px">
        Los meses marcados «Por confirmar» son de antes de septiembre y no hay registro de si se pagaron.
        No cuentan como deuda hasta que se confirme hasta qué mes pagó.
      </p>` : ''}`;
}

/* ====================================================================
   VISTA · ALUMNOS
   ==================================================================== */

vistas.alumnos = async () => {
  const alumnos = await api('alumnos.php');

  vista.innerHTML = `
    <div class="tarjeta">
      <header>
        <h2>Alumnos <span class="suave" style="font-weight:400">· ${alumnos.length}</span></h2>
        <div class="barra-busqueda">
          <input class="campo" id="buscar" placeholder="Buscar por nombre, materia o maestro">
          <button class="pildora pildora-azul pildora-chica" data-escribe id="b-nuevo">Nuevo alumno</button>
        </div>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Nombre</th><th>Clases</th><th class="derecha">Al mes</th>
            <th>Contacto</th><th>Estado</th><th></th>
          </tr></thead>
          <tbody id="cuerpo-tabla"></tbody>
        </table>
      </div>
      <div class="cuerpo suave" style="border-top:1px solid var(--linea);font-size:13px">
        Haz clic en cualquier alumno para abrir su ficha: datos de contacto, padre o tutor,
        sus clases una por una y su historial de pagos.
      </div>
    </div>`;

  const pintar = (lista) => {
    $('#cuerpo-tabla').innerHTML = lista.length ? lista.map(a => `
      <tr class="clicable" data-ficha="${a.id_alumno}">
        <td>
          ${esc(nombreCompleto(a))}
          ${a.edad !== null && a.edad !== undefined && Number(a.edad) < 18
            ? `<span class="suave" style="font-size:12.5px"> · ${a.edad} años</span>` : ''}
          ${a.tutor_nombre ? `<div class="suave" style="font-size:12.5px">Tutor: ${esc(a.tutor_nombre)}</div>` : ''}
        </td>
        <td>${a.clases.length
              ? a.clases.map(c => `
                  <div style="font-size:13.5px;line-height:1.45">
                    ${esc(c.materia)}
                    <span class="suave">con ${esc(c.maestro)}</span>
                    ${c.dia
                      ? `<span class="suave">· ${esc(c.dia)} ${esc(c.hora_inicio)}–${esc(c.hora_fin)}${c.salon ? ' · ' + esc(c.salon) : ''}</span>`
                      : '<span class="ambar">· sin horario</span>'}
                  </div>`).join('')
              : '<span class="suave">Sin clases</span>'}</td>
        <td class="derecha cifra">${Number(a.colegiatura) > 0 ? dinero(a.colegiatura) : '<span class="suave">—</span>'}</td>
        <td class="suave" style="font-size:13.5px">
          ${esc(a.telefono || a.tutor_telefono || '—')}
        </td>
        <td>${Number(a.activo)
              ? '<span class="etiqueta-estado verde">Activo</span>'
              : '<span class="etiqueta-estado suave">Baja</span>'}</td>
        <td class="derecha" style="white-space:nowrap">
          <button class="pildora pildora-linea pildora-chica" data-escribe data-editar="${a.id_alumno}">Editar</button>
          ${Number(a.activo)
            ? `<button class="pildora pildora-roja pildora-chica" data-escribe data-baja="${a.id_alumno}">Baja</button>`
            : `<button class="pildora pildora-linea pildora-chica" data-escribe data-alta="${a.id_alumno}">Reactivar</button>`}
        </td>
      </tr>`).join('') : `<tr><td colspan="6"><div class="mensaje-vacio">Sin resultados.</div></td></tr>`;

    const buscar = (id) => alumnos.find(x => String(x.id_alumno) === id);

    $$('[data-ficha]').forEach(fila => fila.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;      // el botón Editar tiene lo suyo
      fichaAlumno(Number(fila.dataset.ficha));
    }));

    $$('[data-editar]').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      formularioAlumno(buscar(b.dataset.editar));
    }));

    $$('[data-baja]').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      formularioBajaAlumno(buscar(b.dataset.baja));
    }));

    $$('[data-alta]').forEach(b => b.addEventListener('click', async (e) => {
      e.stopPropagation();
      try {
        await api(`alumnos.php?accion=reactivar&id=${b.dataset.alta}`, { method: 'PUT' });
        aviso(`Reactivación lista: ${primerNombre(buscar(b.dataset.alta)?.nombre)}. Falta darle sus clases.`, 'exito');
        refrescar();
      } catch (err) { aviso(err.message, 'error'); }
    }));
  };

  pintar(alumnos);

  $('#buscar').addEventListener('input', (e) => {
    const t = e.target.value.toLowerCase().trim();
    pintar(alumnos.filter(a =>
      nombreCompleto(a).toLowerCase().includes(t) ||
      (a.tutor_nombre || '').toLowerCase().includes(t) ||
      a.clases.some(c => `${c.materia} ${c.maestro} ${c.dia}`.toLowerCase().includes(t))
    ));
  });

  $('#b-nuevo').addEventListener('click', () => formularioAlumno(null));
};

/** Ficha del alumno: todo lo suyo en un solo lugar, con acciones por clase */
async function fichaAlumno(id) {
  const f = await api(`alumnos.php?accion=ficha&id=${id}`);
  const a = f.alumno;
  const vivas = f.clases.filter(c => c.estado !== 'baja');

  const dato = (etiqueta, valor) => valor
    ? `<div class="ficha-dato"><dt>${esc(etiqueta)}</dt><dd>${esc(valor)}</dd></div>` : '';

  modal({
    titulo: nombreCompleto(a),
    textoAceptar: 'Cerrar',
    soloLectura: true,
    ancho: '720px',
    alAceptar: (_d, cerrar) => cerrar(),
    campos: `
      <div class="pestanas" style="margin-bottom:4px">
        <button type="button" data-tab="datos" aria-selected="true">Datos</button>
        <button type="button" data-tab="clases" aria-selected="false">Clases (${vivas.length})</button>
        <button type="button" data-tab="pagos" aria-selected="false">Pagos (${f.pagos.length})</button>
      </div>

      <div data-panel="datos">
        <dl style="margin:0">
          ${dato('Teléfono', a.telefono)}
          ${dato('Correo', a.email)}
          ${dato('Nacimiento', a.fecha_nacimiento)}
          ${dato('Padre o tutor', a.tutor_nombre)}
          ${dato('Parentesco', a.tutor_parentesco)}
          ${dato('Teléfono del tutor', a.tutor_telefono)}
          ${dato('Domicilio', a.direccion)}
          ${dato('Notas', a.notas)}
          <div class="ficha-dato"><dt>Colegiatura</dt><dd class="cifra">${dinero(f.colegiatura)} al mes${
            f.descuento_familiar ? ` <span class="verde">− ${dinero(f.descuento_familiar.monto)} de descuento familiar</span>` : ''}</dd></div>
        </dl>
        ${bloqueFamilia(f)}
        ${Number(a.requiere_factura) === 1 ? (() => {
          const mes = (f.facturas || []).find(x => x.periodo === periodoActual());
          const hecha = mes && Number(mes.hecha) === 1;
          return `
          <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px;margin-top:14px;font-size:13.5px;
                      display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
            <span><strong>Requiere factura</strong>
              <span class="suave">· ${esc([a.rfc, a.razon_social].filter(Boolean).join(' · ') || 'faltan sus datos fiscales')}</span>
              <span style="display:block;margin-top:3px" class="${hecha ? 'verde' : 'ambar'}">
                Factura de ${esc(mesLargo(periodoActual()))}: ${hecha ? `hecha${mes.folio ? ' · folio ' + esc(mes.folio) : ''}` : 'pendiente'}</span></span>
            <button type="button" class="pildora ${hecha ? 'pildora-linea' : 'pildora-azul'} pildora-chica"
                    data-solo-admin data-factura-mes="${hecha ? 0 : 1}">${hecha ? 'Desmarcar' : 'Marcar hecha'}</button>
          </div>`; })() : ''}
        ${!a.tutor_nombre ? `<p class="suave" style="font-size:13px;margin:14px 0 0">
          Sin datos de padre o tutor. Si es menor de edad, agrégalos desde Editar.</p>` : ''}
      </div>

      <div data-panel="clases" class="oculto">
        <div style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:12px">
          <button type="button" class="pildora pildora-azul pildora-chica" data-escribe data-inscribir-aqui>Inscribir en otra clase</button>
        </div>
        ${f.clases.length ? f.clases.map(c => `
          <div class="bloque-clase ${c.estado === 'baja' ? 'baja' : ''}">
            <div style="flex:1;min-width:210px">
              <strong>${esc(c.instrumento)}</strong>
              <span class="suave">con ${esc(nombreCompleto(c, 'maestro_'))}</span>
              <div class="suave" style="font-size:13px;margin-top:4px">
                ${c.dia_semana
                  ? `${esc(c.dia_semana)} de ${hora(c.hora_inicio)} a ${hora(c.hora_fin)}
                     · ${textoDuracion(c.duracion_min)}${c.salon ? ' · ' + esc(c.salon) : ''}`
                  : '<span class="ambar">Sin horario asignado</span>'}
              </div>
              <div class="suave" style="font-size:13px;margin-top:3px">
                ${dinero(c.costo_mensual)} al mes
                ${c.estado === 'baja' ? ` · baja el ${esc(c.fecha_baja || '')}` : ''}
              </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              ${c.estado !== 'baja' ? `
                <button type="button" class="pildora pildora-linea pildora-chica" data-escribe data-editar-clase="${c.id_inscripcion}">Editar</button>
                <button type="button" class="pildora pildora-roja pildora-chica" data-escribe data-baja-clase="${c.id_inscripcion}">Dar de baja</button>`
              : `<button type="button" class="pildora pildora-linea pildora-chica" data-escribe data-reactivar="${c.id_inscripcion}"
                         title="Si la baja fue un error: vuelve con su horario, su nómina y su cobro">Reactivar</button>
                 <button type="button" class="pildora pildora-linea pildora-chica" data-solo-admin data-corregir-baja="${c.id_inscripcion}">Corregir baja</button>`}
              <button type="button" class="pildora pildora-linea pildora-chica" data-historial="${c.id_inscripcion}">Historial</button>
            </div>
          </div>`).join('')
          : '<p class="suave" style="margin:0">Este alumno no tiene clases.</p>'}
        <p class="suave" style="font-size:13px;margin:14px 0 0">
          Dar de baja una clase no da de baja al alumno: sigue inscrito en las demás.
        </p>
      </div>

      <div data-panel="pagos" class="oculto">
        <div style="margin-bottom:12px">
          <button type="button" class="pildora pildora-linea pildora-chica" data-estado-cuenta>Estado de cuenta</button>
        </div>
        ${f.pagos.length ? `
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Fecha</th><th>Concepto</th><th>Periodo</th><th>Método</th>
                <th class="derecha">Monto</th><th class="derecha">Recargo</th><th></th></tr></thead>
              <tbody>${f.pagos.map(p => `
                <tr>
                  <td class="cifra suave">${esc(p.fecha_pago)}</td>
                  <td class="suave">${p.anula_a ? `Anulación del pago #${esc(p.anula_a)}` : esc(ETIQUETA_CONCEPTO[p.concepto || 'colegiatura'] || p.concepto)}</td>
                  <td class="suave">${esc(mesLargo(p.periodo))}</td>
                  <td class="suave">${etiquetaMetodo(p.metodo)}</td>
                  <td class="derecha cifra">${importePago(p)}${marcaMonto(p)}</td>
                  <td class="derecha cifra ${Number(p.recargo) > 0 ? 'rojo' : 'suave'}">${Number(p.recargo) > 0 ? dinero(p.recargo) : '—'}</td>
                  <td class="derecha">${sePuedeCorregir(p) ? `<button type="button" class="pildora pildora-linea pildora-chica"
                        ${Number(p.monto_confirmado) ? 'data-solo-admin' : 'data-escribe'} data-corregir-pago="${p.id_pago}">${Number(p.monto_confirmado) ? 'Corregir' : 'Confirmar'}</button>` : ''}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>`
          : '<p class="suave" style="margin:0">Sin pagos registrados.</p>'}
      </div>`
  });

  const capa = $('#capa-modal');

  $('[data-estado-cuenta]', capa)?.addEventListener('click', () => estadoDeCuenta(a));

  $$('[data-corregir-pago]', capa).forEach(b => b.addEventListener('click', () => {
    const pago = f.pagos.find(x => String(x.id_pago) === b.dataset.corregirPago);
    formularioCorregirPago({ ...pago, quien: nombreCompleto(a) }, () => refrescar());
  }));

  $('[data-inscribir-aqui]', capa)?.addEventListener('click', () => {
    capa.innerHTML = '';
    formularioAlta(a.id_alumno);
  });

  // Factura del mes, desde la ficha.
  $('[data-factura-mes]', capa)?.addEventListener('click', async (e) => {
    const hecha = e.currentTarget.dataset.facturaMes === '1';
    const folio = hecha ? (prompt('Folio de la factura (opcional):') ?? '') : null;
    try {
      await api('facturas.php', { method: 'PUT', cuerpo: { id_alumno: a.id_alumno, periodo: periodoActual(), hecha: hecha ? 1 : 0, folio } });
      aviso(hecha ? 'Factura marcada como hecha.' : 'Factura marcada como pendiente.', 'exito');
      fichaAlumno(a.id_alumno);
    } catch (err) { aviso(err.message, 'error'); }
  });

  // Familia: cobrarle a toda la familia, editarla o crearla.
  $('[data-cobrar-familia]', capa)?.addEventListener('click', () => cobrarFamilia(f.familia.id_familia));
  $('[data-editar-familia]', capa)?.addEventListener('click', () => formularioFamilia(f.familia));
  $('[data-crear-familia]', capa)?.addEventListener('click', () => {
    const s = f.familia_sugerida;
    if (s?.id_familia) {
      api(`familias.php?id=${s.id_familia}`).then(fam => formularioFamilia(fam, { agregar: s.alumnos.map(x => x.id_alumno) }))
        .catch(e => aviso(e.message, 'error'));
    } else {
      formularioFamilia(null, s ? { nombre: s.nombre, responsable: s.responsable, telefono: s.telefono,
                                   miembros: s.alumnos.map(x => x.id_alumno) }
                                : { nombre: `Familia ${String(a.apellido).split(' ')[0]}`, responsable: a.tutor_nombre,
                                    telefono: a.tutor_telefono, miembros: [a.id_alumno] });
    }
  });

  // Pestañas
  $$('[data-tab]', capa).forEach(b => b.addEventListener('click', () => {
    $$('[data-tab]', capa).forEach(x => x.setAttribute('aria-selected', String(x === b)));
    $$('[data-panel]', capa).forEach(pa => pa.classList.toggle('oculto', pa.dataset.panel !== b.dataset.tab));
  }));

  // Acciones sobre una clase concreta
  $$('[data-editar-clase]', capa).forEach(b => b.addEventListener('click', () => {
    const c = f.clases.find(x => String(x.id_inscripcion) === b.dataset.editarClase);
    $('#capa-modal').innerHTML = '';
    formularioHorario({
      id_inscripcion: c.id_inscripcion,
      alumno_nombre: a.nombre, alumno_apellido: a.apellido,
      maestro_nombre: c.maestro_nombre, maestro_apellido: c.maestro_apellido,
      id_maestro: c.id_maestro,
      id_instrumento: c.id_instrumento, id_salon: c.id_salon,
      modalidad: c.modalidad, comparte_con: c.comparte_con,
      dia_semana: c.dia_semana, hora_inicio: c.hora_inicio,
      duracion_min: c.duracion_min, costo_mensual: c.costo_mensual
    }, () => { refrescar(); });
  }));

  $$('[data-reactivar]', capa).forEach(b => b.addEventListener('click', async () => {
    const c = f.clases.find(x => String(x.id_inscripcion) === b.dataset.reactivar);
    if (!confirm(`¿Reactivar la clase de ${c.instrumento} con ${nombreCompleto(c, 'maestro_')}?\n\n`
      + 'Es para cuando la baja fue un error: vuelve con su horario, y su nómina y su cobro corren otra vez desde este mes.')) return;
    try {
      await api(`inscripciones.php?id=${c.id_inscripcion}&accion=reactivar`, { method: 'PUT', cuerpo: {} });
      aviso(`Clase reactivada: ${primerNombre(a.nombre)}, ${c.instrumento}.`, 'exito');
      refrescar();
      fichaAlumno(a.id_alumno);
    } catch (err) { aviso(err.message, 'error'); }
  }));

  $$('[data-corregir-baja]', capa).forEach(b => b.addEventListener('click', () => {
    const c = f.clases.find(x => String(x.id_inscripcion) === b.dataset.corregirBaja);
    formularioCorregirBaja(c, () => refrescar());
  }));
  $$('[data-historial]', capa).forEach(b => b.addEventListener('click', () => {
    const c = f.clases.find(x => String(x.id_inscripcion) === b.dataset.historial);
    historialClase({ ...c, alumno_nombre: a.nombre, alumno_apellido: a.apellido });
  }));

  $$('[data-baja-clase]', capa).forEach(b => b.addEventListener('click', async () => {
    const c = f.clases.find(x => String(x.id_inscripcion) === b.dataset.bajaClase);
    const quedan = vivas.length - 1;
    formularioBajaClase(c, a, quedan > 0
      ? `${nombreCompleto(a)} sigue inscrito en sus otras ${quedan} ${quedan === 1 ? 'clase' : 'clases'}.`
      : 'Es su única clase; se queda sin clases pero no se borra su expediente.', quedan);
  }));
}

/**
 * Baja de una clase. Antes de soltarla hay que decidir qué pasa con lo que el
 * alumno debe: si se conserva (se va de verdad) o si no debe nada (el registro
 * estaba duplicado o venía viejo de una importación). Sin esta decisión, dar de
 * baja le borraría el adeudo en silencio.
 */
async function formularioBajaClase(c, alumno, explicacion, quedan) {
  const hoy = hoyLocal();
  let previa = null;
  try {
    previa = await api(`inscripciones.php?accion=baja_previa&id=${c.id_inscripcion}&fecha=${hoy}`);
  } catch { /* si no se puede consultar, se sigue con lo básico */ }

  modal({
    titulo: `Dar de baja la clase de ${c.instrumento || 'esta materia'}`,
    textoAceptar: 'Dar de baja',
    ancho: '560px',
    campos: `
      <p style="margin:0;font-size:14px">${esc(explicacion)}</p>
      ${campoTexto('fecha_baja', 'Último día que tomó clase', { tipo: 'date', valor: hoy })}
      <div id="resumen-baja" class="suave" style="font-size:13.5px"></div>
      <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px">
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px">
          <input type="radio" name="adeudo" value="conservar" checked style="margin-top:3px">
          <span>Se va de la escuela: <strong>consérvale lo que debe</strong>
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              Lo que debía queda registrado y sigue apareciendo en la cobranza. Al maestro se le pagan
              las clases que sí dio este mes.</span></span>
        </label>
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px;margin-top:10px">
          <input type="radio" name="adeudo" value="sin_deuda" style="margin-top:3px">
          <span>Este registro no debía existir: <strong>no debe nada</strong>
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              Para duplicados y alumnos viejos que llegaron con una importación. No deja nómina al
              maestro de este mes en adelante.</span></span>
        </label>
      </div>
      ${!c.dia_semana ? `
        <div id="caja-clases-tomadas">
          ${campoSelect('clases_tomadas', '¿Cuántas clases tomó este mes?',
            [{ valor: '', texto: '— Elige —' }, ...Array.from({ length: clasesAlMes() + 1 }, (_, n) =>
              ({ valor: n, texto: n === 0 ? 'Ninguna' : `${n} de ${clasesAlMes()}` }))])}
          <p class="suave" style="font-size:13px;margin:6px 0 0">
            Esta clase no tiene día asignado, así que el sistema no puede contarlas. Es lo que se le
            paga al maestro y lo que se le cobra al alumno de este mes.
          </p>
        </div>` : ''}`,
    alAceptar: async (datos, cerrar) => {
      const conserva = datos.adeudo === 'conservar';
      if (conserva && !c.dia_semana && datos.clases_tomadas === '') {
        errorEnCampo($('#c-clases_tomadas'), 'Indica cuántas clases tomó (Ninguna si no vino).');
        return;
      }
      const r = await api(`inscripciones.php?id=${c.id_inscripcion}`
        + `&fecha=${encodeURIComponent(datos.fecha_baja || hoy)}`
        + `&adeudo=${conserva ? 1 : 0}`
        + (conserva && !c.dia_semana ? `&clases=${encodeURIComponent(datos.clases_tomadas)}` : ''),
        { method: 'DELETE' });
      aviso([
        'Clase dada de baja.',
        r.clases_del_mes
          ? `Este mes alcanzó a tomar ${r.clases_del_mes} ${r.clases_del_mes === 1 ? 'clase' : 'clases'},`
            + ` y eso se le paga a ${nombreCompleto(c, 'maestro_')}.`
          : 'Este mes no alcanzó a tomar ninguna clase, así que no genera nómina.',
        r.meses_congelados ? `Se conservaron ${r.meses_congelados} mes(es) de adeudo.` : ''
      ].filter(Boolean).join(' '), 'exito');
      cerrar();
      $('#capa-modal').innerHTML = '';
      refrescar();
      avisarListaEspera(r.lista_espera);
    }
  });

  // Si no debía existir, no hace falta decir cuántas clases tomó.
  $$('input[name="adeudo"]').forEach(r => r.addEventListener('change', () => {
    $('#caja-clases-tomadas')?.classList.toggle('oculto', $('input[name="adeudo"]:checked').value !== 'conservar');
  }));

  const pintar = () => {
    if (!previa) return;
    const esUltima = quedan === 0;
    if (!c.dia_semana) {
      $('#resumen-baja').innerHTML = `Debe <strong>${dinero(previa.adeudo)}</strong>${previa.meses_debe
        ? ` de ${previa.meses_debe} ${previa.meses_debe === 1 ? 'mes' : 'meses'}` : ''}.`;
      return;
    }
    $('#resumen-baja').innerHTML = `
      Debe <strong>${dinero(previa.adeudo)}</strong>${previa.meses_debe
        ? ` de ${previa.meses_debe} ${previa.meses_debe === 1 ? 'mes' : 'meses'}` : ''}.
      ${esUltima
        ? `De este mes se le cobrarían <strong>${dinero(previa.del_mes)}</strong>, solo las clases que tomó.`
        : 'Le quedan otras clases, así que este mes se cierra cuando termine.'}`;
  };
  pintar();
  $('#c-fecha_baja')?.addEventListener('change', async (e) => {
    try {
      previa = await api(`inscripciones.php?accion=baja_previa&id=${c.id_inscripcion}&fecha=${e.target.value}`);
      pintar();
    } catch { /* se deja lo que ya se mostraba */ }
  });
}

/** Los datos del alumno tal como los piden la API de alumnos y la de borradores */
const CAMPOS_ALUMNO = ['nombre', 'apellido', 'telefono', 'email', 'fecha_nacimiento',
  'tutor_nombre', 'tutor_parentesco', 'tutor_telefono', 'direccion', 'notas',
  'rfc', 'razon_social', 'correo_factura', 'datos_fiscales'];
// La casilla "requiere factura" llega como 'on' si está marcada y no llega si no: se manda siempre 1 o 0.
const datosDeAlumno = (datos) => ({
  ...Object.fromEntries(CAMPOS_ALUMNO.map(c => [c, (datos[c] ?? '').trim()])),
  requiere_factura: datos.requiere_factura === 'on' || String(datos.requiere_factura) === '1' ? 1 : 0
});

/**
 * Guarda (o actualiza) un borrador de alta. Un borrador nunca es alumno,
 * inscripción, nómina ni deuda: es lo que se capturó para terminarlo después.
 */
async function guardarBorrador(idBorrador, cuerpo) {
  const r = await api(idBorrador ? `borradores.php?id=${idBorrador}` : 'borradores.php',
                      { method: idBorrador ? 'PUT' : 'POST', cuerpo });
  aviso('Borrador guardado. Lo encuentras en Pendientes para terminarlo.', 'exito');
  return r.id_borrador;
}

/**
 * @param {object|null} alumno   para editar uno que ya existe
 * @param {object|null} borrador {id_borrador, alumno, clase} para retomar un alta a medias
 */
function formularioAlumno(alumno, borrador = null) {
  const editar = Boolean(alumno);
  // Al retomar un borrador, los campos salen de lo que se guardó.
  if (!editar && borrador) alumno = null;
  const v = (campo) => editar ? alumno?.[campo] : borrador?.alumno?.[campo];
  modal({
    titulo: editar ? `Editar a ${nombreCompleto(alumno)}`
          : borrador?.id_borrador ? 'Terminar el alta'
          : borrador?.id_lista_espera ? 'Inscribir desde la lista de espera' : 'Nuevo alumno',
    ancho: '640px',
    protegerCambios: true,
    campoDeError: (m) => /RFC/.test(m) ? $('#c-rfc') : /correo para la factura/.test(m) ? $('#c-correo_factura') : null,
    textoAceptar: editar ? 'Guardar' : 'Continuar',
    extras: editar ? [] : [{
      texto: 'Guardar borrador y salir',
      alPulsar: async (datos, cerrar) => {
        await guardarBorrador(borrador?.id_borrador, {
          alumno: datosDeAlumno(datos),
          clase: { ...(borrador?.clase ?? {}), id_lista_espera: borrador?.id_lista_espera ?? borrador?.clase?.id_lista_espera ?? null },
          motivo: 'Faltan datos del alumno o de la clase'
        });
        cerrar();
        refrescar();
      }
    }],
    campos: `
      <div class="fila-campos">
        ${campoTexto('nombre', 'Nombre(s)', { valor: v('nombre'), requerido: true })}
        ${campoTexto('apellido', 'Apellidos', { valor: v('apellido') })}
      </div>
      <div class="fila-campos">
        ${campoTexto('telefono', 'Teléfono', { valor: v('telefono'), pista: '555 123 4567' })}
        ${campoTexto('email', 'Correo', { valor: v('email'), tipo: 'email' })}
        ${campoTexto('fecha_nacimiento', 'Fecha de nacimiento', { valor: v('fecha_nacimiento'), tipo: 'date' })}
      </div>

      <div style="border-top:1px solid var(--linea);padding-top:14px">
        <p style="margin:0 0 4px;font-weight:500;font-size:14px">Padre o tutor</p>
        <p class="suave" style="font-size:13px;margin:0 0 12px">
          Se piden en la hoja de inscripción de los menores de edad. Déjalos vacíos si el alumno es mayor.
        </p>
        <div class="fila-campos">
          ${campoTexto('tutor_nombre', 'Nombre del padre o tutor', { valor: v('tutor_nombre') })}
          ${campoTexto('tutor_parentesco', 'Parentesco', { valor: v('tutor_parentesco'), pista: 'Madre' })}
          ${campoTexto('tutor_telefono', 'Teléfono del tutor', { valor: v('tutor_telefono') })}
        </div>
        ${campoTexto('direccion', 'Domicilio', { valor: v('direccion'), pista: 'Calle, número, colonia' })}
      </div>

      ${campoTexto('notas', 'Notas', { valor: v('notas'), pista: 'Alergias, permisos, lo que haga falta' })}

      <div style="border-top:1px solid var(--linea);padding-top:14px">
        <label style="display:flex;gap:9px;align-items:center;font-size:14px;font-weight:500">
          <input type="checkbox" name="requiere_factura" id="c-requiere_factura"
                 ${Number(v('requiere_factura')) === 1 ? 'checked' : ''}> Requiere factura
        </label>
        <div id="datos-factura" class="${Number(v('requiere_factura')) === 1 ? '' : 'oculto'}" style="margin-top:12px">
          <div class="fila-campos">
            ${campoTexto('rfc', 'RFC', { valor: v('rfc'), pista: 'XAXX010101000' })}
            ${campoTexto('razon_social', 'Nombre o razón social', { valor: v('razon_social') })}
          </div>
          <div class="fila-campos">
            ${campoTexto('correo_factura', 'Correo para la factura', { tipo: 'email', valor: v('correo_factura') })}
            ${campoTexto('datos_fiscales', 'Régimen, código postal, uso de CFDI', { valor: v('datos_fiscales') })}
          </div>
          <p class="suave" style="font-size:13px;margin:6px 0 0">
            Cada mes, antes de que termine, el sistema recuerda a quién falta hacerle su factura.
          </p>
        </div>
      </div>

      ${editar ? '' : `
      <label style="display:flex;gap:9px;align-items:flex-start;border-top:1px solid var(--linea);
                    padding-top:14px;font-size:14px">
        <input type="checkbox" name="inscribir" checked style="margin-top:3px">
        <span>Inscribirlo en una clase ahora
          <span class="suave" style="display:block;font-size:13px;margin-top:2px">
            Sigue el alta de la clase: materia, maestro, horario y costo. El alumno se registra
            junto con su clase, al darla de alta; si la clase no se puede, no queda un alumno a medias.</span>
        </span>
      </label>`}`,
    alAceptar: async (datos, cerrar) => {
      const inscribir = datos.inscribir === 'on';
      delete datos.inscribir;

      // Lo obligatorio se marca en su campo; el cursor va al primero que falta.
      const faltaNombre = !datos.nombre.trim();
      const faltaApellido = !editar && inscribir && !datos.apellido.trim();
      if (faltaApellido) errorEnCampo($('#c-apellido'), 'Escribe también los apellidos.');
      if (faltaNombre) errorEnCampo($('#c-nombre'), 'Escribe el nombre.');
      if (faltaNombre || faltaApellido) return;

      // Los errores de facturación se marcan en su campo, no en un aviso que se va.
      const enSuCampo = (e) => {
        if (/RFC/.test(e.message)) { errorEnCampo($('#c-rfc'), e.message); return true; }
        if (/correo para la factura/.test(e.message)) { errorEnCampo($('#c-correo_factura'), e.message); return true; }
        return false;
      };
      if (editar) {
        try {
          await api('alumnos.php', { method: 'PUT', cuerpo: { ...datos, ...datosDeAlumno(datos), id_alumno: alumno.id_alumno } });
        } catch (e) { if (enSuCampo(e)) return; throw e; }
        aviso(`Datos de ${primerNombre(datos.nombre)} guardados.`, 'exito');
        cerrar();
        refrescar();
        return;
      }

      if (inscribir) {
        // No se crea nada todavía: el alumno viaja con el alta de su clase.
        cerrar();
        await formularioAlta(null, {
          alumno_nuevo: datosDeAlumno(datos),
          id_borrador: borrador?.id_borrador ?? null,
          id_lista_espera: borrador?.id_lista_espera ?? borrador?.clase?.id_lista_espera ?? null,
          clase: borrador?.clase ?? {}
        });
        return;
      }

      // Solo el alumno, sin clase. Si venía de un borrador, el borrador sigue
      // abierto (ya con su alumno) hasta que se le dé de alta la clase.
      try {
        await api('alumnos.php', {
          cuerpo: { ...datos, ...datosDeAlumno(datos), ...(borrador?.id_borrador ? { id_borrador: borrador.id_borrador } : {}) }
        });
      } catch (e) { if (enSuCampo(e)) return; throw e; }
      aviso(borrador?.id_borrador
        ? `Registro listo: ${primerNombre(datos.nombre)}. El borrador sigue en Pendientes para darle su clase.`
        : `Registro listo: ${primerNombre(datos.nombre)}.`, 'exito');
      cerrar();
      refrescar();
    }
  });
  $('#c-requiere_factura')?.addEventListener('change', (e) => {
    $('#datos-factura').classList.toggle('oculto', !e.target.checked);
    if (e.target.checked) $('#c-rfc').focus();
  });
}

/**
 * Dar de baja a un alumno con todas sus clases, en un paso: o se van todas o
 * ninguna. Si alguna clase no tiene día, se pregunta cuántas tomó este mes.
 */
function formularioBajaAlumno(a) {
  const hoy = hoyLocal();
  const sinDia = (a.clases || []).filter(c => !c.dia);
  const opcionesClases = [{ valor: '', texto: '— Elige —' }, ...Array.from({ length: clasesAlMes() + 1 }, (_, n) =>
    ({ valor: n, texto: n === 0 ? 'Ninguna' : `${n} de ${clasesAlMes()}` }))];
  modal({
    titulo: `Dar de baja a ${nombreCompleto(a)}`,
    textoAceptar: 'Dar de baja',
    ancho: '560px',
    campos: `
      <p style="margin:0;font-size:14px">${(a.clases || []).length
        ? `Se dan de baja también sus ${a.clases.length} ${a.clases.length === 1 ? 'clase' : 'clases'} y se libera su horario.`
        : 'No tiene clases: deja de contar como alumno activo.'}
        Su expediente, sus pagos y su historial se conservan.</p>
      ${campoTexto('fecha_baja', 'Último día que vino', { tipo: 'date', valor: hoy })}
      <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px">
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px">
          <input type="radio" name="adeudo" value="conservar" checked style="margin-top:3px">
          <span>Se va de la escuela: <strong>consérvale lo que debe</strong>
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              A sus maestros se les pagan las clases que sí dio este mes.</span></span>
        </label>
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px;margin-top:10px">
          <input type="radio" name="adeudo" value="sin_deuda" style="margin-top:3px">
          <span>Este registro no debía existir: <strong>no debe nada</strong>
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              Duplicados o alumnos viejos de una importación: no deja nómina ni deuda.</span></span>
        </label>
      </div>
      ${sinDia.length ? `
        <div id="caja-sin-dia">
          ${sinDia.map(c => campoSelect(`tomadas_${c.id_inscripcion}`,
            `¿Cuántas clases tomó este mes ${c.materia && c.materia !== 'Por asignar' ? `de ${c.materia} ` : ''}con ${c.maestro}?`,
            opcionesClases)).join('')}
          <p class="suave" style="font-size:13px;margin:6px 0 0">
            ${sinDia.length === 1 ? 'Esa clase no tiene' : 'Esas clases no tienen'} día asignado: el sistema no puede contarlas.</p>
        </div>` : ''}`,
    alAceptar: async (datos, cerrar) => {
      const conserva = datos.adeudo === 'conservar';
      const tomadas = {};
      if (conserva) {
        for (const c of sinDia) {
          const v = datos[`tomadas_${c.id_inscripcion}`];
          if (v === '') { errorEnCampo($(`#c-tomadas_${c.id_inscripcion}`), 'Indica cuántas tomó (Ninguna si no vino).'); return; }
          tomadas[c.id_inscripcion] = Number(v);
        }
      }
      const r = await api(`alumnos.php?id=${a.id_alumno}&fecha=${encodeURIComponent(datos.fecha_baja || hoy)}`
        + `&adeudo=${conserva ? 1 : 0}&clases=${encodeURIComponent(JSON.stringify(tomadas))}`, { method: 'DELETE' });
      aviso(`Baja registrada: ${nombreCompleto(a)}${r.clases ? `, ${r.clases} ${r.clases === 1 ? 'clase' : 'clases'}` : ''}.`, 'exito');
      cerrar();
      refrescar();
      avisarListaEspera(r.lista_espera);
    }
  });
  $$('input[name="adeudo"]').forEach(r => r.addEventListener('change', () =>
    $('#caja-sin-dia')?.classList.toggle('oculto', $('input[name="adeudo"]:checked').value !== 'conservar')));
}

/**
 * Dar de baja a un maestro. Si tiene clases, en el mismo paso pasan a otro
 * maestro desde una fecha (el mes se reparte entre los dos). Si alguna choca
 * con el horario del nuevo, no se pasa ninguna y se dice cuál.
 */
async function formularioBajaMaestro(m) {
  let maestros = window.__maestros?.length ? window.__maestros : null;
  if (!maestros) { try { maestros = window.__maestros = await api('maestros.php'); } catch (e) { aviso(e.message, 'error'); return; } }
  const otros = maestros.filter(x => Number(x.activo) && String(x.id_maestro) !== String(m.id_maestro))
    .sort((x, y) => nombreCompleto(x).localeCompare(nombreCompleto(y), 'es'));
  const tiene = Number(m.alumnos) > 0;
  modal({
    titulo: `Dar de baja a ${nombreCompleto(m)}`,
    textoAceptar: tiene ? 'Pasar sus clases y dar de baja' : 'Dar de baja',
    ancho: '560px',
    campos: tiene ? `
      <p style="margin:0;font-size:14px">Tiene <strong>${m.alumnos} ${Number(m.alumnos) === 1 ? 'alumno' : 'alumnos'}</strong>.
        Sus clases pasan a otro maestro, con el mismo día, hora y salón.</p>
      <div class="fila-campos">
        ${campoSelect('pasar_a', 'Pasan con', [{ valor: '', texto: '— Elige un maestro —' },
          ...otros.map(x => ({ valor: x.id_maestro, texto: nombreCompleto(x) }))])}
        ${campoTexto('desde', 'Desde el', { tipo: 'date', valor: hoyLocal() })}
      </div>
      <p class="suave" style="font-size:13px;margin:0">
        Las clases de este mes antes de esa fecha se le pagan a ${esc(nombreCompleto(m))}; las que quedan, al nuevo.
        Si alguna choca con el horario del nuevo, no se pasa ninguna y te digo cuál.</p>
      <div id="error-baja-maestro" class="rojo" style="font-size:13.5px;white-space:pre-line"></div>`
    : `<p style="margin:0;font-size:14px">No tiene clases. Deja de aparecer en la nómina y en las listas; su historial se conserva.</p>`,
    alAceptar: async (datos, cerrar) => {
      if (tiene && !datos.pasar_a) { errorEnCampo($('#c-pasar_a'), 'Elige a qué maestro pasan sus clases.'); return; }
      try {
        const r = await api(`maestros.php?id=${m.id_maestro}` + (tiene
          ? `&pasar_a=${datos.pasar_a}&desde=${encodeURIComponent(datos.desde || hoyLocal())}` : ''), { method: 'DELETE' });
        aviso(tiene ? `Baja de ${nombreCompleto(m)} registrada; sus ${r.clases} clases pasaron al maestro nuevo.`
                    : `Baja de ${nombreCompleto(m)} registrada.`, 'exito');
        window.__maestros = null;
        cerrar();
        refrescar();
      } catch (e) {
        // El mensaje completo (con sus saltos de línea) en la misma ventana, y la lista de maestros
        // vuelve a leerse: la pantalla no debe mostrar como hecho algo que no se guardó.
        window.__maestros = null;
        try { window.__maestros = await api('maestros.php'); } catch { /* si no se puede leer, se pedirá al abrir */ }
        errorEnFormulario(e.message);
      }
    }
  });
}

/* ====================================================================
   VISTA · MAESTROS
   ==================================================================== */

vistas.maestros = async () => {
  const maestros = await api('maestros.php');

  vista.innerHTML = `
    <div class="tarjeta">
      <header>
        <h2>Maestros <span class="suave" style="font-weight:400">· ${maestros.length}</span></h2>
        <div class="barra-busqueda">
          <input class="campo" id="buscar-maestro" placeholder="Buscar por nombre o materia">
          <button class="pildora pildora-linea pildora-chica" data-escribe id="b-falta-maestro">Registrar falta</button>
          <button class="pildora pildora-azul pildora-chica" data-solo-admin id="b-nuevo">Nuevo maestro</button>
        </div>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Nombre</th><th>Imparte</th><th>Alumnos</th><th data-no-recepcion>Bono</th><th data-no-recepcion>Cómo se le paga</th><th>Teléfono</th><th>Estado</th><th></th></tr></thead>
          <tbody id="cuerpo-maestros"></tbody>
        </table>
      </div>
    </div>`;

  const filaMaestro = (m) => `
    <tr>
      <td>${esc(nombreCompleto(m))}</td>
      <td class="suave">${esc(m.instrumentos || 'Sin asignar')}</td>
      <td class="suave cifra">${m.alumnos}</td>
      <td class="suave" data-no-recepcion>${Number(m.bono_puntualidad) > 0
            ? `<span class="verde">+${dinero(m.bono_puntualidad)}</span> de bono` : '—'}</td>
      <td class="suave" data-no-recepcion>${Number(m.lleva_nomina) === 0
            ? '<span class="etiqueta-estado suave">Sin nómina</span>'
            : m.modo_pago === 'por_hora'
            ? `${dinero(m.tarifa_por_hora)} por hora`
            : `${dinero(m.tarifa_por_alumno)} por alumno`}</td>
      <td class="suave">${esc(m.telefono || '—')}</td>
      <td>${Number(m.activo)
            ? '<span class="etiqueta-estado verde">Activo</span>'
            : '<span class="etiqueta-estado suave">Baja</span>'}</td>
      <td class="derecha" style="white-space:nowrap">
        <button class="pildora pildora-linea pildora-chica" data-solo-admin data-editar="${m.id_maestro}">Editar</button>
        ${Number(m.activo)
          ? `<button class="pildora pildora-roja pildora-chica" data-solo-admin data-baja="${m.id_maestro}">Baja</button>`
          : `<button class="pildora pildora-linea pildora-chica" data-solo-admin data-alta="${m.id_maestro}">Reactivar</button>`}
      </td>
    </tr>`;

  const buscarM = (id) => maestros.find(x => String(x.id_maestro) === id);

  const pintarMaestros = (lista) => {
    $('#cuerpo-maestros').innerHTML = lista.length
      ? lista.map(filaMaestro).join('')
      : '<tr><td colspan="7"><div class="mensaje-vacio">Sin resultados.</div></td></tr>';
    conectarMaestros();
  };

  $('#buscar-maestro').addEventListener('input', (e) => {
    const t = e.target.value.toLowerCase().trim();
    pintarMaestros(maestros.filter(m =>
      nombreCompleto(m).toLowerCase().includes(t) ||
      (m.instrumentos || '').toLowerCase().includes(t)));
  });

  function conectarMaestros() {
  $$('[data-editar]').forEach(b => b.addEventListener('click', () => formularioMaestro(buscarM(b.dataset.editar))));

  $$('[data-baja]').forEach(b => b.addEventListener('click', () => formularioBajaMaestro(buscarM(b.dataset.baja))));

  $$('[data-alta]').forEach(b => b.addEventListener('click', async () => {
    try {
      await api(`maestros.php?accion=reactivar&id=${b.dataset.alta}`, { method: 'PUT' });
      aviso('Maestro reactivado.', 'exito');
      refrescar();
    } catch (e) { aviso(e.message, 'error'); }
  }));
  }

  pintarMaestros(maestros);
  $('#b-nuevo').addEventListener('click', () => formularioMaestro(null));
  $('#b-falta-maestro').addEventListener('click', () => formularioFalta(maestros.filter(m => Number(m.activo))));
};

function formularioMaestro(maestro) {
  const editar = Boolean(maestro);
  const cfg = catalogos?.configuracion ?? { tarifa_maestro: 0, modo_pago_maestro: 'por_alumno' };
  const instrumentos = catalogos?.instrumentos ?? [];
  // Los que ya imparte, para marcarlos al editar.
  const suyos = String(maestro?.ids_instrumentos || '').split(',').filter(Boolean);

  modal({
    titulo: editar ? 'Editar maestro' : 'Nuevo maestro',
    campos: `
      <div class="fila-campos">
        ${campoTexto('nombre', 'Nombre(s)', { valor: maestro?.nombre, requerido: true })}
        ${campoTexto('apellido', 'Apellidos', { valor: maestro?.apellido })}
      </div>
      <div class="fila-campos">
        ${campoTexto('telefono', 'Teléfono', { valor: maestro?.telefono })}
        ${campoTexto('email', 'Correo', { valor: maestro?.email, tipo: 'email' })}
      </div>
      <div class="fila-campos">
        ${campoSelect('modo_pago', 'Cómo se le paga', [
          { valor: 'por_alumno', texto: 'Una tarifa fija por alumno' },
          { valor: 'por_hora', texto: 'Por hora de clase' }], maestro?.modo_pago ?? cfg.modo_pago_maestro)}
        ${campoTexto('tarifa_por_alumno', 'Tarifa por alumno',
          { valor: maestro?.tarifa_por_alumno ?? (cfg.modo_pago_maestro === 'por_alumno' ? cfg.tarifa_maestro : 0),
            tipo: 'number', paso: '0.01' })}
        ${campoTexto('tarifa_por_hora', 'Tarifa por hora',
          { valor: maestro?.tarifa_por_hora ?? (cfg.modo_pago_maestro === 'por_hora' ? cfg.tarifa_maestro : 0),
            tipo: 'number', paso: '0.01' })}
      </div>
      <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px">
        <input type="checkbox" name="lleva_nomina" ${maestro && Number(maestro.lleva_nomina) === 0 ? '' : 'checked'}
               style="margin-top:3px">
        <span>Lleva nómina
          <span class="suave" style="display:block;font-size:13px;margin-top:2px">
            Desmárcalo para quien da clases pero no cobra por nómina, como la dueña. Sus clases se
            siguen cobrando y cuentan en los reportes; solo no se le arma nómina.</span></span>
      </label>
      <div>
        ${campoTexto('bono_puntualidad', 'Bono de puntualidad y asistencia (al mes)',
          { valor: maestro?.bono_puntualidad ?? 0, tipo: 'number', paso: '0.01' })}
        <p class="suave" style="font-size:13px;margin:6px 0 0">
          Se le suma solo a su nómina cada mes. Si ese mes tiene una falta registrada, lo pierde.
          Déjalo en cero si ese maestro no lleva bono.
        </p>
      </div>
      <div style="border-top:1px solid var(--linea);padding-top:14px">
        <label class="etiqueta">Materias que imparte</label>
        <div class="fichas">
          ${instrumentos.map(i => `
            <label>
              <input type="checkbox" name="inst_${i.id_instrumento}"
                     ${suyos.includes(String(i.id_instrumento)) ? 'checked' : ''}>
              <span>${esc(i.nombre)}</span>
            </label>`).join('')}
        </div>
      </div>
      <p class="suave" style="font-size:13px;margin:0">
        Lo que se le paga al maestro no depende de lo que paga el alumno: la colegiatura es
        ingreso de la escuela y la tarifa de aquí es lo que sale por nómina.
      </p>`,
    alAceptar: async (datos, cerrar) => {
      if (!datos.nombre.trim()) throw new Error('El nombre es obligatorio.');
      // Una casilla sin marcar no viaja en el formulario: se manda explícito.
      datos.lleva_nomina = datos.lleva_nomina === 'on' ? 1 : 0;
      const seleccionados = Object.keys(datos)
        .filter(k => k.startsWith('inst_'))
        .map(k => Number(k.replace('inst_', '')));
      Object.keys(datos).filter(k => k.startsWith('inst_')).forEach(k => delete datos[k]);

      if (editar) {
        const r = await api('maestros.php', {
          method: 'PUT',
          cuerpo: { ...datos, id_maestro: maestro.id_maestro, instrumentos: seleccionados }
        });
        aviso(r.nomina_actualizada
          ? `Maestro actualizado. Se ajustaron ${r.nomina_actualizada} renglones de su nómina con la tarifa nueva.`
          : 'Maestro actualizado.', 'exito');
      } else {
        await api('maestros.php', { cuerpo: { ...datos, instrumentos: seleccionados } });
        aviso('Maestro registrado.', 'exito');
      }
      cerrar();
      refrescar();
    }
  });
}

/* ====================================================================
   VISTA · INSCRIPCIONES
   ==================================================================== */

/* ====================================================================
   VISTA · PENDIENTES
   Lo que le falta al sistema para estar completo. No es un listado más de
   alumnos: es una lista de trabajo, y cada renglón se arregla desde aquí.
   ==================================================================== */

vistas.pendientes = async () => {
  const p = await api('pendientes.php');
  // Altas a medias: no son problema de datos, pero hay que terminarlas.
  const borradores = p.borradores_abiertos ? await api('borradores.php') : [];

  const seccion = (titulo, explicacion, filas, contenido) => filas.length ? `
    <div class="tarjeta">
      <header>
        <h2>${esc(titulo)} <span class="suave" style="font-weight:400">· ${filas.length}</span></h2>
      </header>
      <div class="cuerpo suave" style="border-bottom:1px solid var(--linea);font-size:13.5px;padding-bottom:14px">
        ${explicacion}
      </div>
      ${contenido}
    </div>` : '';

  const nombreDe = (x, pre) => esc(nombreCompleto(x, pre));

  vista.innerHTML = `
    ${p.total === 0 ? `
      <div class="tarjeta">
        <div class="mensaje-vacio">
          <p style="font-size:17px;margin:0 0 6px">No hay nada pendiente.</p>
          <p style="margin:0">Todas las clases tienen horario, materia y precio, y no se detectan registros repetidos.</p>
        </div>
      </div>` : `
      <div class="franja-aviso" style="border-left-color:var(--azul)">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" style="color:var(--azul);flex-shrink:0;margin-top:2px">
          <path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
        <div>
          <strong>${p.total} cosas por resolver</strong>
          <p class="suave" style="margin:5px 0 0;font-size:13.5px">
            Esto sale sobre todo de lo que se importó del Excel. Cada renglón se arregla desde aquí
            y desaparece de la lista.
          </p>
        </div>
      </div>`}

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:14px">
      <button class="pildora pildora-linea pildora-chica" data-solo-admin id="b-precios">Revisar precios importados</button>
      <button class="pildora pildora-azul pildora-chica" data-escribe id="b-alta">Nueva inscripción</button>
    </div>

    ${borradores.length ? `
      <div class="tarjeta">
        <header>
          <h2>Borradores de alta <span class="suave" style="font-weight:400">· ${borradores.length}</span></h2>
        </header>
        <div class="cuerpo suave" style="border-bottom:1px solid var(--linea);font-size:13.5px;padding-bottom:14px">
          Altas que se dejaron a medias. Un borrador no es alumno ni inscripción: no aparece en listas,
          horarios, nómina ni cobranza hasta que se termina el alta.
        </div>
        <div class="cuerpo" style="display:flex;flex-direction:column;gap:10px">
          ${borradores.map(b => {
            const c = b.clase || {};
            const materia = (catalogos?.instrumentos ?? []).find(i => String(i.id_instrumento) === String(c.id_instrumento))?.nombre;
            const clase = [materia, c.dia_semana, c.hora_inicio ? hora(c.hora_inicio) : '',
                           c.modalidad === 'online' ? 'en línea' : ''].filter(Boolean).join(' · ');
            return `
            <div class="bloque-clase">
              <div style="flex:1;min-width:250px">
                <strong>${esc(b.nombre_mostrar)}</strong>
                <span class="suave">${b.id_alumno ? '· ya es alumno' : '· todavía no es alumno'}</span>
                <div class="suave" style="font-size:13px;margin-top:4px">
                  ${clase ? esc(clase) : 'Sin clase elegida'}${b.motivo ? ` · ${esc(b.motivo)}` : ''}
                </div>
                <div class="suave" style="font-size:12.5px;margin-top:3px">
                  ${esc((b.fecha_actualizacion || '').slice(0, 16))}${b.actualizado_por ? ' · ' + esc(b.actualizado_por) : ''}
                </div>
              </div>
              <button class="pildora pildora-linea pildora-chica" data-escribe data-descartar-borrador="${b.id_borrador}">Descartar</button>
              <button class="pildora pildora-azul pildora-chica" data-escribe data-continuar-borrador="${b.id_borrador}">Continuar</button>
            </div>`; }).join('')}
        </div>
      </div>` : ''}

    ${(p.familias_sugeridas || []).length ? `
      <div class="tarjeta">
        <header>
          <h2>Posibles familias <span class="suave" style="font-weight:400">· ${p.familias_sugeridas.length}</span></h2>
        </header>
        <div class="cuerpo suave" style="border-bottom:1px solid var(--linea);font-size:13.5px;padding-bottom:14px">
          Alumnos con el mismo padre, madre o tutor. Juntarlos en una familia permite cobrarle todo de una vez a
          quien paga, eligiendo qué clases liquida, y darles un descuento familiar. Nadie se junta sin que lo confirmes.
        </div>
        <div class="cuerpo" style="display:flex;flex-direction:column;gap:10px">
          ${p.familias_sugeridas.map((s, k) => `
            <div class="bloque-clase">
              <div style="flex:1;min-width:250px">
                <strong>${esc(s.id_familia ? s.familia : s.nombre)}</strong>
                ${s.responsable ? `<span class="suave">· ${esc(s.responsable)}</span>` : ''}
                <div class="suave" style="font-size:13px;margin-top:4px">
                  ${s.alumnos.map(x => esc(x.nombre + ' ' + x.apellido) + (x.en_familia ? ' (ya está)' : '')).join(', ')}
                </div>
              </div>
              <button class="pildora pildora-azul pildora-chica" data-escribe data-sugerencia="${k}">${
                s.id_familia ? 'Agregar a la familia' : 'Crear familia'}</button>
            </div>`).join('')}
        </div>
      </div>` : ''}

    ${seccion('Clases compartidas sin su pareja',
      'Estas clases dicen compartir horario con otra que ya no está en ese lugar (dada de baja, sin horario o movida). No debería pasar: ábrelas y asígnales su horario de nuevo.',
      p.grupos_rotos, `
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:10px">
        ${p.grupos_rotos.map(g => `
          <div class="bloque-clase">
            <div style="flex:1;min-width:250px">
              <strong>${nombreDe(g, 'alumno_')}</strong>
              <div class="suave" style="font-size:13px;margin-top:4px">${esc(g.motivo)}</div>
            </div>
            <button class="pildora pildora-azul pildora-chica" data-ficha="${g.id_alumno}">Abrir alumno</button>
          </div>`).join('')}
      </div>`)}

    ${seccion('Clases repetidas',
      'El mismo alumno aparece dos veces con el mismo maestro: una clase entró por el Excel de nómina y otra por el de horarios. Unir deja una sola, con su horario, su nómina y sus pagos juntos. Antes de unir se guarda un respaldo automático.',
      p.clases_repetidas, `
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:10px">
        ${p.clases_repetidas.map(r => `
          <div class="bloque-clase">
            <div style="flex:1;min-width:250px">
              <strong>${nombreDe(r, 'alumno_')}</strong>
              <span class="suave">con ${nombreDe(r, 'maestro_')}</span>
              <div class="suave" style="font-size:13px;margin-top:4px">
                ${r.dia_semana ? `${esc(r.dia_semana)} ${hora(r.hora_inicio)}` : 'Sin horario'} ·
                ${r.renglones_nomina} ${Number(r.renglones_nomina) === 1 ? 'renglón' : 'renglones'} de nómina entre las dos
              </div>
            </div>
            <button class="pildora pildora-azul pildora-chica" data-solo-admin data-unir-a="${r.id_a}" data-unir-b="${r.id_b}">Unir</button>
          </div>`).join('')}
      </div>`)}

    ${seccion('Maestros repetidos',
      'El mismo maestro quedó como dos registros, normalmente porque en un archivo viene con apellido y en otro solo el nombre de pila. Fusionar mueve alumnos, horarios y nómina a uno solo, y borra el otro.',
      p.duplicados_maestros, `
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:10px">
        ${p.duplicados_maestros.map((d, i) => `
          <div class="bloque-clase">
            <div style="flex:1;min-width:250px">
              <strong>${nombreDe(d.principal)}</strong>
              <span class="suave">se queda · ${d.principal.clases} clases</span>
              <div class="suave" style="font-size:13px;margin-top:4px">
                Se le suma <strong>${nombreDe(d.duplicado)}</strong> (${d.duplicado.clases} clases)
              </div>
              <div class="suave" style="font-size:12.5px;margin-top:3px">${esc(d.motivo)}${
                d.choques.length ? ` · ${d.choques.length} ${d.choques.length === 1 ? 'clase repetida que se descarta' : 'clases repetidas que se descartan'}` : ''}${
                d.empalmes ? ` · <span class="ambar">${d.empalmes} ${d.empalmes === 1 ? 'clase queda sin horario' : 'clases quedan sin horario'} por empalme</span>` : ''}</div>
            </div>
            <button class="pildora pildora-azul pildora-chica" data-solo-admin data-fusionar="maestros"
                    data-principal="${d.principal.id}" data-duplicado="${d.duplicado.id}"
                    data-nombre="${nombreDe(d.principal)}">Fusionar</button>
          </div>`).join('')}
      </div>`)}

    ${seccion('Alumnos repetidos',
      'Dos registros que parecen la misma persona. Al fusionar se conservan los pagos y el historial de los dos.',
      p.duplicados_alumnos, `
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:10px">
        ${p.duplicados_alumnos.map(d => `
          <div class="bloque-clase">
            <div style="flex:1;min-width:250px">
              <strong>${nombreDe(d.principal)}</strong>
              <span class="suave">se queda · ${d.principal.clases} clases</span>
              <div class="suave" style="font-size:13px;margin-top:4px">
                Se le suma <strong>${nombreDe(d.duplicado)}</strong> (${d.duplicado.clases} clases)
              </div>
              <div class="suave" style="font-size:12.5px;margin-top:3px">${esc(d.motivo)}</div>
            </div>
            <button class="pildora pildora-azul pildora-chica" data-solo-admin data-fusionar="alumnos"
                    data-principal="${d.principal.id}" data-duplicado="${d.duplicado.id}"
                    data-nombre="${nombreDe(d.principal)}">Fusionar</button>
          </div>`).join('')}
      </div>`)}

    ${seccion('Clases sin horario',
      'Alumnos que ya cuentan para la nómina pero a los que todavía no se les asignó día, hora ni salón. Vienen del Excel de nómina.',
      p.sin_horario, `
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Alumno</th><th>Maestro</th><th class="derecha">Paga al mes</th><th></th></tr></thead>
          <tbody>${p.sin_horario.map(i => `
            <tr>
              <td>${nombreDe(i, 'alumno_')}</td>
              <td class="suave">${nombreDe(i, 'maestro_')}</td>
              <td class="derecha cifra">${dinero(i.costo_mensual)}</td>
              <td class="derecha" style="white-space:nowrap">
                <button class="pildora pildora-azul pildora-chica" data-escribe data-asignar="${i.id_inscripcion}">Asignar horario</button>
                <button class="pildora pildora-roja pildora-chica" data-solo-admin data-borrar="${i.id_inscripcion}"
                        data-que="la clase de ${nombreDe(i, 'alumno_')} con ${nombreDe(i, 'maestro_')}">Ya no va</button>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`)}

    ${seccion('Maestros sin tarifa',
      'Tienen alumnos pero no se ha definido cuánto se les paga, así que su nómina sale en cero.',
      p.sin_tarifa, `
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Maestro</th><th>Alumnos</th><th>Cómo cobra</th><th></th></tr></thead>
          <tbody>${p.sin_tarifa.map(m => `
            <tr>
              <td>${nombreDe(m)}</td>
              <td class="suave cifra">${m.alumnos}</td>
              <td class="suave">${m.modo_pago === 'por_hora' ? 'Por hora' : 'Por alumno'}</td>
              <td class="derecha"><a class="pildora pildora-linea pildora-chica" href="#maestros">Poner su tarifa</a></td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`)}

    ${seccion('Clases sin materia',
      'Se importaron con horario pero sin saber qué se imparte. Afecta a los reportes por materia.',
      p.sin_materia, `
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Alumno</th><th>Maestro</th><th>Horario</th><th></th></tr></thead>
          <tbody>${p.sin_materia.map(i => `
            <tr>
              <td>${nombreDe(i, 'alumno_')}</td>
              <td class="suave">${nombreDe(i, 'maestro_')}</td>
              <td class="suave">${esc(i.dia_semana)} ${hora(i.hora_inicio)}</td>
              <td class="derecha"><button class="pildora pildora-linea pildora-chica" data-escribe data-editar="${i.id_inscripcion}">Completar</button></td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`)}

    ${seccion('Clases sin precio',
      'Quedaron en cero al importarlas. Mientras sigan así no cuentan como colegiatura en los reportes.',
      p.sin_precio, `
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Alumno</th><th>Maestro</th><th>Duración</th><th></th></tr></thead>
          <tbody>${p.sin_precio.map(i => `
            <tr>
              <td>${nombreDe(i, 'alumno_')}</td>
              <td class="suave">${nombreDe(i, 'maestro_')}</td>
              <td class="suave">${textoDuracion(i.duracion_min)}</td>
              <td class="derecha"><button class="pildora pildora-linea pildora-chica" data-escribe data-editar="${i.id_inscripcion}">Poner precio</button></td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`)}

    ${seccion('Alumnos sin apellido',
      'Se crearon desde el Excel de horarios, que solo trae nombres de pila.',
      p.sin_apellido, `
      <div class="cuerpo">
        <div class="fichas">
          ${p.sin_apellido.map(a => `
            <button class="pildora pildora-linea pildora-chica" data-escribe data-alumno="${a.id_alumno}">${esc(a.nombre)}</button>`).join('')}
        </div>
        <p class="suave" style="font-size:13px;margin:12px 0 0">Haz clic en cada uno para completar sus datos.</p>
      </div>`)}`;

  $('#b-alta').addEventListener('click', () => formularioAlta());

  // Poner al día lo que paga cada alumno según su materia y duración
  $$('[data-continuar-borrador]').forEach(b => b.addEventListener('click', async () => {
    try {
      const borrador = await api(`borradores.php?id=${b.dataset.continuarBorrador}`);
      if (borrador.id_alumno) {
        // Ya es alumno: falta solo la clase.
        await formularioAlta(Number(borrador.id_alumno), {
          id_borrador: borrador.id_borrador, id_lista_espera: borrador.clase?.id_lista_espera ?? null, clase: borrador.clase
        });
      } else {
        formularioAlumno(null, borrador);
      }
    } catch (e) { aviso(e.message, 'error'); }
  }));

  $$('[data-descartar-borrador]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('¿Descartar este borrador? No se registra a nadie; el borrador queda como descartado.')) return;
    try {
      await api(`borradores.php?id=${b.dataset.descartarBorrador}`, { method: 'DELETE' });
      aviso('Borrador descartado.', 'exito');
      refrescar();
    } catch (e) { aviso(e.message, 'error'); }
  }));

  $$('[data-ficha]').forEach(b => b.addEventListener('click', () => fichaAlumno(Number(b.dataset.ficha))));

  $$('[data-sugerencia]').forEach(b => b.addEventListener('click', async () => {
    const s = p.familias_sugeridas[Number(b.dataset.sugerencia)];
    if (s.id_familia) {
      try { formularioFamilia(await api(`familias.php?id=${s.id_familia}`), { agregar: s.alumnos.map(x => x.id_alumno) }); }
      catch (e) { aviso(e.message, 'error'); }
    } else {
      formularioFamilia(null, { nombre: s.nombre, responsable: s.responsable, telefono: s.telefono,
                                miembros: s.alumnos.map(x => x.id_alumno) });
    }
  }));

  $('#b-precios').addEventListener('click', async () => {
    let r;
    try { r = await api('pendientes.php?accion=recalcular_precios', { cuerpo: { aplicar: false } }); }
    catch (e) { return aviso(e.message, 'error'); }

    if (!r.cuantos) {
      aviso(r.sin_materia
        ? `Nada que corregir. Hay ${r.sin_materia} clases importadas sin materia: ponles la materia primero.`
        : 'Los precios ya cuadran con la tarifa de cada materia.', 'exito');
      return;
    }

    modal({
      titulo: 'Precios que no cuadran',
      textoAceptar: `Corregir ${r.cuantos}`,
      ancho: '680px',
      campos: `
        <p class="suave" style="margin:0;font-size:13.5px">
          Estas clases se importaron con el monto que se le paga al <strong>maestro</strong>, no con
          lo que paga el alumno. El precio correcto sale de la materia y la duración.
          ${r.sin_materia ? `<br>Hay otras ${r.sin_materia} sin materia asignada: esas no se pueden calcular todavía.` : ''}
        </p>
        <div class="tabla-scroll" style="max-height:340px;overflow-y:auto">
          <table>
            <thead><tr><th>Alumno</th><th>Materia</th><th>Duración</th><th class="derecha">Antes</th><th class="derecha">Queda en</th></tr></thead>
            <tbody>${r.cambios.map(c => `
              <tr>
                <td>${esc(c.alumno)}</td>
                <td class="suave">${esc(c.materia)}</td>
                <td class="suave">${textoDuracion(c.duracion_min)}</td>
                <td class="derecha cifra suave">${dinero(c.antes)}</td>
                <td class="derecha cifra">${dinero(c.despues)}</td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
        <p style="text-align:right;margin:0;font-size:14px">
          Las colegiaturas pasan de <strong>${dinero(r.suma_antes)}</strong> a
          <strong class="verde">${dinero(r.suma_despues)}</strong> al mes.
        </p>`,
      alAceptar: async (_d, cerrar) => {
        const hecho = await api('pendientes.php?accion=recalcular_precios', { cuerpo: { aplicar: true } });
        aviso(`${hecho.cuantos} precios corregidos.`, 'exito');
        cerrar();
        refrescar();
      }
    });
  });

  // Fusionar dos registros en uno
  $$('[data-fusionar]').forEach(b => b.addEventListener('click', async () => {
    const tipo = b.dataset.fusionar;
    if (!confirm(`Todo lo del registro repetido se va a mover a ${b.dataset.nombre}, y el repetido se borra.\n\n` +
      'Las clases que existan en los dos se conservan una sola vez, y si dos caen a la misma hora ' +
      'con alumnos distintos, una se queda sin horario para que tú decidas dónde va. ' +
      'Esto no se puede deshacer. ¿Continuar?')) return;
    try {
      const r = await api('pendientes.php?accion=fusionar', {
        cuerpo: { tipo, principal: Number(b.dataset.principal), duplicado: Number(b.dataset.duplicado) }
      });
      aviso(`Fusionados${r.respaldo ? ' (respaldo previo guardado)' : ''}. ${r.clases_movidas} clases movidas${
        r.clases_repetidas ? `, ${r.clases_repetidas} repetidas descartadas` : ''}${
        r.sin_horario ? `, ${r.sin_horario} quedaron sin horario por empalme` : ''}.`, 'exito');
      refrescar();
    } catch (e) { aviso(e.message, 'error'); }
  }));

  $$('[data-unir-a]').forEach(b => b.addEventListener('click', async () => {
    try {
      const r = await api('pendientes.php?accion=unir', {
        cuerpo: { id_a: Number(b.dataset.unirA), id_b: Number(b.dataset.unirB) }
      });
      aviso(`Clases unidas. Se conservó la que tiene horario, con toda su nómina.${
        r.respaldo ? ' Respaldo previo guardado.' : ''}`, 'exito');
      refrescar();
    } catch (e) { aviso(e.message, 'error'); }
  }));

  const inscripcionDe = (id) => [...p.sin_horario, ...p.sin_materia, ...p.sin_precio]
    .find(i => String(i.id_inscripcion) === String(id));

  $$('[data-asignar]').forEach(b => b.addEventListener('click', () =>
    formularioHorario(inscripcionDe(b.dataset.asignar), () => refrescar())));

  $$('[data-editar]').forEach(b => b.addEventListener('click', () =>
    formularioHorario(inscripcionDe(b.dataset.editar), () => refrescar())));

  $$('[data-borrar]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm(`¿Quitar ${b.dataset.que}?\n\nSe da de baja la inscripción y se toma como un registro `
      + 'que no debía existir, así que no le queda adeudo. Al maestro se le pagan las clases que ya '
      + 'dio este mes, y los pagos del alumno se conservan.')) return;
    try {
      // Desde Pendientes se limpian duplicados y sobras de importación: sin adeudo.
      const r = await api(`inscripciones.php?id=${b.dataset.borrar}&adeudo=0`, { method: 'DELETE' });
      aviso('Inscripción dada de baja.', 'exito');
      refrescar();
      avisarListaEspera(r?.lista_espera);
    } catch (e) { aviso(e.message, 'error'); }
  }));

  $$('[data-alumno]').forEach(b => b.addEventListener('click', async () => {
    const alumnos = await api('alumnos.php');
    const a = alumnos.find(x => String(x.id_alumno) === b.dataset.alumno);
    if (a) formularioAlumno(a);
  }));
};

// El enlace viejo sigue funcionando.
vistas.inscripciones = vistas.pendientes;

/* ====================================================================
   VISTA · LISTA DE ESPERA
   Quien quiere una clase que hoy está llena. Una solicitud no es
   inscripción: no ocupa horario ni genera nómina, cargos o deuda. Cuando se
   libera su lugar, el sistema avisa; inscribir siempre lo hace una persona.
   ==================================================================== */

const MODALIDADES_ESPERA = [
  { valor: 'presencial', texto: 'Presencial' },
  { valor: 'online', texto: 'En línea' },
  { valor: 'cualquiera', texto: 'Cualquiera de las dos' }
];

let filtroEspera = 'abiertas';

vistas.espera = async () => {
  const lista = await api(`lista_espera.php?estado=${filtroEspera}`);

  const pide = (f) => [
    f.dia_semana ? `${f.dia_semana}${f.hora_inicio ? ' ' + hora(f.hora_inicio) : ''}` : 'Cualquier día',
    textoDuracion(f.duracion_min),
    f.modalidad === 'online' ? 'en línea' : (f.modalidad === 'cualquiera' ? 'presencial o en línea' : ''),
    f.maestro ? `con ${f.maestro}` : '',
    f.salon ? f.salon : ''
  ].filter(Boolean).join(' · ');

  const estado = (f) => {
    const base = {
      pendiente: '<span class="etiqueta-estado ambar">Pendiente</span>',
      contactado: '<span class="etiqueta-estado">Contactado</span>',
      inscrito: '<span class="etiqueta-estado verde">Inscrito</span>',
      cancelado: '<span class="etiqueta-estado suave">Cancelado</span>'
    }[f.estado] || esc(f.estado);
    return base + (f.disponible_ahora ? ' <span class="etiqueta-estado verde" title="Ese lugar está libre ahora mismo">Hay lugar</span>' : '');
  };

  const abierta = (f) => ['pendiente', 'contactado'].includes(f.estado);

  vista.innerHTML = `
    <div class="tarjeta">
      <header style="gap:10px">
        <h2>Lista de espera <span class="suave" style="font-weight:400">· ${lista.length}</span></h2>
        <div class="pestanas" id="filtro-espera">
          ${[['abiertas', 'Abiertas'], ['inscrito', 'Inscritas'], ['cancelado', 'Canceladas']].map(([v, t]) => `
            <button type="button" data-filtro="${v}" aria-selected="${filtroEspera === v}">${t}</button>`).join('')}
        </div>
        <button class="pildora pildora-azul pildora-chica" data-escribe id="b-nueva-espera">Anotar solicitud</button>
      </header>
      <div class="cuerpo suave" style="border-bottom:1px solid var(--linea);font-size:13.5px;padding-bottom:14px">
        Anotar a alguien no lo inscribe ni le aparta el lugar. Cuando se libera un lugar que coincide
        exactamente con lo que pidió (materia, día, hora de inicio y, si los pidió, maestro y salón), el sistema
        avisa. "Hay lugar" quiere decir que ese lugar está libre ahora mismo: contáctalo e inscríbelo.
      </div>
      ${lista.length ? `
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Quién</th><th>Materia</th><th>Pide</th><th>Desde</th><th>Estado</th><th></th></tr></thead>
          <tbody>${lista.map(f => `
            <tr>
              <td>${esc(f.quien)}${f.id_alumno ? '' : ' <span class="suave" style="font-size:12.5px">(no es alumno)</span>'}
                <div class="suave" style="font-size:12.5px">${esc(f.contacto || 'Sin teléfono')}</div></td>
              <td>${esc(f.materia)}</td>
              <td class="suave">${esc(pide(f))}${f.notas ? `<div style="font-size:12.5px">${esc(f.notas)}</div>` : ''}</td>
              <td class="suave cifra">${esc((f.fecha_solicitud || '').slice(0, 10))}</td>
              <td>${estado(f)}</td>
              <td class="derecha"><div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end;min-width:190px">
                ${abierta(f) ? `
                  ${f.contacto ? `<button class="pildora pildora-linea pildora-chica" data-wa-espera="${f.id_lista_espera}">WhatsApp</button>` : ''}
                  <button class="pildora pildora-linea pildora-chica" data-escribe data-editar-espera="${f.id_lista_espera}">Editar</button>
                  ${f.estado === 'pendiente' ? `<button class="pildora pildora-linea pildora-chica" data-escribe data-estado-espera="${f.id_lista_espera}" data-estado="contactado">Contactado</button>` : ''}
                  <button class="pildora pildora-roja pildora-chica" data-escribe data-estado-espera="${f.id_lista_espera}" data-estado="cancelado">Cancelar</button>
                  <button class="pildora pildora-azul pildora-chica" data-escribe data-inscribir-espera="${f.id_lista_espera}">Inscribir</button>`
                : f.estado === 'cancelado' ? `
                  <button class="pildora pildora-linea pildora-chica" data-escribe data-estado-espera="${f.id_lista_espera}" data-estado="pendiente">Reabrir</button>` : ''}
              </div></td>
            </tr>`).join('')}</tbody>
        </table>
      </div>` : `<div class="mensaje-vacio">${filtroEspera === 'abiertas'
          ? 'Nadie está esperando lugar.' : 'No hay solicitudes en esta lista.'}</div>`}
    </div>`;

  const de = (id) => lista.find(f => String(f.id_lista_espera) === String(id));

  $$('[data-filtro]').forEach(b => b.addEventListener('click', () => { filtroEspera = b.dataset.filtro; refrescar(); }));
  $('#b-nueva-espera').addEventListener('click', () => formularioEspera(null, {}));
  $$('[data-editar-espera]').forEach(b => b.addEventListener('click', () => formularioEspera(de(b.dataset.editarEspera))));

  $$('[data-estado-espera]').forEach(b => b.addEventListener('click', async () => {
    const nuevo = b.dataset.estado;
    if (nuevo === 'cancelado' && !confirm('¿Cancelar esta solicitud? Deja de avisarse cuando se libere su lugar.')) return;
    try {
      await api(`lista_espera.php?id=${b.dataset.estadoEspera}&accion=estado`, { method: 'PUT', cuerpo: { estado: nuevo } });
      aviso(nuevo === 'contactado' ? 'Marcada como contactada.' : nuevo === 'cancelado' ? 'Solicitud cancelada.' : 'Solicitud reabierta.', 'exito');
      refrescar();
    } catch (e) { aviso(e.message, 'error'); }
  }));

  $$('[data-wa-espera]').forEach(b => b.addEventListener('click', () => {
    const f = de(b.dataset.waEspera);
    avisarPorWhatsApp(f.contacto, `Hola, buen día. Le escribimos de la escuela: se abrió un lugar para la clase de `
      + `${f.materia}${f.dia_semana ? ` el ${f.dia_semana}${f.hora_inicio ? ' a las ' + hora(f.hora_inicio) : ''}` : ''}. `
      + '¿Le interesa que le apartemos la inscripción? Quedamos atentos, gracias.');
  }));

  // Inscribir es un alta normal, con todas sus revisiones. La solicitud se
  // cierra en la misma operación del alta: si el alta falla, sigue abierta.
  $$('[data-inscribir-espera]').forEach(b => b.addEventListener('click', async () => {
    const f = de(b.dataset.inscribirEspera);
    const clase = {
      id_instrumento: f.id_instrumento, id_maestro: f.id_maestro, id_salon: f.id_salon,
      modalidad: f.modalidad === 'online' ? 'online' : 'presencial',
      dia_semana: f.dia_semana, hora_inicio: f.hora_inicio, duracion_min: f.duracion_min
    };
    try {
      if (f.id_alumno) {
        await formularioAlta(Number(f.id_alumno), { id_lista_espera: f.id_lista_espera, clase });
      } else {
        // Todavía no es alumno: primero sus datos; se registra junto con su clase.
        const [nombre, ...resto] = String(f.nombre || '').trim().split(/\s+/);
        formularioAlumno(null, {
          id_lista_espera: f.id_lista_espera, clase,
          alumno: { nombre, apellido: resto.join(' '), telefono: f.telefono, email: f.correo }
        });
      }
    } catch (e) { aviso(e.message, 'error'); }
  }));
};

/**
 * Anotar o corregir una solicitud de la lista de espera.
 * @param {object|null} solicitud la que se edita, o null para una nueva
 * @param {object} prefill valores iniciales (por ejemplo, desde un alta que no se pudo)
 */
async function formularioEspera(solicitud, prefill = {}) {
  const alumnos = await api('alumnos.php?activos=1');
  const v = { duracion_min: 60, modalidad: 'presencial', prioridad: 0, ...prefill, ...(solicitud || {}) };
  const materias = catalogos?.instrumentos ?? [];
  const esGrupal = (id) => Number(materias.find(x => String(x.id_instrumento) === String(id))?.es_grupal) === 1;
  const sinPreferencia = (texto) => [{ valor: '', texto }];
  const maestros = (window.__maestros?.length ? window.__maestros : (window.__maestros = await api('maestros.php')))
    .filter(m => Number(m.activo));

  modal({
    titulo: solicitud ? 'Corregir solicitud' : 'Anotar en la lista de espera',
    textoAceptar: solicitud ? 'Guardar' : 'Anotar',
    ancho: '620px',
    alAceptar: async (datos, cerrar) => {
      const online = datos.modalidad === 'online';
      const cuerpo = {
        id_alumno: datos.id_alumno ? Number(datos.id_alumno) : null,
        nombre: datos.id_alumno ? null : datos.nombre.trim(),
        telefono: datos.id_alumno ? null : datos.telefono.trim(),
        correo: datos.id_alumno ? null : datos.correo.trim(),
        id_instrumento: Number(datos.id_instrumento),
        id_maestro: datos.id_maestro ? Number(datos.id_maestro) : null,
        id_salon: online || !datos.id_salon ? null : Number(datos.id_salon),
        modalidad: datos.modalidad,
        dia_semana: datos.dia_semana || null,
        hora_inicio: datos.hora_inicio || null,
        duracion_min: Number(datos.duracion_min),
        prioridad: Number(datos.prioridad),
        notas: datos.notas.trim()
      };
      if (!cuerpo.id_alumno && (!cuerpo.nombre || !cuerpo.telefono)) {
        throw new Error('Elige un alumno, o escribe el nombre y el teléfono de quien espera.');
      }
      if (solicitud) {
        await api(`lista_espera.php?id=${solicitud.id_lista_espera}`, { method: 'PUT', cuerpo });
        aviso('Solicitud corregida.', 'exito');
      } else {
        await api('lista_espera.php', { cuerpo });
        aviso('Anotado en la lista de espera. No se inscribió ni se apartó lugar.', 'exito');
      }
      cerrar();
      if (vistaActual === 'espera') refrescar(); else location.hash = '#espera';
    },
    campos: `
      ${campoSelect('id_alumno', 'Quién espera', [
          { valor: '', texto: '— Todavía no es alumno —' },
          ...alumnos.map(a => ({ valor: a.id_alumno, texto: nombreCompleto(a) }))], v.id_alumno ?? '')}
      <div id="datos-persona" class="fila-campos">
        ${campoTexto('nombre', 'Nombre completo', { valor: v.id_alumno ? '' : (v.nombre ?? '') })}
        ${campoTexto('telefono', 'Teléfono', { valor: v.id_alumno ? '' : (v.telefono ?? ''), pista: '555 123 4567' })}
        ${campoTexto('correo', 'Correo', { tipo: 'email', valor: v.id_alumno ? '' : (v.correo ?? '') })}
      </div>
      <div class="fila-campos">
        ${campoSelect('id_instrumento', 'Materia', opcionesInstr(), v.id_instrumento ?? materias[0]?.id_instrumento)}
        ${campoSelect('id_maestro', 'Maestro', [...sinPreferencia('Cualquier maestro'),
            ...maestros.map(m => ({ valor: m.id_maestro, texto: nombreCompleto(m) }))], v.id_maestro ?? '')}
      </div>
      <div class="fila-campos">
        ${campoSelect('dia_semana', 'Día', [...sinPreferencia('Sin preferencia'), ...opcionesDia()], v.dia_semana ?? '')}
        ${campoSelect('hora_inicio', 'Hora de inicio', [...sinPreferencia('Sin preferencia'), ...opcionesInicio()], v.hora_inicio ?? '')}
        ${campoSelect('duracion_min', 'Duración', DURACIONES, v.duracion_min)}
      </div>
      <div class="fila-campos">
        ${campoSelect('modalidad', 'Modalidad', MODALIDADES_ESPERA, v.modalidad)}
        ${campoSelect('id_salon', 'Salón', [...sinPreferencia('Cualquier salón'), ...opcionesSalon()], v.id_salon ?? '')}
        ${campoSelect('prioridad', 'Prioridad', [
            { valor: 0, texto: 'Normal' }, { valor: 50, texto: 'Alta' }, { valor: 100, texto: 'Muy alta' }],
            [0, 50, 100].includes(Number(v.prioridad)) ? Number(v.prioridad) : 0)}
      </div>
      ${campoTexto('notas', 'Notas', { valor: v.notas ?? '', pista: 'Por ejemplo: también le sirve el jueves' })}
      <p class="suave" style="font-size:13px;margin:0">
        Solo se avisa cuando se libera un lugar con el mismo día y la misma hora de inicio que pidió.
        Sin día u hora, la solicitud se ve en la lista pero no dispara el aviso.
      </p>`
  });

  const ajustar = () => {
    $('#datos-persona').classList.toggle('oculto', Boolean($('#c-id_alumno').value));
    const grupal = esGrupal($('#c-id_instrumento').value);
    const sel = $('#c-modalidad');
    $$('option', sel).forEach(o => { o.disabled = grupal && o.value !== 'presencial'; });
    if (grupal) sel.value = 'presencial';
    $('#c-id_salon').parentElement.classList.toggle('oculto', sel.value === 'online');
  };
  ['id_alumno', 'id_instrumento', 'modalidad'].forEach(c => $(`#c-${c}`).addEventListener('change', ajustar));
  ajustar();
}

const DURACIONES = [
  { valor: 30, texto: 'Media hora' },
  { valor: 60, texto: 'Una hora' },
  { valor: 90, texto: 'Hora y media' },
  { valor: 120, texto: 'Dos horas' },
  { valor: 150, texto: 'Dos horas y media' },
  { valor: 180, texto: 'Tres horas' },
  { valor: 210, texto: 'Tres horas y media' },
  { valor: 240, texto: 'Cuatro horas' },
];

/** '17:00:00' + 90 min -> '18:30:00' */
const sumarMinutos = (hora, minutos) => {
  const [h, m] = (hora || '00:00').split(':').map(Number);
  const t = h * 60 + m + Number(minutos);
  return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}:00`;
};

const textoDuracion = (min) => DURACIONES.find(d => d.valor === Number(min))?.texto || `${min} min`;

const opcionesDia   = () => (catalogos?.dias ?? []).map(d => ({ valor: d, texto: d }));
const opcionesSalon = () => (catalogos?.salones ?? []).map(s => ({ valor: s.id_salon, texto: s.nombre }));
const opcionesInstr = () => (catalogos?.instrumentos ?? []).map(i => ({ valor: i.id_instrumento, texto: i.nombre }));
// Los bloques son de media hora: sirven como horas de inicio posibles.
const opcionesInicio = () => (catalogos?.bloques ?? []).map(b => ({
  valor: b.hora_inicio, texto: hora(b.hora_inicio)
}));

/**
 * Modalidad, clase compartida y vista previa del hueco. Lo usan el alta y la
 * edición de una clase. Quien decide es el servidor: aquí solo se enseña, antes
 * de guardar, qué va a pasar (sola, en un grupo, compartida o si choca).
 */
const camposHueco = ({ modalidad = 'presencial', compartida = false } = {}) => `
  <div class="fila-campos">
    ${campoSelect('modalidad', 'Modalidad',
      [{ valor: 'presencial', texto: 'Presencial' }, { valor: 'online', texto: 'En línea' }], modalidad || 'presencial')}
    <div id="caja-compartir" style="align-self:end">
      <label style="display:flex;gap:9px;align-items:center;font-size:13.5px;padding-bottom:11px">
        <input type="checkbox" id="c-compartida" ${compartida ? 'checked' : ''}>
        <span>Clase compartida <span class="suave">(2 alumnos, misma hora)</span></span>
      </label>
    </div>
  </div>
  <p id="nota-linea" class="suave oculto" style="font-size:13px;margin:-2px 0 8px">Pintura y Dibujo son un taller en el salón: no se dan en línea.</p>
  <div id="elige-compartida" class="oculto">
    <label class="etiqueta" for="c-compartir_con">La comparte con</label>
    <select class="campo" id="c-compartir_con" name="compartir_con"></select>
    <p class="suave" style="font-size:13px;margin:6px 0 0">
      Tiene que ser el mismo maestro, salón (o las dos en línea), día, hora de inicio y hora de fin.
      Cada alumno paga lo suyo.<span data-solo-admin> Al maestro se le paga la clase de cada uno.</span>
    </p>
  </div>`;

/**
 * Engancha los campos de camposHueco al resto del formulario.
 * @returns {{leer: Function, estado: Function, revisar: Function, compartirCon: Function}}
 */
function enlazarHueco({ idInscripcion = null, idAlumno = () => null, esGrupal, compartirCon = null, alCambiar = () => {},
                        juntar = false, especial = () => null, ocultarCompartir = () => false,
                        simularDos = null, bloqueo = null }) {
  let ultimo = { ok: null };
  let turno = 0;

  // Mientras la vista previa diga "así no se puede", el botón principal se queda apagado y debajo
  // se explica qué cambiar. Lo que aún no se sabe (o un fallo de conexión) no bloquea: el servidor
  // decide al guardar. `bloqueo` = { pista, excepto(horario) }: excepto deja pasar, por ejemplo, la
  // clase que ya estaba así antes de tocarla.
  const aplicarBloqueo = () => {
    if (!bloqueo) return;
    const no = ultimo.ok === false && !ultimo.pendiente && !bloqueo.excepto?.(leer());
    bloquearAceptar(no, typeof bloqueo.pista === 'function' ? bloqueo.pista() : bloqueo.pista);
  };
  const notificar = () => { aplicarBloqueo(); alCambiar(ultimo); };

  const leer = () => {
    const minutos = Number($('#c-duracion_min').value);
    const online = $('#c-modalidad').value === 'online';
    return {
      id_instrumento: $('#c-id_instrumento').value, id_maestro: $('#c-id_maestro').value,
      modalidad: online ? 'online' : 'presencial', id_salon: online ? '' : $('#c-id_salon').value,
      dia_semana: $('#c-dia_semana').value, hora_inicio: $('#c-hora_inicio').value,
      hora_fin: sumarMinutos($('#c-hora_inicio').value, minutos), duracion_min: minutos
    };
  };
  const compartida = () => $('#c-compartida').checked && !$('#caja-compartir').classList.contains('oculto');
  // "j:<id>": otra clase del maestro que se mueve a esta hora para compartirla.
  const valorCompartir = () => compartida() ? ($('#c-compartir_con').value || '') : '';
  const juntarCon = () => valorCompartir().startsWith('j:') ? Number(valorCompartir().slice(2)) : null;

  // Pintura y dibujo: siempre presenciales, y el grupo se arma solo.
  const ajustar = () => {
    const grupal = esGrupal($('#c-id_instrumento').value);
    const sel = $('#c-modalidad');
    const opLinea = sel.querySelector('option[value="online"]');
    opLinea.disabled = grupal;
    opLinea.textContent = grupal ? 'En línea (Pintura y Dibujo son en el salón)' : 'En línea';
    const nota = $('#nota-linea');
    if (nota) nota.classList.toggle('oculto', !grupal);
    if (grupal && sel.value === 'online') sel.value = 'presencial';
    $('#c-id_salon').parentElement.classList.toggle('oculto', sel.value === 'online');
    $('#caja-compartir').classList.toggle('oculto', grupal || ocultarCompartir());
    $('#elige-compartida').classList.toggle('oculto', !compartida());
  };

  const cargarCompartibles = async () => {
    const select = $('#c-compartir_con');
    if (!compartida()) { select.innerHTML = ''; return; }
    const d = leer();
    const q = new URLSearchParams({ accion: 'compartibles', id_maestro: d.id_maestro, modalidad: d.modalidad,
      id_salon: d.id_salon, dia_semana: d.dia_semana, hora_inicio: d.hora_inicio, hora_fin: d.hora_fin });
    if (idInscripcion) q.set('id', idInscripcion);
    let lista = [], otras = [];
    try { lista = await api(`inscripciones.php?${q}`); } catch { lista = []; }
    // Al editar, también se puede juntar con otra clase del maestro a otra hora:
    // esa se mueve aquí y comparten. Lo de dos hermanos en la misma clase, en un paso.
    if (juntar && d.id_maestro) {
      try {
        otras = await api(`inscripciones.php?accion=juntables&id_maestro=${d.id_maestro}${idInscripcion ? '&id=' + idInscripcion : ''}`);
      } catch { otras = []; }
    }
    if (!document.body.contains(select)) return;
    const antes = select.value || (compartirCon ? String(compartirCon) : '');
    const utiles = lista.filter(c => !Number(c.es_grupal));
    const exactas = new Set(utiles.map(c => String(c.id_inscripcion)));
    const movibles = otras.filter(c => !exactas.has(String(c.id_inscripcion)) && Number(c.en_clase) < 2 && !c.comparte_con);
    const grupos = [];
    if (utiles.length) {
      grupos.push(`<optgroup label="A esta misma hora">${utiles.map(c => {
        const llena = Number(c.en_clase) >= 2;
        return `<option value="${c.id_inscripcion}" ${llena ? 'disabled' : ''}
                  ${String(c.id_inscripcion) === antes ? 'selected' : ''}>${esc(nombreCompleto(c, 'alumno_'))} · ${
                  esc(c.instrumento)}${llena ? ' (ya son 2)' : ''}</option>`;
      }).join('')}</optgroup>`);
    }
    const opcionMovible = (c) => `
        <option value="j:${c.id_inscripcion}" ${'j:' + c.id_inscripcion === antes ? 'selected' : ''}
                data-nombre="${esc(nombreCompleto(c, 'alumno_'))}">${esc(nombreCompleto(c, 'alumno_'))} · ${esc(c.instrumento)} ·
          ${esc(diaBonito(c.dia_semana))} ${hora(c.hora_inicio)}–${hora(c.hora_fin)}</option>`;
    const mismoDia = movibles.filter(c => c.dia_semana === d.dia_semana);
    const otroDia = movibles.filter(c => c.dia_semana !== d.dia_semana);
    if (mismoDia.length) {
      grupos.push(`<optgroup label="El mismo día, a otra hora: se mueve a esta hora">${mismoDia.map(opcionMovible).join('')}</optgroup>`);
    }
    if (otroDia.length) {
      grupos.push(`<optgroup label="Otro día: se mueve a este día y hora">${otroDia.map(opcionMovible).join('')}</optgroup>`);
    }
    select.innerHTML = grupos.join('') || `<option value="">No hay otra clase de ese maestro${
      juntar ? '' : ` a esa misma hora${d.modalidad === 'online' ? ' en línea' : ' en ese salón'}`}</option>`;
  };

  const previsualizar = async () => {
    const mio = ++turno;
    const d = leer();
    const caja = $('#vista-hueco');
    if (!caja) return;
    if (!d.id_maestro || !d.hora_inicio) {
      ultimo = { ok: null };
      caja.className = 'vista-hueco suave';
      caja.textContent = !d.id_maestro ? 'Elige el maestro para ver si ese horario está libre.'
        : 'Elige la hora para ver si ese horario está libre.';
      notificar();
      return;
    }
    // Operaciones de dos clases (mover a la pareja, juntar con otra): el
    // servidor revisa las dos al guardar; aquí solo se explica qué va a pasar.
    const opcion = $('#c-compartir_con')?.selectedOptions?.[0];
    const aviso2 = especial() || (juntarCon() && opcion ? `Se juntan: ${opcion.dataset.nombre} pasa a esta misma hora y
      comparten la clase (cada una con su pago${esAdmin() ? ' y su nómina' : ''}). Al guardar se revisa que el horario esté libre para las dos.` : null);
    if (aviso2) {
      caja.className = 'vista-hueco suave';
      caja.textContent = 'Revisando el horario para las dos…';
      let sim = { posible: true };
      if (simularDos) {
        try { sim = await simularDos(juntarCon()); } catch (e) { sim = { posible: false, error: e.message }; }
      }
      if (mio !== turno || !document.body.contains(caja)) return;
      ultimo = sim.posible ? { ok: true, especial: true } : { ok: false, error: sim.error, especial: true };
      caja.className = 'vista-hueco ' + (sim.posible ? 'ok' : 'choca');
      caja.innerHTML = sim.posible
        ? `<strong class="verde">Así queda.</strong> ${esc(aviso2.replace(' Al guardar se revisa que el horario esté libre para las dos.', ''))}`
        : `<strong class="rojo">Así no se puede.</strong> ${esc(sim.error)}`;
      notificar();
      return;
    }
    if (compartida() && !valorCompartir()) {
      ultimo = { ok: false, pendiente: true, error: 'Elige con qué clase la comparte, o quita la marca de clase compartida.' };
    } else {
      const q = new URLSearchParams({ accion: 'hueco', ...d });
      const alumno = idAlumno();
      if (alumno) q.set('id_alumno', alumno);
      if (idInscripcion) q.set('id', idInscripcion);
      if (compartida() && !juntarCon()) q.set('compartir_con', valorCompartir());
      try {
        const r = await api(`inscripciones.php?${q}`);
        ultimo = r.posible === false ? { ok: false, error: r.error } : { ok: true, res: r };
      } catch (e) {
        ultimo = { ok: false, pendiente: true, error: e.message };
      }
    }
    if (mio !== turno || !document.body.contains(caja)) return;
    caja.className = 'vista-hueco ' + (ultimo.ok ? 'ok' : 'choca');
    caja.innerHTML = ultimo.ok
      ? `<strong class="verde">Se puede.</strong> ${esc(ultimo.res.mensaje)}${
          ultimo.res.modalidad === 'online' ? ' <span class="insignia-online">EN LÍNEA</span>' : ''}`
      : `<strong class="rojo">Así no se puede.</strong> ${esc(ultimo.error)}`;
    notificar();
  };

  const revisar = async () => { ajustar(); await cargarCompartibles(); await previsualizar(); };

  ['id_instrumento', 'id_maestro', 'modalidad', 'id_salon', 'dia_semana', 'hora_inicio', 'duracion_min']
    .forEach(c => $(`#c-${c}`)?.addEventListener('change', revisar));
  $('#c-compartida').addEventListener('change', revisar);
  $('#c-compartir_con').addEventListener('change', previsualizar);
  revisar();

  return {
    leer, revisar,
    estado: () => ultimo,
    compartirCon: () => (valorCompartir() && !juntarCon()) ? Number(valorCompartir()) : null,
    juntarCon
  };
}

/**
 * Si el servidor rechaza el alta o el cambio por algo de un campo, en cuál está.
 * Devuelve el campo (o null: entonces el error va en el recuadro del formulario).
 */
const enPantalla = (c) => (c && c.offsetParent !== null ? c : null);
const campoDeErrorClase = (mensaje) => {
  const m = String(mensaje || '');
  if (/fecha del cambio/i.test(m)) return enPantalla($('#c-fecha_cambio'));
  if (/fecha de inicio/i.test(m)) return enPantalla($('#c-fecha_inicio'));
  if (/^Elige el salón/i.test(m)) return enPantalla($('#c-id_salon'));
  if (/^Falta el alumno/i.test(m)) return enPantalla($('#c-id_alumno'));
  if (/^Faltan el maestro|maestro no existe/i.test(m)) return enPantalla($('#c-id_maestro'));
  if (/^Elige con qué clase|quieres compartir/i.test(m)) return enPantalla($('#c-compartir_con'));
  if (/inscripción/i.test(m)) return enPantalla($('#c-monto_inscripcion'));
  return null;
};

/**
 * "desde el 15 de octubre", o "desde octubre" si rige todo el mes (día 1 o antes de su primera clase).
 * @param {string} fecha AAAA-MM-DD
 * @param {boolean} mesCompleto
 */
const cuandoRige = (fecha, mesCompleto) => {
  const [, m, d] = String(fecha).split('-').map(Number);
  const mes = NOMBRE_MES[(m || 1) - 1];
  return mesCompleto || d === 1 ? `desde ${mes}` : `desde el ${d} de ${mes}`;
};

/**
 * Alta de una clase. Todo en una pantalla: alumno, materia, maestro, salón,
 * horario y costo. El maestro se filtra por la materia elegida, el costo se
 * pone solo y abajo se ve el resumen de lo que va a quedar.
 *
 * @param {number|null} idAlumno para inscribir a alguien concreto sin buscarlo
 * @param {object} precarga {alumno_nuevo, id_borrador, id_lista_espera, clase}
 *   · alumno_nuevo: el alumno todavía no existe; se crea junto con su clase,
 *     en la misma operación del servidor. Si la clase no se puede, no se crea.
 *   · clase: valores con que empieza el formulario (borrador o lista de espera).
 */
async function formularioAlta(idAlumno = null, precarga = {}) {
  const nuevo = precarga.alumno_nuevo || null;
  const pre = precarga.clase || {};
  const [alumnos, maestros] = await Promise.all([api('alumnos.php?activos=1'), api('maestros.php')]);
  const materias = catalogos?.instrumentos ?? [];
  const activos = maestros.filter(m => Number(m.activo));
  // El importe que se propone de inscripción (Ajustes → Cobros); 0 = no se propone ninguno.
  const cuota = Number(catalogos?.configuracion?.costo_inscripcion) || 0;

  const costoHoraDe = (id) => Number(materias.find(m => String(m.id_instrumento) === String(id))?.costo_mensual) || 0;
  const costoPorDuracion = (idMateria, min) => Math.round(costoHoraDe(idMateria) * (Number(min) / 60) * 100) / 100;

  // Quién imparte esa materia. Si nadie la tiene marcada, se ofrecen todos:
  // más vale dejar inscribir que bloquear por un catálogo incompleto.
  const maestrosDe = (idMateria) => {
    const suyos = activos.filter(m =>
      String(m.ids_instrumentos || '').split(',').includes(String(idMateria)));
    return suyos.length ? suyos : activos;
  };

  const opcionesMaestro = (idMateria) => maestrosDe(idMateria)
    .map(m => ({ valor: m.id_maestro, texto: nombreCompleto(m) }));

  const materiaInicial = pre.id_instrumento || materias[0]?.id_instrumento;
  let esNuevo = Boolean(nuevo);
  const esGrupal = (id) => Number(materias.find(x => String(x.id_instrumento) === String(id))?.es_grupal) === 1;
  let hueco = null;
  let horaAMano = false;      // la hora la eligió la persona (si no, el sistema propone la primera libre)

  // Lo capturado, en la forma que piden el alta, el borrador y la lista de espera.
  const claseDe = (datos) => {
    const minutos = Number(datos.duracion_min);
    const online = datos.modalidad === 'online';
    return {
      id_instrumento: Number(datos.id_instrumento), id_maestro: Number(datos.id_maestro),
      modalidad: online ? 'online' : 'presencial', id_salon: online ? null : Number(datos.id_salon),
      dia_semana: datos.dia_semana,
      hora_inicio: datos.hora_inicio, hora_fin: sumarMinutos(datos.hora_inicio, minutos),
      duracion_min: minutos,
      costo_mensual: Number(datos.costo_mensual),
      fecha_inicio: datos.fecha_inicio || null,
      // Si la hora la eligió la persona o la propuso el sistema (al volver de "Es alumno nuevo" se sigue proponiendo).
      hora_a_mano: horaAMano,
      compartir_con: hueco?.compartirCon() ?? null
    };
  };
  const nombreNuevo = nuevo ? `${nuevo.nombre} ${nuevo.apellido}`.trim() : '';
  const quienEs = (datos) => nuevo
    ? { nombre: nombreNuevo, telefono: nuevo.telefono || nuevo.tutor_telefono || '', correo: nuevo.email || '' }
    : { id_alumno: Number(datos.id_alumno) };

  const cerrarAlta = modal({
    titulo: nuevo ? `Inscribir a ${nombreNuevo}` : 'Inscribir en una clase',
    textoAceptar: 'Dar de alta',
    ancho: '660px',
    protegerCambios: true,
    // Un alumno nuevo ya capturado en el paso anterior también se perdería al cancelar.
    hayCambiosExtra: () => Boolean(nuevo) && !precarga.id_borrador,
    campoDeError: campoDeErrorClase,
    extras: [{
      texto: precarga.id_borrador ? 'Guardar cambios del borrador' : 'Guardar como borrador',
      alPulsar: async (datos, cerrar) => {
        const e = hueco?.estado();
        await guardarBorrador(precarga.id_borrador, {
          id_alumno: nuevo ? null : Number(datos.id_alumno),
          alumno: nuevo || {},
          clase: { ...claseDe(datos), id_lista_espera: precarga.id_lista_espera || null },
          motivo: e && e.ok === false ? `No se pudo en ese horario: ${e.error}`.slice(0, 200) : 'Alta sin terminar'
        });
        cerrar();
        refrescar();
      }
    }],
    alAceptar: async (datos, cerrar) => {
      const clase = claseDe(datos);

      // Lo que falta se marca en su campo, con el motivo debajo, y el cursor va al primero.
      const faltan = [];
      const falta = (selector, mensaje) => { const c = $(selector); if (c) faltan.push([c, mensaje]); };
      if (!nuevo && !datos.id_alumno) falta('#c-id_alumno', 'Elige a qué alumno inscribes.');
      if (!datos.id_instrumento) falta('#c-id_instrumento', 'Elige la materia.');
      if (!datos.id_maestro) falta('#c-id_maestro', 'Elige el maestro de la clase.');
      if (!datos.hora_inicio) falta('#c-hora_inicio', 'Elige a qué hora empieza.');
      if (datos.modalidad !== 'online' && !datos.id_salon) falta('#c-id_salon', 'Elige el salón.');
      if (String(datos.costo_mensual ?? '').trim() === '') falta('#c-costo_mensual', 'Escribe cuánto paga al mes.');
      else if (Number(datos.costo_mensual) < 0) falta('#c-costo_mensual', 'No puede ser negativo.');
      if (!datos.fecha_inicio) falta('#c-fecha_inicio', 'Elige desde qué día empieza.');
      // Inscripción: si se cobra, el importe tiene que estar escrito (o venir de Ajustes).
      const cobraInsc = $('#cobrar-inscripcion').checked;
      const montoInsc = String($('#c-monto_inscripcion').value ?? '').trim();
      if (cobraInsc) {
        if (!(Number(montoInsc) > 0)) falta('#c-monto_inscripcion', 'Escribe cuánto es la inscripción.');
        else if (Number(montoInsc) > 100000) falta('#c-monto_inscripcion', 'Es demasiado alto. Revísalo.');
      }
      if ($('#c-compartida').checked && !$('#caja-compartir').classList.contains('oculto') && !clase.compartir_con) {
        falta('#c-compartir_con', 'Elige con qué clase la comparte, o quita la marca de clase compartida.');
      }
      if (faltan.length) {
        // Del último al primero: cada error enfoca su campo y el que queda enfocado es el primero.
        [...faltan].reverse().forEach(([campo, mensaje]) => errorEnCampo(campo, mensaje));
        return;
      }

      // En dibujo y pintura se puede inscribir a varios de un jalón: el primero
      // abre el taller de ese día (o entra al que ya hay) y los demás entran a la misma hora.
      const companeros = $$('[data-companero]:checked').map(c => Number(c.dataset.companero));

      const r = await api('inscripciones.php', {
        cuerpo: {
          ...clase,
          ...(nuevo ? { alumno_nuevo: nuevo } : { id_alumno: Number(datos.id_alumno) }),
          cobrar_inscripcion: cobraInsc ? 1 : 0,
          monto_inscripcion: cobraInsc ? Number(montoInsc) : null,
          registrar_pago_inicial: datos.pago === 'si',
          metodo_pago: datos.metodo,
          id_borrador: precarga.id_borrador || null,
          id_lista_espera: precarga.id_lista_espera || null
        }
      });
      let sumados = 0, fallidos = [];
      for (const id of companeros) {
        try {
          await api('inscripciones.php', {
            cuerpo: { ...clase, compartir_con: null, id_alumno: id, registrar_pago_inicial: false }
          });
          sumados++;
        } catch (e) {
          const a = alumnos.find(x => String(x.id_alumno) === String(id));
          fallidos.push(`${nombreCompleto(a)}: ${e.message}`);
        }
      }

      // Un solo aviso, corto y con el nombre. Sin adivinar el género de nadie.
      const tipo = hueco?.estado()?.res?.tipo;
      const quien = nuevo ? primerNombre(nuevo.nombre)
        : primerNombre(alumnos.find(x => String(x.id_alumno) === String(datos.id_alumno))?.nombre);
      const cuando = `${diaBonito(clase.dia_semana).toLowerCase()} ${hora(clase.hora_inicio)}–${hora(clase.hora_fin)}`;
      const donde = tipo === 'grupo' ? ' (taller)' : tipo === 'compartida' ? ' (clase compartida)' : '';
      // La nómina es cosa de administración: recepción solo se entera del horario.
      const alDia = esAdmin() ? 'Horario y nómina al día.' : 'Horario al día.';
      const primerPago = (datos.pago === 'si' && Number(r?.primer_mes?.paga_alumno) > 0
        ? ` Primer pago de ${dinero(r.primer_mes.paga_alumno)} registrado.` : '')
        + (Number(r?.inscripcion_cobrada) > 0 ? ` Inscripción de ${dinero(r.inscripcion_cobrada)} registrada.` : '');
      aviso(sumados
        ? `Inscripción lista: ${quien} y ${sumados} más, taller del ${cuando}. ${alDia}${primerPago}`
        : `Inscripción lista: ${quien}${donde}, ${cuando}. ${alDia}${primerPago}`, 'exito');
      if (fallidos.length) aviso('No entraron: ' + fallidos.join(' · '), 'error');
      cerrar();
      refrescar();
    },
    campos: `
      ${nuevo ? `
        <div class="vista-hueco">
          <span class="suave">Alumno nuevo:</span> <strong>${esc(nombreNuevo)}</strong>
          <div class="suave" style="font-size:13px;margin-top:3px">
            Se registra al dar de alta la clase. Si la cancelas, no queda nada guardado;
            usa "Guardar como borrador" si te falta algo.</div>
        </div>`
      : `
        <div>
          <label class="etiqueta" for="buscar-alumno-alta">Alumno</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input class="campo" id="buscar-alumno-alta" placeholder="Escribe su nombre para buscarlo"
                   autocomplete="off" style="flex:1;min-width:180px">
            <button type="button" class="pildora pildora-linea pildora-chica" id="b-alumno-nuevo">Es alumno nuevo</button>
          </div>
          <select class="campo" id="c-id_alumno" name="id_alumno" aria-label="Alumno" style="margin-top:8px">
            ${alumnos.map(a => `<option value="${a.id_alumno}" ${String(a.id_alumno) === String(idAlumno ?? '') ? 'selected' : ''}>${
              esc(nombreCompleto(a))}</option>`).join('')}
          </select>
        </div>`}
      <div id="grupo-extra"></div>
      <div class="fila-campos">
        ${campoSelect('id_instrumento', 'Materia', opcionesInstr(), materiaInicial)}
        ${campoSelect('id_maestro', 'Maestro', opcionesMaestro(materiaInicial), pre.id_maestro ?? '')}
      </div>
      <div class="fila-campos">
        ${campoSelect('dia_semana', 'Día', opcionesDia(), pre.dia_semana ?? '')}
        ${campoSelect('hora_inicio', 'Empieza a las',
          pre.hora_inicio && pre.hora_a_mano !== false ? opcionesInicio() : [{ valor: '', texto: 'Elige la hora' }, ...opcionesInicio()],
          pre.hora_inicio || '')}
        ${campoSelect('duracion_min', 'Duración', DURACIONES, pre.duracion_min || 60)}
      </div>
      ${camposHueco({ modalidad: pre.modalidad, compartida: Boolean(pre.compartir_con) })}
      <div class="fila-campos">
        ${campoSelect('id_salon', 'Salón', opcionesSalon(), pre.id_salon ?? '')}
        ${campoTexto('costo_mensual', 'Paga al mes', { tipo: 'number', paso: '0.01', valor: pre.costo_mensual ?? '' })}
        ${campoTexto('fecha_inicio', 'Empieza el', { tipo: 'date', valor: pre.fecha_inicio || hoyLocal() })}
      </div>
      <p id="nota-fecha" class="nota-campo oculto"></p>
      <div id="vista-hueco" class="vista-hueco suave">Revisando el horario…</div>
      <div class="fila-campos">
        ${campoSelect('pago', 'Registrar el primer pago', [{ valor:'si', texto:'Sí' }, { valor:'no', texto:'Todavía no' }])}
        ${campoSelect('metodo', 'Método de pago', [
          { valor:'efectivo', texto:'Efectivo' },
          { valor:'transferencia', texto:'Transferencia' },
          { valor:'tarjeta', texto:'Tarjeta' }])}
      </div>
      <div id="resumen-alta"></div>
      <div id="caja-inscripcion" style="border:1px solid var(--linea);border-radius:12px;padding:13px 16px">
        <label style="display:flex;gap:8px;align-items:center;font-size:14px;font-weight:500">
          <input type="checkbox" id="cobrar-inscripcion" ${nuevo ? 'checked' : ''}> Cobrar inscripción
        </label>
        <p class="nota-campo" id="nota-inscripcion" style="margin:4px 0 0 26px"></p>
        <div id="detalle-inscripcion" style="margin-top:10px;max-width:260px">
          ${campoTexto('monto_inscripcion', 'Importe de la inscripción',
            { tipo: 'number', paso: '0.01', valor: cuota > 0 ? cuota : '', pista: 'Escribe cuánto es la inscripción' })}
        </div>
      </div>`
  });

  // Siempre con las clases al mes del mes en que empieza (si entra el mes que viene y cambian, son las nuevas).
  const nominaDe = (idMaestro, min, sem) => {
    const m = activos.find(x => String(x.id_maestro) === String(idMaestro));
    if (!m) return 0;
    return m.modo_pago === 'por_hora'
      ? Math.round(Number(m.tarifa_por_hora) * (Number(min) / 60) * sem * 100) / 100
      : Number(m.tarifa_por_alumno);
  };

  // Si la materia es grupal, se ofrece marcar a más alumnos para el mismo horario.
  const pintarGrupo = () => {
    const caja = $('#grupo-extra');
    const materia = $('#c-id_instrumento').value;
    if (!esGrupal(materia)) { caja.innerHTML = ''; return; }

    const elegido = $('#c-id_alumno')?.value ?? '';
    caja.innerHTML = `
      <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px">
        <p style="margin:0 0 4px;font-size:14px;font-weight:500">Inscribir a más alumnos en esta misma clase</p>
        <p class="suave" style="margin:0 0 10px;font-size:13px">
          Dibujo y pintura son un taller: cada quien llega a su hora. Este recuadro es opcional y sirve
          para inscribir a varios a la misma hora de una vez; el límite es el cupo del salón. Cada uno
          paga lo suyo${esAdmin() ? ' y al maestro se le paga por cada uno' : ''}. La inscripción que marques abajo es
          solo de este alumno; a los demás se les cobra después, desde "Cobrar".
        </p>
        <input class="campo" id="buscar-companero" placeholder="Buscar para filtrar" style="margin-bottom:10px">
        <div id="lista-companeros" style="max-height:170px;overflow-y:auto;display:flex;flex-wrap:wrap;gap:8px"></div>
      </div>`;

    const pintarLista = (filtro = '') => {
      const t = filtro.toLowerCase().trim();
      const marcados = new Set($$('[data-companero]:checked').map(c => c.dataset.companero));
      const lista = alumnos
        .filter(a => String(a.id_alumno) !== String(elegido))
        .filter(a => !t || nombreCompleto(a).toLowerCase().includes(t))
        .slice(0, 60);
      $('#lista-companeros').innerHTML = `<div class="fichas">${lista.map(a => `
        <label><input type="checkbox" data-companero="${a.id_alumno}"
          ${marcados.has(String(a.id_alumno)) ? 'checked' : ''}><span>${esc(nombreCompleto(a))}</span></label>`).join('')}</div>`;
    };
    pintarLista();
    $('#buscar-companero').addEventListener('input', (e) => pintarLista(e.target.value));
  };

  const refrescarMaestros = () => {
    const materia = $('#c-id_instrumento').value;
    const select = $('#c-id_maestro');
    const antes = select.value;
    const opciones = opcionesMaestro(materia);
    select.innerHTML = opciones.map(o =>
      `<option value="${o.valor}" ${String(o.valor) === antes ? 'selected' : ''}>${esc(o.texto)}</option>`).join('');
  };

  const pintar = (tocarCosto = true) => {
    const materia = $('#c-id_instrumento').value;
    const minutos = Number($('#c-duracion_min').value);
    const inicio = $('#c-hora_inicio').value;
    if (tocarCosto) $('#c-costo_mensual').value = costoPorDuracion(materia, minutos);

    const paga = Number($('#c-costo_mensual').value) || 0;
    const cuesta = nominaDe($('#c-id_maestro').value, minutos, clasesAlMes($('#c-fecha_inicio').value.slice(0, 7)));
    // Entra a medio mes: ese primer mes, solo las clases que le quedan.
    const clases = clasesDelPrimerMes($('#c-dia_semana').value, $('#c-fecha_inicio').value);
    // Las clases al mes de ESE mes (si entra el mes que viene y cambia, son las nuevas).
    const sem = clasesAlMes($('#c-fecha_inicio').value.slice(0, 7));
    const parte = (monto) => Math.round(monto * clases / sem * 100) / 100;

    $('#resumen-alta').innerHTML = `
      <div style="border:1px solid var(--linea);border-radius:12px;padding:13px 16px;font-size:13.5px">
        <div>${inicio ? `${esc($('#c-dia_semana').value)} de <strong>${hora(inicio)}</strong> a
             <strong>${hora(sumarMinutos(inicio, minutos))}</strong>` : `${esc($('#c-dia_semana').value)}: <strong>elige la hora</strong>`} · ${textoDuracion(minutos)}${
             $('#c-modalidad').value === 'online' ? ' · <span class="insignia-online">EN LÍNEA</span>' : ''}</div>
        <div class="suave" style="margin-top:6px">
          El alumno paga <strong class="verde">${dinero(paga)}</strong> al mes${esAdmin() ? ` ·
          al maestro se le pagan <strong>${dinero(cuesta)}</strong> por este alumno` : ''}
        </div>
        ${clases !== null ? `
          <div style="margin-top:8px">
            Entra a medio mes: en ${esc(mesLargo($('#c-fecha_inicio').value.slice(0, 7)))} toma
            <strong>${clases} de ${sem} clases</strong>. Ese mes paga
            <strong class="verde">${dinero(parte(paga))}</strong>${esAdmin() ? ` y al maestro se le pagan
            <strong>${dinero(parte(cuesta))}</strong>` : ''}. Del mes siguiente en adelante, completo.
          </div>` : ''}
      </div>`;
  };

  // Inscripción: siempre se puede cobrar. Viene marcada si es su primera vez en la escuela y sin marcar si ya
  // estuvo inscrito; la persona decide. El método de pago se pide si se cobra cualquiera de los dos pagos.
  const pintarInscripcion = () => {
    const marcada = $('#cobrar-inscripcion').checked;
    $('#nota-inscripcion').textContent = esNuevo
      ? 'Es su primera clase.'
      : 'Ya estuvo inscrito antes: márcala solo si sí se le cobra.';
    $('#detalle-inscripcion').classList.toggle('oculto', !marcada);
    $('#c-metodo').parentElement.classList.toggle('oculto', $('#c-pago').value !== 'si' && !marcada);
  };
  $('#cobrar-inscripcion').addEventListener('change', pintarInscripcion);
  $('#c-pago').addEventListener('change', pintarInscripcion);

  const revisarSiEsNuevo = async () => {
    let primera = Boolean(nuevo);
    const id = $('#c-id_alumno')?.value;
    if (!nuevo) {
      try { primera = id ? Boolean((await api(`inscripciones.php?accion=primera&alumno=${id}`)).primera) : false; }
      catch { primera = false; }       // sin saberlo, no se marca: nunca se cobra de más
    }
    esNuevo = primera;
    $('#cobrar-inscripcion').checked = primera;
    pintarInscripcion();
    pintar(false);
  };

  // Si el horario no se puede (ocupado o taller lleno), se ofrece anotarlo en
  // la lista de espera con lo que ya se capturó. Nunca se inscribe solo.
  const ofrecerEspera = (estado) => {
    if (estado.ok !== false || precarga.id_lista_espera) return;
    const caja = $('#vista-hueco');
    caja.insertAdjacentHTML('beforeend', `
      <div style="margin-top:8px">
        <button type="button" class="pildora pildora-linea pildora-chica" id="b-a-espera">Anotar en la lista de espera</button>
      </div>`);
    $('#b-a-espera').addEventListener('click', () => {
      const datos = Object.fromEntries(new FormData($('#capa-modal form')).entries());
      const clase = claseDe(datos);
      $('#capa-modal').innerHTML = '';
      formularioEspera(null, {
        ...quienEs(datos),
        id_instrumento: clase.id_instrumento, id_maestro: clase.id_maestro, id_salon: clase.id_salon,
        dia_semana: clase.dia_semana, hora_inicio: clase.hora_inicio, duracion_min: clase.duracion_min,
        modalidad: clase.modalidad
      });
    });
  };

  // Fecha de inicio: hoy, salvo que ese día de la semana ya no ocurra en lo que queda del mes; en ese
  // caso se propone el 1 del mes que entra y se dice por qué. Si la persona la cambia, se respeta.
  // Qué fecha y qué nota propone el sistema para el día elegido.
  const fechaPropuesta = () => {
    const dia = $('#c-dia_semana').value, hoy = hoyLocal();
    if (clasesQueQuedan(dia, hoy) === 0) {
      const primero = primeroDelMesSiguiente(hoy);
      return { valor: primero, nota: `Este mes ya no le quedan clases del ${diaBonito(dia).toLowerCase()}: empieza el 1 de ${
        NOMBRE_MES[Number(primero.slice(5, 7)) - 1]}.` };
    }
    return { valor: hoy, nota: null };
  };
  // "A mano" es solo lo que la persona eligió ella. Una fecha que viene precargada (de un borrador, o al volver de
  // "Es alumno nuevo") NO cuenta: mientras sea la que propone el sistema, sigue al día elegido, y la nota se conserva.
  let fechaAMano = Boolean(pre.fecha_inicio) && pre.fecha_inicio !== fechaPropuesta().valor;
  const ajustarFecha = () => {
    if (fechaAMano) return;
    const campo = $('#c-fecha_inicio'), nota = $('#nota-fecha');
    const p = fechaPropuesta();
    campo.value = p.valor;
    if (p.nota) { nota.textContent = p.nota; nota.classList.remove('oculto'); } else nota.classList.add('oculto');
  };
  $('#c-fecha_inicio').addEventListener('change', (e) => {
    if (e.isTrusted) { fechaAMano = true; $('#nota-fecha').classList.add('oculto'); }
  });
  $('#c-dia_semana').addEventListener('change', () => { ajustarFecha(); pintar(false); });

  // Hora de inicio: se propone la primera hora LIBRE (con el maestro, el día, el salón y la duración elegidos), no una
  // que casi siempre choca. Mientras la persona no elija la hora ella, se vuelve a proponer al cambiar cualquiera
  // de esos datos. Sin maestro, no se propone nada: "Elige la hora".
  horaAMano = Boolean(pre.hora_inicio) && pre.hora_a_mano !== false;
  let turnoHora = 0;
  const proponerHora = async () => {
    if (horaAMano) return;
    const campo = $('#c-hora_inicio');
    if (!campo) return;
    const n = ++turnoHora;
    let hueco1 = null;
    if ($('#c-id_maestro').value) {
      try {
        const q = new URLSearchParams({ accion: 'primer_hueco', id_maestro: $('#c-id_maestro').value,
          dia_semana: $('#c-dia_semana').value, modalidad: $('#c-modalidad').value, id_salon: $('#c-id_salon').value || '',
          id_instrumento: $('#c-id_instrumento').value || '', duracion_min: $('#c-duracion_min').value,
          id_alumno: $('#c-id_alumno')?.value || '' });
        hueco1 = (await api(`inscripciones.php?${q}`))?.hora_inicio || null;
      } catch { hueco1 = null; }
    }
    if (n !== turnoHora || horaAMano || !document.body.contains(campo)) return;
    campo.value = hueco1 || '';
    if (campo.value !== (hueco1 || '')) campo.value = '';
    pintar(false);
    hueco?.revisar();
    cerrarAlta.marcarLimpio();
  };
  $('#c-hora_inicio').addEventListener('change', (e) => { if (e.isTrusted) horaAMano = true; });

  $('#c-id_instrumento').addEventListener('change', () => { refrescarMaestros(); pintarGrupo(); pintar(true); });
  ['id_maestro', 'dia_semana', 'hora_inicio', 'duracion_min', 'fecha_inicio', 'modalidad'].forEach(c =>
    $(`#c-${c}`)?.addEventListener('change', () => pintar(c === 'duracion_min')));
  ['id_instrumento', 'id_maestro', 'dia_semana', 'duracion_min', 'modalidad', 'id_salon', 'id_alumno'].forEach(c =>
    $(`#c-${c}`)?.addEventListener('change', proponerHora));
  $('#c-costo_mensual').addEventListener('input', () => pintar(false));
  $('#c-id_alumno')?.addEventListener('change', () => { revisarSiEsNuevo(); pintarGrupo(); hueco.revisar(); });

  // Buscar al alumno escribiendo: con más de cien, la lista sola no sirve.
  $('#buscar-alumno-alta')?.addEventListener('input', (e) => {
    const t = e.target.value.toLowerCase().trim();
    const select = $('#c-id_alumno');
    const antes = select.value;
    const encontrados = alumnos.filter(a => !t || nombreCompleto(a).toLowerCase().includes(t));
    select.innerHTML = encontrados.length
      ? encontrados.map(a => `<option value="${a.id_alumno}" ${String(a.id_alumno) === antes ? 'selected' : ''}>${
          esc(nombreCompleto(a))}</option>`).join('')
      : '<option value="">Nadie con ese nombre: si es nuevo, pulsa "Es alumno nuevo"</option>';
    if (select.value !== antes) select.dispatchEvent(new Event('change'));
  });
  // Alumno nuevo: se capturan sus datos y se vuelve aquí con la clase que ya se había elegido.
  $('#b-alumno-nuevo')?.addEventListener('click', () => {
    const datos = Object.fromEntries(new FormData($('#capa-modal form')).entries());
    const clase = claseDe(datos);
    $('#capa-modal').innerHTML = '';
    formularioAlumno(null, { clase });
  });

  hueco = enlazarHueco({
    idAlumno: () => nuevo ? null : $('#c-id_alumno')?.value,
    esGrupal, compartirCon: pre.compartir_con ?? null, alCambiar: ofrecerEspera,
    bloqueo: { pista: precarga.id_lista_espera
      ? 'Cambia la hora, el día o el salón.'
      : 'Cambia la hora, el día o el salón, o anótalo en la lista de espera.' }
  });
  pintarGrupo();
  ajustarFecha();
  pintar(pre.costo_mensual === undefined || pre.costo_mensual === null);
  pintarInscripcion();
  revisarSiEsNuevo();
  proponerHora();
  cerrarAlta.marcarLimpio();
}

/**
 * Editar una clase existente: se puede cambiar de maestro, de materia, de
 * salón, de horario y de costo sin dar de baja y volver a inscribir.
 */
function formularioHorario(inscripcion, alTerminar) {
  const mover = Boolean(inscripcion.dia_semana);
  const activos = (window.__maestros || []).filter(m => Number(m.activo));

  const construir = (maestros) => {
    const materias = catalogos?.instrumentos ?? [];
    const maestrosDe = (idMateria) => {
      const suyos = maestros.filter(m =>
        String(m.ids_instrumentos || '').split(',').includes(String(idMateria)));
      return suyos.length ? suyos : maestros;
    };
    const opcionesMaestro = (idMateria) => maestrosDe(idMateria)
      .map(m => ({ valor: m.id_maestro, texto: nombreCompleto(m) }));
    const esGrupal = (id) => Number(materias.find(x => String(x.id_instrumento) === String(id))?.es_grupal) === 1;

    const materiaActual = inscripcion.id_instrumento || materias[0]?.id_instrumento;
    // Si ya compartía una clase (no de grupo), se sigue proponiendo la misma.
    const compartia = inscripcion.comparte_con && !esGrupal(materiaActual) ? inscripcion.comparte_con : null;
    let hueco = null;
    // Cambio de duración -> precio: lo que el servidor propone (null = nada que proponer) y si se aplica.
    let propuesta = null;
    const aplicaPrecio = () => Boolean(propuesta?.aplica) && Boolean($('#c-precio_por_duracion')?.checked);

    // Si comparte su clase con alguien (no de grupo), al moverla se ofrece
    // mover a las dos juntas: es lo normal y evita tener que acomodarlas una por una.
    let pareja = null;
    const moverPareja = () => Boolean(pareja) && $('#c-mover-pareja')?.checked;

    const horarioDe = (datos) => {
      const minutos = Number(datos.duracion_min);
      const online = datos.modalidad === 'online';
      return {
        id_maestro: Number(datos.id_maestro), modalidad: online ? 'online' : 'presencial',
        id_salon: online ? null : Number(datos.id_salon), dia_semana: datos.dia_semana,
        hora_inicio: datos.hora_inicio, hora_fin: sumarMinutos(datos.hora_inicio, minutos), duracion_min: minutos
      };
    };

    const guardar = async (datos, confirmado) => {
      const minutos = Number(datos.duracion_min);
      const online = datos.modalidad === 'online';
      const otra = moverPareja() ? pareja.id_inscripcion : hueco.juntarCon();
      if (otra) {
        const r = await api('inscripciones.php?accion=juntar', {
          cuerpo: { id_a: inscripcion.id_inscripcion, id_b: otra, ...horarioDe(datos),
                    id_instrumento: datos.id_instrumento ? Number(datos.id_instrumento) : null,
                    costo_mensual: Number(datos.costo_mensual), fecha_cambio: datos.fecha_cambio || null,
                    motivo: (datos.motivo || '').trim() || null }
        });
        return { ...r.a, juntadas: true };
      }
      return api(`inscripciones.php?id=${inscripcion.id_inscripcion}`, {
        method: 'PUT',
        cuerpo: {
          id_instrumento: datos.id_instrumento ? Number(datos.id_instrumento) : null,
          id_maestro: Number(datos.id_maestro),
          modalidad: online ? 'online' : 'presencial',
          id_salon: online ? null : Number(datos.id_salon),
          dia_semana: datos.dia_semana,
          hora_inicio: datos.hora_inicio, hora_fin: sumarMinutos(datos.hora_inicio, minutos),
          duracion_min: minutos,
          costo_mensual: Number(datos.costo_mensual),
          fecha_cambio: datos.fecha_cambio || null,
          precio_por_duracion: aplicaPrecio() ? 1 : 0,
          compartir_con: hueco.compartirCon(),
          confirmar_grupo: confirmado ? 1 : 0,
          motivo: (datos.motivo || '').trim() || null
        }
      });
    };

    const cerrarHorario = modal({
      titulo: mover
        ? `Clase de ${nombreCompleto(inscripcion, 'alumno_')}`
        : `Asignar horario a ${nombreCompleto(inscripcion, 'alumno_')}`,
      textoAceptar: 'Guardar',
      ancho: '620px',
      protegerCambios: true,
      campoDeError: campoDeErrorClase,
      extras: mover ? [{ texto: 'Historial', alPulsar: () => historialClase(inscripcion) }] : [],
      alAceptar: async (datos, cerrar) => {
        // Lo que falta se marca en su campo, con el motivo debajo, y el cursor va al primero.
        const faltan = [];
        const falta = (selector, mensaje) => { const c = $(selector); if (c) faltan.push([c, mensaje]); };
        if (!datos.id_maestro) falta('#c-id_maestro', 'Elige el maestro de la clase.');
        if (!datos.hora_inicio) falta('#c-hora_inicio', 'Elige a qué hora empieza.');
        if (datos.modalidad !== 'online' && !datos.id_salon) falta('#c-id_salon', 'Elige el salón.');
        if (String(datos.costo_mensual ?? '').trim() === '') falta('#c-costo_mensual', 'Escribe cuánto paga al mes.');
        else if (Number(datos.costo_mensual) < 0) falta('#c-costo_mensual', 'No puede ser negativo.');
        {
          const f = $('#c-fecha_cambio'), c = cambios();
          if (f && !$('#cambio-maestro').classList.contains('oculto') && f.value && (c.duracion || c.dia) && f.value < minimoDeFecha()) {
            falta('#c-fecha_cambio', ultimoCambio && f.value >= primeroDeMes
              ? `No puede ser antes del último cambio de esta clase (${ultimoCambio.split('-').reverse().join('/')}).`
              : 'Elige una fecha de este mes.');
          }
        }
        if ($('#c-compartida').checked && !$('#caja-compartir').classList.contains('oculto')
            && !hueco.compartirCon() && !hueco.juntarCon()) {
          falta('#c-compartir_con', 'Elige con qué clase la comparte, o quita la marca de clase compartida.');
        }
        if (faltan.length) {
          [...faltan].reverse().forEach(([campo, mensaje]) => errorEnCampo(campo, mensaje));
          return;
        }
        let r;
        try {
          r = await guardar(datos, false);
        } catch (e) {
          // 409: el servidor pide confirmar que entra a un grupo que ya existe.
          if (e.status !== 409) throw e;
          if (!confirm(`${e.message}\n\nYa hay alguien del taller exactamente a esa hora: ¿los juntas en la misma clase?`)) return;
          r = await guardar(datos, true);
        }
        // Un solo aviso de éxito. El reparto de la nómina del mes solo le sirve a administración.
        const rep = r?.reparto_nomina;
        const quien = primerNombre(inscripcion.alumno_nombre);
        const tipo = r?.hueco?.tipo;
        let texto = r?.juntadas
          ? `Clases juntas, ${hora(datos.hora_inicio)}–${hora(sumarMinutos(datos.hora_inicio, Number(datos.duracion_min)))}: cada una con su pago${esAdmin() ? ' y su nómina' : ''}.`
          : tipo === 'grupo' ? `Clase de ${quien} actualizada, en el taller (${r.hueco.en_clase} de ${r.hueco.cupo} a la vez).`
          : tipo === 'compartida' ? `Clase de ${quien} actualizada, ahora compartida.`
          : (mover ? `Clase de ${quien} actualizada.` : `Horario de ${quien} asignado.`);
        if (esAdmin() && rep?.aviso) texto += ' ' + rep.aviso;
        else if (esAdmin() && rep && rep.viejo !== null) {
          texto += ` Este mes, ${rep.viejo} ${rep.viejo === 1 ? 'clase' : 'clases'} con el maestro anterior y ${rep.nuevo} con el nuevo.`;
        }
        // Cambio de duración: lo que pasó con su colegiatura y, si aplica, con la nómina.
        const cp = r?.cambio_precio;
        if (cp) {
          // La misma fecha que dijo la ventana antes de guardar: la decide el servidor, aquí solo se dice.
          texto += ` Su colegiatura pasó de ${dinero(cp.antes)} a ${dinero(cp.despues)} ${cuandoRige(cp.desde, cp.mes_completo)}.`;
          if (Number(cp.queda_por_pagar) > 0) texto += ` Quedan ${dinero(cp.queda_por_pagar)} por pagar de ${NOMBRE_MES[Number(cp.desde.slice(5, 7)) - 1]}.`;
          else if (cp.por_confirmar && !cp.baja) texto += ' Ese mes tiene un pago por confirmar: no se le cobra ninguna diferencia.';
        }
        if (esAdmin() && r?.cambio_nomina?.mes?.pagado) texto += ' La nómina de este mes ya estaba pagada: el cambio le aplica desde el mes siguiente.';
        aviso(texto, 'exito');
        cerrar();
        (alTerminar || refrescar)();
        avisarListaEspera(r?.lista_espera);
      },
      campos: `
        <div class="fila-campos">
          ${campoSelect('id_instrumento', 'Materia', opcionesInstr(), materiaActual)}
          ${campoSelect('id_maestro', 'Maestro',
            inscripcion.id_maestro ? opcionesMaestro(materiaActual)
              : [{ valor: '', texto: '— Elige un maestro —' }, ...opcionesMaestro(materiaActual)],
            inscripcion.id_maestro ?? '')}
        </div>
        <div class="fila-campos">
          ${campoSelect('dia_semana', 'Día', opcionesDia(), inscripcion.dia_semana)}
          ${campoSelect('hora_inicio', 'Empieza a las', opcionesInicio(), inscripcion.hora_inicio || '16:00:00')}
          ${campoSelect('duracion_min', 'Duración', DURACIONES, inscripcion.duracion_min || 60)}
        </div>
        ${camposHueco({ modalidad: inscripcion.modalidad, compartida: Boolean(compartia) })}
        <div class="fila-campos">
          ${campoSelect('id_salon', 'Salón', opcionesSalon(), inscripcion.id_salon)}
          ${campoTexto('costo_mensual', 'Paga al mes', { tipo: 'number', paso: '0.01', valor: inscripcion.costo_mensual ?? '' })}
        </div>
        <div id="vista-hueco" class="vista-hueco suave">Revisando el horario…</div>
        <div id="cambio-maestro" class="oculto">
          ${campoTexto('fecha_cambio', 'El cambio rige desde el', { tipo: 'date', valor: hoyLocal() })}
          <p id="texto-fecha-maestro" class="suave oculto" data-solo-admin style="font-size:13px;margin:6px 0 0">
            Las clases de este mes antes de esa fecha se le pagan al maestro anterior; las que
            quedan, al nuevo. Del mes que entra en adelante, completo con el nuevo.
          </p>
          <p id="texto-fecha-clase" class="suave oculto" data-solo-admin style="font-size:13px;margin:6px 0 0">
            Las clases de este mes antes de esa fecha se cuentan y se pagan como antes; las de esa fecha
            en adelante, con el día y la duración nuevos.
          </p>
        </div>
        <div id="caja-precio" class="oculto" style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px">
          <label style="display:flex;gap:9px;align-items:flex-start;font-size:14px">
            <input type="checkbox" id="c-precio_por_duracion" checked style="margin-top:3px">
            <span id="texto-precio"></span>
          </label>
          <p id="nota-precio" class="suave" style="font-size:13px;margin:8px 0 0 26px"></p>
        </div>
        ${campoTexto('motivo', 'Nota del cambio (opcional)', { pista: 'Por qué se cambia; queda en el historial de la clase' })}
        <p class="suave" data-solo-admin style="font-size:13px;margin:0">
          Si cambias el maestro, la clase y su nómina del mes se reparten entre los dos.
        </p>
        <p id="nota-taller" class="suave ${esGrupal(materiaActual) ? '' : 'oculto'}" style="font-size:13px;margin:0">
          Dibujo y Pintura son un taller: cada alumno llega y se va a su hora, aunque se encimen con
          otros del mismo maestro. Solo si otro alumno del taller está exactamente a la misma hora se te
          pregunta antes de juntarlos en una misma clase.
        </p>`
    });

    // Si cambia el maestro, la duración o el día de una clase que ya tenía horario, se pide desde
    // cuándo rige (una sola fecha para todo el cambio) y, si cambia la duración, se propone su precio.
    const cambios = () => ({
      maestro: Boolean(inscripcion.id_maestro) && String($('#c-id_maestro').value) !== String(inscripcion.id_maestro),
      duracion: mover && Number($('#c-duracion_min').value) !== Number(inscripcion.duracion_min),
      dia: mover && $('#c-dia_semana').value !== inscripcion.dia_semana
    });
    const revisarCambio = () => {
      const c = cambios();
      $('#cambio-maestro').classList.toggle('oculto', !(c.maestro || c.duracion || c.dia));
      $('#texto-fecha-maestro').classList.toggle('oculto', !c.maestro);
      $('#texto-fecha-clase').classList.toggle('oculto', c.maestro || !(c.duracion || c.dia));
      const etiqueta = $('#cambio-maestro label.etiqueta');
      if (etiqueta) etiqueta.textContent = c.maestro ? 'El maestro nuevo la da desde el' : 'El cambio rige desde el';
      aplicarMinimo();
      actualizarPropuesta();
    };

    // El cambio es de este mes: la fecha no puede ser de un mes anterior ni pasar de fin de mes, ni caer antes
    // del último cambio de duración o de día de esa clase (eso reescribiría un tramo ya cobrado).
    const primeroDeMes = `${periodoActual()}-01`;
    const [anioMes, numMes] = periodoActual().split('-').map(Number);
    const ultimoDeMes = `${periodoActual()}-${String(new Date(anioMes, numMes, 0).getDate()).padStart(2, '0')}`;
    let ultimoCambio = null;
    const minimoDeFecha = () => {
      const c = cambios();
      return (c.duracion || c.dia) && ultimoCambio && ultimoCambio > primeroDeMes ? ultimoCambio : primeroDeMes;
    };
    const aplicarMinimo = () => { $('#c-fecha_cambio').min = minimoDeFecha(); };
    $('#c-fecha_cambio').min = primeroDeMes;
    $('#c-fecha_cambio').max = ultimoDeMes;
    if (mover) {
      api(`inscripciones.php?accion=ultimo_cambio&id=${inscripcion.id_inscripcion}`)
        .then(r => { ultimoCambio = r?.fecha || null; aplicarMinimo(); }).catch(() => {});
    }

    /**
     * Cambió la duración: se le pregunta al servidor qué pasaría con su colegiatura (precio de la materia por
     * la nueva duración, igual que al inscribir). La casilla viene marcada; desmarcarla le respeta el
     * precio de ahora. El precio se calcula solo en el servidor; aquí solo se muestra.
     */
    let pedido = 0;
    const actualizarPropuesta = async () => {
      const caja = $('#caja-precio');
      const campoFecha = $('#c-fecha_cambio');
      // Cualquier error de una vista anterior se quita: se vuelve a preguntar.
      campoFecha.classList.remove('con-error'); campoFecha.parentElement.querySelector('.error-campo')?.remove();
      if (!cambios().duracion) {
        pedido++; propuesta = null; caja.classList.add('oculto'); volverPrecio();
        bloquearAceptar(false, '', 'precio');
        return;
      }
      const n = ++pedido;
      // Mientras el servidor calcula, no se puede guardar: se guardaría sin ver la casilla del precio.
      bloquearAceptar(true, 'Calculando el precio…', 'precio', 'Calculando el precio…');
      let v = null, rechazo = null;
      try {
        const q = new URLSearchParams({ accion: 'precio_previa', id: inscripcion.id_inscripcion,
          duracion_min: $('#c-duracion_min').value, id_instrumento: $('#c-id_instrumento').value || '',
          dia_semana: $('#c-dia_semana').value || '', fecha: campoFecha.value || hoyLocal() });
        v = await api(`inscripciones.php?${q}`);
      } catch (e) {
        v = null;
        // El servidor rechazó la fecha (o algo que se dice en su campo): se muestra ahí y no se puede guardar.
        // Un fallo de conexión no bloquea: al guardar decide el servidor.
        if (e.status && e.status >= 400 && e.status < 500) rechazo = sinPrefijoDeOperacion(e.message);
      }
      if (n !== pedido || !document.body.contains(caja)) return;
      if (rechazo) {
        propuesta = null; caja.classList.add('oculto'); volverPrecio();
        errorEnCampo(campoFecha, rechazo, false);
        bloquearAceptar(true, 'Corrige la fecha del cambio para poder guardar.', 'precio');
        return;
      }
      bloquearAceptar(false, '', 'precio');
      propuesta = v && v.aplica ? v : null;
      if (!propuesta) { caja.classList.add('oculto'); volverPrecio(); return; }
      pintarPropuesta();
    };
    // El campo "Paga al mes" muestra el precio que quedará mientras la casilla esté marcada.
    const volverPrecio = () => {
      const campo = $('#c-costo_mensual');
      if (campo.dataset.original !== undefined) { campo.value = campo.dataset.original; campo.readOnly = false; delete campo.dataset.original; }
    };
    const pintarPropuesta = () => {
      const v = propuesta, caja = $('#caja-precio'), campo = $('#c-costo_mensual');
      const mes = NOMBRE_MES[Number(v.periodo.slice(5, 7)) - 1];
      const marcada = $('#c-precio_por_duracion').checked;
      // UNA fecha, ya decidida por el servidor: la casilla, la nota y el aviso de éxito dicen lo mismo.
      const cuando = cuandoRige(v.desde, v.mes_completo);
      $('#texto-precio').innerHTML = marcada
        ? `Su colegiatura pasa de <strong>${dinero(v.precio_actual)}</strong> a
           <strong>${dinero(v.precio_nuevo)}</strong> ${esc(cuando)}
           <span class="suave" style="display:block;font-size:13px;margin-top:2px">Desmárcala si se le respeta ${dinero(v.precio_actual)}.</span>`
        : `Se queda en <strong>${dinero(v.precio_actual)}</strong>.
           <span class="suave" style="display:block;font-size:13px;margin-top:2px">Márcala si su colegiatura pasa a ${dinero(v.precio_nuevo)} ${esc(cuando)}.</span>`;
      const notas = [];
      if (v.mes_pagado && v.por_confirmar && !v.baja) notas.push(`Este mes tiene un pago por confirmar: no se le cobra ninguna diferencia.`);
      if (v.mes_pagado && v.baja) notas.push(`Este mes ya está pagado: el precio nuevo aplica ${cuando}.`);
      else if (v.sin_clases_este_mes) notas.push(`Este mes ya no le queda ninguna clase con la duración nueva: el precio nuevo aplica ${cuando}.`);
      if (v.mes_pagado && !v.baja && Number(v.queda_por_pagar) > 0) notas.push(`Quedan ${dinero(v.queda_por_pagar)} por pagar de ${mes}.`);
      if (v.proporcional) notas.push(`En ${mes}: ${v.clases_antes} ${v.clases_antes === 1 ? 'clase' : 'clases'} a ${dinero(v.precio_actual)} y ${v.clases_despues} a ${dinero(v.precio_nuevo)}.`);
      $('#nota-precio').textContent = marcada ? notas.join(' ') : '';
      $('#nota-precio').classList.toggle('oculto', !$('#nota-precio').textContent);
      caja.classList.remove('oculto');
      if (marcada) {
        if (campo.dataset.original === undefined) campo.dataset.original = campo.value;
        campo.value = v.precio_nuevo; campo.readOnly = true;
      } else volverPrecio();
    };
    $('#c-precio_por_duracion').addEventListener('change', () => { if (propuesta) pintarPropuesta(); });
    ['c-id_maestro', 'c-dia_semana', 'c-duracion_min', 'c-fecha_cambio'].forEach(id =>
      $(`#${id}`).addEventListener('change', revisarCambio));

    // Al cambiar de materia, se ofrecen los maestros que la imparten.
    $('#c-id_instrumento').addEventListener('change', () => {
      $('#nota-taller').classList.toggle('oculto', !esGrupal($('#c-id_instrumento').value));
      const select = $('#c-id_maestro');
      const antes = select.value;
      select.innerHTML = opcionesMaestro($('#c-id_instrumento').value)
        .map(o => `<option value="${o.valor}" ${String(o.valor) === antes ? 'selected' : ''}>${esc(o.texto)}</option>`).join('');
      revisarCambio();
    });

    // Si el horario lo ocupa otra clase individual de la misma duración, se
    // ofrece intercambiarlas en un paso, en vez de mover una a una hora falsa.
    const ofrecerIntercambio = async (estado) => {
      if (estado.ok !== false || !mover || moverPareja()) return;
      const d = hueco.leer();
      if (Number(d.duracion_min) !== Number(inscripcion.duracion_min)) return;
      const q = new URLSearchParams({ accion: 'ocupantes', id: inscripcion.id_inscripcion, ...d });
      let ocupan = [];
      try { ocupan = await api(`inscripciones.php?${q}`); } catch { return; }
      const caja = $('#vista-hueco');
      if (ocupan.length !== 1 || !caja || $('#b-intercambiar')) return;
      const o = ocupan[0];
      if (Number(o.es_grupal) || Number(o.acompanantes) || Number(o.duracion_min) !== Number(inscripcion.duracion_min)) return;
      caja.insertAdjacentHTML('beforeend', `
        <div style="margin-top:8px">
          <button type="button" class="pildora pildora-linea pildora-chica" id="b-intercambiar">
            Intercambiar con ${esc(nombreCompleto(o, 'alumno_'))}</button>
          <span class="suave" style="font-size:12.5px;display:block;margin-top:4px">
            ${esc(nombreCompleto(o, 'alumno_'))} pasaría a este horario de ahora
            (${esc(diaBonito(inscripcion.dia_semana))} ${hora(inscripcion.hora_inicio)}) y esta clase al suyo
            (${hora(o.hora_inicio)}–${hora(o.hora_fin)}). Cada una conserva su maestro y su precio.</span>
        </div>`);
      $('#b-intercambiar').addEventListener('click', async () => {
        try {
          await api('inscripciones.php?accion=intercambiar', {
            cuerpo: { id_a: inscripcion.id_inscripcion, id_b: Number(o.id_inscripcion),
                      motivo: ($('#c-motivo')?.value || '').trim() || null }
          });
          aviso(`Listo: intercambiaron horario con ${nombreCompleto(o, 'alumno_')}.`, 'exito');
          $('#capa-modal').innerHTML = '';
          (alTerminar || refrescar)();
        } catch (e) { aviso(e.message, 'error'); }
      });
    };

    // Si el horario sigue siendo el que ya tenía, no se bloquea el guardado por lo que diga la vista
    // previa (por ejemplo, para corregir solo el precio): en ese caso decide el servidor al guardar.
    const igualAlOriginal = (d) => mover
      && d.dia_semana === inscripcion.dia_semana
      && hora(d.hora_inicio) === hora(inscripcion.hora_inicio)
      && Number(d.duracion_min) === Number(inscripcion.duracion_min)
      && String(d.id_maestro) === String(inscripcion.id_maestro)
      && d.modalidad === (inscripcion.modalidad || 'presencial')
      && (d.modalidad === 'online' || String(d.id_salon) === String(inscripcion.id_salon))
      && $('#c-compartida').checked === Boolean(compartia)
      && !$('#c-mover-pareja')?.checked;

    hueco = enlazarHueco({
      idInscripcion: inscripcion.id_inscripcion, esGrupal, compartirCon: compartia, juntar: mover,
      alCambiar: ofrecerIntercambio,
      bloqueo: { pista: 'Cambia la hora, el día o el salón.', excepto: igualAlOriginal },
      ocultarCompartir: () => moverPareja(),
      simularDos: (otra) => {
        const datos = Object.fromEntries(new FormData($('#capa-modal form')).entries());
        return api('inscripciones.php?accion=juntar', {
          cuerpo: { simular: 1, id_a: inscripcion.id_inscripcion, id_b: moverPareja() ? pareja.id_inscripcion : otra,
                    ...horarioDe(datos), id_instrumento: datos.id_instrumento ? Number(datos.id_instrumento) : null,
                    costo_mensual: Number(datos.costo_mensual), fecha_cambio: datos.fecha_cambio || null }
        });
      },
      especial: () => moverPareja()
        ? `Se mueven las dos: ${nombreCompleto(pareja)} pasa a este mismo horario y siguen compartiendo la clase. `
          + 'Al guardar se revisa que el horario esté libre para las dos.'
        : null
    });

    // ¿Comparte con alguien? Entonces se ofrece moverlas juntas (marcado de inicio).
    if (mover) {
      api(`inscripciones.php?accion=pareja&id=${inscripcion.id_inscripcion}`).then(p => {
        if (!p || !document.body.contains($('#vista-hueco'))) return;
        pareja = p;
        $('#vista-hueco').insertAdjacentHTML('beforebegin', `
          <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px" id="caja-pareja">
            <input type="checkbox" id="c-mover-pareja" checked style="margin-top:3px">
            <span>Mover también a <strong>${esc(nombreCompleto(p))}</strong>
              <span class="suave" style="display:block;font-size:13px;margin-top:2px">
                Comparten la clase. Desmárcalo si solo cambia esta clase (la otra se queda en su horario).</span></span>
          </label>`);
        $('#c-mover-pareja').addEventListener('change', () => hueco.revisar());
        hueco.revisar();
      }).catch(() => {});
    }
    cerrarHorario.marcarLimpio();
  };

  if (activos.length) { construir(activos); return; }
  api('maestros.php').then(m => { window.__maestros = m; construir(m.filter(x => Number(x.activo))); })
                     .catch(e => aviso(e.message, 'error'));
}

/** Nombre legible de un valor de la bitácora (ids de maestro, materia, salón) */
function valorLegible(campo, valor) {
  if (valor === null || valor === undefined || valor === '') return '—';
  if (campo === 'id_maestro') {
    const m = (window.__maestros || []).find(x => String(x.id_maestro) === String(valor));
    return m ? nombreCompleto(m) : `maestro #${valor}`;
  }
  if (campo === 'id_instrumento') return (catalogos?.instrumentos ?? []).find(i => String(i.id_instrumento) === String(valor))?.nombre ?? valor;
  if (campo === 'id_salon') return (catalogos?.salones ?? []).find(s => String(s.id_salon) === String(valor))?.nombre ?? valor;
  if (campo === 'comparte_con') return `comparte con ${valor}`;
  if (campo === 'hora_inicio' || campo === 'hora_fin') return hora(valor);
  if (campo === 'costo_mensual') return dinero(valor);
  if (campo === 'duracion_min') return textoDuracion(valor);
  return valor;
}

const CAMPO_BITACORA = {
  estado: 'Estado', fecha_baja: 'Fecha de baja', id_maestro: 'Maestro', id_instrumento: 'Materia',
  modalidad: 'Modalidad', id_salon: 'Salón', dia_semana: 'Día', hora_inicio: 'Empieza', hora_fin: 'Termina',
  duracion_min: 'Duración', costo_mensual: 'Paga al mes', comparte_con: 'Clase compartida'
};

/** Todo lo que le ha pasado a una clase: qué cambió, quién, cuándo, por qué y con qué evidencia */
async function historialClase(c) {
  let cambios;
  try {
    cambios = await api(`inscripciones.php?accion=cambios&id=${c.id_inscripcion}`);
    if (!(window.__maestros || []).length) window.__maestros = await api('maestros.php');
  } catch (e) { aviso(e.message, 'error'); return; }
  const porOperacion = [];
  cambios.forEach(x => {
    const k = x.operacion || `c${x.id_cambio}`;
    let g = porOperacion.find(o => o.k === k);
    if (!g) { g = { k, x, lineas: [] }; porOperacion.push(g); }
    g.lineas.push(x);
  });
  modal({
    titulo: `Historial · ${nombreCompleto(c, 'alumno_') || 'clase'}`,
    textoAceptar: 'Cerrar',
    sinCancelar: true,
    ancho: '600px',
    campos: porOperacion.length ? porOperacion.map(g => `
      <div style="border:1px solid var(--linea);border-radius:12px;padding:10px 14px;font-size:13.5px">
        <div class="suave" style="font-size:12.5px">${esc(String(g.x.fecha).slice(0, 16))}
          ${g.x.usuario ? '· ' + esc(g.x.usuario) : ''}</div>
        ${g.x.motivo ? `<div style="margin-top:3px">${esc(g.x.motivo)}</div>` : ''}
        ${g.x.evidencia ? `<div class="suave" style="margin-top:3px">Evidencia: ${esc(g.x.evidencia)}</div>` : ''}
        ${g.lineas.map(l => `<div style="margin-top:4px">
          <strong>${esc(CAMPO_BITACORA[l.campo] || (l.campo.startsWith('nomina ') ? 'Nómina ' + mesLargo(l.campo.slice(7)) : l.campo))}</strong>:
          ${esc(valorLegible(l.campo, l.valor_anterior))} → ${esc(valorLegible(l.campo, l.valor_nuevo))}</div>`).join('')}
      </div>`).join('')
      : '<p class="suave" style="margin:0">Esta clase no tiene cambios registrados todavía.</p>',
    alAceptar: (_d, cerrar) => cerrar()
  });
}

/**
 * Corregir una baja ya hecha: no debía existir (quita lo que quedó en la
 * nómina del maestro y el cargo de ese mes) o cuántas clases tomó de verdad.
 */
function formularioCorregirBaja(c, alTerminar) {
  modal({
    titulo: `Corregir la baja de ${c.instrumento && c.instrumento !== 'Por asignar' ? c.instrumento
      : `la clase con ${nombreCompleto(c, 'maestro_')}`}`,
    textoAceptar: 'Guardar corrección',
    ancho: '540px',
    campos: `
      <p style="margin:0;font-size:14px">Se dio de baja el ${esc(c.fecha_baja || '')}. Si quedó mal, corrígelo aquí
        mientras la nómina de ese mes no se haya pagado.</p>
      <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px">
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px">
          <input type="radio" name="modo" value="no_debia_existir" checked style="margin-top:3px">
          <span>No tomó clases: <strong>este registro no debía existir</strong>
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              Se quita lo que quedó en la nómina del maestro de ese mes y su cargo queda en cero.</span></span>
        </label>
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px;margin-top:10px">
          <input type="radio" name="modo" value="clases" style="margin-top:3px">
          <span>Sí tomó clases ese mes
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              La nómina y el cargo del mes quedan por las clases que indiques.</span></span>
        </label>
        <div id="caja-clases" class="oculto" style="margin-top:10px">
          ${campoSelect('clases', 'Clases que tomó', Array.from({ length: clasesAlMes() }, (_, n) =>
            ({ valor: n + 1, texto: `${n + 1} de ${clasesAlMes()}` })))}
        </div>
      </div>
      ${campoTexto('motivo', 'Motivo (opcional)', { pista: 'Queda en el historial de la clase' })}`,
    alAceptar: async (datos, cerrar) => {
      const r = await api(`inscripciones.php?id=${c.id_inscripcion}&accion=corregir_baja`, {
        method: 'PUT',
        cuerpo: { modo: datos.modo, clases: Number(datos.clases), motivo: (datos.motivo || '').trim() || null }
      });
      aviso(r.clases
        ? `Baja corregida: ${r.clases} ${r.clases === 1 ? 'clase' : 'clases'} en ${mesLargo(r.periodo)}.`
        : `Baja corregida: sin clases ni nómina en ${mesLargo(r.periodo)}.`, 'exito');
      cerrar();
      (alTerminar || refrescar)();
    }
  });
  $$('input[name="modo"]').forEach(r => r.addEventListener('change', () => {
    $('#caja-clases').classList.toggle('oculto', $('input[name="modo"]:checked').value !== 'clases');
  }));
}

/**
 * Se liberó un lugar (baja o cambio de horario). Si alguien de la lista de
 * espera pidió justo ese hueco, se avisa; las solicitudes parecidas (otra hora,
 * otro salón) se muestran aparte. Nunca se inscribe a nadie solo.
 */
function avisarListaEspera(le) {
  if (!le) return;
  const exactas = le.exactas || [], alternativas = le.alternativas || [];
  if (!exactas.length) {
    if (alternativas.length) {
      aviso(`Se liberó un lugar. Hay ${alternativas.length} en la lista de espera con una solicitud parecida `
          + '(no la misma hora o salón). Revísalo en Lista de espera.', 'info');
    }
    return;
  }
  const h = le.hueco || {};
  const renglon = (f, extra = '') => `
    <li style="display:block">
      <strong>${esc(f.quien)}</strong> <span class="suave">· ${esc(f.contacto || 'sin teléfono')}</span>
      <div class="suave" style="font-size:13px;margin-top:2px">
        ${esc(f.materia)} · ${esc(f.dia_semana || 'cualquier día')} ${f.hora_inicio ? hora(f.hora_inicio) : ''}
        · ${textoDuracion(f.duracion_min)}${f.maestro ? ' · con ' + esc(f.maestro) : ''}${extra}
      </div>
    </li>`;
  modal({
    titulo: 'Se liberó un lugar que alguien espera',
    textoAceptar: 'Ver lista de espera',
    textoCancelar: 'Cerrar',
    ancho: '580px',
    campos: `
      <p style="margin:0;font-size:14px">
        ${h.libera === 'lugar' ? 'Quedó un lugar libre en el taller' : 'Quedó libre el horario'} del
        <strong>${esc(h.dia_semana || '')} a las ${hora(h.hora_inicio)}</strong>.
        Estas solicitudes coinciden exactamente. Contacta a la persona e inscríbela desde la lista de espera:
        el sistema no inscribe a nadie por su cuenta.
      </p>
      <ul class="lista-simple" style="display:block">${exactas.map(f => renglon(f)).join('')}</ul>
      ${alternativas.length ? `
        <p class="suave" style="margin:6px 0 0;font-size:13.5px">Parecidas, pero no coinciden:</p>
        <ul class="lista-simple" style="display:block">${alternativas.map(f =>
          renglon(f, f.por_que_no ? ` · <span class="ambar">${esc(f.por_que_no)}</span>` : '')).join('')}</ul>` : ''}`,
    alAceptar: async (datos, cerrar) => {
      cerrar();
      if (vistaActual === 'espera') refrescar(); else location.hash = '#espera';
    }
  });
}
/* ====================================================================
   VISTA · HORARIOS
   ==================================================================== */

/** Lo que la edición de una clase necesita, a partir de una fila del horario */
const inscripcionDeOcupacion = (c) => ({
  id_inscripcion: c.id_inscripcion,
  alumno_nombre: c.alumno_nombre, alumno_apellido: c.alumno_apellido,
  maestro_nombre: c.maestro_nombre, maestro_apellido: c.maestro_apellido,
  id_maestro: c.id_maestro, id_instrumento: c.id_instrumento, id_salon: c.id_salon,
  modalidad: c.modalidad, comparte_con: c.comparte_con ?? null,
  dia_semana: c.dia_semana, hora_inicio: c.hora_inicio,
  duracion_min: c.duracion_min, costo_mensual: c.costo_mensual
});

/**
 * Nombre para la rejilla: primer nombre + primer apellido completo ("Prueba Taller"), sin
 * iniciales sueltas que se confunden. Respeta apellidos con partícula ("De la Cruz").
 */
function nombreDeRejilla(nombre, apellido) {
  const n = String(nombre ?? '').trim().split(/\s+/)[0] || '';
  const a = String(apellido ?? '').trim().split(/\s+/).filter(Boolean);
  const particulas = ['de', 'del', 'la', 'las', 'los', 'y', 'san', 'santa'];
  let ap = a.length ? [a[0]] : [];
  for (let k = 1; k < a.length && particulas.includes(a[k - 1].toLowerCase()); k++) ap.push(a[k]);
  return [n, ...ap].filter(Boolean).join(' ');
}

/**
 * Los alumnos de una casilla del taller, cada uno con su rango de horas: así se ve
 * quién sale antes. Los que entran con la titular a la hora exacta comparten su rango.
 * Devuelve HTML ya escapado; el rango no se parte en dos renglones.
 */
function alumnosConRango(c) {
  const rango = `<span style="white-space:nowrap">${hora(c.hora_inicio)}–${hora(c.hora_fin)}</span>`;
  const otros = String(c.lista_grupo_p || '').split('||').filter(Boolean).map(x => {
    const [n, ...ap] = x.split('~');
    return nombreDeRejilla(n, ap.join('~'));
  });
  return [nombreDeRejilla(c.alumno_nombre, c.alumno_apellido), ...otros].map(n => `${esc(n)} ${rango}`);
}

/**
 * Casilla de varias clases encimadas en la misma columna. Si todas son del
 * mismo grupo (Dibujo y Pintura con el mismo maestro), se ve como el grupo;
 * si no, como "N clases", y al hacer clic se ve cada una.
 */
function celdaVarias(pieza, j, i, porDia) {
  const cs = pieza.clases;
  const mismoGrupo = cs.every(c => c.clave_grupo && c.clave_grupo === cs[0].clave_grupo
                                && String(c.id_maestro) === String(cs[0].id_maestro));
  const personas = cs.reduce((n, c) => n + Number(c.en_clase || 1), 0);
  const online = cs.every(c => c.modalidad === 'online');
  const nombres = cs.flatMap(alumnosConRango);
  const lista = nombres.length > 5 ? [...nombres.slice(0, 4), `y ${nombres.length - 4} más`] : nombres;
  const estilo = `grid-column:${j + 2};grid-row:${i + 2} / span ${pieza.alto}`;
  if (mismoGrupo) {
    return `<div class="clase grupo" data-grupo="${cs[0].id_inscripcion}" style="${estilo}"
                 title="Clic para ver el taller y editar a cada alumno">
              <b class="cabeza-grupo">${esc(cs[0].grupo_nombre)} <span class="cuantos">${personas}</span></b>
              <span class="integrantes" style="overflow-wrap:anywhere">${lista.join(' · ')}</span>
              <span>${esc(nombreCompleto(cs[0], 'maestro_'))}${porDia ? '' : ' · ' + esc(cs[0].salon)}</span>
            </div>`;
  }
  return `<div class="clase${online ? ' online' : ''}" data-varias="${cs.map(c => c.id_inscripcion).join(',')}"
               style="${estilo}" title="Clic para ver cada clase">
            <b class="cabeza-grupo">${cs.length} clases a la vez${online ? ' <span class="insignia-online">EN LÍNEA</span>' : ''}</b>
            <span class="integrantes" style="overflow-wrap:anywhere">${lista.join(' · ')}</span>
          </div>`;
}

/**
 * El grupo de un día (Dibujo y Pintura): quiénes son, a qué hora llega cada
 * uno, y un Editar por alumno. Mover a uno no mueve a los demás.
 */
async function panelGrupo(idInscripcion) {
  let g;
  try { g = await api(`horarios.php?grupo_de=${idInscripcion}`); }
  catch (e) { aviso(e.message, 'error'); return; }
  const primero = g.alumnos[0] || {};
  const salones = [...new Set(g.alumnos.map(a => a.salon).filter(Boolean))];
  modal({
    titulo: `${g.grupo} · ${diaBonito(g.dia_semana)}`,
    textoAceptar: 'Cerrar',
    sinCancelar: true,
    ancho: '600px',
    extras: [{
      texto: 'Agregar alumno a este taller',
      alPulsar: async (_d, cerrar) => {
        cerrar();
        await formularioAlta(null, { clase: {
          id_instrumento: primero.id_instrumento, id_maestro: g.id_maestro, dia_semana: g.dia_semana,
          id_salon: primero.id_salon, hora_inicio: primero.hora_inicio, duracion_min: 60 } });
      }
    }],
    campos: `
      <p style="margin:0;font-size:14px">
        Maestro: <strong>${esc(nombreCompleto(primero, 'maestro_'))}</strong>
        ${salones.length ? ` · ${esc(salones.join(', '))}` : ''}
        ${primero.capacidad ? `<span class="suave">(caben ${primero.capacidad} a la vez)</span>` : ''}
      </p>
      <p class="suave" style="margin:0;font-size:13px">
        Es un taller: cada alumno llega y se va a su hora, aunque se encimen con otros de este día y este maestro.
        Cada uno conserva su propio horario: editar a uno no mueve a los demás.
      </p>
      <ul class="lista-simple" style="display:block">
        ${g.alumnos.map(a => `
          <li style="display:flex;justify-content:space-between;align-items:center;gap:10px">
            <span><strong>${esc(nombreCompleto(a, 'alumno_'))}</strong>
              <span class="suave">· ${esc(a.instrumento)} · ${hora(a.hora_inicio)} a ${hora(a.hora_fin)}</span></span>
            <button type="button" class="pildora pildora-linea pildora-chica" data-escribe data-editar-integrante="${a.id_inscripcion}">Editar</button>
          </li>`).join('')}
      </ul>`,
    alAceptar: (_d, cerrar) => cerrar()
  });
  $$('[data-editar-integrante]').forEach(b => b.addEventListener('click', () => {
    const a = g.alumnos.find(x => String(x.id_inscripcion) === b.dataset.editarIntegrante);
    $('#capa-modal').innerHTML = '';
    formularioHorario({ ...a }, () => refrescar());
  }));
}

/** Varias clases encimadas que no son un grupo: una lista con Editar para cada una */
function panelVarias(clases) {
  modal({
    titulo: `${clases.length} clases a la vez`,
    textoAceptar: 'Cerrar',
    sinCancelar: true,
    ancho: '560px',
    campos: `
      <ul class="lista-simple" style="display:block">
        ${clases.map(c => `
          <li style="display:flex;justify-content:space-between;align-items:center;gap:10px">
            <span><strong>${esc(nombreCompleto(c, 'alumno_'))}</strong>
              <span class="suave">· ${esc(c.instrumento)} · ${esc(nombreCompleto(c, 'maestro_'))} ·
                ${hora(c.hora_inicio)} a ${hora(c.hora_fin)}${c.modalidad === 'online' ? ' · en línea' : ''}</span></span>
            <button type="button" class="pildora pildora-linea pildora-chica" data-escribe data-editar-varias="${c.id_inscripcion}">Editar</button>
          </li>`).join('')}
      </ul>`,
    alAceptar: (_d, cerrar) => cerrar()
  });
  $$('[data-editar-varias]').forEach(b => b.addEventListener('click', () => {
    const c = clases.find(x => String(x.id_inscripcion) === b.dataset.editarVarias);
    $('#capa-modal').innerHTML = '';
    formularioHorario(inscripcionDeOcupacion(c), () => refrescar());
  }));
}

vistas.horarios = async () => {
  const ocupacion = await api('horarios.php');
  const salones = catalogos?.salones ?? [];
  const bloques = catalogos?.bloques ?? [];
  const dias    = catalogos?.dias ?? [];
  const apertura = catalogos?.horario_escuela ?? [];

  const hoy = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'][new Date().getDay()];
  let diaActivo = dias.includes(hoy) ? hoy : dias[0];
  let modo = 'dia';           // 'dia' = todos los salones de un día; 'salon' = una semana de un salón
  let salonActivo = String(salones[0]?.id_salon ?? '');
  // Al refrescar tras guardar, se vuelve al mismo día, vista, salón y punto de desplazamiento.
  const recuerdo = memoriaHorarios;
  if (recuerdo) {
    if (dias.includes(recuerdo.dia)) diaActivo = recuerdo.dia;
    if (recuerdo.modo === 'dia' || recuerdo.modo === 'salon') modo = recuerdo.modo;
    if (salones.some(x => String(x.id_salon) === recuerdo.salon) || recuerdo.salon === 'online') salonActivo = recuerdo.salon;
  }
  memoriaHorarios = { dia: diaActivo, modo, salon: salonActivo, arriba: recuerdo?.arriba ?? 0, izquierda: recuerdo?.izquierda ?? 0 };
  const recordar = () => { memoriaHorarios = { ...(memoriaHorarios || {}), dia: diaActivo, modo, salon: salonActivo }; };
  // Las clases en línea no tienen salón: van en su propia columna (o "salón").
  const EN_LINEA = 'online';
  const hayEnLinea = ocupacion.some(o => !o.id_salon);
  const salonDe = (o) => o.id_salon ? String(o.id_salon) : EN_LINEA;
  const lugares = [...salones.map(s => ({ id: String(s.id_salon), texto: s.nombre })),
                   ...(hayEnLinea ? [{ id: EN_LINEA, texto: 'En línea' }] : [])];

  const abierto = (dia, horaInicio) => {
    const h = apertura.find(x => x.dia_semana === dia);
    if (!h) return false;
    const dentro = (a, c) => a && horaInicio >= a && horaInicio < c;
    return dentro(h.abre_1, h.cierra_1) || dentro(h.abre_2, h.cierra_2);
  };

  vista.innerHTML = `
    <div class="tarjeta">
      <header style="gap:10px">
        <h2 id="titulo-horario">Horario</h2>
        <div class="pestanas" id="modo-vista">
          <button type="button" data-modo="dia" aria-selected="${modo === 'dia'}">Un día, todos los salones</button>
          <button type="button" data-modo="salon" aria-selected="${modo === 'salon'}">Un salón, toda la semana</button>
        </div>
      </header>
      <div class="cuerpo" style="border-bottom:1px solid var(--linea);padding:14px 22px">
        <div class="pestanas" id="selector"></div>
      </div>
      <div class="caja-horario" id="contenedor-rejilla"></div>
      <div class="cuerpo suave" style="font-size:13px;border-top:1px solid var(--linea)">
        Cada renglón es media hora. Haz clic en cualquier clase para moverla de día, hora o salón.
        Las casillas rayadas son horas en que la escuela está cerrada. Las clases en línea no ocupan
        salón (van en su propia columna), pero sí el horario del maestro.
      </div>
    </div>`;

  /**
   * Dibuja la rejilla. Las columnas son los salones (vista por día) o los días
   * (vista por salón); el resto del armado es idéntico en los dos casos.
   */
  const dibujar = () => {
    const porDia = modo === 'dia';
    const columnas = porDia ? lugares : dias.map(d => ({ id: d, texto: diaBonito(d) }));

    const clases = ocupacion.filter(o => porDia
      ? o.dia_semana === diaActivo
      : salonDe(o) === salonActivo);

    const columnaDe = (c) => porDia ? salonDe(c) : c.dia_semana;
    const diaDe = (c) => porDia ? diaActivo : c;

    const filaDe = {};
    bloques.forEach((b, i) => { filaDe[b.hora_inicio] = i; });

    // Lo que se encima en una misma columna se junta en un solo bloque: el
    // grupo de Dibujo y Pintura, con alumnos que llegan a horas distintas, o
    // dos clases en línea de maestros distintos. Así nada queda escondido.
    const sueltas = [];
    clases.forEach(c => {
      const fila = filaDe[c.hora_inicio];
      if (fila === undefined) return;
      const alto = Math.max(1, Math.round((Number(c.duracion_min) || 60) / 30));
      sueltas.push({ clase: c, fila, fin: fila + alto, columna: columnaDe(c) });
    });
    sueltas.sort((x, y) => x.fila - y.fila);
    const piezas = [];
    sueltas.forEach(s => {
      const junta = piezas.find(p => p.columna === s.columna && p.fila < s.fin && s.fila < p.fin);
      if (junta) {
        junta.clases.push(s.clase);
        junta.fin = Math.max(junta.fin, s.fin);
      } else {
        piezas.push({ columna: s.columna, fila: s.fila, fin: s.fin, clases: [s.clase] });
      }
    });
    piezas.forEach(p => { p.alto = p.fin - p.fila; });
    const cubierto = new Set();
    piezas.forEach(p => { for (let k = 1; k < p.alto; k++) cubierto.add(`${p.columna}:${p.fila + k}`); });

    let html = `<div class="rejilla-horario"
      style="grid-template-columns:70px repeat(${columnas.length}, minmax(${porDia ? 130 : 140}px, 1fr));
             grid-template-rows:auto repeat(${bloques.length}, minmax(28px, auto));
             min-width:${70 + columnas.length * 135}px">`;

    html += `<div class="cab hora" style="grid-column:1;grid-row:1"></div>`;
    columnas.forEach((col, j) => {
      html += `<div class="cab" style="grid-column:${j + 2};grid-row:1">${esc(col.texto)}</div>`;
    });

    bloques.forEach((b, i) => {
      const enPunto = b.hora_inicio.endsWith(':00:00');
      html += `<div class="hora cifra${enPunto ? '' : ' media'}" style="grid-column:1;grid-row:${i + 2}">
                 ${hora(b.hora_inicio)}</div>`;

      columnas.forEach((col, j) => {
        if (cubierto.has(`${col.id}:${i}`)) return;
        const pieza = piezas.find(pz => pz.fila === i && pz.columna === col.id);
        if (pieza && pieza.clases.length > 1) {
          html += celdaVarias(pieza, j, i, porDia);
          return;
        }
        if (pieza) {
          const c = pieza.clases[0];
          const enGrupo = Number(c.en_clase) > 1 || Number(c.es_grupal) === 1;
          const online = c.modalidad === 'online';
          const marca = online ? ' <span class="insignia-online">EN LÍNEA</span>' : '';
          const todos = enGrupo ? alumnosConRango(c) : [];
          // Con muchos alumnos la casilla se desborda: se muestran los primeros
          // y el resto se cuenta. La lista completa sale al hacer clic.
          const integrantes = todos.length > 4
            ? [...todos.slice(0, 3), `y ${todos.length - 3} más`]
            : todos;
          const esGrupo = Number(c.es_grupal) === 1;
          html += `<div class="clase${enGrupo ? ' grupo' : ''}${online ? ' online' : ''}"
                        ${esGrupo ? `data-grupo="${c.id_inscripcion}"` : `data-mover="${c.id_inscripcion}"`}
                        title="${esGrupo ? 'Clic para ver el taller y editar a cada alumno'
                               : enGrupo ? `Clase compartida de ${c.en_clase} · clic para mover` : 'Clic para mover'}"
                        style="grid-column:${j + 2};grid-row:${i + 2} / span ${pieza.alto}">
                     ${enGrupo
                       ? `<b class="cabeza-grupo">${esc(esGrupo ? c.grupo_nombre : c.instrumento)}
                            <span class="cuantos">${c.en_clase}</span>${marca}</b>
                          <span class="integrantes" style="overflow-wrap:anywhere">${integrantes.join(' · ')}</span>
                          <span>${esc(nombreCompleto(c, 'maestro_'))}${porDia ? '' : ' · ' + esc(c.salon)}</span>`
                       : `<b>${esc(nombreCompleto(c, 'alumno_'))}${marca}</b>
                          <span>${esc(c.instrumento)} · ${esc(nombreCompleto(c, 'maestro_'))}</span>
                          <span>${hora(c.hora_inicio)}–${hora(c.hora_fin)}${porDia ? '' : ' · ' + esc(c.salon)}</span>`}
                   </div>`;
          return;
        }
        const cerrado = abierto(diaDe(col.id), b.hora_inicio) ? '' : 'cerrado';
        html += `<div class="${cerrado}" style="grid-column:${j + 2};grid-row:${i + 2}"></div>`;
      });
    });

    html += `</div>`;
    $('#contenedor-rejilla').innerHTML = html;

    $('#titulo-horario').textContent = porDia
      ? `${diaBonito(diaActivo)} · ${clases.length} ${clases.length === 1 ? 'clase' : 'clases'}`
      : `${lugares.find(s => s.id === salonActivo)?.texto ?? ''} · ${clases.length} clases`;

    $$('[data-mover]').forEach(celda => celda.addEventListener('click', () => {
      const c = ocupacion.find(o => String(o.id_inscripcion) === celda.dataset.mover);
      // Consulta solo ve: en vez de editar, se abre la historia de la clase.
      if (rolActual() === 'consulta') { historialClase(inscripcionDeOcupacion(c)); return; }
      formularioHorario(inscripcionDeOcupacion(c), () => refrescar());
    }));
    // Un grupo (Dibujo y Pintura): se abre con todos sus alumnos del día.
    $$('[data-grupo]').forEach(celda => celda.addEventListener('click', () => panelGrupo(Number(celda.dataset.grupo))));
    // Varias clases encimadas que no son un grupo (dos en línea a la vez, por ejemplo).
    $$('[data-varias]').forEach(celda => celda.addEventListener('click', () => {
      const ids = celda.dataset.varias.split(',');
      panelVarias(ocupacion.filter(o => ids.includes(String(o.id_inscripcion))));
    }));
  };

  const dibujarSelector = () => {
    const opciones = modo === 'dia'
      ? dias.map(d => ({ id: d, texto: diaBonito(d), activo: d === diaActivo }))
      : lugares.map(s => ({ ...s, activo: s.id === salonActivo }));

    $('#selector').innerHTML = opciones.map(o => `
      <button type="button" data-sel="${esc(o.id)}" aria-selected="${o.activo}">${esc(o.texto)}</button>`).join('');

    $$('#selector button').forEach(b => b.addEventListener('click', () => {
      if (modo === 'dia') diaActivo = b.dataset.sel; else salonActivo = b.dataset.sel;
      recordar();
      dibujarSelector();
      dibujar();
    }));
  };

  $$('#modo-vista button').forEach(b => b.addEventListener('click', () => {
    modo = b.dataset.modo;
    recordar();
    $$('#modo-vista button').forEach(x => x.setAttribute('aria-selected', String(x === b)));
    dibujarSelector();
    dibujar();
  }));

  dibujarSelector();
  dibujar();

  // El desplazamiento de la rejilla también se recuerda: se guarda al moverla y se restaura al redibujar.
  const caja = $('#contenedor-rejilla');
  if (recuerdo) { caja.scrollTop = recuerdo.arriba || 0; caja.scrollLeft = recuerdo.izquierda || 0; }
  caja.addEventListener('scroll', () => {
    if (memoriaHorarios) { memoriaHorarios.arriba = caja.scrollTop; memoriaHorarios.izquierda = caja.scrollLeft; }
  }, { passive: true });
};

/* ====================================================================
   VISTA · PAGOS
   ==================================================================== */

const LOTE_PAGOS = 200;   // lo máximo que el servidor devuelve por página

vistas.pagos = async () => {
  const [pagos, muestras, porConfirmar, sinConfirmar] = await Promise.all([
    api('pagos.php'),
    api('pagos.php?accion=muestras').catch(() => []),
    api('pagos.php?accion=por_confirmar').catch(() => []),
    // Recepción no ve esta lista: la resuelve administración.
    rolActual() !== 'recepcion' ? api('pagos.php?accion=sin_confirmar').catch(() => []) : Promise.resolve([])
  ]);

  const etiquetaConcepto = ETIQUETA_CONCEPTO;

  // Los que no cuadran con lo que paga el alumno van primero y se señalan. Si
  // su precio es en realidad el pago del maestro, no se propone nada.
  const difiere = (p) => p.sugerido === null || Math.abs(Number(p.sugerido) - Number(p.monto)) > 0.01;

  vista.innerHTML = `
    <div class="tarjeta">
      <header>
        <h2>Pagos</h2>
        <div class="barra-busqueda">
          <input class="campo" id="filtro-pagos" placeholder="Buscar un pago por nombre">
          <button class="pildora pildora-linea pildora-chica" data-escribe id="b-muestra">Cobrar clase muestra</button>
          <button class="pildora pildora-linea pildora-chica" data-escribe id="b-familia">Cobrar a una familia</button>
          <button class="pildora pildora-azul pildora-chica" data-escribe id="b-pago">Registrar pago</button>
        </div>
      </header>
    </div>

    <div id="tarjeta-facturas"></div>

    ${porConfirmar.length ? `
    <div class="tarjeta" id="tarjeta-por-confirmar">
      <header>
        <h2>Pagos por confirmar <span class="suave" style="font-weight:400" id="cuenta-confirmar">· ${porConfirmar.length}</span></h2>
      </header>
      <div class="cuerpo" style="padding-top:0">
        <p class="suave" style="margin:0 0 12px;font-size:13.5px">
          Se sabe que pagaron ese mes (venían de la hoja de pagos), pero falta anotar cuánto y cómo.
          Ya viene puesto lo que paga cada alumno: si entregó otra cantidad, cámbiala; elige cómo pagó y
          pulsa <strong>Confirmar</strong>. Mientras tanto, ese mes cuenta como pagado y no genera deuda.
          ${porConfirmar.filter(difiere).length ? `Los <span class="ambar">marcados</span> traían de la
            importación un importe distinto${esAdmin() ? ' (a veces era lo que se le paga al maestro)' : ''} y van primero.` : ''}
        </p>
        <div class="tabla-scroll">
          <table style="min-width:720px">
            <thead><tr>
              <th>Alumno</th><th>Mes</th><th>Cuánto pagó</th><th>Cómo pagó</th><th></th>
            </tr></thead>
            <tbody id="cuerpo-confirmar"></tbody>
          </table>
        </div>
      </div>
    </div>` : ''}

    ${sinConfirmar.length ? `
    <div class="tarjeta" id="tarjeta-sin-confirmar">
      <header>
        <h2>Meses por confirmar <span class="suave" style="font-weight:400">· antes de septiembre</span></h2>
      </header>
      <div class="cuerpo" style="padding-top:0">
        <p class="suave" style="margin:0 0 12px;font-size:13.5px">
          De estos meses no hay registro de pago. No cuentan como deuda hasta que se confirme hasta qué mes pagó cada alumno.
        </p>
        <div class="tabla-scroll">
          <table style="min-width:620px">
            <thead><tr><th>Alumno</th><th>Meses</th><th class="derecha">Colegiatura</th><th></th></tr></thead>
            <tbody>${sinConfirmar.map(x => `
              <tr>
                <td>${esc(nombreCompleto(x))}</td>
                <td class="suave">${esc(primeraMayuscula(rangoMeses(x.meses)))}</td>
                <td class="derecha cifra">${dinero(x.colegiatura)}</td>
                <td class="derecha">
                  <button type="button" class="pildora pildora-azul pildora-chica" data-solo-admin
                          data-resolver="${x.id_alumno}">Resolver</button>
                </td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>
    </div>` : ''}

    <div class="tarjeta">
      <header>
        <h2>Pagos registrados <span class="suave" style="font-weight:400" id="cuenta-pagos">· ${pagos.length}${pagos.length >= LOTE_PAGOS ? '+' : ''}</span></h2>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Fecha</th><th>Quién</th><th>Concepto</th><th>Periodo</th><th>Método</th>
            <th class="derecha">Monto</th><th class="derecha">Recargo</th><th class="derecha">Total</th><th></th>
          </tr></thead>
          <tbody id="cuerpo-pagos"></tbody>
        </table>
      </div>
      <div class="cuerpo centro oculto" id="pie-pagos" style="border-top:1px solid var(--linea)">
        <button type="button" class="pildora pildora-linea" id="b-ver-mas-pagos">Ver más pagos</button>
      </div>
    </div>

    ${muestras.length ? `
    <div class="tarjeta">
      <header>
        <h2>Clases muestra <span class="suave" style="font-weight:400">· ${muestras.length}</span></h2>
        <span class="suave" style="font-size:13.5px">
          ${muestras.filter(m => Number(m.se_inscribio)).length} terminaron inscritos
        </span>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Fecha</th><th>Nombre</th><th>Teléfono</th><th>Duración</th><th>Maestro</th><th class="derecha">Pagó</th><th>Seguimiento</th></tr></thead>
          <tbody>${muestras.map(m => `
            <tr>
              <td class="suave cifra">${esc(m.fecha_pago)}</td>
              <td>${esc(m.nombre_prospecto || '')}</td>
              <td class="suave">${esc(m.telefono_prospecto || '—')}</td>
              <td class="suave">${m.duracion_min} min</td>
              <td class="suave">${esc(m.maestro || '—')}</td>
              <td class="derecha cifra">${dinero(m.monto)}</td>
              <td>${Number(m.se_inscribio)
                    ? '<span class="etiqueta-estado verde">Se inscribió</span>'
                    : '<span class="etiqueta-estado suave">Sin inscribir</span>'}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>` : ''}`;

  // Lo que se ve: los pagos cargados, o los resultados de una búsqueda hecha en TODOS los pagos.
  let lista = pagos;
  let texto = '';
  let hayMas = pagos.length >= LOTE_PAGOS;
  let hayMasSinBuscar = hayMas;
  let turnoBusqueda = 0;
  let temporizador = null;

  const pintarPagos = (filas) => {
    $('#cuerpo-pagos').innerHTML = filas.length ? filas.map(p => `
      <tr>
        <td class="cifra suave">${esc(p.fecha_pago)}</td>
        <td>${esc(p.quien || 'Sin nombre')}</td>
        <td class="suave">${p.anula_a ? `Anulación del pago #${esc(p.anula_a)}` : esc(etiquetaConcepto[p.concepto] || p.concepto)}</td>
        <td class="suave">${esc(mesLargo(p.periodo))}</td>
        <td class="suave">${etiquetaMetodo(p.metodo)}</td>
        <td class="derecha cifra">${importePago(p)}${marcaMonto(p)}</td>
        <td class="derecha cifra ${Number(p.recargo) > 0 ? 'rojo' : 'suave'}">${Number(p.recargo) > 0 ? dinero(p.recargo) : '—'}</td>
        <td class="derecha cifra" ${Number(p.anulado) === 1 ? 'style="text-decoration:line-through"' : ''}>${dinero(Number(p.monto) + Number(p.recargo || 0))}</td>
        <td class="derecha">${p.id_alumno && sePuedeCorregir(p) ? `<button type="button" class="pildora pildora-linea pildora-chica"
              ${Number(p.monto_confirmado) ? 'data-solo-admin' : 'data-escribe'} data-corregir-pago="${p.id_pago}">${Number(p.monto_confirmado) ? 'Corregir' : 'Confirmar'}</button>` : ''}</td>
      </tr>`).join('')
      : `<tr><td colspan="9"><div class="mensaje-vacio">Sin pagos que mostrar.</div></td></tr>`;
    $$('#cuerpo-pagos [data-corregir-pago]').forEach(b => b.addEventListener('click', () => {
      formularioCorregirPago(lista.find(x => String(x.id_pago) === b.dataset.corregirPago)
        || pagos.find(x => String(x.id_pago) === b.dataset.corregirPago), () => refrescar());
    }));
  };

  // Lista de trabajo: los pagos por confirmar. Los que no cuadran, primero.
  porConfirmar.sort((x, y) => difiere(y) - difiere(x));
  let filtroConfirmar = '';
  const pintarPorConfirmar = () => {
    const cuerpo = $('#cuerpo-confirmar');
    if (!cuerpo) return;
    const lista = porConfirmar.filter(p => !filtroConfirmar || nombreCompleto(p).toLowerCase().includes(filtroConfirmar));
    $('#cuenta-confirmar').textContent = filtroConfirmar
      ? `· ${lista.length} de ${porConfirmar.length}` : `· ${porConfirmar.length}`;
    // Si quien se busca no tiene nada por confirmar, la tarjeta vacía solo estorba.
    $('#tarjeta-por-confirmar').classList.toggle('oculto', Boolean(filtroConfirmar) && !lista.length);
    cuerpo.innerHTML = lista.map(p => `
      <tr data-fila-confirmar="${p.id_pago}">
        <td>${esc(nombreCompleto(p))}
          <div style="font-size:12px" class="${difiere(p) ? 'ambar' : 'suave'}">${p.sugerido === null
            ? (esAdmin()
                ? `Su colegiatura registrada (${dinero(p.colegiatura)}) es lo que se le paga al maestro: escribe lo que pagó`
                : 'Su colegiatura registrada parece no ser la correcta: escribe lo que pagó.')
            : difiere(p)
            ? `Se importó ${dinero(p.monto)}; ${deDondeSugerido(p)} es ${dinero(p.sugerido)}`
            : `Coincide con ${deDondeSugerido(p)}`}</div>
          ${p.sugerido === null && Number(p.clases_vivas) === 1 ? `
            <label style="display:flex;gap:6px;align-items:center;font-size:12px;margin-top:4px">
              <input type="checkbox" data-actualizar-colegiatura checked>
              Es lo que paga cada mes: corregir también su colegiatura</label>` : ''}</td>
        <td class="suave" style="text-transform:capitalize">${esc(mesLargo(p.periodo))}</td>
        <td><input class="campo" type="number" step="0.01" min="0" data-monto-real
                   value="${p.sugerido === null ? '' : Number(p.sugerido).toFixed(2)}"
                   placeholder="¿Cuánto?" aria-label="Cuánto pagó ${esc(nombreCompleto(p))}"
                   style="max-width:120px;padding:7px 10px;font-size:14px"></td>
        <td><select class="campo" data-metodo-real aria-label="Cómo pagó ${esc(nombreCompleto(p))}"
                    style="padding:7px 10px;font-size:14px">
              <option value="">— Elige —</option>
              ${METODOS_PAGO.map(m => `<option value="${m.valor}">${m.texto}</option>`).join('')}
            </select></td>
        <td class="derecha">
          <button type="button" class="pildora pildora-azul pildora-chica" data-escribe data-confirmar="${p.id_pago}">Confirmar</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="5"><div class="mensaje-vacio">Nadie con ese nombre por confirmar.</div></td></tr>';

    $$('[data-confirmar]', cuerpo).forEach(b => b.addEventListener('click', async () => {
      const fila = b.closest('tr');
      const monto = $('[data-monto-real]', fila);
      const metodo = $('[data-metodo-real]', fila);
      if (!(Number(monto.value) > 0)) { errorEnCampo(monto, 'Escribe cuánto pagó.'); return; }
      if (!metodo.value) { errorEnCampo(metodo, 'Elige cómo pagó.'); return; }
      b.disabled = true;
      try {
        await api(`pagos.php?id=${b.dataset.confirmar}`, {
          method: 'PUT',
          cuerpo: { monto: monto.value, metodo: metodo.value, confirmar: 1,
                    actualizar_colegiatura: $('[data-actualizar-colegiatura]', fila)?.checked ? 1 : 0 }
        });
        // Se quita de la lista en su sitio, sin recargar: se sigue con el siguiente.
        const i = porConfirmar.findIndex(x => String(x.id_pago) === b.dataset.confirmar);
        if (i >= 0) porConfirmar.splice(i, 1);
        fila.remove();
        $('#cuenta-confirmar').textContent = `· ${porConfirmar.length}`;
        if (!porConfirmar.length) $('#tarjeta-por-confirmar')?.remove();
        aviso('Pago confirmado.', 'exito');
      } catch (e) { aviso(e.message, 'error'); b.disabled = false; }
    }));
  };
  pintarPorConfirmar();

  $$('[data-resolver]').forEach(b => b.addEventListener('click', () => {
    const x = sinConfirmar.find(s => String(s.id_alumno) === b.dataset.resolver);
    if (x) formularioHastaQueMes(x, x.meses, x.colegiatura, () => refrescar());
  }));

  const mostrarLista = () => {
    pintarPagos(lista);
    $('#cuenta-pagos').textContent = `· ${lista.length}${hayMas ? '+' : ''}`;
    $('#pie-pagos').classList.toggle('oculto', !hayMas);
  };

  // "Ver más" trae los 200 siguientes (después del último que ya se ve), sin repetir ni saltar ninguno.
  $('#b-ver-mas-pagos').addEventListener('click', async (e) => {
    const boton = e.currentTarget;
    const ultimo = lista[lista.length - 1];
    if (!ultimo) return;
    boton.disabled = true;
    const miTurno = turnoBusqueda;
    try {
      const mas = await api(`pagos.php?limite=${LOTE_PAGOS}&antes_fecha=${encodeURIComponent(ultimo.fecha_pago)}`
        + `&antes_id=${ultimo.id_pago}${texto ? '&q=' + encodeURIComponent(texto) : ''}`);
      if (miTurno !== turnoBusqueda) return;   // mientras tanto cambió la búsqueda
      lista.push(...mas);
      hayMas = mas.length >= LOTE_PAGOS;
      if (!texto) hayMasSinBuscar = hayMas;
      mostrarLista();
    } catch (err) { aviso(err.message, 'error'); }
    finally { boton.disabled = false; }
  });

  $('#b-familia').addEventListener('click', elegirFamiliaYCobrar);
  pintarFacturas(periodoActual());
  mostrarLista();
  $('#filtro-pagos').addEventListener('input', (e) => {
    const t = e.target.value.toLowerCase().trim();
    texto = t;
    const miTurno = ++turnoBusqueda;
    clearTimeout(temporizador);
    if (!t) {
      lista = pagos;
      hayMas = hayMasSinBuscar;
    } else {
      // Al instante se filtra lo que ya está cargado; enseguida se busca en TODOS los pagos.
      lista = pagos.filter(p => (p.quien || '').toLowerCase().includes(t));
      hayMas = false;
      temporizador = setTimeout(async () => {
        try {
          const r = await api(`pagos.php?limite=${LOTE_PAGOS}&q=${encodeURIComponent(t)}`);
          if (miTurno !== turnoBusqueda) return;
          lista = r;
          hayMas = r.length >= LOTE_PAGOS;
          mostrarLista();
        } catch { /* se queda lo filtrado de lo ya cargado */ }
      }, 250);
    }
    mostrarLista();
    // El buscador también filtra los pagos por confirmar: así se encuentra rápido el de alguien.
    filtroConfirmar = t;
    pintarPorConfirmar();
  });

  $('#b-pago').addEventListener('click', async () => {
    const alumnos = await api('alumnos.php?activos=1');
    const alumno = await elegirAlumno(alumnos, '¿De quién es el pago?');
    if (alumno) registrarPago(alumno, null, () => refrescar());
  });

  $('#b-muestra').addEventListener('click', formularioClaseMuestra);
};

/** Cobro de una clase de prueba a alguien que todavía no es alumno */
async function formularioClaseMuestra() {
  // Quién la impartió: hace falta para sumarla a su nómina.
  const activos = (window.__maestros || []).filter(m => Number(m.activo)).length
    ? (window.__maestros || []).filter(m => Number(m.activo))
    : await api('maestros.php').then(m => { window.__maestros = m; return m.filter(x => Number(x.activo)); })
                               .catch(() => []);
  const maestrosActivos = activos
    .map(m => ({ valor: m.id_maestro, texto: nombreCompleto(m) }))
    .sort((a, b) => a.texto.localeCompare(b.texto));
  const cfg = catalogos?.configuracion ?? { muestra_hora: 250, muestra_media_hora: 125 };
  const precioDe = (min) => Number(min) <= 30
    ? Number(cfg.muestra_media_hora)
    : Math.round(Number(cfg.muestra_hora) * (Number(min) / 60) * 100) / 100;

  modal({
    titulo: 'Cobrar clase muestra',
    textoAceptar: 'Registrar cobro',
    campos: `
      <div class="fila-campos">
        ${campoTexto('nombre', 'Nombre de quien toma la clase', { requerido: true, pista: 'Juan Pérez' })}
        ${campoTexto('telefono', 'Teléfono', { pista: '555 123 4567' })}
      </div>
      <div class="fila-campos">
        ${campoSelect('duracion_min', 'Duración', [
          { valor: 30, texto: 'Media hora' },
          { valor: 60, texto: 'Una hora' },
          { valor: 90, texto: 'Hora y media' },
          { valor: 120, texto: 'Dos horas' }], 60)}
        ${campoTexto('monto', 'Cobro', { tipo: 'number', paso: '0.01', valor: precioDe(60) })}
      </div>
      <div class="fila-campos">
        ${campoSelect('id_maestro', 'Maestro que la impartió',
          [{ valor: '', texto: '— Sin asignar —' }, ...maestrosActivos], '')}
        ${esAdmin() ? campoTexto('pago_maestro', 'Se le paga', { tipo: 'number', paso: '0.01' }) : ''}
      </div>
      <div id="aviso-muestra-maestro"></div>
      ${campoSelect('metodo', 'Método', [
        { valor:'efectivo', texto:'Efectivo' },
        { valor:'transferencia', texto:'Transferencia' },
        { valor:'tarjeta', texto:'Tarjeta' }])}
      <p class="suave" style="font-size:13px;margin:0">
        No hace falta que esté inscrito. Si después se inscribe, el sistema lo reconoce por su
        nombre y lo cuenta como clase muestra convertida.
      </p>`,
    alAceptar: async (datos, cerrar) => {
      if (!datos.nombre.trim()) throw new Error('Escribe el nombre de quien toma la clase.');
      const r = await api('pagos.php?accion=muestra', {
        cuerpo: {
          ...datos, duracion_min: Number(datos.duracion_min), monto: Number(datos.monto),
          id_maestro: datos.id_maestro ? Number(datos.id_maestro) : null,
          pago_maestro: esAdmin() && datos.id_maestro && datos.pago_maestro !== undefined && datos.pago_maestro !== ''
            ? Number(datos.pago_maestro) : ''
        }
      });
      aviso(`Clase muestra de ${r.nombre} cobrada: ${dinero(r.monto)}.`
          + (r.al_maestro ? ` Al maestro se le suman ${dinero(r.al_maestro)} en su nómina del mes.` : ''), 'exito');
      cerrar();
      refrescar();
    }
  });

  // El cobro se ajusta a la duración elegida, y con él lo que se le paga al maestro.
  const sel = $('#c-duracion_min');
  const pintarMaestro = () => {
    const m = (window.__maestros || []).find(x => String(x.id_maestro) === $('#c-id_maestro').value);
    const min = Number(sel.value);
    // Lo que vale una clase suya: la misma cuenta que usa su nómina.
    const valor = !m ? 0 : (m.modo_pago === 'por_hora'
      ? Math.round(Number(m.tarifa_por_hora) * (min / 60) * 100) / 100
      : Math.round(Number(m.tarifa_por_alumno) / clasesAlMes() * 100) / 100);
    const campoPago = $('#c-pago_maestro');   // solo administración lo ve
    if (campoPago) { campoPago.value = m ? valor : ''; campoPago.disabled = !m; }
    $('#aviso-muestra-maestro').innerHTML = m ? `
      <div class="suave" style="font-size:13px">
        Se le suma a la nómina de ${esc(nombreCompleto(m))} de este mes, como una clase suya.
        ${campoPago ? 'Puedes cambiar la cantidad.' : ''}
      </div>` : `
      <div class="suave" style="font-size:13px">
        Sin maestro solo se registra el cobro. Elige quién la impartió para que se le pague.
      </div>`;
  };
  sel?.addEventListener('change', () => { $('#c-monto').value = precioDe(sel.value); pintarMaestro(); });
  $('#c-id_maestro')?.addEventListener('change', pintarMaestro);
  pintarMaestro();
}

/* ====================================================================
   VISTA · NÓMINA
   ==================================================================== */

vistas.nomina = async () => {
  const periodos = await api('nomina.php?accion=periodos');
  // Abre en el mes en curso. (La lista de meses trae también el que entra, porque ya tiene renglones por los
  // cambios de clase y las altas del mes siguiente: no es una razón para abrir la nómina en un mes que no ha llegado.)
  const actual = location.hash.split('=')[1] || periodoActual();
  if (!periodos.some(p => p.periodo === actual)) periodos.unshift({ periodo: actual });
  periodos.sort((a, b) => String(b.periodo).localeCompare(String(a.periodo)));
  const resumen = await api(`nomina.php?periodo=${actual}`);

  // Entra quien tiene alumnos o quien tuvo faltas o suplencias este mes: un
  // suplente puede no tener alumnos propios y aun así cobrar.
  // Entra quien tenga alumnos o cualquier movimiento del mes: un suplente sin
  // alumnos propios, un bono de puntualidad o una clase muestra también se cobran.
  const conAlumnos = resumen.filter(m =>
    Number(m.num_alumnos) > 0 || Number(m.movimientos) > 0);
  const total = conAlumnos.reduce((s, m) => s + Number(m.total_a_pagar), 0);
  const pendientes = conAlumnos.filter(m => !m.pagado);
  const montoPendiente = pendientes.reduce((s, m) => s + Number(m.total_a_pagar), 0);
  // Alumnos distintos: uno con dos maestros cuenta una sola vez (sumar el de cada maestro lo contaría doble).
  const alumnosDistintos = resumen.length && resumen[0].alumnos_escuela !== undefined
    ? Number(resumen[0].alumnos_escuela) : conAlumnos.reduce((s, m) => s + Number(m.num_alumnos), 0);

  const ETIQUETA = {
    vencido:   { texto: 'Ya tocaba',  clase: 'rojo' },
    hoy:       { texto: 'Hoy',        clase: 'rojo' },
    proximo:   { texto: 'Próximo',    clase: 'suave' },
    sin_fecha: { texto: 'Sin horario', clase: 'ambar' },
    pagado:    { texto: 'Pagada',     clase: 'verde' },
  };

  const cuando = (m) => {
    if (m.pagado) return m.fecha_pagado ? `Pagada el ${esc(m.fecha_pagado)}` : 'Pagada';
    if (!m.fecha_pago_sugerida) return (m.dias_en_linea || []).length
      ? 'No viene a la escuela: solo da clases en línea' : 'Sin clases con horario';
    const d = Number(m.dias_para_pago);
    const fecha = new Date(m.fecha_pago_sugerida + 'T12:00:00')
      .toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' });
    if (d < 0) return `Le tocaba el ${fecha}`;
    if (d === 0) return `Le toca hoy, ${fecha}`;
    if (d === 1) return `Mañana, ${fecha}`;
    return `El ${fecha} · faltan ${d} días`;
  };

  vista.innerHTML = `
    <div class="tarjeta">
      <header>
        <h2>Nómina de ${mesLargo(actual)}</h2>
        <div class="barra-busqueda">
          <input class="campo" id="buscar-nomina" placeholder="Buscar maestro" style="max-width:200px">
          <select class="campo" id="sel-periodo" style="max-width:190px">
            ${(periodos.length ? periodos : [{ periodo: actual }]).map(p =>
              `<option value="${p.periodo}" ${p.periodo === actual ? 'selected' : ''}>${mesLargo(p.periodo)}</option>`).join('')}
          </select>
          <button class="pildora pildora-linea pildora-chica" data-escribe id="b-falta">Registrar falta</button>
          <button class="pildora pildora-linea pildora-chica" id="b-imprimir">Imprimir pendientes</button>
          <button class="pildora pildora-linea pildora-chica" data-solo-admin id="b-generar">Generar el mes actual</button>
          <button class="pildora pildora-linea pildora-chica" id="b-csv">Descargar CSV</button>
        </div>
      </header>

      <div class="cuerpo" style="display:flex;gap:32px;flex-wrap:wrap;border-bottom:1px solid var(--linea)">
        <div><div class="cifra ${pendientes.length ? 'rojo' : 'verde'}" style="font-size:30px;font-weight:600">${dinero(montoPendiente)}</div>
             <div class="suave" style="font-size:13px">Falta por pagar · ${pendientes.length} ${pendientes.length === 1 ? 'maestro' : 'maestros'}</div></div>
        <div><div class="cifra" style="font-size:30px;font-weight:600">${dinero(total)}</div>
             <div class="suave" style="font-size:13px">Total del mes</div></div>
        <div><div class="cifra" style="font-size:30px;font-weight:600">${alumnosDistintos}</div>
             <div class="suave" style="font-size:13px">Alumnos</div></div>
      </div>

      <div class="cuerpo" style="border-bottom:1px solid var(--linea);padding:14px 22px">
        <div class="pestanas" id="filtro-nomina">
          <button type="button" data-filtro="pendientes" aria-selected="true">Por pagar (${pendientes.length})</button>
          <button type="button" data-filtro="todos" aria-selected="false">Todos (${conAlumnos.length})</button>
        </div>
      </div>

      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Maestro</th><th>Días que viene</th><th>Cuándo se le paga</th>
            <th>Alumnos</th><th class="derecha">A pagar</th><th></th>
          </tr></thead>
          <tbody id="cuerpo-nomina"></tbody>
        </table>
      </div>
      <div class="cuerpo suave" style="border-top:1px solid var(--linea);font-size:13px">
        A cada maestro se le paga el último día del mes en que viene a la escuela. Si alguien solo
        da clase los miércoles, cobra el último miércoles; con varios días, el último de ellos.
      </div>
    </div>`;

  let filtro = 'pendientes';
  let busqueda = '';

  const visibles = () => {
    let lista = filtro === 'pendientes' ? pendientes : conAlumnos;
    if (busqueda) lista = lista.filter(m => nombreCompleto(m).toLowerCase().includes(busqueda));
    return lista;
  };

  const pintar = () => {
    const lista = visibles();
    $('#cuerpo-nomina').innerHTML = lista.length ? lista.map(m => {
      // Solo clases en línea: se le paga, pero no viene a la escuela ningún día.
      const e = m.urgencia === 'sin_fecha' && (m.dias_en_linea || []).length && !(m.dias || []).length
        ? { texto: 'Solo en línea', clase: 'suave' } : ETIQUETA[m.urgencia];
      return `
      <tr class="clicable" data-detalle="${m.id_maestro}">
        <td>
          ${esc(nombreCompleto(m))}
          ${Number(m.activo) === 0 ? '<span class="etiqueta-estado suave" style="margin-left:7px" title="Ya salió, pero todavía tiene nómina este mes">Ya no da clases</span>' : ''}
          <span class="etiqueta-estado ${e.clase}" style="margin-left:7px">${e.texto}</span>
        </td>
        <td class="suave" style="font-size:13px">${esc(textoDias(m))}</td>
        <td class="${m.urgencia === 'vencido' || m.urgencia === 'hoy' ? 'rojo' : 'suave'}" style="font-size:13px">${cuando(m)}</td>
        <td class="suave cifra">${m.num_alumnos}</td>
        <td class="derecha cifra">
          ${dinero(m.total_a_pagar)}
          ${Number(m.total_ajustes) !== 0 ? `<div style="font-size:12px" class="${Number(m.total_ajustes) < 0 ? 'rojo' : 'verde'}">
            ${Number(m.total_ajustes) < 0 ? '−' : '+'}${dinero(Math.abs(Number(m.total_ajustes)))}
            ${m.faltas ? `· ${m.faltas} ${m.faltas === 1 ? 'falta' : 'faltas'}` : ''}
            ${m.suplencias ? `· ${m.suplencias} ${m.suplencias === 1 ? 'suplencia' : 'suplencias'}` : ''}
            ${Number(m.bono) > 0 ? '· bono' : ''}
            ${m.muestras ? `· ${m.muestras} ${m.muestras === 1 ? 'muestra' : 'muestras'}` : ''}</div>` : ''}
        </td>
        <td class="derecha" style="white-space:nowrap">
          <button class="pildora pildora-linea pildora-chica" data-imprimir="${m.id_maestro}" title="Imprimir recibo">Recibo</button>
          <button class="pildora ${m.pagado ? 'pildora-linea' : 'pildora-azul'} pildora-chica"
                  data-solo-admin data-pagar="${m.id_maestro}" data-pagado="${m.pagado ? 1 : 0}">
            ${m.pagado ? 'Marcar pendiente' : 'Marcar pagada'}
          </button>
        </td>
      </tr>`;
    }).join('')
      : `<tr><td colspan="6"><div class="mensaje-vacio">${
          filtro === 'pendientes' && !busqueda
            ? 'No falta pagarle a nadie este mes.'
            : 'Sin resultados.'}</div></td></tr>`;
    conectar();
  };

  function conectar() {
    $$('[data-imprimir]').forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      imprimirRecibos([Number(b.dataset.imprimir)], actual);
    }));

    $$('[data-pagar]').forEach(b => b.addEventListener('click', async (e) => {
      e.stopPropagation();
      const id = Number(b.dataset.pagar);
      const maestro = resumen.find(m => Number(m.id_maestro) === id);
      const nombre = maestro ? nombreCompleto(maestro) : 'este maestro';

      if (b.dataset.pagado !== '1') {
        if (!confirm(`¿Marcar como pagada la nómina de ${nombre} de ${mesLargo(actual)}? Para cambiarla después habrá que regresarla a pendiente.`)) return;
        try {
          await api('nomina.php?accion=pagar', { cuerpo: { id_maestro: id, periodo: actual, pagado: true } });
          refrescar();
        } catch (err) { aviso(err.message, 'error'); }
        return;
      }

      // Regresarla a pendiente cambia lo ya pagado: se pide el motivo y queda registrado.
      modal({
        titulo: 'Regresar a pendiente',
        textoAceptar: 'Regresar a pendiente',
        ancho: '500px',
        campos: `
          <p style="margin:0;font-size:14px"><strong>${esc(nombre)}</strong> · ${esc(mesLargo(actual))}</p>
          <p class="suave" style="margin:0;font-size:13.5px">
            Esta nómina ya estaba marcada como pagada. Escribe por qué vuelve a quedar pendiente; queda registrado.
          </p>
          ${campoTexto('motivo', 'Motivo', { pista: 'Se marcó por error, faltó un pago…' })}`,
        alAceptar: async (datos, cerrar) => {
          const motivo = (datos.motivo || '').trim();
          if (motivo.length < 4) { errorEnCampo($('#c-motivo'), 'Escribe brevemente el motivo.'); return; }
          await api('nomina.php?accion=pagar', {
            cuerpo: { id_maestro: id, periodo: actual, pagado: false, motivo }
          });
          cerrar();
          refrescar();
        }
      });
    }));

    // El detalle de un maestro en el mes que se está viendo. Administración, con la nómina sin pagar,
    // puede corregirlo: quitar alumnos, agregar alumnos y registrar clases extra (solo cambia lo que se le
    // paga al maestro). Después de cada corrección se recarga el detalle y el resumen (el total cambió).
    let modificado = false;
    const abrirDetalle = async (id, fresco = false) => {
      let maestro = resumen.find(m => String(m.id_maestro) === String(id));
      let detalle;
      try {
        if (fresco || modificado) {
          maestro = (await api(`nomina.php?periodo=${actual}`)).find(m => String(m.id_maestro) === String(id)) || maestro;
        }
        detalle = await api(`nomina.php?maestro=${id}&periodo=${actual}`);
      } catch (e) { aviso(e.message, 'error'); return; }
      if (!maestro) { aviso('Ese maestro ya no aparece en la nómina de este mes.', 'error'); return; }

      const admin = esAdmin();
      const editable = admin && !maestro.pagado;
      const ctx = {
        maestro, periodo: actual,
        volver: () => abrirDetalle(id),
        cambio: async () => { modificado = true; await refrescar(); abrirDetalle(id, true); }
      };
      // En el mes en curso o uno futuro, un renglón quitado de una clase viva queda en $0 (si no, volvería solo).
      const quitado = (g) => actual >= periodoActual() && g.monto === 0 && g.clases.every(c => Number(c.ajuste_manual) === 1);
      const grupos = agruparPorAlumno(detalle);

      modal({
        titulo: `${nombreCompleto(maestro)} · ${mesLargo(actual)}`,
        textoAceptar: 'Cerrar',
        soloLectura: true,
        ancho: '760px',
        alAceptar: (_d, cerrar) => cerrar(),
        campos: `
          ${admin && maestro.pagado ? `<p style="margin:0;font-size:13.5px;padding:10px 14px;border-radius:10px;background:var(--sup2)">
            Esta nómina ya está pagada. Para corregirla, regrésala a pendiente.</p>` : ''}
          <p class="suave" style="margin:0;font-size:13.5px">${cuando(maestro)}</p>
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Alumno</th><th>Materia</th><th>Horario</th><th class="derecha">Paga el alumno</th><th class="derecha">Cobra el maestro</th>${editable ? '<th></th>' : ''}</tr></thead>
              <tbody>${grupos.length ? grupos.map(g => {
                const d = g.clases[0];
                return `
                <tr>
                  <td>${esc(g.nombre)}${g.varias ? `<div class="suave" style="font-size:12px;font-weight:400">${
                    esc(g.clases.map(c => desgloseClase(c) + (leyendaTramo(c) ? ` (${leyendaTramo(c)})` : '')).join(' · '))}</div>` : ''}</td>
                  <td class="suave">${g.varias ? esc([...new Set(g.clases.map(c => c.instrumento))].join(' · ')) : esc(d.instrumento)}</td>
                  <td class="suave">${g.varias ? `${g.clases.length} clases`
                    : (d.dia_semana ? esc(d.dia_semana) + ' ' + hora(d.hora_inicio) : 'Sin asignar')}</td>
                  <td class="derecha cifra suave">${dinero(g.paga_el_alumno)}</td>
                  <td class="derecha cifra">${dinero(g.monto)}${!g.varias && leyendaTramo(d)
                    ? `<div class="suave" style="font-size:12px;font-weight:400">${esc(leyendaTramo(d))}</div>` : ''}</td>
                  ${editable ? `<td class="derecha" style="white-space:nowrap">${quitado(g)
                    ? '<span class="suave" style="font-size:12px">Quitado este mes</span>'
                    : `<button type="button" class="pildora pildora-linea pildora-chica" data-solo-admin data-quitar="${g.id_alumno}">Quitar</button>`}</td>` : ''}
                </tr>`; }).join('')
                : `<tr><td colspan="${editable ? 6 : 5}"><div class="mensaje-vacio">Este maestro no tiene alumnos en la nómina de este mes.</div></td></tr>`}
              </tbody>
            </table>
          </div>
          ${editable ? `
          <div style="display:flex;gap:10px;flex-wrap:wrap" data-solo-admin>
            <button type="button" class="pildora pildora-linea pildora-chica" id="b-agregar-alumno">+ Agregar alumno</button>
            <button type="button" class="pildora pildora-linea pildora-chica" id="b-clase-extra">+ Clase extra</button>
          </div>
          <p class="suave" data-solo-admin style="font-size:12.5px;margin:-6px 0 0">Esto cambia solo lo que se le paga al maestro; lo que debe el alumno no cambia.</p>` : ''}
          <div id="ajustes-maestro"></div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <button type="button" class="pildora pildora-linea pildora-chica" id="b-recibo-detalle">Imprimir recibo</button>
            <p style="font-weight:600;margin:0">Total a pagar: ${dinero(maestro.total_a_pagar)}</p>
          </div>`
      });

      $('#b-recibo-detalle')?.addEventListener('click', () => imprimirRecibos([Number(id)], actual));
      $$('[data-quitar]').forEach(b => b.addEventListener('click', () => {
        const g = grupos.find(x => String(x.id_alumno) === b.dataset.quitar);
        if (g) formularioQuitarDeNomina(ctx, g);
      }));
      $('#b-agregar-alumno')?.addEventListener('click', () => iniciarAgregarAlumno(ctx));
      $('#b-clase-extra')?.addEventListener('click', () => formularioClaseExtra(ctx));

      const ajustes = await api(`nomina.php?accion=ajustes&maestro=${id}&periodo=${actual}`).catch(() => []);
      if (ajustes.length && $('#ajustes-maestro')) {
        $('#ajustes-maestro').innerHTML = `
          <p style="font-weight:500;margin:6px 0 8px;font-size:14px">Movimientos del mes</p>
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th class="derecha">Monto</th><th></th></tr></thead>
              <tbody>${ajustes.map(a => `
                <tr>
                  <td class="suave cifra">${esc(a.fecha)}</td>
                  <td>${etiquetaMovimiento(a)}</td>
                  <td class="suave" style="font-size:13px">${esc(detalleMovimiento(a))}</td>
                  <td class="derecha cifra ${Number(a.monto) < 0 ? 'rojo' : 'verde'}">${Number(a.monto) < 0 ? '−' : '+'}${dinero(Math.abs(Number(a.monto)))}</td>
                  <td class="derecha">${Number(a.pagado) || a.tipo === 'bono' ? '' :
                    `<button type="button" class="pildora pildora-roja pildora-chica" data-solo-admin data-anular="${a.id_ajuste}" data-es-extra="${claseExtraDe(a) ? 1 : 0}">Anular</button>`}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>`;
        $$('[data-anular]').forEach(b => b.addEventListener('click', async () => {
          if (!confirm(b.dataset.esExtra === '1'
            ? '¿Anular esta clase extra? Se le quita al maestro lo que se le había sumado.'
            : '¿Anular este movimiento? Si es una falta cubierta, también se anula la suplencia de quien la cubrió.')) return;
          try {
            await api(`nomina.php?accion=ajuste&id=${b.dataset.anular}`, { method: 'DELETE' });
            aviso('Movimiento anulado.', 'exito');
            await ctx.cambio();
          } catch (e) { aviso(e.message, 'error'); }
        }));
      }
    };

    $$('[data-detalle]').forEach(fila => fila.addEventListener('click', () => abrirDetalle(fila.dataset.detalle)));
  }

  pintar();

  $('#buscar-nomina').addEventListener('input', (e) => { busqueda = e.target.value.toLowerCase().trim(); pintar(); });

  $$('#filtro-nomina button').forEach(b => b.addEventListener('click', () => {
    filtro = b.dataset.filtro;
    $$('#filtro-nomina button').forEach(x => x.setAttribute('aria-selected', String(x === b)));
    pintar();
  }));

  $('#sel-periodo').addEventListener('change', (e) => { location.hash = `#nomina=${e.target.value}`; navegar(`#nomina=${e.target.value}`); });

  $('#b-falta').addEventListener('click', () => formularioFalta(resumen));

  $('#b-imprimir').addEventListener('click', () => {
    const ids = (filtro === 'pendientes' ? pendientes : conAlumnos)
      .filter(m => !busqueda || nombreCompleto(m).toLowerCase().includes(busqueda))
      .map(m => Number(m.id_maestro));
    imprimirRecibos(ids, actual);
  });

  $('#b-generar').addEventListener('click', async () => {
    if (!confirm('Se va a armar la nómina del mes en curso con las inscripciones vivas. Los renglones ya marcados como pagados no se tocan. ¿Continuar?')) return;
    try {
      const r = await api('nomina.php?accion=generar', { cuerpo: { periodo: periodoActual() } });
      aviso(`Nómina de ${mesLargo(r.periodo)}: ${r.renglones} renglones, ${dinero(r.total)}.`, 'exito');
      navegar(`#nomina=${r.periodo}`);
    } catch (e) { aviso(e.message, 'error'); }
  });

  $('#b-csv').addEventListener('click', async () => {
    const filas = [['Maestro', 'Alumno', 'Materia', 'Dia', 'Hora', 'Monto', 'Periodo', 'Estado']];
    for (const m of conAlumnos) {
      const detalle = await api(`nomina.php?maestro=${m.id_maestro}&periodo=${actual}`);
      // Un renglón por alumno con la suma; si toma varias clases, materia, día y hora van juntos con " + ".
      agruparPorAlumno(detalle).forEach(g => filas.push([
        nombreCompleto(m), g.nombre, g.clases.map(c => c.instrumento).join(' + '),
        g.clases.map(c => c.dia_semana || '').join(' + '), g.clases.map(c => hora(c.hora_inicio)).join(' + '),
        Number(g.monto).toFixed(2), actual, m.pagado ? 'Pagada' : 'Pendiente'
      ]));
      // Las clases extra del mes: "14/09 clase extra Ana López" con su importe.
      if (Number(m.movimientos) > 0) {
        const movs = await api(`nomina.php?accion=ajustes&maestro=${m.id_maestro}&periodo=${actual}`).catch(() => []);
        movs.forEach(a => {
          const ce = claseExtraDe(a);
          if (ce) filas.push([nombreCompleto(m), `${a.fecha.slice(8, 10)}/${a.fecha.slice(5, 7)} clase extra ${ce.alumno}`.trim(),
                              'Clase extra', '', '', Number(a.monto).toFixed(2), actual, m.pagado ? 'Pagada' : 'Pendiente']);
        });
      }
    }
    // Un texto que empieza con = + - @ se podría abrir como fórmula en Excel: se le antepone una comilla.
    // Los números normales (-50, 12.5) se dejan tal cual.
    const celda = (c) => {
      let t = String(c ?? '');
      if (/^[=+\-@\t\r]/.test(t) && !/^-?\d+(\.\d+)?$/.test(t)) t = "'" + t;
      return `"${t.replace(/"/g, '""')}"`;
    };
    const csv = filas.map(f => f.map(celda).join(',')).join('\n');
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }));
    enlace.download = `nomina-${actual}.csv`;
    enlace.click();
    URL.revokeObjectURL(enlace.href);
    aviso('CSV descargado.', 'exito');
  });
};

/* ------------------------------------- Correcciones a la nómina de un mes */

/**
 * Administración corrige lo que se le paga a un maestro en un mes (cualquiera, también uno pasado):
 * quita a un alumno, agrega a uno que faltó, o suma una clase extra. Cambia SOLO la nómina del maestro;
 * lo que debe el alumno no se toca. El servidor niega todo si la nómina de ese mes ya está pagada.
 *
 * `ctx` = { maestro (fila del resumen), periodo, volver() (reabre el detalle), cambio() (recarga y reabre) }.
 */
const soloMes = (periodo) => NOMBRE_MES[Number(String(periodo).slice(5, 7)) - 1] || periodo;

const NOTA_SOLO_MAESTRO = '<p class="suave" style="font-size:12.5px;margin:0">Esto cambia solo lo que se le paga al maestro; lo que debe el alumno no cambia.</p>';

/** Lo que vale una clase suelta de esa duración para ese maestro (la misma cuenta que hace el servidor) */
const valorDeClase = (maestro, minutos, periodo) => maestro.modo_pago === 'por_hora'
  ? Math.round(Number(maestro.tarifa_por_hora) * minutos / 60 * 100) / 100
  : Math.round(Number(maestro.tarifa_por_alumno) / Math.max(1, clasesAlMes(periodo)) * 100) / 100;

function formularioQuitarDeNomina(ctx, g) {
  const mes = soloMes(ctx.periodo);
  modal({
    titulo: 'Quitar de la nómina',
    textoAceptar: 'Quitar de la nómina',
    ancho: '480px',
    alCancelar: ctx.volver,
    campos: `
      <p style="margin:0;font-size:14px"><strong>${esc(g.nombre)}</strong> · ${dinero(g.monto)}</p>
      <p class="suave" style="margin:0;font-size:13.5px">
        Se quita de lo que se le paga a ${esc(nombreCompleto(ctx.maestro))} en ${esc(mes)}.
      </p>
      ${campoTexto('motivo', 'Motivo', { pista: 'No tomó clases ese mes, estaba repetido…' })}
      ${NOTA_SOLO_MAESTRO}`,
    alAceptar: async (datos, cerrar) => {
      const motivo = (datos.motivo || '').trim();
      if (motivo.length < 4) { errorEnCampo($('#c-motivo'), 'Escribe brevemente el motivo.'); return; }
      const r = await api('nomina.php?accion=quitar_alumno', {
        cuerpo: { id_maestro: Number(ctx.maestro.id_maestro), periodo: ctx.periodo, id_alumno: Number(g.id_alumno), motivo }
      });
      cerrar();
      aviso(r.en_cero
        ? `Quitado de la nómina de ${mes}. Sigue inscrito con este maestro, por eso queda en $0 y no se vuelve a agregar solo.`
        : `Quitado de la nómina de ${mes}.`, 'exito');
      await ctx.cambio();
    }
  });
}

/** Busca al alumno (también inactivos) y, según sus clases, abre el formulario que toca */
async function iniciarAgregarAlumno(ctx) {
  const mes = soloMes(ctx.periodo);
  let alumno;
  try {
    alumno = await elegirAlumno(await api('alumnos.php'), `¿A quién agregar a la nómina de ${mes}?`);
  } catch (e) { aviso(e.message, 'error'); ctx.volver(); return; }
  if (!alumno) { ctx.volver(); return; }
  let info;
  try {
    info = await api(`nomina.php?accion=clases_alumno&id_alumno=${alumno.id_alumno}&maestro=${ctx.maestro.id_maestro}&periodo=${ctx.periodo}`);
  } catch (e) { aviso(e.message, 'error'); ctx.volver(); return; }

  if (!info.clases.length) {
    // Sin ninguna clase registrada no hay a cuál agregarlo: lo que corresponde es una clase extra.
    modal({
      titulo: 'Agregar alumno',
      textoAceptar: 'Registrar como clase extra',
      ancho: '480px',
      alCancelar: ctx.volver,
      campos: `
        <p style="margin:0;font-size:14px"><strong>${esc(nombreCompleto(alumno))}</strong> no tiene ninguna clase registrada.</p>
        <p class="suave" style="margin:0;font-size:13.5px">
          Si ${esc(nombreCompleto(ctx.maestro))} le dio clase en ${esc(mes)}, regístrala como clase extra.
        </p>`,
      alAceptar: async (_d, cerrar) => { cerrar(); formularioClaseExtra(ctx, { alumno }); }
    });
    return;
  }
  formularioAgregarAlumno(ctx, alumno, info);
}

function formularioAgregarAlumno(ctx, alumno, info) {
  const mes = soloMes(ctx.periodo);
  const N = Number(info.semanas) || clasesAlMes(ctx.periodo);
  const libres = info.clases.filter(c => !c.ya_en_nomina);
  const inicial = libres.find(c => c.es_de_este_maestro) || libres[0] || null;
  const nombreClase = (c) => [c.instrumento, c.dia_semana ? `${diaBonito(c.dia_semana)} ${hora(c.hora_inicio)}` : 'sin horario',
                              `${c.duracion_min} min`].join(' · ');
  const opcionesClases = [];
  for (let i = N; i >= 1; i--) opcionesClases.push({ valor: i, texto: i === N ? `${i} (el mes completo)` : String(i) });
  const claseElegida = () => {
    const r = $('#capa-modal input[name="id_inscripcion"]:checked');
    return r ? info.clases.find(c => String(c.id_inscripcion) === r.value) : null;
  };
  // Mes completo: lo que vale el mes con la tarifa de este maestro; menos clases: cada clase vale lo suyo.
  const importeDe = (c, n) => String(n >= N ? Number(c.importe_mes) : Math.round(Number(c.valor_clase) * n * 100) / 100);

  modal({
    titulo: `Agregar a la nómina de ${mes}`,
    textoAceptar: `Agregar a la nómina de ${mes}`,
    ancho: '600px',
    alCancelar: ctx.volver,
    campos: `
      <p style="margin:0;font-size:14px"><strong>${esc(nombreCompleto(alumno))}</strong>${Number(info.alumno.activo) === 0 ? ' <span class="suave">· inactivo</span>' : ''}
        <span class="suave"> · en la nómina de ${esc(nombreCompleto(ctx.maestro))}</span></p>
      <div>
        <label class="etiqueta">¿Qué clase tomó?</label>
        <div style="border:1px solid var(--linea);border-radius:12px;overflow:hidden">
          ${info.clases.map(c => `
            <label style="display:flex;gap:10px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--linea);
                          font-size:14px;${c.ya_en_nomina ? 'opacity:.5' : ''}">
              <input type="radio" name="id_inscripcion" value="${c.id_inscripcion}" ${c.ya_en_nomina ? 'disabled' : ''}
                     ${inicial && inicial.id_inscripcion === c.id_inscripcion ? 'checked' : ''}>
              <span style="flex:1">${esc(nombreClase(c))}<span class="suave" style="font-size:12.5px"> · ${
                c.es_de_este_maestro ? 'con este maestro' : 'con ' + esc(c.maestro_actual)}${
                c.estado === 'baja' ? ' · dada de baja' : ''}${c.ya_en_nomina ? ' · ya está en esta nómina' : ''}</span></span>
            </label>`).join('')}
        </div>
        <p class="suave" id="pista-clase-ajena" style="font-size:12.5px;margin:6px 0 0"></p>
      </div>
      <div class="fila-campos">
        ${campoSelect('clases', 'Clases que tomó ese mes', opcionesClases, N)}
        ${campoTexto('monto', 'Importe para el maestro', { tipo: 'number', paso: '0.01', valor: inicial ? importeDe(inicial, N) : '' })}
      </div>
      ${campoTexto('motivo', 'Motivo', { pista: 'Faltó en la nómina, tomó clases ese mes…' })}
      ${NOTA_SOLO_MAESTRO}`,
    alAceptar: async (datos, cerrar) => {
      if (!datos.id_inscripcion) { errorEnFormulario('Elige a cuál de sus clases corresponde.'); return; }
      const monto = Number(datos.monto);
      if (String(datos.monto).trim() === '' || !Number.isFinite(monto) || monto < 0) {
        errorEnCampo($('#c-monto'), 'Escribe el importe (puede ser $0).'); return;
      }
      const motivo = (datos.motivo || '').trim();
      if (motivo.length < 4) { errorEnCampo($('#c-motivo'), 'Escribe brevemente el motivo.'); return; }
      const n = Number(datos.clases);
      const r = await api('nomina.php?accion=agregar_alumno', {
        cuerpo: { id_maestro: Number(ctx.maestro.id_maestro), periodo: ctx.periodo, id_inscripcion: Number(datos.id_inscripcion),
                  clases: n >= N ? null : n, monto, motivo }
      });
      cerrar();
      aviso(`Agregado a la nómina de ${mes}: ${dinero(r.monto)}.`, 'exito');
      await ctx.cambio();
    }
  });

  // El importe se calcula solo con la tarifa de ESTE maestro; si alguien lo cambia a mano, ya no se toca.
  let editado = false;
  const recalcular = () => {
    const c = claseElegida();
    if (c && !editado) $('#c-monto').value = importeDe(c, Number($('#c-clases').value) || N);
    $('#pista-clase-ajena').textContent = c && !c.es_de_este_maestro
      ? `Esa clase la da ${c.maestro_actual}. El importe es con la tarifa de ${nombreCompleto(ctx.maestro)}.` : '';
  };
  $$('#capa-modal input[name="id_inscripcion"]').forEach(r => r.addEventListener('change', recalcular));
  $('#c-clases').addEventListener('change', recalcular);
  $('#c-monto').addEventListener('input', (e) => { if (e.isTrusted) editado = true; });
  recalcular();
}

/**
 * Una clase que el maestro dio y no estaba en su horario. Se le suma al mes de la fecha (dentro del mes
 * que se está viendo). `ini` conserva lo capturado cuando se sale a elegir al alumno y se regresa.
 */
function formularioClaseExtra(ctx, ini = {}) {
  const mes = soloMes(ctx.periodo);
  const [anio, mm] = ctx.periodo.split('-').map(Number);
  const ultimo = `${ctx.periodo}-${String(new Date(anio, mm, 0).getDate()).padStart(2, '0')}`;
  const est = { fecha: ini.fecha || `${ctx.periodo}-01`, minutos: ini.minutos || 60, alumno: ini.alumno || null,
                monto: ini.monto ?? null, motivo: ini.motivo || '' };
  const sugerido = (minutos) => String(valorDeClase(ctx.maestro, minutos, ctx.periodo));

  modal({
    titulo: `Clase extra · ${mes}`,
    textoAceptar: 'Registrar clase extra',
    ancho: '560px',
    alCancelar: ctx.volver,
    campos: `
      <p class="suave" style="margin:0;font-size:13.5px">
        Una clase que ${esc(nombreCompleto(ctx.maestro))} dio y no estaba en su horario. Se le suma a la nómina de ${esc(mes)}.
      </p>
      <div class="fila-campos">
        ${campoTexto('fecha', 'Día que la dio', { tipo: 'date', valor: est.fecha })}
        ${campoSelect('minutos', 'Duración', [30, 45, 60, 90, 120].map(m => ({ valor: m, texto: `${m} minutos` })), est.minutos)}
      </div>
      <div>
        <label class="etiqueta">Alumno (opcional)</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <span style="font-size:14px">${est.alumno ? esc(nombreCompleto(est.alumno)) : '<span class="suave">Sin alumno</span>'}</span>
          <button type="button" class="pildora pildora-linea pildora-chica" id="b-extra-alumno">${est.alumno ? 'Cambiar' : 'Elegir alumno'}</button>
          ${est.alumno ? '<button type="button" class="pildora pildora-linea pildora-chica" id="b-extra-sin-alumno">Quitar</button>' : ''}
        </div>
      </div>
      <div class="fila-campos">
        ${campoTexto('monto', 'Importe para el maestro', { tipo: 'number', paso: '0.01', valor: est.monto ?? sugerido(est.minutos) })}
        ${campoTexto('motivo', 'Motivo', { pista: 'Reposición, ensayo extra…', valor: est.motivo })}
      </div>
      ${NOTA_SOLO_MAESTRO}`,
    alAceptar: async (datos, cerrar) => {
      if (!datos.fecha || datos.fecha < `${ctx.periodo}-01` || datos.fecha > ultimo) {
        errorEnCampo($('#c-fecha'), `Elige un día de ${mes}.`); return;
      }
      const monto = Number(datos.monto);
      if (String(datos.monto).trim() === '' || !Number.isFinite(monto) || monto <= 0) {
        errorEnCampo($('#c-monto'), 'Escribe el importe (más de $0).'); return;
      }
      const motivo = (datos.motivo || '').trim();
      if (motivo.length < 4) { errorEnCampo($('#c-motivo'), 'Escribe brevemente el motivo.'); return; }
      const r = await api('nomina.php?accion=clase_extra', {
        cuerpo: { id_maestro: Number(ctx.maestro.id_maestro), fecha: datos.fecha, minutos: Number(datos.minutos),
                  id_alumno: est.alumno ? Number(est.alumno.id_alumno) : null, monto, motivo }
      });
      cerrar();
      aviso(`Clase extra registrada: +${dinero(r.monto)}.`, 'exito');
      await ctx.cambio();
    }
  });

  $('#c-fecha').min = `${ctx.periodo}-01`;
  $('#c-fecha').max = ultimo;
  // El importe se calcula solo con la duración; si alguien lo cambia a mano, ya no se toca.
  let editado = est.monto !== null;
  $('#c-minutos').addEventListener('change', () => { if (!editado) $('#c-monto').value = sugerido(Number($('#c-minutos').value)); });
  $('#c-monto').addEventListener('input', (e) => { if (e.isTrusted) editado = true; });

  const capturado = () => ({ fecha: $('#c-fecha').value, minutos: Number($('#c-minutos').value), alumno: est.alumno,
                             monto: editado ? $('#c-monto').value : null, motivo: $('#c-motivo').value });
  $('#b-extra-alumno').addEventListener('click', async () => {
    const guardado = capturado();
    let elegido = null;
    try { elegido = await elegirAlumno(await api('alumnos.php'), 'Alumno de la clase extra'); }
    catch (e) { aviso(e.message, 'error'); }
    formularioClaseExtra(ctx, { ...guardado, alumno: elegido || guardado.alumno });
  });
  $('#b-extra-sin-alumno')?.addEventListener('click', () => formularioClaseExtra(ctx, { ...capturado(), alumno: null }));
}

/* ----------------------------------------------------- Registrar una falta */

/**
 * Un maestro no vino: se eligen el maestro y el día, el sistema trae las
 * clases que tenía ese día, se marcan las que no dio y, si alguien las cubrió,
 * se elige al suplente. Se ve cuánto se descuenta y cuánto se le suma a quien
 * cubrió antes de guardar.
 */
function formularioFalta(resumen) {
  // Recepción registra qué clases no se dieron; los importes los ve y decide administración.
  const conImportes = esAdmin();
  const maestros = resumen.map(m => ({ valor: m.id_maestro, texto: nombreCompleto(m) }))
    .sort((a, b) => a.texto.localeCompare(b.texto));
  const hoy = hoyLocal();

  modal({
    titulo: 'Registrar falta de un maestro',
    textoAceptar: 'Registrar',
    ancho: '680px',
    campos: `
      <div class="fila-campos">
        ${campoSelect('id_maestro', 'Maestro que faltó', maestros)}
        ${campoTexto('fecha', 'Día que faltó', { tipo: 'date', valor: hoy })}
      </div>
      <div id="clases-falta"><p class="suave" style="margin:0">Cargando sus clases…</p></div>
      <div class="fila-campos">
        ${campoSelect('id_suplente', '¿Alguien cubrió esas clases?',
          [{ valor: '', texto: 'Nadie: solo se descuenta' }, ...maestros])}
        ${campoTexto('motivo', 'Motivo (opcional)', { pista: 'Enfermedad, permiso…' })}
      </div>
      <div id="resumen-falta"></div>`,
    alAceptar: async (datos, cerrar) => {
      const marcadas = $$('[data-clase-falta]:checked').map(c => ({
        id_inscripcion: Number(c.dataset.claseFalta),
        ...(conImportes ? { monto: Number($(`#monto-${c.dataset.claseFalta}`)?.value || 0) } : {})
      }));
      if (!marcadas.length) throw new Error('Marca al menos una clase que no se dio.');
      const r = await api('nomina.php?accion=falta', {
        cuerpo: {
          id_maestro: Number(datos.id_maestro), fecha: datos.fecha, clases: marcadas,
          id_suplente: datos.id_suplente ? Number(datos.id_suplente) : null,
          motivo: datos.motivo || null
        }
      });
      aviso(conImportes
        ? `Se descontaron ${dinero(r.descontado)} por ${r.clases} ${r.clases === 1 ? 'clase' : 'clases'}${
          r.sumado_suplente ? ` y se le suman ${dinero(r.sumado_suplente)} a quien cubrió` : ''}.`
          + (r.bono_perdido ? ` También pierde su bono de ${dinero(r.bono_perdido)} de este mes.` : '')
        : `Falta registrada: ${r.clases} ${r.clases === 1 ? 'clase' : 'clases'}. Administración la verá en la nómina.`, 'exito');
      cerrar();
      refrescar();
    }
  });

  let clases = [];

  const pintarResumen = () => {
    const marcadas = $$('[data-clase-falta]:checked');
    if (!conImportes) {
      $('#resumen-falta').innerHTML = marcadas.length ? `
        <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px;font-size:13.5px">
          Se registran ${marcadas.length} ${marcadas.length === 1 ? 'clase' : 'clases'} como no dadas.
          Administración verá el descuento en la nómina.
        </div>` : '';
      return;
    }
    const total = marcadas.reduce((s, c) => s + Number($(`#monto-${c.dataset.claseFalta}`)?.value || 0), 0);
    const suplente = $('#c-id_suplente')?.selectedOptions[0];
    $('#resumen-falta').innerHTML = marcadas.length ? `
      <div style="border:1px solid var(--linea);border-radius:12px;padding:12px 15px;font-size:13.5px">
        Se le descuentan <strong class="rojo">${dinero(total)}</strong> por ${marcadas.length}
        ${marcadas.length === 1 ? 'clase' : 'clases'}.
        ${suplente?.value ? `<br><span class="suave">A ${esc(suplente.textContent)} se le suma lo que valga
          esa clase según su propia tarifa.</span>` : ''}
      </div>` : '';
  };

  const cargar = async () => {
    const caja = $('#clases-falta');
    const id = $('#c-id_maestro').value;
    const fecha = $('#c-fecha').value;
    if (!id || !fecha) return;
    caja.innerHTML = '<p class="suave" style="margin:0">Cargando sus clases…</p>';
    try {
      const r = await api(`nomina.php?accion=clases_dia&maestro=${id}&fecha=${fecha}`);
      clases = r.clases;
      const sueltas = r.sin_horario || [];

      // Una clase importada sin día ni hora sí se imparte, pero el sistema no
      // sabe si le tocaba ese día: se ofrece aparte y sin marcar.
      const bloqueSueltas = sueltas.length ? `
        <label class="etiqueta" style="margin-top:12px">
          Clases suyas todavía sin día ni hora · márcalas si a esa le tocaba ese día
        </label>
        <div style="border:1px solid var(--linea);border-radius:12px;overflow:hidden">
          ${sueltas.map(c => `
            <label style="display:flex;gap:10px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--linea);
                          font-size:14px;${c.ya_descontada ? 'opacity:.5' : ''}">
              <input type="checkbox" data-clase-falta="${c.id_inscripcion}" ${c.ya_descontada ? 'disabled' : ''}>
              <span style="flex:1">
                ${esc(nombreCompleto(c, 'alumno_'))}
                <span class="suave">· ${esc(c.instrumento)} · sin horario</span>
                ${c.ya_descontada ? '<span class="suave"> · ya descontada</span>' : ''}
              </span>
              ${conImportes ? `<input class="campo" type="number" step="0.01" id="monto-${c.id_inscripcion}" value="${c.valor}"
                     style="max-width:110px;padding:7px 10px;font-size:14px" ${c.ya_descontada ? 'disabled' : ''}
                     title="Lo que se descuenta por esta clase">` : ''}
            </label>`).join('')}
        </div>
        <p class="suave" style="font-size:12.5px;margin:6px 0 0">
          Aparecen aquí porque se importaron sin horario. Asígnales día y hora desde
          <strong>Pendientes</strong> y se acomodan solas arriba.
        </p>` : '';

      caja.innerHTML = (clases.length || sueltas.length) ? `
        ${clases.length ? `<label class="etiqueta">Clases de ese ${esc(r.dia)} · marca las que no se dieron</label>` : ''}
        <div style="border:1px solid var(--linea);border-radius:12px;overflow:hidden;${clases.length ? '' : 'display:none'}">
          ${clases.map(c => `
            <label style="display:flex;gap:10px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--linea);
                          font-size:14px;${c.ya_descontada ? 'opacity:.5' : ''}">
              <input type="checkbox" data-clase-falta="${c.id_inscripcion}" ${c.ya_descontada ? 'disabled' : 'checked'}>
              <span style="flex:1">
                ${hora(c.hora_inicio)}–${hora(c.hora_fin)} · ${esc(nombreCompleto(c, 'alumno_'))}
                <span class="suave">· ${esc(c.instrumento)}</span>
                ${c.ya_descontada ? '<span class="suave"> · ya descontada</span>' : ''}
              </span>
              ${conImportes ? `<input class="campo" type="number" step="0.01" id="monto-${c.id_inscripcion}" value="${c.valor}"
                     style="max-width:110px;padding:7px 10px;font-size:14px" ${c.ya_descontada ? 'disabled' : ''}
                     title="Lo que se descuenta por esta clase">` : ''}
            </label>`).join('')}
        </div>
        ${conImportes ? `<p class="suave" style="font-size:12.5px;margin:6px 0 0">
          El monto es lo que vale una sesión con su tarifa. Puedes cambiarlo si ese día se trató distinto.
        </p>` : ''}${bloqueSueltas}`
        : `<p class="suave" style="margin:0">Ese maestro no tiene ninguna clase registrada.</p>`;
      $$('[data-clase-falta], #clases-falta input[type=number]').forEach(e =>
        e.addEventListener('input', pintarResumen));
      pintarResumen();
    } catch (e) {
      caja.innerHTML = `<p class="rojo" style="margin:0">${esc(e.message)}</p>`;
    }
  };

  $('#c-id_maestro').addEventListener('change', cargar);
  $('#c-fecha').addEventListener('change', cargar);
  $('#c-id_suplente').addEventListener('change', pintarResumen);
  cargar();
}

/* ====================================================================
   RECIBO DE NÓMINA IMPRESO
   Se abre en una ventana aparte con el mismo estilo de la página y se manda a
   imprimir. Cada recibo cabe en media hoja carta, que es lo que entra en el
   sobre en que se le entrega el dinero al maestro.
   ==================================================================== */

/** Cómo se ve cada movimiento de nómina fuera de lo normal */
const MOVIMIENTO = {
  falta:     '<span class="rojo">Falta</span>',
  suplencia: '<span class="verde">Suplencia</span>',
  bono:      '<span class="verde">Bono</span>',
  muestra:   '<span class="verde">Clase muestra</span>',
  otro:      '<span class="suave">Ajuste</span>',
};

// >>> clase-extra
/**
 * Una clase extra es un movimiento 'otro' cuyo motivo empieza con "Clase extra" (sin cambiar el ENUM de la
 * base). Si el alumno no tenía clase con ese maestro, su nombre va entre paréntesis: "Clase extra (Ana López): ...".
 * @returns {null|{alumno: string, motivo: string}}
 */
const claseExtraDe = (a) => {
  if (!a || a.tipo !== 'otro') return null;
  const m = String(a.motivo || '').match(/^Clase extra(?: \(([^)]*)\))?(?::\s*([\s\S]*))?$/);
  if (!m) return null;
  return { alumno: a.alumno_nombre ? nombreCompleto(a, 'alumno_') : (m[1] || ''), motivo: (m[2] || '').trim() };
};
// <<< clase-extra

/** Cómo se llama un movimiento en "Movimientos del mes" */
const etiquetaMovimiento = (a) => claseExtraDe(a) ? '<span class="verde">Clase extra</span>'
  : (MOVIMIENTO[a.tipo] || '<span class="verde">Suplencia</span>');

/** El detalle de un movimiento: de quién y por qué */
const detalleMovimiento = (a) => {
  const ce = claseExtraDe(a);
  if (ce) return [ce.alumno, ce.motivo].filter(Boolean).join(' · ');
  return [a.alumno_nombre ? nombreCompleto(a, 'alumno_') : '', a.motivo || ''].filter(Boolean).join(' · ');
};

const DIA_CON_ACENTO = { Miercoles: 'Miércoles', Sabado: 'Sábado' };
const diaBonito = (d) => DIA_CON_ACENTO[d] || d;

/**
 * Días del maestro para mostrar: los que viene a la escuela y, aparte, los
 * que solo da clases en línea (se pagan, pero no son asistencia).
 */
function textoDias(m, corto = false) {
  const f = (d) => corto ? diaBonito(d).slice(0, 3) : diaBonito(d);
  const sep = corto ? ' ' : ', ';
  const presenciales = (m.dias || []);
  const enLinea = (m.dias_en_linea || []).filter(d => !presenciales.includes(d));
  if (!presenciales.length && !enLinea.length) return corto ? 'sin horario' : '—';
  return [presenciales.map(f).join(sep), enLinea.length ? `en línea: ${enLinea.map(f).join(sep)}` : '']
    .filter(Boolean).join(' · ');
}

/**
 * Formato de impresión de los recibos:
 *   · 'sobre' (el de siempre en la escuela): hoja Carta horizontal, márgenes de
 *     60 mm, escala 130 %. El área que se imprime es de 159.4 × 85 mm, centrada:
 *     un sobre por hoja.
 *   · 'hoja': varios recibos por hoja carta vertical, para archivarlos.
 * La escala no se puede fijar desde la página en la ventana de impresión del
 * navegador; se reproduce aquí (el recibo se dibuja al 130 %). Por eso en esa
 * ventana hay que dejar Escala y Márgenes en "Predeterminado".
 */
const SOBRE = { anchoMm: 159.4, altoMm: 85, escala: 1.3, margenMm: 60, altoAreaMm: 215.9 - 2 * 60 };

const formatoRecibos = () => {
  try { return localStorage.getItem('escuela-recibos-formato') || 'sobre'; }
  catch { return 'sobre'; }
};

function htmlRecibo(r, formato = 'sobre') {
  const m = r.maestro;
  const alumnos = agruparPorAlumno(r.detalle);
  const n = alumnos.length;
  const filas = n + r.ajustes.length;
  // Con más renglones, más columnas y letra más chica: el recibo siempre mide lo mismo.
  const [columnas, apretado] = formato === 'sobre'
    ? (filas > 42 ? [3, ' muy-apretado'] : filas > 28 ? [3, ' apretado'] : filas > 16 ? [2, ' apretado']
       : filas > 8 ? [2, ''] : [1, ''])
    : (n > 30 ? [4, ' muy-apretado'] : n > 16 ? [3, ' apretado'] : n > 7 ? [2, ''] : [1, '']);

  // Nombre completo; la versión corta solo se usa si al imprimir no cabe.
  const nombre = (completo, extra = '') =>
    `<span class="q ajusta" data-corto="${esc(nombreCorto(completo) + extra)}">${esc(completo + extra)}</span>`;

  // Un renglón por alumno: con varias clases va la suma y "(2 clases)"; con una a medias, "(2/4)".
  const linea = (g) => {
    const d = g.clases[0];
    const extra = g.varias ? ` (${g.clases.length} clases)`
      : (d.clases !== null && d.clases !== undefined ? ` (${d.clases}/${r.semanas_mes || clasesAlMes(r.periodo)})` : '');
    return `
    <li>
      ${nombre(g.nombre, extra)}
      <span class="m">${dineroCorto(g.monto)}</span>
    </li>`;
  };

  const ajuste = (a) => {
    const extra = claseExtraDe(a);   // "14/09 clase extra Ana López"
    const que = (a.tipo === 'bono' ? '' : a.fecha.slice(8, 10) + '/' + a.fecha.slice(5, 7) + ' ')
      + (extra ? 'clase extra'
        : ({ falta: 'falta', suplencia: 'suplencia', bono: 'bono puntualidad', muestra: 'clase muestra' }[a.tipo] || 'ajuste'));
    const quien = extra ? extra.alumno : (a.alumno_nombre ? nombreCompleto(a, 'alumno_') : '');
    return `
    <li class="${Number(a.monto) < 0 ? 'resta' : 'suma'}">
      <span class="q ajusta" data-corto="${esc(que + (quien ? ' ' + nombreCorto(quien) : ''))}">${esc(que + (quien ? ' ' + quien : ''))}</span>
      <span class="m">${Number(a.monto) < 0 ? '−' : '+'}${dineroCorto(Math.abs(Number(a.monto)))}</span>
    </li>`;
  };

  return `
  <section class="recibo${apretado}" data-columnas="${columnas}">
    <header>
      <img src="${new URL('img/logo-negro.svg', location.href)}" alt="" onerror="this.remove()">
      <div class="quien">
        <span class="rotulo">Recibo de nómina</span>
        <b class="ajusta maestro" data-corto="${esc(nombreCorto(nombreCompleto(m)))}">${esc(nombreCompleto(m))}</b>
        <span>${esc(mesLargo(r.periodo))} · ${esc(textoDias(r, true))}
          · ${m.modo_pago === 'por_hora' ? `${dineroCorto(m.tarifa_por_hora)}/hora` : `${dineroCorto(m.tarifa_por_alumno)}/alumno`}</span>
      </div>
      <div class="pago">
        <span>Total</span>
        <b>${dinero(r.total)}</b>
      </div>
    </header>

    <div class="cuerpo">
      <ul class="lista" style="column-count:${columnas}">
        ${alumnos.map(linea).join('')}
        ${r.ajustes.map(ajuste).join('')}
      </ul>
    </div>

    <footer>
      <span class="sumas">${n} ${n === 1 ? 'alumno' : 'alumnos'} ${dineroCorto(r.base)}${
        r.ajuste !== 0 ? ` · ajustes ${r.ajuste < 0 ? '−' : '+'}${dineroCorto(Math.abs(r.ajuste))}` : ''}</span>
      <span class="sello">${r.pagado ? 'Pagado' : ''}</span>
    </footer>
  </section>`;
}

/** "María Fernanda López Hernández" -> "María F. López H.". Solo se usa si el completo no cabe. */
function nombreCorto(completo) {
  const p = String(completo).trim().split(/\s+/);
  if (p.length <= 2) return p.join(' ');
  return [p[0], ...p.slice(1).map(x => (x.length > 3 ? x[0] + '.' : x))].join(' ');
}

/** 1,350 sin decimales ni símbolo repetido: en el recibo el espacio es oro */
function dineroCorto(n) {
  return '$' + new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(Number(n) || 0);
}

/**
 * El documento que se imprime. Separado de la ventana para poder revisarlo
 * sin mandarlo a la impresora.
 *
 * @param {boolean} autoImprimir abre el cuadro de imprimir al cargar
 */
function documentoRecibos(recibos, periodo, formato, autoImprimir = true) {
  const sobre = formato === 'sobre';
  // En el sobre todo se dibuja al 130 %: la caja mide el área entre 1.3 para
  // que, ya escalada, ocupe exactamente 159.4 × 85 mm, centrada en el alto.
  const ancho = (SOBRE.anchoMm / SOBRE.escala).toFixed(3);
  const alto = (SOBRE.altoMm / SOBRE.escala).toFixed(3);
  const arriba = ((SOBRE.altoAreaMm - SOBRE.altoMm) / 2 / SOBRE.escala).toFixed(3);
  return `<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Nómina ${esc(mesLargo(periodo))}</title>
<style>
  ${sobre
    ? `/* Sobre: Carta horizontal, márgenes de 60 mm, escala 130 %, un sobre por hoja. */
  @page { size: letter landscape; margin: ${SOBRE.margenMm}mm; }`
    : `/* Hoja: varios recibos por hoja carta vertical, para archivar. */
  @page { size: letter portrait; margin: 5mm; }`}
  * { box-sizing: border-box; }
  /* Sin esto el navegador quita los fondos al imprimir y el total, blanco sobre
     negro, sale invisible: justo el número que importa. */
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; color: #000;
         font: 8pt/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, system-ui, sans-serif; }

  .recibo {
    padding: 4mm 5mm 3mm;
    border: 1px dashed #9a9a9a; border-radius: 3mm;
    display: flex; flex-direction: column; overflow: hidden;
    break-inside: avoid; page-break-inside: avoid;
    margin: 0 auto;
  }
  ${sobre
    ? `.recibo { width: ${ancho}mm; height: ${alto}mm; zoom: ${SOBRE.escala}; margin-top: ${arriba}mm; }
  .recibo:not(:last-of-type) { break-after: page; page-break-after: always; }`
    : `.recibo { width: 165mm; height: 88mm; padding: 5mm 6mm 4mm; }
  .recibo + .recibo { margin-top: 1.5mm; }`}

  header { display: flex; align-items: center; gap: 3mm; padding-bottom: 2mm; border-bottom: 1px solid #000; }
  header img { height: 9mm; }
  .quien { flex: 1; min-width: 0; }
  .quien b { display: block; font-size: 12pt; letter-spacing: -.01em; line-height: 1.1;
             white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .quien b.chico { font-size: 10pt; }
  .quien span { display: block; font-size: 7pt; color: #444; margin-top: .6mm; }
  .quien .rotulo { font-size: 6pt; letter-spacing: .06em; text-transform: uppercase; color: #777; margin: 0 0 .3mm; }
  .pago { text-align: right; background: #000; color: #fff; border-radius: 2mm; padding: 1.5mm 3mm; }
  .pago span { display: block; font-size: 6.5pt; opacity: .75; }
  .pago b { font-size: 14pt; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }

  .cuerpo { flex: 1; min-height: 0; overflow: hidden; padding-top: 1.5mm; }
  ul.lista { list-style: none; margin: 0; padding: 0; column-gap: 4mm; column-rule: 1px solid #ddd; }
  /* Con pocos alumnos sobra espacio: se usa para que la letra sea más grande. */
  ul.lista li { display: flex; justify-content: space-between; gap: 2mm;
                break-inside: avoid; padding: 1mm 0; font-size: 8.5pt; }
  .medio ul.lista li { font-size: 7.6pt; padding: .45mm 0; }
  .apretado ul.lista li { font-size: 6.8pt; padding: .15mm 0; }
  .muy-apretado ul.lista li { font-size: 6.2pt; padding: 0; }
  .minimo ul.lista li { font-size: 5.4pt; padding: 0; line-height: 1.12; }
  ul.lista .q { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  ul.lista .m { font-variant-numeric: tabular-nums; white-space: nowrap; }
  li.resta .m, li.resta .q { color: #b3261e; }
  li.suma .m, li.suma .q { color: #12633f; }

  footer { display: flex; align-items: flex-end; gap: 4mm; padding-top: 1.5mm; margin-top: 1mm;
           border-top: 1px solid #000; font-size: 6.5pt; color: #444; }
  footer .sumas { flex: 1; }
  footer .sello { width: 18mm; text-align: right; font-weight: 600; color: #000; }

  .acciones { position: fixed; top: 10px; right: 10px; display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .acciones button { font: 13px system-ui; border-radius: 980px; padding: 8px 18px; border: 1px solid #1d1d1f;
                     background: #fff; cursor: pointer; }
  .acciones button.primario { background: #0071e3; border-color: #0071e3; color: #fff; }
  .nota { font: 13px/1.4 system-ui; max-width: 560px; margin: 0 auto 18px; padding: 10px 14px;
          background: #fff; border: 1px solid #d2d2d7; border-radius: 12px; }
  @media screen { body { background: #f5f5f7; padding: 60px 0 20px; } .recibo { background: #fff; margin-bottom: 14px; } }
  @media print { .acciones, .nota { display: none; } }
</style></head>
<body>
  <div class="acciones">
    <button class="primario" onclick="window.print()">Imprimir</button>
    <button onclick="cambiarFormato()">${sobre ? 'Varios por hoja' : 'Un sobre por hoja'}</button>
    <button onclick="window.close()">Cerrar</button>
  </div>
  ${sobre ? `<p class="nota">Listo para sobre: hoja <strong>Carta</strong>, <strong>horizontal</strong>, márgenes de
    <strong>60 mm</strong> y escala de <strong>130 %</strong>, un sobre por hoja. En la ventana de impresión deja
    <strong>Márgenes</strong> y <strong>Escala</strong> en <em>Predeterminado</em>: si pones 130 % ahí, se
    agranda dos veces.</p>` : ''}
  ${recibos.map(r => htmlRecibo(r, formato)).join('')}
  <script>
    // Nombre completo si cabe; si no, primero letra un poco más chica (el
    // maestro) y luego la versión corta. Nunca se abrevia lo que sí cabe.
    function ajustarNombres() {
      document.querySelectorAll('.ajusta').forEach(function (el) {
        if (el.scrollWidth <= el.clientWidth + 1) return;
        if (el.classList.contains('maestro')) {
          el.classList.add('chico');
          if (el.scrollWidth <= el.clientWidth + 1) return;
        }
        if (el.dataset.corto) el.textContent = el.dataset.corto;
      });
    }
    // Que se vean TODOS los renglones: si la lista no cabe en el recibo (muchos
    // alumnos, suplencias, faltas), se pasa al siguiente acomodo hasta que quepa:
    // más columnas y letra más chica. El recibo siempre mide lo mismo.
    // Se prueba de la letra más grande a la más chica y se queda con la primera que cabe.
    var ACOMODOS = [[1, ''], [2, ''], [2, 'medio'], [2, 'apretado'], [3, 'medio'], [3, 'apretado'],
                    [3, 'muy-apretado'], [4, 'muy-apretado'], [4, 'minimo'], [5, 'minimo']];
    function acomodarRecibos() {
      document.querySelectorAll('.recibo').forEach(function (rec) {
        var cuerpo = rec.querySelector('.cuerpo'), ul = rec.querySelector('ul.lista');
        if (!cuerpo || !ul) return;
        var i = 0;
        function aplica(k) {
          rec.classList.remove('medio', 'apretado', 'muy-apretado', 'minimo');
          if (ACOMODOS[k][1]) rec.classList.add(ACOMODOS[k][1]);
          ul.style.columnCount = ACOMODOS[k][0];
        }
        aplica(i);
        while (ul.scrollHeight > cuerpo.clientHeight + 1 && i < ACOMODOS.length - 1) { i++; aplica(i); }
        rec.classList.toggle('no-cabe', ul.scrollHeight > cuerpo.clientHeight + 1);
      });
    }
    var yaImprimio = ${autoImprimir ? 'false' : 'true'};
    window.addEventListener('load', function () {
      acomodarRecibos();
      ajustarNombres();
      setTimeout(function () { if (!yaImprimio) { yaImprimio = true; window.print(); } }, 250);
    });
    // El formato se guarda en la ventana que abrió esta, que es la del sistema.
    function cambiarFormato() {
      yaImprimio = true;
      try { window.opener.cambiarFormatoRecibos(); } catch (e) {}
      window.close();
    }
  <\/script>
</body></html>`;
}

async function imprimirRecibos(idsMaestros, periodo) {
  if (!idsMaestros.length) { aviso('No hay recibos que imprimir.', 'error'); return; }
  const formato = formatoRecibos();

  // Se abre la ventana primero, de forma síncrona al clic, para que el
  // navegador no la bloquee como ventana emergente.
  const ventana = window.open('', '_blank');
  if (!ventana) { aviso('El navegador bloqueó la ventana de impresión. Permite ventanas emergentes para este sitio.', 'error'); return; }
  ventana.document.write('<p style="font:15px system-ui;padding:40px">Preparando recibos…</p>');

  let recibos;
  try {
    recibos = await Promise.all(idsMaestros.map(id => api(`nomina.php?accion=recibo&maestro=${id}&periodo=${periodo}`)));
  } catch (e) {
    ventana.close();
    aviso(e.message, 'error');
    return;
  }

  ventana.document.open();
  ventana.document.write(documentoRecibos(recibos, periodo, formato));
  ventana.document.close();

  // La ventana de impresión pide el cambio: se guarda y se vuelve a abrir.
  window.cambiarFormatoRecibos = () => {
    const nuevo = formatoRecibos() === 'sobre' ? 'hoja' : 'sobre';
    try { localStorage.setItem('escuela-recibos-formato', nuevo); } catch {}
    aviso(nuevo === 'sobre' ? 'Los recibos se imprimirán uno por sobre.' : 'Los recibos se imprimirán varios por hoja.', 'exito');
    imprimirRecibos(idsMaestros, periodo);
  };
}

/* ====================================================================
   VISTA · REPORTES
   ==================================================================== */

vistas.reportes = async () => {
  const d = await api('reportes.php');
  const b = d.balance;

  const barra = (valor, maximo, color = 'var(--azul)') => `
    <span style="display:block;height:8px;border-radius:980px;background:var(--sup2);overflow:hidden">
      <span style="display:block;height:100%;width:${maximo > 0 ? (valor / maximo) * 100 : 0}%;
                   background:${color};border-radius:980px"></span>
    </span>`;

  const maxMes = Math.max(...d.mensuales.map(m => Number(m.total_ingresos)), 1);
  const maxMateria = Math.max(...d.por_materia.map(m => Number(m.ingreso_mensual)), 1);
  const maxDia = Math.max(...d.ocupacion.por_dia.map(x => Number(x.horas)), 1);

  vista.innerHTML = `
    <!-- El mes en curso -->
    <section class="panel-negro" style="padding:34px 30px">
      <p class="fecha">${mesLargo(b.mes)}</p>
      <h1 style="font-size:clamp(28px,4vw,44px)">${dinero(b.balance_estimado)}</h1>
      <p>Entró ${dinero(b.ingresos)} y sale ${dinero(b.nomina_del_mes)} de nómina.</p>
      <p style="font-size:14px;margin-top:14px">
        Llevas cobrado el ${b.cobrado_del_esperado}% de las colegiaturas comprometidas
        (${dinero(b.colegiaturas_esperadas)} al mes).
      </p>
    </section>

    <!-- Cobranza -->
    <div class="rejilla k3" style="margin-bottom:18px">
      <div class="tarjeta dato">
        <div class="valor cifra ${d.adeudos.cuantos ? 'rojo' : 'verde'}">${d.adeudos.cuantos}</div>
        <div class="nombre">Alumnos deben ${mesLargo(d.adeudos.periodo)}</div>
        <div class="suave" style="font-size:13px;margin-top:6px">
          ${dinero(d.adeudos.monto)} por cobrar · vencía el ${esc(d.adeudos.vence || '')}
        </div>
      </div>
      <div class="tarjeta dato">
        <div class="valor cifra">${d.clases_muestra.total}</div>
        <div class="nombre">Clases muestra dadas</div>
        <div class="suave" style="font-size:13px;margin-top:6px">
          ${d.clases_muestra.convertidas} se inscribieron · ${d.clases_muestra.conversion}% de conversión
        </div>
      </div>
      <div class="tarjeta dato">
        <div class="valor cifra">${d.ocupacion.por_dia.reduce((s, x) => s + Number(x.clases), 0)}</div>
        <div class="nombre">Clases agendadas a la semana</div>
        <div class="suave" style="font-size:13px;margin-top:6px">
          ${d.ocupacion.por_dia.reduce((s, x) => s + Number(x.horas), 0).toFixed(1)} horas de salón
        </div>
      </div>
    </div>

    <!-- Mes a mes -->
    <div class="tarjeta">
      <header><h2>Mes a mes</h2><span class="suave" style="font-size:13.5px">Ingresos contra nómina</span></header>
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Mes</th><th style="width:26%"></th>
            <th class="derecha">Colegiaturas</th><th class="derecha">Inscripciones</th>
            <th class="derecha">Recargos</th><th class="derecha">Muestras</th><th class="derecha">Entró</th>
            <th class="derecha">Nómina</th><th class="derecha">Resultado</th>
          </tr></thead>
          <tbody>${d.mensuales.length ? d.mensuales.map(m => `
            <tr>
              <td>${esc(mesLargo(m.mes))}</td>
              <td>${barra(Number(m.total_ingresos), maxMes)}</td>
              <td class="derecha cifra">${dinero(m.colegiaturas)}</td>
              <td class="derecha cifra ${Number(m.inscripciones) > 0 ? '' : 'suave'}">${Number(m.inscripciones) > 0 ? dinero(m.inscripciones) : '—'}</td>
              <td class="derecha cifra ${Number(m.recargos) > 0 ? 'ambar' : 'suave'}">${Number(m.recargos) > 0 ? dinero(m.recargos) : '—'}</td>
              <td class="derecha cifra suave">${Number(m.muestras) > 0 ? dinero(m.muestras) : '—'}</td>
              <td class="derecha cifra">${dinero(m.total_ingresos)}</td>
              <td class="derecha cifra suave">${dinero(m.nomina)}</td>
              <td class="derecha cifra ${Number(m.resultado) < 0 ? 'rojo' : 'verde'}">${dinero(m.resultado)}</td>
            </tr>`).join('')
            : '<tr><td colspan="9"><div class="mensaje-vacio">Todavía no hay movimientos.</div></td></tr>'}
          </tbody>
        </table>
      </div>
    </div>

    <div class="rejilla principal" style="gap:18px;margin-top:18px">
      <!-- Materias -->
      <div class="tarjeta">
        <header><h2>Qué sostiene a la escuela</h2><span class="suave" style="font-size:13.5px">Colegiaturas comprometidas</span></header>
        <div class="tabla-scroll">
          <table>
            <thead><tr><th>Materia</th><th style="width:28%"></th><th>Alumnos</th><th class="derecha">Al mes</th></tr></thead>
            <tbody>${d.por_materia.length ? d.por_materia.map(m => `
              <tr>
                <td>${esc(m.instrumento)}</td>
                <td>${barra(Number(m.ingreso_mensual), maxMateria)}</td>
                <td class="suave cifra">${m.alumnos}</td>
                <td class="derecha cifra">${dinero(m.ingreso_mensual)}</td>
              </tr>`).join('')
              : '<tr><td colspan="4"><div class="mensaje-vacio">Sin inscripciones.</div></td></tr>'}
            </tbody>
          </table>
        </div>
      </div>

      <!-- Ocupación -->
      <div class="tarjeta">
        <header><h2>Qué tan llena está la escuela</h2></header>
        <div class="cuerpo">
          ${d.ocupacion.por_dia.map(x => `
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
              <span class="suave" style="width:80px;font-size:13px">${esc(x.dia_semana)}</span>
              <span style="flex:1">${barra(Number(x.horas), maxDia)}</span>
              <span class="cifra" style="width:70px;text-align:right;font-size:13px">${x.horas} h</span>
            </div>`).join('') || '<p class="suave" style="margin:0">Sin clases agendadas.</p>'}
          <hr style="border:0;border-top:1px solid var(--linea);margin:16px 0">
          ${d.ocupacion.por_salon.map(x => `
            <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:5px 0">
              <span>${esc(x.salon)}</span>
              <span class="suave cifra">${x.clases} clases · ${x.horas} h</span>
            </div>`).join('')}
        </div>
      </div>
    </div>

    <!-- Maestros -->
    <div class="tarjeta" style="margin-top:18px">
      <header>
        <h2>Cada maestro</h2>
        <span class="suave" style="font-size:13.5px">Lo que generan sus alumnos contra lo que cuesta su nómina</span>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Maestro</th><th>Alumnos</th><th>Cómo cobra</th>
            <th class="derecha">Genera</th><th class="derecha">Cuesta</th><th class="derecha">Diferencia</th>
          </tr></thead>
          <tbody>${d.por_maestro.length ? d.por_maestro.map(m => {
            const dif = Number(m.genera) - Number(m.cuesta);
            return `
            <tr>
              <td>${esc(nombreCompleto(m))}</td>
              <td class="suave cifra">${m.alumnos}</td>
              <td class="suave">${Number(m.lleva_nomina) === 0 ? 'Sin nómina'
                                   : m.modo_pago === 'por_hora' ? 'Por hora' : 'Por alumno'}</td>
              <td class="derecha cifra">${dinero(m.genera)}</td>
              <td class="derecha cifra suave">${Number(m.lleva_nomina) === 0 ? '—' : dinero(m.cuesta)}</td>
              <td class="derecha cifra ${dif < 0 ? 'rojo' : ''}">${dinero(dif)}</td>
            </tr>`; }).join('')
            : '<tr><td colspan="6"><div class="mensaje-vacio">Sin datos de maestros.</div></td></tr>'}
          </tbody>
        </table>
      </div>
      <div class="cuerpo suave" style="border-top:1px solid var(--linea);font-size:13px">
        "Genera" es la suma de las colegiaturas de sus alumnos activos. "Cuesta" es su nómina del mes.
        Los dos números son independientes: cambiar uno no mueve el otro.
      </div>
    </div>

    <!-- Quién debe -->
    ${d.adeudos.cuantos ? `
    <div class="tarjeta" style="margin-top:18px">
      <header>
        <h2>Quién debe ${mesLargo(d.adeudos.periodo)}</h2>
        <span class="cifra">${dinero(d.adeudos.monto)}</span>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Alumno</th><th>Teléfono</th><th class="derecha">Colegiatura</th><th class="derecha">Recargo</th><th class="derecha">Total</th></tr></thead>
          <tbody>${d.adeudos.alumnos.map(a => `
            <tr>
              <td>${esc(nombreCompleto(a))}</td>
              <td class="suave">
                ${esc(a.telefono || '—')}
                ${a.telefono ? `<button class="pildora pildora-linea pildora-chica" style="margin-left:8px"
                    data-wa-rep="${a.id_alumno}">WhatsApp</button>` : ''}
              </td>
              <td class="derecha cifra">${dinero(a.colegiatura)}</td>
              <td class="derecha cifra ${Number(a.recargo) > 0 ? 'rojo' : 'suave'}">${Number(a.recargo) > 0 ? dinero(a.recargo) : '—'}</td>
              <td class="derecha cifra">${dinero(a.total)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>` : ''}

    <!-- Altas y bajas -->
    ${d.movimiento.length ? `
    <div class="tarjeta" style="margin-top:18px">
      <header><h2>Altas y bajas</h2></header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Mes</th><th class="derecha">Altas</th><th class="derecha">Bajas</th><th class="derecha">Neto</th></tr></thead>
          <tbody>${d.movimiento.map(m => `
            <tr>
              <td>${esc(mesLargo(m.mes))}</td>
              <td class="derecha cifra verde">${m.altas}</td>
              <td class="derecha cifra ${Number(m.bajas) > 0 ? 'rojo' : 'suave'}">${m.bajas}</td>
              <td class="derecha cifra">${Number(m.altas) - Number(m.bajas)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>` : ''}`;

  $$('[data-wa-rep]').forEach(b => b.addEventListener('click', () => {
    const a = d.adeudos.alumnos.find(x => String(x.id_alumno) === b.dataset.waRep);
    avisarPorWhatsApp(a.telefono, mensajeCobro(nombreCompleto(a), a.total, d.adeudos.periodo));
  }));
};

/* ====================================================================
   VISTA · BITÁCORA
   ==================================================================== */

vistas.bitacora = async () => {
  const notas = await api('notas.php');

  vista.innerHTML = `
    <div class="tarjeta">
      <header><h2>Bitácora del turno</h2></header>
      <div class="cuerpo" style="border-bottom:1px solid var(--linea)">
        <form id="form-nota" data-escribe style="display:flex;gap:10px;flex-wrap:wrap">
          <input class="campo" name="mensaje" style="flex:1;min-width:240px"
                 placeholder="Qué necesita saber el siguiente turno" required>
          <button class="pildora pildora-azul" type="submit">Dejar nota</button>
        </form>
      </div>
      <ul class="lista-simple" style="display:block">
        ${notas.length ? notas.map(n => `
          <li style="display:flex;gap:16px;align-items:flex-start">
            <div style="flex:1">
              <p style="margin:0;${Number(n.resuelto) ? 'text-decoration:line-through;color:var(--suave)' : ''}">${esc(n.mensaje)}</p>
              <p class="suave" style="margin:6px 0 0;font-size:12.5px">${esc(n.autor)} · ${esc((n.fecha_creacion || '').slice(0, 16))}</p>
            </div>
            ${Number(n.resuelto) ? '' :
              `<button class="pildora pildora-linea pildora-chica" data-escribe data-resolver="${n.id_nota}">Resuelta</button>`}
          </li>`).join('')
          : '<li><div class="mensaje-vacio" style="width:100%">Sin notas por ahora.</div></li>'}
      </ul>
    </div>`;

  $('#form-nota').addEventListener('submit', async (e) => {
    e.preventDefault();
    const mensaje = e.target.mensaje.value.trim();
    if (!mensaje) return;
    try {
      await api('notas.php', { cuerpo: { mensaje } });
      refrescar();
    } catch (err) { aviso(err.message, 'error'); }
  });

  $$('[data-resolver]').forEach(b => b.addEventListener('click', async () => {
    try {
      await api(`notas.php?id=${b.dataset.resolver}`, { method: 'PUT' });
      refrescar();
    } catch (err) { aviso(err.message, 'error'); }
  }));
};

/* ====================================================================
   VISTA · IMPORTAR
   ==================================================================== */

let previaImportacion = null;

vistas.importar = async () => {
  const historial = await api('importar.php?accion=historial').catch(() => []);

  vista.innerHTML = `
    <div class="tarjeta">
      <header>
        <h2>Importar desde Excel</h2>
        <div class="pestanas" id="tipo-import">
          <button type="button" data-tipo="nomina" aria-selected="true">Nómina</button>
          <button type="button" data-tipo="horarios" aria-selected="false">Horarios</button>
          <button type="button" data-tipo="pagos" aria-selected="false">Pagos</button>
        </div>
      </header>

      <div class="cuerpo" data-panel-import="nomina">
        <p class="suave" style="margin-top:0">
          La hoja de nómina tal como la llevas: bloques con <strong>PROFESOR:</strong> arriba, los
          alumnos abajo y la fila de TOTAL al final. Crea maestros, alumnos y los renglones de
          nómina del mes. Si vuelves a subir el mismo mes, actualiza en vez de duplicar.
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
          <input type="file" id="archivo-nomina" accept=".xlsx" class="campo" style="max-width:340px">
          <button class="pildora pildora-linea" id="b-previa-nomina" disabled>Revisar el archivo</button>
        </div>
      </div>

      <div class="cuerpo oculto" data-panel-import="horarios">
        <p class="suave" style="margin-top:0">
          El tablero de horarios: un bloque por día, los salones en columnas y las medias horas en
          renglones, con celdas que dicen <strong>Alumno-Maestro</strong>. Una clase de una hora ocupa
          dos renglones seguidos y el sistema los junta solo.
        </p>
        <p class="suave" style="margin:0 0 14px">
          Como la hoja solo trae nombres de pila, cada clase se compara con las inscripciones que ya
          existen. Te muestro cuáles casan, cuáles son ambiguas y cuáles no existen todavía, antes de
          guardar nada.
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
          <input type="file" id="archivo-horarios" accept=".xlsx" class="campo" style="max-width:340px">
          <button class="pildora pildora-linea" id="b-previa-horarios" disabled>Revisar el archivo</button>
        </div>
      </div>

      <div class="cuerpo oculto" data-panel-import="pagos">
        <p class="suave" style="margin-top:0">
          El tablero de pagos: los alumnos en las casillas, cada una pintada del color del último mes
          que pagaron. La guía de colores se lee <strong>del propio archivo</strong>, de las celdas con
          el nombre de cada mes, así que si cambias un color el sistema lo sigue entendiendo.
        </p>
        <p class="suave" style="margin:0 0 14px">
          Por cada alumno se registra un pago del mes que marca su color, y los meses posteriores
          quedan como adeudo. No se duplica nada: si ese alumno ya tiene registrado ese mes, se salta.
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
          <input type="file" id="archivo-pagos" accept=".xlsx" class="campo" style="max-width:340px">
          <button class="pildora pildora-linea" id="b-previa-pagos" disabled>Revisar el archivo</button>
        </div>
      </div>

      <div id="resultado-previa"></div>
    </div>

    ${historial.length ? `
    <div class="tarjeta">
      <header><h2>Importaciones anteriores</h2></header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Fecha</th><th>Tipo</th><th>Archivo</th><th>Periodo</th><th>Filas</th><th class="derecha">Total</th></tr></thead>
          <tbody>${historial.map(h => `
            <tr>
              <td class="suave cifra">${esc((h.fecha || '').slice(0, 16))}</td>
              <td class="suave">${esc(h.tipo)}</td>
              <td>${esc(h.archivo)}</td>
              <td class="suave">${esc(mesLargo(h.periodo))}</td>
              <td class="cifra suave">${h.filas_importadas}</td>
              <td class="derecha cifra">${Number(h.total) > 0 ? dinero(h.total) : '—'}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>` : ''}`;

  $$('#tipo-import button').forEach(b => b.addEventListener('click', () => {
    $$('#tipo-import button').forEach(x => x.setAttribute('aria-selected', String(x === b)));
    $$('[data-panel-import]').forEach(pa => pa.classList.toggle('oculto', pa.dataset.panelImport !== b.dataset.tipo));
    $('#resultado-previa').innerHTML = '';
  }));

  const revisar = async (tipo, entrada, boton) => {
    boton.disabled = true;
    boton.textContent = 'Leyendo…';
    try {
      const cuerpo = new FormData();
      cuerpo.append('archivo', entrada.files[0]);
      cuerpo.append('tipo', tipo);
      const respuesta = await fetch(`${API}/importar.php?accion=previsualizar`, {
        method: 'POST', body: cuerpo, credentials: 'include',
        headers: tokenCsrf ? { 'X-CSRF-Token': tokenCsrf } : {}
      });
      const datos = await respuesta.json();
      if (!datos.ok) throw new Error(datos.error);
      previaImportacion = datos.datos;
      if (tipo === 'nomina') pintarPreviaNomina(datos.datos, entrada.files[0]);
      else if (tipo === 'pagos') pintarPreviaPagos(datos.datos, entrada.files[0]);
      else pintarPreviaHorarios(datos.datos, entrada.files[0]);
    } catch (e) {
      aviso(e.message || 'No se pudo leer el archivo.', 'error');
    } finally {
      boton.disabled = false;
      boton.textContent = 'Revisar el archivo';
    }
  };

  const conectar = (tipo) => {
    const entrada = $(`#archivo-${tipo}`);
    const boton = $(`#b-previa-${tipo}`);
    entrada.addEventListener('change', () => { boton.disabled = !entrada.files.length; });
    boton.addEventListener('click', () => revisar(tipo, entrada, boton));
  };
  conectar('nomina');
  conectar('horarios');
  conectar('pagos');
};

/* ---------------------------------------------------- Vista previa: pagos */

function pintarPreviaPagos(previa, archivo) {
  const r = previa.resumen;
  const meses = Object.entries(r.por_mes || {}).sort().reverse();

  const grupo = (estado) => (previa.alumnos || []).filter(a => a.estado === estado);

  const tabla = (lista, extra) => `
    <div class="tabla-scroll" style="max-height:260px;overflow-y:auto">
      <table>
        <thead><tr><th>Alumno en la hoja</th><th>Maestro</th><th>Último mes pagado</th><th>${extra}</th></tr></thead>
        <tbody>${lista.map(a => `
          <tr>
            <td>${esc(a.nombre_hoja)}</td>
            <td class="suave">${esc(a.maestro_hoja || '—')}</td>
            <td class="suave">${esc(mesLargo(a.periodo))}</td>
            <td class="suave" style="font-size:13px">${esc(a.nota || a.motivo || '')}</td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`;

  $('#resultado-previa').innerHTML = `
    <div class="cuerpo" style="border-top:1px solid var(--linea)">
      <div class="rejilla k4" style="margin-bottom:18px">
        <div><div class="cifra verde" style="font-size:28px;font-weight:600">${r.nuevo}</div>
             <div class="suave" style="font-size:13px">Pagos por registrar</div></div>
        <div><div class="cifra" style="font-size:28px;font-weight:600">${r.ya_estaba}</div>
             <div class="suave" style="font-size:13px">Ya estaban registrados</div></div>
        <div><div class="cifra ${r.no_encontrado ? 'ambar' : 'suave'}" style="font-size:28px;font-weight:600">${r.no_encontrado}</div>
             <div class="suave" style="font-size:13px">No se encontraron</div></div>
        <div><div class="cifra ${r.sin_precio ? 'rojo' : 'suave'}" style="font-size:28px;font-weight:600">${r.sin_precio}</div>
             <div class="suave" style="font-size:13px">Sin precio en su clase</div></div>
      </div>

      <p class="suave" style="font-size:13.5px;margin:0 0 14px">
        Último mes pagado según los colores:
        ${meses.map(([m, n]) => `<strong>${esc(mesLargo(m))}</strong> ${n}`).join(' · ')}
      </p>

      ${r.sin_precio ? `
        <div style="border:1px solid var(--linea);border-left:3px solid var(--rojo);border-radius:12px;padding:13px 16px;margin-bottom:16px">
          <strong class="rojo">${r.sin_precio} alumnos no tienen precio en su clase</strong>
          <p class="suave" style="margin:5px 0 0;font-size:13.5px">
            Sin colegiatura no se puede registrar cuánto pagaron. Corre primero
            <strong>Revisar precios importados</strong> en Pendientes y vuelve aquí.
          </p>
        </div>` : ''}

      <button class="pildora pildora-azul" id="b-importar-pagos">Registrar ${r.nuevo} pagos</button>

      <div style="margin-top:20px">
        ${r.no_encontrado ? `<details style="margin-top:12px"><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          No se encontraron en el sistema (${r.no_encontrado})</summary>${tabla(grupo('no_encontrado'), 'Por qué')}</details>` : ''}
        ${r.ambiguo ? `<details style="margin-top:12px"><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          Ambiguos (${r.ambiguo})</summary>${tabla(grupo('ambiguo'), 'Podría ser')}</details>` : ''}
        <details style="margin-top:12px"><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          Se van a registrar (${r.nuevo})</summary>${tabla(grupo('nuevo'), 'Monto')}</details>
      </div>
    </div>`;

  $('#b-importar-pagos').addEventListener('click', async () => {
    if (!confirm(`Se van a registrar ${r.nuevo} pagos.\n\n` +
      'Quedan marcados con método "por confirmar" para que después indiques si fue efectivo o transferencia. ' +
      'Si vuelves a subir el archivo, no se duplican. ¿Continuar?')) return;
    const boton = $('#b-importar-pagos');
    boton.disabled = true; boton.textContent = 'Registrando…';
    try {
      const cuerpo = new FormData();
      cuerpo.append('archivo', archivo);
      cuerpo.append('tipo', 'pagos');
      const respuesta = await fetch(`${API}/importar.php?accion=importar`, {
        method: 'POST', body: cuerpo, credentials: 'include',
        headers: tokenCsrf ? { 'X-CSRF-Token': tokenCsrf } : {}
      });
      const datos = await respuesta.json();
      if (!datos.ok) throw new Error(datos.error);
      const d = datos.datos;
      aviso(`${d.creados} pagos registrados por ${dinero(d.total)}. ${d.saltados} se saltaron.`, 'exito');
      navegar('#pagos');
    } catch (e) {
      aviso(e.message, 'error');
      boton.disabled = false; boton.textContent = 'Reintentar';
    }
  });
}

/* ---------------------------------------------------- Vista previa: horarios */

function pintarPreviaHorarios(previa, archivo) {
  const r = previa.resumen;
  const porEstado = (e) => previa.clases.filter(c => c.estado === e);

  const tabla = (clases, extra = '') => `
    <div class="tabla-scroll" style="max-height:290px;overflow-y:auto">
      <table>
        <thead><tr><th>Clase</th><th>Día y hora</th><th>Salón</th><th>Duración</th>${extra ? `<th>${extra}</th>` : ''}</tr></thead>
        <tbody>${clases.map(c => `
          <tr>
            <td>${esc(c.texto)}</td>
            <td class="suave">${esc(c.dia)} ${hora(c.hora_inicio)}–${hora(c.hora_fin)}</td>
            <td class="suave">${esc(c.salon_texto)}</td>
            <td class="suave">${textoDuracion(c.duracion_min)}</td>
            ${extra ? `<td class="suave" style="font-size:13px">${
              c.estado === 'ambigua'
                ? esc(c.candidatos.map(k => k.alumno).join(' · '))
                : c.estado === 'nueva'
                  ? `se creará · ${dinero(c.costo_sugerido)}`
                  : esc(c.motivo || '')}</td>` : ''}
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`;

  $('#resultado-previa').innerHTML = `
    <div class="cuerpo" style="border-top:1px solid var(--linea)">
      <div class="rejilla k4" style="margin-bottom:20px">
        <div><div class="cifra verde" style="font-size:28px;font-weight:600">${r.asignar}</div>
             <div class="suave" style="font-size:13px">Se acomodan solas</div></div>
        <div><div class="cifra" style="font-size:28px;font-weight:600">${r.nueva}</div>
             <div class="suave" style="font-size:13px">No existen todavía</div></div>
        <div><div class="cifra ambar" style="font-size:28px;font-weight:600">${r.ambigua}</div>
             <div class="suave" style="font-size:13px">Ambiguas</div></div>
        <div><div class="cifra ${r.problema ? 'rojo' : 'suave'}" style="font-size:28px;font-weight:600">${r.problema}</div>
             <div class="suave" style="font-size:13px">Con problema</div></div>
      </div>

      ${r.salones_nuevos.length ? `
        <p class="suave" style="font-size:13.5px;margin:0 0 16px">
          La hoja usa espacios que el sistema no conoce: <strong>${esc(r.salones_nuevos.join(', '))}</strong>.
          Se darán de alta como espacios exteriores.
        </p>` : ''}

      ${r.nueva ? `
        <label style="display:flex;gap:9px;align-items:flex-start;border:1px solid var(--linea);
                      border-radius:12px;padding:13px 16px;margin-bottom:16px;font-size:13.5px">
          <input type="checkbox" id="crear-faltantes" checked style="margin-top:3px">
          <span>
            Crear las ${r.nueva} clases que no existen, dando de alta al alumno y al maestro si hacen falta.
            <span class="suave">Se les pone el precio de música según la duración (${dinero(r.precio_hora_musica)} la hora)
            y la materia queda por asignar. Revísalos después en Alumnos.</span>
          </span>
        </label>` : ''}

      <button class="pildora pildora-azul" id="b-importar-horarios">
        Importar ${r.asignar + (r.nueva || 0)} clases
      </button>
      <p class="suave" style="font-size:13px;margin:10px 0 0">
        Mientras importa no se pueden guardar horarios: si alguien intenta asignar uno, tendrá que esperar a que termine.
      </p>

      <div style="margin-top:20px">
        <details open><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          Se acomodan solas (${r.asignar})</summary>${tabla(porEstado('asignar'))}</details>
        ${r.nueva ? `<details style="margin-top:12px"><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          No existen todavía (${r.nueva})</summary>${tabla(porEstado('nueva'), 'Qué pasará')}</details>` : ''}
        ${r.ambigua ? `<details style="margin-top:12px"><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          Ambiguas (${r.ambigua}) · las tendrás que asignar a mano</summary>${tabla(porEstado('ambigua'), 'Podría ser')}</details>` : ''}
        ${r.problema ? `<details style="margin-top:12px"><summary style="cursor:pointer;font-size:14px;margin-bottom:10px">
          Con problema (${r.problema})</summary>${tabla(porEstado('problema'), 'Motivo')}</details>` : ''}
      </div>
    </div>`;

  $('#b-importar-horarios').addEventListener('click', async () => {
    const crear = $('#crear-faltantes')?.checked;
    if (!confirm(`Se van a acomodar ${r.asignar} clases${crear ? ` y crear ${r.nueva} nuevas` : ''}.\n\n` +
      'Las ambiguas y las que tienen problema se omiten y te las listo al terminar.\n' +
      'Mientras importa no se pueden guardar horarios. ¿Continuar?')) return;

    const boton = $('#b-importar-horarios');
    boton.disabled = true;
    boton.textContent = 'Importando…';
    try {
      const cuerpo = new FormData();
      cuerpo.append('archivo', archivo);
      cuerpo.append('tipo', 'horarios');
      if (crear) cuerpo.append('crear_faltantes', '1');
      const respuesta = await fetch(`${API}/importar.php?accion=importar`, {
        method: 'POST', body: cuerpo, credentials: 'include',
        headers: tokenCsrf ? { 'X-CSRF-Token': tokenCsrf } : {}
      });
      const datos = await respuesta.json();
      if (!datos.ok) throw new Error(datos.error);
      const d = datos.datos;
      aviso(`${d.asignadas} clases acomodadas y ${d.creadas} creadas. ${d.omitidas.length} quedaron fuera.`, 'exito');
      pintarOmitidas(d);
    } catch (e) {
      aviso(e.message, 'error');
      boton.disabled = false;
      boton.textContent = 'Reintentar importación';
    }
  });
}

function pintarOmitidas(d) {
  $('#resultado-previa').innerHTML = `
    <div class="cuerpo" style="border-top:1px solid var(--linea)">
      <div class="rejilla k4" style="margin-bottom:20px">
        <div><div class="cifra verde" style="font-size:28px;font-weight:600">${d.asignadas}</div>
             <div class="suave" style="font-size:13px">Clases acomodadas</div></div>
        <div><div class="cifra" style="font-size:28px;font-weight:600">${d.creadas}</div>
             <div class="suave" style="font-size:13px">Clases creadas</div></div>
        <div><div class="cifra" style="font-size:28px;font-weight:600">${d.alumnos_nuevos}</div>
             <div class="suave" style="font-size:13px">Alumnos nuevos</div></div>
        <div><div class="cifra ${d.omitidas.length ? 'ambar' : 'suave'}" style="font-size:28px;font-weight:600">${d.omitidas.length}</div>
             <div class="suave" style="font-size:13px">Quedaron fuera</div></div>
      </div>
      ${d.omitidas.length ? `
        <p style="font-size:14px;margin:0 0 4px">
          <strong>${d.omitidas.length} ${d.omitidas.length === 1 ? 'quedó fuera' : 'quedaron fuera'}:</strong>
          revisa el motivo de cada una.
        </p>
        <p class="suave" style="font-size:13px;margin:0 0 10px">
          Se resuelven a mano desde Inscripciones o Horarios; la fila de Excel te dice dónde buscarla en la hoja.
        </p>
        <div class="tabla-scroll" style="max-height:320px;overflow-y:auto">
          <table>
            <thead><tr><th>Fila de Excel</th><th>Alumno</th><th>Clase</th><th>Cuándo</th><th>Por qué</th></tr></thead>
            <tbody>${d.omitidas.map(o => `
              <tr>
                <td class="suave cifra">${o.fila ? esc(o.fila) : '—'}</td>
                <td>${esc(o.alumno || '—')}</td>
                <td>${esc(o.clase)}</td>
                <td class="suave">${esc(o.cuando)}</td>
                <td class="suave" style="font-size:13px;white-space:pre-line">${esc(o.motivo)}</td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>` : '<p class="verde" style="margin:0">Entró todo.</p>'}
      <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        <a class="pildora pildora-azul" href="#horarios">Ver los horarios</a>
        <a class="pildora pildora-linea" href="#alumnos">Revisar los alumnos nuevos</a>
      </div>
    </div>`;
}

/* ---------------------------------------------------- Vista previa: nómina */

function pintarPreviaNomina(previa, archivo) {

  const r = previa.resumen;
  const descuadres = r.descuadres;

  $('#resultado-previa').innerHTML = `
    <div class="cuerpo" style="border-top:1px solid var(--linea)">
      <div class="rejilla k4" style="margin-bottom:20px">
        <div><div class="cifra" style="font-size:28px;font-weight:600">${r.maestros}</div><div class="suave" style="font-size:13px">Maestros</div></div>
        <div><div class="cifra" style="font-size:28px;font-weight:600">${r.renglones}</div><div class="suave" style="font-size:13px">Renglones de alumno</div></div>
        <div><div class="cifra" style="font-size:28px;font-weight:600">${dinero(r.total_calculado)}</div><div class="suave" style="font-size:13px">Suma real</div></div>
        <div><div class="cifra ${descuadres.length ? 'ambar' : 'verde'}" style="font-size:28px;font-weight:600">${descuadres.length}</div><div class="suave" style="font-size:13px">Totales que no cuadran</div></div>
      </div>

      ${descuadres.length ? `
        <div style="border:1px solid var(--linea);border-radius:14px;padding:16px 18px;margin-bottom:20px">
          <strong class="ambar">Revisa estos totales antes de importar</strong>
          <p class="suave" style="margin:6px 0 12px;font-size:13.5px">
            El total escrito en la hoja no coincide con la suma de sus renglones. Se importa la suma real.
          </p>
          <ul class="lista-simple">
            ${descuadres.map(d => `
              <li><span>${esc(d.maestro)}</span>
                  <span class="cifra">hoja ${dinero(d.en_la_hoja)} · suma ${dinero(d.suma_real)}
                  <strong class="${Number(d.diferencia) > 0 ? 'rojo' : 'ambar'}">(${Number(d.diferencia) > 0 ? '+' : ''}${dinero(d.diferencia)})</strong></span>
              </li>`).join('')}
          </ul>
        </div>` : ''}

      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px">
        <div>
          <label class="etiqueta" for="periodo-imp">Mes al que pertenece esta nómina</label>
          <input class="campo" id="periodo-imp" value="${esc(previa.periodo_sugerido)}" style="max-width:170px">
        </div>
        <button class="pildora pildora-azul" id="b-importar">Importar ${r.renglones} renglones</button>
      </div>

      <details>
        <summary style="cursor:pointer;font-size:14px">Ver el desglose por maestro</summary>
        <div class="tabla-scroll" style="margin-top:14px">
          <table>
            <thead><tr><th>Maestro</th><th>Alumnos</th><th class="derecha">Suma</th><th class="derecha">Total en la hoja</th></tr></thead>
            <tbody>${previa.bloques.map(b => `
              <tr>
                <td>${esc(b.maestro)}</td>
                <td class="suave cifra">${b.alumnos.length}</td>
                <td class="derecha cifra">${dinero(b.total_calculado)}</td>
                <td class="derecha cifra suave">${b.total_hoja === null ? '—' : dinero(b.total_hoja)}</td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </details>
    </div>`;

  $('#b-importar').addEventListener('click', async () => {
    const periodo = $('#periodo-imp').value.trim();
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(periodo)) {
      aviso('El mes debe escribirse como AAAA-MM, por ejemplo 2026-02.', 'error');
      return;
    }
    if (!confirm(`Se van a crear o actualizar ${previa.resumen.maestros} maestros y ${previa.resumen.renglones} renglones de nómina para ${mesLargo(periodo)}. ¿Continuar?`)) return;

    const boton = $('#b-importar');
    boton.disabled = true;
    boton.textContent = 'Importando…';
    try {
      const cuerpo = new FormData();
      cuerpo.append('archivo', archivo);
      cuerpo.append('periodo', periodo);
      cuerpo.append('tipo', 'nomina');
      const respuesta = await fetch(`${API}/importar.php?accion=importar`, {
        method: 'POST', body: cuerpo, credentials: 'include',
        headers: tokenCsrf ? { 'X-CSRF-Token': tokenCsrf } : {}
      });
      const datos = await respuesta.json();
      if (!datos.ok) throw new Error(datos.error);
      const d = datos.datos;
      aviso(`Listo: ${d.maestros_nuevos} maestros nuevos, ${d.alumnos_nuevos} alumnos nuevos, ${d.renglones} renglones, ${dinero(d.total)}.`, 'exito');
      navegar(`#nomina=${periodo}`);
    } catch (e) {
      aviso(e.message, 'error');
      boton.disabled = false;
      boton.textContent = 'Reintentar importación';
    }
  });
}

/* ====================================================================
   VISTA · AJUSTES  (materias, espacios, usuarios y respaldo)
   ==================================================================== */

vistas.ajustes = async () => {
  const [materias, espacios, usuarios, cobros, apertura, red] = await Promise.all([
    api('catalogos.php?tipo=instrumentos'),
    api('catalogos.php?tipo=salones_todos'),
    esAdmin() ? api('usuarios.php') : Promise.resolve([]),
    api('catalogos.php?tipo=configuracion'),
    api('catalogos.php?tipo=horario_escuela'),
    api('catalogos.php?tipo=red').catch(() => null)
  ]);

  const DIAS_SEMANA = ['Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];
  const horarioDe = (d) => apertura.find(h => h.dia_semana === d) || {};
  setTimeout(() => $$('[data-tema-opcion]').forEach(b => b.addEventListener('click', () => aplicarTema(b.dataset.temaOpcion))), 0);

  vista.innerHTML = `
    <!-- Apariencia -->
    <div class="tarjeta">
      <header><h2>Apariencia</h2></header>
      <div class="cuerpo" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
        <div class="pestanas">
          ${[['auto', 'Automática'], ['claro', 'Clara'], ['oscuro', 'Oscura']].map(([v, t]) => `
            <button type="button" data-tema-opcion="${v}" aria-selected="${temaGuardado() === v}">${t}</button>`).join('')}
        </div>
        <span class="suave" style="font-size:13.5px">Automática sigue a la de la computadora. Se guarda en esta
          computadora; también se cambia con el botón del sol y la luna, arriba.</span>
      </div>
    </div>

    <!-- Mi cuenta -->
    <div class="tarjeta">
      <header>
        <h2>Mi cuenta</h2>
        <button class="pildora pildora-azul pildora-chica" id="b-password">Cambiar mi contraseña</button>
      </header>
      <div class="cuerpo suave" style="font-size:13.5px">
        Entraste como <strong>${esc(usuarioActual?.nombre || '')}</strong> (${esc(usuarioActual?.usuario || '')}) ·
        ${esc(NOMBRE_ROL[rolActual()] || '')}. La sesión se cierra sola tras 60 minutos sin actividad.
      </div>
    </div>

    <!-- Horario de la escuela -->
    <div class="tarjeta" data-no-recepcion>
      <header><h2>Horario de la escuela</h2></header>
      <div class="cuerpo">
        <p class="suave" style="margin-top:0;font-size:13.5px">
          El sistema no deja agendar una clase fuera de estas horas, y los renglones de la rejilla
          de horarios se generan a partir de aquí. Deja el turno de la tarde vacío si ese día solo
          hay clases por la mañana, y los dos vacíos si la escuela no abre.
        </p>
        <form id="form-horario">
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Día</th><th>Abre</th><th>Cierra</th><th>Abre (tarde)</th><th>Cierra (tarde)</th></tr></thead>
              <tbody>${DIAS_SEMANA.map(d => {
                const h = horarioDe(d);
                return `
                <tr>
                  <td>${esc(d)}</td>
                  <td><input class="campo" type="time" name="${d}_abre_1" value="${(h.abre_1 || '').slice(0,5)}" style="min-width:120px"></td>
                  <td><input class="campo" type="time" name="${d}_cierra_1" value="${(h.cierra_1 || '').slice(0,5)}" style="min-width:120px"></td>
                  <td><input class="campo" type="time" name="${d}_abre_2" value="${(h.abre_2 || '').slice(0,5)}" style="min-width:120px"></td>
                  <td><input class="campo" type="time" name="${d}_cierra_2" value="${(h.cierra_2 || '').slice(0,5)}" style="min-width:120px"></td>
                </tr>`; }).join('')}
              </tbody>
            </table>
          </div>
          <button class="pildora pildora-azul" data-solo-admin type="submit" style="margin-top:14px">Guardar horario</button>
        </form>
      </div>
    </div>

    <!-- Cobros -->
    <div class="tarjeta" data-no-recepcion>
      <header><h2>Cobros</h2></header>
      <div class="cuerpo">
        <form id="form-cobros" class="fila-campos" style="align-items:end">
          ${campoTexto('costo_inscripcion', 'Cuota única de inscripción',
            { tipo: 'number', paso: '0.01', valor: cobros.costo_inscripcion,
              ayuda: 'Es el importe que se propone al inscribir; se puede cambiar en cada alta. En 0 no se propone ninguno y se escribe al cobrar.' })}
          ${campoSelect('modo_pago_maestro', 'A los maestros se les paga',
            [{ valor:'por_alumno', texto:'Por alumno' }, { valor:'por_hora', texto:'Por hora' }],
            cobros.modo_pago_maestro)}
          ${campoTexto('tarifa_maestro', 'Tarifa que se propone al dar de alta un maestro',
            { tipo: 'number', paso: '0.01', valor: cobros.tarifa_maestro })}
          ${campoTexto('recargo_monto', 'Recargo por pago tardío',
            { tipo: 'number', paso: '0.01', valor: cobros.recargo_monto })}
          ${campoTexto('recargo_dia_limite', 'Se cobra a partir del día',
            { tipo: 'number', valor: cobros.recargo_dia_limite })}
          ${campoTexto('muestra_hora', 'Clase muestra de una hora',
            { tipo: 'number', paso: '0.01', valor: cobros.muestra_hora })}
          ${campoTexto('muestra_media_hora', 'Clase muestra de media hora',
            { tipo: 'number', paso: '0.01', valor: cobros.muestra_media_hora })}
          ${campoTexto('semanas_por_mes', 'Clases al mes para la nómina por hora',
            { tipo: 'number', valor: cobros.semanas_proximas?.semanas ?? cobros.semanas_por_mes })}
          <div id="caja-semanas" style="grid-column:1 / -1">
            <p id="nota-semanas" class="nota-campo oculto"></p>
            <label id="etiqueta-semanas-mes" class="oculto" style="display:flex;gap:9px;align-items:center;font-size:13.5px;margin-top:8px">
              <input type="checkbox" name="semanas_este_mes" id="c-semanas_este_mes">
              <span><span id="texto-semanas-mes"></span>
                <span class="suave" style="display:block;font-size:13px;margin-top:2px">Los que ya pagaron este mes conservan lo que pagaron.</span></span>
            </label>
          </div>
          ${campoSelect('cupo_online', 'Alumnos por clase en línea',
            [{ valor: 1, texto: '1 (individual)' }, { valor: 2, texto: '2 (se puede compartir)' }],
            Number(cobros.cupo_online) === 2 ? 2 : 1)}
          <div data-solo-admin><button class="pildora pildora-azul" type="submit">Guardar</button></div>
        </form>
        <p class="suave" style="font-size:13px;margin:14px 0 0">
          Hoy es ${new Date().toLocaleDateString('es-MX', { day:'numeric', month:'long', year:'numeric' })}.
          La colegiatura de cada mes se puede pagar sin recargo hasta el día
          ${cobros.recargo_dia_limite} de ese mismo mes; después, el sistema suma
          ${dinero(cobros.recargo_monto)} automáticamente al registrar el pago.
          El cálculo usa la fecha del servidor, no la de la computadora de quien cobra.
          La cuota de inscripción se cobra una sola vez, la primera vez que el alumno entra a la
          escuela; si después toma una segunda clase, ya no se le vuelve a cobrar.
          Las clases muestra nunca llevan recargo. "Clases al mes" es el número de sesiones que se
          cuentan para pagarle a un maestro que cobra por hora. Las clases en línea son individuales;
          con 2, una clase en línea se puede compartir entre dos alumnos, como una presencial.
        </p>
      </div>
    </div>

    <!-- Materias -->
    <div class="tarjeta" data-no-recepcion>
      <header>
        <h2>Materias que se imparten <span class="suave" style="font-weight:400">· ${materias.length}</span></h2>
      </header>
      <div class="cuerpo" data-solo-admin style="border-bottom:1px solid var(--linea)">
        <form id="form-materia" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <input class="campo" name="nombre" style="flex:1;min-width:200px"
                 placeholder="Nombre de la materia o instrumento" required>
          <input class="campo" name="costo_mensual" type="number" step="0.01" style="max-width:150px"
                 placeholder="Costo mensual" value="900">
          <label style="display:flex;gap:7px;align-items:center;font-size:13.5px;white-space:nowrap">
            <input type="checkbox" name="es_grupal"> Se da en grupo (taller)
          </label>
          <button class="pildora pildora-azul" type="submit">Agregar</button>
        </form>
        <p class="suave" style="font-size:13px;margin:12px 0 0">
          El costo mensual es lo que paga el alumno por una hora semanal de esa clase, y es el
          que se propone al inscribirlo. Música 900, dibujo y pintura 700.
          Marca <strong>se da en grupo (taller)</strong> en las materias donde varios alumnos están con el
          mismo maestro y en el mismo salón, cada uno a su hora, como dibujo y pintura. El límite es el
          cupo del salón.
        </p>
      </div>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Materia</th><th>Cómo se da</th><th class="derecha">Costo mensual</th><th>Maestros</th><th>Inscripciones</th><th></th></tr></thead>
          <tbody>${materias.map(m => `
            <tr>
              <td>${esc(m.nombre)}</td>
              <td>${Number(m.es_grupal)
                    ? `<span class="etiqueta-estado verde">Taller</span>`
                    : '<span class="suave">Individual</span>'}</td>
              <td class="derecha cifra">${Number(m.costo_mensual) > 0 ? dinero(m.costo_mensual) : '<span class="suave">sin precio</span>'}</td>
              <td class="suave cifra">${m.maestros}</td>
              <td class="suave cifra">${m.en_uso}</td>
              <td class="derecha" style="white-space:nowrap">
                <button class="pildora pildora-linea pildora-chica" data-solo-admin data-mat-editar="${m.id_instrumento}">Renombrar</button>
                ${Number(m.en_uso) === 0
                  ? `<button class="pildora pildora-roja pildora-chica" data-solo-admin data-mat-borrar="${m.id_instrumento}">Quitar</button>`
                  : ''}
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>

    <!-- Espacios -->
    <div class="tarjeta" data-no-recepcion>
      <header>
        <h2>Espacios de la escuela</h2>
        <button class="pildora pildora-azul pildora-chica" data-solo-admin id="b-espacio">Agregar espacio</button>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Espacio</th><th>Tipo</th><th>Capacidad</th><th>Clases</th><th>Estado</th><th></th></tr></thead>
          <tbody>${espacios.map(s => `
            <tr>
              <td>${esc(s.nombre)}</td>
              <td class="suave">${esc({ salon:'Salón', compartido:'Compartido', exterior:'Exterior' }[s.tipo] || s.tipo)}</td>
              <td class="suave cifra">${s.capacidad}</td>
              <td class="suave cifra">${s.clases}</td>
              <td>${Number(s.activo)
                    ? '<span class="etiqueta-estado verde">En uso</span>'
                    : '<span class="etiqueta-estado suave">Inactivo</span>'}</td>
              <td class="derecha" style="white-space:nowrap">
                <button class="pildora pildora-linea pildora-chica" data-solo-admin data-esp-editar="${s.id_salon}">Editar</button>
                <button class="pildora ${Number(s.activo) ? 'pildora-roja' : 'pildora-linea'} pildora-chica"
                        data-solo-admin data-esp-estado="${s.id_salon}" data-activo="${Number(s.activo)}">
                  ${Number(s.activo) ? 'Desactivar' : 'Activar'}
                </button>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>

    <!-- Usuarios -->
    <div class="tarjeta" data-solo-admin>
      <header>
        <h2>Quién entra al sistema</h2>
        <div class="barra-busqueda">
          <button class="pildora pildora-azul pildora-chica" id="b-usuario">Agregar usuario</button>
        </div>
      </header>
      <div class="tabla-scroll">
        <table>
          <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Desde</th><th>Estado</th><th></th></tr></thead>
          <tbody>${usuarios.map(u => `
            <tr>
              <td>${esc(u.usuario)}${String(u.id_usuario) === String(usuarioActual?.id_usuario) ? ' <span class="suave">(tú)</span>' : ''}</td>
              <td class="suave">${esc(u.nombre)}</td>
              <td>${esc(NOMBRE_ROL[u.rol] || u.rol)}</td>
              <td class="suave cifra">${esc((u.fecha_creacion || '').slice(0, 10))}</td>
              <td>${Number(u.activo)
                    ? '<span class="etiqueta-estado verde">Activo</span>'
                    : '<span class="etiqueta-estado suave">Bloqueado</span>'}</td>
              <td class="derecha" style="white-space:nowrap">
                <button class="pildora pildora-linea pildora-chica" data-usr-editar="${u.id_usuario}">Editar</button>
                <button class="pildora pildora-linea pildora-chica" data-usr-reset="${u.id_usuario}" data-nombre="${esc(u.nombre)}">Restablecer</button>
                ${String(u.id_usuario) === String(usuarioActual?.id_usuario) ? '' : `
                  <button class="pildora ${Number(u.activo) ? 'pildora-roja' : 'pildora-linea'} pildora-chica"
                          data-usr-estado="${u.id_usuario}" data-activo="${Number(u.activo)}">
                    ${Number(u.activo) ? 'Bloquear' : 'Desbloquear'}
                  </button>`}
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <div class="cuerpo suave" style="border-top:1px solid var(--linea);font-size:13px">
        <strong>Administración</strong>: todo. <strong>Recepción</strong>: alumnos, clases, cobros, lista de espera,
        borradores y faltas. <strong>Consulta</strong>: solo ver. Las contraseñas llevan al menos 8 caracteres,
        con letras y números. Restablecer una contraseña cierra las sesiones abiertas de esa persona.
      </div>
    </div>

    <!-- Otras computadoras de la escuela -->
    <div class="tarjeta">
      <header><h2>Usar desde otra computadora</h2></header>
      <div class="cuerpo">
        <p class="suave" style="margin-top:0">
          El sistema vive en esta computadora. Las demás de la escuela pueden entrar desde su
          navegador, sin instalar nada, mientras esta esté encendida y conectada a la misma red.
        </p>
        ${red?.direccion ? `
          <p style="margin:0 0 6px">En la otra computadora, escribe en el navegador:</p>
          <p style="font-size:19px;font-weight:600;margin:0 0 12px;word-break:break-all">
            ${esc(red.direccion)}
          </p>
          <button class="pildora pildora-linea pildora-chica" id="b-copiar-red">Copiar dirección</button>
          <p class="suave" data-solo-admin style="font-size:13px;margin:12px 0 0">
            Si no abre, hay que permitir Apache en el Firewall de Windows de esta computadora.
            Y conviene que esta máquina tenga IP fija, porque si el módem se la cambia, la dirección cambia.
          </p>`
        : (esAdmin() ? `<p class="suave" style="margin:0">
            No pude detectar la dirección de red de esta computadora. Abre una terminal aquí,
            escribe <strong>ipconfig</strong> y usa la "Dirección IPv4" que aparezca:
            luego, en la otra computadora, entra a <strong>http://ESA-IP${esc(red?.ruta || '/escuela-musica/frontend/')}</strong>
          </p>` : `<p class="suave" style="margin:0">
            Pídele a administración la dirección para entrar desde otra computadora.
          </p>`)}
        <p class="suave" data-solo-admin style="font-size:13px;margin:12px 0 0">
          Las herramientas de mantenimiento (actualizar la base, comprobar la instalación) solo las abre
          un administrador. Crear el primer administrador se hace desde la Terminal del servidor.
        </p>
      </div>
    </div>

    <!-- Respaldo -->
    <div class="tarjeta" data-solo-admin>
      <header><h2>Respaldo de la base de datos</h2></header>
      <div class="cuerpo">
        <p class="suave" style="margin-top:0">
          Descarga un archivo .sql con toda la información de la escuela. Guárdalo fuera de la
          computadora de recepción. Para restaurarlo, impórtalo desde phpMyAdmin.
        </p>
        <a class="pildora pildora-azul" href="${API}/backup.php" download>Descargar respaldo</a>
      </div>
      <div class="cuerpo suave" style="border-top:1px solid var(--linea);font-size:13px">
        Sistema Escuela versión <strong>${VERSION}</strong>, del ${VERSION_FECHA}.
        Si acabas de reemplazar los archivos y aquí sigue apareciendo un número viejo,
        recarga con Ctrl+F5: tu navegador está usando la copia guardada.
      </div>
    </div>`;

  const recargar = () => refrescar();
  // Después de un error del servidor: se vuelve a leer el catálogo y se pinta lo que de verdad está guardado.
  const releerAjustes = async () => { try { await cargarCatalogos(); recargar(); } catch { /* sin conexión: se queda como está */ } };

  /* ---- Materias ---- */
  $('#b-copiar-red')?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(red.direccion);
      aviso('Dirección copiada.', 'exito');
    } catch { aviso('No se pudo copiar. Anótala a mano.', 'error'); }
  });

  $('#form-horario').addEventListener('submit', async (e) => {
    e.preventDefault();
    const datos = Object.fromEntries(new FormData(e.target).entries());
    const dias = DIAS_SEMANA.map(d => ({
      dia_semana: d,
      abre_1: datos[`${d}_abre_1`], cierra_1: datos[`${d}_cierra_1`],
      abre_2: datos[`${d}_abre_2`], cierra_2: datos[`${d}_cierra_2`]
    }));
    try {
      await api('catalogos.php?tipo=horario_escuela', { method: 'PUT', cuerpo: { dias } });
      aviso('Horario guardado. Los bloques de la rejilla se recalcularon.', 'exito');
      await cargarCatalogos();
      recargar();
    } catch (err) {
      // El mensaje completo (qué clases quedarían fuera del horario) y la pantalla vuelve a lo que SÍ
      // está guardado: las horas que se escribieron y no se guardaron no deben quedarse como si lo estuvieran.
      mostrarProblema('No se guardó el horario', err.message);
      await releerAjustes();
    }
  });

  // Clases al mes: un cambio rige desde el mes que entra; este mes se queda como está, salvo que se marque la casilla.
  const mesActualNombre = NOMBRE_MES[Number(periodoActual().slice(5, 7)) - 1];
  const mesSiguienteNombre = NOMBRE_MES[Number(periodoActual().slice(5, 7)) % 12];
  const semanasHoy = Number(cobros.semanas_por_mes);
  const semanasProgramadas = cobros.semanas_proximas ? Number(cobros.semanas_proximas.semanas) : null;
  const notaSemanas = () => {
    const campo = $('#form-cobros').semanas_por_mes;
    const nuevo = Number(campo.value);
    const nota = $('#nota-semanas'), caja = $('#etiqueta-semanas-mes');
    const yaProgramado = semanasProgramadas !== null && nuevo === semanasProgramadas;
    const cambia = campo.value !== '' && nuevo !== (semanasProgramadas ?? semanasHoy);
    caja.classList.toggle('oculto', !(cambia || (yaProgramado && nuevo !== semanasHoy)));
    if (cambia) {
      nota.textContent = nuevo === semanasHoy
        ? `Vuelve a ${semanasHoy}: se cancela el cambio programado desde ${mesSiguienteNombre}.`
        : `Rige desde ${mesSiguienteNombre}. ${mesActualNombre[0].toUpperCase() + mesActualNombre.slice(1)} se queda con ${semanasHoy}.`;
      $('#texto-semanas-mes').textContent = `Aplicar también a este mes (${mesActualNombre})`;
      $('#c-semanas_este_mes').checked = false;
    } else if (yaProgramado && nuevo !== semanasHoy) {
      nota.textContent = `Ya está programado: desde ${mesSiguienteNombre} serán ${nuevo}. Este mes son ${semanasHoy}.`;
      $('#texto-semanas-mes').textContent = `Aplicar también a este mes (${mesActualNombre})`;
    } else {
      nota.textContent = '';
    }
    nota.classList.toggle('oculto', !nota.textContent);
  };
  $('#form-cobros').semanas_por_mes.addEventListener('input', notaSemanas);
  notaSemanas();

  $('#form-cobros').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('catalogos.php?tipo=configuracion', {
        method: 'PUT',
        cuerpo: {
          semanas_este_mes: e.target.semanas_este_mes.checked ? 1 : 0,
          costo_inscripcion: Number(e.target.costo_inscripcion.value),
          tarifa_maestro: Number(e.target.tarifa_maestro.value),
          modo_pago_maestro: e.target.modo_pago_maestro.value,
          recargo_monto: Number(e.target.recargo_monto.value),
          recargo_dia_limite: Number(e.target.recargo_dia_limite.value),
          muestra_hora: Number(e.target.muestra_hora.value),
          muestra_media_hora: Number(e.target.muestra_media_hora.value),
          semanas_por_mes: Number(e.target.semanas_por_mes.value),
          cupo_online: Number(e.target.cupo_online.value)
        }
      });
      aviso('Reglas de cobro actualizadas.', 'exito');
      await cargarCatalogos();
      recargar();
    } catch (err) {
      if (/clases al mes/i.test(err.message)) errorEnCampo(e.target.semanas_por_mes, err.message);
      else aviso(err.message, 'error');
    }
  });

  $('#form-materia').addEventListener('submit', async (e) => {
    e.preventDefault();
    const nombre = e.target.nombre.value.trim();
    if (!nombre) return;
    try {
      await api('catalogos.php?tipo=instrumentos', {
        cuerpo: {
          nombre,
          costo_mensual: Number(e.target.costo_mensual.value) || 0,
          es_grupal: e.target.es_grupal.checked ? 1 : 0
        }
      });
      aviso('Materia agregada.', 'exito');
      recargar();
    } catch (err) { aviso(err.message, 'error'); }
  });

  $$('[data-mat-editar]').forEach(b => b.addEventListener('click', () => {
    const m = materias.find(x => String(x.id_instrumento) === b.dataset.matEditar);
    modal({
      titulo: `Editar ${m.nombre}`,
      campos: `
        <div class="fila-campos">
          ${campoTexto('nombre', 'Nombre', { valor: m.nombre, requerido: true })}
          ${campoTexto('costo_mensual', 'Costo mensual', { tipo: 'number', paso: '0.01', valor: m.costo_mensual })}
        </div>
        <label style="display:flex;gap:9px;align-items:flex-start;font-size:14px">
          <input type="checkbox" name="es_grupal" ${Number(m.es_grupal) ? 'checked' : ''} style="margin-top:3px">
          <span>Se da en grupo (taller)
            <span class="suave" style="display:block;font-size:13px;margin-top:2px">
              Taller: varios alumnos con el mismo maestro y en el mismo salón, cada uno a su hora. El límite
              es el cupo del salón. Cada uno paga lo suyo y al maestro se le paga por cada uno.</span>
            <span id="error-es-grupal" class="oculto" role="alert"
                  style="display:block;font-size:13px;margin-top:6px;color:var(--rojo, #c62828)"></span>
          </span>
        </label>
        <p class="suave" style="font-size:13px;margin:0">
          Cambiar el precio no altera las inscripciones que ya existen: solo el que se propone de aquí en adelante.
        </p>`,
      alAceptar: async (datos, cerrar) => {
        const casilla = $('#capa-modal input[name="es_grupal"]');
        const aviso_ = $('#error-es-grupal');
        aviso_?.classList.add('oculto');
        try {
          await api(`catalogos.php?tipo=instrumentos&id=${m.id_instrumento}`, {
            method: 'PUT', cuerpo: { ...datos, es_grupal: datos.es_grupal === 'on' ? 1 : 0 }
          });
        } catch (err) {
          // Si se intentó cambiar «Se da en grupo» y no se guardó, la casilla vuelve a lo que
          // está guardado y el motivo se ve junto a ella.
          if (casilla && casilla.checked !== !!Number(m.es_grupal)) {
            casilla.checked = !!Number(m.es_grupal);
            if (aviso_) { aviso_.textContent = err.message; aviso_.classList.remove('oculto'); }
          }
          throw err;
        }
        aviso('Materia actualizada.', 'exito');
        cerrar(); recargar();
      }
    });
  }));

  $$('[data-mat-borrar]').forEach(b => b.addEventListener('click', async () => {
    const m = materias.find(x => String(x.id_instrumento) === b.dataset.matBorrar);
    if (!confirm(`¿Quitar "${m.nombre}" de la lista de materias?`)) return;
    try {
      await api(`catalogos.php?tipo=instrumentos&id=${m.id_instrumento}`, { method: 'DELETE' });
      aviso('Materia eliminada.', 'exito');
      recargar();
    } catch (err) { aviso(err.message, 'error'); }
  }));

  /* ---- Espacios ---- */
  const formEspacio = (espacio) => modal({
    titulo: espacio ? 'Editar espacio' : 'Nuevo espacio',
    campos: `
      ${campoTexto('nombre', 'Nombre', { valor: espacio?.nombre, requerido: true, pista: 'Salon 7' })}
      <div class="fila-campos">
        ${campoTexto('capacidad', 'Capacidad', { tipo: 'number', valor: espacio?.capacidad ?? 2 })}
        ${campoSelect('tipo', 'Tipo', [
          { valor:'salon', texto:'Salón de clase' },
          { valor:'compartido', texto:'Compartido (como la cocina)' },
          { valor:'exterior', texto:'Exterior (como el patio)' }], espacio?.tipo)}
      </div>`,
    alAceptar: async (datos, cerrar) => {
      if (!datos.nombre.trim()) throw new Error('Escribe el nombre del espacio.');
      try {
        if (espacio) {
          await api(`catalogos.php?tipo=salones&id=${espacio.id_salon}`, { method: 'PUT', cuerpo: datos });
        } else {
          await api('catalogos.php?tipo=salones', { cuerpo: datos });
        }
      } catch (err) {
        // El mensaje completo se ve en la ventana (el modal respeta sus saltos de línea) y la lista
        // vuelve a leerse: no debe mostrar una capacidad o un estado que no se guardó.
        await releerAjustes();
        throw err;
      }
      aviso('Espacio guardado.', 'exito');
      await cargarCatalogos();
      cerrar(); recargar();
    }
  });

  $('#b-espacio').addEventListener('click', () => formEspacio(null));
  $$('[data-esp-editar]').forEach(b => b.addEventListener('click', () =>
    formEspacio(espacios.find(x => String(x.id_salon) === b.dataset.espEditar))));

  $$('[data-esp-estado]').forEach(b => b.addEventListener('click', async () => {
    try {
      await api(`catalogos.php?tipo=salones&id=${b.dataset.espEstado}`, {
        method: 'PUT', cuerpo: { activo: b.dataset.activo !== '1' }
      });
      await cargarCatalogos();
      recargar();
    } catch (err) {
      // El servidor puede explicar varias líneas (qué clases se quedarían sin salón): se muestra completo.
      mostrarProblema(b.dataset.activo === '1' ? 'No se pudo desactivar el espacio' : 'No se pudo activar el espacio', err.message);
      await releerAjustes();
    }
  }));

  /* ---- Usuarios ---- */
  $('#b-password').addEventListener('click', () => modal({
    titulo: 'Cambiar mi contraseña',
    textoAceptar: 'Cambiar',
    campos: `
      ${campoTexto('actual', 'Contraseña actual', { tipo: 'password', requerido: true })}
      ${campoTexto('nueva', 'Contraseña nueva', { tipo: 'password', requerido: true })}
      ${campoTexto('repetir', 'Repite la nueva', { tipo: 'password', requerido: true })}
      <p class="suave" style="font-size:13px;margin:0">Al menos 8 caracteres, con letras y números.</p>`,
    alAceptar: async (datos, cerrar) => {
      if (datos.nueva !== datos.repetir) throw new Error('Las dos contraseñas nuevas no coinciden.');
      const r = await api('usuarios.php?accion=password', { cuerpo: { actual: datos.actual, nueva: datos.nueva } });
      if (r?.csrf) tokenCsrf = r.csrf;
      aviso('Contraseña actualizada. Tus sesiones en otras computadoras se cerraron.', 'exito');
      cerrar();
    }
  }));

  $('#b-usuario').addEventListener('click', () => modal({
    titulo: 'Agregar usuario',
    textoAceptar: 'Crear',
    campos: `
      <div class="fila-campos">
        ${campoTexto('usuario', 'Usuario para entrar', { requerido: true, pista: 'karla' })}
        ${campoTexto('nombre', 'Nombre de la persona', { requerido: true, pista: 'María López' })}
      </div>
      <div class="fila-campos">
        ${campoSelect('rol', 'Rol', OPCIONES_ROL, 'recepcion')}
        ${campoTexto('password', 'Contraseña', { tipo: 'password', requerido: true })}
      </div>`,
    alAceptar: async (datos, cerrar) => {
      await api('usuarios.php?accion=crear', { cuerpo: datos });
      aviso('Usuario creado.', 'exito');
      cerrar(); recargar();
    }
  }));

  $$('[data-usr-editar]').forEach(b => b.addEventListener('click', () => {
    const u = usuarios.find(x => String(x.id_usuario) === b.dataset.usrEditar);
    if (!u) return;
    modal({
      titulo: `Editar a ${u.nombre}`,
      textoAceptar: 'Guardar',
      campos: `
        <div class="fila-campos">
          ${campoTexto('usuario', 'Usuario para entrar', { requerido: true, valor: u.usuario })}
          ${campoTexto('nombre', 'Nombre de la persona', { requerido: true, valor: u.nombre })}
        </div>
        ${campoSelect('rol', 'Rol', OPCIONES_ROL, u.rol)}
        <p class="suave" style="font-size:13px;margin:0">
          El cambio de rol vale desde su siguiente clic; no tiene que volver a entrar.</p>`,
      alAceptar: async (datos, cerrar) => {
        await api(`usuarios.php?accion=editar&id=${u.id_usuario}`, { method: 'PUT', cuerpo: datos });
        aviso('Usuario actualizado.', 'exito');
        cerrar(); recargar();
      }
    });
  }));

  $$('[data-usr-reset]').forEach(b => b.addEventListener('click', () => modal({
    titulo: `Restablecer la contraseña de ${b.dataset.nombre}`,
    textoAceptar: 'Restablecer',
    campos: `
      ${campoTexto('nueva', 'Contraseña nueva', { tipo: 'password', requerido: true })}
      <p class="suave" style="font-size:13px;margin:0">
        Entrégasela en persona y pídele que la cambie al entrar.
      </p>`,
    alAceptar: async (datos, cerrar) => {
      await api('usuarios.php?accion=restablecer', {
        cuerpo: { id_usuario: Number(b.dataset.usrReset), nueva: datos.nueva }
      });
      aviso('Contraseña restablecida.', 'exito');
      cerrar();
    }
  })));

  $$('[data-usr-estado]').forEach(b => b.addEventListener('click', async () => {
    try {
      await api(`usuarios.php?accion=estado&id=${b.dataset.usrEstado}`, {
        method: 'PUT', cuerpo: { activo: b.dataset.activo !== '1' }
      });
      recargar();
    } catch (err) { aviso(err.message, 'error'); }
  }));
};

// Quien tenga guardado el enlace viejo sigue llegando al respaldo.
vistas.respaldo = vistas.ajustes;

/* ====================================================================
   ARRANQUE
   ==================================================================== */

const pieVersion = document.getElementById('version-acceso');
if (pieVersion) pieVersion.textContent = `Sistema Escuela ${VERSION} · ${VERSION_FECHA}`;

(async function iniciar() {
  try {
    const estado = await api('auth.php?accion=estado');
    if (estado.autenticado) {
      usuarioActual = estado.usuario;
      tokenCsrf = estado.csrf ?? null;
      aplicarRol();
      mostrarApp();
      await cargarCatalogos();
      navegar(location.hash || '#inicio');
    } else {
      mostrarAcceso();
    }
  } catch (err) {
    // Sin sesión se ve la pantalla de entrada; si el servidor no contestó bien (apagado, error de PHP) se
    // dice ahí mismo, en lugar de dejar la pantalla de entrada muda.
    mostrarAcceso();
    mostrarErrorAcceso(err?.message);
  }
})();
