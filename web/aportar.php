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
  $items = array_map(fn($x) => ['id' => $x['id'], 'tipo' => $x['tipo'], 'numero' => $x['numero'], 'datos' => array_diff_key($x['datos'], ['cv' => 1]), 'creado' => $x['creado'] ?? ''], $items);
  // Cambios a lo preparado en importar.js: datos aprobados y piezas quitadas (lo pendiente no sale)
  $cambios = [];
  foreach ($d['cambios'] ?? [] as $id => $c) { $o = []; if (!empty($c['datos']) && !empty($c['aprobado'])) $o['datos'] = $c['datos']; if (!empty($c['quitado'])) $o['quitado'] = true; if ($o) $cambios[$id] = $o; }
  // Lo preparado para un boletín que todavía no ha salido no se ve hasta que alguien lo aprueba
  foreach ($d['boletines'] as $b) if (($b['fecha'] ?? '') >= $hoy) foreach (piezasImportar() as $x)
    if ((int)($x['numero'] ?? 0) === (int)$b['numero'] && empty($d['cambios'][$x['id']]['aprobado'])) $cambios[$x['id']] = ['quitado' => true, 'sinAprobar' => true];
  echo 'window.PATRIAM_APORTACIONES = ' . json_encode(['boletines' => $d['boletines'], 'items' => $items, 'cambios' => (object)$cambios], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
  exit;
}

/* ---------- artículos que llegan por correo (hola@movimientopatriam.com, asunto «ARTICULO Nombre») ---------- */
require __DIR__ . '/_correo.php';
function numeroDestino($d, $hoy) {
  $bs = boletinesTodos($d); $max = 0;
  foreach ($bs as $b) { if ($b['fecha'] >= $hoy) return (int)$b['numero']; $max = max($max, (int)$b['numero']); }
  return $max + 1;   // aún no está abierto: entra en el siguiente, y se ve en cuanto se abra
}
function revisarCorreo($FICHERO, $hoy, $forzar = false) {
  return revisarCorreoArticulos(function ($datos, $info) use ($FICHERO, $hoy) {
    $d = leerAp($FICHERO);
    $id = 'ap-' . bin2hex(random_bytes(5));
    $d['items'][] = ['id' => $id, 'tipo' => 'firma', 'numero' => numeroDestino($d, $hoy), 'datos' => $datos, 'estado' => 'pendiente',
      'autor' => 'Correo · ' . ($datos['autor'] ?? ''), 'autor_id' => 'correo', 'origen' => 'correo', 'remite' => $info['remite'], 'asunto' => $info['asunto'],
      'creado' => gmdate('c'), 'aprobado_por' => ''];
    guardarAp($FICHERO, $d);
    return $id;
  }, $forzar);
}
/* Artículos ya publicados (o aprobados) que todavía no tienen imagen: los lee cada mañana la tarea que crea las ilustraciones.
   Solo lo que ya es público (aprobado o del editor); lo pendiente no sale aquí. */
if ($acc === 'sin-imagen') {
  header('Access-Control-Allow-Origin: *');
  $auto = @file_get_contents(__DIR__ . '/imagenes-auto.js') ?: '';
  $hechas = preg_match('/=\s*(\{.*\})\s*;?\s*$/s', $auto, $m) ? (json_decode($m[1], true) ?: []) : [];
  $res = [];
  $corto = fn($t, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace(['**', '*', '## ', '> '], '', (string)$t))), 0, $n);
  $mete = function ($id, $c, $num) use (&$res, $hechas, $corto) {
    if (!$id || !empty($c['imagen']) || isset($hechas[$id]) || empty($c['titulo'])) return;
    $res[$id] = ['id' => $id, 'numero' => $num, 'titulo' => $c['titulo'], 'autor' => $c['autor'] ?? '', 'seccion' => $c['seccion'] ?? '',
      'entradilla' => $corto($c['entradilla'] ?? '', 400), 'texto' => $corto($c['texto'] ?? '', 1800)];
  };
  foreach (leerAp($FICHERO)['items'] as $x) if (($x['estado'] ?? '') === 'aprobado' && ($x['tipo'] ?? '') === 'firma') $mete($x['id'], $x['datos'], $x['numero']);
  [$c, $js] = pedir(origenBoletin() . '/boletines.js', ['Accept: application/javascript']);
  if ($c === 200 && preg_match('/window\.BOLETINES\s*=\s*(\[.*\]);?\s*$/s', $js, $m))
    foreach (json_decode(rtrim($m[1], "; \n"), true) ?: [] as $b) foreach ($b['colaboraciones'] ?? [] as $col) $mete($col['id'] ?? '', $col, $b['numero'] ?? null);
  salir(['articulos' => array_values($res)]);
}

