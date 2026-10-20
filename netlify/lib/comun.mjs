/* Lógica común del boletín: almacén en Netlify Blobs, sesiones y contraseñas. */
import { getStore } from "@netlify/blobs";
import crypto from "node:crypto";
import semilla from "./datos-iniciales.mjs";

export const almacen = () => getStore({ name: "boletin", consistency: "strong" });

/* ---------- datos iniciales (lo que había publicado antes del cambio) ---------- */
/* Cambios de datos que se aplican una sola vez */
const FRASE_OCTUBRE = { fraseMes: "Que cualquiera que crea sin reserva se una a nuestras filas. Que el que tenga dudas permanezca al margen.", fraseMesAutor: "Corneliu Zelea Codreanu" };
async function migrar(s) {
  await quitarPodcastDuplicado(s);
  if ((await s.get("migracion/frase-octubre")) !== null) return;
  for (const k of ["cfg", "pubcfg"]) {
    const c = await s.get(k, { type: "json" });
    if (c && !c.fraseMes) await s.setJSON(k, Object.assign(c, FRASE_OCTUBRE));
  }
  const { blobs } = await s.list({ prefix: "pub/" });
  for (const { key } of blobs) {
    for (const k of [key, "b/" + key.slice(4)]) {
      const b = await s.get(k, { type: "json" });
      if (b && !b.fraseMes && String(b.fecha || "").startsWith("2026-10")) await s.setJSON(k, Object.assign(b, FRASE_OCTUBRE));
    }
  }
  await s.set("migracion/frase-octubre", new Date().toISOString());
}

/* Boletín Nº 01: el podcast de Desmemoria Histórica estaba en Noticias y también en «Podcast recomendado» */
async function quitarPodcastDuplicado(s) {
  if ((await s.get("migracion/podcast-duplicado")) !== null) return;
  for (const k of ["b/b1", "pub/b1"]) {
    const b = await s.get(k, { type: "json" });
    if (!b || !Array.isArray(b.noticias)) continue;
    const quedan = b.noticias.filter(n => !/h9nEckgkCh8/.test(String(n.url || "")));
    if (quedan.length !== b.noticias.length) { b.noticias = quedan; await s.setJSON(k, b); }
  }
  await s.set("migracion/podcast-duplicado", new Date().toISOString());
}

export async function asegurar(s) {
  if ((await s.get("usuarios")) !== null) return migrar(s);
  const { usuarios, ...cfg } = semilla.cfg;
  await s.setJSON("cfg", cfg);
  await s.setJSON("pubcfg", cfg);
  for (const b of semilla.boletines) {
    await s.setJSON("b/" + b.id, b);
    if (b.estado === "aprobado" || !b.estado) await s.setJSON("pub/" + b.id, b);
  }
  await s.setJSON("usuarios", usuarios || [], { onlyIfNew: true });
  await migrar(s);
}

