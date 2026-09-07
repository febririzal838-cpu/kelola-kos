<?php
/**
 * proses_checkout.php
 * Menangani dua aksi:
 *   1. action=ajukan  — dari Penyewa: ubah status transaksi → 'Menunggu Checkout'
 *   2. action=konfirmasi — dari Pemilik: ubah status → 'Selesai', kamar → 'Kosong'
 */
session_start();
require_once __DIR__ . '/config/koneksi.php';

// ── Helper: tambah 'Menunggu Checkout' ke ENUM jika belum ada ─────────────
function ensureCheckoutStatus(PDO $pdo): void
{
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos($col['Type'], 'Menunggu Checkout') === false) {
            // Tambahkan nilai ENUM baru
            $pdo->exec("ALTER TABLE transaksi
                MODIFY COLUMN status ENUM(
                    'Menunggu','Menunggu Verifikasi','Pending',
                    'Disetujui','Ditolak','Lunas','Belum Bayar',
                    'Menunggu Checkout','Selesai'
                ) NOT NULL DEFAULT 'Menunggu Verifikasi'");
        }
    } catch (PDOException $e) {
        // Lanjutkan meski ALTER gagal (misal: status sudah ada)
    }
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ════════════════════════════════════════════════════════
//  AKSI 1: PENYEWA — Ajukan Selesai Sewa (Checkout)
// ════════════════════════════════════════════════════════
if ($action === 'ajukan') {
    // Validasi sesi penyewa
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'penyewa') {
        header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai penyewa.') . '&type=error');
        exit;
    }

    $userId    = (int)($_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? 0);
    $transaksiId = (int)($_POST['transaksi_id'] ?? 0);

    if ($userId <= 0 || $transaksiId <= 0) {
        header('Location: riwayat_penyewa.php?msg=' . urlencode('Data tidak valid.') . '&type=error');
        exit;
    }

    try {
        ensureCheckoutStatus($pdo);

        // Pastikan transaksi ini benar-benar milik penyewa yang login & statusnya Disetujui/Lunas
        $checkStmt = $pdo->prepare(
            "SELECT id, kamar_id, status FROM transaksi
             WHERE id = :id AND penyewa_id = :uid
               AND LOWER(status) IN ('disetujui','lunas','approved','paid')
             LIMIT 1"
        );
        $checkStmt->execute([':id' => $transaksiId, ':uid' => $userId]);
        $txn = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            header('Location: riwayat_penyewa.php?msg=' . urlencode('Transaksi tidak ditemukan atau tidak dapat diajukan checkout.') . '&type=error');
            exit;
        }

        // Update status → 'Menunggu Checkout'
        $pdo->prepare(
            "UPDATE transaksi SET status = 'Menunggu Checkout', updated_at = NOW()
             WHERE id = :id AND penyewa_id = :uid"
        )->execute([':id' => $transaksiId, ':uid' => $userId]);

        header('Location: riwayat_penyewa.php?msg=' . urlencode('Pengajuan selesai sewa berhasil dikirim. Menunggu konfirmasi pemilik kos.') . '&type=success');
        exit;

    } catch (PDOException $e) {
        header('Location: riwayat_penyewa.php?msg=' . urlencode('Gagal mengajukan checkout: ' . $e->getMessage()) . '&type=error');
        exit;
    }
}

// ════════════════════════════════════════════════════════
//  AKSI 2: PEMILIK KOS — Konfirmasi Selesai Sewa
// ════════════════════════════════════════════════════════
if ($action === 'konfirmasi') {
    // Validasi sesi pemilik
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
        header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai pemilik kos.') . '&type=error');
        exit;
    }

    $ownerId     = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
    $transaksiId = (int)($_POST['transaksi_id'] ?? 0);

    if ($ownerId <= 0 || $transaksiId <= 0) {
        header('Location: verifikasi_pembayaran.php?msg=' . urlencode('Data tidak valid.') . '&type=error');
        exit;
    }

    try {
        ensureCheckoutStatus($pdo);

        // Ambil data transaksi — pastikan kamarnya milik pemilik ini
        $checkStmt = $pdo->prepare(
            "SELECT t.id, t.kamar_id, t.penyewa_id
             FROM transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             WHERE t.id = :id
               AND t.status = 'Menunggu Checkout'
               AND k.owner_id = :oid
             LIMIT 1"
        );
        $checkStmt->execute([':id' => $transaksiId, ':oid' => $ownerId]);
        $txn = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            header('Location: verifikasi_pembayaran.php?msg=' . urlencode('Transaksi tidak ditemukan atau tidak berhak dikonfirmasi.') . '&type=error');
            exit;
        }

        $pdo->beginTransaction();

        // 1. Update transaksi → 'Selesai'
        $pdo->prepare(
            "UPDATE transaksi SET status = 'Selesai', updated_at = NOW()
             WHERE id = :id"
        )->execute([':id' => $txn['id']]);

        // 2. Kembalikan kamar → 'Kosong'
        $pdo->prepare(
            "UPDATE kamar SET status = 'Kosong' WHERE id = :kamar_id"
        )->execute([':kamar_id' => $txn['kamar_id']]);

        // 3. Putuskan relasi penyewa-kamar
        $pdo->prepare(
            "UPDATE penyewa SET kamar_id = NULL WHERE id = :penyewa_id"
        )->execute([':penyewa_id' => $txn['penyewa_id']]);

        $pdo->commit();

        header('Location: verifikasi_pembayaran.php?msg=' . urlencode('Checkout berhasil dikonfirmasi. Kamar telah dikembalikan ke status Kosong.') . '&type=success');
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Location: verifikasi_pembayaran.php?msg=' . urlencode('Gagal konfirmasi checkout: ' . $e->getMessage()) . '&type=error');
        exit;
    }
}

// Jika action tidak dikenali
header('Location: dashboard_penyewa.php');
exit;
