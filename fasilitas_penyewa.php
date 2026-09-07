<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'penyewa') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai penyewa.') . '&type=error');
    exit;
}

$full_name  = $_SESSION['full_name'] ?? 'Penyewa';
$first_name = explode(' ', trim($full_name))[0] ?? $full_name;
$userId     = (int)($_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? 0);
$initials   = strtoupper(substr(trim($full_name), 0, 1)) ?: 'P';
$parts      = preg_split('/\s+/', trim($full_name));
if (count($parts) > 1) {
    $initials = strtoupper(substr($parts[0], 0, 1)) . strtoupper(substr($parts[count($parts)-1], 0, 1));
}

// ── Ambil data kamar aktif dari transaksi Disetujui/Lunas ─────────────────
$activeRoom   = null;
$fasilitasList = [];

if ($userId > 0) {
    // Get tenant's current kamar_id
    try {
        $kamarStmt = $pdo->prepare("SELECT kamar_id FROM penyewa WHERE id = :id");
        $kamarStmt->execute([':id' => $userId]);
        $currKamarId = $kamarStmt->fetchColumn();
    } catch (Throwable $e) {
        $currKamarId = null;
    }

    // Ambil info kamar penyewa (hanya tampilkan jika kamar aktif)
    try {
        // Pastikan transaksi terbaru berstatus Disetujui/Lunas/Menunggu Checkout
        // untuk menyaring kamar yang sudah dinonaktifkan/checkout
        $stmt = $pdo->prepare(
            "SELECT t.id AS txn_id, t.status AS txn_status, t.created_at,
                    k.id AS kamar_id, k.nomor_kamar, k.tipe_kamar, k.harga_sewa,
                    k.fasilitas,
                    COALESCE(pm.full_name, 'Pemilik Kos') AS pemilik_nama
             FROM transaksi t
             LEFT JOIN kamar k  ON k.id  = t.kamar_id
             LEFT JOIN pemilik pm ON pm.id = k.owner_id
             WHERE t.penyewa_id = :uid
               AND t.kamar_id = :kamar_id
               AND LOWER(t.status) IN ('disetujui','lunas','approved','paid','menunggu checkout')
             ORDER BY t.created_at DESC
             LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':kamar_id' => $currKamarId]);
        $activeRoom = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeRoom && !empty($activeRoom['fasilitas'])) {
            // Fasilitas tersimpan sebagai teks dipisah koma
            $raw = preg_split('/[,;\n]+/', $activeRoom['fasilitas']);
            $fasilitasList = array_filter(array_map('trim', $raw));
        }
    } catch (PDOException $e) {
        $activeRoom = null;
    }
}

// ── Peta ikon & warna tiap fasilitas ──────────────────────────────────────
$fasilitasIcons = [
    'wifi'              => ['icon' => 'wifi',               'color' => 'blue',   'label' => 'WiFi'],
    'ac'                => ['icon' => 'ac_unit',            'color' => 'cyan',   'label' => 'AC'],
    'ac / kipas angin'  => ['icon' => 'ac_unit',            'color' => 'cyan',   'label' => 'AC / Kipas Angin'],
    'kipas angin'       => ['icon' => 'mode_fan',           'color' => 'teal',   'label' => 'Kipas Angin'],
    'kamar mandi dalam' => ['icon' => 'bathtub',            'color' => 'indigo', 'label' => 'Kamar Mandi Dalam'],
    'kamar mandi'       => ['icon' => 'bathtub',            'color' => 'indigo', 'label' => 'Kamar Mandi'],
    'kasur'             => ['icon' => 'bed',                'color' => 'violet', 'label' => 'Kasur'],
    'lemari'            => ['icon' => 'door_sliding',       'color' => 'amber',  'label' => 'Lemari'],
    'meja belajar'      => ['icon' => 'table_restaurant',   'color' => 'orange', 'label' => 'Meja Belajar'],
    'kursi'             => ['icon' => 'chair',              'color' => 'yellow', 'label' => 'Kursi'],
    'dapur bersama'     => ['icon' => 'local_dining',       'color' => 'rose',   'label' => 'Dapur Bersama'],
    'parkir motor'      => ['icon' => 'two_wheeler',        'color' => 'slate',  'label' => 'Parkir Motor'],
    'parkir mobil'      => ['icon' => 'directions_car',     'color' => 'slate',  'label' => 'Parkir Mobil'],
    'listrik'           => ['icon' => 'bolt',               'color' => 'yellow', 'label' => 'Listrik'],
    'air bersih'        => ['icon' => 'water_drop',         'color' => 'blue',   'label' => 'Air Bersih'],
    'laundry'           => ['icon' => 'local_laundry_service','color' => 'purple','label' => 'Laundry'],
    'tv'                => ['icon' => 'tv',                 'color' => 'gray',   'label' => 'TV'],
    'kulkas'            => ['icon' => 'kitchen',            'color' => 'cyan',   'label' => 'Kulkas'],
    'security'          => ['icon' => 'security',           'color' => 'green',  'label' => 'Keamanan 24 Jam'],
    'cctv'              => ['icon' => 'videocam',           'color' => 'red',    'label' => 'CCTV'],
    'ruang tamu'        => ['icon' => 'weekend',            'color' => 'orange', 'label' => 'Ruang Tamu'],
    'teras'             => ['icon' => 'deck',               'color' => 'green',  'label' => 'Teras'],
    'balkon'            => ['icon' => 'balcony',            'color' => 'lime',   'label' => 'Balkon'],
    'gym'               => ['icon' => 'fitness_center',     'color' => 'red',    'label' => 'Gym'],
    'kolam renang'      => ['icon' => 'pool',               'color' => 'blue',   'label' => 'Kolam Renang'],
];

