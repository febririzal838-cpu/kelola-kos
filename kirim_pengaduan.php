<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

$msg = trim($_GET['msg'] ?? '');
$type = trim($_GET['type'] ?? 'success');
$messageType = $type === 'error' ? 'error' : 'success';

if (!isset($_SESSION['user_id'])) {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login terlebih dahulu untuk mengajukan pengaduan.') . '&type=error');
    exit;
}

function escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>KelolaKos - Pengaduan Penyewa</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
</head>
<body class="bg-background text-on-surface min-h-screen">
  <nav class="sticky top-0 z-40 border-b border-outline-variant/30 bg-surface/80 backdrop-blur-md">
    <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
      <?php
      $logoUrl = 'dashboard_penyewa.php';
      if (isset($_SESSION['role']) && $_SESSION['role'] === 'pemilik') {
          $logoUrl = 'dashboard_pemilik_kos.php';
      }
      ?>
      <a href="<?= $logoUrl ?>" class="flex items-center gap-3">
        <span class="material-symbols-outlined text-primary text-3xl">domain</span>
        <span class="font-semibold text-primary">KelolaKos</span>
      </a>
      <div class="flex items-center gap-4">
        <a href="dashboard_penyewa.php" class="text-sm font-semibold text-on-surface-variant hover:text-primary">Dashboard</a>
        <a href="login_daftar.php" class="rounded-full bg-primary px-4 py-2 text-sm font-semibold text-on-primary">Logout</a>
      </div>
    </div>
  </nav>

  <main class="mx-auto max-w-5xl px-6 py-10">
    <div class="rounded-2xl border border-outline-variant/30 bg-surface-container-lowest p-8 shadow-sm">
      <?php if ($msg): ?>
        <div class="mb-6 rounded-xl px-4 py-3 <?= $messageType === 'error' ? 'bg-error-container text-error border border-error' : 'bg-secondary-container text-on-secondary-container border border-secondary' ?>">
          <?= escape($msg) ?>
        </div>
      <?php endif; ?>
      <p class="text-sm font-semibold uppercase tracking-[0.2em] text-primary">Form Pengaduan Penyewa</p>
      <h1 class="mt-2 text-3xl font-bold text-on-surface">Ajukan keluhan Anda</h1>
      <p class="mt-2 text-sm text-on-surface-variant">Halaman ini khusus untuk penyewa. Isi detail pengaduan dan kirim untuk ditangani pemilik kos.</p>

      <form action="pengaduan_action.php" method="POST" class="mt-8 space-y-5">
        <div>
          <label class="mb-2 block text-sm font-semibold text-on-surface">Nomor Kamar</label>
          <input name="nomor_kamar" required class="w-full rounded-lg border border-outline-variant bg-surface-container p-3 text-sm" placeholder="Contoh: A-01" />
        </div>
        <div>
          <label class="mb-2 block text-sm font-semibold text-on-surface">Jenis Pengaduan</label>
          <select name="jenis_pengaduan" required class="w-full rounded-lg border border-outline-variant bg-surface-container p-3 text-sm">
            <option value="Masalah fasilitas kamar">Masalah fasilitas kamar</option>
            <option value="Masalah kebersihan">Masalah kebersihan</option>
            <option value="Masalah keamanan">Masalah keamanan</option>
            <option value="Masalah pembayaran">Masalah pembayaran</option>
          </select>
        </div>
        <div>
          <label class="mb-2 block text-sm font-semibold text-on-surface">Detail Keluhan</label>
          <textarea name="deskripsi" rows="5" required class="min-h-32 w-full rounded-lg border border-outline-variant bg-surface-container p-3 text-sm" placeholder="Jelaskan kendala yang Anda alami..."></textarea>
        </div>
        <div>
          <label class="mb-2 block text-sm font-semibold text-on-surface">Prioritas</label>
          <select name="prioritas" class="w-full rounded-lg border border-outline-variant bg-surface-container p-3 text-sm">
            <option value="Normal">Normal</option>
            <option value="Segera">Segera</option>
            <option value="Kritis">Kritis</option>
          </select>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
          <a href="dashboard_penyewa.php" class="inline-flex justify-center rounded-lg border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">Batal</a>
          <button type="submit" class="inline-flex justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:opacity-90">Kirim Pengaduan</button>
        </div>
      </form>
    </div>
  </main>
</body>
</html>
