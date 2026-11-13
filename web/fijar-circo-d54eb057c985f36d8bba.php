<?php
/* Uso único: pone la imagen a la editorial del boletín nº 2 (solo si no tiene). Se borra del repositorio después. */
require __DIR__ . '/_privado.php';
$f = privado() . '/aportaciones.json';
$d = json_decode(file_get_contents($f), true);
if (!is_array($d)) salir(['error' => 'sin datos'], 500);
$img = 'https://boletin.movimientopatriam.es/imagenes/editorial-circo.jpg';
$res = null;
foreach ($d['items'] as &$x) if (($x['id'] ?? '') === 'ap-69a2ec12d7' && ($x['tipo'] ?? '') === 'editorial') {
  if (empty($x['datos']['imagen'])) {
    $x['datos']['imagen'] = $img;
    $x['datos']['imagenes'] = array_values(array_unique(array_merge($x['datos']['imagenes'] ?? [], [$img])));
    $x['cambiado'] = gmdate('c');
    $res = 'puesta';
  } else $res = 'ya tenía: ' . $x['datos']['imagen'];
  $res = ['resultado' => $res, 'estado' => $x['estado'] ?? '', 'numero' => $x['numero'] ?? ''];
}
unset($x);
if ($res && $res['resultado'] === 'puesta') {
  @copy($f, $f . '.antes-circo');
  $t = $f . '.tmp'; file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($t, $f);
}
salir(['ok' => (bool)$res, 'editorial' => $res]);
