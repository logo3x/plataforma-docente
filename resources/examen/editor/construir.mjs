// Construye los paquetes del editor que viajan dentro del examen (sin internet): npm run build:editor
import { build } from 'esbuild';
import { readFileSync, writeFileSync } from 'node:fs';

for (const paquete of ['javascript', 'todos']) {
  const salida = `resources/examen/editor-${paquete}.js`;
  await build({ entryPoints: [`resources/examen/editor/${paquete}.js`], bundle: true, minify: true, format: 'iife', target: 'es2019',
    legalComments: 'none', outfile: salida, charset: 'utf8' });
  // 4.5.5: dentro de <script>, «<!--», «<script» y «</script» cambian dónde termina el script. Solo pueden estar
  // dentro de cadenas o expresiones regulares, donde \x3C significa lo mismo que «<».
  const js = readFileSync(salida, 'utf8').replace(/<(?=!--|\/?script)/gi, '\\x3C');
  writeFileSync(salida, `/* CodeMirror 6 (MIT), paquete «${paquete}» del editor del examen. No editar: npm run build:editor */\n` + js);
  console.log(salida, Math.round(js.length / 1024) + ' KB');
}
