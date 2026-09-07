<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';

/**
 * Pastikan kolom owner_id di tabel transaksi selalu tersedia & sinkron dengan kamar.owner_id.
 * Digunakan pada saat insert untuk menghindari transaksi "hantu" yang tak muncul di dasbor pemilik.
 */
function ensureOwnerIdColumn(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'owner_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE transaksi ADD COLUMN owner_id INT NOT NULL DEFAULT 0 AFTER id");
        }
    } catch (Throwable $e) {
        // Lanjutkan jika tabel belum ada.
    }
}

/**
 * SYNC: Isi owner_id transaksi yang NULL / 0 / mismatch berdasarkan kamar.owner_id.
 * Dipanggil SETELAH insert & juga setiap kali agar data lama ikut tertampilkan.
 */
function syncMissingOwnerId(PDO $pdo): void {
    try {
        $pdo->exec(
            "UPDATE transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             SET t.owner_id = k.owner_id
             WHERE (t.owner_id IS NULL OR t.owner_id = 0 OR t.owner_id <> k.owner_id)
               AND k.owner_id IS NOT NULL AND k.owner_id <> 0"
        );
    } catch (Throwable $e) {
        // Ignore
    }
}

/**
 * Pastikan ENUM 'status' punya 'Selesai' & 'Menunggu Checkout' juga agar kompatibel dengan checkout.
 */
function ensureTxStatusEnum(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'status'")->fetch();
        if ($col && strpos($col['Type'], 'Menunggu Checkout') === false) {
            $pdo->exec("ALTER TABLE transaksi MODIFY COLUMN status ENUM(
                'Menunggu','Menunggu Verifikasi','Pending',
                'Disetujui','Ditolak','Lunas','Belum Bayar',
                'Menunggu Checkout','Selesai'
            ) NOT NULL DEFAULT 'Menunggu Verifikasi'");
        }
    } catch (Throwable $e) {
        // Ignore
    }
}

$userId = $_SESSION['penyewa_id'] ?? $_SESSION['user_id'] ?? $_SESSION['tenant_id'] ?? null;
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'penyewa' || empty($userId)) {
    header('Location: login_daftar.php?redirect=' . urlencode('dashboard_penyewa.php'));
    exit;
}

$userId = (int)$userId;
if ($userId <= 0) {
    header('Location: login_daftar.php');
    exit;
}

$kamarId = isset($_POST['kamar_id']) ? (int)$_POST['kamar_id'] : 0;
$tanggalMulai = trim($_POST['tanggal_mulai'] ?? date('Y-m-d', strtotime('+1 day')));
$durasiBulan = isset($_POST['durasi_bulan']) ? (int)$_POST['durasi_bulan'] : 1;

if ($kamarId <= 0) {
    header('Location: dashboard_penyewa.php?status=error&msg=' . urlencode('Kamar yang dipilih tidak valid.'));
    exit;
}