// Lo llama el propio boletín al abrirse (y puede llamarlo una tarea programada de Hostinger): como mucho una vez cada 5 minutos
if ($acc === 'correo-auto') { $r = revisarCorreo($FICHERO, $hoy); salir(['ok' => $r['ok'], 'nuevos' => count($r['nuevos'] ?? [])]); }

$yo = comprobarEquipoWeb();
if ($acc === 'estado') revisarCorreo($FICHERO, $hoy);
if ($acc === 'correo') { if (!$yo['puedeAprobar']) salir(['error' => 'Solo pueden revisar el correo Carlos e Isabel.'], 403); salir(revisarCorreo($FICHERO, $hoy, true)); }
$d = leerAp($FICHERO);

/* boletines que ya existen en el boletín (Netlify) + los abiertos desde aquí */
function boletinesTodos($d) {
  $lista = [];
  [$c, $js] = pedir(origenBoletin() . '/boletines.js', ['Accept: application/javascript']);
  if ($c === 200 && preg_match('/window\.BOLETINES\s*=\s*(\[.*\]);?\s*$/s', $js, $m)) {
    foreach (json_decode(rtrim($m[1], "; \n"), true) ?: [] as $b) {
      $lista[(int)$b['numero']] = ['numero' => (int)$b['numero'], 'fecha' => substr($b['fecha'] ?? '', 0, 10), 'origen' => 'editor'];
      $GLOBALS['BOLETIN_EDITOR'][(int)$b['numero']] = $b;   // contenido completo (para «Lo que ya hay»)
    }
  }
  foreach ($d['boletines'] as $b) if (empty($lista[(int)$b['numero']])) $lista[(int)$b['numero']] = ['numero' => (int)$b['numero'], 'fecha' => $b['fecha'], 'origen' => 'publicar'];
  ksort($lista);
  return array_values($lista);
}
/* Lo que tiene el boletín nº N en el editor (lo aprobado): para enseñarlo junto a lo subido desde Publicar */
function contenidoEditor($num) {
  $b = $GLOBALS['BOLETIN_EDITOR'][$num] ?? null;
  if (!$b) return ['aprobado' => false, 'items' => []];
  $t = fn($x) => trim((string)$x);
  $res = [];
  if (is_array($b['editorial'] ?? null) && $t($b['editorial']['titulo'] ?? '')) $res[] = ['tipo' => 'Editorial', 'titulo' => $t($b['editorial']['titulo']), 'firma' => $t($b['editorial']['firma'] ?? ($b['editorial']['autor'] ?? ''))];
  foreach ((array)($b['noticias'] ?? []) as $x) if ($t($x['titular'] ?? '')) $res[] = ['tipo' => $t($x['seccion'] ?? '') ?: 'Noticia', 'titulo' => $t($x['titular']), 'firma' => $t($x['comenta'] ?? ''), 'medio' => $t($x['medio'] ?? '')];
  foreach ((array)($b['colaboraciones'] ?? []) as $x) if ($t($x['titulo'] ?? '')) $res[] = ['tipo' => 'Colaboración' . ($t($x['seccion'] ?? '') ? ' · ' . $t($x['seccion']) : ''), 'titulo' => $t($x['titulo']), 'firma' => $t($x['autor'] ?? '')];
  foreach ((array)($b['recomendados'] ?? []) as $x) if (is_array($x) && $t($x['titulo'] ?? '')) $res[] = ['tipo' => $t($x['tipo'] ?? '') ?: 'Recomendación', 'titulo' => $t($x['titulo']), 'firma' => $t($x['autor'] ?? '')];
  foreach ((array)($b['eventos'] ?? []) as $x) if ($t($x['titulo'] ?? '')) $res[] = ['tipo' => 'Agenda', 'titulo' => $t($x['titulo']), 'firma' => $t($x['fecha'] ?? '')];
  return ['aprobado' => true, 'items' => $res];
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
  $items = array_map(fn($x) => array_merge($x, ['datos' => array_diff_key($x['datos'] ?? [], ['cv' => 1]), 'tieneCv' => !empty($x['datos']['cv'])]) + ['mio' => ($x['autor_id'] ?? '') === $yo['id']], $items);
  if ($num) foreach (preparados($d, $num) as $x) $items[] = $x;
  return ['delEditor' => $num ? contenidoEditor($num) : null, 'hoy' => $hoy, 'ultimo' => $ultimo, 'abierto' => $abierto, 'siguiente' => $siguiente, 'items' => $items, 'puedeAprobar' => $yo['puedeAprobar'], 'yo' => $yo['nombre'] ?? ''];
}