// Warna palet per color key
$colorMap = [
    'blue'   => ['bg' => 'bg-blue-50',   'icon' => 'text-blue-500',   'border' => 'border-blue-100'],
    'cyan'   => ['bg' => 'bg-cyan-50',   'icon' => 'text-cyan-500',   'border' => 'border-cyan-100'],
    'teal'   => ['bg' => 'bg-teal-50',   'icon' => 'text-teal-500',   'border' => 'border-teal-100'],
    'indigo' => ['bg' => 'bg-indigo-50', 'icon' => 'text-indigo-500', 'border' => 'border-indigo-100'],
    'violet' => ['bg' => 'bg-violet-50', 'icon' => 'text-violet-500', 'border' => 'border-violet-100'],
    'purple' => ['bg' => 'bg-purple-50', 'icon' => 'text-purple-500', 'border' => 'border-purple-100'],
    'amber'  => ['bg' => 'bg-amber-50',  'icon' => 'text-amber-500',  'border' => 'border-amber-100'],
    'orange' => ['bg' => 'bg-orange-50', 'icon' => 'text-orange-500', 'border' => 'border-orange-100'],
    'yellow' => ['bg' => 'bg-yellow-50', 'icon' => 'text-yellow-500', 'border' => 'border-yellow-100'],
    'rose'   => ['bg' => 'bg-rose-50',   'icon' => 'text-rose-500',   'border' => 'border-rose-100'],
    'red'    => ['bg' => 'bg-red-50',    'icon' => 'text-red-500',    'border' => 'border-red-100'],
    'green'  => ['bg' => 'bg-green-50',  'icon' => 'text-green-500',  'border' => 'border-green-100'],
    'lime'   => ['bg' => 'bg-lime-50',   'icon' => 'text-lime-500',   'border' => 'border-lime-100'],
    'slate'  => ['bg' => 'bg-slate-50',  'icon' => 'text-slate-500',  'border' => 'border-slate-100'],
    'gray'   => ['bg' => 'bg-gray-50',   'icon' => 'text-gray-500',   'border' => 'border-gray-100'],
];

// Fallback icon colors (cycling)
$fallbackColors = ['blue','indigo','violet','teal','cyan','amber','rose','green','orange','purple'];
$colorCycle = 0;

