/* Retos de HTML y CSS (4.6): el documento del estudiante se muestra en una vista previa aislada y las
   verificaciones se evalúan sobre esa página.
   - iframe con sandbox="allow-scripts" y sin allow-same-origin: origen opaco, no ve el examen ni la plataforma.
   - Política de seguridad propia: sin red; solo estilos, scripts en línea e imágenes data:.
   - Los ciclos del estudiante llevan un guardián de tiempo: un iframe aislado puede compartir el hilo de la
     página, y un while (true) la congelaría entera (4.5.2, 4.5.4).
   - Los resultados viajan con una clave que el código del estudiante no conoce: no puede fabricarlos.
   Este archivo también va incrustado en el examen: no debe contener la etiqueta de cierre de script literal. */
(function () {
  'use strict';
  /* Sin las secuencias literales de apertura y cierre de script ni de comentario HTML: este archivo va
     dentro de una etiqueta script del examen y el analizador de HTML las interpretaría. */
  const INI_SCRIPT = '<' + 'script>', FIN_SCRIPT = '<' + '/script>', INI_COMENTARIO = '<' + '!--', FIN_COMENTARIO = '--' + '>';
  const VACIOS = new Set('area base br col embed hr img input link meta source track wbr'.split(' '));
  const CIERRE_OPCIONAL = new Set('p li td th tr option dt dd thead tbody tfoot colgroup html head body'.split(' '));
  const TEXTO_CRUDO = new Set(['script', 'style', 'textarea', 'title']);

  /* Inserta el guardián en la condición de cada for (;;), while y do…while, sin tocar textos ni comentarios.
     Va como texto también dentro del iframe, para los manejadores en atributos: debe ser autocontenida. */
  function instrumentar(c) {
    const G = '__plataformaG()';
    const n = c.length; const cambios = []; let prev = '';
    const ident = (ch) => !!ch && /[\w$]/.test(ch);
    const saltarCadena = (j) => { const q = c[j]; j++; while (j < n && c[j] !== q) { if (c[j] === '\\') j++; j++; } return j; };
    const saltarRegex = (j) => { j++; let clase = false; while (j < n) { const ch = c[j]; if (ch === '\\') j++; else if (ch === '[') clase = true; else if (ch === ']') clase = false; else if ((ch === '/' && !clase) || ch === '\n') break; j++; } return j; };
    const cerrar = (j) => {
      let d = 0; const puntos = [];
      for (; j < n; j++) {
        const ch = c[j], sig = c[j + 1];
        if (ch === '"' || ch === "'" || ch === '`') { j = saltarCadena(j); continue; }
        if (ch === '/' && sig === '/') { while (j < n && c[j] !== '\n') j++; continue; }
        if (ch === '/' && sig === '*') { const f = c.indexOf('*/', j + 2); j = f < 0 ? n : f + 1; continue; }
        if ('([{'.includes(ch)) d++;
        else if (')]}'.includes(ch)) { d--; if (d === 0) return { fin: j, puntos }; }
        else if (ch === ';' && d === 1) puntos.push(j);
      }
      return null;
    };
    for (let i = 0; i < n; i++) {
      const ch = c[i], sig = c[i + 1];
      if (ch === '"' || ch === "'" || ch === '`') { i = saltarCadena(i); prev = 'a'; continue; }
      if (ch === '/' && sig === '/') { while (i < n && c[i] !== '\n') i++; continue; }
      if (ch === '/' && sig === '*') { const f = c.indexOf('*/', i + 2); i = f < 0 ? n : f + 1; continue; }
      if (ch === '/' && (!prev || '(,=:[!&|?{};+-*%<>~^'.includes(prev))) { i = saltarRegex(i); prev = 'a'; continue; }
      if (/\s/.test(ch)) continue;
      if ((ch === 'w' || ch === 'f') && !ident(c[i - 1]) && c[i - 1] !== '.') {
        const m = /^(while|for)(\s+await)?\s*\(/.exec(c.slice(i, i + 24));
        if (m) {
          const abre = i + m[0].length - 1; const r = cerrar(abre);
          if (r && m[1] === 'while') { cambios.push([abre + 1, abre + 1, G + ' && (']); cambios.push([r.fin, r.fin, ')']); }
          else if (r && r.puntos.length === 2) { const [a, b] = r.puntos; const cond = c.slice(a + 1, b).trim(); cambios.push([a + 1, b, ' ' + G + ' && (' + (cond || 'true') + ')']); }
          i += m[1].length - 1; prev = 'a'; continue;
        }
      }
      prev = ch;
    }
    let s = c;
    cambios.sort((x, y) => y[0] - x[0]).forEach(([a, b, t]) => { s = s.slice(0, a) + t + s.slice(b); });
    return s;
  }

  /* Solo se usa la versión instrumentada si sigue siendo JavaScript válido; un script con error de
     sintaxis se deja tal cual para que el estudiante vea el error real. */
  function instrumentarSeguro(js) {
    try { new Function(js); } catch (e) { return js; }
    const out = instrumentar(js);
    try { new Function(out); return out; } catch (e) { return js; }
  }

  /* Se ejecuta dentro del iframe, antes que cualquier código del estudiante. */
  function arnes(cfg, instrumentarAtributo) {
    const post = parent.postMessage.bind(parent), ahora = Date.now.bind(Date), programar = setTimeout.bind(window), Fn = Function;
    const lineas = [], errores = []; const MAX = 300; let n = 0, inicio = 0, agotado = false, enviado = false;
    const CENTINELA = '__plataforma_tiempo__';
    const enviar = (d) => { if (enviado) return; enviado = true; d.clave = cfg.clave; d.consola = lineas; post(d, '*'); };
    Object.defineProperty(window, '__plataformaG', { configurable: false, writable: false, value: function () {
      if (agotado) throw new Error(CENTINELA);
      if ((++n & 1023) === 0) {
        const t = ahora();
        if (!inicio) { inicio = t; programar(() => { inicio = 0; }, 0); }
        else if (t - inicio > cfg.limite) { agotado = true; enviar({ estado: 'tiempo' }); throw new Error(CENTINELA); }
      }
      return true;
    } });
    const ser = (v, vistos) => {
      vistos = vistos || new WeakSet();
      if (v === undefined) return 'undefined'; if (v === null) return 'null';
      if (typeof v === 'function') return '[función ' + (v.name || 'anónima') + ']';
      if (typeof v === 'string') return v;
      if (typeof Element !== 'undefined' && v instanceof Element) return v.outerHTML.length > 300 ? v.outerHTML.slice(0, 300) + '…' : v.outerHTML;
      if (typeof NodeList !== 'undefined' && (v instanceof NodeList || v instanceof HTMLCollection)) return '[' + Array.from(v).map(x => ser(x, vistos)).join(', ') + ']';
      if (typeof v === 'object') { if (vistos.has(v)) return '[referencia circular]'; vistos.add(v); try { return JSON.stringify(v, null, 2); } catch (e) { return String(v); } }
      return String(v);
    };
    const registrar = (nivel) => (...a) => { if (lineas.length < MAX) lineas.push({ nivel, texto: a.map(x => ser(x)).join(' ') }); else if (lineas.length === MAX) lineas.push({ nivel: 'warn', texto: '… salida truncada' }); };
    console.log = registrar('info'); console.info = registrar('info'); console.warn = registrar('warn'); console.error = registrar('error');
    console.table = (d) => registrar('info')(d); console.clear = () => { lineas.length = 0; };
    window.addEventListener('error', (ev) => {
      const m = String(ev.message || '');
      if (m.includes(CENTINELA)) { ev.preventDefault(); return; }
      errores.push({ mensaje: m.replace(/^Uncaught /, ''), linea: ev.lineno || null, sintaxis: (ev.error && ev.error.name === 'SyntaxError') || /SyntaxError/.test(m) });
    });
    window.addEventListener('unhandledrejection', (ev) => { const r = ev.reason; if (String(r && r.message || r).includes(CENTINELA)) return; errores.push({ mensaje: 'Promesa rechazada: ' + String(r && r.message || r), linea: null, sintaxis: false }); });
    /* Manejadores en atributos (onclick="…"): también llevan el guardián */
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('*').forEach(el => Array.from(el.attributes).forEach(a => {
        if (/^on/i.test(a.name) && /\b(for|while)\b/.test(a.value)) { try { el.setAttribute(a.name, instrumentarAtributo(a.value)); } catch (e) { /* se deja como está */ } }
      }));
    });
    window.addEventListener('load', () => programar(async () => {
      if (enviado) return;
      let retorno, ok = false, errorExpr = null;
      if (cfg.expr) {
        try { const r = await new Fn('return (' + cfg.expr + ');')(); ok = r === true; retorno = r === undefined ? undefined : ser(r); }
        catch (e) { if (String(e && e.message).includes(CENTINELA)) return; errorExpr = (e && e.name ? e.name + ': ' : '') + (e && e.message || e); }
      }
      const sintaxis = errores.find(e => e.sintaxis), primero = sintaxis || errores[0];
      const estado = agotado ? 'tiempo' : sintaxis ? 'no_compila' : (primero || errorExpr) ? 'error' : 'con_salida';
      enviar({ estado, ok, retorno, errores, mensaje: primero ? (primero.linea ? 'Línea ' + primero.linea + ': ' : '') + primero.mensaje : errorExpr });
    }, 0));
  }

  /** Documento listo para srcdoc: política, arnés y el HTML del estudiante con sus ciclos protegidos. */
  function documento(html, cfg) {
    const csp = "default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data:; font-src data:; media-src data:; connect-src 'none'; form-action 'none'";
    const datos = JSON.stringify(cfg).replace(/</g, '\\u003c');
    // Todo en una línea: los números de línea de los errores coinciden con los del estudiante
    const cabecera = '<meta http-equiv="Content-Security-Policy" content="' + csp + '">' + INI_SCRIPT + '(' + arnes.toString().replace(/\n\s*/g, ' ')
      + ')(' + datos + ', ' + instrumentar.toString().replace(/\n\s*/g, ' ') + ');document.currentScript.remove();' + FIN_SCRIPT;
    const cuerpo = String(html).replace(/(<[s]cript\b[^>]*>)([\s\S]*?)(<\/[s]cript\s*>)/gi, (m, a, js, b) =>
      /\bsrc\s*=/i.test(a) || /\btype\s*=\s*["']?(?!(?:text|application)\/javascript|module)[\w\/+-]+/i.test(a) ? m : a + instrumentarSeguro(js) + b);
    const tipo = /^\s*<!doctype[^>]*>/i.exec(cuerpo);
    return tipo ? tipo[0] + cabecera + cuerpo.slice(tipo[0].length) : cabecera + cuerpo;
  }

  const ETIQUETA_TIEMPO = (ms) => 'Se detuvo a los ' + (ms / 1000) + ' s: ¿un ciclo que no termina?';

  /**
   * Muestra el HTML del estudiante y evalúa «expr» sobre la página. Con «contenedor» la vista previa queda
   * visible e interactiva; sin él se usa una página oculta de 1024×768 que se descarta al terminar.
   * Resuelve { estado, mensaje, consola, retorno, ok, errores }.
   */
  function ejecutarHTML(html, expr, ms, contenedor) {
    ms = ms || 3000;
    return new Promise((resolver) => {
      const clave = Math.random().toString(36).slice(2) + Date.now().toString(36);
      const marco = document.createElement('iframe');
      marco.setAttribute('sandbox', 'allow-scripts');
      marco.setAttribute('title', 'Vista previa del código');
      marco.setAttribute('referrerpolicy', 'no-referrer');
      if (contenedor) { marco.className = 'vista-previa'; contenedor.replaceChildren(marco); }
      else marco.style.cssText = 'position:fixed;left:-20000px;top:0;width:1024px;height:768px;border:0;visibility:hidden';
      let listo = false;
      const terminar = (r) => {
        if (listo) return; listo = true; clearTimeout(t); window.removeEventListener('message', oir);
        if (!contenedor || r.estado === 'tiempo') marco.remove();
        if (contenedor && r.estado === 'tiempo') contenedor.replaceChildren(Object.assign(document.createElement('p'), { textContent: 'La vista previa se cerró porque el código no terminó.' }));
        resolver(Object.assign({ consola: [], ok: false, errores: [] }, r, { clave: undefined }));
      };
      const oir = (ev) => {
        if (ev.source !== marco.contentWindow || !ev.data || ev.data.clave !== clave) return;
        const d = ev.data;
        terminar({ estado: d.estado, mensaje: d.estado === 'tiempo' ? ETIQUETA_TIEMPO(ms) : d.mensaje, consola: Array.isArray(d.consola) ? d.consola : [],
          retorno: d.retorno, ok: d.ok === true, errores: d.errores || [] });
      };
      const t = setTimeout(() => terminar({ estado: 'tiempo', mensaje: ETIQUETA_TIEMPO(ms) }), ms + 1500);
      window.addEventListener('message', oir);
      marco.srcdoc = documento(html, { clave, expr: expr || '', limite: ms });
      if (!contenedor) document.body.append(marco);
    });
  }

  /* 4.4.1: etiquetas sin cerrar, cierres que no corresponden y comillas abiertas en una etiqueta.
     Revisa además el JavaScript de cada etiqueta script con «revisarJS», si se entrega. Señala; nunca corrige. */
  function revisarHTML(codigo, revisarJS) {
    const pila = []; const n = codigo.length; let linea = 1;
    const re = /<\/?([a-zA-Z][\w-]*)((?:\s+[^\s=>\/"']+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>"']+))?)*)\s*(\/?)>/y;
    for (let i = 0; i < n; i++) {
      const ch = codigo[i];
      if (ch === '\n') { linea++; continue; }
      if (ch !== '<') continue;
      if (codigo.startsWith(INI_COMENTARIO, i)) { const f = codigo.indexOf(FIN_COMENTARIO, i + 4); if (f < 0) return 'Línea ' + linea + ': un comentario ' + INI_COMENTARIO + ' no se cerró con ' + FIN_COMENTARIO + '.'; linea += (codigo.slice(i, f).match(/\n/g) || []).length; i = f + 2; continue; }
      if (codigo[i + 1] === '!' || codigo[i + 1] === '?') { const f = codigo.indexOf('>', i); i = f < 0 ? n : f; continue; }
      if (!/[a-zA-Z\/]/.test(codigo[i + 1] || '')) continue;
      re.lastIndex = i; const m = re.exec(codigo);
      if (!m) {
        const nombre = (/^<\/?([a-zA-Z][\w-]*)/.exec(codigo.slice(i)) || [])[1];
        if (nombre) return 'Línea ' + linea + ': la etiqueta <' + nombre + '> no se cerró con «>» o tiene comillas sin cerrar.';
        continue;
      }
      const nombre = m[1].toLowerCase(), cierre = codigo[i + 1] === '/';
      const lineasTag = (m[0].match(/\n/g) || []).length;
      if (cierre) {
        while (pila.length && pila[pila.length - 1].nombre !== nombre && CIERRE_OPCIONAL.has(pila[pila.length - 1].nombre)) pila.pop();
        const abierto = pila[pila.length - 1];
        if (!abierto) return 'Línea ' + linea + ': sobra </' + nombre + '>: no hay un <' + nombre + '> abierto.';
        if (abierto.nombre !== nombre) return 'Línea ' + linea + ': se cierra </' + nombre + '>, pero lo último abierto es <' + abierto.nombre + '> de la línea ' + abierto.linea + '.';
        pila.pop();
      } else if (!VACIOS.has(nombre) && !m[3]) {
        if (TEXTO_CRUDO.has(nombre)) {
          const fin = codigo.toLowerCase().indexOf('</' + nombre, re.lastIndex);
          if (fin < 0) return 'Línea ' + linea + ': se abrió <' + nombre + '> y no se cerró.';
          const dentro = codigo.slice(re.lastIndex, fin);
          if (nombre === 'script' && revisarJS) { const p = revisarJS(dentro); if (p) return 'En el ' + INI_SCRIPT + ' de la línea ' + linea + ': ' + p.replace(/^Línea (\d+)/, (x, k) => 'línea ' + (linea + lineasTag + Number(k) - 1)); }
          linea += lineasTag + (dentro.match(/\n/g) || []).length; i = fin + 1; continue;
        }
        pila.push({ nombre, linea });
      }
      linea += lineasTag; i = re.lastIndex - 1;
    }
    const sinCerrar = pila.filter(p => !CIERRE_OPCIONAL.has(p.nombre)).pop();
    return sinCerrar ? 'Línea ' + sinCerrar.linea + ': se abrió <' + sinCerrar.nombre + '> y no se cerró.' : null;
  }

  window.ejecutarHTML = ejecutarHTML;
  window.revisarHTML = revisarHTML;
  window.instrumentarCiclos = instrumentar;
})();