$cuerpo = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

/* Lo preparado en importar.js para el nº N, con los cambios hechos desde Publicar, como un elemento más */
function preparados($d, $num) {
  $res = [];
  foreach (piezasImportar() as $x) {
    if ((int)($x['numero'] ?? 0) !== (int)$num) continue;
    $c = $d['cambios'][$x['id']] ?? [];
    if (!empty($c['quitado'])) continue;
    $datos = $c['datos'] ?? $x['datos'];
    $res[] = ['id' => $x['id'], 'tipo' => $x['tipo'], 'numero' => (int)$num, 'datos' => !empty($c['propuesta']) ? $c['propuesta'] : $datos,
      'estado' => (!empty($c['propuesta']) || empty($c['aprobado'])) ? 'pendiente' : 'aprobado', 'origen' => 'preparado', 'autor' => $datos['autor'] ?? ($datos['comenta'] ?? ''),
      'mio' => true, 'propuestaDe' => $c['propuestaDe'] ?? ''];
  }
  return $res;
}
function esPreparado($id) { return (bool)preg_match('/^(imp|rec)-/', (string)$id); }
function piezaPreparada($id) { foreach (piezasImportar() as $x) if ($x['id'] === $id) return $x; return null; }
/* Los datos de un formulario de Publicar, limpios según el tipo (igual que al subir) */
function datosLimpios($tipo, $x, $antes = []) {
  $t = fn($k, $n) => limpio($x[$k] ?? ($antes[$k] ?? ''), $n);
  $sec = in_array($x['seccion'] ?? ($antes['seccion'] ?? ''), ['Actualidad', 'Historia', 'Sociedad', 'Análisis'], true) ? ($x['seccion'] ?? $antes['seccion']) : ($antes['seccion'] ?? 'Actualidad');
  $u = fn($k) => url_ok($x[$k] ?? ($antes[$k] ?? ''));
  if ($tipo === 'noticia') return array_merge($antes, ['titular' => $t('titular', 300), 'url' => $u('url'), 'medio' => $t('medio', 80), 'imagen' => $u('imagen'), 'resumen' => $t('resumen', 2000), 'comentario' => $t('comentario', 2000), 'comenta' => limpio($x['firma'] ?? ($x['comenta'] ?? ($antes['comenta'] ?? '')), 80), 'seccion' => $sec]);
  if ($tipo === 'firma') return array_merge($antes, ['titulo' => $t('titulo', 300), 'entradilla' => $t('entradilla', 600), 'texto' => $t('texto', 60000), 'imagen' => $u('imagen'), 'autor' => limpio($x['firma'] ?? ($x['autor'] ?? ($antes['autor'] ?? '')), 80), 'cargo' => $t('cargo', 120), 'seccion' => $sec, 'tema' => $t('tema', 60)]);
  if ($tipo === 'recomendacion') return array_merge($antes, ['tipo' => $t('tipo', 40), 'titulo' => $t('titulo', 300), 'url' => $u('url'), 'imagen' => $u('imagen'), 'medio' => $t('medio', 160), 'descripcion' => $t('descripcion', 3000), 'comentario' => $t('comentario', 3000), 'critica' => $t('critica', 40000), 'comenta' => limpio($x['firma'] ?? ($x['comenta'] ?? ($antes['comenta'] ?? '')), 80)]);
  if ($tipo === 'editorial') return array_merge($antes, ['titulo' => $t('titulo', 300), 'texto' => $t('texto', 40000), 'firma' => $t('firma', 80), 'imagen' => $u('imagen')]);
  if ($tipo === 'espana') return array_merge($antes, ['titulo' => limpio($x['titular'] ?? ($x['titulo'] ?? ($antes['titulo'] ?? '')), 300), 'url' => $u('url'), 'medio' => $t('medio', 80), 'imagen' => $u('imagen'), 'resumen' => $t('resumen', 1200)]);
  return $antes;
}

