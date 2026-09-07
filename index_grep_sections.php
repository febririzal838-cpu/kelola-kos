<?php
$content = file_get_contents('index.php');
$lines = explode("\n", $content);
$out = "";
foreach ($lines as $idx => $line) {
    if (stripos($line, '<section') !== false || stripos($line, 'id=') !== false || stripos($line, 'Executive') !== false || stripos($line, 'Standard') !== false || stripos($line, 'Deluxe') !== false || stripos($line, 'Suite') !== false) {
        $out .= "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
file_put_contents('index_sections.txt', $out);
echo "OK";
