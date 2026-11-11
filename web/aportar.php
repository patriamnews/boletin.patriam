<?php
/* «Publicar» (publicar.movimientopatriam.es) → boletín, paso a paso y sin el editor.
   El equipo entra con su usuario de la web. Lo que sube va al boletín abierto:
   noticias, artículos de firma, recomendaciones y la editorial.
   Lo de quien aprueba (Carlos, Isabel) sale directamente; lo de los demás espera su visto bueno.
   El boletín (index.html) y el editor lo leen de /aportaciones.js (este mismo archivo, accion=js).
   Datos en la carpeta privada del servidor: aportaciones.json */
require __DIR__ . '/_privado.php';
permitirPublicar();
$acc = $_GET['accion'] ?? '';
$FICHERO = privado() . '/aportaciones.json';
$zona = new DateTimeZone('Europe/Madrid');
$hoy = (new DateTime('now', $zona))->format('Y-m-d');

function leerAp($f) { $d = is_file($f) ? json_decode(file_get_contents($f), true) : null; return is_array($d) ? $d + ['boletines' => [], 'items' => []] : ['boletines' => [], 'items' => []]; }
function guardarAp($f, $d) { $t = $f . '.tmp'; file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($t, $f); }
function limpio($t, $n = 20000) { return mb_substr(trim(str_replace("\r", '', (string)$t)), 0, $n); }
function url_ok($u) { $u = trim((string)$u); return preg_match('#^https?://#i', $u) ? mb_substr($u, 0, 1000) : ''; }

