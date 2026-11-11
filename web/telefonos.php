<?php
/* Teléfonos para mandar el boletín por WhatsApp (Publicar › Boletín › Teléfonos).
   - Es la misma lista de contactos que usan las comunicaciones de Carlos y «Otros destinatarios» del editor (com.json):
     lo que se mete aquí lo ven allí y al revés. No se duplica nada.
   - Solo la ven y la tocan quienes aprueban (Carlos e Isabel): son datos personales.
   - Sin WhatsApp Business: cada persona tiene su botón, que abre WhatsApp con el mensaje y el enlace ya escritos,
     y se apunta a quién se le ha mandado cada número del boletín (telefonos.json, en la carpeta privada). */
require_once __DIR__ . '/_privado.php';
require_once __DIR__ . '/carlos.php';   // comLeerTodo, comGuardarTodo, cMovil, cCorreo, cBloqueo…
permitirPublicar();
$yo = comprobarEquipoWeb();
if (empty($yo['puedeAprobar'])) salir(['error' => 'Los teléfonos solo los ven Carlos e Isabel.'], 403);

$acc = $_GET['accion'] ?? 'datos';
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
const TEL_NOTA = 'Boletín por WhatsApp';

function telLeer() { $f = privado() . '/telefonos.json'; $d = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; return $d + ['envios' => [], 'texto' => ''] ; }
function telGuardar($d) { $f = privado() . '/telefonos.json'; file_put_contents($f . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($f . '.tmp', $f); @chmod($f, 0600); }
function telTextoBase() {
  return "Buenos días, {nombre}:\n\nYa tienes el boletín nº {numero} de PATRIAM, «Lo que hay que saber: con criterio».\n\n{enlace}\n\nSi te gusta, reenvíalo.\n\nUn saludo,\nPATRIAM";
}
function telPublico() {
  $t = telLeer();
  $cont = array_values(array_map(fn($c) => [
      'id' => $c['id'] ?? '', 'nombre' => $c['nombre'] ?? '', 'movil' => $c['movil'] ?? '', 'email' => $c['email'] ?? '',
      'nota' => $c['nota'] ?? '', 'origen' => $c['origen'] ?? '', 'alta' => $c['alta'] ?? '', 'wa' => empty($c['noWa']) && cMovil($c['movil'] ?? '') !== ''],
    comLeerTodo()['contactos'] ?? []));
  usort($cont, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
  return ['contactos' => $cont, 'envios' => (object)$t['envios'], 'texto' => $t['texto'] ?: telTextoBase(), 'textoBase' => telTextoBase()];
}

if ($acc === 'datos') salir(telPublico());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['error' => 'No válido'], 405);

$fp = cBloqueo();
if ($acc === 'anadir') {
  $com = comLeerTodo(); $com['contactos'] = $com['contactos'] ?? [];
  $ya = []; foreach ($com['contactos'] as $c) { if ($m = cMovil($c['movil'] ?? '')) $ya[$m] = 1; if ($e = cCorreo($c['email'] ?? '')) $ya[$e] = 1; }
  $n = 0; $repes = 0; $malos = [];
  foreach (array_slice((array)($in['personas'] ?? []), 0, 3000) as $p) {
    $nombre = trim(mb_substr((string)($p['nombre'] ?? ''), 0, 120));
    $mv = cMovil($p['movil'] ?? ''); $e = cCorreo($p['email'] ?? '');
    if (!$mv) { if ($nombre !== '' || trim((string)($p['movil'] ?? '')) !== '') $malos[] = $nombre ?: (string)$p['movil']; continue; }
    if (isset($ya[$mv]) || ($e && isset($ya[$e]))) { $repes++; continue; }
    $com['contactos'][] = ['id' => 'k' . base_convert((string)(int)(microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(2)), 'nombre' => $nombre,
      'email' => $e, 'movil' => '+' . $mv, 'nota' => TEL_NOTA, 'alta' => gmdate('c'), 'altaPor' => $yo['nombre'] ?? ''];
    $ya[$mv] = 1; if ($e) $ya[$e] = 1; $n++;
  }
  comGuardarTodo($com); cSoltar($fp);
  salir(['anadidos' => $n, 'repetidos' => $repes, 'malos' => $malos] + telPublico());
}
if ($acc === 'editar') {
  $com = comLeerTodo();
  foreach ($com['contactos'] as &$c) if (($c['id'] ?? '') === ($in['id'] ?? '')) {
    if (isset($in['nombre'])) $c['nombre'] = trim(mb_substr((string)$in['nombre'], 0, 120));
    if (isset($in['movil'])) { $mv = cMovil($in['movil']); if (!$mv) { cSoltar($fp); salir(['error' => 'El móvil no es válido.'], 400); } $c['movil'] = '+' . $mv; }
    if (isset($in['email'])) $c['email'] = cCorreo($in['email']);
    if (isset($in['wa'])) { if ($in['wa']) unset($c['noWa']); else $c['noWa'] = true; }
  }
  unset($c);
  comGuardarTodo($com); cSoltar($fp); salir(telPublico());
}
if ($acc === 'quitar') {
  $com = comLeerTodo();
  $com['contactos'] = array_values(array_filter($com['contactos'] ?? [], fn($c) => ($c['id'] ?? '') !== ($in['id'] ?? '')));
  comGuardarTodo($com); cSoltar($fp); salir(telPublico());
}
if ($acc === 'enviado') {   // apunta que a esta persona ya se le ha mandado el boletín nº N
  $t = telLeer(); $num = (string)(int)($in['numero'] ?? 0); $id = (string)($in['id'] ?? '');
  if ($num === '0' || $id === '') { cSoltar($fp); salir(['error' => 'Faltan datos.'], 400); }
  if (!empty($in['quitar'])) unset($t['envios'][$num][$id]); else $t['envios'][$num][$id] = gmdate('c');
  telGuardar($t); cSoltar($fp); salir(telPublico());
}
if ($acc === 'texto') {
  $t = telLeer(); $t['texto'] = mb_substr((string)($in['texto'] ?? ''), 0, 3000);
  telGuardar($t); cSoltar($fp); salir(telPublico());
}
cSoltar($fp);
salir(['error' => 'Acción desconocida.'], 400);
