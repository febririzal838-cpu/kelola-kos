<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/data_kamar.php';

$isLoggedIn = !empty($_SESSION['user']) || !empty($_SESSION['user_id']) || !empty($_SESSION['pemilik_id']) || !empty($_SESSION['penyewa_id']) || !empty($_SESSION['owner_id']);
$role = $_SESSION['role'] ?? (!empty($_SESSION['pemilik_id']) || !empty($_SESSION['owner_id']) ? 'pemilik' : 'penyewa');
$fullName = $_SESSION['full_name'] ?? $_SESSION['nama'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['nama'] ?? $_SESSION['user']['full_name'] ?? 'User') : 'User');
$initial = strtoupper(substr(strtok((string)$fullName, ' '), 0, 1) ?: 'U');
$dashboardUrl = ($role === 'pemilik') ? 'dashboard_pemilik_kos.php' : 'dashboard_penyewa.php';
$roleLabel = ($role === 'pemilik') ? 'Pemilik Kos' : 'Penyewa';
$rooms = getKamarList();

// Cek apakah penyewa punya transaksi 'Menunggu Verifikasi'
$hasPending = false;
if ($isLoggedIn) {
    try {
        $uid = (int)($_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? 0);
        if ($uid > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM transaksi WHERE penyewa_id = :uid AND status IN ('Menunggu','Menunggu Verifikasi','Pending')");
            $chk->execute([':uid' => $uid]);
            $hasPending = (int)$chk->fetchColumn() > 0;
        }
    } catch (PDOException $e) {
        $hasPending = false;
    }
}

$detailLink = function (int $roomId) {
    return 'detail_kos.php?id=' . $roomId;
};
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
    tailwind.config = { darkMode: 'class', theme: { extend: { colors: { primary: '#5341cd', secondary: '#006b55', background: '#f1fbff', surface: '#f1fbff', 'on-surface': '#131d21', 'on-surface-variant': '#474554', 'outline-variant': '#c8c4d7', 'surface-container': '#e4f0f4', 'surface-container-low': '#eaf5fa', 'primary-container': '#6c5ce7', 'on-primary': '#ffffff' }, borderRadius: { DEFAULT: '0.25rem', lg: '0.5rem', xl: '0.75rem', full: '9999px' }, spacing: { 'container-padding': '32px' } } } };
  </script>
  <style>
    body { min-height: 100vh; scroll-behavior: smooth; }
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    .property-card { transition: all 0.3s ease; }
    .property-card:hover { transform: translateY(-4px); box-shadow: 0 14px 28px rgba(83,65,205,0.12); }
  </style>
