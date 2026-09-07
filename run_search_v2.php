<?php
$dir = __DIR__;
$files = glob("$dir/*.php");
$html_files = glob("$dir/*.html");
$all_files = array_merge($files, $html_files);

$search_terms = ['Admin 1', 'Admin 2', 'Pemilik', 'Rizal', 'owner'];
$output = "";

foreach ($all_files as $file) {
    if (basename($file) === 'run_search_v2.php' || basename($file) === 'search_results_v2.txt') {
        continue;
    }
    $content = @file_get_contents($file);
    if ($content === false) continue;
    foreach ($search_terms as $term) {
        $pos = 0;
        while (($pos = stripos($content, $term, $pos)) !== false) {
            $line_num = substr_count(substr($content, 0, $pos), "\n") + 1;
            // Get the line content
            $lines = explode("\n", $content);
            $line_content = isset($lines[$line_num - 1]) ? trim($lines[$line_num - 1]) : '';
            $output .= "FILE: " . basename($file) . " | LINE: " . $line_num . " | TERM: " . $term . " | CONTENT: " . $line_content . "\n";
            $pos += strlen($term);
        }
    }
}

file_put_contents($dir . '/search_results_v2.txt', $output);
echo "SUCCESS_WRITTEN_V2\n";
