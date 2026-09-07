<?php
session_start();
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'penyewa') {
    header('Location: login_daftar.php?redirect=' . urlencode('cari_kos_logged_in.php'));
    exit;
}

$full_name = $_SESSION['full_name'] ?? 'Penyewa';
$initial = strtoupper(substr(strtok($full_name, ' '), 0, 1) ?: 'P');
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>KelolaKos - Cari Kost</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script id="tailwind-config">
  tailwind.config = { darkMode: 'class', theme: { extend: { colors: { 'primary': '#5341cd', 'secondary': '#006b55', 'background': '#f1fbff', 'surface': '#f1fbff', 'on-surface': '#131d21', 'on-surface-variant': '#474554', 'outline-variant': '#c8c4d7', 'surface-container': '#e4f0f4', 'surface-container-low': '#eaf5fa', 'primary-container': '#6c5ce7', 'on-primary': '#ffffff' }, borderRadius: { DEFAULT: '0.25rem', lg: '0.5rem', xl: '0.75rem', full: '9999px' }, spacing: { 'container-padding': '32px' } } } };
</script>
<style>
  body { min-height: 100vh; scroll-behavior: smooth; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
</style>
</head>
<body class="bg-background text-on-surface">
<nav class="sticky top-0 z-50 h-[72px] bg-surface/80 backdrop-blur-md flex items-center justify-between px-container-padding border-b border-outline-variant/30">
  <div class="flex items-center gap-3">
    <span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1;">domain</span>
    <span class="font-bold text-primary tracking-tight">KelolaKos</span>
  </div>
  <div class="hidden md:flex items-center gap-8">
    <a class="font-label-md text-label-md text-primary font-bold" href="cari_kos_logged_in.php">Cari Kost</a>
    <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="#fasilitas">Fasilitas</a>
    <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="#tentang-kami">Tentang Kami</a>
  </div>
  <div class="flex items-center gap-4">
    <div class="relative flex items-center gap-3 bg-primary-fixed/30 border border-primary/20 pl-4 pr-1.5 py-1.5 rounded-full">
      <div class="flex flex-col text-right">
        <span class="text-[11px] font-bold text-primary leading-none">Penyewa</span>
        <span class="text-xs font-semibold text-on-surface leading-normal mt-0.5"><?php echo htmlspecialchars($full_name); ?></span>
      </div>
      <a href="dashboard_penyewa.php" class="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-on-primary font-bold shadow-md shadow-primary/25">
        <?php echo htmlspecialchars($initial); ?>
      </a>
    </div>
  </div>
</nav>
<main class="w-full px-container-padding py-12">
  <div class="mx-auto max-w-[1200px] rounded-3xl border border-outline-variant bg-surface-container-low p-6">
    <h1 class="text-3xl font-bold text-on-surface">Cari Kost</h1>
    <p class="mt-2 text-on-surface-variant">Anda sedang login sebagai penyewa. Silakan pilih kamar yang tersedia.</p>
    <div class="mt-6 flex flex-col gap-4 md:flex-row">
      <a href="detail_kos.php?id=1" class="inline-flex items-center justify-center rounded-xl bg-primary px-5 py-3 text-sm font-bold text-on-primary">Lihat Detail Kamar</a>
      <a href="dashboard_penyewa.php" class="inline-flex items-center justify-center rounded-xl border border-outline-variant bg-white px-5 py-3 text-sm font-bold text-on-surface">Kembali ke Dashboard</a>
    </div>
  </div>
</main>
</body>
</html>