</head>
<body class="bg-background text-on-surface">
  <nav class="sticky top-0 z-50 h-[72px] bg-surface/80 backdrop-blur-md flex items-center justify-between px-container-padding border-b border-outline-variant/30">
    <div class="flex items-center gap-3">
      <a href="index.php" class="flex items-center gap-3">
        <span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1;">domain</span>
        <span class="font-bold text-primary tracking-tight">KelolaKos</span>
      </a>
    </div>
    <div class="hidden md:flex items-center gap-8">
      <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="index.php">Beranda</a>
      <a class="font-label-md text-label-md text-primary font-bold" href="cari_kos.php">Cari Kost</a>
    </div>
    <div class="flex items-center gap-4">
      <?php if ($isLoggedIn): ?>
        <div class="relative flex items-center gap-3 bg-primary/5 border border-primary/20 pl-4 pr-1.5 py-1.5 rounded-full">
          <div class="flex flex-col text-right">
            <span class="text-[11px] font-bold text-primary leading-none"><?php echo htmlspecialchars($roleLabel); ?></span>
            <span class="text-xs font-semibold text-on-surface leading-normal mt-0.5"><?php echo htmlspecialchars($fullName); ?></span>
          </div>
          <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-on-primary font-bold shadow-md shadow-primary/25">
            <?php echo htmlspecialchars($initial); ?>
          </a>
        </div>
      <?php else: ?>
        <button type="button" onclick="openLoginModal()" title="Masuk ke Akun" class="flex items-center gap-2 px-4 py-2 rounded-full border border-primary/30 bg-primary/5 text-primary hover:bg-primary hover:text-white transition-all duration-300 shadow-sm font-semibold text-sm group">
          <span class="material-symbols-outlined text-2xl group-hover:scale-110 transition-transform">account_circle</span>
          <span class="font-label-md text-label-md">Masuk</span>
        </button>
      <?php endif; ?>
    </div>
  </nav>

  <main class="w-full px-container-padding py-12">
    <!-- Banner Ruang Tunggu -->
    <?php if ($isLoggedIn && $hasPending): ?>
    <div class="mx-auto max-w-[1200px] mb-6">
      <a href="riwayat_penyewa.php" class="flex items-center gap-3 bg-amber-50 border border-amber-300 text-amber-800 rounded-2xl px-5 py-4 hover:bg-amber-100 transition-all group">
        <span class="material-symbols-outlined text-amber-500 text-2xl flex-shrink-0" style="font-variation-settings:'FILL' 1">schedule</span>
        <div class="flex-1">
          <p class="font-bold text-sm">⏳ Pengajuan Sewa Sedang MENUNGGU VERIFIKASI Pemilik Kos</p>
          <p class="text-xs text-amber-700 mt-0.5">Anda memiliki pengajuan yang sedang diproses. Klik di sini untuk melihat status pesanan.</p>
        </div>
        <span class="material-symbols-outlined text-amber-500 group-hover:translate-x-1 transition-transform">arrow_forward</span>
      </a>
    </div>
    <?php endif; ?>
    <div class="mx-auto max-w-[1200px] rounded-3xl border border-outline-variant bg-surface-container-low p-6">
      <h1 class="text-3xl font-bold text-on-surface">Cari Kost</h1>
      <p class="mt-2 text-on-surface-variant">
        Pilih hunian terbaik sesuai kebutuhanmu. Klik "Lihat Detail" untuk melihat informasi lengkap kamar, foto, serta fasilitas secara publik.
      </p>
      <div class="mt-6 grid gap-6 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <?php 
        $availableRooms = array_filter($rooms, function($r) {
            return strtolower(trim($r['status'] ?? '')) === 'kosong';
        });
        if (empty($availableRooms)): ?>
          <div class="col-span-full py-12 text-center bg-white rounded-2xl border border-outline-variant/30 p-8 shadow-sm">
            <span class="material-symbols-outlined text-5xl text-primary/40">home_work</span>
            <p class="mt-4 text-on-surface font-semibold text-lg">Semua kamar kost saat ini sedang terisi.</p>
            <p class="text-sm text-on-surface-variant mt-1">Silakan hubungi pemilik kos atau kembali lagi nanti.</p>
          </div>
        <?php else: ?>
          <?php foreach ($availableRooms as $room):
            $roomId = (int) $room['id'];
            $href = $detailLink($roomId);
          ?>
            <div class="property-card group flex flex-col overflow-hidden rounded-[20px] border border-outline-variant/30 bg-surface-container-lowest">
              <div class="relative h-48 overflow-hidden">
                <img class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" src="<?php echo htmlspecialchars($room['image']); ?>" alt="<?php echo htmlspecialchars($room['name']); ?>" />
                <div class="absolute left-3 top-3 flex flex-col gap-1.5">
                  <span class="rounded-full bg-secondary px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-on-secondary shadow-md leading-none">Tersedia</span>
                </div>
              </div>
              <div class="flex flex-1 flex-col p-5">
                <div class="space-y-1">
                  <span class="inline-block rounded bg-primary/10 px-2.5 py-0.5 text-[11px] font-bold text-primary"><?php echo htmlspecialchars($room['type']); ?></span>
                  <h3 class="font-headline-sm text-headline-sm font-bold text-on-background line-clamp-1 group-hover:text-primary transition-colors"><?php echo htmlspecialchars($room['name']); ?></h3>
                  <p class="flex items-center gap-1 text-body-md text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px] text-primary">location_on</span> <?php echo htmlspecialchars($room['location']); ?>
                  </p>
                  <?php if (!empty($room['owner_name'])): ?>
                  <p class="flex items-center gap-1 text-[11px] text-on-surface-variant/70 mt-0.5">
                    <span class="material-symbols-outlined text-[13px]">person</span>
                    <?php echo htmlspecialchars($room['owner_name']); ?>
                  </p>
                  <?php endif; ?>
                </div>
                <div class="mt-4 flex flex-wrap gap-1.5">
                  <?php foreach ($room['amenities'] as $amenity): ?>
                    <span class="inline-flex items-center gap-1 rounded-full bg-surface-container px-2.5 py-1 text-xs text-on-surface-variant"><?php echo htmlspecialchars($amenity); ?></span>
                  <?php endforeach; ?>
                </div>
                <div class="mt-auto border-t border-outline-variant/30 pt-4 flex items-center justify-between gap-2">
                  <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Mulai dari</p>
                    <p class="text-headline-sm font-extrabold text-primary"><?php echo htmlspecialchars($room['price']); ?><span class="text-xs font-normal text-on-surface-variant">/bln</span></p>
                  </div>
                  <a class="rounded-xl bg-primary px-4 py-2.5 text-label-md font-label-md font-bold text-on-primary hover:bg-primary-container transition-all shadow-md shadow-primary/15 hover:shadow-primary/25 active:scale-95" href="<?php echo htmlspecialchars($href); ?>">Lihat Detail</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </main>

