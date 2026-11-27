import { instalar } from './nucleo.js';
import { javascript } from '@codemirror/lang-javascript';
import { html } from '@codemirror/lang-html';
import { sql } from '@codemirror/lang-sql';
import { php } from '@codemirror/lang-php';

instalar({
  javascript: () => javascript(),
  html: () => html({ autoCloseTags: false }),       // cerrar etiquetas solo sería escribir por el estudiante (4.4.7)
  sql: () => sql(),
  php: () => php({ plain: false }),
  blade: () => html({ autoCloseTags: false }),
});
