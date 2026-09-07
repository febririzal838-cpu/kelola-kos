<?php
/**
 * MASTER SYNC SCRIPT (jalankan SEKALI via browser)
 * =================================================
 * 1. Pastikan akun pemilik email=febririzal838@gmail.com ADA di tabel pemilik (auto-create jika perlu)
 * 2. Kamar dengan nomor 'C-102' → owner_id di-set ke ID dari akun di atas
 * 3. SEMUA kamar yang owner_id=0 / NULL → di-set ke pemilik default (dari email febri)
 * 4. SEMUA transaksi owner_id di-RESET & di-update JOIN kamar.owner_id
 * 5. Output diagnostik final status
 */

require_once __DIR__ . '/config/koneksi.php';

echo "<pre style='background:#0b1120;color:#e2e8f0;padding:24px 28px;border-radius:16px;font-family:Consolas,Menlo,monospace;font-size:13px;line-height:1.65;max-width:1100px;'>";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║        MASTER SYNC: owner_id Kamar C-102 & Semua Transaksi      ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$EMAIL_FEBRI = 'febririzal838@gmail.com';

// ──────────────────────────────────────────────────────────────────────
// STEP 0: Pastikan tabel pemilik & kamar & transaksi punya kolom owner_id
// ──────────────────────────────────────────────────────────────────────
echo "[0] Pemeriksaan & perbaikan skema kolom...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'owner_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE transaksi ADD COLUMN owner_id INT NOT NULL DEFAULT 0 AFTER id");
        echo "    ✓ Kolom owner_id DITAMBAHKAN ke tabel transaksi.\n";
    } else { echo "    ✓ Kolom transaksi.owner_id ADA.\n"; }
} catch (Throwable $e) { echo "    ✗ transaksi.owner_id: {$e->getMessage()}\n"; }

try {
    $col = $pdo->query("SHOW COLUMNS FROM kamar LIKE 'owner_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE kamar ADD COLUMN owner_id INT NOT NULL DEFAULT 0 AFTER id");
        echo "    ✓ Kolom owner_id DITAMBAHKAN ke tabel kamar.\n";
    } else { echo "    ✓ Kolom kamar.owner_id ADA.\n"; }
} catch (Throwable $e) { echo "    ✗ kamar.owner_id: {$e->getMessage()}\n"; }

try {
    $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'status'")->fetch();
    if ($col && strpos($col['Type'], 'Menunggu Checkout') === false) {
        $pdo->exec("ALTER TABLE transaksi MODIFY COLUMN status ENUM(
            'Menunggu','Menunggu Verifikasi','Pending','Disetujui','Ditolak','Lunas','Belum Bayar','Menunggu Checkout','Selesai'
        ) NOT NULL DEFAULT 'Menunggu Verifikasi'");
        echo "    ✓ ENUM status transaksi DIPERLUAS.\n";
    } else { echo "    ✓ ENUM status transaksi SUDAH LENGKAP.\n"; }
} catch (Throwable $e) { echo "    ✗ enum: {$e->getMessage()}\n"; }

echo "\n";

// ──────────────────────────────────────────────────────────────────────
// STEP 1: Dapatkan ID pemilik febri (jika tdk ada, auto-create)
// ──────────────────────────────────────────────────────────────────────
echo "[1] Cari / Buat akun pemilik dengan email = {$EMAIL_FEBRI}...\n";

$pemilikFebriId = 0;
$pemilikFebriNama = '';
try {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM pemilik WHERE LOWER(email) = LOWER(:em) LIMIT 1");
    $stmt->execute([':em' => $EMAIL_FEBRI]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $pemilikFebriId   = (int)$row['id'];
        $pemilikFebriNama = trim((string)($row['full_name'] ?? ''));
        echo "    ✓ DITEMUKAN → id = {$pemilikFebriId}, nama = " . ($pemilikFebriNama ?: '(null - akan diperbaiki)') . "\n";
        if ($pemilikFebriNama === '') {
            $namaBaru = 'Febri Rizal';
            $pdo->prepare("UPDATE pemilik SET full_name = :nm WHERE id = :id")->execute([
                ':nm' => $namaBaru, ':id' => $pemilikFebriId
            ]);
            echo "      → full_name diperbaiki menjadi '{$namaBaru}'.\n";
            $pemilikFebriNama = $namaBaru;
        }
    } else {
        // Belum ada → auto-create
        $namaBaru = 'Febri Rizal';
        $hash = password_hash('kelolakos123', PASSWORD_DEFAULT);
        try {
            $ins = $pdo->prepare(
                "INSERT INTO pemilik (full_name, email, password, phone_number) VALUES (:nm,:em,:pw,:hp)"
            );
            $ins->execute([
                ':nm' => $namaBaru,
                ':em' => $EMAIL_FEBRI,
                ':pw' => $hash,
                ':hp' => '081234567890',
            ]);
            $pemilikFebriId   = (int)$pdo->lastInsertId();
            $pemilikFebriNama = $namaBaru;
            echo "    ✓ DIBUAT BARU → id = {$pemilikFebriId}, nama = {$namaBaru}, pass = kelolakos123\n";
        } catch (Throwable $e) {
            // Jika gagal insert, coba ambil pemilik ID terkecil sebagai fallback
            $fId = (int)$pdo->query("SELECT COALESCE(MIN(id),0) FROM pemilik")->fetchColumn();
            if ($fId > 0) {
                $pemilikFebriId = $fId;
                $fNameRow = $pdo->query("SELECT full_name FROM pemilik WHERE id = {$fId}")->fetch();
                $pemilikFebriNama = trim((string)($fNameRow['full_name'] ?? 'Pemilik'));
                echo "    ⚠ Gagal insert. Fallback ke pemilik ID terkecil = {$fId}, nama = {$pemilikFebriNama}\n";
                echo "      Pesan error: {$e->getMessage()}\n";
            } else {
                echo "    ✗ TIDAK ADA PEMILIK SAMA SEKALI. Silakan buat akun pemilik di login_daftar.php?role=pemilik terlebih dahulu.\n";
            }
        }
    }
} catch (Throwable $e) { echo "    ✗ Error step 1: {$e->getMessage()}\n"; }

