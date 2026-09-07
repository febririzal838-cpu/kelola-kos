<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'penyewa') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai penyewa untuk melihat riwayat transaksi.') . '&type=error');
    exit;
}

$full_name   = $_SESSION['full_name'] ?? 'Penyewa';
$first_name  = explode(' ', trim($full_name))[0] ?? $full_name;
$userId      = (int)($_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? 0);
$initials    = strtoupper(substr(trim($full_name), 0, 1)) ?: 'P';
$parts       = preg_split('/\s+/', trim($full_name));
if (count($parts) > 1) {
    $initials = strtoupper(substr($parts[0], 0, 1)) . strtoupper(substr($parts[count($parts)-1], 0, 1));
}

// ── Handle Hapus Riwayat Transaksi Selesai/Ditolak ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_txn') {
    $txnId = (int)($_POST['transaksi_id'] ?? 0);
    if ($txnId > 0 && $userId > 0) {
        try {
            $delStmt = $pdo->prepare("DELETE FROM transaksi WHERE id = :id AND penyewa_id = :uid AND status IN ('Selesai', 'Ditolak', 'Gagal', 'Rejected')");
            $delStmt->execute([':id' => $txnId, ':uid' => $userId]);
            if ($delStmt->rowCount() > 0) {
                header('Location: riwayat_penyewa.php?msg=' . urlencode('Riwayat transaksi berhasil dihapus.') . '&type=success');
            } else {
                header('Location: riwayat_penyewa.php?msg=' . urlencode('Transaksi tidak dapat dihapus atau status belum selesai.') . '&type=error');
            }
            exit;
        } catch (PDOException $e) {
            header('Location: riwayat_penyewa.php?msg=' . urlencode('Gagal menghapus riwayat transaksi: ' . $e->getMessage()) . '&type=error');
            exit;
        }
    }
}

// ── Ambil SEMUA transaksi milik penyewa ini ───────────────────────────────
$transactions     = [];
$hasPending       = false;
$hasApproved      = false;
$hasCheckout      = false;   // status 'Menunggu Checkout'
$activeRoom       = null;
$checkoutTxnId    = null;    // ID transaksi yg sedang menunggu checkout

if ($userId > 0) {
    // Get tenant's current kamar_id
    try {
        $kamarStmt = $pdo->prepare("SELECT kamar_id FROM penyewa WHERE id = :id");
        $kamarStmt->execute([':id' => $userId]);
        $currKamarId = $kamarStmt->fetchColumn();
    } catch (Throwable $e) {
        $currKamarId = null;
    }

    try {
        $tStmt = $pdo->prepare(
            "SELECT t.id, t.kamar_id, t.total_harga AS nominal, t.status, t.created_at,
                    k.nomor_kamar, k.tipe_kamar, k.harga_sewa,
                    COALESCE(pm.full_name, 'Pemilik Kos') AS pemilik_nama
             FROM transaksi t
             LEFT JOIN kamar k  ON k.id  = t.kamar_id
             LEFT JOIN pemilik pm ON pm.id = k.owner_id
             WHERE t.penyewa_id = :uid
             ORDER BY t.created_at DESC"
        );
        $tStmt->execute([':uid' => $userId]);
        $transactions = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions as $txn) {
            $s = strtolower(trim($txn['status'] ?? ''));
            if (in_array($s, ['menunggu', 'menunggu verifikasi', 'pending'], true)) {
                $hasPending = true;
            }
            if (in_array($s, ['disetujui', 'lunas', 'approved', 'paid', 'menunggu checkout'], true)) {
                if ($s === 'menunggu checkout' && !$activeRoom) {
                    $hasCheckout   = true;
                    $activeRoom    = $txn;
                    $checkoutTxnId = (int)$txn['id'];
                }
                if (in_array($s, ['disetujui', 'lunas', 'approved', 'paid'], true) && !$activeRoom) {
                    $hasApproved = true;
                    $activeRoom  = $txn;
                }
            }
        }
    } catch (PDOException $e) {
        $transactions = [];
    }
}

// ── Helper: badge style ────────────────────────────────────────────────────
function statusBadge(string $status): array
{
    $s = strtolower(trim($status));
    if (in_array($s, ['lunas', 'sukses', 'approved', 'paid', 'disetujui'], true)) {
        return ['bg-emerald-100 text-emerald-700 border-emerald-200', 'check_circle', 'Disetujui / Lunas'];
    }
    if ($s === 'menunggu checkout') {
        return ['bg-orange-100 text-orange-700 border-orange-200', 'logout', 'Menunggu Checkout'];
    }
    if ($s === 'selesai') {
        return ['bg-slate-100 text-slate-600 border-slate-300', 'done_all', 'Selesai'];
    }
    if (in_array($s, ['menunggu', 'menunggu verifikasi', 'pending', 'waiting'], true)) {
        return ['bg-amber-100 text-amber-700 border-amber-200', 'schedule', 'Menunggu Verifikasi'];
    }
    if (in_array($s, ['menunggak', 'overdue', 'terlambat'], true)) {
        return ['bg-red-100 text-red-700 border-red-200', 'warning', 'Menunggak'];
    }
    if (in_array($s, ['ditolak', 'rejected', 'gagal'], true)) {
        return ['bg-slate-100 text-slate-600 border-slate-200', 'cancel', 'Ditolak'];
    }
    return ['bg-blue-100 text-blue-700 border-blue-200', 'info', ucfirst($status)];
}