<div id="login-choice-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-md transition-opacity duration-300 opacity-0 pointer-events-none">
  <div class="absolute inset-0" onclick="closeLoginModal()"></div>
  <div class="relative z-10 w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-white p-8 text-slate-800 shadow-2xl transition-all duration-300 scale-95 transform" id="login-modal-box">
    <button onclick="closeLoginModal()" type="button" class="absolute top-5 right-5 flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 border border-slate-200 text-slate-500 hover:bg-slate-200 hover:text-slate-800 transition-all duration-200" aria-label="Tutup Modal">
      <span class="material-symbols-outlined text-xl">close</span>
    </button>

    <div class="text-center space-y-3 mb-8">
      <div class="inline-flex items-center justify-center gap-2.5 px-4 py-2 rounded-2xl bg-primary/10 border border-primary/20">
        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">domain</span>
        <span class="font-extrabold tracking-tight text-xl text-slate-800">Kelola<span class="text-primary">Kos</span></span>
      </div>
      <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight pt-1">Masuk ke Kelola Kos</h2>
      <p class="text-sm font-medium text-slate-500">Saya ingin masuk sebagai</p>
    </div>

    <div class="grid gap-4">
      <a href="login_daftar.php?role=penyewa&redirect=cari_kos.php" class="group relative flex items-center gap-4.5 rounded-2xl border border-purple-200 bg-purple-50/60 p-5 transition-all duration-300 hover:scale-[1.02] hover:border-purple-300 hover:bg-purple-100/80 hover:shadow-md">
        <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-purple-600 text-white shadow-md shadow-purple-200 group-hover:scale-110 transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">meeting_room</span>
        </div>
        <div class="flex-1">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 group-hover:text-purple-700 transition-colors">Pencari Kos</h3>
            <span class="material-symbols-outlined text-slate-400 group-hover:translate-x-1 group-hover:text-purple-700 transition-all text-xl">arrow_forward</span>
          </div>
          <p class="text-xs text-slate-600 mt-1 leading-relaxed">Cari & sewa kamar kos impian dengan mudah dan transparan.</p>
        </div>
      </a>

      <a href="login_daftar.php?role=pemilik&redirect=cari_kos.php" class="group relative flex items-center gap-4.5 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 transition-all duration-300 hover:scale-[1.02] hover:border-amber-300 hover:bg-amber-100/80 hover:shadow-md">
        <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-amber-600 text-white shadow-md shadow-amber-200 group-hover:scale-110 transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">real_estate_agent</span>
        </div>
        <div class="flex-1">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 group-hover:text-amber-700 transition-colors">Pemilik Kos</h3>
            <span class="material-symbols-outlined text-slate-400 group-hover:translate-x-1 group-hover:text-amber-700 transition-all text-xl">arrow_forward</span>
          </div>
          <p class="text-xs text-slate-600 mt-1 leading-relaxed">Kelola properti, penyewa, & laporan keuangan kos Anda.</p>
        </div>
      </a>
    </div>

    <p class="text-center text-[11px] text-slate-500 mt-6">
      Belum memiliki akun? Pilih peran di atas untuk langsung mendaftar.
    </p>
  </div>
</div>

<script>
  function openLoginModal() {
    const modal = document.getElementById('login-choice-modal');
    const modalBox = document.getElementById('login-modal-box');
    if (!modal) return;
    modal.classList.remove('hidden');
    setTimeout(() => {
      modal.classList.remove('opacity-0', 'pointer-events-none');
      if (modalBox) modalBox.classList.remove('scale-95');
    }, 10);
  }

  function closeLoginModal() {
    const modal = document.getElementById('login-choice-modal');
    const modalBox = document.getElementById('login-modal-box');
    if (!modal) return;
    modal.classList.add('opacity-0', 'pointer-events-none');
    if (modalBox) modalBox.classList.add('scale-95');
    setTimeout(() => {
      modal.classList.add('hidden');
    }, 300);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeLoginModal();
  });
</script>

</body>
</html>
