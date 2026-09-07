<?php
$content = file_get_contents('index.php');
$lines = explode("\n", $content);
$out = "";
foreach ($lines as $idx => $line) {
    if (stripos($line, 'getKamarList') !== false || stripos($line, 'rooms') !== false || stripos($line, 'Rizal') !== false || stripos($line, 'Admin') !== false || stripos($line, 'pemilik') !== false) {
        $out .= "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
file_put_contents('index_render_kamar.txt', $out);
echo "OK";
