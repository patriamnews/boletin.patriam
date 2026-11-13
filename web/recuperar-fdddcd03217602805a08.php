<?php
/* Uso único: vuelve a poner en el boletín nº 1 «Yo fui a un cole de monjas», que se había quitado. Se borra del repositorio después. */
require __DIR__ . '/_privado.php';
$f = privado() . '/aportaciones.json';
$d = json_decode(file_get_contents($f), true);
if (!is_array($d)) salir(['error' => 'sin datos'], 500);
$id = 'imp-c-cole-de-monjas';
$antes = $d['cambios'][$id] ?? null;
if ($antes !== null) {
  unset($d['cambios'][$id]['quitado'], $d['cambios'][$id]['sinAprobar']);
  $d['cambios'][$id]['aprobado'] = true; $d['cambios'][$id]['cambiado'] = gmdate('c');
  @copy($f, $f . '.antes-cole');
  $t = $f . '.tmp'; file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($t, $f);
}
// si al quitarla se hizo una copia en la recámara, se deja ahí (no molesta) y se avisa
$copias = array_values(array_map(fn($x) => $x['id'], array_filter($d['items'] ?? [], fn($x) => ($x['estado'] ?? '') === 'recamara' && stripos($x['datos']['titulo'] ?? '', 'cole de monjas') !== false)));
salir(['antes' => $antes, 'ahora' => $d['cambios'][$id] ?? null, 'copiasEnRecamara' => $copias]);