/* Lo preparado en importar.js (artículos y recomendaciones), en el mismo formato que lo subido desde Publicar */
function piezasImportar() {
  $t = @file_get_contents(__DIR__ . '/importar.js') ?: ''; $res = [];
  $lee = function ($nombre) use ($t) { return preg_match('/window\.' . $nombre . '\s*=\s*(\[.*?\n\]);/s', $t, $m) ? (json_decode($m[1], true) ?: []) : []; };
  foreach ($lee('PATRIAM_IMPORTAR') as $x) $res[] = ['id' => 'imp-' . ($x['id'] ?? ''), 'tipo' => 'firma', 'numero' => $x['numero'] ?? null,
    'datos' => ['titulo' => $x['titulo'] ?? '', 'entradilla' => $x['entradilla'] ?? '', 'texto' => $x['texto'] ?? '', 'imagen' => $x['imagen'] ?? '', 'autor' => $x['autor'] ?? '', 'cargo' => $x['cargo'] ?? '', 'seccion' => $x['seccion'] ?? '', 'tema' => $x['tema'] ?? '']];
  foreach ($lee('PATRIAM_RECOMENDAR') as $x) $res[] = ['id' => 'rec-' . ($x['id'] ?? ''), 'tipo' => 'recomendacion', 'numero' => $x['numero'] ?? 1,
    'datos' => ['tipo' => $x['tipo'] ?? '', 'titulo' => $x['titulo'] ?? '', 'url' => $x['url'] ?? '', 'imagen' => $x['imagen'] ?? '', 'medio' => $x['medio'] ?? '', 'descripcion' => $x['descripcion'] ?? '',
      'comentario' => $x['comentario'] ?? '', 'critica' => $x['critica'] ?? '', 'comenta' => $x['comenta'] ?? '', 'seccion' => $x['seccion'] ?? '', 'tema' => $x['tema'] ?? 'Cine']];
  return $res;
}

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
    $aRecamara = ($cuerpo['destino'] ?? '') === 'recamara' && in_array($cuerpo['tipo'] ?? '', ['noticia', 'firma', 'recomendacion', 'editorial'], true);
    if (!$e['abierto'] && !$aRecamara) salir(['error' => 'Primero hay que abrir el boletín de esta semana (o guárdalo en la recámara).'], 409);
    $tipo = $cuerpo['tipo'] ?? '';
    $x = $cuerpo['datos'] ?? [];
    $sec = in_array($x['seccion'] ?? '', ['Actualidad', 'Historia', 'Sociedad', 'Análisis'], true) ? $x['seccion'] : 'Actualidad';
    if ($tipo === 'noticia') {
      $datos = ['titular' => limpio($x['titular'] ?? '', 300), 'url' => url_ok($x['url'] ?? ''), 'medio' => limpio($x['medio'] ?? '', 80), 'imagen' => url_ok($x['imagen'] ?? ''),
        'resumen' => limpio($x['resumen'] ?? '', 2000), 'comentario' => limpio($x['comentario'] ?? '', 2000), 'comenta' => limpio($x['firma'] ?? '', 80), 'seccion' => $sec, 'formato' => 'Noticia'];
      $datos['enlaces'] = array_values(array_filter(array_map(fn($e) => ['medio' => limpio($e['medio'] ?? '', 80), 'url' => url_ok($e['url'] ?? '')], array_slice((array)($x['enlaces'] ?? []), 0, 8)), fn($e) => $e['url']));
      if (!$datos['enlaces']) unset($datos['enlaces']);
      if (!$datos['titular'] || !$datos['url']) salir(['error' => 'Faltan el titular o el enlace de la noticia.'], 400);
    } elseif ($tipo === 'espana') {
      // Una noticia que no puede faltar en «España al día» (también de Instagram, X, YouTube…): sale destacada siete días
      $datos = ['titulo' => limpio($x['titular'] ?? ($x['titulo'] ?? ''), 300), 'url' => url_ok($x['url'] ?? ''), 'medio' => limpio($x['medio'] ?? '', 80), 'imagen' => url_ok($x['imagen'] ?? ''),
        'resumen' => limpio($x['resumen'] ?? '', 1200), 'tema' => ($x['tema'] ?? '') === 'Economía' ? 'Economía' : 'Política'];
      if (!$datos['titulo'] || !$datos['url']) salir(['error' => 'Faltan el titular o el enlace.'], 400);
      if (!$datos['medio']) $datos['medio'] = preg_replace('/^www\./', '', parse_url($datos['url'], PHP_URL_HOST) ?: 'Abrir');
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
    if ($aRecamara) {   // a la recámara: no va a ningún número todavía; se aprueba al usarlo
      $d['items'][] = ['id' => $id, 'tipo' => $tipo, 'numero' => null, 'datos' => $datos, 'estado' => 'recamara', 'autor' => $yo['nombre'] ?? '', 'autor_id' => $yo['id'], 'creado' => gmdate('c'), 'aprobado_por' => ''];
      guardarAp($FICHERO, $d);
      salir(estado($d, $hoy, $yo) + ['nuevo' => $id, 'recamara' => true]);
    }
    $d['items'][] = ['id' => $id, 'tipo' => $tipo, 'numero' => $e['abierto']['numero'], 'datos' => $datos, 'estado' => 'pendiente',
      'autor' => $yo['nombre'] ?? '', 'autor_id' => $yo['id'], 'creado' => gmdate('c'), 'aprobado_por' => ''];
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo) + ['nuevo' => $id]);
  }

  /* Presentación de cada colaborador para «Quién es quién» del boletín, con su CV (el CV no se publica: solo lo ven Carlos e Isabel) */
  case 'mi-ficha': {
    $mia = null;
    foreach ($d['items'] as $x) if (($x['tipo'] ?? '') === 'ficha' && ($x['autor_id'] ?? '') === $yo['id'] && !in_array($x['estado'] ?? '', ['borrado', 'rechazado'], true)) $mia = $x;
    salir(['ficha' => $mia ? ['estado' => $mia['estado'], 'datos' => array_diff_key($mia['datos'], ['cv' => 1]), 'cv' => !empty($mia['datos']['cv'])] : null]);
  }
  case 'ficha': {
    $x = $cuerpo['datos'] ?? [];
    $firma = limpio($x['firma'] ?? '', 80);
    if (!$firma) salir(['error' => 'Falta la firma.'], 400);
    $datos = ['firma' => $firma, 'titulo' => 'Presentación de ' . $firma, 'cargo' => limpio($x['cargo'] ?? '', 140), 'bio' => limpio($x['bio'] ?? '', 4000)];
    $cv = $cuerpo['cv'] ?? null;
    if (is_array($cv) && !empty($cv['datos'])) {
      $ext = strtolower(pathinfo((string)($cv['nombre'] ?? ''), PATHINFO_EXTENSION));
      if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) salir(['error' => 'El CV tiene que ser PDF o Word.'], 400);
      $bin = base64_decode(preg_replace('/^data:[^,]*,/', '', $cv['datos']), true);
      if ($bin === false || strlen($bin) > 8000000) salir(['error' => 'El CV no se ha podido leer o pasa de 8 MB.'], 400);
      @mkdir(privado() . '/cv', 0750, true);
      $f = bin2hex(random_bytes(8)) . '.' . $ext;
      file_put_contents(privado() . '/cv/' . $f, $bin); @chmod(privado() . '/cv/' . $f, 0600);
      $datos['cv'] = ['archivo' => $f, 'nombre' => limpio($cv['nombre'] ?? 'cv.' . $ext, 120)];
    }
    if (!$datos['bio'] && empty($datos['cv'])) salir(['error' => 'Sube tu CV o escribe unas líneas sobre ti.'], 400);
    $e = estado($d, $hoy, $yo);
    // si ya había una suya, se sustituye (se conserva el CV si no se sube otro)
    foreach ($d['items'] as &$y) if (($y['tipo'] ?? '') === 'ficha' && ($y['autor_id'] ?? '') === $yo['id'] && ($y['estado'] ?? '') !== 'borrado') {
      if (empty($datos['cv']) && !empty($y['datos']['cv'])) $datos['cv'] = $y['datos']['cv'];
      $y['estado'] = 'borrado'; $y['cambiado'] = gmdate('c');
    }
    unset($y);
    $d['items'][] = ['id' => 'ap-' . bin2hex(random_bytes(5)), 'tipo' => 'ficha', 'numero' => $e['abierto']['numero'] ?? $e['siguiente']['numero'], 'datos' => $datos,
      'estado' => $yo['puedeAprobar'] && $datos['bio'] ? 'aprobado' : 'pendiente', 'autor' => $yo['nombre'] ?? '', 'autor_id' => $yo['id'], 'creado' => gmdate('c'), 'aprobado_por' => ''];
    guardarAp($FICHERO, $d);
    salir(['ok' => true]);
  }
  case 'cv': {
    if (!$yo['puedeAprobar']) salir(['error' => 'Solo pueden verlo Carlos e Isabel.'], 403);
    foreach ($d['items'] as $x) if ($x['id'] === ($cuerpo['id'] ?? '') && !empty($x['datos']['cv']['archivo'])) {
      $f = privado() . '/cv/' . basename($x['datos']['cv']['archivo']);
      if (!is_file($f)) break;
      salir(['nombre' => $x['datos']['cv']['nombre'], 'datos' => base64_encode(file_get_contents($f))]);
    }
    salir(['error' => 'No hay CV.'], 404);
  }
  case 'editar-ficha': {
    if (!$yo['puedeAprobar']) salir(['error' => 'Solo pueden cambiarlo Carlos e Isabel.'], 403);
    foreach ($d['items'] as &$x) if ($x['id'] === ($cuerpo['id'] ?? '') && ($x['tipo'] ?? '') === 'ficha') { $x['datos']['bio'] = limpio($cuerpo['bio'] ?? '', 4000); $x['datos']['cargo'] = limpio($cuerpo['cargo'] ?? $x['datos']['cargo'], 140); $x['cambiado'] = gmdate('c'); }
    unset($x);
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo));
  }

  case 'seccion': {
    if (!$yo['puedeAprobar']) salir(['error' => 'Solo pueden cambiarlo Carlos e Isabel.'], 403);
    $sec = in_array($cuerpo['seccion'] ?? '', ['Actualidad', 'Historia', 'Sociedad', 'Análisis'], true) ? $cuerpo['seccion'] : '';
    if (!$sec) salir(['error' => 'Sección no válida.'], 400);
    foreach ($d['items'] as &$x) if ($x['id'] === ($cuerpo['id'] ?? '')) { $x['datos']['seccion'] = $sec; $x['cambiado'] = gmdate('c'); }
    unset($x);
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo));
  }

  case 'editar': {   // cambiar algo ya subido (de Publicar o preparado): si no lo cambia quien aprueba, espera visto bueno
    $id = $cuerpo['id'] ?? ''; $x = $cuerpo['datos'] ?? [];
    if (esPreparado($id)) {
      $p = piezaPreparada($id); if (!$p) salir(['error' => 'No se encuentra.'], 404);
      $c = $d['cambios'][$id] ?? [];
      $nuevo = datosLimpios($p['tipo'], $x, $c['datos'] ?? $p['datos']);
      $c['propuesta'] = $nuevo; $c['propuestaDe'] = $yo['nombre'] ?? '';   // nada sale hasta que alguien pulse «Aprobar»
      $c['cambiado'] = gmdate('c'); $d['cambios'][$id] = $c;
    } else {
      $ok = false;
      foreach ($d['items'] as &$it) if ($it['id'] === $id) {
        if (!$yo['puedeAprobar'] && ($it['autor_id'] ?? '') !== $yo['id']) salir(['error' => 'Solo puede cambiarlo quien lo subió o quien aprueba.'], 403);
        $it['datos'] = datosLimpios($it['tipo'], $x, $it['datos'] ?? []);
        $it['estado'] = 'pendiente'; $it['aprobado_por'] = '';   // nada sale hasta que alguien pulse «Aprobar»
        $it['cambiado'] = gmdate('c'); $ok = true;
      }
      unset($it);
      if (!$ok) salir(['error' => 'No se encuentra.'], 404);
    }
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo) + ['ok' => true]);
  }

  case 'aprobar': case 'rechazar': case 'borrar': {
    $id = $cuerpo['id'] ?? '';
    if (esPreparado($id)) {   // lo preparado: aprobar el cambio propuesto, rechazarlo, o quitar la pieza del boletín
      if (!$yo['puedeAprobar']) salir(['error' => 'Solo pueden hacerlo Carlos e Isabel.'], 403);
      $c = $d['cambios'][$id] ?? [];
      if ($acc === 'aprobar') { if (!empty($c['propuesta'])) $c['datos'] = $c['propuesta']; $c['aprobado'] = true; $c['aprobado_por'] = $yo['nombre'] ?? ''; }
      if ($acc === 'rechazar' && empty($c['propuesta']) && empty($c['aprobado'])) $c['quitado'] = true;   // «No publicar» algo que nunca se aprobó
      if ($acc !== 'borrar') unset($c['propuesta'], $c['propuestaDe']); else $c['quitado'] = true;
      $c['cambiado'] = gmdate('c'); $d['cambios'][$id] = $c;
      guardarAp($FICHERO, $d);
      salir(estado($d, $hoy, $yo));
    }
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

  /* ---------- Recámara: lo preparado que todavía no va a ningún número ---------- */
  case 'recamara': {
    $r = array_values(array_filter($d['items'], fn($x) => ($x['estado'] ?? '') === 'recamara'));
    $r = array_map(fn($x) => array_merge($x, ['datos' => array_diff_key($x['datos'] ?? [], ['cv' => 1])]), $r);
    usort($r, fn($a, $b) => strcmp($b['creado'] ?? '', $a['creado'] ?? ''));
    // y lo que ya está preparado para un número que todavía no ha salido (importar.js)
    $ab = estado($d, $hoy, $yo)['abierto']; $prog = [];
    foreach (piezasImportar() as $x) if (($x['numero'] ?? 0) && (int)$x['numero'] > (int)($ab['numero'] ?? 0)) $prog[] = $x;
    salir(['items' => $r, 'programado' => $prog, 'abierto' => $ab]);
  }
  case 'usar': {   // de la recámara al boletín abierto
    $e = estado($d, $hoy, $yo);
    if (!$e['abierto']) salir(['error' => 'Primero hay que abrir el boletín de esta semana.'], 409);
    $ok = false;
    foreach ($d['items'] as &$x) if ($x['id'] === ($cuerpo['id'] ?? '') && ($x['estado'] ?? '') === 'recamara') {
      $x['numero'] = $e['abierto']['numero']; $x['estado'] = 'pendiente';
      $x['aprobado_por'] = ''; $x['cambiado'] = gmdate('c'); $ok = true;
    }
    unset($x);
    if (!$ok) salir(['error' => 'No está en la recámara.'], 404);
    guardarAp($FICHERO, $d);
    salir(estado($d, $hoy, $yo) + ['ok' => true]);
  }
  case 'a-recamara': {   // devolver a la recámara algo del boletín abierto (si no ha salido) o recuperar algo antiguo (copia)
    $id = $cuerpo['id'] ?? ''; $hecho = false;
    $abierto = estado($d, $hoy, $yo)['abierto']['numero'] ?? null;
    foreach ($d['items'] as &$x) if ($x['id'] === $id) {
      if ($x['numero'] !== null && (int)$x['numero'] === (int)$abierto && ($x['estado'] ?? '') !== 'borrado') {
        $x['numero'] = null; $x['estado'] = 'recamara'; $x['cambiado'] = gmdate('c'); $hecho = true;
      }
    }
    unset($x);
    if (!$hecho && esPreparado($id) && ($pp = piezaPreparada($id)) && (int)($pp['numero'] ?? 0) === (int)$abierto) {
      $c = $d['cambios'][$id] ?? []; $c['quitado'] = true; $c['cambiado'] = gmdate('c'); $d['cambios'][$id] = $c;
      $d['items'][] = ['id' => 'ap-' . bin2hex(random_bytes(5)), 'tipo' => $pp['tipo'], 'numero' => null, 'datos' => $c['datos'] ?? $pp['datos'], 'estado' => 'recamara',
        'autor' => $yo['nombre'] ?? '', 'autor_id' => $yo['id'], 'creado' => gmdate('c'), 'aprobado_por' => ''];
      $hecho = true;
    }
    if (!$hecho) {   // algo ya publicado: se copia a la recámara, marcado con el número en que salió
      $p = $cuerpo['pieza'] ?? null;
      if (!$p) foreach ($d['items'] as $x) if ($x['id'] === $id) $p = ['tipo' => $x['tipo'], 'datos' => $x['datos'], 'numero' => $x['numero']];
      if (!$p || !in_array($p['tipo'] ?? '', ['noticia', 'firma', 'recomendacion', 'editorial'], true)) salir(['error' => 'No se encuentra.'], 404);
      $datos = array_map(fn($v) => is_string($v) ? limpio($v, 60000) : $v, (array)($p['datos'] ?? []));
      $d['items'][] = ['id' => 'ap-' . bin2hex(random_bytes(5)), 'tipo' => $p['tipo'], 'numero' => null, 'datos' => $datos, 'estado' => 'recamara',
        'ya_en' => (int)($p['numero'] ?? 0) ?: null, 'autor' => $yo['nombre'] ?? '', 'autor_id' => $yo['id'], 'creado' => gmdate('c'), 'aprobado_por' => ''];
    }
    guardarAp($FICHERO, $d);
    salir(['ok' => true]);
  }
  case 'quitar-recamara': {
    foreach ($d['items'] as &$x) if ($x['id'] === ($cuerpo['id'] ?? '') && ($x['estado'] ?? '') === 'recamara') {
      if (!$yo['puedeAprobar'] && ($x['autor_id'] ?? '') !== $yo['id']) salir(['error' => 'Solo puede quitarlo quien lo subió o quien aprueba.'], 403);
      $x['estado'] = 'borrado'; $x['cambiado'] = gmdate('c');
    }
    unset($x);
    guardarAp($FICHERO, $d);
    salir(['ok' => true]);
  }
  /* ---------- Archivo: todo lo ya publicado, para buscar por sección o tema y recuperarlo ---------- */
  case 'archivo': {
    $e = estado($d, $hoy, $yo); $abierto = (int)($e['abierto']['numero'] ?? 0);
    $res = []; $vistos = [];
    $mete = function ($tipo, $numero, $datos, $id, $origen) use (&$res, &$vistos) {
      $t = trim((string)($datos['titulo'] ?? ($datos['titular'] ?? '')));
      if ($t === '' || isset($vistos[mb_strtolower($t)])) return; $vistos[mb_strtolower($t)] = 1;
      $res[] = ['id' => $id, 'origen' => $origen, 'tipo' => $tipo, 'numero' => $numero, 'titulo' => $t,
        'firma' => $datos['autor'] ?? ($datos['comenta'] ?? ($datos['firma'] ?? '')), 'seccion' => $datos['seccion'] ?? '', 'tema' => $datos['tema'] ?? ($datos['tipo'] ?? ''),
        'imagen' => $datos['imagen'] ?? '', 'pieza' => ['tipo' => $tipo, 'numero' => $numero, 'datos' => $datos]];
    };
    foreach ($d['items'] as $x) if (($x['estado'] ?? '') === 'aprobado' && $x['numero'] !== null && in_array($x['tipo'], ['noticia', 'firma', 'recomendacion', 'editorial'], true))
      $mete($x['tipo'], (int)$x['numero'], array_diff_key($x['datos'], ['cv' => 1]), $x['id'], 'publicar');
    foreach (piezasImportar() as $x) if (($x['numero'] ?? 0) && (int)$x['numero'] <= max($abierto, 1)) $mete($x['tipo'], (int)$x['numero'], $x['datos'], $x['id'], 'preparado');
    boletinesTodos($d);
    foreach ($GLOBALS['BOLETIN_EDITOR'] ?? [] as $num => $b) {
      foreach ((array)($b['noticias'] ?? []) as $k => $n) if (!preg_match('/ejemplo\.com/i', (string)($n['url'] ?? '')))
        $mete('noticia', $num, ['titular' => $n['titular'] ?? '', 'url' => $n['url'] ?? '', 'medio' => $n['medio'] ?? '', 'imagen' => $n['imagen'] ?? '', 'resumen' => $n['resumen'] ?? '', 'comentario' => $n['comentario'] ?? '', 'firma' => $n['comenta'] ?? '', 'seccion' => $n['seccion'] ?? 'Actualidad', 'tema' => $n['tema'] ?? ''], "ed-$num-n$k", 'editor');
      foreach ((array)($b['colaboraciones'] ?? []) as $k => $c)
        $mete('firma', $num, ['titulo' => $c['titulo'] ?? '', 'entradilla' => $c['entradilla'] ?? '', 'texto' => $c['texto'] ?? '', 'imagen' => $c['imagen'] ?? '', 'autor' => $c['autor'] ?? '', 'cargo' => $c['cargo'] ?? '', 'seccion' => $c['seccion'] ?? '', 'tema' => $c['tema'] ?? ''], "ed-$num-c$k", 'editor');
      if (is_array($b['editorial'] ?? null) && trim($b['editorial']['titulo'] ?? '') !== '')
        $mete('editorial', $num, ['titulo' => $b['editorial']['titulo'], 'texto' => $b['editorial']['texto'] ?? '', 'firma' => $b['editorial']['firma'] ?? '', 'imagen' => $b['editorial']['imagen'] ?? '', 'seccion' => 'Editorial'], "ed-$num-e", 'editor');
    }
    usort($res, fn($a, $b) => ($b['numero'] <=> $a['numero']) ?: strcmp($a['titulo'], $b['titulo']));
    salir(['items' => $res]);
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
