<?php
/**
 * Koneksi database menggunakan PDO.
 * Dilengkapi fitur Auto-Setup & Auto-Create Database + Schema dari backup SQL.
 */
$host = '127.0.0.1';
$db   = 'kelolakos';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Jika database tidak ditemukan (SQLSTATE 1049 atau terkait)
    if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
        try {
            // Coba koneksi ke MySQL server tanpa database
            $tempDsn = "mysql:host=$host;charset=$charset";
            $tempPdo = new PDO($tempDsn, $user, $pass, $options);
            // Buat database
            $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Coba koneksi ulang ke database yang baru dibuat
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $ex) {
            http_response_code(500);
            echo '<h1>Auto-setup database gagal.</h1>';
            echo '<p>' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
            exit;
        }
    } else {
        http_response_code(500);
        echo '<h1>Koneksi database gagal.</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        exit;
    }
}

// Cek apakah tabel utama sudah ada, jika belum inisialisasi dari backup SQL
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'kamar'")->fetch();
    if (!$tableCheck) {
        $backupPath = dirname(__DIR__) . '/kelolakos_backup.sql';
        if (file_exists($backupPath)) {
            $sql = file_get_contents($backupPath);
            
            // Hilangkan baris komentar dan spasi kosong agar parsing lancar
            $lines = explode("\n", $sql);
            $cleanSql = '';
            foreach ($lines as $line) {
                $lineClean = trim($line);
                if ($lineClean !== '' && strpos($lineClean, '--') !== 0 && strpos($lineClean, '#') !== 0) {
                    $cleanSql .= $line . "\n";
                }
            }
            
            // Jalankan multi-query untuk memulihkan database
            if (trim($cleanSql) !== '') {
                $pdo->exec($cleanSql);
            }
        }
    }
} catch (Throwable $e) {
    // Abaikan atau log kesalahan inisialisasi agar aplikasi tidak crash
}

// Cek apakah kolom-kolom yang diperlukan ada di tabel transaksi, jika belum buat kolomnya
try {
    // 1. updated_at
    $colCheck = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'updated_at'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE transaksi ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }

    // 2. nomor_kamar
    $colCheck = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'nomor_kamar'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE transaksi ADD COLUMN nomor_kamar VARCHAR(50) NOT NULL DEFAULT '' AFTER kamar_id");
    }

    // 3. total_harga
    $colCheck = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'total_harga'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE transaksi ADD COLUMN total_harga DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER nomor_kamar");
    }

    // 4. tanggal_pengajuan
    $colCheck = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'tanggal_pengajuan'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE transaksi ADD COLUMN tanggal_pengajuan DATE DEFAULT NULL AFTER status");
    }

    // 5. durasi_bulan
    $colCheck = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'durasi_bulan'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE transaksi ADD COLUMN durasi_bulan INT NOT NULL DEFAULT 1 AFTER tanggal_pengajuan");
    }

    // 6. alamat pada tabel kamar
    $colCheckKamar = $pdo->query("SHOW COLUMNS FROM kamar LIKE 'alamat'")->fetch();
    if (!$colCheckKamar) {
        $pdo->exec("ALTER TABLE kamar ADD COLUMN alamat TEXT DEFAULT NULL AFTER fasilitas");
    }

    // 7. Kolom-kolom pada tabel pengaduan
    $colTenant = $pdo->query("SHOW COLUMNS FROM pengaduan LIKE 'tenant_id'")->fetch();
    if (!$colTenant) {
        $pdo->exec("ALTER TABLE pengaduan ADD COLUMN tenant_id INT NOT NULL DEFAULT 0 AFTER owner_id");
    }
    $colNoKamar = $pdo->query("SHOW COLUMNS FROM pengaduan LIKE 'nomor_kamar'")->fetch();
    if (!$colNoKamar) {
        $pdo->exec("ALTER TABLE pengaduan ADD COLUMN nomor_kamar VARCHAR(50) NOT NULL DEFAULT '' AFTER kamar_id");
    }
    $colJenis = $pdo->query("SHOW COLUMNS FROM pengaduan LIKE 'jenis_pengaduan'")->fetch();
    if (!$colJenis) {
        $pdo->exec("ALTER TABLE pengaduan ADD COLUMN jenis_pengaduan VARCHAR(100) NOT NULL DEFAULT '' AFTER nomor_kamar");
    }
    $colPrioritas = $pdo->query("SHOW COLUMNS FROM pengaduan LIKE 'prioritas'")->fetch();
    if (!$colPrioritas) {
        $pdo->exec("ALTER TABLE pengaduan ADD COLUMN prioritas VARCHAR(50) NOT NULL DEFAULT 'Sedang' AFTER deskripsi");
    }

    // Sinkronisasi data kolom baru untuk baris yang sudah ada
    // total_harga dari nominal
    $pdo->exec("UPDATE transaksi SET total_harga = nominal WHERE (total_harga IS NULL OR total_harga = 0) AND nominal > 0");
    // tanggal_pengajuan dari created_at
    $pdo->exec("UPDATE transaksi SET tanggal_pengajuan = DATE(created_at) WHERE tanggal_pengajuan IS NULL AND created_at IS NOT NULL");
    // nomor_kamar dari kamar
    $pdo->exec("UPDATE transaksi t LEFT JOIN kamar k ON k.id = t.kamar_id SET t.nomor_kamar = k.nomor_kamar WHERE (t.nomor_kamar IS NULL OR t.nomor_kamar = '') AND k.nomor_kamar IS NOT NULL");
} catch (Throwable $e) {
    // Abaikan atau log kesalahan agar aplikasi tidak crash jika tabel belum dibuat
}



