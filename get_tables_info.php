<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/config/koneksi.php';

try {
    echo "=== DATABASE TABLES ===\n";
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    print_r($tables);
    echo "\n";

    foreach ($tables as $t) {
        echo "=====================================\n";
        echo "TABLE: $t\n";
        echo "=====================================\n";
        
        // Describe columns
        $desc = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        echo "Columns:\n";
        foreach ($desc as $c) {
            echo "  - {$c['Field']} ({$c['Type']}) " . ($c['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . " Key:{$c['Key']} Default:{$c['Default']}\n";
        }
        
        // Show rows
        $rows = $pdo->query("SELECT * FROM `$t` LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        echo "Rows (up to 20):\n";
        print_r($rows);
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
