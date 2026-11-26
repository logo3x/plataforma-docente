/* Retos de SQL (4.6): motor SQLite embebido (sql.js, WebAssembly) dentro de un Worker.
   - Cada ejecución usa una base nueva en memoria creada con el esquema del reto: nada pasa de un caso a otro.
   - El Worker no tiene red; si una consulta no termina, se descarta el Worker entero (4.5.2).
   - Mensajes de error traducidos al español, con el original disponible (4.4.2).
   En el examen el motor viaja incrustado (bloque «motor-sql»); en la plataforma se descarga de
   data-motor. Este archivo va dentro del examen: sin las secuencias literales de etiquetas script ni de
   comentario HTML. */
(function () {
  'use strict';
  const BASE = document.currentScript && document.currentScript.dataset ? document.currentScript.dataset.motor : null;
  let fuente = null, trabajador = null, iniciado = null, cola = Promise.resolve(), sec = 0;

  const deBase64 = (b64) => { const s = atob(b64); const a = new Uint8Array(s.length); for (let i = 0; i < s.length; i++) a[i] = s.charCodeAt(i); return a; };

  function cargarFuente() {
    if (fuente) return fuente;
    const bloque = document.getElementById('motor-sql');
    if (bloque) {
      const d = JSON.parse(bloque.textContent);
      fuente = Promise.resolve({ codigo: new TextDecoder().decode(deBase64(d.codigo)), wasm: deBase64(d.wasm).buffer });
    } else if (BASE) {
      fuente = Promise.all([fetch(BASE + '/sql-wasm.js').then(r => r.text()), fetch(BASE + '/sql-wasm.wasm').then(r => r.arrayBuffer())])
        .then(([codigo, wasm]) => ({ codigo, wasm }));
    } else {
      fuente = Promise.reject(new Error('No está disponible el motor SQL.'));
    }
    return fuente;
  }

  /* Código del Worker, después del de sql.js. */
  function trabajo() {
    self.fetch = undefined; self.XMLHttpRequest = undefined; self.WebSocket = undefined; self.EventSource = undefined;
    const LIMITE_FILAS = 2000; let SQL = null;
    const valor = (v) => v instanceof Uint8Array ? '[BLOB de ' + v.length + ' bytes]' : v;
    /* Línea del error: la del símbolo que cita SQLite («near "x"») dentro de la instrucción que falló, o el inicio de esa instrucción */
    const lineaDe = (codigo, resto, mensaje) => {
      const inicio = codigo.length - (resto || '').length + ((resto || '').match(/^\s*/) || [''])[0].length;
      const cerca = /near "([^"]+)"/.exec(mensaje || '') || /no such (?:table|column|function): ([\w.]+)|ambiguous column name: ([\w.]+)/.exec(mensaje || ''); let pos = inicio;
      if (cerca && !cerca[1]) cerca[1] = cerca[2];
      if (cerca) {
        // Palabra completa: «equipo» no debe coincidir con «equipos»
        const re = new RegExp('(^|[^\\w])' + cerca[1].replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![\\w])', 'g'); re.lastIndex = inicio;
        const k = re.exec(codigo); if (k) pos = k.index + k[1].length;
      }
      return codigo.slice(0, pos).split('\n').length;
    };
    function correr(db, codigo) {
      const resultados = []; const it = db.iterateStatements(codigo); let antes = codigo;
      try {
        for (;;) {
          antes = it.getRemainingSQL();
          const paso = it.next(); if (paso.done) break; const st = paso.value;
          const columnas = st.getColumnNames(); const filas = []; let truncado = false;
          while (st.step()) { if (filas.length < LIMITE_FILAS) filas.push(st.get().map(valor)); else { truncado = true; break; } }
          const sql = st.getSQL().trim();
          st.free();
          // sqlite3_changes no se reinicia con CREATE, DROP ni ALTER: solo cuenta en INSERT, UPDATE y DELETE
          resultados.push(columnas.length ? { sql, columnas, filas, truncado } : { sql, afectadas: /^\s*(insert|update|delete|replace)\b/i.test(sql) ? db.getRowsModified() : 0 });
        }
        return { resultados };
      } catch (e) {
        return { resultados, error: String(e && e.message || e), linea: lineaDe(codigo, antes, String(e && e.message || e)) };
      }
    }
    const nueva = (esquema, datos) => {
      const db = new SQL.Database();
      for (const [que, sql] of [['esquema', esquema], ['datos', datos]]) {
        if (!sql) continue;
        const r = correr(db, sql);
        if (r.error) { db.close(); throw new Error('El ' + (que === 'esquema' ? 'esquema' : 'juego de datos') + ' de la actividad tiene un error (línea ' + r.linea + '): ' + r.error); }
      }
      return db;
    };
    const ultimaConsulta = (rs) => rs.filter(r => r.columnas).pop() || null;
    const normal = (fila) => fila.map(v => typeof v === 'number' ? Number(v.toPrecision(12)) : v);
    function comparar(a, b, orden) {
      if (!a || !b || a.columnas.length !== b.columnas.length || a.filas.length !== b.filas.length) return false;
      let x = a.filas.map(f => JSON.stringify(normal(f))), y = b.filas.map(f => JSON.stringify(normal(f)));
      if (!orden) { x = x.sort(); y = y.sort(); }
      return x.every((f, i) => f === y[i]);
    }
    self.onmessage = async (ev) => {
      const m = ev.data;
      try {
        if (m.accion === 'iniciar') { SQL = await initSqlJs({ wasmBinary: m.wasm }); self.postMessage({ id: m.id, listo: true }); return; }
        if (m.accion === 'tablas') {
          const db = nueva(m.esquema, '');
          const nombres = correr(db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name").resultados[0];
          const tablas = (nombres ? nombres.filas : []).map(([t]) => Object.assign({ tabla: t }, correr(db, 'SELECT * FROM "' + String(t).replace(/"/g, '""') + '" LIMIT 50').resultados[0]));
          db.close(); self.postMessage({ id: m.id, tablas }); return;
        }
        if (m.accion === 'probar') {
          const db = nueva(m.esquema, '');
          const r = correr(db, m.codigo || '');
          let extra = null;
          if (!r.error && m.entrada) { extra = correr(db, m.entrada); }
          db.close();
          self.postMessage({ id: m.id, resultados: r.resultados, error: r.error || null, linea: r.linea || null, entrada: extra }); return;
        }
        if (m.accion === 'verificar') {
          const v = m.verificacion || {};
          const db = nueva(m.esquema, v.datos || '');
          const r = correr(db, m.codigo || '');
          if (r.error) { db.close(); self.postMessage({ id: m.id, ok: false, error: r.error, linea: r.linea }); return; }
          let ok = false;
          if (v.consulta) {
            const ref = nueva(m.esquema, v.datos || ''); const esperado = correr(ref, v.consulta); ref.close();
            if (esperado.error) throw new Error('La consulta de referencia de «' + (v.nombre || v.id || 'la verificación') + '» tiene un error: ' + esperado.error);
            ok = comparar(ultimaConsulta(r.resultados), ultimaConsulta(esperado.resultados), !!v.orden);
          } else {
            const p = correr(db, v.prueba || '');
            if (p.error) throw new Error('La prueba de «' + (v.nombre || v.id || 'la verificación') + '» tiene un error: ' + p.error);
            const u = ultimaConsulta(p.resultados); const x = u && u.filas[0] ? u.filas[0][0] : null;
            ok = x === 1 || x === true || x === '1';
          }
          db.close(); self.postMessage({ id: m.id, ok }); return;
        }
      } catch (e) { self.postMessage({ id: m.id, fallo: String(e && e.message || e) }); }
    };
  }

  function crear() {
    iniciado = cargarFuente().then(f => new Promise((ok, mal) => {
      const url = URL.createObjectURL(new Blob([f.codigo, '\n;(' + trabajo.toString() + ')();'], { type: 'text/javascript' }));
      trabajador = new Worker(url);
      const t = setTimeout(() => mal(new Error('El motor SQL tardó demasiado en iniciar.')), 15000);
      trabajador.onerror = (e) => { clearTimeout(t); e.preventDefault(); mal(new Error(e.message || 'El motor SQL no pudo iniciar.')); };
      trabajador.onmessage = (ev) => { if (ev.data && ev.data.listo) { clearTimeout(t); ok(); } else if (ev.data && ev.data.fallo) { clearTimeout(t); mal(new Error(ev.data.fallo)); } };
      trabajador.postMessage({ id: 0, accion: 'iniciar', wasm: f.wasm.slice(0) });
    }));
    iniciado.catch(() => { descartar(); });
    return iniciado;
  }
  function descartar() { if (trabajador) trabajador.terminate(); trabajador = null; iniciado = null; }

  /* Una petición a la vez; si no responde a tiempo, el Worker se descarta y el siguiente se crea de nuevo. */
  function pedir(msg, ms) {
    const tarea = cola.then(async () => {
      try { await (iniciado || crear()); } catch (e) { return { fallo: 'El motor SQL no pudo iniciar en este navegador: ' + e.message }; }
      return new Promise((resolver) => {
        const id = ++sec;
        const t = setTimeout(() => { descartar(); resolver({ tiempo: true }); }, ms);
        trabajador.onmessage = (ev) => { if (ev.data && ev.data.id === id) { clearTimeout(t); resolver(ev.data); } };
        trabajador.onerror = (e) => { e.preventDefault(); clearTimeout(t); descartar(); resolver({ fallo: e.message || 'El motor SQL se detuvo.' }); };
        trabajador.postMessage(Object.assign({ id }, msg));
      });
    });
    cola = tarea.catch(() => {});
    return tarea;
  }

  /* Anexo A para SQL: mensajes de SQLite redactados en español. */
  const CATALOGO = [
    [/no such table: (\S+)/i, 'La tabla «$1» no existe. Revise el nombre: las tablas de la actividad se ven con «Ver tablas».'],
    [/no such column: (\S+)/i, 'La columna «$1» no existe en las tablas de la consulta. Revise el nombre y el alias de la tabla.'],
    [/ambiguous column name: (\S+)/i, 'La columna «$1» está en más de una tabla: indique de cuál, por ejemplo tabla.$1.'],
    [/near "([^"]*)": syntax error/i, 'Error de sintaxis cerca de «$1». Revise comas, paréntesis y el orden de las cláusulas: SELECT … FROM … WHERE … GROUP BY … HAVING … ORDER BY.'],
    [/incomplete input/i, 'La instrucción quedó incompleta: falta cerrar un paréntesis o unas comillas, o terminar una cláusula.'],
    [/unrecognized token: "?([^"]*)"?/i, 'Hay un símbolo que SQL no reconoce: «$1». ¿Unas comillas sin cerrar?'],
    [/misuse of aggregate/i, 'Una función de agregado (COUNT, SUM, AVG…) está donde no se puede usar. Para filtrar por un agregado use HAVING, no WHERE.'],
    [/a GROUP BY clause is required before HAVING/i, 'HAVING filtra grupos: necesita un GROUP BY antes.'],
    [/no such function: (\w+)/i, 'La función «$1» no existe en SQLite. Revise el nombre.'],
    [/table (\S+) has (\d+) columns but (\d+) values were supplied/i, 'La tabla «$1» tiene $2 columnas y el INSERT da $3 valores. Indique las columnas o complete los valores.'],
    [/(\d+) values for (\d+) columns/i, 'El INSERT da $1 valores para $2 columnas.'],
    [/table (\S+) already exists/i, 'La tabla «$1» ya existe.'],
    [/UNIQUE constraint failed: (\S+)/i, 'Se repite un valor que debe ser único en «$1».'],
    [/NOT NULL constraint failed: (\S+)/i, 'Falta un valor obligatorio en «$1» (no admite NULL).'],
    [/FOREIGN KEY constraint failed/i, 'El valor no existe en la tabla relacionada (llave foránea).'],
    [/CHECK constraint failed: (\S+)/i, 'Un valor no cumple la condición «$1» de la tabla.'],
  ];
  function traducir(m) {
    if (!m) return m;
    for (const [re, t] of CATALOGO) { const x = m.match(re); if (x) return t.replace(/\$(\d)/g, (s, k) => x[Number(k)] || ''); }
    return null;
  }
  const SINTAXIS = /syntax error|incomplete input|unrecognized token/i;

  function estadoDe(d, ms) {
    if (d.tiempo) return { estado: 'tiempo', mensaje: 'Se detuvo a los ' + (ms / 1000) + ' s: ¿una consulta que no termina?', resultados: [] };
    if (d.fallo) return { estado: 'error', mensaje: d.fallo, resultados: [] };
    const resultados = (d.resultados || []).concat(d.entrada ? d.entrada.resultados : []);
    if (d.error) return { estado: SINTAXIS.test(d.error) ? 'no_compila' : 'error', original: 'Línea ' + d.linea + ': ' + d.error, traducido: traducir(d.error),
      mensaje: 'Línea ' + d.linea + ': ' + (traducir(d.error) || d.error), resultados };
    if (d.entrada && d.entrada.error) return { estado: 'error', mensaje: 'La consulta del caso falló: ' + (traducir(d.entrada.error) || d.entrada.error), original: d.entrada.error, resultados };
    const algo = resultados.some(r => r.columnas || r.afectadas > 0);
    return { estado: algo ? 'con_salida' : 'sin_salida', resultados, consulta: d.entrada ? d.entrada.resultados.filter(r => r.columnas).pop() || null : null };
  }

  /** Ejecuta el código del estudiante (y, si hay, la consulta del caso) sobre una base nueva con el esquema. */
  window.ejecutarSQL = (esquema, codigo, entrada, ms) => pedir({ accion: 'probar', esquema, codigo, entrada: entrada || '' }, ms || 3000).then(d => estadoDe(d, ms || 3000));
  /** Evalúa una verificación ({consulta, orden} o {prueba}, con sus datos) sin decir nada más que si pasó. */
  window.verificarSQL = (esquema, codigo, verificacion, ms) => pedir({ accion: 'verificar', esquema, codigo, verificacion }, ms || 3000)
    .then(d => d.tiempo ? { ok: false, error: 'tiempo agotado' } : d.fallo ? { ok: false, error: d.fallo } : { ok: d.ok === true, error: d.error ? 'Línea ' + d.linea + ': ' + d.error : null });
  /** Tablas del esquema con sus primeras 50 filas. */
  window.tablasSQL = (esquema, ms) => pedir({ accion: 'tablas', esquema }, ms || 5000).then(d => d.fallo ? { error: d.fallo } : d.tiempo ? { error: 'tiempo agotado' } : { tablas: d.tablas });
  /** Resultado como texto de columnas, para la consola del calificador y el detalle. */
  window.textoSQL = (r) => {
    if (!r) return [];
    if (!r.columnas) return [(/^\s*(insert|update|delete|replace)\b/i.test(r.sql) ? r.afectadas + ' fila(s) afectada(s): ' : 'Hecho: ') + r.sql.split('\n')[0].slice(0, 80)];
    const v = (x) => x === null ? 'NULL' : String(x);
    return [r.columnas.join(' | ')].concat(r.filas.slice(0, 50).map(f => f.map(v).join(' | ')), r.filas.length > 50 || r.truncado ? ['… (' + r.filas.length + (r.truncado ? '+' : '') + ' filas)'] : []);
  };

  /* 4.4.1: paréntesis y comillas sin cerrar, ignorando comentarios -- y bloques. Señala; nunca corrige. */
  window.revisarSQL = (c) => {
    const pila = []; let linea = 1;
    for (let i = 0; i < c.length; i++) {
      const ch = c[i], sig = c[i + 1];
      if (ch === '\n') { linea++; continue; }
      if (ch === '-' && sig === '-') { while (i < c.length && c[i] !== '\n') i++; linea++; continue; }
      if (ch === '/' && sig === '*') { const f = c.indexOf('*/', i + 2); if (f < 0) return 'Línea ' + linea + ': un comentario /* no se cerró con */.'; linea += (c.slice(i, f).match(/\n/g) || []).length; i = f + 1; continue; }
      if (ch === "'" || ch === '"' || ch === '`' || ch === '[') {
        const cierre = ch === '[' ? ']' : ch; const desde = linea; let j = i + 1;
        while (j < c.length) { if (c[j] === cierre) { if (c[j + 1] === cierre && ch !== '[') { j += 2; continue; } break; } if (c[j] === '\n') linea++; j++; }
        if (j >= c.length) return 'Línea ' + desde + ': ' + (ch === "'" ? 'un texto entre comillas simples' : 'un nombre entre ' + ch + cierre) + ' no se cerró.';
        i = j; continue;
      }
      if (ch === '(') pila.push(linea);
      else if (ch === ')') { if (!pila.length) return 'Línea ' + linea + ': sobra un «)».'; pila.pop(); }
    }
    return pila.length ? 'Línea ' + pila.pop() + ': se abrió «(» y no se cerró.' : null;
  };
})();
