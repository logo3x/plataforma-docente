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
    renderItems(); guardar(); iniciarReloj(); if (D.contar_salidas !== false) vigilarFoco(); vigilarConexion();
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
    caja.append(area, aviso, guiaErrores(lenguajesReto), el('p', {}, probar, ' ', verTablas, verTablas ? ' ' : null, it.reservadas ? el('span', { class: 'peso', texto: 'Hay ' + it.reservadas + ' verificación(es) adicional(es) que se evaluarán al calificar.' }) : null), vista, salida);
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
      w.onmessage = (ev) => { clearTimeout(t); w.terminate(); res(traducir(ev.data, ['javascript'])); };
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