echo "    → Pemilik target akhir: ID = <b style='color:#fbbf24'>{$pemilikFebriId}</b> | Nama = {$pemilikFebriNama} | Email = {$EMAIL_FEBRI}\n\n";

if ($pemilikFebriId <= 0) {
    echo "🚫 STOP: Tidak bisa melanjutkan karena ID pemilik target = 0 / tidak ditemukan.\n\n";
    echo "</pre>";
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// STEP 2: KAMAR C-102 → dipaksa owner_id = $pemilikFebriId
// ──────────────────────────────────────────────────────────────────────
echo "[2] Force-sync kamar dengan nomor_kamar = 'C-102' ke owner_id #{$pemilikFebriId}...\n";

$c102Count = 0;
try {
    // Cari kamar C-102 (flexibel: case-insensitive + hapus spasi)
    $findC102 = $pdo->prepare(
        "SELECT id, nomor_kamar, owner_id FROM kamar
         WHERE REPLACE(UPPER(nomor_kamar),' ','') LIKE '%C102%'
            OR nomor_kamar = 'C-102' OR nomor_kamar = 'C 102' OR nomor_kamar = 'C102'"
    );
    $findC102->execute();
    $rowsC = $findC102->fetchAll(PDO::FETCH_ASSOC);

    if (!$rowsC) {
        echo "    ⚠ Kamar C-102 TIDAK DITEMUKAN. Membuat sample jika diinginkan... (SKIP)\n";
    } else {
        $updKmr = $pdo->prepare("UPDATE kamar SET owner_id = :oid WHERE id = :id");
        foreach ($rowsC as $r) {
            if ((int)$r['owner_id'] === $pemilikFebriId) {
                echo "    ✓ kamar id:{$r['id']} [{$r['nomor_kamar']}] → owner_id SUDAH BENAR ({$pemilikFebriId})\n";
            } else {
                $updKmr->execute([':oid' => $pemilikFebriId, ':id' => (int)$r['id']]);
                echo "    ✎ kamar id:{$r['id']} [{$r['nomor_kamar']}] → owner_id di-update: {$r['owner_id']} → {$pemilikFebriId}\n";
                $c102Count++;
            }
        }
    }
} catch (Throwable $e) { echo "    ✗ Error step 2: {$e->getMessage()}\n"; }
echo "    → Total kamar C-102 diperbaiki: <b style='color:#34d399'>{$c102Count}</b>\n\n";

// ──────────────────────────────────────────────────────────────────────
// STEP 3: SEMUA KAMAR LAIN yang owner_id = 0 / NULL → set ke $pemilikFebriId
// ──────────────────────────────────────────────────────────────────────
echo "[3] Sync SEMUA kamar LAIN dengan owner_id = 0 / NULL → owner_id #{$pemilikFebriId}...\n";

try {
    $stmt3 = $pdo->prepare(
        "UPDATE kamar SET owner_id = :oid
         WHERE owner_id IS NULL OR owner_id = 0"
    );
    $stmt3->execute([':oid' => $pemilikFebriId]);
    $n = $stmt3->rowCount();
    echo "    → <b style='color:#34d399'>{$n}</b> kamar lain di-update owner_id-nya.\n\n";
} catch (Throwable $e) { echo "    ✗ Error step 3: {$e->getMessage()}\n\n"; }

// ──────────────────────────────────────────────────────────────────────
// STEP 4: RESET & SYNC SEMUA TRANSAKSI.owner_id = kamar.owner_id
// ──────────────────────────────────────────────────────────────────────
echo "[4] Sync SEMUA transaksi (owner_id ← kamar.owner_id via JOIN)...\n";

try {
    $totalTx = (int)$pdo->query("SELECT COUNT(*) FROM transaksi")->fetchColumn();
    echo "    Total transaksi di tabel: {$totalTx}\n";

    $syncAll = $pdo->exec(
        "UPDATE transaksi t
         LEFT JOIN kamar k ON k.id = t.kamar_id
         SET t.owner_id = COALESCE(k.owner_id, {$pemilikFebriId})
         WHERE (t.owner_id IS NULL OR t.owner_id = 0 OR t.owner_id <> k.owner_id)"
    );
    echo "    → <b style='color:#34d399'>{$syncAll}</b> transaksi berhasil di-update owner_id.\n";

    // Pastikan transaksi yang kamarnya tidak ketemu (kamar_id null / invalid)
    // → di-set ke $pemilikFebriId (agar tidak hilang dari dasbor)
    $orphanStmt = $pdo->prepare(
        "UPDATE transaksi t
         SET t.owner_id = :oid
         WHERE (t.owner_id IS NULL OR t.owner_id = 0)
           AND (t.kamar_id IS NULL OR t.kamar_id = 0
                OR NOT EXISTS (SELECT 1 FROM kamar kk WHERE kk.id = t.kamar_id))"
    );
    $orphanStmt->execute([':oid' => $pemilikFebriId]);
    $orphan = $orphanStmt->rowCount();
    if ($orphan > 0) {
        echo "    → <b style='color:#f59e0b'>{$orphan}</b> transaksi ORPHAN (kamar tdk valid) di-set ke owner_id default.\n";
    }
} catch (Throwable $e) { echo "    ✗ Error step 4: {$e->getMessage()}\n"; }

echo "\n";

// ──────────────────────────────────────────────────────────────────────
// STEP 5: DIAGNOSTIK FINAL — tampilkan status per kamar / per transaksi
// ──────────────────────────────────────────────────────────────────────
echo str_repeat("─", 66) . "\n";
echo "[5] DIAGNOSTIK FINAL\n\n";

echo "─── Rekap KAMAR per owner_id ───\n";
try {
    foreach ($pdo->query(
        "SELECT owner_id, COALESCE(p.full_name,'(pemilik ?)') AS nama_pemilik, COUNT(*) AS jml
         FROM kamar k LEFT JOIN pemilik p ON p.id = k.owner_id
         GROUP BY owner_id, p.full_name ORDER BY owner_id ASC"
    )->fetchAll() as $r) {
        echo "    owner_id #{$r['owner_id']} | {$r['nama_pemilik']} = <b>{$r['jml']}</b> kamar\n";
    }
} catch (Throwable $e) { echo "    ERROR: {$e->getMessage()}\n"; }

echo "\n─── Rekap TRANSAKSI per owner_id ───\n";
try {
    foreach ($pdo->query(
        "SELECT t.owner_id, COALESCE(p.full_name,'(pemilik ?)') AS nama_pemilik, t.status, COUNT(*) AS jml
         FROM transaksi t LEFT JOIN pemilik p ON p.id = t.owner_id
         GROUP BY t.owner_id, p.full_name, t.status
         ORDER BY t.owner_id ASC, t.status ASC"
    )->fetchAll() as $r) {
        echo "    owner #{$r['owner_id']} | {$r['nama_pemilik']} | {$r['status']} = <b>{$r['jml']}</b> transaksi\n";
    }
} catch (Throwable $e) { echo "    ERROR: {$e->getMessage()}\n"; }

echo "\n─── Transaksi yang MASIH punya owner_id = 0 / NULL (HARUSNYA KOSONG) ───\n";
try {
    $sisa = $pdo->query(
        "SELECT COUNT(*) FROM transaksi WHERE owner_id IS NULL OR owner_id = 0"
    )->fetchColumn();
    echo "    → SISA: <b style='color:" . ((int)$sisa === 0 ? "#34d399" : "#f87171") . "'>{$sisa}</b> transaksi\n";
} catch (Throwable $e) { echo "    ERROR: {$e->getMessage()}\n"; }

echo "\n─── Kamar yang MASIH owner_id = 0 / NULL (HARUSNYA KOSONG) ───\n";
try {
    $sisaK = $pdo->query(
        "SELECT COUNT(*) FROM kamar WHERE owner_id IS NULL OR owner_id = 0"
    )->fetchColumn();
    echo "    → SISA: <b style='color:" . ((int)$sisaK === 0 ? "#34d399" : "#f87171") . "'>{$sisaK}</b> kamar\n";
} catch (Throwable $e) { echo "    ERROR: {$e->getMessage()}\n"; }

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                   ✅ MASTER SYNC SELESAI ✅                      ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  • Login sebagai febri → session user_id, owner_id, nama AKTIF  ║\n";
echo "║  • Kamar C-102 owner_id SUDAH ter-pasang ke Febri               ║\n";
echo "║  • SEMUA transaksi ter-sync owner_id ← kamar.owner_id           ║\n";
echo "║  • Ajuan sewa BARU = otomatis tampil di dasbor pemilik Febri    ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "</pre>";