try {
    $roomStmt = $pdo->prepare('SELECT id, owner_id, nomor_kamar, harga_sewa, status FROM kamar WHERE id = :id LIMIT 1');
    $roomStmt->execute([':id' => $kamarId]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        header('Location: dashboard_penyewa.php?status=error&msg=' . urlencode('Kamar yang dipilih tidak ditemukan.'));
        exit;
    }

    // Pastikan tabel & kolom yang dibutuhkan tersedia
    try {
        $tableCheck = $pdo->prepare("SHOW TABLES LIKE 'transaksi'");
        $tableCheck->execute();
        if (!$tableCheck->fetch()) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS transaksi (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_id INT NOT NULL DEFAULT 0,
                    penyewa_id INT NOT NULL,
                    kamar_id INT NOT NULL,
                    nomor_kamar VARCHAR(50) NOT NULL,
                    total_harga DECIMAL(12,2) NOT NULL DEFAULT 0,
                    status ENUM('Menunggu','Menunggu Verifikasi','Pending','Disetujui','Ditolak','Lunas','Belum Bayar','Menunggu Checkout','Selesai') NOT NULL DEFAULT 'Menunggu Verifikasi',
                    tanggal_pengajuan DATE NOT NULL,
                    durasi_bulan INT NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            );
        } else {
            ensureOwnerIdColumn($pdo);
            ensureTxStatusEnum($pdo);
        }
    } catch (PDOException $e) {
        // Continue
    }

    // 🔑 PASTIKAN owner_id ADA & BENAR dari tabel kamar (TIDAK BOLEH 0 / NULL)
    // --- STEP 1: Ambil dari cache query kamar ---
    $ownerId = (int)($room['owner_id'] ?? 0);

    // --- STEP 2: Jika 0, coba fallback query tambahan (properti / dll) ---
    if ($ownerId <= 0) {
        try {
            $fStmt = $pdo->prepare(
                'SELECT COALESCE(NULLIF(k.owner_id,0), p.owner_id) AS safe_owner_id
                 FROM kamar k LEFT JOIN properti p ON p.id = k.properti_id
                 WHERE k.id = :id LIMIT 1'
            );
            $fStmt->execute([':id' => $kamarId]);
            $fallback = $fStmt->fetchColumn();
            if ($fallback && (int)$fallback > 0) {
                $ownerId = (int)$fallback;
                $pdo->prepare('UPDATE kamar SET owner_id = :oid WHERE id = :id')->execute([
                    ':oid' => $ownerId, ':id' => $kamarId
                ]);
            }
        } catch (Throwable $e) { /* lanjut */ }
    }

    // --- STEP 3: MASIH 0? Ambil ID pemilik dari email febririzal838@gmail.com (jika ada di tabel pemilik) ---
    if ($ownerId <= 0) {
        try {
            $gStmt = $pdo->prepare(
                "SELECT id FROM pemilik
                 WHERE LOWER(email) = 'febririzal838@gmail.com'
                    OR id IN (SELECT COALESCE(MIN(id),0) FROM pemilik)
                 ORDER BY id ASC LIMIT 1"
            );
            $gStmt->execute();
            $defOwner = (int)$gStmt->fetchColumn();
            if ($defOwner > 0) {
                $ownerId = $defOwner;
                $pdo->prepare('UPDATE kamar SET owner_id = :oid WHERE id = :id')
                    ->execute([':oid' => $ownerId, ':id' => $kamarId]);
            }
        } catch (Throwable $e) { /* lanjut */ }
    }

    // --- STEP 4: FINAL CHECK — kalau masih 0 / NULL → TOLAK ajukan sewa, pesan ke penyewa perbaiki kamar.owner_id dulu ---
    if ($ownerId <= 0) {
        header('Location: dashboard_penyewa.php?status=error&msg=' . urlencode(
            'Kamar ID ' . $kamarId . ' (nomor '.$room['nomor_kamar'].') belum memiliki owner_id yang valid. Mohon hubungi admin untuk memperbaiki data kamar sebelum mengajukan sewa.'
        ));
        exit;
    }

    $totalHarga = (float)($room['harga_sewa'] ?? 0) * max(1, $durasiBulan);

    // Insert dengan skema dinamis + SELALU sertakan owner_id (wajib)
    $columnsStmt = $pdo->query("DESCRIBE transaksi");
    $txColumns = array_map(static fn($col) => $col['Field'], $columnsStmt->fetchAll(PDO::FETCH_ASSOC));

    $insertFields = [];
    $insertValues = [];
    $insertParams = [];

    // 🔑 SELALU TANAMKAN owner_id DI AWAL (meskipun kolom tidak ada di daftar — kita sudah ensureOwnerIdColumn)
    $insertFields[] = 'owner_id';
    $insertValues[] = ':owner_id';
    $insertParams[':owner_id'] = $ownerId;

    if (in_array('penyewa_id', $txColumns, true)) {
        $insertFields[] = 'penyewa_id';
        $insertValues[] = ':penyewa_id';
        $insertParams[':penyewa_id'] = $userId;
    } elseif (in_array('tenant_id', $txColumns, true)) {
        $insertFields[] = 'tenant_id';
        $insertValues[] = ':tenant_id';
        $insertParams[':tenant_id'] = $userId;
    }
    if (in_array('kamar_id', $txColumns, true)) {
        $insertFields[] = 'kamar_id';
        $insertValues[] = ':kamar_id';
        $insertParams[':kamar_id'] = $kamarId;
    }
    if (in_array('nomor_kamar', $txColumns, true)) {
        $insertFields[] = 'nomor_kamar';
        $insertValues[] = ':nomor_kamar';
        $insertParams[':nomor_kamar'] = $room['nomor_kamar'];
    }
    if (in_array('total_harga', $txColumns, true)) {
        $insertFields[] = 'total_harga';
        $insertValues[] = ':total_harga';
        $insertParams[':total_harga'] = $totalHarga;
    } elseif (in_array('nominal', $txColumns, true)) {
        $insertFields[] = 'nominal';
        $insertValues[] = ':nominal';
        $insertParams[':nominal'] = $totalHarga;
    }
    if (in_array('status', $txColumns, true)) {
        $insertFields[] = 'status';
        $insertValues[] = ':status';
        $insertParams[':status'] = 'Menunggu Verifikasi';
    }
    if (in_array('tanggal_pengajuan', $txColumns, true)) {
        $insertFields[] = 'tanggal_pengajuan';
        $insertValues[] = ':tanggal_pengajuan';
        $insertParams[':tanggal_pengajuan'] = $tanggalMulai;
    } elseif (in_array('tanggal_bayar', $txColumns, true)) {
        $insertFields[] = 'tanggal_bayar';
        $insertValues[] = ':tanggal_bayar';
        $insertParams[':tanggal_bayar'] = $tanggalMulai;
    }
    if (in_array('durasi_bulan', $txColumns, true)) {
        $insertFields[] = 'durasi_bulan';
        $insertValues[] = ':durasi_bulan';
        $insertParams[':durasi_bulan'] = $durasiBulan;
    }
    if (in_array('created_at', $txColumns, true)) {
        $insertFields[] = 'created_at';
        $insertValues[] = 'NOW()';
    }

    $sql = "INSERT INTO transaksi (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
    $insertStmt = $pdo->prepare($sql);
    $insertStmt->execute($insertParams);
    $newTxId = (int)$pdo->lastInsertId();

    // 🔑 RE-ASSURANCE: Update owner_id transaksi BARU saja via JOIN kamar (jika default tdk terisi krn constraint)
    if ($newTxId > 0) {
        $pdo->prepare(
            "UPDATE transaksi t JOIN kamar k ON k.id = t.kamar_id
             SET t.owner_id = k.owner_id WHERE t.id = :tid AND k.owner_id <> 0"
        )->execute([':tid' => $newTxId]);
    }

    // Sync SEMUA owner_id transaksi yang cacat agar pengajuan lama ikut muncul
    syncMissingOwnerId($pdo);

    // Update penyewa room link ONLY after approval.
    // Commented out here to prevent overwriting active room link before owner verifies payment.
    /*
    try {
        $pdo->prepare('UPDATE penyewa SET kamar_id = :kamar_id WHERE id = :penyewa_id')->execute([
            ':kamar_id' => $kamarId,
            ':penyewa_id' => $userId,
        ]);
    } catch (PDOException $e) {
        // Continue
    }
    */

    $_SESSION['pengajuan_sukses'] = true; // Trigger ruang tunggu popup
    $_SESSION['success_message'] = 'Pengajuan sewa Anda berhasil dikirim!';
    header('Location: riwayat_penyewa.php?ruang_tunggu=1');
    exit;
} catch (PDOException $e) {
    header('Location: dashboard_penyewa.php?status=error&msg=' . urlencode('Gagal mengajukan sewa: ' . $e->getMessage()));
    exit;
}