// Resolve setiap fasilitas ke icon+warna
function resolveFasilitas(string $item, array $icons, array $colorMap, int &$cycle, array $fallbackColors): array {
    $key = strtolower(trim($item));
    if (isset($icons[$key])) {
        $c = $icons[$key]['color'];
        return [
            'icon'   => $icons[$key]['icon'],
            'label'  => $icons[$key]['label'],
            'bg'     => $colorMap[$c]['bg']     ?? 'bg-slate-50',
            'iconCls'=> $colorMap[$c]['icon']   ?? 'text-slate-500',
            'border' => $colorMap[$c]['border'] ?? 'border-slate-100',
        ];
    }
    // Fallback: tebak icon dari kata kunci
    $guessIcon  = 'star';
    $guessLabel = ucwords($item);
    if (stripos($item, 'wifi') !== false || stripos($item, 'internet') !== false) { $guessIcon = 'wifi'; }
    elseif (stripos($item, 'ac') !== false) { $guessIcon = 'ac_unit'; }
    elseif (stripos($item, 'kasur') !== false || stripos($item, 'bed') !== false) { $guessIcon = 'bed'; }
    elseif (stripos($item, 'parkir') !== false) { $guessIcon = 'local_parking'; }
    elseif (stripos($item, 'listrik') !== false) { $guessIcon = 'bolt'; }
    elseif (stripos($item, 'air') !== false) { $guessIcon = 'water_drop'; }
    elseif (stripos($item, 'dapur') !== false) { $guessIcon = 'local_dining'; }
    elseif (stripos($item, 'laundry') !== false) { $guessIcon = 'local_laundry_service'; }
    elseif (stripos($item, 'lemari') !== false) { $guessIcon = 'door_sliding'; }
    elseif (stripos($item, 'meja') !== false) { $guessIcon = 'table_restaurant'; }

    $c = $fallbackColors[$cycle % count($fallbackColors)];
    $cycle++;
    return [
        'icon'    => $guessIcon,
        'label'   => $guessLabel,
        'bg'      => $colorMap[$c]['bg']    ?? 'bg-slate-50',
        'iconCls' => $colorMap[$c]['icon']  ?? 'text-slate-500',
        'border'  => $colorMap[$c]['border']?? 'border-slate-100',
    ];
}

// Jika tidak ada fasilitas dari DB tapi kamar aktif ada → tampilkan set default
$defaultFasilitas = ['WiFi', 'AC', 'Kasur', 'Lemari', 'Kamar Mandi Dalam', 'Dapur Bersama', 'Listrik', 'Air Bersih', 'Parkir Motor'];
if ($activeRoom && empty($fasilitasList)) {
    $fasilitasList = $defaultFasilitas;
}

