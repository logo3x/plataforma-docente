/*
 * Editor del banco de preguntas (13.5). Convierte cada ítem «plataforma-banco» en un formulario por tipo y lo
 * devuelve al formato al guardar. El servidor valida igual que una importación: aquí solo se ayuda a no
 * equivocarse (sumas por versión, correctas marcadas, huecos de la plantilla).
 */
window.editorBanco = function (inicial, otras, tipos) {
  const LETRAS = 'abcdefghijklmnopqrstuvwxyz';
  const RETO = ['lenguaje', 'esquema', 'archivos', 'alcance', 'plantilla_codigo', 'casos_visibles', 'verificaciones'];
  const texto = (v) => (v === null || v === undefined ? '' : String(v));
  let siguiente = 1;

  const aFormulario = (it) => {
    const f = {
      uid: siguiente++, _ap: it._ap || null, tipo: it.tipo || 'unica', version: it.version || 'A', peso: texto(it.peso || '1'),
      enunciado: texto(it.enunciado), tema: texto(it.tema), dificultad: it.dificultad || 'media', justificacion: texto(it.justificacion),
      ops: [{ t: '', ok: true }, { t: '', ok: false }], vf: 'true', izq: [{ t: '', par: '' }], der: [{ k: 'a', t: '' }],
      pasos: ['', ''], plantilla: '', huecos: [''], referencia: '', rubrica: '', lineas: 6,
      reto: JSON.stringify({ lenguaje: 'javascript', plantilla_codigo: '', casos_visibles: [], verificaciones: [{ id: 'v1', nombre: '', fraccion: '1', prueba: '' }] }, null, 2),
      abierto: false,
    };
    if (it.opciones) {
      const correctas = (it.correctas || [it.correcta]).map(String);
      f.ops = Object.entries(it.opciones).map(([k, t]) => ({ t: texto(t), ok: correctas.includes(String(k)) }));
    }
    if (it.tipo === 'vf') f.vf = it.correcta ? 'true' : 'false';
    if (it.tipo === 'emparejar' && it.izquierda) {
      f.der = Object.entries(it.derecha || {}).map(([k, t]) => ({ k: String(k), t: texto(t) }));
      f.izq = Object.entries(it.izquierda || {}).map(([k, t]) => ({ t: texto(t), par: texto((it.pares || {})[k]) }));
    }
    if (it.tipo === 'ordenar' && it.pasos) f.pasos = (it.orden || Object.keys(it.pasos || {})).map(k => texto((it.pasos || {})[k]));
    if (it.tipo === 'completar' && it.huecos) {
      f.plantilla = texto(it.plantilla);
      f.huecos = it.huecos.map(h => Array.isArray(h) ? h.join(' | ') : texto(h));
    }
    if (it.tipo === 'abierta') {
      f.referencia = texto(it.referencia);
      f.rubrica = typeof it.rubrica === 'string' || !it.rubrica ? texto(it.rubrica) : JSON.stringify(it.rubrica);
      f.lineas = it.lineas || 6;
    }
    if (it.tipo === 'reto') {
      const r = {};
      RETO.forEach(c => { if (it[c] !== undefined) r[c] = it[c]; });
      f.reto = JSON.stringify(r, null, 2);
    }
    return f;
  };

  const aBanco = (f) => {
    const it = { tipo: f.tipo, version: f.version, peso: texto(f.peso).trim().replace(',', '.'), enunciado: f.enunciado.trim() };
    if (f._ap) it._ap = f._ap;
    if (f.tema.trim()) it.tema = f.tema.trim();
    it.dificultad = f.dificultad;
    if (f.justificacion.trim()) it.justificacion = f.justificacion.trim();
    const ops = f.ops.filter(o => o.t.trim() !== '');
    switch (f.tipo) {
      case 'unica':
        it.opciones = Object.fromEntries(ops.map((o, i) => [LETRAS[i], o.t.trim()]));
        it.correcta = LETRAS[Math.max(0, ops.findIndex(o => o.ok))];
        break;
      case 'multiple':
        it.opciones = Object.fromEntries(ops.map((o, i) => [LETRAS[i], o.t.trim()]));
        it.correctas = ops.map((o, i) => (o.ok ? LETRAS[i] : null)).filter(Boolean);
        break;
      case 'vf': it.correcta = f.vf === 'true'; break;
      case 'emparejar': {
        const der = f.der.filter(d => d.t.trim() !== '');
        it.derecha = Object.fromEntries(der.map(d => [d.k, d.t.trim()]));
        const izq = f.izq.filter(x => x.t.trim() !== '');
        it.izquierda = Object.fromEntries(izq.map((x, i) => [String(i + 1), x.t.trim()]));
        it.pares = Object.fromEntries(izq.map((x, i) => [String(i + 1), x.par]));
        break;
      }
      case 'ordenar': {
        const pasos = f.pasos.map(p => p.trim()).filter(Boolean);
        it.pasos = Object.fromEntries(pasos.map((p, i) => ['p' + (i + 1), p]));
        it.orden = pasos.map((_, i) => 'p' + (i + 1));
        break;
      }
      case 'completar':
        it.plantilla = f.plantilla;
        it.huecos = f.huecos.map(h => h.split('|').map(x => x.trim()).filter(Boolean)).filter(h => h.length).map(h => (h.length === 1 ? h[0] : h));
        break;
      case 'abierta':
        it.lineas = Number(f.lineas) || 6;
        if (f.referencia.trim()) it.referencia = f.referencia.trim();
        if (f.rubrica.trim()) it.rubrica = f.rubrica.trim();
        break;
      case 'reto': Object.assign(it, JSON.parse(f.reto)); break;
    }
    return it;
  };

  const centesimas = (v) => Math.round(Number(texto(v).replace(',', '.')) * 10000);

  return {
    b: { sigla: inicial.sigla || '', titulo: inicial.titulo || '', tipo: inicial.tipo || 'quiz', valor: texto(inicial.valor || '5') },
    meta: { formato: 'plataforma-banco', version: 1, configuracion: inicial.configuracion || {} },
    items: (inicial.items || []).map(aFormulario),
    otras: otras || [],
    version: (inicial.items || [])[0]?.version || 'A',
    filtro: '',
    errorReto: {},
    LETRAS,
    tipos: tipos || {},

    get versiones() { return ['A', 'B', '2A', '2B'].filter(v => v === 'A' || this.items.some(i => i.version === v)); },
    deVersion(v) { return this.items.filter(i => i.version === v); },
    suma(v) { return this.deVersion(v).reduce((s, i) => s + (centesimas(i.peso) || 0), 0) / 10000; },
    cuadra(v) { return centesimas(this.suma(v)) === centesimas(this.b.valor); },
    agregar(tipo, despues) {
      const f = aFormulario({ tipo, version: this.version, peso: '1' });
      f.abierto = true;
      const i = despues ? this.items.indexOf(despues) + 1 : this.items.length;
      this.items.splice(i, 0, f);
      this.$nextTick(() => document.getElementById('enun-' + f.uid)?.focus());
    },
    traer(o) {
      const f = aFormulario(Object.assign({}, o.item, { version: this.version }));
      f.abierto = true;
      this.items.push(f);
    },
    duplicar(f) { const c = aFormulario(aBanco(Object.assign({}, f, { _ap: null }))); c._ap = null; c.abierto = true; this.items.splice(this.items.indexOf(f) + 1, 0, c); },
    mover(f, paso) {
      const lista = this.deVersion(f.version); const j = lista.indexOf(f) + paso;
      if (j < 0 || j >= lista.length) return;
      const a = this.items.indexOf(f), b2 = this.items.indexOf(lista[j]);
      [this.items[a], this.items[b2]] = [this.items[b2], this.items[a]];
    },
    quitar(f) { if (confirm('¿Quitar esta pregunta del banco?')) this.items.splice(this.items.indexOf(f), 1); },
    nuevaDerecha(f) { const usadas = f.der.map(d => d.k); f.der.push({ k: [...LETRAS].find(l => !usadas.includes(l)), t: '' }); },
    mezclarDerecha(f) { for (let i = f.der.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [f.der[i], f.der[j]] = [f.der[j], f.der[i]]; } },
    huecosEnPlantilla(f) { return (f.plantilla.match(/___/g) || []).length; },
    marcarUnica(f, o) { f.ops.forEach(x => { x.ok = x === o; }); },
    resumen(f) { return (f.enunciado || '(sin enunciado)').replace(/\s+/g, ' ').slice(0, 110); },
    get otrasFiltradas() {
      const q = this.filtro.trim().toLowerCase();
      return this.otras.filter(o => !q || (o.item.enunciado + ' ' + (o.item.tema || '') + ' ' + o.origen + ' ' + (this.tipos[o.item.tipo] || o.item.tipo)).toLowerCase().includes(q)).slice(0, 40);
    },
    validarReto(f) {
      try { const r = JSON.parse(f.reto); if (typeof r !== 'object' || Array.isArray(r)) throw new Error('debe ser un objeto { … }'); delete this.errorReto[f.uid]; return true; }
      catch (e) { this.errorReto[f.uid] = 'El JSON del reto no es válido: ' + e.message; return false; }
    },
    json() {
      return JSON.stringify(Object.assign({}, this.meta, this.b, { items: this.items.map(aBanco) }));
    },
    enviar(ev) {
      const malos = this.items.filter(f => f.tipo === 'reto' && !this.validarReto(f));
      if (malos.length) { ev.preventDefault(); malos.forEach(f => { f.abierto = true; this.version = f.version; }); return; }
      ev.target.querySelector('input[name=banco]').value = this.json();
    },
  };
};
