import { instalar } from './nucleo.js';
import { javascript } from '@codemirror/lang-javascript';

instalar({ javascript: () => javascript() });
