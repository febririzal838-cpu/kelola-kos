<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai pemilik kos untuk melakukan aksi kamar.') . '&type=error');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
if ($owner_id <= 0) {
    header('Location: login_daftar.php?msg=' . urlencode('Sesi pemilik tidak valid. Silakan login kembali.') . '&type=error');
    exit;
}

function redirect($message, $type = 'success') {
    header('Location: manajemen_kamar.php?msg=' . urlencode($message) . '&type=' . $type);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('Metode permintaan tidak valid.', 'error');
}

$action = $_POST['action'] ?? '';
$validActions = ['add', 'edit', 'delete'];
if (!in_array($action, $validActions, true)) {
    redirect('Aksi tidak valid.', 'error');
}

try {
    if ($action === 'add') {
        $nomor_kamar = trim($_POST['nomor_kamar'] ?? '');
        $tipe = trim($_POST['tipe_kamar'] ?? $_POST['tipe'] ?? '');
        $fasilitas = trim($_POST['fasilitas'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $harga = (int)($_POST['harga_sewa'] ?? $_POST['harga'] ?? 0);
        $status = trim($_POST['status'] ?? 'Kosong');

        if ($nomor_kamar === '' || $tipe === '' || $harga <= 0) {
            redirect('Lengkapi semua kolom wajib dan gunakan nilai harga yang valid.', 'error');
        }

        $stmt = $pdo->prepare('INSERT INTO kamar (owner_id, nomor_kamar, tipe_kamar, fasilitas, alamat, harga_sewa, status) VALUES (:owner_id, :nomor_kamar, :tipe_kamar, :fasilitas, :alamat, :harga_sewa, :status)');
        $stmt->execute([
            ':owner_id' => $owner_id,
            ':nomor_kamar' => $nomor_kamar,
            ':tipe_kamar' => $tipe,
            ':fasilitas' => $fasilitas,
            ':alamat' => $alamat,
            ':harga_sewa' => $harga,
            ':status' => $status,
        ]);

        redirect('Kamar berhasil ditambahkan.');
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $nomor_kamar = trim($_POST['nomor_kamar'] ?? '');
        $tipe = trim($_POST['tipe_kamar'] ?? $_POST['tipe'] ?? '');
        $fasilitas = trim($_POST['fasilitas'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $harga = (int)($_POST['harga_sewa'] ?? $_POST['harga'] ?? 0);
        $status = trim($_POST['status'] ?? 'Kosong');

        if ($id <= 0 || $nomor_kamar === '' || $tipe === '' || $harga <= 0) {
            redirect('Lengkapi semua kolom wajib dan gunakan nilai harga yang valid.', 'error');
        }

        $stmt = $pdo->prepare('UPDATE kamar SET nomor_kamar = :nomor_kamar, tipe_kamar = :tipe_kamar, fasilitas = :fasilitas, alamat = :alamat, harga_sewa = :harga_sewa, status = :status WHERE id = :id AND owner_id = :owner_id');
        $stmt->execute([
            ':nomor_kamar' => $nomor_kamar,
            ':tipe_kamar' => $tipe,
            ':fasilitas' => $fasilitas,
            ':alamat' => $alamat,
            ':harga_sewa' => $harga,
            ':status' => $status,
            ':id' => $id,
            ':owner_id' => $owner_id,
        ]);

        redirect('Data kamar berhasil diperbarui.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            redirect('ID kamar tidak valid.', 'error');
        }

        $stmt = $pdo->prepare('DELETE FROM kamar WHERE id = :id AND owner_id = :owner_id');
        $stmt->execute([':id' => $id, ':owner_id' => $owner_id]);

        redirect('Kamar berhasil dihapus.');
    }
} catch (PDOException $e) {
    redirect('Terjadi kesalahan database: ' . $e->getMessage(), 'error');
}