/* ---------- utilidades ---------- */
export const json = (datos, status = 200) =>
  new Response(JSON.stringify(datos), { status, headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" } });
export const hoy = () => new Date().toISOString().slice(0, 10);
export const sha256 = t => crypto.createHash("sha256").update(t).digest("hex");
export const limpio = o => JSON.parse(JSON.stringify(o, (k, v) => (k.startsWith("_") ? undefined : v)));
export const idValido = id => typeof id === "string" && /^[\w-]{1,80}$/.test(id);

/* ---------- contraseñas (compatibles con las provisionales ya creadas) ---------- */
const SAL = "patriam-boletin|";
export const huellaCorreo = c => sha256(SAL + String(c).trim().toLowerCase());
const huellaAntigua = (c, k) => sha256(SAL + String(c).trim().toLowerCase() + "|" + k);
export function cifrarClave(clave) {
  const sal = crypto.randomBytes(16).toString("hex");
  return "s$" + sal + "$" + crypto.scryptSync(clave, sal, 32).toString("hex");
}
export function comprobarClave(u, correo, clave) {
  if (!u || !u.clave) return false;
  let esperado, obtenido;
  if (u.clave.startsWith("s$")) {
    const [, sal, h] = u.clave.split("$");
    esperado = Buffer.from(h, "hex"); obtenido = crypto.scryptSync(clave, sal, 32);
  } else {
    esperado = Buffer.from(u.clave, "hex"); obtenido = Buffer.from(huellaAntigua(correo, clave), "hex");
  }
  return esperado.length === obtenido.length && crypto.timingSafeEqual(esperado, obtenido);
}
export function enmascarar(c) { c = String(c).trim().toLowerCase(); const [u, d] = c.split("@"); return (u || "").slice(0, 3) + "…@" + (d || ""); }

/* ---------- bajas del boletín: enlace firmado para cada suscriptor ---------- */
export const claveCorreo = email => sha256(String(email).trim().toLowerCase()).slice(0, 32);
export const firmaBaja = email => crypto.createHmac("sha256", Netlify.env.get("SESION_SECRETO") || "").update("baja|" + String(email).trim().toLowerCase()).digest("hex").slice(0, 32);
export const enlaceBaja = (origen, email) => origen + "/#/baja/" + encodeURIComponent(String(email).trim().toLowerCase()) + "/" + firmaBaja(email);
/* Pasa a un suscriptor a la lista de bajas, con la fecha y cómo se dio de baja (queda registrado) */
export async function darDeBaja(s, email, via) {
  email = String(email || "").trim().toLowerCase();
  const k = claveCorreo(email), sus = await s.get("sus/" + k, { type: "json" });
  if (!sus) return false;
  const ant = (await s.get("bajas/" + k, { type: "json" })) || {};
  const ahora = new Date().toISOString();
  const historial = (ant.historial || []).concat(sus.historial || [], [{ que: "baja", fecha: ahora, via }]);
  await s.setJSON("bajas/" + k, { nombre: sus.nombre, email, movil: sus.movil || "", alta: sus.fecha, baja: ahora, via, historial });
  await s.delete("sus/" + k);
  return true;
}

/* ---------- sesiones firmadas ---------- */
function secreto() {
  const s = Netlify.env.get("SESION_SECRETO");
  if (!s) throw new Error("Falta la variable SESION_SECRETO en Netlify");
  return s;
}
const b64 = t => Buffer.from(t).toString("base64url");
export function firmar(u) {
  const datos = b64(JSON.stringify({ id: u.id, exp: Date.now() + 12 * 3600 * 1000 }));
  return datos + "." + crypto.createHmac("sha256", secreto()).update(datos).digest("base64url");
}
export async function sesion(req, s) {
  const t = (req.headers.get("authorization") || "").replace(/^Bearer\s+/i, "");
  const [datos, firma] = t.split(".");
  if (!datos || !firma) return null;
  const buena = crypto.createHmac("sha256", secreto()).update(datos).digest("base64url");
  if (buena.length !== firma.length || !crypto.timingSafeEqual(Buffer.from(buena), Buffer.from(firma))) return null;
  let p; try { p = JSON.parse(Buffer.from(datos, "base64url").toString()); } catch { return null; }
  if (!p.exp || p.exp < Date.now()) return null;
  const usuarios = (await s.get("usuarios", { type: "json" })) || [];
  return usuarios.find(u => u.id === p.id) || null; // si le han dado de baja, la sesión deja de valer
}

/* ---------- imágenes: se guardan aparte y el boletín solo lleva la dirección ---------- */
export async function sacarImagenes(s, obj) {
  const mapa = {};
  const recorrer = async v => {
    if (typeof v === "string" && v.startsWith("data:image/")) {
      const m = v.match(/^data:(image\/[\w.+-]+);base64,(.+)$/);
      if (!m) return v;
      const datos = Buffer.from(m[2], "base64"), clave = sha256(v).slice(0, 32);
      await s.set("img/" + clave, datos, { metadata: { tipo: m[1] } });
      const url = "/img/" + clave;
      mapa[v.length + ":" + v.slice(-64)] = url;
      return url;
    }
    if (Array.isArray(v)) { for (let i = 0; i < v.length; i++) v[i] = await recorrer(v[i]); return v; }
    if (v && typeof v === "object") { for (const k of Object.keys(v)) v[k] = await recorrer(v[k]); return v; }
    return v;
  };
  await recorrer(obj);
  return mapa;
}
