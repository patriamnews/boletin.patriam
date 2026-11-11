<?php
/* boletin.movimientopatriam.es/espana-al-dia: la misma página del boletín, pero con su propia tarjeta para
   WhatsApp, X o Facebook (título, explicación e imagen grande de «España al día"). */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$h = file_get_contents(__DIR__ . '/index.html');
$t = 'España al día · Clipping de noticias políticas y económicas de España';
$d = 'Clipping diario de noticias políticas y económicas de España, con toda la semana. Se actualiza cada día. Por PATRIAM.';
$u = 'https://boletin.movimientopatriam.es/espana-al-dia';
$i = 'https://boletin.movimientopatriam.es/espana-al-dia-og.jpg?v=2';
$e = fn($x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
$cambios = [
  '#<title>.*?</title>#s' => '<title>' . $e($t) . '</title>',
  '#<meta name="description" content="[^"]*">#' => '<meta name="description" content="' . $e($d) . '">',
  '#<meta property="og:title" content="[^"]*">#' => '<meta property="og:title" content="' . $e($t) . '">',
  '#<meta property="og:description" content="[^"]*">#' => '<meta property="og:description" content="' . $e($d) . '">',
  '#<meta property="og:url" content="[^"]*">#' => '<meta property="og:url" content="' . $u . '">',
  '#<meta property="og:image" content="[^"]*">#' => '<meta property="og:image" content="' . $i . '">',
  '#<meta property="og:image:width" content="[^"]*">#' => '<meta property="og:image:width" content="1200">',
  '#<meta property="og:image:height" content="[^"]*">#' => '<meta property="og:image:height" content="630">',
  '#<meta name="twitter:card" content="[^"]*">#' => '<meta name="twitter:card" content="summary_large_image">',
  '#<meta name="twitter:title" content="[^"]*">#' => '<meta name="twitter:title" content="' . $e($t) . '">',
  '#<meta name="twitter:description" content="[^"]*">#' => '<meta name="twitter:description" content="' . $e($d) . '">',
  '#<meta name="twitter:image" content="[^"]*">#' => '<meta name="twitter:image" content="' . $i . '">',
  '#<link rel="canonical" href="[^"]*">#' => '<link rel="canonical" href="' . $u . '">',
];
foreach ($cambios as $a => $b) $h = preg_replace($a, $b, $h, 1);
echo $h;
