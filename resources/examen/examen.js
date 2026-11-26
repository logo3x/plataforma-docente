/* Plataforma educativa — examen autocontenido.
 * Sin respuestas dentro (5.4.5). Autoguardado anunciado (5.5). Salidas reales de ventana con duración (5.4.9).
 * Entorno de prueba de código en un Worker aislado, sin red y con límite de tiempo (sección 4).
 * Entrega cifrada: AES-256-GCM + RSA-OAEP-256 con la clave pública del periodo (5.4.6). */
(function () {
  'use strict';
  const D = JSON.parse(document.getElementById('datos-examen').textContent);
  const $ = (s, r) => (r || document).querySelector(s);
  const el = (tag, attrs, ...hijos) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (k === 'texto') n.textContent = v; else if (k.startsWith('on')) n.addEventListener(k.slice(2), v); else if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v === true ? '' : v);
    }
    hijos.flat().forEach(h => h !== null && h !== undefined && n.append(h));
    return n;
  };

  let E = null;                 // estado de la sesión
  let clave = null;             // clave de localStorage
  let salidaDesde = null;
  let cerrado = false;

  // ---------- Barajado determinista derivado del documento (5.4.3) ----------
  // Bits altos del hash: los bajos de FNV-1a casi no mezclan (el último solo depende de la paridad de los caracteres)
  function versionPara(doc) { const v = D.versiones && D.versiones.length ? D.versiones : ['A']; return v[(semilla(doc + '|' + D.sigla) >>> 16) % v.length]; }
  function semilla(txt) { let h = 2166136261; for (const c of txt) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; }
  function barajar(lista, txt) {
    let s = semilla(txt); const a = lista.slice();
    for (let i = a.length - 1; i > 0; i--) { s = (Math.imul(s, 1664525) + 1013904223) >>> 0; const j = s % (i + 1); [a[i], a[j]] = [a[j], a[i]]; }
    return a;
  }

  // ---------- Autoguardado (5.5) ----------
  function guardar() {
    if (!E || cerrado && E.cierre) { /* se guarda también al cerrar */ }
    try { localStorage.setItem(clave, JSON.stringify(E)); $('#guardado').textContent = '✓ Guardado ' + new Date().toLocaleTimeString(); }
    catch (e) { $('#guardado').textContent = '⚠ Este navegador no permite guardar: no cierre la ventana'; }
    actualizarAvance();
  }

  // ---------- Portada ----------
  function portada() {
    const p = $('#portada');
    const grupos = D.grupos.length > 1 ? el('div', {}, el('label', { for: 'grupo', texto: 'Grupo' }),
      el('select', { id: 'grupo' }, D.grupos.map(g => el('option', { value: g, texto: g })))) : null;
    const integrantes = D.grupal ? el('div', {}, el('label', { for: 'integrantes', texto: 'Documentos de los demás integrantes (separados por coma)' }),
      el('input', { type: 'text', id: 'integrantes', inputmode: 'numeric' })) : null;
    if (D.ensayo) p.append(el('div', { class: 'aviso mal', role: 'note' }, el('strong', { texto: 'EXAMEN DE ENSAYO. ' }),
      'Para el docente: verifica este equipo antes de aplicar el examen. Su entrega solo sirve en la página de ensayo de la plataforma; no la reparta a los estudiantes.'));
    p.append(
      el('h2', { texto: D.titulo }),
      el('p', { texto: D.materia + ' · ' + D.periodo + ' · Valor ' + D.valor }),
      D.instrucciones ? el('p', { texto: D.instrucciones }) : null,
      el('div', { class: 'aviso' },
        el('strong', { texto: 'Sus respuestas se guardan solas en este equipo. ' }),
        'Si la ventana se cierra por accidente, vuelva a abrir este mismo archivo, en este mismo navegador, y escriba el mismo documento: todo se recupera. No funciona en modo incógnito.'),
      el('div', { class: 'aviso' }, 'Solo cuenta como salida dejar esta ventana (otra pestaña, otra aplicación o minimizar). Probar su código no cuenta.'),
      D.reloj_minutos ? el('p', { texto: 'Tiempo disponible: ' + D.reloj_minutos + ' minutos. Al terminar el tiempo, el examen se cierra y se genera la entrega con lo respondido.' }) : null,
      el('label', { for: 'documento', texto: 'Número de documento' }), el('input', { type: 'text', id: 'documento', inputmode: 'numeric', autocomplete: 'off' }),
      el('label', { for: 'nombre', texto: 'Apellidos y nombres' }), el('input', { type: 'text', id: 'nombre', autocomplete: 'off' }),
      grupos, integrantes,
      el('p', {}, el('button', { class: 'boton', id: 'iniciar', texto: 'Iniciar', onclick: iniciar })),
      el('p', { id: 'error-portada', class: 'aviso mal oculto' })
    );
  }

  function iniciar() {
    const doc = $('#documento').value.replace(/\D/g, '');
    const nombre = $('#nombre').value.trim();
    if (doc.length < 5 || nombre.length < 5) { const e = $('#error-portada'); e.textContent = 'Escriba su documento y su nombre completo.'; e.classList.remove('oculto'); return; }
    clave = (D.ensayo ? 'plataforma-ensayo:' : 'plataforma:') + D.sigla + ':' + doc;   // el ensayo nunca pisa el guardado de un estudiante
    const previo = localStorage.getItem(clave);
    if (previo) { E = JSON.parse(previo); E.sesion.restauraciones = (E.sesion.restauraciones || 0) + 1; }
    else {
      E = { documento: doc, nombre, grupo: $('#grupo') ? $('#grupo').value : (D.grupos[0] || null),
        integrantes: D.grupal ? ($('#integrantes').value.split(/[^0-9]+/).filter(x => x.length >= 5 && x !== doc)) : [],
        inicio: new Date().toISOString(), respuestas: {}, cierre: null,
        sesion: { salidas: [], pegados: 0, ejecuciones: 0, errores_sintaxis: 0, reconexiones: [], restauraciones: 0, navegador: navigator.userAgent } };
    }
    // 5.4.2: la versión sale del documento (siempre la misma al reabrir) y solo se muestran sus ítems
    E.version = E.version || versionPara(E.documento);
    D.items = D.items.filter(it => (it.version || 'A') === E.version);
    $('#portada').classList.add('oculto');
    $('#examen').classList.remove('oculto');
    $('#quien').textContent = E.nombre + ' · ' + E.documento + ((D.versiones || []).length > 1 ? ' · versión ' + E.version : '');
    renderItems(); guardar(); iniciarReloj(); vigilarFoco(); vigilarConexion();
    if (D.ensayo) autoverificar();
    if (E.cierre) mostrarCierre();
  }

  // ---------- Ítems ----------
  function renderItems() {
    const cont = $('#items'); const nav = $('#nav'); cont.innerHTML = ''; nav.innerHTML = '';
    D.items.forEach((it, i) => {
      const id = String(it.id); const r = E.respuestas;
      const cab = el('h2', {}, (i + 1) + '. ', el('span', { texto: it.texto }), ' ', el('span', { class: 'peso', texto: '(' + it.peso + ')' }));
      const caja = el('section', { class: 'tarjeta item', id: 'item-' + id }, cab);
      const fijar = (v) => { r[id] = v; guardar(); };

      if (it.tipo === 'unica') {
        Object.entries(it.opciones).forEach(([k, t]) => caja.append(el('label', {}, el('input', { type: 'radio', name: 'i' + id, value: k, checked: r[id] === k, onchange: () => fijar(k) }), ' ' + k + ') ' + t)));
      } else if (it.tipo === 'vf') {
        [['true', 'Verdadero'], ['false', 'Falso']].forEach(([v, t]) => caja.append(el('label', {}, el('input', { type: 'radio', name: 'i' + id, checked: r[id] === (v === 'true'), onchange: () => fijar(v === 'true') }), ' ' + t)));
      } else if (it.tipo === 'multiple') {
        caja.append(el('p', { class: 'peso', texto: 'Marque todas las correctas.' }));
        Object.entries(it.opciones).forEach(([k, t]) => caja.append(el('label', {}, el('input', { type: 'checkbox', value: k, checked: (r[id] || []).includes(k),
          onchange: () => fijar([...caja.querySelectorAll('input:checked')].map(x => x.value)) }), ' ' + k + ') ' + t)));
      } else if (it.tipo === 'emparejar') {
        const der = Object.entries(it.derecha);
        const tabla = el('table', {}, Object.entries(it.izquierda).map(([k, t]) => el('tr', {}, el('td', { texto: k + '. ' + t }), el('td', {},
          el('select', { 'aria-label': 'Pareja de ' + t, onchange: (ev) => { const v = Object.assign({}, r[id] || {}); v[k] = ev.target.value || null; fijar(v); } },
            el('option', { value: '', texto: '—' }), der.map(([dk, dt]) => el('option', { value: dk, selected: (r[id] || {})[k] === dk, texto: dk + ') ' + dt })))))));
        caja.append(tabla);
      } else if (it.tipo === 'ordenar') {
        const pasos = barajar(Object.entries(it.pasos), E.documento + id);
        caja.append(el('p', { class: 'peso', texto: 'Asigne a cada paso su posición (1 = primero).' }));
        const actual = () => { const v = []; caja.querySelectorAll('select').forEach(s => { if (s.value) v[Number(s.value) - 1] = s.dataset.paso; }); return v; };
        pasos.forEach(([k, t]) => caja.append(el('label', {}, el('select', { 'data-paso': k, 'aria-label': 'Posición de ' + t, onchange: () => {
          const pos = [...caja.querySelectorAll('select')].map(s => s.value).filter(Boolean);
          caja.querySelector('.repetidas').classList.toggle('oculto', new Set(pos).size === pos.length);
          fijar(Array.from({ length: pasos.length }, (_, i) => actual()[i] || null));
        } }, el('option', { value: '', texto: '—' }), pasos.map((_, n) => el('option', { value: n + 1, selected: (r[id] || [])[n] === k, texto: n + 1 }))), ' ' + t)));
        caja.append(el('p', { class: 'aviso mal oculto repetidas', texto: 'Hay posiciones repetidas.' }));
      } else if (it.tipo === 'completar') {
        const pre = el('pre', { class: 'plantilla' }); const partes = it.plantilla.split('___');
        partes.forEach((t, n) => { pre.append(t); if (n < partes.length - 1) pre.append(el('input', { type: 'text', 'aria-label': 'Hueco ' + (n + 1), value: (r[id] || [])[n] || '',
          onchange: () => fijar([...pre.querySelectorAll('input')].map(x => x.value)), onpaste: () => { E.sesion.pegados++; } })); });
        caja.append(pre);
      } else if (it.tipo === 'abierta') {
        caja.append(el('textarea', { rows: it.lineas || 6, 'aria-label': 'Respuesta', style: 'font-family:inherit', onchange: (ev) => fijar(ev.target.value),
          onpaste: () => { E.sesion.pegados++; guardar(); } }, (r[id] || '')));
      } else if (it.tipo === 'reto') {
        renderReto(caja, it, id);
      }
      cont.append(caja);
      nav.append(el('a', { href: '#item-' + id, id: 'nav-' + id, texto: String(i + 1) }));
    });
  }

  // ---------- Entorno de prueba de código (sección 4) ----------
  function renderReto(caja, it, id) {
    const area = el('textarea', { rows: 14, spellcheck: 'false', 'aria-label': 'Código', onpaste: () => { E.sesion.pegados++; guardar(); } });
    area.value = E.respuestas[id] !== undefined ? E.respuestas[id] : (it.plantilla_codigo || '');
    area.addEventListener('keydown', ev => { if (ev.key === 'Tab') { ev.preventDefault(); const s = area.selectionStart; area.setRangeText('  ', s, area.selectionEnd, 'end'); } });
    area.addEventListener('input', () => { E.respuestas[id] = area.value; clearTimeout(area._t); area._t = setTimeout(guardar, 400); });
    const salida = el('div', { class: 'resultado-reto' });
    const probar = el('button', { class: 'boton boton-sec', texto: '▶ Probar', onclick: async () => {
      E.sesion.ejecuciones++; salida.innerHTML = '';
      const casos = it.casos_visibles.length ? it.casos_visibles : [{ entrada: '', descripcion: 'Ejecutar el código' }];
      for (const c of casos) {
        const r = await ejecutar(area.value, c.entrada || '', D.tiempo_ms);
        if (r.estado === 'no_compila') E.sesion.errores_sintaxis++;
        salida.append(el('div', { class: 'tarjeta' },
          el('div', {}, el('span', { class: 'estado ' + ({ con_salida: 'e-ok', sin_salida: 'e-pend' }[r.estado] || 'e-mal'), texto: ETIQUETAS[r.estado] }),
            c.descripcion ? ' ' + c.descripcion : '', c.entrada ? el('code', { texto: '  ' + c.entrada }) : null),
          r.mensaje ? el('div', { class: 'aviso mal', texto: r.mensaje }) : null,
          r.retorno !== undefined ? el('div', {}, 'Valor retornado: ', el('code', { texto: r.retorno })) : null,
          c.esperada !== undefined ? el('div', {}, 'Salida esperada (enunciado): ', el('code', { texto: String(c.esperada) })) : null,
          el('div', { class: 'consola' }, el('div', { class: 'sis', texto: '— consola —' }), r.consola.map(l => el('div', { class: l.nivel, texto: l.texto })))));
      }
      guardar();
    } });
    caja.append(area, el('p', {}, probar, ' ', it.reservadas ? el('span', { class: 'peso', texto: 'Hay ' + it.reservadas + ' verificación(es) adicional(es) que se evaluarán al calificar.' }) : null), salida);
  }

  const ETIQUETAS = { no_compila: '✗ No compila', error: '✗ Ejecuta con error', sin_salida: '… Ejecuta sin salida', con_salida: '✓ Ejecuta con salida', tiempo: '⏱ Excede el tiempo' };

  /* Código del worker: sin acceso al examen ni a la red. */
  const CODIGO_WORKER = `
    self.fetch = undefined; self.XMLHttpRequest = undefined; self.WebSocket = undefined; self.importScripts = undefined; self.EventSource = undefined;
    const lineas = []; const MAX = 500;
    const ser = (v, vistos = new WeakSet()) => {
      if (v === undefined) return 'undefined'; if (v === null) return 'null';
      if (typeof v === 'function') return '[función ' + (v.name || 'anónima') + ']';
      if (typeof v === 'string') return v;
      if (typeof v === 'object') { if (vistos.has(v)) return '[referencia circular]'; vistos.add(v);
        try { return JSON.stringify(v, (k, x) => typeof x === 'object' && x !== null ? (k && vistos.has(x) ? '[referencia circular]' : x) : (x === undefined ? 'undefined' : typeof x === 'function' ? '[función]' : x), 2); } catch (e) { return String(v); } }
      return String(v);
    };
    const registrar = (nivel) => (...a) => { if (lineas.length < MAX) lineas.push({ nivel, texto: a.map(x => ser(x)).join(' ') }); else if (lineas.length === MAX) lineas.push({ nivel: 'warn', texto: '… salida truncada' }); };
    console.log = registrar('info'); console.info = registrar('info'); console.warn = registrar('warn'); console.error = registrar('error');
    console.table = (d) => registrar('info')(d); console.clear = () => { lineas.length = 0; };
    self.onmessage = (ev) => {
      const { codigo, entrada } = ev.data;
      try { new Function(codigo); } catch (e) { self.postMessage({ estado: 'no_compila', mensaje: e.message, consola: lineas }); return; }
      try {
        const fn = new Function(codigo + '\\n;return (' + (entrada || 'undefined') + ');');
        const r = fn();
        self.postMessage({ estado: lineas.length || r !== undefined ? 'con_salida' : 'sin_salida', retorno: r === undefined ? undefined : ser(r), consola: lineas });
      } catch (e) { self.postMessage({ estado: 'error', mensaje: (e && e.name ? e.name + ': ' : '') + (e && e.message || e), consola: lineas }); }
    };`;
  const URL_WORKER = URL.createObjectURL(new Blob([CODIGO_WORKER], { type: 'text/javascript' }));

  function ejecutar(codigo, entrada, limite) {
    return new Promise(res => {
      const w = new Worker(URL_WORKER);
      const t = setTimeout(() => { w.terminate(); res({ estado: 'tiempo', mensaje: 'Se detuvo a los ' + (limite / 1000) + ' s: ¿un ciclo que no termina?', consola: [] }); }, limite);
      w.onmessage = (ev) => { clearTimeout(t); w.terminate(); res(traducir(ev.data)); };
      w.onerror = (ev) => { clearTimeout(t); w.terminate(); res(traducir({ estado: 'error', mensaje: ev.message, consola: [] })); ev.preventDefault(); };
      w.postMessage({ codigo, entrada });
    });
  }

  /* Anexo A: mensajes del motor traducidos, con el original debajo (4.4.2). */
  const CATALOGO = [
    [/Unexpected end of input/i, 'El código terminó con algo sin cerrar. Cuente llaves y paréntesis.'],
    [/Unexpected token/i, 'Hay un símbolo donde no se esperaba. Revise paréntesis, llaves o comas de esa zona.'],
    [/(\w+) is not defined/i, 'Se usa «$1», que no existe todavía. Revise la ortografía y que esté declarado antes.'],
    [/(\S+) is not a function/i, '«$1» no es una función. Revise el nombre del método y el tipo del valor.'],
    [/Cannot read propert(?:y|ies) of (undefined|null)/i, 'Se pide un dato de algo que es $1. Revise qué valor tiene esa variable.'],
    [/Assignment to constant variable/i, 'Se reasigna una constante. Use let si el valor debe cambiar.'],
    [/Missing initializer in const/i, 'Una constante se declaró sin valor. Las constantes se inicializan al declararse.'],
  ];
  function traducir(r) {
    if (r.mensaje) for (const [re, t] of CATALOGO) { const m = r.mensaje.match(re); if (m) { r.mensaje = t.replace('$1', m[1] || '') + '  (original: ' + r.mensaje + ')'; break; } }
    return r;
  }

  // ---------- Avance (5.5.5) ----------
  function respondida(it) {
    const v = E.respuestas[String(it.id)];
    if (v === undefined || v === null || v === '') return false;
    if (Array.isArray(v)) return v.some(x => x !== null && x !== '');
    if (typeof v === 'object') return Object.values(v).some(Boolean);
    if (it.tipo === 'reto') return v.trim() !== '' && v !== (it.plantilla_codigo || '');
    return true;
  }
  function actualizarAvance() {
    if (!E) return;
    let n = 0;
    D.items.forEach(it => { const ok = respondida(it); n += ok ? 1 : 0; const a = $('#nav-' + it.id); if (a) { a.classList.toggle('resp', ok); a.title = ok ? 'Respondida' : 'Sin responder'; } });
    $('#avance').textContent = n + ' de ' + D.items.length + ' respondidas';
    $('#barra').style.width = (100 * n / D.items.length) + '%';
  }

  // ---------- Reloj (5.4.8) ----------
  function iniciarReloj() {
    const r = $('#reloj'); const t0 = new Date(E.inicio).getTime(); const avisos = new Set();
    const tic = () => {
      if (E.cierre) return;
      const s = Math.floor((Date.now() - t0) / 1000);
      if (!D.reloj_minutos) { r.textContent = '⏱ ' + fmt(s); return; }
      const resta = D.reloj_minutos * 60 - s;
      r.textContent = '⏱ ' + fmt(Math.max(resta, 0));
      [10, 5].forEach(m => { if (resta <= m * 60 && !avisos.has(m)) { avisos.add(m); alert('Quedan ' + m + ' minutos.'); } });
      if (resta <= 0) finalizar('automatico');
    };
    tic(); setInterval(tic, 1000);
  }
  const fmt = s => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');

  // ---------- Salidas de ventana (5.4.9) ----------
  function vigilarFoco() {
    const salir = () => { if (!E || E.cierre || salidaDesde) return; salidaDesde = Date.now(); };
    const volver = () => {
      if (!salidaDesde || !E) return;
      E.sesion.salidas.push({ inicio: new Date(salidaDesde).toISOString(), segundos: Math.round((Date.now() - salidaDesde) / 1000) });
      salidaDesde = null; guardar();
    };
    window.addEventListener('blur', () => setTimeout(() => { if (!document.hasFocus()) salir(); }, 50));
    window.addEventListener('focus', volver);
    document.addEventListener('visibilitychange', () => document.hidden ? salir() : volver());
  }

  // ---------- Modo desconectado (5.7) ----------
  function vigilarConexion() {
    if (!D.modo_desconectado) return;
    const capa = $('#bloqueo'); let desde = null;
    const sondear = async () => {
      if (E.cierre) return;
      let conectado = false;
      try { await fetch(D.sonda + '?t=' + Date.now(), { mode: 'no-cors', cache: 'no-store', signal: AbortSignal.timeout(4000) }); conectado = true; } catch (e) { conectado = false; }
      if (conectado && !desde) { desde = Date.now(); capa.classList.remove('oculto'); }
      if (!conectado && desde) { E.sesion.reconexiones.push({ inicio: new Date(desde).toISOString(), segundos: Math.round((Date.now() - desde) / 1000) }); desde = null; capa.classList.add('oculto'); guardar(); }
    };
    sondear(); setInterval(sondear, (D.intervalo_sonda || 20) * 1000);
  }

  // ---------- Modo de ensayo (5.6): verificación del equipo de la sala ----------
  async function autoverificar() {
    const panel = $('#ensayo'); panel.classList.remove('oculto'); panel.innerHTML = '';
    panel.append(el('h2', { texto: 'Verificación de este equipo' }), el('p', { texto: 'Comprobando…' }));
    const r = [];
    const chequear = async (nombre, prueba) => { try { const d = await prueba(); r.push({ nombre, ok: true, detalle: d || '' }); } catch (e) { r.push({ nombre, ok: false, detalle: String(e && e.message || e) }); } };
    await chequear('Autoguardado en este navegador', async () => {
      const k = 'plataforma-ensayo:prueba'; localStorage.setItem(k, 'ok'); const v = localStorage.getItem(k); localStorage.removeItem(k);
      if (v !== 'ok') throw new Error('El navegador no conserva lo guardado (¿modo incógnito o almacenamiento bloqueado?).');
      return 'Las respuestas se guardan y se recuperan si se cierra la ventana.';
    });
    await chequear('Cifrado de la entrega', async () => {
      if (!window.crypto || !crypto.subtle) throw new Error('Este navegador no ofrece cifrado (crypto.subtle): las entregas saldrían sin cifrar.');
      await cifrar('prueba de ensayo'); return 'Cifra con la clave pública del periodo.';
    });
    const casos = [['No compila', 'function (', 'no_compila'], ['Ejecuta con error', 'throw new Error("x")', 'error'],
      ['Ejecuta sin salida', 'let a = 1;', 'sin_salida'], ['Ejecuta con salida', 'console.log("hola")', 'con_salida'], ['Excede el tiempo', 'while (true) {}', 'tiempo']];
    for (const [nombre, codigo, esperado] of casos) {
      await chequear('Entorno de código: «' + nombre + '»', async () => {
        const res = await ejecutar(codigo, '', esperado === 'tiempo' ? 1000 : (D.tiempo_ms || 2000));
        if (res.estado !== esperado) throw new Error('Reportó «' + (ETIQUETAS[res.estado] || res.estado) + '» en lugar de «' + nombre + '».');
        return 'lo reporta bien';
      });
    }
    E.sesion.ensayo = { verificaciones: r, navegador: navigator.userAgent, pantalla: screen.width + '×' + screen.height, fecha: new Date().toISOString() };
    guardar();
    const fallas = r.filter(x => !x.ok).length;
    panel.innerHTML = '';
    panel.append(el('h2', { texto: 'Verificación de este equipo' }),
      el('p', { class: fallas ? 'aviso mal' : 'aviso bien', texto: fallas ? fallas + ' verificación(es) fallaron: no aplique el examen en este equipo hasta resolverlo.' : '✓ Este equipo está listo para el examen.' }),
      el('ul', {}, r.map(x => el('li', {}, (x.ok ? '✓ ' : '✗ ') + x.nombre + (x.detalle ? ': ' + x.detalle : '')))),
      el('p', { class: 'peso', texto: 'Falta comprobar a mano: pruebe código en un reto (no debe contar salidas), salga a otra pestaña una vez (debe contar una, con su duración) y finalice para subir la entrega de ensayo.' }));
  }

  // ---------- Entrega cifrada (5.4.6, 5.4.7) ----------
  const b64 = (buf) => { let s = ''; new Uint8Array(buf).forEach(b => s += String.fromCharCode(b)); return btoa(s); };
  async function cifrar(texto) {
    const der = Uint8Array.from(atob(D.clave_publica), c => c.charCodeAt(0));
    const publica = await crypto.subtle.importKey('spki', der, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['encrypt']);
    const aes = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt']);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const datos = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aes, new TextEncoder().encode(texto));
    const crudo = await crypto.subtle.exportKey('raw', aes);
    const clave = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, publica, crudo);
    return { alg: 'RSA-OAEP-256+A256GCM', clave: b64(clave), iv: b64(iv), datos: b64(datos) };
  }

  async function finalizar(tipo) {
    if (E.cierre && tipo !== 'descargar') { mostrarCierre(); return; }
    if (tipo === 'manual') {
      const faltan = D.items.map((it, i) => respondida(it) ? null : i + 1).filter(Boolean);
      if (!confirm(faltan.length ? 'Faltan por responder: ' + faltan.join(', ') + '.\n¿Finalizar de todos modos?' : '¿Finalizar el examen? No podrá cambiar sus respuestas.')) return;
    }
    if (!E.cierre) { E.cierre = { tipo, fin: new Date().toISOString() }; guardar(); }
    const carga = { sigla: D.sigla, artefacto: D.uuid, documento: E.documento, nombre: E.nombre, grupo: E.grupo, integrantes: E.integrantes,
      inicio: E.inicio, fin: E.cierre.fin, cierre: E.cierre.tipo, respuestas: E.respuestas, sesion: E.sesion, version: E.version };
    const sobre = { formato: 'plataforma-entrega', version: 1, sigla: D.sigla, artefacto: D.uuid, documento: E.documento, generado: new Date().toISOString(), cifrado: null };
    if (D.ensayo) sobre.ensayo = true;
    try {
      if (!window.crypto || !crypto.subtle) throw new Error('sin cifrado');
      sobre.cifrado = await cifrar(JSON.stringify(carga));
    } catch (e) { sobre.datos_planos = carga; }      // respaldo marcado en el calificador como «sin cifrar»
    const blob = new Blob([JSON.stringify(sobre)], { type: 'application/json' });
    const a = el('a', { href: URL.createObjectURL(blob), download: (D.ensayo ? 'ENSAYO_' : '') + D.sigla + '_' + E.documento + '.json' });
    document.body.append(a); a.click(); a.remove();
    mostrarCierre(!!sobre.cifrado);
  }

  function mostrarCierre(cifrada) {
    cerrado = true;
    $('#examen').querySelectorAll('input, textarea, select').forEach(x => x.disabled = true);
    const c = $('#cierre'); c.classList.remove('oculto'); c.innerHTML = '';
    c.append(el('h2', { texto: 'Examen finalizado' + (E.cierre.tipo === 'automatico' ? ' por tiempo' : '') }),
      el('p', {}, 'Se descargó el archivo ', el('strong', { texto: (D.ensayo ? 'ENSAYO_' : '') + D.sigla + '_' + E.documento + '.json' }),
        D.ensayo ? '. Súbalo en la página de ensayo de la plataforma para cerrar el ciclo.' : '. Entréguelo como le indique el docente.'),
      cifrada === false ? el('p', { class: 'aviso mal', texto: 'Este navegador no permitió cifrar: la entrega se generó sin cifrar y el docente lo verá marcado.' }) : null,
      D.anexo ? el('p', { class: 'aviso', texto: 'Recuerde subir también el anexo de la parte práctica.' }) : null,
      el('button', { class: 'boton boton-sec', texto: 'Descargar de nuevo', onclick: () => finalizar('descargar') }));
    c.scrollIntoView();
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#finalizar').addEventListener('click', () => finalizar('manual'));
    portada();
  });
})();
