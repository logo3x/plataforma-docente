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
    poner(n, hijos);
    return n;
  };
  // El append nativo escribe «null» como texto: todo lo opcional pasa por aquí
  function poner(n, ...hijos) { hijos.flat(Infinity).forEach(h => h !== null && h !== undefined && n.append(h)); return n; }

  let E = null;                 // estado de la sesión
  let clave = null;             // clave de localStorage
  let salidaDesde = null;
  let cerrado = false;

  // ---------- Barajado determinista derivado del documento (5.4.3) ----------
  // Bits altos del hash: los bajos de FNV-1a casi no mezclan (el último solo depende de la paridad de los caracteres)
  function versionPara(doc) { const v = D.versiones && D.versiones.length ? D.versiones : ['A']; return v[(semilla(doc + '|' + D.sigla) >>> 16) % v.length]; }
  function semilla(txt) { let h = 2166136261; for (const c of txt) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; }
  /* 5.4.3: cada estudiante ve las opciones en otro orden (siempre el mismo para él). No se barajan las que dependen
     del orden («todas las anteriores», «ninguna de las anteriores»). Las letras son de posición; se responde con la clave. */
  function opcionesDe(it, id) {
    const e = Object.entries(it.opciones || it.derecha || {});
    return e.some(([, t]) => /anteriores|todas las opciones|ninguna de las/i.test(String(t))) ? e : barajar(e, E.documento + '|' + id + '|op');
  }
  const LETRA = (i) => 'abcdefghijklmnopqrstuvwxyz'[i] || String(i + 1);
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

  // ---------- Compañeros de grupo (6.8.1): se escogen de la lista del curso, nunca tecleando documentos ----------
  function selectorCompaneros() {
    const lista = D.lista || [];
    const maximo = D.tamano_grupo ? D.tamano_grupo - 1 : null;
    const cuenta = el('p', { class: 'peso', id: 'cuenta-companeros', 'aria-live': 'polite' });
    const opciones = el('div', { id: 'companeros', class: 'companeros', role: 'group', 'aria-label': 'Compañeros de grupo' },
      lista.map(x => el('label', { 'data-grupo': x.g || '', 'data-nombre': x.n.toLowerCase() },
        el('input', { type: 'checkbox', value: x.r, onchange: contar }), ' ' + x.n + (x.g && D.grupos.length > 1 ? ' · ' + x.g : ''))));
    function contar() {
      const n = opciones.querySelectorAll('input:checked').length;
      cuenta.textContent = (n ? n + (n === 1 ? ' compañero escogido' : ' compañeros escogidos') : 'Ningún compañero escogido: si trabajó solo, déjelo así.')
        + (maximo && n > maximo ? ' La actividad es para grupos de ' + D.tamano_grupo + ': su docente verá que son más.' : '');
    }
    const buscar = el('input', { type: 'text', id: 'buscar-companero', autocomplete: 'off', oninput: () => {
      const q = buscar.value.trim().toLowerCase();
      opciones.querySelectorAll('label').forEach(l => l.classList.toggle('oculto', !!q && !l.dataset.nombre.includes(q) && !l.querySelector('input').checked));
    } });
    contar();
    return el('div', {}, el('label', { for: 'buscar-companero', texto: 'Compañeros de grupo' + (maximo ? ' (hasta ' + maximo + ')' : '') + ': escriba parte del nombre y márquelos' }),
      buscar, opciones, cuenta);
  }

  // ---------- Portada ----------
  function portada() {
    const p = $('#portada');
    const grupos = D.grupos.length > 1 ? el('div', {}, el('label', { for: 'grupo', texto: 'Grupo' }),
      el('select', { id: 'grupo' }, D.grupos.map(g => el('option', { value: g, texto: g })))) : null;
    const integrantes = !D.grupal ? null : D.grupos_por === 'docente'
      ? el('div', { class: 'aviso' }, el('strong', { texto: 'Trabajo en grupo. ' }), 'Su docente definió los grupos: la entrega queda a nombre de todo su grupo. No tiene que escribir a sus compañeros.')
      : selectorCompaneros();
    if (D.ensayo) p.append(el('div', { class: 'aviso mal', role: 'note' }, el('strong', { texto: 'EXAMEN DE ENSAYO. ' }),
      'Para el docente: verifica este equipo antes de aplicar el examen. Su entrega solo sirve en la página de ensayo de la plataforma; no la reparta a los estudiantes.'));
    poner(p,
      el('h2', { texto: D.titulo }),
      el('p', { texto: D.materia + ' · ' + D.periodo + ' · Valor ' + D.valor }),
      D.instrucciones ? el('p', { texto: D.instrucciones }) : null,
      el('div', { class: 'aviso' },
        el('strong', { texto: 'Sus respuestas se guardan solas en este equipo. ' }),
        'Si la ventana se cierra por accidente, vuelva a abrir este mismo archivo, en este mismo navegador, y escriba el mismo documento: todo se recupera. No funciona en modo incógnito.'),
      el('div', { class: 'aviso' }, 'Solo cuenta como salida dejar esta ventana (otra pestaña, otra aplicación o minimizar). Probar su código no cuenta.'),
      // 5.4.9: lo que se registra se declara antes de empezar
      el('div', { class: 'aviso' }, el('strong', { texto: 'Qué se registra: ' }),
        'el tiempo en cada pregunta, las salidas de esta ventana, los textos pegados, las veces que ejecuta su código y sus errores de sintaxis, la versión del examen y el navegador. '
        + 'Lo ve su docente; sirve para revisar, y nada de eso baja la nota por sí solo.'),
      D.reloj_minutos ? el('p', { texto: 'Tiempo disponible: ' + D.reloj_minutos + ' minutos. Al terminar el tiempo, el examen se cierra y se genera la entrega con lo respondido.' }) : null,
      el('label', { for: 'documento', texto: 'Número de documento' }), el('input', { type: 'text', id: 'documento', inputmode: 'numeric', autocomplete: 'off' }),
      el('label', { for: 'nombre', texto: 'Apellidos y nombres' }), el('input', { type: 'text', id: 'nombre', autocomplete: 'off' }),
      grupos, integrantes,
      el('p', {}, el('button', { class: 'boton', id: 'iniciar', texto: 'Iniciar', onclick: iniciar })),
      el('p', { id: 'error-portada', class: 'aviso mal oculto' })
    );
  }

  async function iniciar() {
    const doc = $('#documento').value.replace(/\D/g, '');
    const nombre = $('#nombre').value.trim();
    if (doc.length < 5 || nombre.length < 5) { const e = $('#error-portada'); e.textContent = 'Escriba su documento y su nombre completo.'; e.classList.remove('oculto'); return; }
    // 5.7.1 y 5.7.3: en modo desconectado no se inicia con internet; la pantalla lo dice y el examen arranca solo al desconectarse
    if (D.modo_desconectado && !D.ensayo && await hayInternet()) {
      const capa = $('#bloqueo');
      capa.textContent = 'Desconéctese del internet para iniciar. El examen empieza solo cuando el equipo quede sin conexión.';
      capa.classList.remove('oculto');
      while (await hayInternet()) await new Promise(ok => setTimeout(ok, 3000));
      capa.classList.add('oculto');
    }
    clave = (D.ensayo ? 'plataforma-ensayo:' : 'plataforma:') + D.sigla + ':' + doc;   // el ensayo nunca pisa el guardado de un estudiante
    const previo = localStorage.getItem(clave);
    if (previo) { E = JSON.parse(previo); E.sesion.restauraciones = (E.sesion.restauraciones || 0) + 1; }
    else {
      E = { documento: doc, nombre, grupo: $('#grupo') ? $('#grupo').value : (D.grupos[0] || null),
        integrantes: D.grupal && D.grupos_por !== 'docente' ? [...document.querySelectorAll('#companeros input:checked')].map(x => x.value) : [],
        inicio: new Date().toISOString(), respuestas: {}, cierre: null,
        sesion: { salidas: [], pegados: 0, ejecuciones: 0, errores_sintaxis: 0, reconexiones: [], restauraciones: 0, navegador: navigator.userAgent } };
    }
    // 5.4.2: la versión sale del documento (siempre la misma al reabrir) y solo se muestran sus ítems
    E.version = E.version || versionPara(E.documento);
    if (D.vivo) {
      const r = await unirseVivo();
      if (r === false) { E = null; return; }       // la plataforma lo rechazó: el motivo queda en la portada
    }
    D.items = D.items.filter(it => (it.version || 'A') === E.version);
    $('#portada').classList.add('oculto');
    $('#examen').classList.remove('oculto');
    $('#quien').textContent = E.nombre + ' · ' + E.documento + ((D.versiones || []).length > 1 ? ' · versión ' + E.version : '');
    renderItems(); guardar(); iniciarReloj(); if (D.contar_salidas !== false) vigilarFoco(); vigilarConexion(); latirVivo(); medirItems();
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
        opcionesDe(it, id).forEach(([k, t], i) => caja.append(el('label', {}, el('input', { type: 'radio', name: 'i' + id, value: k, checked: r[id] === k, onchange: () => fijar(k) }), ' ' + LETRA(i) + ') ' + t)));
      } else if (it.tipo === 'vf') {
        [['true', 'Verdadero'], ['false', 'Falso']].forEach(([v, t]) => caja.append(el('label', {}, el('input', { type: 'radio', name: 'i' + id, checked: r[id] === (v === 'true'), onchange: () => fijar(v === 'true') }), ' ' + t)));
      } else if (it.tipo === 'multiple') {
        caja.append(el('p', { class: 'peso', texto: 'Marque todas las correctas.' }));
        opcionesDe(it, id).forEach(([k, t], i) => caja.append(el('label', {}, el('input', { type: 'checkbox', value: k, checked: (r[id] || []).includes(k),
          onchange: () => fijar([...caja.querySelectorAll('input:checked')].map(x => x.value)) }), ' ' + LETRA(i) + ') ' + t)));
      } else if (it.tipo === 'emparejar') {
        const der = opcionesDe(it, id);
        const tabla = el('table', {}, Object.entries(it.izquierda).map(([k, t]) => el('tr', {}, el('td', { texto: k + '. ' + t }), el('td', {},
          el('select', { 'aria-label': 'Pareja de ' + t, onchange: (ev) => { const v = Object.assign({}, r[id] || {}); v[k] = ev.target.value || null; fijar(v); } },
            el('option', { value: '', texto: '—' }), der.map(([dk, dt], i) => el('option', { value: dk, selected: (r[id] || {})[k] === dk, texto: LETRA(i) + ') ' + dt })))))));
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
    if (it.lenguaje === 'php') return renderRetoPHP(caja, it, id);
    const area = el('textarea', { rows: 14, spellcheck: 'false', 'aria-label': 'Código', onpaste: () => { E.sesion.pegados++; guardar(); } });
    area.value = E.respuestas[id] !== undefined ? E.respuestas[id] : (it.plantilla_codigo || '');
    area.addEventListener('keydown', ev => { if (ev.key === 'Tab') { ev.preventDefault(); const s = area.selectionStart; area.setRangeText('  ', s, area.selectionEnd, 'end'); } });
    const nivel = D.nivel_ayuda || 'completo';
    const aviso = el('div', { class: 'aviso-sintaxis', role: 'status', 'aria-live': 'polite' });
    const html = it.lenguaje === 'html', sql = it.lenguaje === 'sql';
    const lenguajesReto = html ? ['html', 'javascript'] : sql ? ['sql'] : ['javascript'];
    const revisar = () => { if (nivel === 'minimo') return; const p = conCatalogo(area, lenguajesReto, html ? window.revisarHTML(area.value, revisarSintaxis) : sql ? window.revisarSQL(area.value) : revisarSintaxis(area.value)); aviso.textContent = p ? '⚠ ' + p : ''; aviso.classList.toggle('oculto', !p); };
    area.addEventListener('input', () => { E.respuestas[id] = area.value; clearTimeout(area._t); area._t = setTimeout(guardar, 400); clearTimeout(area._s); area._s = setTimeout(revisar, 500); });
    const salida = el('div', { class: 'resultado-reto' });
    const vista = html ? el('div', { class: 'contenedor-vista', 'aria-label': 'Vista previa de su página' }) : sql ? el('div', { class: 'contenedor-vista', 'aria-label': 'Tablas de la actividad' }) : null;
    const probar = el('button', { class: 'boton boton-sec', texto: '▶ Probar', onclick: async () => {
      E.sesion.ejecuciones++; salida.innerHTML = '';
      if (html) return probarHTML();
      if (sql) return probarSQL();
      // 4.4.6: en el nivel mínimo solo se ejecuta el código tal cual; los casos del enunciado quedan para los otros niveles
      const casos = nivel !== 'minimo' && it.casos_visibles.length ? it.casos_visibles : [{ entrada: '', descripcion: 'Ejecutar el código' }];
      for (const c of casos) {
        const r = await ejecutar(area.value, c.entrada || '', D.tiempo_ms);
        if (r.estado === 'no_compila') E.sesion.errores_sintaxis++;
        salida.append(el('div', { class: 'tarjeta' },
          el('div', {}, el('span', { class: 'estado ' + ({ con_salida: 'e-ok', sin_salida: 'e-pend' }[r.estado] || 'e-mal'), texto: ETIQUETAS[r.estado] }),
            c.descripcion ? ' ' + c.descripcion : '', c.entrada ? el('code', { texto: '  ' + c.entrada }) : null),
          r.mensaje && nivel !== 'minimo' ? el('div', { class: 'aviso mal', texto: r.mensaje }) : null,
          r.retorno !== undefined ? el('div', {}, 'Valor retornado: ', el('code', { texto: r.retorno })) : null,
          c.esperada !== undefined && nivel === 'completo' ? el('div', {}, 'Salida esperada (enunciado): ', el('code', { texto: String(c.esperada) })) : null,
          el('div', { class: 'consola' }, el('div', { class: 'sis', texto: '— consola —' }), r.consola.map(l => el('div', { class: l.nivel, texto: l.texto })))));
      }
      guardar();
    } });
    /* 4.6: la página del estudiante en una vista previa aislada e interactiva; los casos se evalúan cada uno
       en su propia página, para que lo que haga uno no contamine al siguiente (4.3.5). */
    const tarjeta = (r, c) => el('div', { class: 'tarjeta' },
      el('div', {}, el('span', { class: 'estado ' + ({ con_salida: 'e-ok', sin_salida: 'e-pend' }[r.estado] || 'e-mal'), texto: r.estado === 'con_salida' && !c ? '✓ La página se muestra' : ETIQUETAS[r.estado] }),
        c && c.descripcion ? ' ' + c.descripcion : '', c && c.entrada ? el('code', { texto: '  ' + c.entrada }) : null),
      r.mensaje && (nivel !== 'minimo' || r.estado === 'tiempo') ? el('div', { class: 'aviso mal', texto: r.mensaje }) : null,
      c && r.retorno !== undefined ? el('div', {}, 'Valor obtenido: ', el('code', { texto: r.retorno })) : null,
      c && c.esperada !== undefined && nivel === 'completo' ? el('div', {}, 'Valor esperado (enunciado): ', el('code', { texto: String(c.esperada) })) : null,
      el('div', { class: 'consola' }, el('div', { class: 'sis', texto: '— consola —' }), r.consola.map(l => el('div', { class: l.nivel, texto: l.texto }))));
    async function probarHTML() {
      probar.disabled = true;
      try {
        const r = traducir(await window.ejecutarHTML(area.value, '', D.tiempo_ms, vista), ['html', 'javascript']);
        if (r.estado === 'no_compila') E.sesion.errores_sintaxis++;
        salida.append(tarjeta(r, null));
        for (const c of nivel !== 'minimo' ? it.casos_visibles : []) salida.append(tarjeta(traducir(await window.ejecutarHTML(area.value, c.entrada || '', D.tiempo_ms), ['html', 'javascript']), c));
      } finally { probar.disabled = false; guardar(); }
    }
    /* 4.6 SQL: cada prueba usa una base nueva creada con el esquema del reto; se ven las tablas que devuelve
       cada instrucción, las filas afectadas y el error traducido. Nunca dice si la consulta es la correcta. */
    const tablaSQL = (r) => {
      if (!r.columnas) return el('p', { class: 'peso', texto: /^\s*(insert|update|delete|replace)\b/i.test(r.sql) ? r.afectadas + ' fila(s) afectada(s).' : 'Hecho: ' + r.sql.split('\n')[0].slice(0, 80) });
      const t = el('table', { class: 'tabla-sql' }, el('thead', {}, el('tr', {}, r.columnas.map(c => el('th', { texto: c })))),
        el('tbody', {}, r.filas.slice(0, 200).map(f => el('tr', {}, f.map(v => el('td', v === null ? { class: 'nulo', texto: 'NULL' } : { texto: String(v) }))))));
      return el('div', { class: 'resultado-sql' }, t, el('p', { class: 'peso', texto: r.filas.length + (r.truncado ? '+' : '') + ' fila(s)' + (r.filas.length > 200 ? '; se muestran 200' : '') + '.' }));
    };
    const mensajeSQL = (r) => {
      if (!r.mensaje) return null;
      if (r.estado === 'tiempo') return r.mensaje;
      const crudo = r.original || r.mensaje, ex = explicar(['sql'], crudo);
      anotar(['sql'], crudo, ex, nivel === 'completo');
      if (nivel === 'completo') return ex ? (/^Línea \d+: /.exec(crudo) || [''])[0] + ex.texto + '  (original: ' + crudo + ')' : crudo;
      return nivel === 'intermedio' ? crudo : null;
    };
    async function probarSQL() {
      probar.disabled = true;
      try {
        const casos = nivel !== 'minimo' ? [null].concat(it.casos_visibles) : [null];
        for (const c of casos) {
          const r = await window.ejecutarSQL(it.esquema, area.value, c ? c.entrada : '', D.tiempo_ms);
          if (!c && r.estado === 'no_compila') E.sesion.errores_sintaxis++;
          const m = mensajeSQL(r);
          salida.append(el('div', { class: 'tarjeta' },
            el('div', {}, el('span', { class: 'estado ' + ({ con_salida: 'e-ok', sin_salida: 'e-pend' }[r.estado] || 'e-mal'), texto: ETIQUETAS[r.estado] }),
              c ? ' ' + (c.descripcion || 'Caso') : ' Su código', c && c.entrada ? el('code', { texto: '  ' + c.entrada }) : null),
            m ? el('div', { class: 'aviso mal', texto: m }) : null,
            c ? (r.consulta ? tablaSQL(r.consulta) : null) : r.resultados.map(tablaSQL),
            c && c.esperada !== undefined && nivel === 'completo' ? el('div', {}, 'Resultado esperado (enunciado): ', el('pre', { class: 'esperado-sql', texto: String(c.esperada) })) : null));
          if (r.estado === 'no_compila' || r.estado === 'tiempo') break;
        }
      } finally { probar.disabled = false; guardar(); }
    }
    const verTablas = sql ? el('button', { class: 'boton boton-sec', texto: 'Ver tablas', onclick: async () => {
      const r = await window.tablasSQL(it.esquema);
      vista.replaceChildren(r.error ? el('div', { class: 'aviso mal', texto: r.error })
        : el('div', {}, r.tablas.map(t => el('details', { open: true }, el('summary', { texto: t.tabla + ' (' + t.columnas.join(', ') + ')' }), tablaSQL(t)))));
    } }) : null;
    if (html || sql) area.rows = 16;
    revisar();
    poner(caja, area, aviso, guiaErrores(lenguajesReto), el('p', {}, probar, ' ', verTablas, verTablas ? ' ' : null, it.reservadas ? el('span', { class: 'peso', texto: 'Hay ' + it.reservadas + ' verificación(es) adicional(es) que se evaluarán al calificar.' }) : null), vista, salida);
  }

  /* 4.6 PHP y Laravel: no hay ejecución en el navegador. El estudiante escribe cada archivo, ve en lenguaje
     natural qué se revisará y tiene la ayuda de sintaxis; nada aquí sugiere que el código "corre". */
  function renderRetoPHP(caja, it, id) {
    const archivos = it.archivos && it.archivos.length ? it.archivos : [{ nombre: 'codigo.php', plantilla: it.plantilla_codigo || '' }];
    const varios = archivos.length > 1, nivel = D.nivel_ayuda || 'completo';
    const previo = E.respuestas[id];
    const valorDe = (f, i) => varios ? (previo && typeof previo === 'object' && typeof previo[f.nombre] === 'string' ? previo[f.nombre] : f.plantilla)
      : (typeof previo === 'string' ? previo : f.plantilla);
    const areas = [];
    const fijarTodo = () => { E.respuestas[id] = varios ? Object.fromEntries(areas.map(([f, a]) => [f.nombre, a.value])) : areas[0][1].value; };
    caja.append(el('div', { class: 'aviso-estatico' },
      el('strong', { texto: 'Este reto no se ejecuta.' }), ' Al calificar se revisa el código con estos criterios, sin ejecutarlo:',
      el('ul', {}, (it.criterios_visibles || []).map(n => el('li', { texto: n })),
        it.reservadas ? el('li', { class: 'peso', texto: 'y ' + it.reservadas + ' criterio(s) más que se revisan al calificar.' }) : null),
      el('p', { class: 'peso', texto: it.alcance || 'Que el código cumpla los criterios no garantiza que el proyecto funcione: pruébelo en su equipo si la actividad lo permite.' })));
    archivos.forEach((f, i) => {
      const blade = /\.blade\.php$/.test(f.nombre);
      const area = el('textarea', { rows: varios ? 10 : 14, spellcheck: 'false', 'aria-label': 'Archivo ' + f.nombre, onpaste: () => { E.sesion.pegados++; guardar(); } });
      area.value = valorDe(f, i);
      const aviso = el('div', { class: 'aviso-sintaxis', role: 'status', 'aria-live': 'polite' });
      const revisar = () => { if (nivel === 'minimo') return; const p = conCatalogo(area, ['php'], blade ? revisarBlade(area.value) : revisarPHP(area.value)); aviso.textContent = p ? '⚠ ' + p : ''; };
      area.addEventListener('keydown', ev => { if (ev.key === 'Tab') { ev.preventDefault(); const s = area.selectionStart; area.setRangeText('    ', s, area.selectionEnd, 'end'); } });
      area.addEventListener('input', () => { fijarTodo(); clearTimeout(area._t); area._t = setTimeout(guardar, 400); clearTimeout(area._s); area._s = setTimeout(revisar, 500); });
      areas.push([f, area]);
      caja.append(el('label', { class: 'archivo-php', texto: f.nombre }), area, aviso);
      revisar();
    });
    caja.append(guiaErrores(['php']));
  }

  /* PHP: lo que está fuera de las etiquetas de PHP, los comentarios con # y los heredoc no cuentan para
     llaves y paréntesis; se reemplazan por líneas vacías para conservar la numeración. */
  function revisarPHP(c) {
    const blanco = (s) => s.replace(/[^\n]/g, ' ');
    let t = c;
    if (/<\?php|<\?=/.test(t)) t = t.replace(/(^|\?>)([\s\S]*?)(<\?php|<\?=|$)/g, (m, a, fuera, b) => a + blanco(fuera) + b);
    t = t.replace(/<<<\s*['"]?(\w+)['"]?\n[\s\S]*?\n\s*\1\b/g, (m) => blanco(m));
    t = t.replace(/(^|[^\w$'"])#(?!\[)[^\n]*/g, (m, a) => a + blanco(m.slice(a.length)));
    t = t.replace(/<\?php|<\?=|\?>/g, (m) => blanco(m));
    return revisarSintaxis(t);
  }

  /* Blade (4.4.1): directivas sin su pareja de cierre y {{ }} sin cerrar. */
  const PAREJAS_BLADE = { if: ['endif'], unless: ['endunless'], isset: ['endisset'], foreach: ['endforeach'], forelse: ['endforelse'], for: ['endfor'], while: ['endwhile'],
    switch: ['endswitch'], auth: ['endauth'], guest: ['endguest'], can: ['endcan'], cannot: ['endcannot'], error: ['enderror'], push: ['endpush'], prepend: ['endprepend'],
    once: ['endonce'], env: ['endenv'], production: ['endproduction'], component: ['endcomponent'], slot: ['endslot'], verbatim: ['endverbatim'],
    section: ['endsection', 'show', 'stop', 'append', 'overwrite'], php: ['endphp'], empty: ['endempty'], fragment: ['endfragment'] };
  function revisarBlade(c) {
    const t = c.replace(/\{\{--[\s\S]*?--\}\}/g, (m) => m.replace(/[^\n]/g, ' '));
    const lineaEn = (i) => t.slice(0, i).split('\n').length;
    for (const [a, b] of [['{{', '}}'], ['{!!', '!!}']]) {
      let i = 0;
      while ((i = t.indexOf(a, i)) >= 0) { if (t[i - 1] === '@') { i += a.length; continue; } const f = t.indexOf(b, i + a.length); const otra = t.indexOf(a, i + a.length);
        if (f < 0 || (otra >= 0 && otra < f && a === '{!!')) return 'Línea ' + lineaEn(i) + ': se abrió «' + a + '» y no se cerró con «' + b + '».'; i = f + b.length; }
    }
    const cierres = {}; Object.entries(PAREJAS_BLADE).forEach(([k, v]) => v.forEach(x => { cierres[x] = k; }));
    const pila = []; const re = /(^|[^\w@])@(\w+)/g; let m;
    while ((m = re.exec(t))) {
      const nombre = m[2], pos = m.index + m[1].length; let j = pos + 1 + nombre.length; let args = null;
      const k = /^\s*\(/.exec(t.slice(j)); if (k) { let d = 0, q = null; const ini = j + k[0].length - 1;
        for (j = ini; j < t.length; j++) { const ch = t[j]; if (q) { if (ch === '\\') j++; else if (ch === q) q = null; continue; } if (ch === "'" || ch === '"') q = ch; else if (ch === '(') d++; else if (ch === ')' && --d === 0) break; }
        if (j >= t.length) return 'Línea ' + lineaEn(pos) + ': el paréntesis de @' + nombre + ' no se cerró.'; args = t.slice(ini + 1, j); re.lastIndex = j + 1; }
      if (PAREJAS_BLADE[nombre]) {
        // Formas que no llevan cierre: @section('t', 'valor'), @php($x = 1), @empty sin paréntesis dentro de @forelse
        const coma = args !== null && /^\s*(['"]).*?\1\s*,/.test(args);
        if ((nombre === 'section' || nombre === 'push' || nombre === 'prepend') && coma) continue;
        if ((nombre === 'php' && args !== null) || (nombre === 'empty' && args === null)) continue;
        pila.push({ nombre, linea: lineaEn(pos) });
        if (nombre === 'verbatim') { const f = t.indexOf('@endverbatim', re.lastIndex); if (f < 0) break; re.lastIndex = f; }
      } else if (cierres[nombre]) {
        const abierto = pila.pop();
        if (!abierto) return 'Línea ' + lineaEn(pos) + ': sobra @' + nombre + ': no hay un @' + cierres[nombre] + ' abierto.';
        if (abierto.nombre !== cierres[nombre]) return 'Línea ' + lineaEn(pos) + ': @' + nombre + ' cierra un @' + cierres[nombre] + ', pero lo último abierto es @' + abierto.nombre + ' de la línea ' + abierto.linea + '.';
      }
    }
    const sin = pila.pop();
    return sin ? 'Línea ' + sin.linea + ': @' + sin.nombre + ' no tiene su @' + PAREJAS_BLADE[sin.nombre][0] + '.' : null;
  }

  /* 4.4.1: verificación al escribir, sin ejecutar. Paréntesis, llaves, corchetes y comillas sin cerrar,
     ignorando lo que está dentro de textos y comentarios. Señala; nunca corrige (4.4.7). */
  function revisarSintaxis(codigo) {
    const pila = []; const pareja = { ')': '(', ']': '[', '}': '{' }; let linea = 1;
    for (let i = 0; i < codigo.length; i++) {
      const c = codigo[i], sig = codigo[i + 1];
      if (c === '\n') { linea++; continue; }
      if (c === '/' && sig === '/') { while (i < codigo.length && codigo[i] !== '\n') i++; linea++; continue; }
      if (c === '/' && sig === '*') { const fin = codigo.indexOf('*/', i + 2); if (fin < 0) return 'Línea ' + linea + ': un comentario /* no se cerró con */.'; linea += (codigo.slice(i, fin).match(/\n/g) || []).length; i = fin + 1; continue; }
      if (c === '"' || c === "'" || c === '`') {
        const desde = linea; let j = i + 1;
        while (j < codigo.length && codigo[j] !== c) { if (codigo[j] === '\\') j++; else if (codigo[j] === '\n') { if (c !== '`') return 'Línea ' + desde + ': unas comillas ' + c + ' no se cerraron.'; linea++; } j++; }
        if (j >= codigo.length) return 'Línea ' + desde + ': unas comillas ' + c + ' no se cerraron.';
        i = j; continue;
      }
      if ('([{'.includes(c)) pila.push({ c, linea });
      else if (')]}'.includes(c)) {
        const abierto = pila.pop();
        if (!abierto) return 'Línea ' + linea + ': sobra un «' + c + '»: no hay nada abierto que cierre.';
        if (abierto.c !== pareja[c]) return 'Línea ' + linea + ': se cierra con «' + c + '» lo que se abrió con «' + abierto.c + '» en la línea ' + abierto.linea + '.';
      }
    }
    const sinCerrar = pila.pop();
    return sinCerrar ? 'Línea ' + sinCerrar.linea + ': se abrió «' + sinCerrar.c + '» y no se cerró.' : null;
  }

  const ETIQUETAS = { no_compila: '✗ No compila', error: '✗ Ejecuta con error', sin_salida: '… Ejecuta sin salida', con_salida: '✓ Ejecuta con salida', tiempo: '⏱ Excede el tiempo' };

  /* Código del worker: sin acceso al examen ni a la red. */
  const SIN_RED = `
    /* 4.5.3: sin red. Se borran de toda la cadena de prototipos (no basta con tapar «self.fetch»: el prototipo
       la conserva) y se fijan como indefinidas y no configurables. Sin Worker, tampoco hay un worker anidado con red. */
    for (const n of ['fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'importScripts', 'Worker', 'SharedWorker', 'WebTransport', 'Request', 'BroadcastChannel']) {
      for (let o = self; o; o = Object.getPrototypeOf(o)) { try { delete o[n]; } catch (e) {} }
      try { Object.defineProperty(self, n, { value: undefined, writable: false, configurable: false }); } catch (e) {}
    }`;
  const CODIGO_WORKER = SIN_RED + `
    const lineas = []; const MAX = 500;
    const ser = (v, vistos = new WeakSet()) => {
      if (v === undefined) return 'undefined'; if (v === null) return 'null';
      if (typeof v === 'function') return '[función ' + (v.name || 'anónima') + ']';
      if (typeof v === 'string') return v;
      if (typeof v === 'object') {
        /* 4.2.4: un ciclo a cualquier profundidad se muestra como [referencia circular]; solo cuenta lo que está en
           la rama actual (un mismo objeto repetido en dos ramas no es un ciclo). */
        const enRama = [];
        try { return JSON.stringify(v, function (k, x) {
          if (typeof x === 'object' && x !== null) {
            while (enRama.length && enRama[enRama.length - 1] !== this) enRama.pop();
            if (enRama.includes(x)) return '[referencia circular]';
            enRama.push(x); return x;
          }
          return x === undefined ? 'undefined' : typeof x === 'function' ? '[función]' : typeof x === 'bigint' ? x + 'n' : x;
        }, 2); } catch (e) { return String(v); } }
      return String(v);
    };
    const registrar = (nivel) => (...a) => { if (lineas.length < MAX) lineas.push({ nivel, texto: a.map(x => ser(x)).join(' ') }); else if (lineas.length === MAX) lineas.push({ nivel: 'warn', texto: '… salida truncada' }); };
    console.log = registrar('info'); console.info = registrar('info'); console.warn = registrar('warn'); console.error = registrar('error');
    console.table = (d) => registrar('info')(d); console.clear = () => { lineas.length = 0; };
    /* 4.2.3: línea del error en el código del estudiante (new Function agrega dos líneas de encabezado). */
    const lineaDe = (e) => { const m = /<anonymous>:(\\d+):(\\d+)/.exec(e && e.stack || ''); return m && Number(m[1]) > 2 ? { linea: Number(m[1]) - 2, columna: Number(m[2]) } : {}; };
    const texto = (e) => (e && e.name ? e.name + ': ' : '') + (e && e.message || e);
    /* Errores de lo que corre después (promesas rechazadas, setTimeout): antes se perdían al cerrar el worker. */
    let tardio = null;
    self.addEventListener('unhandledrejection', (ev) => { ev.preventDefault(); const e = ev.reason; tardio = tardio || Object.assign({ mensaje: 'Promesa rechazada sin capturar: ' + texto(e) }, lineaDe(e)); lineas.push({ nivel: 'error', texto: 'Promesa rechazada: ' + texto(e) }); });
    self.addEventListener('error', (ev) => { ev.preventDefault(); const e = ev.error || ev.message; tardio = tardio || Object.assign({ mensaje: texto(e) }, lineaDe(e)); lineas.push({ nivel: 'error', texto: texto(e) }); });
    self.onmessage = (ev) => {
      const { codigo, entrada } = ev.data;
      try { new Function(codigo); } catch (e) { self.postMessage({ estado: 'no_compila', mensaje: e.message, consola: lineas }); return; }
      let res;
      try {
        const fn = new Function(codigo + '\\n;return (' + (entrada || 'undefined') + ');');
        const r = fn();
        res = { estado: lineas.length || r !== undefined ? 'con_salida' : 'sin_salida', retorno: r === undefined ? undefined : ser(r) };
      } catch (e) { res = Object.assign({ estado: 'error', mensaje: texto(e) }, lineaDe(e)); }
      setTimeout(() => {
        if (tardio && res.estado !== 'error') res = Object.assign(res, { estado: 'error' }, tardio);
        self.postMessage(Object.assign(res, { consola: lineas }));
      }, 50);
    };`;
  const URL_WORKER = URL.createObjectURL(new Blob([CODIGO_WORKER], { type: 'text/javascript' }));

  const ANTES = (SIN_RED.match(/\n/g) || []).length + 2;
  function lineaSintaxis(codigo) {
    return new Promise(ok => {
      const w = new Worker(URL.createObjectURL(new Blob([SIN_RED + '\n(function () {\n' + codigo + '\n})'], { type: 'text/javascript' })));
      const fin = (v) => { clearTimeout(t); w.terminate(); ok(v); };
      const t = setTimeout(() => fin({}), 600);
      w.onerror = (ev) => { ev.preventDefault(); fin(ev.lineno > ANTES ? { linea: ev.lineno - ANTES, columna: ev.colno } : {}); };
    });
  }
  /* «Línea 3: …» y la línea citada, para que el estudiante vea dónde está el error. */
  function conLinea(r, codigo) {
    if (!r.linea || !['error', 'no_compila'].includes(r.estado)) return r;
    const fragmento = (codigo.split('\n')[r.linea - 1] || '').trim();
    r.mensaje = 'Línea ' + r.linea + ': ' + r.mensaje + (fragmento ? ' → «' + fragmento.slice(0, 80) + '»' : '');
    return r;
  }
  function ejecutar(codigo, entrada, limite) {
    return new Promise(res => {
      const w = new Worker(URL_WORKER);
      const t = setTimeout(() => { w.terminate(); res({ estado: 'tiempo', mensaje: 'Se detuvo a los ' + (limite / 1000) + ' s: ¿un ciclo que no termina?', consola: [] }); }, limite);
      w.onmessage = async (ev) => {
        clearTimeout(t); w.terminate();
        const r = ev.data;
        if (r.estado === 'no_compila') Object.assign(r, await lineaSintaxis(codigo));
        res(traducir(conLinea(r, codigo), ['javascript']));
      };
      w.onerror = (ev) => { clearTimeout(t); w.terminate(); res(traducir({ estado: 'error', mensaje: ev.message, consola: [] }, ['javascript'])); ev.preventDefault(); };
      w.postMessage({ codigo, entrada });
    });
  }

  /* Anexo A: mensajes del motor traducidos, con el original debajo (4.4.2). */
  /* 4.4.4 / anexo A: catálogo de errores frecuentes del docente. Explica el mensaje del motor y dice qué revisar
     (nivel completo); en el intermedio se ve el original. La sesión registra qué entradas se mostraron y qué
     mensajes no tenían explicación, para que el docente amplíe el catálogo con lo que aparece en el aula. */
  const COMODIN = '([^\\s"\'«»]+)';
  const CAT = (D.catalogo || []).map(e => {
    try { return Object.assign({}, e, { re: new RegExp(e.regex ? e.patron : e.patron.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\{x\\\}/g, COMODIN), 'iu') }); }
    catch (x) { return null; }
  }).filter(Boolean);
  function explicar(lenguajes, mensaje) {
    for (const e of CAT) {
      if (!lenguajes.includes(e.lenguaje)) continue;
      const m = e.re.exec(mensaje);
      if (m) { const x = m[1] || ''; return { id: e.id, texto: e.explicacion.split('{x}').join(x) + (e.revision ? ' Qué revisar: ' + e.revision.split('{x}').join(x) : '') }; }
    }
    return null;
  }
  function anotar(lenguajes, mensaje, ex, mostrada) {
    const s = E.sesion;
    if (ex) { if (mostrada) { s.catalogo = s.catalogo || {}; s.catalogo[ex.id] = (s.catalogo[ex.id] || 0) + 1; } return; }
    const limpio = String(mensaje).replace(/^Línea \d+:\s*/, '').slice(0, 300);
    if (!limpio || /^(El esquema|El juego de datos|El motor SQL|Se detuvo)/.test(limpio)) return;
    s.sin_catalogo = s.sin_catalogo || [];
    const previo = s.sin_catalogo.find(x => x.lenguaje === lenguajes[0] && x.mensaje === limpio);
    if (previo) previo.veces++; else if (s.sin_catalogo.length < 30) s.sin_catalogo.push({ lenguaje: lenguajes[0], mensaje: limpio, veces: 1 });
  }
  function traducir(r, lenguajes) {
    lenguajes = lenguajes || ['javascript'];
    if (!r.mensaje || !['error', 'no_compila'].includes(r.estado)) return r;
    const ex = explicar(lenguajes, r.mensaje), completo = (D.nivel_ayuda || 'completo') === 'completo';
    if (E) anotar(lenguajes, r.mensaje, ex, completo);
    if (completo && ex) r.mensaje = (/^Línea \d+: /.exec(r.mensaje) || [''])[0] + ex.texto + '  (original: ' + r.mensaje + ')';
    return r;
  }
  /* Aviso de la ayuda de sintaxis con su explicación del catálogo; se cuenta una vez por aviso distinto. */
  function conCatalogo(area, lenguajes, p) {
    if (!p || (D.nivel_ayuda || 'completo') !== 'completo') return p;
    const ex = explicar(lenguajes, p);
    if (ex && area._ultimo !== p) anotar(lenguajes, p, ex, true);
    area._ultimo = p;
    return ex ? p + ' — ' + ex.texto : p;
  }
  /* Nivel completo: la guía de errores frecuentes del lenguaje del reto, a la vista. */
  function guiaErrores(lenguajes) {
    if ((D.nivel_ayuda || 'completo') !== 'completo') return null;
    const es = CAT.filter(e => lenguajes.includes(e.lenguaje));
    if (!es.length) return null;
    return el('details', { class: 'guia-errores' }, el('summary', { texto: 'Errores frecuentes y qué revisar (' + es.length + ')' }),
      el('dl', {}, es.map(e => [el('dt', {}, el('code', { texto: e.regex ? e.patron : e.patron.split('{x}').join('…') })),
        el('dd', { texto: e.explicacion.split('{x}').join('…') + (e.revision ? ' Qué revisar: ' + e.revision.split('{x}').join('…') : '') })])));
  }

  // ---------- Avance (5.5.5) ----------
  function respondida(it) {
    const v = E.respuestas[String(it.id)];
    if (v === undefined || v === null || v === '') return false;
    if (it.tipo === 'reto' && it.lenguaje === 'php') {
      const base = Object.fromEntries((it.archivos || []).map(f => [f.nombre, f.plantilla]));
      return typeof v === 'string' ? v.trim() !== '' && v !== ((it.archivos || [])[0] || {}).plantilla : Object.entries(v).some(([n, c]) => typeof c === 'string' && c.trim() !== '' && c !== base[n]);
    }
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
  let reconectadoDesde = null;
  function iniciarReloj() {
    const r = $('#reloj'); const t0 = new Date(E.inicio).getTime(); const avisos = new Set();
    const tic = () => {
      if (E.cierre) return;
      // El tiempo en pausa por reconexión (5.7.4) no cuenta
      const pausa = (E.sesion.reconexiones || []).reduce((t, x) => t + x.segundos * 1000, 0) + (reconectadoDesde ? Date.now() - reconectadoDesde : 0);
      const s = Math.floor((Date.now() - t0 - pausa) / 1000);
      if (!D.reloj_minutos) { r.textContent = '⏱ ' + fmt(s); return; }
      const resta = D.reloj_minutos * 60 - s;
      r.textContent = '⏱ ' + fmt(Math.max(resta, 0));
      [10, 5].forEach(m => { if (resta <= m * 60 && !avisos.has(m)) { avisos.add(m); alert('Quedan ' + m + ' minutos.'); } });
      if (resta <= 0) finalizar('automatico');
    };
    tic(); setInterval(tic, 1000);
  }
  const fmt = s => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');

  // ---------- Tiempo en cada pregunta (5.4.9) ----------
  // Cuenta mientras el foco está dentro de la pregunta y la ventana está a la vista
  function medirItems() {
    E.sesion.tiempos = E.sesion.tiempos || {};
    let actual = null, desde = null;
    const cerrar = () => {
      if (actual && desde) { E.sesion.tiempos[actual] = Math.round((E.sesion.tiempos[actual] || 0) + (Date.now() - desde) / 1000); }
      desde = actual ? Date.now() : null;
    };
    document.addEventListener('focusin', (ev) => {
      const s = ev.target.closest && ev.target.closest('section.item');
      const id = s ? s.id.replace('item-', '') : null;
      if (id === actual) return;
      cerrar(); actual = id; desde = id ? Date.now() : null; guardar();
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden) { cerrar(); desde = null; guardar(); } else if (actual) desde = Date.now(); });
    setInterval(() => { if (!document.hidden && !E.cierre) cerrar(); }, 15000);
    window.addEventListener('beforeunload', cerrar);
    medirItems.cerrar = cerrar;
  }

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
  /* 5.7.5 y 5.7.6: hay internet solo si la sonda responde; un fallo (o que tarde) cuenta como sin conexión. */
  async function hayInternet() {
    try { await fetch(D.sonda + '?t=' + Date.now(), { mode: 'no-cors', cache: 'no-store', signal: AbortSignal.timeout(4000) }); return true; } catch (e) { return false; }
  }
  function vigilarConexion() {
    if (!D.modo_desconectado) return;
    const capa = $('#bloqueo');
    const sondear = async () => {
      if (E.cierre) return;
      const conectado = await hayInternet();
      // 5.7.4: con conexión el examen se pausa (el reloj también) y queda en el registro con hora y duración
      if (conectado && !reconectadoDesde) {
        reconectadoDesde = Date.now();
        capa.textContent = 'El equipo volvió a tener internet. El examen está en pausa, y el reloj también: desconéctese para continuar.';
        capa.classList.remove('oculto');
      }
      if (!conectado && reconectadoDesde) {
        E.sesion.reconexiones.push({ inicio: new Date(reconectadoDesde).toISOString(), segundos: Math.round((Date.now() - reconectadoDesde) / 1000) });
        reconectadoDesde = null; capa.classList.add('oculto'); guardar();
      }
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
    const S = '<' + '/script>';
    for (const [nombre, html, expr, esperado] of [['Vista previa de HTML y CSS', '<style>p{color:red}</style><p id="x">hola</p>', "getComputedStyle(document.getElementById('x')).color === 'rgb(255, 0, 0)'", 'con_salida'],
      ['Ciclo infinito en una página', '<' + 'script>while (true) {}' + S, '', 'tiempo']]) {
      await chequear('Retos de HTML: «' + nombre + '»', async () => {
        const res = await window.ejecutarHTML(html, expr, 1000);
        if (res.estado !== esperado || (expr && !res.ok)) throw new Error('Reportó «' + (ETIQUETAS[res.estado] || res.estado) + '»' + (expr && !res.ok ? ' y la página no se evaluó bien' : '') + '.');
        return esperado === 'tiempo' ? 'se detiene sin congelar el examen' : 'se muestra y se evalúa';
      });
    }
    if (D.items.some(x => x.tipo === 'reto' && x.lenguaje === 'sql')) {
      for (const [nombre, codigo, esperado] of [['Consulta', 'CREATE TABLE t (a); INSERT INTO t VALUES (1), (2); SELECT SUM(a) FROM t;', 'con_salida'],
        ['Consulta que no termina', 'WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c) SELECT COUNT(*) FROM c;', 'tiempo']]) {
        await chequear('Retos de SQL: «' + nombre + '»', async () => {
          const res = await window.ejecutarSQL('', codigo, '', esperado === 'tiempo' ? 1500 : 8000);
          if (res.estado !== esperado) throw new Error('Reportó «' + (ETIQUETAS[res.estado] || res.estado) + '»' + (res.mensaje ? ': ' + res.mensaje : '') + '.');
          return esperado === 'tiempo' ? 'se detiene sin congelar el examen' : 'el motor SQL funciona sin internet';
        });
      }
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
    if (medirItems.cerrar) medirItems.cerrar();
    if (tipo === 'manual') {
      const faltan = D.items.map((it, i) => respondida(it) ? null : i + 1).filter(Boolean);
      if (!confirm(faltan.length ? 'Faltan por responder: ' + faltan.join(', ') + '.\n¿Finalizar de todos modos?' : '¿Finalizar el examen? No podrá cambiar sus respuestas.')) return;
    }
    if (!E.cierre) { E.cierre = { tipo, fin: new Date().toISOString() }; guardar(); }
    const sobre = await armarSobre(E.cierre.tipo, E.cierre.fin);
    // Sesión en vivo (13.2): la entrega va sola al servidor; si no llega, se descarga el archivo como siempre
    if (D.vivo && E.vivo && tipo !== 'descargar') {
      const r = await enviarVivo('entrega', { sobre: JSON.stringify(sobre) });
      if (r.ok) { E.vivo.entregada = true; guardar(); mostrarCierre(!!sobre.cifrado); return; }
      E.vivo.error = r.mensaje || 'No se pudo enviar la entrega.';
    }
    const blob = new Blob([JSON.stringify(sobre)], { type: 'application/json' });
    const a = el('a', { href: URL.createObjectURL(blob), download: (D.ensayo ? 'ENSAYO_' : '') + D.sigla + '_' + E.documento + '.json' });
    document.body.append(a); a.click(); a.remove();
    mostrarCierre(!!sobre.cifrado);
  }

  /* El mismo sobre del archivo: lo usan la entrega, la descarga y la instantánea de la sesión en vivo. */
  async function armarSobre(tipo, fin) {
    const carga = { sigla: D.sigla, artefacto: D.uuid, documento: E.documento, nombre: E.nombre, grupo: E.grupo, integrantes: E.integrantes,
      inicio: E.inicio, fin, cierre: tipo, respuestas: E.respuestas, sesion: E.sesion, version: E.version };
    const sobre = { formato: 'plataforma-entrega', version: 1, sigla: D.sigla, artefacto: D.uuid, documento: E.documento, generado: new Date().toISOString(), cifrado: null };
    if (D.ensayo) sobre.ensayo = true;
    try {
      if (!window.crypto || !crypto.subtle) throw new Error('sin cifrado');
      sobre.cifrado = await cifrar(JSON.stringify(carga));
    } catch (e) { sobre.datos_planos = carga; }      // respaldo marcado en el calificador como «sin cifrar»
    return sobre;
  }

  // ---------- Sesión en vivo (sección 13) ----------
  async function enviarVivo(que, datos) {
    try {
      const r = await fetch(D.vivo[que], { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, cache: 'no-store',
        body: JSON.stringify(Object.assign({ clave: E.vivo ? E.vivo.clave : null }, datos)), signal: AbortSignal.timeout(que === 'entrega' ? 30000 : 10000) });
      const j = await r.json().catch(() => ({}));
      return Object.assign({ ok: r.ok, status: r.status }, j);
    } catch (e) { return { ok: false, red: true, mensaje: 'Sin conexión con la plataforma.' }; }
  }
  async function unirseVivo() {
    const boton = $('#iniciar'); boton.disabled = true;
    const r = await enviarVivo('unirse', { documento: E.documento, nombre: E.nombre, version: E.version, total: D.items.filter(it => (it.version || 'A') === E.version).length });
    boton.disabled = false;
    if (r.ok) { E.vivo = { clave: r.clave }; return true; }
    if (r.red) { E.vivo = null; avisoVivo('Sin conexión con la plataforma: siga respondiendo; al finalizar se descargará el archivo para entregarlo.'); return true; }
    const e = $('#error-portada'); e.textContent = r.message || r.mensaje || 'La plataforma no admitió el ingreso.'; e.classList.remove('oculto');
    return false;
  }
  function avisoVivo(texto) { let a = $('#aviso-vivo'); if (!a) { a = el('div', { id: 'aviso-vivo', class: 'aviso', role: 'status' }); $('#examen').prepend(a); } a.textContent = texto; }
  /* Latido: avance y salidas para el panel del docente, y una instantánea cifrada de lo respondido cuando cambia,
     para que una finalización masiva registre lo de este equipo aunque deje de responder (13.1). */
  function latirVivo() {
    if (!D.vivo || !E.vivo || E.cierre) return;
    let ultima = null;
    const latir = async () => {
      if (!E.vivo || E.cierre) return;
      const resp = JSON.stringify(E.respuestas), datos = { respondidas: D.items.filter(respondida).length, total: D.items.length,
        salidas: E.sesion.salidas.length, segundos_fuera: E.sesion.salidas.reduce((a, s) => a + s.segundos, 0) };
      // Solo cuando lo respondido cambió desde la última: a lo sumo una por latido
      if (resp !== ultima) { datos.instantanea = JSON.stringify(await armarSobre('docente', new Date().toISOString())); }
      const enviada = datos.instantanea ? resp : null;
      const r = await enviarVivo('latido', datos);
      if (r.ok && enviada !== null) ultima = enviada;      // si no llegó, se reintenta en el próximo latido
      if (r.status === 403) { E.vivo = null; avisoVivo(r.message || 'Esta ventana ya no atiende el examen. Al finalizar se descargará el archivo.'); return; }
      const a = $('#aviso-vivo'); if (r.ok && a) a.remove();
      if (r.red) avisoVivo('Sin conexión con la plataforma: sus respuestas siguen guardadas en este equipo.');
      if (r.cerrar && !E.cierre) { finalizar('docente'); }
    };
    latir(); setInterval(latir, (D.vivo.intervalo || 10) * 1000);
  }

  function mostrarCierre(cifrada) {
    cerrado = true;
    $('#examen').querySelectorAll('input, textarea, select').forEach(x => x.disabled = true);
    const c = $('#cierre'); c.classList.remove('oculto'); c.innerHTML = '';
    const enLinea = D.vivo && E.vivo && E.vivo.entregada;
    poner(c, el('h2', { texto: 'Examen finalizado' + (E.cierre.tipo === 'automatico' ? ' por tiempo' : E.cierre.tipo === 'docente' ? ' por el docente' : '') }),
      enLinea ? el('p', { class: 'aviso bien', texto: '✓ Su entrega llegó a la plataforma. No tiene que descargar ni subir nada.' }) : null,
      D.vivo && E.vivo && E.vivo.error ? el('p', { class: 'aviso mal', texto: 'No se pudo enviar a la plataforma (' + E.vivo.error + '). Entregue el archivo descargado.' }) : null,
      enLinea ? null : el('p', {}, 'Se descargó el archivo ', el('strong', { texto: (D.ensayo ? 'ENSAYO_' : '') + D.sigla + '_' + E.documento + '.json' }),
        D.ensayo ? '. Súbalo en la página de ensayo de la plataforma para cerrar el ciclo.' : '. Entréguelo como le indique el docente.'),
      cifrada === false ? el('p', { class: 'aviso mal', texto: 'Este navegador no permitió cifrar: la entrega se generó sin cifrar y el docente lo verá marcado.' }) : null,
      D.anexo ? el('p', { class: 'aviso', texto: 'Recuerde subir también el anexo de la parte práctica.' }) : null,
      el('button', { class: 'boton boton-sec', texto: enLinea ? 'Descargar una copia' : 'Descargar de nuevo', onclick: () => finalizar('descargar') }));
    c.scrollIntoView();
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#finalizar').addEventListener('click', () => finalizar('manual'));
    portada();
  });
})();