function formatRupiah(float $n): string
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}

// ── Ambil notifikasi dinamis ──────────────────────────────────────────
$notificationsList = [];
if ($userId > 0) {
    try {
        // 1. Ambil transaksi sewa terakhir
        $notifTxnStmt = $pdo->prepare(
            "SELECT t.id, k.nomor_kamar, t.status, t.updated_at, t.created_at
             FROM transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             WHERE t.penyewa_id = :uid
             ORDER BY COALESCE(t.updated_at, t.created_at) DESC
             LIMIT 3"
        );
        $notifTxnStmt->execute([':uid' => $userId]);
        $notifTxns = $notifTxnStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifTxns as $nt) {
            $status = strtolower(trim($nt['status']));
            $timeStr = !empty($nt['updated_at']) ? $nt['updated_at'] : $nt['created_at'];
            $timeAgo = 'Baru saja';
            if ($timeStr) {
                $diff = time() - strtotime($timeStr);
                if ($diff < 60) {
                    $timeAgo = 'Baru saja';
                } elseif ($diff < 3600) {
                    $timeAgo = floor($diff / 60) . ' menit lalu';
                } elseif ($diff < 86400) {
                    $timeAgo = floor($diff / 3600) . ' jam lalu';
                } else {
                    $timeAgo = floor($diff / 86400) . ' hari lalu';
                }
            }

            if (in_array($status, ['disetujui', 'lunas', 'approved', 'paid'], true)) {
                $notificationsList[] = [
                    'title' => 'Pengajuan Sewa Disetujui! 🎉',
                    'desc' => 'Pengajuan sewa Kamar ' . htmlspecialchars($nt['nomor_kamar']) . ' telah disetujui.',
                    'time' => $timeAgo,
                    'bg' => 'bg-emerald-50 text-emerald-800 border-emerald-100'
                ];
            } elseif (in_array($status, ['menunggu', 'menunggu verifikasi', 'pending'], true)) {
                $notificationsList[] = [
                    'title' => 'Menunggu Verifikasi ⏳',
                    'desc' => 'Pengajuan sewa Kamar ' . htmlspecialchars($nt['nomor_kamar']) . ' sedang ditinjau pemilik.',
                    'time' => $timeAgo,
                    'bg' => 'bg-amber-50 text-amber-800 border-amber-100'
                ];
            } elseif (in_array($status, ['ditolak', 'rejected'], true)) {
                $notificationsList[] = [
                    'title' => 'Pengajuan Sewa Ditolak ❌',
                    'desc' => 'Pengajuan sewa Kamar ' . htmlspecialchars($nt['nomor_kamar']) . ' ditolak pemilik.',
                    'time' => $timeAgo,
                    'bg' => 'bg-red-50 text-red-800 border-red-100'
                ];
            } elseif ($status === 'menunggu checkout') {
                $notificationsList[] = [
                    'title' => 'Proses Selesai Sewa ⏳',
                    'desc' => 'Pengajuan selesai sewa Kamar ' . htmlspecialchars($nt['nomor_kamar']) . ' sedang diproses.',
                    'time' => $timeAgo,
                    'bg' => 'bg-orange-50 text-orange-800 border-orange-100'
                ];
            } elseif ($status === 'selesai') {
                $notificationsList[] = [
                    'title' => 'Masa Sewa Selesai 🏁',
                    'desc' => 'Kontrak sewa Kamar ' . htmlspecialchars($nt['nomor_kamar']) . ' telah berakhir.',
                    'time' => $timeAgo,
                    'bg' => 'bg-slate-50 text-slate-700 border-slate-200'
                ];
            }
        }

        // 2. Ambil pengaduan terakhir
        $notifComplaintStmt = $pdo->prepare(
            "SELECT p.id, p.nomor_kamar, p.status, p.created_at
             FROM pengaduan p
             LEFT JOIN penyewa s ON s.kamar_id = p.kamar_id
             WHERE (s.id = :uid OR p.nama_penyewa = :full_name)
             ORDER BY p.created_at DESC
             LIMIT 2"
        );
        $notifComplaintStmt->execute([':uid' => $userId, ':full_name' => $full_name]);
        $notifComplaints = $notifComplaintStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notifComplaints as $nc) {
            $cStatus = strtolower(trim($nc['status']));
            $timeAgo = 'Baru saja';
            if ($nc['created_at']) {
                $diff = time() - strtotime($nc['created_at']);
                if ($diff < 60) {
                    $timeAgo = 'Baru saja';
                } elseif ($diff < 3600) {
                    $timeAgo = floor($diff / 60) . ' menit lalu';
                } elseif ($diff < 86400) {
                    $timeAgo = floor($diff / 3600) . ' jam lalu';
                } else {
                    $timeAgo = floor($diff / 86400) . ' hari lalu';
                }
            }
            $statusLabel = 'diproses';
            $bg = 'bg-blue-50 text-blue-800 border-blue-100';
            if ($cStatus === 'selesai') {
                $statusLabel = 'selesai diselesaikan';
                $bg = 'bg-emerald-50 text-emerald-800 border-emerald-100';
            } elseif ($cStatus === 'baru') {
                $statusLabel = 'diterima dan akan ditinjau';
            }
            $notificationsList[] = [
                'title' => 'Update Pengaduan 🛠️',
                'desc' => 'Pengaduan Kamar ' . htmlspecialchars($nc['nomor_kamar']) . ' status: ' . $statusLabel . '.',
                'time' => $timeAgo,
                'bg' => $bg
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }
}

