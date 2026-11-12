// Pantallazos de la portada (home) de cada medio de «España al día», para las noticias que no traen foto.
// Lo ejecuta GitHub en cada pasada; deja un JPG por medio en la carpeta que se le pase (por defecto /tmp/portadas),
// que luego se sube a la rama «portadas». Necesita playwright con chromium.
import { mkdirSync } from 'node:fs'
import { chromium } from 'playwright'

const HOME = { 'The Objective': 'https://theobjective.com/', 'El Debate': 'https://www.eldebate.com/', 'La Gaceta': 'https://gaceta.es/',
  'El Español': 'https://www.elespanol.com/', 'ABC': 'https://www.abc.es/', 'El Mundo': 'https://www.elmundo.es/', 'Adelante España': 'https://adelanteespana.com/' }
const slugMedio = (m) => m.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
const dir = process.argv[2] || '/tmp/portadas'
mkdirSync(dir, { recursive: true })
// Botones del aviso de cookies: se acepta para que no tape la portada

const nav = await chromium.launch()
const ctx = await nav.newContext({ viewport: { width: 1200, height: 675 }, locale: 'es-ES', deviceScaleFactor: 1,
  userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' })
// Fuera publicidad: no se cargan anuncios y se ocultan sus huecos
const ANUNCIOS = /doubleclick|googlesyndication|googleadservices|adservice\.google|amazon-adsystem|criteo|taboola|outbrain|smartadserver|adform|rubiconproject|pubmatic|teads|seedtag|adnxs|sunmedia|richaudience|weborama|quantcast|onetag|ogury|invibes|360yield|improvedigital/i
await ctx.route('**/*', (r) => ANUNCIOS.test(r.request().url()) ? r.abort() : r.continue())
const CSS = `iframe, ins.adsbygoogle, [id^="div-gpt"], [id*="google_ads"], [class*="publicidad"], [class*="advert"], [class*="ad-slot"], [class*="adslot"], [id*="adslot"], [class*="banner-top"], [class*="megabanner"], [class*="skin"] { display: none !important; }`
let ok = 0
for (const [medio, url] of Object.entries(HOME)) {
  const p = await ctx.newPage()
  try {
    await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 })
    await p.waitForTimeout(4000)
    // aviso de cookies (a veces en un iframe, a veces con enlaces en vez de botones): se acepta
    for (let i = 0; i < 2; i++) for (const f of p.frames()) {
      const b = f.locator('button, a, [role="button"], input[type="button"]').filter({ hasText: /^\s*(acept|acepto|de acuerdo|entendido|accept|agree|consent)/i }).first()
      if (await b.isVisible().catch(() => false)) { await b.click({ timeout: 3000 }).catch(() => {}); await p.waitForTimeout(1500) }
    }
    // si el medio bloquea al navegador automático, se carga su portada bajada aparte y se fotografía igual
    const txt = (await p.locator('body').innerText().catch(() => '')).slice(0, 600)
    if (/have been blocked|access denied|just a moment|attention required|verify you are human|acceso denegado/i.test(txt)) {
      const r = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 (compatible; PatriamBoletin/1.0; +https://boletin.movimientopatriam.es)' } }).catch(() => null)
      const html = r && r.ok ? await r.text() : ''
      if (!html) throw new Error('bloqueado')
      await p.setContent(html.replace(/<head([^>]*)>/i, `<head$1><base href="${url}">`), { waitUntil: 'load', timeout: 45000 }).catch(() => {})
      await p.waitForTimeout(3000)
      console.log(`  · ${medio}: bloqueaba al navegador, se usa su portada bajada aparte`)
    }
    await p.addStyleTag({ content: CSS }).catch(() => {})
    await p.waitForTimeout(2500)
    // El pantallazo empieza en la cabecera del medio (su nombre), no en el hueco de publicidad de arriba
    let y = 0
    for (const sel of ['header', '[class*="header"]', '[id*="header"]', '[class*="masthead"]']) {
      const b = await p.locator(sel).first().boundingBox().catch(() => null)
      if (b && b.height > 30 && b.y < 900) { y = Math.max(0, Math.round(b.y)); break }
    }
    await p.screenshot({ path: `${dir}/${slugMedio(medio)}.jpg`, type: 'jpeg', quality: 72, fullPage: true, clip: { x: 0, y, width: 1200, height: 675 } })
    console.log(`  ✓ ${medio}`); ok++
  } catch (e) { console.log(`  ✗ ${medio}: ${e.message.split('\n')[0]}`) }
  await p.close()
}
await nav.close()
console.log(`Pantallazos: ${ok} de ${Object.keys(HOME).length}`)
