/* Lo que el equipo sube desde publicar.movimientopatriam.es («Boletín paso a paso») entra solo en su boletín.
   Llega en window.PATRIAM_APORTACIONES (aportaciones.js, solo lo aprobado).
   - Los boletines abiertos desde Publicar se crean aquí si el editor todavía no los tiene.
   - Un boletín no se enseña a los lectores hasta su fecha; el equipo lo ve antes con ?vista=N.
   - Nada se repite: cada pieza lleva su id («ap-…») y si ya está (porque el editor la guardó) no se vuelve a poner.
   - Una recomendación de cada tipo por boletín: la última que se sube sustituye a la anterior del mismo tipo. */
function unirAportaciones(L, C, opciones) {
  var A = window.PATRIAM_APORTACIONES;
  if (!A || !Array.isArray(L)) return;
  opciones = opciones || {};
  var copia = function (o) { return JSON.parse(JSON.stringify(o)); };
  var buscar = function (n) { return L.filter(function (b) { return Number(b.numero) === Number(n); })[0]; };
  (A.boletines || []).forEach(function (nb) {
    if (buscar(nb.numero)) return;
    L.push({ id: "boletin-" + nb.numero, numero: Number(nb.numero), fecha: nb.fecha, titulo: "Boletín nº " + nb.numero, estado: "aprobado",
      _dePublicar: true, editorial: {}, noticias: [], colaboraciones: [], eventos: [], recomendados: [] });
  });
  L.sort(function (a, b) { return (a.numero || 0) - (b.numero || 0); });
  var yaEsta = function (lista, id) { return (lista || []).some(function (x) { return x && x.id === id; }); };
  (A.items || []).forEach(function (it) {
    var b = buscar(it.numero), d = copia(it.datos || {});
    if (!b) return;
    d.id = it.id;
    if (it.tipo === "noticia") {
      b.noticias = b.noticias || [];
      if (!yaEsta(b.noticias, it.id)) b.noticias.push(d);
    } else if (it.tipo === "firma") {
      b.colaboraciones = b.colaboraciones || [];
      if (!yaEsta(b.colaboraciones, it.id)) b.colaboraciones.push(d);
    } else if (it.tipo === "editorial") {
      if (!b.editorial || !b.editorial.texto || b.editorial.id === it.id || b._dePublicar) b.editorial = d;
      if (b._dePublicar && d.titulo) b.titulo = d.titulo;
    } else if (it.tipo === "recomendacion" && C) {
      C.biblioteca = C.biblioteca || [];
      b.recomendados = b.recomendados || [];
      if (!yaEsta(C.biblioteca, it.id)) C.biblioteca.push(Object.assign(d, { numero: b.numero }));
      if (b.recomendados.indexOf(it.id) < 0) {
        // fuera la anterior del mismo tipo en este boletín (una de cada por semana)
        var mismo = function (t) { return (t || "").replace(/^Serie de podcast$/, "Podcast").replace(/^Documental$/, "Vídeo"); };
        b.recomendados = b.recomendados.filter(function (rid) {
          var r = C.biblioteca.filter(function (y) { return y.id === rid; })[0];
          return !r || mismo(r.tipo) !== mismo(d.tipo);
        });
        b.recomendados.push(it.id);
      }
    }
  });
  if (opciones.lectores) {
    // A los lectores no se les enseñan los boletines que todavía no han salido (salvo vista previa del equipo: ?vista=N)
    var hoy = new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Madrid" });
    var vista = Number(new URLSearchParams(location.search).get("vista") || 0);
    for (var i = L.length - 1; i >= 0; i--) {
      var x = L[i];
      if (x._dePublicar && x.fecha > hoy && Number(x.numero) !== vista) L.splice(i, 1);
    }
  }
}

/* Cambios hechos desde Publicar a lo preparado en importar.js (artículos «imp-…» y recomendaciones «rec-…»):
   se aplican los datos aprobados y se quitan las piezas quitadas o pasadas a la recámara. */
function aplicarCambiosPreparados(L, C) {
  var M = (window.PATRIAM_APORTACIONES || {}).cambios || {};
  Object.keys(M).forEach(function (k) {
    var c = M[k], id = k.replace(/^(imp|rec)-/, "");
    if (/^imp-/.test(k)) (L || []).forEach(function (b) {
      b.colaboraciones = (b.colaboraciones || []).filter(function (x) { return !(x && x.id === id && c.quitado); });
      (b.colaboraciones || []).forEach(function (x) { if (x && x.id === id && c.datos) Object.assign(x, c.datos); });
    });
    if (/^rec-/.test(k) && C) {
      (C.biblioteca || []).forEach(function (x) { if (x && x.id === id && c.datos) Object.assign(x, c.datos); });
      if (c.quitado) (L || []).forEach(function (b) { b.recomendados = (b.recomendados || []).filter(function (r) { return r !== id; }); });
    }
  });
}