if (empty($notificationsList)) {
    $notificationsList[] = [
        'title' => 'Belum ada notifikasi',
        'desc' => 'Aktivitas pengajuan & laporan sewa Anda akan muncul di sini.',
        'time' => '',
        'bg' => 'bg-slate-50 text-slate-600 border-slate-100'
    ];
}
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transaksi & Tagihan - KelolaKos</title>
  <meta name="description" content="Lihat seluruh riwayat transaksi dan tagihan sewa kos Anda di KelolaKos." />
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
  <script id="tailwind-config">
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          colors: {
            "on-primary-fixed-variant": "#4029ba",
            "background": "#f1fbff", "on-background": "#131d21",
            "tertiary-fixed-dim": "#ffb4a3", "surface-container-low": "#eaf5fa",
            "error-container": "#ffdad6", "surface-tint": "#5847d2",
            "primary-container": "#6c5ce7", "tertiary-container": "#b95239",
            "on-error": "#ffffff", "secondary": "#006b55",
            "on-primary-container": "#faf6ff",
            "surface-container": "#e4f0f4",
            "surface-container-highest": "#d9e4e9",
            "on-secondary-fixed-variant": "#005140",
            "secondary-fixed": "#6dfad2", "inverse-surface": "#283236",
            "surface-variant": "#d9e4e9", "on-tertiary": "#ffffff",
            "on-surface": "#131d21", "on-secondary-container": "#00725b",
            "secondary-fixed-dim": "#4bddb7", "outline-variant": "#c8c4d7",
            "surface-container-high": "#dfeaef", "outline": "#787586",
            "primary-fixed-dim": "#c6bfff", "on-primary-fixed": "#160066",
            "on-primary": "#ffffff", "on-secondary": "#ffffff",
            "tertiary": "#993a24", "tertiary-fixed": "#ffdad2",
            "secondary-container": "#6dfad2", "on-surface-variant": "#474554",
            "on-error-container": "#93000a", "surface-bright": "#f1fbff",
            "error": "#ba1a1a", "surface": "#f1fbff", "primary-fixed": "#e4dfff",
            "inverse-primary": "#c6bfff", "surface-container-lowest": "#ffffff",
            "on-tertiary-container": "#fff6f4", "inverse-on-surface": "#e7f3f7",
            "on-secondary-fixed": "#002018", "primary": "#5341cd", "surface-dim": "#d1dce0"
          },
          borderRadius: { DEFAULT: "0.25rem", lg: "0.5rem", xl: "0.75rem", full: "9999px" },
          spacing: {
            "stack-sm": "8px", "stack-lg": "24px", "topbar-height": "72px",
            "sidebar-width": "260px", "stack-md": "16px",
            "container-padding": "32px", "gutter": "24px"
          },
          fontFamily: {
            "display-lg": ["Inter"], "headline-md-mobile": ["Inter"],
            "body-lg": ["Inter"], "label-md": ["Inter"],
            "headline-md": ["Inter"], "headline-sm": ["Inter"],
            "label-sm": ["Inter"], "body-md": ["Inter"]
          },
          fontSize: {
            "display-lg": ["32px", {"lineHeight":"40px","letterSpacing":"-0.02em","fontWeight":"700"}],
            "headline-md-mobile": ["20px", {"lineHeight":"28px","fontWeight":"600"}],
            "body-lg": ["16px", {"lineHeight":"24px","fontWeight":"400"}],
            "label-md": ["13px", {"lineHeight":"18px","letterSpacing":"0.05em","fontWeight":"600"}],
            "headline-md": ["24px", {"lineHeight":"32px","letterSpacing":"-0.01em","fontWeight":"600"}],
            "headline-sm": ["20px", {"lineHeight":"28px","fontWeight":"600"}],
            "label-sm": ["12px", {"lineHeight":"16px","fontWeight":"500"}],
            "body-md": ["14px", {"lineHeight":"20px","fontWeight":"400"}]
          }
        }
      }
    }
  </script>
  <style>
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; vertical-align: middle; }
    body { font-family: 'Inter', sans-serif; background-color: #f1fbff; min-height: max(884px, 100dvh); }
    .dashboard-card { background-color: #ffffff; border: 1px solid rgba(120,117,134,0.16); border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #f1fbff; }
    ::-webkit-scrollbar-thumb { background: #d9e4e9; border-radius: 10px; }
    .txn-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .txn-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(83,65,205,0.10); }

    /* Modal overlay */
    #waiting-modal { display: none; }
    #waiting-modal.show { display: flex; }
  </style>
</head>
<body class="bg-surface text-on-surface">

<?php if ($showWaitingPopup): ?>
<!-- ── Popup Ruang Tunggu ─────────────────────────────────────────────────── -->
<div id="waiting-modal" class="show fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
  <div class="bg-white rounded-3xl shadow-2xl max-w-sm w-full p-8 text-center animate-bounce-in">
    <div class="w-20 h-20 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-5">
      <span class="material-symbols-outlined text-amber-500 text-5xl" style="font-variation-settings:'FILL' 1">schedule</span>
    </div>
    <h2 class="text-xl font-bold text-on-surface">Pengajuan Sewa Berhasil! 🎉</h2>
    <p class="mt-3 text-sm text-on-surface-variant leading-relaxed">
      Pesanan Anda saat ini berada di<br>
      <strong class="text-amber-600">⏳ RUANG TUNGGU VERIFIKASI PEMILIK KOS</strong>
    </p>
    <p class="mt-2 text-xs text-on-surface-variant">Pemilik kos akan meninjau pengajuan Anda dalam 1×24 jam. Anda akan mendapatkan konfirmasi setelah disetujui.</p>
    <div class="mt-6 flex flex-col gap-2">
      <button onclick="document.getElementById('waiting-modal').classList.remove('show')"
              class="w-full py-3 bg-primary text-white font-semibold text-sm rounded-full hover:bg-primary/90 transition-all">
        <span class="material-symbols-outlined text-base mr-1">receipt_long</span>
        Lihat Status / Ruang Tunggu
      </button>
      <a href="cari_kos.php" class="w-full py-2.5 text-sm font-semibold text-on-surface-variant hover:text-on-surface transition-all">
        Kembali ke Katalog Kos
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Sidebar ─────────────────────────────────────── (identik dengan dashboard_penyewa.php) -->
<aside class="fixed left-0 top-0 h-full w-[260px] bg-surface-container-lowest flex flex-col border-r border-outline-variant shadow-[0px_4px_12px_rgba(0,0,0,0.05)] z-50">
  <a class="p-6 flex flex-col gap-1" href="cari_kos.php">
    <span class="font-headline-md text-headline-md font-bold text-primary">KelolaKos</span>
    <span class="text-label-sm text-on-surface-variant">Property Management</span>
  </a>
  <nav class="flex-1 mt-4">
    <a class="flex items-center gap-3 text-on-surface-variant px-4 py-3 hover:bg-surface-container hover:text-on-surface transition-all duration-300" href="dashboard_penyewa.php">
      <span class="material-symbols-outlined">dashboard</span>
      <span class="font-label-md text-label-md">Dashboard</span>
    </a>
    <a class="flex items-center gap-3 text-on-surface-variant px-4 py-3 hover:bg-surface-container hover:text-on-surface transition-all duration-300" href="cari_kos.php">
      <span class="material-symbols-outlined">domain</span>
      <span class="font-label-md text-label-md">Cari Kos</span>
    </a>
    <a class="flex items-center gap-3 text-primary font-bold border-l-4 border-primary bg-surface-container-low px-4 py-3 transition-transform scale-[0.98]" href="riwayat_penyewa.php">
      <span class="material-symbols-outlined">payments</span>
      <span class="font-label-md text-label-md">Transaksi</span>
    </a>
    <a class="flex items-center gap-3 text-on-surface-variant px-4 py-3 hover:bg-surface-container hover:text-on-surface transition-all duration-300" href="kirim_pengaduan.php">
      <span class="material-symbols-outlined">assessment</span>
      <span class="font-label-md text-label-md">Pengaduan</span>
    </a>
    <a class="flex items-center gap-3 text-on-surface-variant px-4 py-3 hover:bg-surface-container hover:text-on-surface transition-all duration-300" href="fasilitas_penyewa.php">
      <span class="material-symbols-outlined">room_service</span>
      <span class="font-label-md text-label-md">Fasilitas</span>
    </a>
  </nav>
  <!-- User profile at bottom of sidebar -->
  <div class="p-4 border-t border-outline-variant">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-[#6c5ce7] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
        <?= htmlspecialchars($initials) ?>
      </div>
      <div class="min-w-0">
        <p class="text-sm font-semibold text-on-surface truncate"><?= htmlspecialchars($full_name) ?></p>
        <p class="text-xs text-on-surface-variant">Penyewa</p>
      </div>
    </div>
    <a href="logout.php" class="mt-3 flex items-center gap-2 text-sm text-rose-600 hover:text-rose-700 px-2 py-1.5 rounded-lg hover:bg-rose-50 transition">
      <span class="material-symbols-outlined text-[16px]">logout</span> Keluar
    </a>
  </div>
</aside>

<!-- ── TopAppBar ────────────────────────────────────────────────────────────── -->
<header class="sticky top-0 h-[72px] bg-white/95 backdrop-blur-md flex justify-between items-center px-container-padding w-full max-w-[1440px] ml-[260px] z-40 border-b border-slate-200 shadow-sm">
  <div class="flex items-center gap-4">
    <h1 class="font-headline-sm text-headline-sm font-bold text-slate-800">Transaksi &amp; Tagihan</h1>
  </div>
  <div class="flex items-center gap-2">
    <!-- Notif dropdown -->
    <div class="relative">
      <button id="notif-btn" class="relative p-2.5 hover:bg-slate-100 rounded-full transition-colors text-slate-600 hover:text-slate-900" type="button" aria-expanded="false" aria-haspopup="true" title="Notifikasi">
        <span class="material-symbols-outlined text-[22px]" style="font-variation-settings:'FILL' 0,'wght' 500">notifications</span>
        <?php if (!empty($notificationsList) && $notificationsList[0]['title'] !== 'Belum ada notifikasi'): ?>
        <span class="absolute top-1.5 right-1.5 w-2.5 h-2.5 bg-rose-500 rounded-full ring-2 ring-white"></span>
        <?php endif; ?>
      </button>
      <!-- Dropdown panel dengan z-index tinggi agar tidak tertutup konten -->
      <div id="notif-panel" class="hidden absolute right-0 top-full mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-black/10 z-[9999]">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 mb-3">Notifikasi</p>
        <div class="space-y-2 max-h-60 overflow-y-auto">
          <?php foreach ($notificationsList as $nItem): ?>
          <div class="rounded-xl p-3 border <?= htmlspecialchars($nItem['bg']) ?>">
            <p class="text-sm font-semibold"><?= htmlspecialchars($nItem['title']) ?></p>
            <p class="text-xs mt-0.5 opacity-90"><?= htmlspecialchars($nItem['desc']) ?></p>
            <?php if ($nItem['time'] !== ''): ?>
            <p class="text-[10px] mt-1 opacity-75"><?= htmlspecialchars($nItem['time']) ?></p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Profile widget -->
    <div class="relative ml-2">
      <button id="profile-btn"
              class="flex items-center gap-3 pl-4 pr-1 py-1 border-l-2 border-slate-200 hover:bg-slate-50 rounded-full transition-all duration-200"
              type="button" aria-expanded="false" aria-haspopup="true">
        <div class="text-right">
          <p class="font-semibold text-sm text-slate-800 leading-tight"><?= htmlspecialchars($full_name) ?></p>
          <p class="text-xs text-slate-500 leading-tight">
            <?php
              $statusLabel = $hasApproved
                ? '<span class="text-emerald-600 font-semibold">● Kamar ' . htmlspecialchars($activeRoom['nomor_kamar'] ?? 'Aktif') . '</span>'
                : ($hasPending ? '<span class="text-amber-500 font-semibold">⏳ Menunggu Verifikasi</span>' : '<span class="text-slate-400">Belum Ada Kamar</span>');
              echo $statusLabel;
            ?>
          </p>
        </div>
        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary to-[#6c5ce7] flex items-center justify-center text-white font-bold text-sm shadow-md flex-shrink-0">
          <?= htmlspecialchars($initials) ?>
        </div>
        <span class="material-symbols-outlined text-slate-400 text-[18px]">expand_more</span>
      </button>
      <div id="profile-panel" class="hidden absolute right-0 top-full mt-2 w-56 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl shadow-black/10 z-[9999]">
        <div class="px-3 py-2 mb-2 border-b border-slate-100">
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Akun Anda</p>
          <p class="text-sm font-semibold text-slate-800 mt-0.5"><?= htmlspecialchars($full_name) ?></p>
        </div>
        <a href="riwayat_penyewa.php" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition">
          <span class="material-symbols-outlined text-[16px] text-primary">receipt_long</span> Riwayat Transaksi
        </a>
        <a href="fasilitas_penyewa.php" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition">
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

<!-- ── Main Content ──────────────────────────────────────────────────────────── -->
<main class="ml-[260px] p-container-padding max-w-[1440px]">
  <div class="space-y-6">

    <?php if (isset($_GET['msg']) && trim($_GET['msg']) !== ''): ?>
    <div class="rounded-2xl px-5 py-4 flex items-center gap-3 text-sm font-medium border <?= ($_GET['type'] ?? 'success') === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200' ?>">
      <span class="material-symbols-outlined text-lg"><?= ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'check_circle' ?></span>
      <span><?= htmlspecialchars(trim($_GET['msg']), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <!-- Banner Ruang Tunggu jika ada transaksi pending -->
    <?php if ($hasPending): ?>
    <div class="flex items-center gap-4 bg-amber-50 border border-amber-300 rounded-2xl px-6 py-4">
      <span class="material-symbols-outlined text-amber-500 text-3xl flex-shrink-0" style="font-variation-settings:'FILL' 1">schedule</span>
      <div>
        <p class="font-bold text-amber-800">⏳ Pengajuan Sewa Sedang MENUNGGU VERIFIKASI Pemilik Kos</p>
        <p class="text-sm text-amber-700 mt-0.5">Pemilik kos akan meninjau dalam 1×24 jam. Lihat detail di bawah.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Banner Kamar Aktif (hanya jika transaksi Disetujui/Lunas/Menunggu Checkout) -->
    <?php if (($hasApproved || $hasCheckout) && $activeRoom): ?>
    <div class="dashboard-card bg-gradient-to-r from-primary to-[#6c5ce7] rounded-2xl p-6 text-white shadow-lg shadow-primary/20 border-0">
      <div class="flex flex-col gap-5">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div>
            <p class="text-sm font-medium text-white/70 uppercase tracking-wider">Kamar Aktif Anda</p>
            <h2 class="text-2xl font-bold mt-1">
              Kamar <?= htmlspecialchars($activeRoom['nomor_kamar'] ?? '-') ?>
              <span class="text-base font-medium text-white/80 ml-2"><?= htmlspecialchars($activeRoom['tipe_kamar'] ?? '') ?></span>
            </h2>
            <p class="text-sm text-white/70 mt-1">Dikelola oleh <?= htmlspecialchars($activeRoom['pemilik_nama'] ?? 'Pemilik Kos') ?></p>
          </div>
          <div class="text-right">
            <p class="text-sm text-white/70">Harga Sewa / Bulan</p>
            <p class="text-2xl font-extrabold"><?= formatRupiah((float)($activeRoom['harga_sewa'] ?? $activeRoom['nominal'] ?? 0)) ?></p>
            <span class="inline-flex items-center gap-1 mt-2 bg-white/20 px-3 py-1 rounded-full text-xs font-bold">
              <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">check_circle</span>
              Kontrak Aktif
            </span>
          </div>
        </div>

        <!-- Tombol / Badge Checkout -->
        <?php if ($hasCheckout): ?>
        <div class="flex items-center gap-3 bg-amber-400/25 border border-amber-300/50 rounded-xl px-4 py-3">
          <span class="material-symbols-outlined text-amber-200 text-2xl" style="font-variation-settings:'FILL' 1">hourglass_top</span>
          <p class="text-sm font-bold text-amber-100">⏳ Pengajuan Selesai Sewa Sedang Diproses Pemilik Kos</p>
        </div>
        <?php else: ?>
        <form method="POST" action="proses_checkout.php"
              onsubmit="return confirm('Anda yakin ingin mengajukan selesai sewa untuk kamar ini? Pengajuan akan dikirim ke pemilik kos untuk konfirmasi.')">
          <input type="hidden" name="action" value="ajukan">
          <input type="hidden" name="transaksi_id" value="<?= (int)$activeRoom['id'] ?>">
          <button type="submit"
                  class="inline-flex items-center gap-2 bg-white/10 border border-white/40 hover:bg-rose-500/80 hover:border-rose-400 text-white font-semibold text-sm px-5 py-2.5 rounded-full transition-all active:scale-95">
            <span class="material-symbols-outlined text-base">logout</span>
            Ajukan Selesai Sewa
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Daftar Transaksi ──────────────────────────────────────────────── -->
    <section class="dashboard-card overflow-hidden">
      <div class="flex items-center justify-between px-6 py-5 border-b border-outline-variant/20">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-on-surface">Daftar Transaksi</h2>
          <p class="text-body-md text-on-surface-variant mt-0.5">
            <?= empty($transactions) ? 'Belum ada transaksi' : count($transactions) . ' transaksi ditemukan' ?>
          </p>
        </div>
        <?php if (!empty($transactions)): ?>
        <span class="inline-flex items-center gap-1 text-xs font-bold bg-primary/10 text-primary px-3 py-1 rounded-full">
          <span class="material-symbols-outlined text-[14px]">receipt_long</span>
          <?= count($transactions) ?> Transaksi
        </span>
        <?php endif; ?>
      </div>

      <?php if (empty($transactions)): ?>
      <!-- Empty state -->
      <div class="flex flex-col items-center justify-center py-20 px-6 text-center">
        <div class="w-20 h-20 rounded-full bg-surface-container-low flex items-center justify-center mb-5">
          <span class="material-symbols-outlined text-4xl text-on-surface-variant/40">receipt_long</span>
        </div>
        <h3 class="font-headline-sm text-headline-sm text-on-surface">Belum Ada Tagihan atau Transaksi Aktif</h3>
        <p class="mt-2 text-body-md text-on-surface-variant max-w-sm">
          Anda belum pernah mengajukan sewa atau belum memiliki kamar aktif. Temukan hunian terbaik dan mulai ajukan sewa sekarang.
        </p>
        <a href="cari_kos.php"
           class="mt-6 inline-flex items-center gap-2 bg-primary text-white font-semibold text-sm px-6 py-3 rounded-full shadow-md shadow-primary/20 hover:bg-primary/90 transition-all active:scale-95">
          <span class="material-symbols-outlined text-base">search</span>
          Cari Kost Sekarang
        </a>
      </div>

      <?php else: ?>
      <!-- Daftar transaksi dari DB -->
      <div class="divide-y divide-outline-variant/20">
        <?php foreach ($transactions as $txn):
          [$badgeClass, $badgeIcon, $badgeLabel] = statusBadge($txn['status'] ?? '');
          $nominal    = (float)($txn['nominal'] ?? $txn['harga_sewa'] ?? 0);
          $tanggal    = !empty($txn['created_at']) ? date('d M Y', strtotime($txn['created_at'])) : '-';
          $kamarLabel = 'Kamar ' . ($txn['nomor_kamar'] ?? '#' . $txn['kamar_id']);
          $tipeLabel  = $txn['tipe_kamar'] ?? '';
          $statusRaw      = strtolower(trim($txn['status'] ?? ''));
          $isPending      = in_array($statusRaw, ['menunggu', 'menunggu verifikasi', 'pending'], true);
          $isApproved     = in_array($statusRaw, ['disetujui', 'lunas', 'approved', 'paid'], true);
          $isWaitCheckout = $statusRaw === 'menunggu checkout';
          $isDone         = $statusRaw === 'selesai';
        ?>
        <div class="txn-card px-6 py-5">
          <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <!-- Kiri: info transaksi -->
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-xl bg-surface-container-low flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings:'FILL' 1">home</span>
              </div>
              <div>
                <p class="font-semibold text-on-surface">
                  <?= htmlspecialchars($kamarLabel) ?>
                  <?php if ($tipeLabel): ?>
                    <span class="text-xs font-normal text-on-surface-variant ml-1">(<?= htmlspecialchars($tipeLabel) ?>)</span>
                  <?php endif; ?>
                </p>
                <p class="text-xs text-on-surface-variant mt-0.5">
                  <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                  Diajukan <?= $tanggal ?>
                </p>
                <p class="text-body-md font-bold text-primary mt-1"><?= formatRupiah($nominal) ?></p>
                <p class="text-xs text-on-surface-variant mt-0.5">
                  Pemilik: <?= htmlspecialchars($txn['pemilik_nama'] ?? 'Pemilik Kos') ?>
                </p>
              </div>
            </div>

            <!-- Kanan: badge status + aksi checkout -->
            <div class="flex flex-col items-start sm:items-end gap-2">
              <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full border <?= $badgeClass ?>">
                <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1"><?= $badgeIcon ?></span>
                <?= htmlspecialchars($badgeLabel) ?>
              </span>

              <?php if ($isPending): ?>
              <!-- Label RUANG TUNGGU yang jelas -->
              <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1.5 rounded-full">
                <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">hourglass_top</span>
                RUANG TUNGGU: Menunggu Konfirmasi Pemilik Kos
              </span>
              <?php endif; ?>

              <?php if ($isApproved): ?>
              <span class="text-[11px] text-emerald-600 font-semibold">✅ Kamar berhasil diamankan</span>
              <?php endif; ?>

              <?php if ($isWaitCheckout): ?>
              <!-- Badge proses checkout -->
              <span class="inline-flex items-center gap-1 text-[11px] font-bold text-orange-700 bg-orange-50 border border-orange-200 px-3 py-1.5 rounded-full">
                <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">hourglass_top</span>
                ⏳ Pengajuan Selesai Sewa Sedang Diproses Pemilik Kos
              </span>
              <?php endif; ?>

              <?php if ($isDone || in_array($statusRaw, ['ditolak', 'rejected', 'gagal'], true)): ?>
              <div class="flex items-center gap-2 mt-1">
                <span class="text-[11px] text-slate-500 font-semibold">🏁 <?= $isDone ? 'Sewa selesai' : 'Transaksi ditolak' ?></span>
                <form method="POST" action="riwayat_penyewa.php" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus riwayat transaksi kamar ini?');">
                  <input type="hidden" name="action" value="delete_txn">
                  <input type="hidden" name="transaksi_id" value="<?= (int)$txn['id'] ?>">
                  <button type="submit" class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-3 py-1 rounded-full transition-all active:scale-95 cursor-pointer" title="Hapus Riwayat Transaksi">
                    <span class="material-symbols-outlined text-sm">delete</span>
                    Hapus
                  </button>
                </form>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ── Panel Informasi ─────────────────────────────────────────────── -->
    <section class="dashboard-card p-6">
      <h3 class="font-headline-sm text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings:'FILL' 1">info</span>
        Informasi Penting
      </h3>
      <ul class="space-y-2.5 text-body-md text-on-surface-variant">
        <li class="flex items-start gap-2">
          <span class="material-symbols-outlined text-[16px] text-primary mt-0.5" style="font-variation-settings:'FILL' 1">check_circle</span>
          Pengajuan sewa akan diproses oleh pemilik kos dalam 1×24 jam.
        </li>
        <li class="flex items-start gap-2">
          <span class="material-symbols-outlined text-[16px] text-amber-500 mt-0.5" style="font-variation-settings:'FILL' 1">schedule</span>
          Status <strong>Menunggu Verifikasi</strong> berarti pengajuan Anda sedang dalam antrian ruang tunggu.
        </li>
        <li class="flex items-start gap-2">
          <span class="material-symbols-outlined text-[16px] text-emerald-500 mt-0.5" style="font-variation-settings:'FILL' 1">check_circle</span>
          Status <strong>Disetujui</strong> berarti kamar telah dikonfirmasi dan siap Anda gunakan.
        </li>
      </ul>
      <div class="mt-5 flex flex-wrap gap-3">
        <a href="cari_kos.php"
           class="inline-flex items-center gap-2 text-sm font-semibold text-primary border border-primary px-4 py-2.5 rounded-full hover:bg-primary/5 transition-all">
          <span class="material-symbols-outlined text-base">search</span> Cari Kos Lain
        </a>
        <a href="kirim_pengaduan.php"
           class="inline-flex items-center gap-2 text-sm font-semibold text-on-surface-variant border border-outline-variant px-4 py-2.5 rounded-full hover:bg-surface-container transition-all">
          <span class="material-symbols-outlined text-base">support_agent</span> Kirim Pengaduan
        </a>
      </div>
    </section>

  </div>
</main>

<script>
  // Close modal when clicking backdrop
  document.getElementById('waiting-modal')?.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
  });

  // Profile & Notif dropdowns
  document.addEventListener('click', (event) => {
    const notifBtn = document.getElementById('notif-btn');
    const notifPanel = document.getElementById('notif-panel');
    const profileBtn = document.getElementById('profile-btn');
    const profilePanel = document.getElementById('profile-panel');

    if (notifBtn && !notifBtn.contains(event.target) && notifPanel && !notifPanel.contains(event.target)) {
      notifPanel.classList.add('hidden');
      notifBtn.setAttribute('aria-expanded', 'false');
    }
    if (profileBtn && !profileBtn.contains(event.target) && profilePanel && !profilePanel.contains(event.target)) {
      profilePanel.classList.add('hidden');
      profileBtn.setAttribute('aria-expanded', 'false');
    }
  });

  document.getElementById('notif-btn')?.addEventListener('click', (event) => {
    event.stopPropagation();
    const notifPanel = document.getElementById('notif-panel');
    const profilePanel = document.getElementById('profile-panel');
    if (profilePanel) profilePanel.classList.add('hidden');
    if (notifPanel) {
      const isHidden = notifPanel.classList.contains('hidden');
      notifPanel.classList.toggle('hidden', !isHidden);
      event.currentTarget.setAttribute('aria-expanded', String(isHidden));
    }
  });

  document.getElementById('profile-btn')?.addEventListener('click', (event) => {
    event.stopPropagation();
    const notifPanel = document.getElementById('notif-panel');
    const profilePanel = document.getElementById('profile-panel');
    if (notifPanel) notifPanel.classList.add('hidden');
    if (profilePanel) {
      const isHidden = profilePanel.classList.contains('hidden');
      profilePanel.classList.toggle('hidden', !isHidden);
      event.currentTarget.setAttribute('aria-expanded', String(isHidden));
    }
  });

  // Micro-interactions
  document.querySelectorAll('button').forEach(btn => {
    btn.addEventListener('mousedown', () => btn.style.transform = 'scale(0.95)');
    btn.addEventListener('mouseup', () => btn.style.transform = '');
    btn.addEventListener('mouseleave', () => btn.style.transform = '');
  });
</script>
</body>
</html>
