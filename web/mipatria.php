<?php
/* «España al día» como página propia y newsletter diaria: boletin.movimientopatriam.es/mipatriaaldia
   - GET: la página (se genera en el servidor, con su tarjeta para WhatsApp y las noticias de la semana, hoy primero).
   - POST ?accion=alta {email}: alta en la newsletter diaria (se confirma con un enlace que llega por correo).
   - ?accion=confirmar / ?accion=baja: con enlace firmado.
   - Envío diario: lo lanza el cron de Hostinger por consola («php mipatria.php enviar»), no se puede lanzar desde fuera.
   Los correos se mandan con Brevo (la misma clave y remitente que las comunicaciones del editor).
   La lista va en la carpeta privada del servidor: mipatria-suscriptores.json */
require __DIR__ . '/_privado.php';

const WEB_MP = 'https://boletin.movimientopatriam.es';
const URL_MP = WEB_MP . '/mipatriaaldia';
$CLI = PHP_SAPI === 'cli';
$acc = $CLI ? ($argv[1] ?? '') : ($_GET['accion'] ?? '');

function e($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function noticias() {
  $t = @file_get_contents(__DIR__ . '/espana-al-dia.js') ?: '';
  $d = preg_match('/=\s*(\{.*\})\s*;?\s*$/s', $t, $m) ? (json_decode($m[1], true) ?: []) : [];
  // Las que el equipo mete a mano desde Publicar («no puede faltar»): delante, siete días
  $ap = is_file(privado() . '/aportaciones.json') ? (json_decode(file_get_contents(privado() . '/aportaciones.json'), true) ?: []) : [];
  $fijas = [];
  foreach ($ap['items'] ?? [] as $x) {
    if (($x['tipo'] ?? '') !== 'espana' || ($x['estado'] ?? '') !== 'aprobado' || strtotime($x['creado'] ?? '') < time() - 7 * 86400) continue;
    $v = $x['datos'];
    $fijas[] = ['titulo' => $v['titulo'], 'resumen' => $v['resumen'] ?? '', 'imagen' => $v['imagen'] ?? '', 'fecha' => $x['creado'], 'tema' => $v['tema'] ?? 'Política', 'destacada' => true, 'medios' => [['medio' => $v['medio'] ?? 'Abrir', 'url' => $v['url']]]];
  }
  usort($fijas, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
  $urls = array_map(fn($f) => $f['medios'][0]['url'], $fijas);
  $auto = array_values(array_filter($d['grupos'] ?? [], fn($g) => !array_intersect(array_column($g['medios'] ?? [], 'url'), $urls)));
  // dentro de cada día, las destacadas primero
  $todo = array_merge($fijas, $auto);
  usort($todo, fn($a, $b) => strcmp(substr(dia2($b['fecha']), 0, 10), substr(dia2($a['fecha']), 0, 10)) ?: (!empty($b['destacada']) <=> !empty($a['destacada'])) ?: (count($b['medios']) <=> count($a['medios'])) ?: strcmp($b['fecha'], $a['fecha']));
  $d['grupos'] = $todo;
  return $d;
}
function dia2($iso) { return (new DateTime($iso))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d'); }
function lista() { $f = privado() . '/mipatria-suscriptores.json'; return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function guardarLista($l) { $f = privado() . '/mipatria-suscriptores.json'; file_put_contents($f . '.tmp', json_encode($l, JSON_UNESCAPED_UNICODE), LOCK_EX); rename($f . '.tmp', $f); @chmod($f, 0600); }
function firma($email, $que) { return substr(hash_hmac('sha256', 'mp|' . $que . '|' . strtolower($email), secreto()), 0, 20); }
function enlace($email, $que) { return URL_MP . '?accion=' . $que . '&e=' . rawurlencode($email) . '&f=' . firma($email, $que); }
function brevo($para, $asunto, $html, $texto, $baja = '') {
  $k = is_file(privado() . '/claves.json') ? (json_decode(file_get_contents(privado() . '/claves.json'), true) ?: []) : [];
  $aj = (is_file(privado() . '/com.json') ? (json_decode(file_get_contents(privado() . '/com.json'), true) ?: []) : [])['ajustes'] ?? [];
  if (empty($k['brevo']) || empty($aj['remitente'])) return [false, 'Falta la clave de Brevo o el remitente (editor → Comunicaciones → Claves de envío / Firma y remitente).'];
  $msg = ['sender' => ['name' => 'PATRIAM · España al día', 'email' => $aj['remitente']], 'to' => [['email' => $para]], 'subject' => $asunto,
    'htmlContent' => $html, 'textContent' => $texto, 'tags' => ['espana-al-dia']];
  if (!empty($aj['responderA'])) $msg['replyTo'] = ['email' => $aj['responderA']];
  if ($baja) $msg['headers'] = ['List-Unsubscribe' => '<' . $baja . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
  [$c, $r, $err] = pedir('https://api.brevo.com/v3/smtp/email', ['api-key: ' . $k['brevo'], 'Content-Type: application/json', 'Accept: application/json'], json_encode($msg, JSON_UNESCAPED_UNICODE));
  return [$c >= 200 && $c < 300, $c >= 200 && $c < 300 ? '' : ((json_decode($r, true)['message'] ?? '') ?: ($err ?: 'Brevo ' . $c))];
}
function dia($iso) {
  $z = new DateTimeZone('Europe/Madrid');
  $d = (new DateTime($iso))->setTimezone($z); $hoy = new DateTime('now', $z);
  if ($d->format('Y-m-d') === $hoy->format('Y-m-d')) return 'Hoy';
  if ($d->format('Y-m-d') === (clone $hoy)->modify('-1 day')->format('Y-m-d')) return 'Ayer';
  $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  return ucfirst($dias[(int)$d->format('w')]) . ' ' . (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n')];
}
function hora($iso) { return (new DateTime($iso))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('H:i'); }
function pagina($titulo, $cuerpo) {
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($titulo) . '</title><meta name="robots" content="noindex"><link rel="icon" href="/icono-192.png">'
    . '<style>body{margin:0;background:#faf5ec;font-family:Georgia,serif;color:#2a211d;display:grid;place-items:center;min-height:100vh;padding:20px;box-sizing:border-box}div{max-width:520px;text-align:center;background:#fff;border-top:5px solid #7b1c2c;padding:28px}a{color:#7b1c2c;font-weight:700}</style></head><body><div><img src="/logo.png" alt="PATRIAM" style="height:54px"><h1 style="color:#7b1c2c">' . e($titulo) . '</h1>' . $cuerpo . '</div></body></html>';
  exit;
}

/* ---------- alta, confirmación y baja ---------- */
if ($acc === 'alta') {
  header('Content-Type: application/json; charset=utf-8');
  $email = strtolower(trim((string)(json_decode(file_get_contents('php://input') ?: '{}', true)['email'] ?? ($_POST['email'] ?? ''))));
  if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) || strlen($email) > 160) salir(['error' => 'Ese correo no parece válido.'], 400);
  $l = lista();
  if (($l[$email]['estado'] ?? '') === 'activo') salir(['ok' => true, 'ya' => true]);
  $l[$email] = ['estado' => 'pendiente', 'alta' => gmdate('c')] + ($l[$email] ?? []);
  guardarLista($l);
  $c = enlace($email, 'confirmar');
  $html = '<div style="font-family:Georgia,serif;max-width:520px;margin:auto;color:#2a211d"><img src="' . WEB_MP . '/logo.png" alt="PATRIAM" style="height:48px"><h2 style="color:#7b1c2c">Confirma tu suscripción a «España al día»</h2>'
    . '<p>Cada mañana te mandaremos el clipping de noticias políticas y económicas de España. Para empezar a recibirlo, pulsa aquí:</p>'
    . '<p><a href="' . e($c) . '" style="display:inline-block;background:#7b1c2c;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:700">Sí, quiero recibirlo</a></p>'
    . '<p style="color:#7a6d66;font-size:13px">Si no lo has pedido tú, no hagas nada y no recibirás ningún correo.</p></div>';
  [$ok, $motivo] = brevo($email, 'Confirma tu suscripción a «España al día»', $html, "Para recibir cada mañana «España al día», confirma aquí: $c");
  salir($ok ? ['ok' => true] : ['error' => 'No se ha podido enviar el correo de confirmación. ' . $motivo], $ok ? 200 : 502);
}
if ($acc === 'confirmar' || $acc === 'baja') {
  $email = strtolower(trim((string)($_GET['e'] ?? '')));
  if (!hash_equals(firma($email, $acc), (string)($_GET['f'] ?? ''))) pagina('Enlace no válido', '<p>Este enlace no es válido o está incompleto.</p><p><a href="' . URL_MP . '">Ir a España al día</a></p>');
  $l = lista();
  if ($acc === 'confirmar') { $l[$email] = ['estado' => 'activo', 'confirmado' => gmdate('c')] + ($l[$email] ?? []); $l[$email]['estado'] = 'activo'; }
  else { $l[$email] = ['estado' => 'baja', 'baja' => gmdate('c')] + ($l[$email] ?? []); $l[$email]['estado'] = 'baja'; }
  guardarLista($l);
  if ($acc === 'confirmar') pagina('¡Ya estás dentro!', '<p>Desde mañana recibirás cada día «España al día» en <b>' . e($email) . '</b>.</p><p><a href="' . URL_MP . '">Ver las noticias de hoy</a></p>');
  pagina('Te has dado de baja', '<p>Ya no recibirás «España al día» en <b>' . e($email) . '</b>.</p><p><a href="' . URL_MP . '">Ver las noticias de hoy</a></p>');
}

/* ---------- envío diario (solo por consola: cron de Hostinger) ---------- */
if ($acc === 'enviar') {
  if (!$CLI) { http_response_code(404); exit; }
  $hoy = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
  $log = privado() . '/mipatria-envios.json';
  $hechos = is_file($log) ? (json_decode(file_get_contents($log), true) ?: []) : [];
  if (!empty($hechos[$hoy]) && !in_array('--otra-vez', $argv, true)) { echo "Ya se envió hoy\n"; exit; }
  $g = noticias()['grupos'] ?? [];
  $limite = time() - 30 * 3600;   // lo publicado desde ayer por la mañana
  $g = array_values(array_filter($g, fn($x) => strtotime($x['fecha'] ?? '') >= $limite));
  usort($g, fn($a, $b) => (!empty($b['destacada']) <=> !empty($a['destacada'])) ?: (count($b['medios']) <=> count($a['medios'])) ?: strcmp($b['fecha'], $a['fecha']));
  $g = array_slice($g, 0, 12);
  if (!$g) { echo "No hay noticias nuevas\n"; exit; }
  $F = 'Georgia,serif'; $G = '#7b1c2c';
  $filas = '';
  foreach ($g as $x) {
    $img = preg_match('#^https?://#', $x['imagen'] ?? '') ? '<td width="110" valign="top" style="width:110px;padding:14px 14px 0 0"><img src="' . e($x['imagen']) . '" width="110" alt="" style="display:block;width:110px;height:auto;border-radius:3px"></td>' : '';
    $bot = implode('', array_map(fn($m) => '<a href="' . e($m['url']) . '" style="display:inline-block;margin:0 6px 6px 0;background:' . $G . ';color:#fff;text-decoration:none;font:600 12px ' . $F . ';padding:6px 12px;border-radius:999px">' . e($m['medio']) . ' →</a>', $x['medios']));
    $filas .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eadfcd"><tr>' . $img . '<td valign="top" style="padding-top:14px">'
      . '<p style="margin:0 0 4px;font:600 10px ' . $F . ';letter-spacing:2px;text-transform:uppercase;color:' . $G . '">' . e($x['tema'] ?? 'Política') . '</p>'
      . '<p style="margin:0 0 6px;font:700 17px/1.3 ' . $F . ';color:#2a211d">' . e($x['titulo']) . '</p>'
      . (!empty($x['resumen']) ? '<p style="margin:0 0 8px;font:400 14px/1.5 ' . $F . ';color:#5b4f49">' . e($x['resumen']) . '</p>' : '')
      . '<p style="margin:0 0 12px">' . $bot . '</p></td></tr></table>';
  }
  $fecha = dia(date('c'));
  $env = 0; $mal = [];
  foreach (lista() as $email => $s) {
    if (($s['estado'] ?? '') !== 'activo') continue;
    $baja = enlace($email, 'baja');
    $html = '<div style="background:#faf5ec;padding:20px 10px"><div style="max-width:620px;margin:auto;background:#fff;border-top:6px solid ' . $G . ';padding:24px">'
      . '<div style="text-align:center"><img src="' . WEB_MP . '/logo.png" alt="PATRIAM" style="height:52px"><p style="margin:14px 0 0;font:600 12px ' . $F . ';letter-spacing:4px;color:' . $G . '">CLIPPING DIARIO</p>'
      . '<h1 style="margin:4px 0 0;font:800 34px ' . $F . ';color:' . $G . '">ESPAÑA AL DÍA</h1><p style="margin:6px 0 18px;font:italic 400 16px ' . $F . ';color:#5b4f49">Noticias políticas y económicas de España · ' . e(mb_strtolower($fecha) === 'hoy' ? 'hoy' : $fecha) . '</p></div>'
      . $filas
      . '<p style="text-align:center;margin:22px 0 8px"><a href="' . URL_MP . '" style="display:inline-block;background:' . $G . ';color:#fff;padding:12px 24px;border-radius:999px;text-decoration:none;font:700 14px ' . $F . '">Toda la semana en la web →</a></p>'
      . '<p style="text-align:center;font:400 12px ' . $F . ';color:#8a7d75;margin:18px 0 0">Recibes este correo porque te apuntaste a «España al día» de PATRIAM. <a href="' . e($baja) . '" style="color:#8a7d75">Darme de baja</a></p>'
      . '</div></div>';
    $texto = "ESPAÑA AL DÍA · $fecha\n\n" . implode("\n\n", array_map(fn($x) => $x['titulo'] . "\n" . implode(' · ', array_map(fn($m) => $m['medio'] . ': ' . $m['url'], $x['medios'])), $g)) . "\n\nToda la semana: " . URL_MP . "\nDarme de baja: $baja";
    [$ok, $motivo] = brevo($email, 'España al día · ' . $g[0]['titulo'], $html, $texto, $baja);
    if ($ok) $env++; else $mal[] = "$email: $motivo";
  }
  $hechos[$hoy] = ['enviados' => $env, 'errores' => count($mal), 'hora' => gmdate('c')];
  file_put_contents($log, json_encode(array_slice($hechos, -60, null, true)), LOCK_EX);
  echo "Enviados: $env" . ($mal ? "\nErrores:\n" . implode("\n", $mal) : '') . "\n";
  exit;
}

/* ---------- la página ---------- */
$d = noticias(); $g = $d['grupos'] ?? [];
$filtro = $_GET['t'] ?? '';
if ($filtro === 'politica') $g = array_values(array_filter($g, fn($x) => ($x['tema'] ?? '') === 'Política'));
if ($filtro === 'economia') $g = array_values(array_filter($g, fn($x) => ($x['tema'] ?? '') === 'Economía'));
$titulo = 'España al día · Clipping de noticias políticas y económicas de España';
$desc = 'Clipping diario de noticias políticas y económicas de España, con toda la semana. Se actualiza cada día. Por PATRIAM.';
$img = WEB_MP . '/espana-al-dia-og.jpg?v=2';
$wa = 'https://wa.me/?text=' . rawurlencode("*España al día* · Clipping diario de noticias políticas y económicas de España, con toda la semana.\n👉 " . URL_MP);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($titulo) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= URL_MP ?>">
<meta property="og:type" content="website"><meta property="og:site_name" content="PATRIAM"><meta property="og:locale" content="es_ES">
<meta property="og:title" content="<?= e($titulo) ?>"><meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= URL_MP ?>"><meta property="og:image" content="<?= $img ?>">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= e($titulo) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>"><meta name="twitter:image" content="<?= $img ?>">
<link rel="icon" href="/icono-192.png"><link rel="apple-touch-icon" href="/icono-192.png">
<style>
@font-face{font-family:Spectral;font-weight:400;font-display:swap;src:url(/fuentes/spectral-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:Spectral;font-weight:400;font-style:italic;font-display:swap;src:url(/fuentes/spectral-latin-400-italic.woff2) format("woff2")}
@font-face{font-family:Spectral;font-weight:600;font-display:swap;src:url(/fuentes/spectral-latin-600-normal.woff2) format("woff2")}
@font-face{font-family:Spectral;font-weight:800;font-display:swap;src:url(/fuentes/spectral-latin-800-normal.woff2) format("woff2")}
:root{--g:#7b1c2c;--t:#2a211d;--s:#6b5d55;--c:#faf5ec;--l:#e3d6c1}
*{box-sizing:border-box}body{margin:0;background:var(--c);color:var(--t);font-family:Spectral,Georgia,serif}
a{color:inherit}
.cab{background:var(--g);color:#fff;text-align:center;padding:22px 16px 26px;border-bottom:6px solid #c9a24a}
.cab img{height:58px;background:#faf5ec;padding:8px 14px;border-radius:4px}
.cab small{display:block;letter-spacing:.35em;font-weight:600;font-size:.78rem;margin-top:14px;opacity:.85}
.cab h1{margin:.2rem 0 0;font-weight:800;font-size:clamp(2.2rem,8vw,4rem);letter-spacing:.02em;line-height:1}
.cab p{margin:.5rem auto 0;font-style:italic;font-size:1.1rem;max-width:560px;opacity:.95}
.cab .act{font-style:normal;font-size:.85rem;opacity:.75;margin-top:.4rem}
main{max-width:1060px;margin:0 auto;padding:18px 16px 40px}
.acc{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between;margin:6px 0 18px}
.tabs{display:flex;gap:.4rem;flex-wrap:wrap}
.tabs a,.btn{display:inline-flex;align-items:center;gap:.35rem;text-decoration:none;border:2px solid var(--g);border-radius:999px;padding:.45rem 1rem;font:600 .9rem Spectral,Georgia,serif;color:var(--g);background:#fff;cursor:pointer}
.tabs a.on,.btn.on{background:var(--g);color:#fff}
.btn.wa{background:#1f8f4e;border-color:#1f8f4e;color:#fff}
.recibir{background:#fff;border:1px solid var(--l);border-top:4px solid var(--g);padding:16px 18px;margin:0 0 22px;display:flex;flex-wrap:wrap;gap:.8rem;align-items:center}
.recibir b{font-size:1.15rem;flex:1 1 260px}.recibir b small{display:block;font-weight:400;color:var(--s);font-size:.92rem}
.recibir form{display:flex;gap:.5rem;flex:1 1 320px}.recibir input{flex:1;min-width:0;padding:.6rem .8rem;border:1px solid #d8c9b2;font:1rem Spectral,Georgia,serif;border-radius:3px}
.recibir .msg{flex-basis:100%;margin:0;font-weight:600}
h2.dia{margin:26px 0 12px;font-size:.95rem;letter-spacing:.3em;text-transform:uppercase;color:var(--g);border-bottom:2px solid var(--g);padding-bottom:6px}
.rej{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.f{background:#fff;border:1px solid var(--l);display:flex;flex-direction:column}
.f .im{aspect-ratio:16/9;background:#efe6d6 center/cover no-repeat}
.f .tx{padding:12px 14px 14px;display:flex;flex-direction:column;gap:.35rem;flex:1}
.f .tm{font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;color:var(--g);font-weight:600}
.f h3{margin:0;font-size:1.12rem;line-height:1.25}
.f p{margin:0;color:var(--s);font-size:.95rem;line-height:1.45}
.f .md{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:auto;padding-top:.5rem}
.f .md a{background:var(--g);color:#fff;text-decoration:none;font-weight:600;font-size:.82rem;padding:.35rem .8rem;border-radius:999px}
.f.top{grid-column:1/-1;flex-direction:row}.f.top .im{flex:0 0 46%;aspect-ratio:auto;min-height:240px}.f.top h3{font-size:1.5rem}
footer{text-align:center;padding:26px 16px 40px;color:var(--s);font-size:.9rem;border-top:1px solid var(--l)}
footer a{color:var(--g);font-weight:600}
@media (max-width:640px){.f.top{flex-direction:column}.f.top .im{min-height:0;aspect-ratio:16/9}.f.top h3{font-size:1.25rem}.recibir form{flex-direction:column}}
</style>
</head><body>
<header class="cab">
  <a href="https://boletin.movimientopatriam.es/"><img src="/logo.png" alt="PATRIAM"></a>
  <small>CLIPPING DIARIO</small>
  <h1>ESPAÑA AL DÍA</h1>
  <p>Noticias políticas y económicas de España, con toda la semana.</p>
  <?php if (!empty($d['actualizado'])): ?><p class="act">Actualizado: <?= e(mb_strtolower(dia($d['actualizado']))) ?> a las <?= e(hora($d['actualizado'])) ?></p><?php endif; ?>
</header>
<main>
  <div class="acc">
    <nav class="tabs">
      <a href="<?= URL_MP ?>" class="<?= $filtro === '' ? 'on' : '' ?>">Todo</a>
      <a href="<?= URL_MP ?>?t=politica" class="<?= $filtro === 'politica' ? 'on' : '' ?>">Política</a>
      <a href="<?= URL_MP ?>?t=economia" class="<?= $filtro === 'economia' ? 'on' : '' ?>">Economía</a>
    </nav>
    <span class="tabs"><a class="btn wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Compartir por WhatsApp</a><button class="btn" type="button" id="copiar">Copiar enlace</button></span>
  </div>
  <section class="recibir" id="recibir">
    <b>Recíbelo cada mañana en tu correo<small>Gratis. Un correo al día con lo más importante. Te das de baja cuando quieras.</small></b>
    <form id="alta"><input type="email" name="email" required placeholder="Tu correo electrónico" autocomplete="email"><button class="btn on">Quiero recibirlo</button></form>
    <p class="msg" id="msg" hidden></p>
  </section>
<?php
if (!$g) echo '<p>Todavía no hay noticias. Se cargan solas varias veces al día.</p>';
$actual = ''; $primera = true;
foreach ($g as $x) {
  $dd = dia($x['fecha']);
  if ($dd !== $actual) { if ($actual) echo '</div>'; $actual = $dd; echo '<h2 class="dia">' . e($dd) . '</h2><div class="rej">'; }
  $im = preg_match('#^https?://#', $x['imagen'] ?? '') ? ' style="background-image:url(\'' . e($x['imagen']) . '\')"' : '';
  echo '<article class="f' . ($primera ? ' top' : '') . '"><div class="im"' . $im . '></div><div class="tx"><span class="tm">' . e($x['tema'] ?? 'Política') . ' · ' . e(hora($x['fecha'])) . '</span><h3>' . e($x['titulo']) . '</h3>'
    . (!empty($x['resumen']) ? '<p>' . e($x['resumen']) . '</p>' : '')
    . '<div class="md">' . implode('', array_map(fn($m) => '<a href="' . e($m['url']) . '" target="_blank" rel="noopener">' . e($m['medio']) . ' →</a>', $x['medios'] ?? [])) . '</div></div></article>';
  $primera = false;
}
if ($actual) echo '</div>';
?>
</main>
<footer>Una iniciativa de <a href="https://movimientopatriam.es/">PATRIAM</a> · Dios · Patria · Justicia · <a href="https://boletin.movimientopatriam.es/">Lee también el boletín semanal</a></footer>
<script>
document.getElementById('copiar').onclick = function () { var b = this; (navigator.clipboard ? navigator.clipboard.writeText('<?= URL_MP ?>') : Promise.reject()).then(function () { b.textContent = '¡Copiado!'; }, function () { prompt('Copia el enlace:', '<?= URL_MP ?>'); }); };
document.getElementById('alta').onsubmit = async function (ev) {
  ev.preventDefault(); var m = document.getElementById('msg'), bt = this.querySelector('button'); bt.disabled = true; m.hidden = false; m.style.color = '#6b5d55'; m.textContent = 'Enviando…';
  try {
    var r = await fetch('<?= URL_MP ?>?accion=alta', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: this.email.value }) });
    var j = await r.json();
    if (!r.ok) throw new Error(j.error || 'Error');
    m.style.color = '#2f6b3a'; m.textContent = j.ya ? 'Ya estabas apuntado: lo recibes cada mañana.' : 'Te hemos mandado un correo: pulsa el botón que trae para confirmar y empezarás a recibirlo.';
  } catch (e) { m.style.color = '#b00020'; m.textContent = e.message; bt.disabled = false; }
};
</script>
</body></html>
