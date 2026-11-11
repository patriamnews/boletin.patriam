<?php
/* Envío automático del boletín quincenal (lo lanza revisar.php, el cron de Hostinger, cada hora).
   - El día que sale cada boletín (su fecha en Publicar), a partir de las 8:00, se manda una vez:
       · por correo (Brevo) a cada suscriptor activo del boletín (boletin-suscriptores.json);
       · por WhatsApp a cada teléfono de la lista, SOLO si está puesta la API de WhatsApp Business (claves de envío).
         Sin la API, se mandan a mano desde Publicar › Boletín › Teléfonos WhatsApp (un botón por persona).
   - Solo entra lo aprobado. Lo enviado queda apuntado en boletin-envios.json y no se repite. */
require_once __DIR__ . '/_privado.php';
require_once __DIR__ . '/carlos.php';   // comLeerTodo, cBrevo, cWhatsappApi, cWhatsapp, cMovil, cNombre

const BE_WEB = 'https://boletin.movimientopatriam.es';
const BE_HORA = 8;

function beLeer() { $f = privado() . '/boletin-envios.json'; return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function beGuardar($d) { $f = privado() . '/boletin-envios.json'; file_put_contents($f . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($f . '.tmp', $f); @chmod($f, 0600); }

/* Lo aprobado del nº N: lo subido desde Publicar y lo preparado (importar.js) que alguien ha aprobado */
function bePiezas($num) {
  $ap = is_file(privado() . '/aportaciones.json') ? (json_decode(file_get_contents(privado() . '/aportaciones.json'), true) ?: []) : [];
  $res = [];
  foreach ($ap['items'] ?? [] as $x) if (($x['estado'] ?? '') === 'aprobado' && (int)($x['numero'] ?? 0) === (int)$num && in_array($x['tipo'], ['firma', 'noticia', 'recomendacion', 'editorial'], true)) $res[] = [$x['tipo'], $x['datos']];
  $t = @file_get_contents(__DIR__ . '/importar.js') ?: '';
  foreach (['PATRIAM_IMPORTAR' => 'firma', 'PATRIAM_RECOMENDAR' => 'recomendacion'] as $n => $tipo) {
    if (!preg_match('/window\.' . $n . '\s*=\s*(\[.*?\n\]);/s', $t, $m)) continue;
    foreach (json_decode($m[1], true) ?: [] as $x) {
      $id = ($tipo === 'firma' ? 'imp-' : 'rec-') . ($x['id'] ?? ''); $c = $ap['cambios'][$id] ?? [];
      if ((int)($x['numero'] ?? 0) !== (int)$num || empty($c['aprobado']) || !empty($c['quitado'])) continue;
      $res[] = [$tipo, array_merge($x, $c['datos'] ?? [])];
    }
  }
  return $res;
}
function beCorreo($num, $fecha, $piezas, $nombre) {
  $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
  $url = BE_WEB . '/?n=' . $num;
  $html = '<div style="background:#faf5ec;padding:24px 12px;font-family:Georgia,serif"><div style="max-width:620px;margin:0 auto;background:#fffdf9;border-top:6px solid #7b1c2c;padding:26px;color:#1d1719">'
    . '<img src="' . BE_WEB . '/logo.png" alt="PATRIAM" style="height:44px"><p style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#7b1c2c;margin:16px 0 4px">Boletín nº ' . $e($num) . ' · ' . $e($fecha) . '</p>'
    . '<h1 style="font-size:26px;margin:0 0 6px">Lo que hay que saber</h1><p style="margin:0 0 18px;color:#6b5d55;font-style:italic">con criterio</p>'
    . '<p style="font-size:17px">Buenos días' . ($nombre ? ', ' . $e($nombre) : '') . ':</p><p style="font-size:17px">Ya tienes el nuevo boletín de PATRIAM. En este número:</p>';
  $txt = "Buenos días" . ($nombre ? ", $nombre" : '') . ":\n\nYa tienes el boletín nº $num de PATRIAM, «Lo que hay que saber: con criterio». En este número:\n\n";
  foreach ($piezas as [$tipo, $d]) {
    $tit = $d['titulo'] ?? ($d['titular'] ?? ''); if ($tit === '') continue;
    $quien = $d['autor'] ?? ($d['comenta'] ?? ($d['firma'] ?? ''));
    $html .= '<div style="border-top:1px solid #eadfcb;padding:12px 0">' . (!empty($d['imagen']) ? '<img src="' . $e($d['imagen']) . '" alt="" style="width:100%;max-height:260px;object-fit:cover;margin-bottom:8px">' : '')
      . '<b style="font-size:18px">' . $e($tit) . '</b>' . ($quien ? '<br><span style="color:#7b1c2c;font-size:14px">' . $e($quien) . '</span>' : '')
      . (!empty($d['entradilla']) ? '<p style="margin:6px 0 0">' . $e($d['entradilla']) . '</p>' : '') . '</div>';
    $txt .= "· $tit" . ($quien ? " ($quien)" : '') . "\n";
  }
  $html .= '<p style="text-align:center;margin:24px 0"><a href="' . $e($url) . '" style="background:#7b1c2c;color:#fff;padding:12px 26px;border-radius:24px;text-decoration:none;font-family:Arial,sans-serif">Leer el boletín</a></p>'
    . '<p style="font-size:12px;color:#8a7a6e;text-align:center">Dios · Patria · Justicia<br>Si no quieres recibirlo más, <a href="' . BE_WEB . '/#/baja" style="color:#8a7a6e">date de baja aquí</a>.</p></div></div>';
  $txt .= "\nLéelo aquí: $url\n\nDios · Patria · Justicia\nPara darte de baja: " . BE_WEB . "/#/baja";
  return [$html, $txt];
}

function boletinRevisar($salida = false) {
  $z = new DateTimeZone('Europe/Madrid'); $ahora = new DateTime('now', $z); $hoy = $ahora->format('Y-m-d');
  if ((int)$ahora->format('G') < BE_HORA) return;
  $ap = is_file(privado() . '/aportaciones.json') ? (json_decode(file_get_contents(privado() . '/aportaciones.json'), true) ?: []) : [];
  $fp = fopen(privado() . '/boletin-envios.lock', 'c'); flock($fp, LOCK_EX);
  $env = beLeer(); $nc = 0; $nw = 0;
  foreach ($ap['boletines'] ?? [] as $b) {
    if (($b['fecha'] ?? '') !== $hoy) continue;
    $num = (string)(int)$b['numero']; $piezas = bePiezas($num);
    if (!$piezas) { if ($salida) echo "Boletín nº $num: no hay nada aprobado, no se manda\n"; continue; }
    $r = &$env[$num]; $r = ($r ?? []) + ['correo' => [], 'whatsapp' => [], 'errores' => []];
    $fechaL = $ahora->format('j') . ' de ' . ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][(int)$ahora->format('n')];
    // correo
    foreach (susLeer() as $email => $s) {
      if (($s['estado'] ?? '') !== 'activo' || isset($r['correo'][$email])) continue;
      $nom = preg_split('/\s+/', trim((string)($s['nombre'] ?? '')))[0] ?? '';
      [$html, $txt] = beCorreo($num, $fechaL, $piezas, $nom);
      [$ok, $m] = cBrevo($email, $s['nombre'] ?? '', 'Boletín nº ' . $num . ' · Lo que hay que saber', $html, $txt, 'boletin-quincenal');
      if ($ok) { $r['correo'][$email] = gmdate('c'); $nc++; } else $r['errores'][] = ['fecha' => gmdate('c'), 'a' => $email, 'motivo' => mb_substr($m, 0, 160)];
    }
    // WhatsApp, solo con la API de WhatsApp Business
    if ($k = cWhatsappApi()) {
      $tel = is_file(privado() . '/telefonos.json') ? (json_decode(file_get_contents(privado() . '/telefonos.json'), true) ?: []) : [];
      foreach (comLeerTodo()['contactos'] ?? [] as $c) {
        $mv = cMovil($c['movil'] ?? ''); if (!$mv || !empty($c['noWa']) || isset($r['whatsapp'][$mv])) continue;
        [$ok, $m] = cWhatsapp($k, $mv, cNombre($c), "Ya tienes el boletín nº $num de PATRIAM, «Lo que hay que saber: con criterio».\n\n" . BE_WEB . '/?n=' . $num);
        if ($ok) { $r['whatsapp'][$mv] = gmdate('c'); $tel['envios'][$num][$c['id'] ?? $mv] = gmdate('c'); $nw++; }
        else $r['errores'][] = ['fecha' => gmdate('c'), 'a' => '+' . $mv, 'motivo' => mb_substr($m, 0, 160)];
      }
      file_put_contents(privado() . '/telefonos.json', json_encode($tel + ['envios' => [], 'texto' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    $r['errores'] = array_slice($r['errores'], -50);
    unset($r);
  }
  beGuardar($env);
  flock($fp, LOCK_UN); fclose($fp);
  if ($salida) echo "Boletín: $nc correos y $nw WhatsApp enviados\n";
}