$resolvedFasilitas = [];
foreach ($fasilitasList as $item) {
    $resolvedFasilitas[] = resolveFasilitas($item, $fasilitasIcons, $colorMap, $colorCycle, $fallbackColors);
}
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Fasilitas Kamar - KelolaKos</title>
  <meta name="description" content="Lihat daftar fasilitas kamar kos yang Anda huni di KelolaKos." />
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
  <script id="tailwind-config">
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          colors: {
            "primary": "#5341cd",
            "primary-container": "#6c5ce7",
            "on-primary": "#ffffff",
            "secondary": "#00b4d8",
            "surface": "#f8faff",
            "surface-container-lowest": "#ffffff",
            "surface-container-low": "#f3f4fb",
            "surface-container": "#edeef8",
            "on-surface": "#1a1a2e",
            "on-surface-variant": "#5a5b72",
            "outline-variant": "#d4d4e8",
          },
          fontFamily: { sans: ['Inter', 'sans-serif'] },
          fontSize: {
            "headline-md": ["24px", { lineHeight: "32px", fontWeight: "600" }],
            "headline-sm": ["20px", { lineHeight: "28px", fontWeight: "600" }],
            "label-md": ["13px", { lineHeight: "18px", letterSpacing: "0.05em", fontWeight: "600" }],
            "label-sm": ["12px", { lineHeight: "16px", fontWeight: "500" }],
            "body-md": ["14px", { lineHeight: "20px", fontWeight: "400" }],
          },
          spacing: { "container-padding": "2rem" },
        },
      },
    }
  </script>
  <style>
    body { font-family: 'Inter', sans-serif; background: #f8faff; min-height: 100dvh; }
    .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: #5a5b72; transition: all 0.2s; }
    .sidebar-link:hover { background: #edeef8; color: #1a1a2e; border-radius: 0; }
    .sidebar-link.active { color: #5341cd; font-weight: 700; border-left: 4px solid #5341cd; background: #f3f4fb; }
    .fasilitas-card {
      background: #ffffff;
      border-radius: 1rem;
      border: 1px solid #e5e7f4;
      padding: 1.25rem;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.75rem;
    }
    .fasilitas-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(83,65,205,0.12); }
    .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; vertical-align: middle; }
    #profile-panel { display: none; }
    #profile-panel.show { display: block; }
  </style>
</head>
<body class="text-on-surface">

<!-- ── Sidebar ────────────────────────────────────────────────────────────── -->
<aside class="fixed left-0 top-0 h-full w-[260px] bg-white flex flex-col border-r border-outline-variant shadow-[0px_4px_12px_rgba(0,0,0,0.05)] z-50">
  <a class="p-6 flex flex-col gap-1" href="cari_kos.php">
    <span class="text-xl font-bold text-primary">KelolaKos</span>
    <span class="text-xs text-on-surface-variant">Property Management</span>
  </a>
  <nav class="flex-1 mt-2">
    <a class="sidebar-link" href="dashboard_penyewa.php">
      <span class="material-symbols-outlined">dashboard</span>
      <span class="text-sm">Dashboard</span>
    </a>
    <a class="sidebar-link" href="cari_kos.php">
      <span class="material-symbols-outlined">domain</span>
      <span class="text-sm">Cari Kos</span>
    </a>
    <a class="sidebar-link" href="riwayat_penyewa.php">
      <span class="material-symbols-outlined">payments</span>
      <span class="text-sm">Transaksi</span>
    </a>
    <a class="sidebar-link" href="kirim_pengaduan.php">
      <span class="material-symbols-outlined">assessment</span>
      <span class="text-sm">Pengaduan</span>
    </a>
    <a class="sidebar-link active" href="fasilitas_penyewa.php">
      <span class="material-symbols-outlined">room_service</span>
      <span class="text-sm">Fasilitas</span>
    </a>
  </nav>
  <div class="mt-auto pb-4">
    <a class="sidebar-link" href="logout.php">
      <span class="material-symbols-outlined">logout</span>
      <span class="text-sm">Keluar</span>
    </a>
  </div>
</aside>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<header class="sticky top-0 h-[72px] bg-white/95 backdrop-blur-md flex justify-between items-center px-8 w-full max-w-[1440px] ml-[260px] z-40 border-b border-slate-200 shadow-sm">
  <div class="flex items-center gap-3">
    <span class="material-symbols-outlined text-primary text-2xl">room_service</span>
    <h1 class="text-xl font-bold text-slate-800">Fasilitas Kamar</h1>
  </div>
  <div class="flex items-center gap-2">
    <!-- Profile dropdown -->
    <div class="relative">
      <button id="profile-btn"
              class="flex items-center gap-3 pl-4 pr-1 py-1 border-l-2 border-slate-200 hover:bg-slate-50 rounded-full transition-all"
              type="button">
        <div class="text-right">
          <p class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($full_name) ?></p>
          <p class="text-xs text-slate-400 leading-tight">
            <?php if ($activeRoom): ?>
              <span class="text-emerald-600 font-semibold">● Kamar <?= htmlspecialchars($activeRoom['nomor_kamar'] ?? 'Aktif') ?></span>
            <?php else: ?>
              Belum Ada Kamar
            <?php endif; ?>
          </p>
        </div>
        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary to-[#6c5ce7] flex items-center justify-center text-white font-bold text-sm shadow-md flex-shrink-0">
          <?= htmlspecialchars($initials) ?>
        </div>
        <span class="material-symbols-outlined text-slate-400 text-[18px]">expand_more</span>
      </button>
      <div id="profile-panel" class="absolute right-0 top-full mt-2 w-56 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl z-[9999]">
        <div class="px-3 py-2 mb-2 border-b border-slate-100">
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Akun Anda</p>
          <p class="text-sm font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($full_name) ?></p>
        </div>
        <a href="riwayat_penyewa.php" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition">
          <span class="material-symbols-outlined text-[16px] text-primary">receipt_long</span> Riwayat Transaksi
        </a>
        <a href="fasilitas_penyewa.php" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-primary font-semibold hover:bg-primary/5 transition">
          <span class="material-symbols-outlined text-[16px] text-primary">room_service</span> Fasilitas Kamar
        </a>
        <a href="kirim_pengaduan.php" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition">
          <span class="material-symbols-outlined text-[16px] text-primary">support_agent</span> Kirim Pengaduan
        </a>
        <div class="border-t border-slate-100 mt-2 pt-2">
          <a href="logout.php" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-rose-600 hover:bg-rose-50 transition">
            <span class="material-symbols-outlined text-[16px]">logout</span> Keluar
          </a>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- ── Main Content ───────────────────────────────────────────────────────── -->
<main class="ml-[260px] px-8 py-8 max-w-[1440px]">

  <?php if (!$activeRoom): ?>
  <!-- ══ EMPTY STATE ══════════════════════════════════════════════════════════ -->
  <div class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">
    <div class="w-24 h-24 rounded-3xl bg-gradient-to-br from-primary/10 to-[#6c5ce7]/10 flex items-center justify-center mb-6 shadow-inner">
      <span class="material-symbols-outlined text-5xl text-primary/60">bed</span>
    </div>
    <h2 class="text-2xl font-bold text-slate-800 mb-3">Belum Ada Kamar Aktif</h2>
    <p class="text-slate-500 max-w-md leading-relaxed">
      Anda belum memiliki kamar kos yang aktif. Temukan hunian yang sesuai dan ajukan sewa untuk melihat detail fasilitas kamar Anda.
    </p>
    <div class="flex flex-wrap gap-3 mt-8 justify-center">
      <a href="cari_kos.php"
         class="inline-flex items-center gap-2 bg-primary text-white font-semibold px-6 py-3 rounded-full shadow-md shadow-primary/30 hover:bg-primary/90 transition-all active:scale-95">
        <span class="material-symbols-outlined text-base">search</span>
        Cari Kos Sekarang
      </a>
      <a href="riwayat_penyewa.php"
         class="inline-flex items-center gap-2 border border-slate-300 text-slate-700 font-semibold px-6 py-3 rounded-full hover:bg-slate-50 transition-all">
        <span class="material-symbols-outlined text-base">receipt_long</span>
        Lihat Transaksi
      </a>
    </div>
  </div>

  <?php else: ?>
  <!-- ══ KAMAR AKTIF HEADER ═══════════════════════════════════════════════════ -->
  <div class="space-y-8">

    <!-- Room Summary Card -->
    <div class="rounded-3xl bg-gradient-to-r from-primary via-[#5a4de0] to-[#6c5ce7] p-8 text-white shadow-xl shadow-primary/25 relative overflow-hidden">
      <!-- Decorative circles -->
      <div class="absolute -top-12 -right-12 w-48 h-48 rounded-full bg-white/5"></div>
      <div class="absolute -bottom-8 -left-8 w-32 h-32 rounded-full bg-white/5"></div>
      <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
        <div>
          <p class="text-sm font-medium text-white/70 uppercase tracking-widest mb-1">Kamar Aktif Anda</p>
          <h2 class="text-3xl font-extrabold">
            Kamar <?= htmlspecialchars($activeRoom['nomor_kamar'] ?? '-') ?>
          </h2>
          <p class="text-white/80 mt-1 text-base"><?= htmlspecialchars($activeRoom['tipe_kamar'] ?? 'Tipe Standar') ?></p>
          <p class="text-white/60 text-sm mt-1">Dikelola oleh <?= htmlspecialchars($activeRoom['pemilik_nama'] ?? 'Pemilik Kos') ?></p>
        </div>
        <div class="text-left md:text-right">
          <p class="text-sm text-white/70">Harga Sewa / Bulan</p>
          <p class="text-3xl font-extrabold mt-1">
            Rp <?= number_format((float)($activeRoom['harga_sewa'] ?? 0), 0, ',', '.') ?>
          </p>
          <div class="flex flex-wrap gap-2 mt-3 md:justify-end">
            <span class="inline-flex items-center gap-1 bg-white/20 backdrop-blur px-3 py-1.5 rounded-full text-xs font-bold">
              <span class="material-symbols-outlined text-[14px]">check_circle</span>
              <?= htmlspecialchars($activeRoom['txn_status'] ?? 'Aktif') ?>
            </span>
            <span class="inline-flex items-center gap-1 bg-white/20 backdrop-blur px-3 py-1.5 rounded-full text-xs font-bold">
              <span class="material-symbols-outlined text-[14px]">event</span>
              Sejak <?= date('d M Y', strtotime($activeRoom['created_at'] ?? 'now')) ?>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Fasilitas Grid -->
    <div>
      <div class="flex items-center gap-3 mb-6">
        <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center">
          <span class="material-symbols-outlined text-primary">room_service</span>
        </div>
        <div>
          <h3 class="text-lg font-bold text-slate-800">Daftar Fasilitas</h3>
          <p class="text-sm text-slate-500"><?= count($resolvedFasilitas) ?> fasilitas tersedia di kamar ini</p>
        </div>
      </div>

      <?php if (empty($resolvedFasilitas)): ?>
      <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
        <span class="material-symbols-outlined text-4xl text-slate-300 mb-3">inventory_2</span>
        <p class="text-slate-500 font-medium">Data fasilitas belum tersedia untuk kamar ini.</p>
        <p class="text-sm text-slate-400 mt-1">Hubungi pemilik kos untuk informasi lebih lanjut.</p>
      </div>
      <?php else: ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
        <?php foreach ($resolvedFasilitas as $i => $f): ?>
        <div class="fasilitas-card" style="animation-delay: <?= $i * 0.05 ?>s">
          <div class="w-14 h-14 rounded-2xl <?= $f['bg'] ?> <?= $f['border'] ?> border-2 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-3xl <?= $f['iconCls'] ?>"><?= htmlspecialchars($f['icon']) ?></span>
          </div>
          <p class="text-sm font-semibold text-slate-700 leading-tight"><?= htmlspecialchars($f['label']) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($activeRoom['deskripsi'])): ?>
    <!-- Deskripsi Kamar -->
    <div class="rounded-2xl bg-white border border-slate-200 p-6 shadow-sm">
      <h3 class="font-bold text-slate-800 mb-3 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-xl">description</span>
        Deskripsi Kamar
      </h3>
      <p class="text-slate-600 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($activeRoom['deskripsi'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Info & CTA -->
    <div class="rounded-2xl bg-white border border-slate-200 p-6 shadow-sm">
      <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary text-xl">info</span>
        Informasi Penting
      </h3>
      <ul class="space-y-2.5 text-sm text-slate-600">
        <li class="flex items-start gap-2.5">
          <span class="material-symbols-outlined text-[15px] text-emerald-500 mt-0.5">check_circle</span>
          Fasilitas kamar dapat berubah sesuai kebijakan pemilik kos.
        </li>
        <li class="flex items-start gap-2.5">
          <span class="material-symbols-outlined text-[15px] text-amber-500 mt-0.5">warning</span>
          Jika ada fasilitas yang rusak atau tidak berfungsi, segera laporkan ke pemilik kos.
        </li>
        <li class="flex items-start gap-2.5">
          <span class="material-symbols-outlined text-[15px] text-blue-500 mt-0.5">support_agent</span>
          Gunakan fitur Pengaduan untuk melaporkan masalah fasilitas secara tertulis.
        </li>
      </ul>
      <div class="flex flex-wrap gap-3 mt-5">
        <a href="kirim_pengaduan.php"
           class="inline-flex items-center gap-2 bg-primary text-white font-semibold text-sm px-5 py-2.5 rounded-full shadow-md shadow-primary/20 hover:bg-primary/90 transition-all active:scale-95">
          <span class="material-symbols-outlined text-base">support_agent</span>
          Laporkan Masalah Fasilitas
        </a>
        <a href="dashboard_penyewa.php"
           class="inline-flex items-center gap-2 border border-slate-300 text-slate-700 font-semibold text-sm px-5 py-2.5 rounded-full hover:bg-slate-50 transition-all">
          <span class="material-symbols-outlined text-base">dashboard</span>
          Kembali ke Dashboard
        </a>
      </div>
    </div>

  </div>
  <?php endif; ?>
</main>

<script>
  // Profile dropdown toggle
  const profileBtn   = document.getElementById('profile-btn');
  const profilePanel = document.getElementById('profile-panel');

  profileBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    profilePanel?.classList.toggle('show');
  });

  document.addEventListener('click', (e) => {
    if (!profileBtn?.contains(e.target) && !profilePanel?.contains(e.target)) {
      profilePanel?.classList.remove('show');
    }
  });

  // Entrance animation for cards
  document.querySelectorAll('.fasilitas-card').forEach((el, i) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(16px)';
    setTimeout(() => {
      el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    }, 60 + i * 50);
  });
</script>
</body>
</html>
