/*
 * Editor de código del examen (4.4.3 y 4.4.5): numeración de líneas, resaltado de sintaxis, pareja del
 * paréntesis bajo el cursor, sangría automática, Tab que inserta espacios (Esc y luego Tab sale del editor)
 * y la línea del error subrayada con su mensaje. Sin cierre automático de paréntesis ni autocompletado:
 * la ayuda señala, nunca escribe código por el estudiante (4.4.7).
 *
 * Se monta sobre el textarea del reto, que queda oculto y sigue siendo la fuente del valor: el autoguardado,
 * la revisión de sintaxis y la ejecución no cambian. Se construye con «npm run build:editor» en dos paquetes:
 * solo JavaScript (el caso común) y todos los lenguajes; el examen lleva el que necesitan sus retos.
 */
import { EditorState, Compartment } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection, highlightSpecialChars } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { indentOnInput, bracketMatching, syntaxHighlighting, HighlightStyle, indentUnit } from '@codemirror/language';
import { tags as t } from '@lezer/highlight';
import { setDiagnostics, lintGutter } from '@codemirror/lint';

/* 9.3: colores propios, todos con contraste de al menos 4,5 a 1 sobre el blanco y sobre la línea activa. */
const colores = HighlightStyle.define([
  { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.operatorKeyword, t.definitionKeyword], color: '#6b21a8' },
  { tag: [t.string, t.special(t.string), t.regexp], color: '#9a1c1c' },
  { tag: [t.comment, t.lineComment, t.blockComment, t.docComment], color: '#4b5563', fontStyle: 'italic' },
  { tag: [t.number, t.bool, t.null, t.atom], color: '#0f5132' },
  { tag: [t.definition(t.variableName), t.function(t.variableName), t.function(t.propertyName)], color: '#1e3a8a' },
  { tag: [t.typeName, t.className, t.namespace], color: '#0b5560' },
  { tag: [t.propertyName, t.attributeName], color: '#7a3e00' },
  { tag: [t.tagName, t.angleBracket], color: '#14532d' },
  { tag: [t.processingInstruction, t.meta, t.documentMeta], color: '#4b5563' },
  { tag: t.invalid, color: '#9a1c1c', textDecoration: 'underline' },
]);

const tema = EditorView.theme({
  '&': { border: '1px solid #6b7280', borderRadius: '6px', background: '#fff', fontSize: '.95rem' },
  '&.cm-focused': { outline: '3px solid #0e7490', outlineOffset: '1px' },
  '.cm-content': { fontFamily: 'ui-monospace, Consolas, monospace', minHeight: 'var(--alto-editor, 18rem)' },
  '.cm-scroller': { maxHeight: '32rem', overflow: 'auto' },
  '.cm-gutters': { background: '#f3f4f6', color: '#374151', borderRight: '1px solid #d1d5db' },
  '.cm-activeLine': { background: '#eef6f8' },
  '.cm-activeLineGutter': { background: '#dbeafe' },
  '&.cm-focused .cm-matchingBracket': { background: '#bae6fd', outline: '1px solid #0369a1' },
  '&.cm-focused .cm-nonmatchingBracket': { background: '#fecaca' },
  '.cm-lintRange-error': { backgroundImage: 'none', textDecoration: 'underline wavy #b91c1c', textUnderlineOffset: '3px' },
  '.cm-tooltip-lint': { fontFamily: 'system-ui, sans-serif' },
});

/**
 * @param {HTMLTextAreaElement} area  el textarea del reto (ya en la página)
 * @param {{lenguaje?: string, espacios?: number, filas?: number}} op
 */
function montar(LENGUAJES, area, op = {}) {
  const soloLectura = new Compartment();
  const espacios = op.espacios || 2;
  const vista = new EditorView({
    parent: area.parentNode,
    state: EditorState.create({
      doc: area.value,
      extensions: [
        lineNumbers(), highlightActiveLineGutter(), highlightSpecialChars(), history(), drawSelection(),
        indentOnInput(), bracketMatching(), highlightActiveLine(), lintGutter(),
        syntaxHighlighting(colores),
        indentUnit.of(' '.repeat(espacios)), EditorState.tabSize.of(espacios),
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
        (LENGUAJES[op.lenguaje] || LENGUAJES.javascript || (() => []))(),
        soloLectura.of(EditorState.readOnly.of(false)),
        tema,
        EditorView.contentAttributes.of({ 'aria-label': area.getAttribute('aria-label') || 'Código', spellcheck: 'false', autocorrect: 'off', autocapitalize: 'off' }),
        EditorView.updateListener.of(u => {
          if (!u.docChanged) return;
          area.value = u.state.doc.toString();
          area.dispatchEvent(new Event('input', { bubbles: true }));
          if (u.transactions.some(t => t.isUserEvent('input.paste'))) area.dispatchEvent(new Event('paste'));
        }),
      ],
    }),
  });
  area.parentNode.insertBefore(vista.dom, area.nextSibling);
  if (op.filas) vista.dom.style.setProperty('--alto-editor', (op.filas * 1.45) + 'rem');
  area.hidden = true;

  const posicion = (linea, columna) => {
    const doc = vista.state.doc;
    const l = doc.line(Math.min(Math.max(1, linea), doc.lines));
    if (!columna) return { from: l.from + (l.text.length - l.text.trimStart().length), to: l.to };
    const from = Math.min(l.from + columna - 1, l.to);
    return { from, to: Math.min(Math.max(from + 1, from), l.to) };
  };

  return {
    vista,
    /** Subraya la línea (y la columna, si se sabe) con el mensaje del error. */
    marcar(linea, columna, mensaje) {
      const r = posicion(linea, columna);
      vista.dispatch(setDiagnostics(vista.state, [{ from: r.from, to: Math.max(r.to, r.from), severity: 'error', message: mensaje }]));
    },
    limpiar() { vista.dispatch(setDiagnostics(vista.state, [])); },
    /** Al cerrar el examen el código queda a la vista, sin poder cambiarse. */
    soloLectura() { vista.dispatch({ effects: soloLectura.reconfigure([EditorState.readOnly.of(true), EditorView.editable.of(false)]) }); },
    /** Para cuando el valor cambia por fuera (p. ej. al restaurar). */
    poner(texto) { vista.dispatch({ changes: { from: 0, to: vista.state.doc.length, insert: texto } }); },
  };
}

/** @param {Object<string, function(): *>} lenguajes los que trae este paquete */
export function instalar(lenguajes) {
  window.EditorCodigo = { lenguajes: Object.keys(lenguajes), montar: (area, op) => montar(lenguajes, area, op) };
}
