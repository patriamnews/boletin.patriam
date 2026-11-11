<?php
/* Temporal: abre el boletín nº 2 (viernes 13 de noviembre) para el equipo. Se borra después de usarlo. */
require __DIR__ . '/_privado.php';
if (($_GET['k'] ?? '') !== '69e3e08db7610d3e9c84b6260db67737') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
$f = privado() . '/aportaciones.json';
$d = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
$d += ['boletines' => [], 'items' => []];
$ya = false; foreach ($d['boletines'] as $b) if ((int)$b['numero'] === 2) $ya = true;
if (!$ya) { $d['boletines'][] = ['numero' => 2, 'fecha' => '2026-11-13', 'abierto_por' => 'Isabel O.C.', 'abierto_el' => gmdate('c')]; file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
echo $ya ? "YA ESTABA ABIERTO\n" : "ABIERTO\n";
echo file_get_contents($f);
