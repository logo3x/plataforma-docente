/* Ejecuta código JavaScript aislado en un Worker sin red y con límite de tiempo (calificación híbrida, 5.3).
   Lo usan el calificador (retos de los estudiantes) y los ejemplos prácticos (verificar el código de la IA). */
(function () {
    const W = `self.fetch=undefined;self.XMLHttpRequest=undefined;self.WebSocket=undefined;self.importScripts=undefined;
      const L=[];console.log=console.info=console.warn=console.error=(...a)=>{if(L.length<200)L.push(a.map(x=>typeof x==='object'&&x!==null?JSON.stringify(x):String(x)).join(' '))};
      self.onmessage=(ev)=>{const {codigo,prueba}=ev.data;
        try{const r=new Function(codigo+'\\n;return ('+prueba+');')();self.postMessage({ok:r===true,consola:L});}
        catch(e){self.postMessage({ok:false,error:String(e&&e.message||e),consola:L});}};`;
    let url = null;
    window.ejecutarJS = (codigo, prueba, ms = 3000) => new Promise(res => {
        url ??= URL.createObjectURL(new Blob([W], {type: 'text/javascript'}));
        const w = new Worker(url);
        const t = setTimeout(() => { w.terminate(); res({ok: false, error: 'tiempo agotado', consola: []}); }, ms);
        w.onmessage = ev => { clearTimeout(t); w.terminate(); res(ev.data); };
        w.onerror = ev => { clearTimeout(t); w.terminate(); ev.preventDefault(); res({ok: false, error: ev.message, consola: []}); };
        w.postMessage({codigo, prueba});
    });
})();
