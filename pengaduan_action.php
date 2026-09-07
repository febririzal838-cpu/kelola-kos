<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kirim_pengaduan.php?msg=' . urlencode('Metode permintaan tidak valid.') . '&type=error');
    exit;
}

$tenant_id = (int)($_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? $_SESSION['id'] ?? 0);
if ($tenant_id <= 0) {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan masuk terlebih dahulu untuk mengirim pengaduan.') . '&type=error');
    exit;
}

$nomor_kamar = trim($_POST['nomor_kamar'] ?? '');
$pengaduan_type = trim($_POST['jenis_pengaduan'] ?? '');
$deskripsi = trim($_POST['deskripsi'] ?? '');
$priority = trim($_POST['prioritas'] ?? 'Normal');

if ($nomor_kamar === '' || $pengaduan_type === '' || $deskripsi === '') {
    header('Location: kirim_pengaduan.php?msg=' . urlencode('Lengkapi semua kolom pengaduan.') . '&type=error');
    exit;
}

try {
    $roomStmt = $pdo->prepare('SELECT id, owner_id FROM kamar WHERE nomor_kamar = :nomor_kamar LIMIT 1');
    $roomStmt->execute([':nomor_kamar' => $nomor_kamar]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        header('Location: kirim_pengaduan.php?msg=' . urlencode('Kamar tidak ditemukan. Periksa nomor kamar dan coba lagi.') . '&type=error');
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO pengaduan (owner_id, tenant_id, kamar_id, nomor_kamar, jenis_pengaduan, deskripsi, prioritas, status, created_at) VALUES (:owner_id, :tenant_id, :kamar_id, :nomor_kamar, :jenis_pengaduan, :deskripsi, :prioritas, :status, NOW())');
    $stmt->execute([
        ':owner_id' => $room['owner_id'],
        ':tenant_id' => $tenant_id,
        ':kamar_id' => $room['id'],
        ':nomor_kamar' => $nomor_kamar,
        ':jenis_pengaduan' => $pengaduan_type,
        ':deskripsi' => $deskripsi,
        ':prioritas' => $priority,
        ':status' => 'Baru',
    ]);

    header('Location: kirim_pengaduan.php?msg=' . urlencode('Pengaduan Anda berhasil dikirim.') . '&type=success');
    exit;
} catch (PDOException $e) {
    header('Location: kirim_pengaduan.php?msg=' . urlencode('Gagal mengirim pengaduan: ' . $e->getMessage()) . '&type=error');
    exit;
}
