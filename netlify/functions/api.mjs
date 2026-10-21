/*
  Servidor del boletín (Netlify Functions + Netlify Blobs).
  Todo se gestiona desde el editor de la web: no hay que subir ni bajar archivos.

  Almacén "patriam" (Netlify Blobs):
    borrador   → lo que se está preparando en el editor (lo ven todos los usuarios del editor)
    publicado  → lo que se ve en la web
    usuarios   → usuarios del editor, con la contraseña cifrada (nunca se publica)
    secreto    → clave interna para firmar las sesiones y cifrar contraseñas
    img/...    → imágenes subidas desde el editor
*/
import { getStore } from "@netlify/blobs";
import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import semilla from "../datos/semilla.mjs";
import usuariosSemilla from "../datos/usuarios-semilla.mjs";

export const config = { path: ["/api/*", "/img/*", "/boletines.js", "/a/*"] };

const almacen = () => getStore({ name: "patriam", consistency: "strong" });
const json = (datos, estado = 200, extra = {}) => new Response(JSON.stringify(datos), { status: estado, headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", ...extra } });
const error = (texto, estado = 400) => json({ error: texto }, estado);
const esc = t => String(t ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

/* ---------- secreto, contraseñas y sesiones ---------- */
let secretoCache = null;
async function secreto(st) {
  if (secretoCache) return secretoCache;
  if (process.env.SECRETO_BOLETIN) return (secretoCache = process.env.SECRETO_BOLETIN);
  let s = await st.get("secreto");
  if (!s) { s = randomBytes(32).toString("hex"); await st.set("secreto", s); }
  return (secretoCache = s);
}
const firmar = (sec, texto) => createHmac("sha256", sec).update(texto).digest("base64url");
const cifrarClave = (sec, huella) => createHmac("sha256", sec).update("clave|" + huella).digest("hex");
function iguales(a, b) { const x = Buffer.from(String(a)), y = Buffer.from(String(b)); return x.length === y.length && timingSafeEqual(x, y); }

async function leerUsuarios(st) {
  let us = await st.get("usuarios", { type: "json" });
  if (!us) {   // primera vez: usuarios iniciales con su contraseña provisional
    const sec = await secreto(st);
    us = usuariosSemilla.map(u => ({ id: u.id, clave: cifrarClave(sec, u.clave), cambiar: true, rol: u.rol, nombre: u.nombre }));
    await st.setJSON("usuarios", us);
  }
  return us;
}
async function crearSesion(st, u) {
  const sec = await secreto(st);
  const datos = Buffer.from(JSON.stringify({ id: u.id, rol: u.rol, nombre: u.nombre, exp: Date.now() + 12 * 3600e3 })).toString("base64url");
  return datos + "." + firmar(sec, datos);
}
async function sesion(req, st) {
  const t = (req.headers.get("authorization") || "").replace(/^Bearer\s+/i, "");
  const [datos, firma] = t.split(".");
  if (!datos || !firma) return null;
  const sec = await secreto(st);
  if (!iguales(firma, firmar(sec, datos))) return null;
  try {
    const s = JSON.parse(Buffer.from(datos, "base64url").toString());
    if (s.exp < Date.now()) return null;
    const u = (await leerUsuarios(st)).find(x => x.id === s.id);   // si le han dado de baja, deja de valer
    return u ? { id: u.id, rol: u.rol, nombre: u.nombre } : null;
  } catch { return null; }
}

/* ---------- datos ---------- */
async function leer(st, clave) {
  let d = await st.get(clave, { type: "json" });
  if (!d) { d = { cfg: semilla.cfg, boletines: semilla.boletines, version: 1, fecha: new Date().toISOString() }; await st.setJSON(clave, d); }
  return d;
}
const limpiarCfg = cfg => { const c = { ...(cfg || {}) }; delete c.usuarios; return c; };
function soloPublico(d) {
  const cfg = limpiarCfg(d.cfg);
  cfg.alMomento = (cfg.alMomento || []).filter(x => x.estado === "aprobado");
  return { cfg, boletines: (d.boletines || []).filter(b => !b.estado || b.estado === "aprobado") };
}
const baseDe = req => new URL(req.url).origin + "/";

/* ---------- página para compartir un envío «Al momento» (WhatsApp, X…) ---------- */
async function paginaCompartir(req, st, slug) {
  const pub = await leer(st, "publicado");
  const x = (pub.cfg.alMomento || []).find(y => y.slug === slug && y.estado === "aprobado");
  const base = baseDe(req);
  if (!x) return Response.redirect(base, 302);
  const tieneImg = await st.getMetadata("img/am-" + slug + ".jpg");
  const img = tieneImg ? base + "img/am-" + slug + ".jpg?v=" + encodeURIComponent(x.version || x.aprobadoEl || "1") : base + "compartir.jpg?v=2";
  const plano = t => String(t || "").replace(/\*\*|\*|^##\s+|^>\s?/gm, "").replace(/\[([^\]]+)\]\([^)]+\)/g, "$1").replace(/\s+/g, " ").trim();
  let d = plano(x.resumen || x.comentario) || "Lo que hay que saber, con criterio. PATRIAM.";
  if (d.length > 190) d = d.slice(0, 190).replace(/\s+\S*$/, "") + "…";
  const destino = base + "#/al/" + encodeURIComponent(x.id);
  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(x.titulo)} · PATRIAM</title><meta name="description" content="${esc(d)}">
<meta property="og:type" content="article"><meta property="og:site_name" content="${esc((pub.cfg.cabecera || "Lo que hay que saber") + " · PATRIAM")}">
<meta property="og:title" content="${esc(x.titulo)}"><meta property="og:description" content="${esc(d)}"><meta property="og:url" content="${esc(base + "a/" + slug)}">
<meta property="og:image" content="${esc(img)}"><meta property="og:image:secure_url" content="${esc(img)}"><meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="800"><meta property="og:image:height" content="800"><meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/icono-192.png"><script>location.replace(${JSON.stringify(destino)});</script></head>
<body style="font-family:Georgia,serif;text-align:center;padding:3rem"><p><a href="${esc(destino)}">${esc(x.titulo)}</a></p></body></html>`;
  return new Response(html, { headers: { "content-type": "text/html; charset=utf-8", "cache-control": "public, max-age=60" } });
}

/* ---------- peticiones ---------- */
export default async (req) => {
  const st = almacen();
  const url = new URL(req.url), ruta = url.pathname;
  try {
    // Datos públicos de la web (lo que antes era el archivo boletines.js)
    if (ruta === "/boletines.js") {
      const p = soloPublico(await leer(st, "publicado"));
      return new Response("window.PATRIAM_CONFIG = " + JSON.stringify(p.cfg) + ";\nwindow.BOLETINES = " + JSON.stringify(p.boletines) + ";\n",
        { headers: { "content-type": "text/javascript; charset=utf-8", "cache-control": "no-cache" } });
    }
    // Imágenes subidas desde el editor
    if (ruta.startsWith("/img/")) {
      const clave = "img/" + decodeURIComponent(ruta.slice(5));
      const r = await st.getWithMetadata(clave, { type: "arrayBuffer" });
      if (!r) return new Response("No encontrada", { status: 404 });
      return new Response(r.data, { headers: { "content-type": (r.metadata && r.metadata.tipo) || "image/jpeg", "cache-control": "public, max-age=31536000, immutable" } });
    }
    // Páginas para compartir «Al momento»
    if (ruta.startsWith("/a/")) return paginaCompartir(req, st, decodeURIComponent(ruta.slice(3)).replace(/\.html$/, "").replace(/\/$/, ""));

    const accion = ruta.replace(/^\/api\//, "").replace(/\/$/, "");
    const cuerpo = req.method === "POST" ? await req.json().catch(() => ({})) : {};

    // Entrar
    if (accion === "entrar" && req.method === "POST") {
      const us = await leerUsuarios(st), sec = await secreto(st);
      const u = us.find(x => x.id === cuerpo.id);
      if (!u || !iguales(u.clave, cifrarClave(sec, String(cuerpo.clave || "")))) return error("El correo o la contraseña no son correctos.", 401);
      return json({ token: await crearSesion(st, u), usuario: { id: u.id, rol: u.rol, nombre: u.nombre, cambiar: !!u.cambiar } });
    }

    // A partir de aquí, hace falta haber entrado
    const yo = await sesion(req, st);
    if (!yo) return error("Tienes que volver a entrar.", 401);
    const admin = yo.rol === "admin";

    if (accion === "yo") {
      const u = (await leerUsuarios(st)).find(x => x.id === yo.id);
      return json({ usuario: { ...yo, cambiar: !!u.cambiar } });
    }
    // Cambiar mi contraseña
    if (accion === "clave" && req.method === "POST") {
      const us = await leerUsuarios(st), sec = await secreto(st), u = us.find(x => x.id === yo.id);
      const nueva = String(cuerpo.nueva || "");
      if (!/^[0-9a-f]{64}$/.test(nueva)) return error("Contraseña no válida.");
      if (iguales(u.clave, cifrarClave(sec, nueva))) return error("Tiene que ser distinta de la anterior.");
      u.clave = cifrarClave(sec, nueva); u.cambiar = false;
      await st.setJSON("usuarios", us);
      return json({ ok: true });
    }
    // Borrador compartido del editor
    if (accion === "borrador" && req.method === "GET") {
      const [b, p] = await Promise.all([leer(st, "borrador"), leer(st, "publicado")]);
      return json({ ...b, cfg: limpiarCfg(b.cfg), publicadoEl: p.fecha });
    }
    if (accion === "borrador" && req.method === "POST") {
      const actual = await leer(st, "borrador");
      if (cuerpo.version !== actual.version) return json({ error: "conflicto", ...actual, cfg: limpiarCfg(actual.cfg) }, 409);
      const nuevo = { cfg: limpiarCfg(cuerpo.cfg), boletines: Array.isArray(cuerpo.boletines) ? cuerpo.boletines : actual.boletines, version: actual.version + 1, fecha: new Date().toISOString(), por: yo.nombre };
      if (!admin) {   // un usuario no puede dejar nada como aprobado: eso solo lo hacen los administradores
        const antes = id => (actual.boletines || []).find(b => b.id === id);
        nuevo.boletines.forEach(b => { if (b.estado === "aprobado" && JSON.stringify(b) !== JSON.stringify(antes(b.id))) { b.estado = "borrador"; delete b.aprobadoPor; } });
        const antesAm = id => ((actual.cfg || {}).alMomento || []).find(x => x.id === id);
        (nuevo.cfg.alMomento || []).forEach(x => { if (x.estado === "aprobado" && JSON.stringify(x) !== JSON.stringify(antesAm(x.id))) { x.estado = "borrador"; delete x.aprobadoPor; } });
      }
      await st.setJSON("borrador", nuevo);
      return json({ ok: true, version: nuevo.version, boletines: nuevo.boletines, cfg: nuevo.cfg });
    }
    // Publicar: lo aprobado del borrador pasa a la web (solo administradores)
    if (accion === "publicar" && req.method === "POST") {
      if (!admin) return error("Solo pueden publicar los administradores.", 403);
      const b = await leer(st, "borrador");
      await st.setJSON("publicado", { cfg: limpiarCfg(b.cfg), boletines: b.boletines, version: b.version, fecha: new Date().toISOString(), por: yo.nombre });
      return json({ ok: true });
    }
    // Subir una imagen
    if (accion === "imagen" && req.method === "POST") {
      const m = String(cuerpo.datos || "").match(/^data:(image\/(?:jpeg|png|webp|gif));base64,(.+)$/);
      if (!m) return error("Imagen no válida.");
      const bytes = Buffer.from(m[2], "base64");
      if (bytes.length > 4.5e6) return error("La imagen es demasiado grande.");
      const ext = { "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp", "image/gif": "gif" }[m[1]];
      let nombre = String(cuerpo.nombre || "").replace(/[^a-z0-9\-]/gi, "").slice(0, 80);
      nombre = nombre ? nombre + "." + ext : Date.now().toString(36) + randomBytes(4).toString("hex") + "." + ext;
      await st.set("img/" + nombre, bytes, { metadata: { tipo: m[1], por: yo.nombre } });
      return json({ url: "/img/" + nombre });
    }
    // Usuarios (solo administradores)
    if (accion === "usuarios") {
      if (!admin) return error("Solo los administradores gestionan usuarios.", 403);
      const us = await leerUsuarios(st), sec = await secreto(st);
      if (req.method === "POST") {
        const { tipo, id, clave, rol, nombre } = cuerpo;
        if (!/^[0-9a-f]{64}$/.test(String(id || ""))) return error("Usuario no válido.");
        if (tipo === "alta") {
          if (us.some(u => u.id === id)) return error("Ese correo ya tiene acceso.");
          if (!/^[0-9a-f]{64}$/.test(String(clave || ""))) return error("Contraseña no válida.");
          us.push({ id, clave: cifrarClave(sec, clave), cambiar: true, rol: rol === "admin" ? "admin" : "editor", nombre: String(nombre || "Usuario").slice(0, 60) });
        } else if (tipo === "baja") {
          const u = us.find(x => x.id === id);
          if (!u) return error("No existe.");
          if (id === yo.id) return error("No puedes darte de baja a ti mismo.");
          if (u.rol === "admin" && us.filter(x => x.rol === "admin").length === 1) return error("Tiene que quedar al menos un administrador.");
          us.splice(us.indexOf(u), 1);
        } else if (tipo === "restablecer") {
          const u = us.find(x => x.id === id);
          if (!u || !/^[0-9a-f]{64}$/.test(String(clave || ""))) return error("Datos no válidos.");
          u.clave = cifrarClave(sec, clave); u.cambiar = true;
        } else return error("Acción no válida.");
        await st.setJSON("usuarios", us);
      }
      return json({ usuarios: us.map(u => ({ id: u.id, rol: u.rol, nombre: u.nombre, cambiar: !!u.cambiar })) });
    }
    return error("No existe.", 404);
  } catch (e) {
    console.error(e);
    return error("Error del servidor: " + (e && e.message ? e.message : e), 500);
  }
};