/* ---------- público: lo aprobado y los boletines abiertos, para el boletín ---------- */
if ($acc === 'js') {
  header('Content-Type: application/javascript; charset=utf-8');
  header('Cache-Control: no-cache, max-age=0');
  $d = leerAp($FICHERO);
  $items = array_values(array_filter($d['items'], fn($x) => ($x['estado'] ?? '') === 'aprobado'));
  $items = array_map(fn($x) => ['id' => $x['id'], 'tipo' => $x['tipo'], 'numero' => $x['numero'], 'datos' => $x['datos']], $items);
  echo 'window.PATRIAM_APORTACIONES = ' . json_encode(['boletines' => $d['boletines'], 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
  exit;
}

$yo = comprobarEquipoWeb();
$d = leerAp($FICHERO);

/* boletines que ya existen en el boletín (Netlify) + los abiertos desde aquí */
function boletinesTodos($d) {
  $lista = [];
  [$c, $js] = pedir(origenBoletin() . '/boletines.js', ['Accept: application/javascript']);
  if ($c === 200 && preg_match('/window\.BOLETINES\s*=\s*(\[.*\]);?\s*$/s', $js, $m)) {
    foreach (json_decode(rtrim($m[1], "; \n"), true) ?: [] as $b) $lista[(int)$b['numero']] = ['numero' => (int)$b['numero'], 'fecha' => substr($b['fecha'] ?? '', 0, 10), 'origen' => 'editor'];
  }
  foreach ($d['boletines'] as $b) if (empty($lista[(int)$b['numero']])) $lista[(int)$b['numero']] = ['numero' => (int)$b['numero'], 'fecha' => $b['fecha'], 'origen' => 'publicar'];
  ksort($lista);
  return array_values($lista);
}
function estado($d, $hoy, $yo) {
  $bs = boletinesTodos($d);
  $abierto = null; $ultimo = null;
  foreach ($bs as $b) { if ($b['fecha'] >= $hoy && !$abierto) $abierto = $b; if ($b['fecha'] < $hoy) $ultimo = $b; }
  $max = $bs ? end($bs) : ['numero' => 0, 'fecha' => $hoy];
  // el siguiente sale el viernes siguiente al último (o el próximo viernes si no hay ninguno)
  $base = new DateTime(max($max['fecha'] ?: $hoy, $hoy) . ' 12:00', new DateTimeZone('Europe/Madrid'));
  $sig = clone $base; $sig->modify(($max['fecha'] ?? '') >= $hoy ? '+7 days' : 'next friday');
  $siguiente = ['numero' => $max['numero'] + 1, 'fecha' => $sig->format('Y-m-d')];
  $num = $abierto ? $abierto['numero'] : null;
  $items = array_values(array_filter($d['items'], fn($x) => ($x['estado'] ?? '') !== 'borrado' && ($num === null || $x['numero'] === $num || ($x['estado'] ?? '') === 'pendiente')));
  $items = array_map(fn($x) => $x + ['mio' => ($x['autor_id'] ?? '') === $yo['id']], $items);
  return ['hoy' => $hoy, 'ultimo' => $ultimo, 'abierto' => $abierto, 'siguiente' => $siguiente, 'items' => $items, 'puedeAprobar' => $yo['puedeAprobar'], 'yo' => $yo['nombre'] ?? ''];
}

$cuerpo = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

switch ($acc) {
  case 'estado':
    salir(estado($d, $hoy, $yo));

  case 'abrir': {
    $e = estado($d, $hoy, $yo);
    if ($e['abierto']) salir(['error' => 'Ya está abierto el boletín nº ' . $e['abierto']['numero'] . '. Se puede abrir el siguiente cuando salga.'], 409);
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cuerpo['fecha'] ?? '') && $cuerpo['fecha'] >= $hoy ? $cuerpo['fecha'] : $e['siguiente']['fecha'];
    $d['boletines'][] = ['numero' => $e['siguiente']['numero'], 'fecha' => $fecha, 'abierto_por' => $yo['nombre'] ?? '', 'abierto_el' => gmdate('c')];
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo));
  }

  case 'subir': {
    $e = estado($d, $hoy, $yo);
    if (!$e['abierto']) salir(['error' => 'Primero hay que abrir el boletín de esta semana.'], 409);
    $tipo = $cuerpo['tipo'] ?? '';
    $x = $cuerpo['datos'] ?? [];
    $sec = in_array($x['seccion'] ?? '', ['Actualidad', 'Historia', 'Sociedad', 'Análisis'], true) ? $x['seccion'] : 'Actualidad';
    if ($tipo === 'noticia') {
      $datos = ['titular' => limpio($x['titular'] ?? '', 300), 'url' => url_ok($x['url'] ?? ''), 'medio' => limpio($x['medio'] ?? '', 80), 'imagen' => url_ok($x['imagen'] ?? ''),
        'resumen' => limpio($x['resumen'] ?? '', 2000), 'comentario' => limpio($x['comentario'] ?? '', 2000), 'comenta' => limpio($x['firma'] ?? '', 80), 'seccion' => $sec, 'formato' => 'Noticia'];
      $datos['enlaces'] = array_values(array_filter(array_map(fn($e) => ['medio' => limpio($e['medio'] ?? '', 80), 'url' => url_ok($e['url'] ?? '')], array_slice((array)($x['enlaces'] ?? []), 0, 8)), fn($e) => $e['url']));
      if (!$datos['enlaces']) unset($datos['enlaces']);
      if (!$datos['titular'] || !$datos['url']) salir(['error' => 'Faltan el titular o el enlace de la noticia.'], 400);
    } elseif ($tipo === 'firma') {
      $datos = ['titulo' => limpio($x['titulo'] ?? '', 300), 'entradilla' => limpio($x['entradilla'] ?? '', 600), 'texto' => limpio($x['texto'] ?? '', 60000), 'imagen' => url_ok($x['imagen'] ?? ''),
        'autor' => limpio($x['firma'] ?? '', 80), 'cargo' => limpio($x['cargo'] ?? '', 120), 'seccion' => $sec, 'tema' => limpio($x['tema'] ?? '', 60)];
      if (!$datos['titulo'] || !$datos['texto'] || !$datos['autor']) salir(['error' => 'Faltan el título, el texto o la firma.'], 400);
    } elseif ($tipo === 'recomendacion') {
      $t = in_array($x['tipo'] ?? '', ['Podcast', 'Serie de podcast', 'Vídeo', 'Documental', 'Libro', 'Película'], true) ? $x['tipo'] : 'Libro';
      $datos = ['tipo' => $t, 'titulo' => limpio($x['titulo'] ?? '', 300), 'url' => url_ok($x['url'] ?? ''), 'imagen' => url_ok($x['imagen'] ?? ''), 'medio' => limpio($x['medio'] ?? '', 160),
        'descripcion' => limpio($x['descripcion'] ?? '', 3000), 'comentario' => limpio($x['comentario'] ?? '', 3000), 'critica' => limpio($x['critica'] ?? '', 40000), 'comenta' => limpio($x['firma'] ?? '', 80), 'seccion' => $sec];
      if (!$datos['titulo'] || !$datos['comentario']) salir(['error' => 'Faltan el título o el comentario.'], 400);
    } elseif ($tipo === 'editorial') {
      $datos = ['titulo' => limpio($x['titulo'] ?? '', 300), 'texto' => limpio($x['texto'] ?? '', 40000), 'firma' => limpio($x['firma'] ?? '', 80), 'imagen' => url_ok($x['imagen'] ?? '')];
      if (!$datos['titulo'] || !$datos['texto']) salir(['error' => 'Faltan el título o el texto de la editorial.'], 400);
    } else salir(['error' => 'Tipo desconocido.'], 400);
    $id = 'ap-' . bin2hex(random_bytes(5));
    $d['items'][] = ['id' => $id, 'tipo' => $tipo, 'numero' => $e['abierto']['numero'], 'datos' => $datos, 'estado' => $yo['puedeAprobar'] ? 'aprobado' : 'pendiente',
      'autor' => $yo['nombre'] ?? '', 'autor_id' => $yo['id'], 'creado' => gmdate('c'), 'aprobado_por' => $yo['puedeAprobar'] ? ($yo['nombre'] ?? '') : ''];
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo) + ['nuevo' => $id]);
  }

  case 'aprobar': case 'rechazar': case 'borrar': {
    $id = $cuerpo['id'] ?? '';
    if ($acc !== 'borrar' && !$yo['puedeAprobar']) salir(['error' => 'Solo pueden aprobar Carlos e Isabel.'], 403);
    foreach ($d['items'] as &$x) {
      if ($x['id'] !== $id) continue;
      if ($acc === 'borrar') { if (!$yo['puedeAprobar'] && ($x['autor_id'] ?? '') !== $yo['id']) salir(['error' => 'Solo puede quitarlo quien lo subió o quien aprueba.'], 403); $x['estado'] = 'borrado'; }
      else { if (!$yo['puedeAprobar']) salir(['error' => 'Solo pueden aprobar Carlos e Isabel.'], 403); $x['estado'] = $acc === 'aprobar' ? 'aprobado' : 'rechazado'; $x['aprobado_por'] = $yo['nombre'] ?? ''; }
      $x['cambiado'] = gmdate('c');
    }
    unset($x);
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo));
  }

  case 'sugerencias': {
    // Las noticias de la semana de «España al día» (las recoge solo scripts/espana-al-dia.mjs) para meterlas con un clic
    $t = @file_get_contents(__DIR__ . '/espana-al-dia.js') ?: '';
    $j = preg_match('/=\s*(\{.*\})\s*;?\s*$/s', $t, $m) ? json_decode($m[1], true) : null;
    $g = array_slice($j['grupos'] ?? [], 0, 150);
    usort($g, fn($a, $b) => (count($b['medios'] ?? []) <=> count($a['medios'] ?? [])) ?: strcmp($b['fecha'] ?? '', $a['fecha'] ?? ''));
    salir(['actualizado' => $j['actualizado'] ?? '', 'grupos' => array_values($g)]);
  }

  case 'enlace': {
    // Rellena solo: titular, resumen, imagen y medio a partir del enlace de la noticia
    $u = url_ok($cuerpo['url'] ?? '');
    if (!$u) salir(['error' => 'Enlace no válido.'], 400);
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PatriamBoletin/1.0)', CURLOPT_ENCODING => '']);
    $h = (string)curl_exec($ch); curl_close($ch);
    $meta = function ($p) use ($h) {
      if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($p, '#') . '["\'][^>]*content=["\']([^"\']*)#i', $h, $m) || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . preg_quote($p, '#') . '["\']#i', $h, $m)) return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      return '';
    };
    $titulo = $meta('og:title') ?: (preg_match('#<title[^>]*>(.*?)</title>#is', $h, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '');
    $host = preg_replace('/^www\./', '', parse_url($u, PHP_URL_HOST) ?: '');
    salir(['titular' => trim($titulo), 'resumen' => trim($meta('og:description') ?: $meta('description')), 'imagen' => url_ok($meta('og:image') ?: $meta('twitter:image')), 'medio' => trim($meta('og:site_name')) ?: $host]);
  }
}
salir(['error' => 'Acción desconocida.'], 400);
