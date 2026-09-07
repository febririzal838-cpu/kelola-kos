<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai pemilik kos untuk mengunduh laporan keuangan.') . '&type=error');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$filename = sprintf('laporan_keuangan_%04d_%02d.csv', $year, $month);

try {
    $stmt = $pdo->prepare('SELECT nomor_kamar, tipe_kamar, status, harga_sewa FROM kamar WHERE owner_id = :owner_id ORDER BY nomor_kamar');
    $stmt->execute([':owner_id' => $owner_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Location: laporan_keuangan.php?msg=' . urlencode('Gagal membuat file ekspor: ' . $e->getMessage()) . '&type=error');
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');
fputcsv($output, ['Nomor Kamar', 'Tipe', 'Status', 'Harga']);
foreach ($rows as $row) {
    fputcsv($output, [$row['nomor_kamar'], $row['tipe_kamar'] ?? $row['tipe'] ?? '', $row['status'], $row['harga_sewa'] ?? $row['harga'] ?? 0]);
}
fclose($output);
exit;
