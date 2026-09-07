<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'penyewa') {
    header('Location: login_daftar.php');
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'Penyewa';
$first_name = explode(' ', trim($full_name))[0] ?? $full_name;
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? 0);
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

$hasActiveRoom  = false;
$isPaid         = false;
$hasPending     = false;  // Transaksi menunggu verifikasi
$hasCheckout    = false;  // Transaksi menunggu checkout
$activeTxnId    = 0;      // ID transaksi aktif
$roomNumber     = '';
$roomType       = '';
$billAmount     = 0;
$dueDateText    = '';
$daysLeftText   = '';
$recentPayments = [];
$latestComplaint = null;

if ($userId > 0) {
    // Get tenant's current kamar_id
    try {
        $kamarStmt = $pdo->prepare("SELECT kamar_id FROM penyewa WHERE id = :id");
        $kamarStmt->execute([':id' => $userId]);
        $currKamarId = $kamarStmt->fetchColumn();
    } catch (Throwable $e) {
        $currKamarId = null;
    }

    // ── Cek kamar aktif dari TRANSAKSI yang Disetujui/Lunas/Menunggu Checkout ────
    try {
        $txnCheck = $pdo->prepare(
            "SELECT t.id, t.kamar_id, t.created_at, t.total_harga AS nominal, t.status,
                    k.nomor_kamar, k.tipe_kamar, k.harga_sewa, k.status AS kamar_status
             FROM transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             WHERE t.penyewa_id = :uid
               AND LOWER(t.status) IN ('disetujui','lunas','approved','paid','menunggu checkout')
             ORDER BY t.created_at DESC
             LIMIT 1"
         );
        $txnCheck->execute([':uid' => $userId]);
        $approvedTxn = $txnCheck->fetch(PDO::FETCH_ASSOC);

        if ($approvedTxn) {
            $hasActiveRoom = true;
            $roomNumber    = trim((string)($approvedTxn['nomor_kamar'] ?? ''));
            $roomType      = trim((string)($approvedTxn['tipe_kamar'] ?? 'Kamar'));
            $billAmount    = (float)($approvedTxn['harga_sewa'] ?? 0);
            $activeTxnId   = (int)$approvedTxn['id'];
            $hasCheckout   = strtolower(trim($approvedTxn['status'] ?? '')) === 'menunggu checkout';
            $isPaid        = in_array(strtolower(trim($approvedTxn['status'] ?? '')), ['lunas', 'sukses', 'approved', 'paid', 'disetujui', 'menunggu checkout'], true);

            $dueDate     = new DateTime($approvedTxn['created_at']);
            $dueDate->modify('+1 month');
            $dueDateText = $dueDate->format('d M Y');
            $daysLeft    = max(0, (int)floor(($dueDate->getTimestamp() - time()) / 86400));
            $daysLeftText = $daysLeft > 0 ? 'Sisa ' . $daysLeft . ' hari lagi' : 'Jatuh tempo hari ini';
        }
    } catch (PDOException $e) {
        $hasActiveRoom = false;
    }

    // ── Cek transaksi yang masih Menunggu Verifikasi ──────────────────────
    try {
        $pendingChk = $pdo->prepare(
            "SELECT COUNT(*) FROM transaksi WHERE penyewa_id = :uid AND LOWER(status) IN ('menunggu','menunggu verifikasi','pending')"
        );
        $pendingChk->execute([':uid' => $userId]);
        $hasPending = (int)$pendingChk->fetchColumn() > 0;
    } catch (PDOException $e) {
        $hasPending = false;
    }

    $paymentTables = ['transaksi', 'riwayat_transaksi', 'payments', 'payment_history'];
    foreach ($paymentTables as $paymentTable) {
        try {
            $tableCheck = $pdo->prepare('SHOW TABLES LIKE :table');
            $tableCheck->execute([':table' => $paymentTable]);
            if (!$tableCheck->fetch()) {
                continue;
            }

            $columnsStmt = $pdo->query('DESCRIBE `' . $paymentTable . '`');
            $columns = array_map(static fn($col) => $col['Field'], $columnsStmt->fetchAll(PDO::FETCH_ASSOC));

            $userColumn = null;
            foreach (['user_id', 'tenant_id', 'penyewa_id', 'userId'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $userColumn = $candidate;
                    break;
                }
            }

            $amountColumn = null;
            foreach (['jumlah', 'amount', 'total', 'harga', 'nominal'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $amountColumn = $candidate;
                    break;
                }
            }

            $dateColumn = null;
            foreach (['created_at', 'tanggal', 'paid_at', 'tanggal_bayar', 'dibayar_pada'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $dateColumn = $candidate;
                    break;
                }
            }

            $statusColumn = null;
            foreach (['status', 'payment_status'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $statusColumn = $candidate;
                    break;
                }
            }

            if ($userColumn && $amountColumn && $dateColumn) {
                $query = 'SELECT `' . $amountColumn . '` AS amount, `' . $dateColumn . '` AS created_at';
                if ($statusColumn) {
                    $query .= ', `' . $statusColumn . '` AS status';
                }
                $query .= ' FROM `' . $paymentTable . '` WHERE `' . $userColumn . '` = :user_id ORDER BY `' . $dateColumn . '` DESC LIMIT 3';
                $paymentStmt = $pdo->prepare($query);
                $paymentStmt->execute([':user_id' => $userId]);
                $recentPayments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($recentPayments)) {
                    break;
                }
            }
        } catch (PDOException $e) {
            continue;
        }
    }

    try {
        $complaintStmt = $pdo->prepare(
            'SELECT p.id, p.kamar_id, p.nama_penyewa, p.deskripsi, p.status, p.created_at, k.nomor_kamar
             FROM pengaduan p
             LEFT JOIN kamar k ON k.id = p.kamar_id
             LEFT JOIN penyewa s ON s.kamar_id = p.kamar_id
             WHERE (s.id = :user_id OR p.nama_penyewa = :full_name)
             ORDER BY p.created_at DESC
             LIMIT 1'
        );
        $complaintStmt->execute([
            ':user_id' => $userId,
            ':full_name' => $full_name,
        ]);
        $latestComplaint = $complaintStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $latestComplaint = null;
    }

    // ── Ambil notifikasi dinamis ──────────────────────────────────────────
    $notificationsList = [];
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

    if (empty($notificationsList)) {
        $notificationsList[] = [
            'title' => 'Belum ada notifikasi',
            'desc' => 'Aktivitas pengajuan & laporan sewa Anda akan muncul di sini.',
            'time' => '',
            'bg' => 'bg-slate-50 text-slate-600 border-slate-100'
        ];
    }
}
?>
<!DOCTYPE html>

<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>KelolaKos - Dashboard Penyewa</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<style>
                .material-symbols-outlined {
                        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
                        vertical-align: middle;
                }
                body {
                        font-family: 'Inter', sans-serif;
                        background-color: #f1fbff;
                }
                /* Custom scrollbar for clean UI */
                ::-webkit-scrollbar {
                        width: 6px;
                }
                ::-webkit-scrollbar-track {
                        background: #f1fbff;
                }
                ::-webkit-scrollbar-thumb {
                        background: #d9e4e9;
                        border-radius: 10px;
                }
        </style>
<script id="tailwind-config">
            tailwind.config = {
                darkMode: "class",
                theme: {
                    extend: {
                        "colors": {
                                        "on-primary-fixed-variant": "#4029ba",
                                        "background": "#f1fbff",
                                        "on-background": "#131d21",
                                        "tertiary-fixed-dim": "#ffb4a3",
                                        "surface-container-low": "#eaf5fa",
                                        "error-container": "#ffdad6",
                                        "surface-tint": "#5847d2",
                                        "primary-container": "#6c5ce7",
                                        "tertiary-container": "#b95239",
                                        "on-error": "#ffffff",
                                        "secondary": "#006b55",
                                        "on-primary-container": "#faf6ff",
                                        "on-tertiary-fixed": "#3d0700",
                                        "surface-container": "#e4f0f4",
                                        "surface-container-highest": "#d9e4e9",
                                        "on-secondary-fixed-variant": "#005140",
                                        "secondary-fixed": "#6dfad2",
                                        "inverse-surface": "#283236",
                                        "surface-variant": "#d9e4e9",
                                        "on-tertiary": "#ffffff",
                                        "on-surface": "#131d21",
                                        "on-secondary-container": "#00725b",
                                        "secondary-fixed-dim": "#4bddb7",
                                        "outline-variant": "#c8c4d7",
                                        "surface-container-high": "#dfeaef",
                                        "outline": "#787586",
                                        "primary-fixed-dim": "#c6bfff",
                                        "on-primary-fixed": "#160066",
                                        "on-tertiary-fixed-variant": "#812914",
                                        "on-primary": "#ffffff",
                                        "on-secondary": "#ffffff",
                                        "tertiary": "#993a24",
                                        "tertiary-fixed": "#ffdad2",
                                        "secondary-container": "#6dfad2",
                                        "on-surface-variant": "#474554",
                                        "on-error-container": "#93000a",
                                        "surface-bright": "#f1fbff",
                                        "error": "#ba1a1a",
                                        "surface": "#f1fbff",
                                        "primary-fixed": "#e4dfff",
                                        "inverse-primary": "#c6bfff",
                                        "surface-container-lowest": "#ffffff",
                                        "on-tertiary-container": "#fff6f4",
                                        "inverse-on-surface": "#e7f3f7",
                                        "on-secondary-fixed": "#002018",
                                        "primary": "#5341cd",
                                        "surface-dim": "#d1dce0"
                        },
                        "borderRadius": {
                                        "DEFAULT": "0.25rem",
                                        "lg": "0.5rem",
                                        "xl": "0.75rem",
                                        "full": "9999px"
                        },
                        "spacing": {
                                        "stack-sm": "8px",
                                        "stack-lg": "24px",
                                        "topbar-height": "72px",
                                        "sidebar-width": "260px",
                                        "stack-md": "16px",
                                        "container-padding": "32px",
                                        "gutter": "24px"
                        },
                        "fontFamily": {
                                        "display-lg": ["Inter"],
                                        "headline-md-mobile": ["Inter"],
                                        "body-lg": ["Inter"],
                                        "label-md": ["Inter"],
                                        "headline-md": ["Inter"],
                                        "headline-sm": ["Inter"],
                                        "label-sm": ["Inter"],
                                        "body-md": ["Inter"]
                        },
                        "fontSize": {
                                        "display-lg": ["32px", {"lineHeight": "40px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                                        "headline-md-mobile": ["20px", {"lineHeight": "28px", "fontWeight": "600"}],
                                        "body-lg": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                                        "label-md": ["13px", {"lineHeight": "18px", "letterSpacing": "0.05em", "fontWeight": "600"}],
                                        "headline-md": ["24px", {"lineHeight": "32px", "letterSpacing": "-0.01em", "fontWeight": "600"}],
                                        "headline-sm": ["20px", {"lineHeight": "28px", "fontWeight": "600"}],
                                        "label-sm": ["12px", {"lineHeight": "16px", "fontWeight": "500"}],
                                        "body-md": ["14px", {"lineHeight": "20px", "fontWeight": "400"}]
                        }
                    },
                },
            }
        </script>
<style>
        body {
            min-height: max(884px, 100dvh);
        }
        .dashboard-card {
            background-color: #ffffff;
            border: 1px solid rgba(120, 117, 134, 0.16);
            border-radius: 1rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }
    </style>
    </head>
<body class="bg-surface text-on-surface">
<?php if (!empty($success_message)): ?>
<script>
  window.addEventListener('DOMContentLoaded', function () {
    alert('Pengajuan sewa Anda berhasil dikirim! Silakan tunggu konfirmasi dari pemilik kos.');
  });
</script>
<?php endif; ?>
<!-- Sidebar / NavigationDrawer -->
<aside class="fixed left-0 top-0 h-full w-[260px] bg-surface-container-lowest flex flex-col border-r border-outline-variant shadow-[0px_4px_12px_rgba(0,0,0,0.05)] z-50">
<a class="p-6 flex flex-col gap-1" href="cari_kos.php">
<span class="font-headline-md text-headline-md font-bold text-primary">KelolaKos</span>
<span class="text-label-sm text-on-surface-variant">Property Management</span>
</a>
<nav class="flex-1 mt-4">
<!-- Navigation Items map directly from JSON labels -->
<a class="flex items-center gap-3 text-primary font-bold border-l-4 border-primary bg-surface-container-low px-4 py-3 transition-transform scale-[0.98]" href="dashboard_penyewa.php">
<span class="material-symbols-outlined">dashboard</span>
<span class="font-label-md text-label-md">Dashboard</span>
</a>
<a class="flex items-center gap-3 text-on-surface-variant px-4 py-3 hover:bg-surface-container hover:text-on-surface transition-all duration-300" href="cari_kos.php">
<span class="material-symbols-outlined">domain</span>
<span class="font-label-md text-label-md">Cari Kos</span>
</a>
<a class="flex items-center gap-3 text-on-surface-variant px-4 py-3 hover:bg-surface-container hover:text-on-surface transition-all duration-300" href="riwayat_penyewa.php">
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
<div class="p-4 border-t border-outline-variant mt-auto">
  <div class="flex items-center gap-3">
    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-[#6c5ce7] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
      <?= htmlspecialchars(strtoupper(substr($full_name, 0, 1))); ?>
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
<!-- TopAppBar -->
<header class="sticky top-0 h-[72px] bg-white/95 backdrop-blur-md flex justify-between items-center px-container-padding w-full max-w-[1440px] ml-[260px] z-40 border-b border-slate-200 shadow-sm">
<div class="flex items-center gap-4">
    <h1 class="font-headline-sm text-headline-sm font-bold text-slate-800">Dashboard Penyewa</h1>
</div>
<div class="flex items-center gap-2">
  <!-- Search icon -->
  <button class="p-2.5 hover:bg-slate-100 rounded-full transition-colors text-slate-600 hover:text-slate-900" title="Cari" type="button">
    <span class="material-symbols-outlined text-[22px]" style="font-variation-settings:'FILL' 0,'wght' 500">search</span>
  </button>

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
        <p class="font-semibold text-sm text-slate-800 leading-tight"><?php echo htmlspecialchars($full_name); ?></p>
        <p class="text-xs text-slate-500 leading-tight">
          <?php if ($hasActiveRoom && $roomNumber): ?>
            <span class="text-emerald-600 font-semibold">● Kamar <?php echo htmlspecialchars($roomNumber); ?></span>
          <?php elseif ($hasCheckout): ?>
            <span class="text-orange-500 font-semibold">⏳ Proses Checkout</span>
          <?php else: ?>
            <span class="text-slate-400">Belum Ada Kamar</span>
          <?php endif; ?>
        </p>
      </div>
      <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary to-[#6c5ce7] flex items-center justify-center text-white font-bold text-sm shadow-md flex-shrink-0">
        <?php echo htmlspecialchars(strtoupper(substr($full_name, 0, 1))); ?>
      </div>
      <span class="material-symbols-outlined text-slate-400 text-[18px]">expand_more</span>
    </button>
    <div id="profile-panel" class="hidden absolute right-0 top-full mt-2 w-56 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl shadow-black/10 z-[9999]">
      <div class="px-3 py-2 mb-2 border-b border-slate-100">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Akun Anda</p>
        <p class="text-sm font-semibold text-slate-800 mt-0.5"><?php echo htmlspecialchars($full_name); ?></p>
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
<!-- Main Content -->
<main class="ml-[260px] p-container-padding max-w-[1440px]">
    <div class="space-y-8">
        <!-- Banner Ruang Tunggu -->
        <?php if ($hasPending): ?>
        <a href="riwayat_penyewa.php" class="flex items-center gap-4 bg-amber-50 border border-amber-300 rounded-2xl px-6 py-4 hover:bg-amber-100 transition-all group no-underline">
            <span class="material-symbols-outlined text-amber-500 text-3xl flex-shrink-0" style="font-variation-settings:'FILL' 1">schedule</span>
            <div class="flex-1">
                <p class="font-bold text-sm text-amber-800">⏳ Anda memiliki pengajuan sewa yang sedang MENUNGGU VERIFIKASI Pemilik Kos</p>
                <p class="text-xs text-amber-700 mt-0.5">Klik di sini untuk melihat status pesanan di Ruang Tunggu.</p>
            </div>
            <span class="material-symbols-outlined text-amber-500 group-hover:translate-x-1 transition-transform">arrow_forward</span>
        </a>
        <?php endif; ?>

        <!-- Banner Pengajuan Disetujui -->
        <?php if ($hasActiveRoom && !$hasCheckout): ?>
        <div class="flex items-center gap-4 bg-emerald-50 border border-emerald-300 rounded-2xl px-6 py-4 transition-all">
            <span class="material-symbols-outlined text-emerald-600 text-3xl flex-shrink-0" style="font-variation-settings:'FILL' 1">check_circle</span>
            <div class="flex-1">
                <p class="font-bold text-sm text-emerald-800">🎉 Pengajuan Sewa Disetujui!</p>
                <p class="text-xs text-emerald-700 mt-0.5">Kamar <?= htmlspecialchars($roomNumber) ?> telah aktif dan siap digunakan. Silakan nikmati hunian Anda.</p>
            </div>
        </div>
        <?php endif; ?>
        <section class="dashboard-card p-6 md:p-8">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <p class="text-label-md font-label-md text-on-surface-variant">Ringkasan Hunian</p>
                    <h2 class="mt-1 font-headline-md text-headline-md text-on-surface">
                        <?php if ($hasActiveRoom): ?>
                            Halo, <?php echo htmlspecialchars($first_name); ?>. Hunian Anda masih aktif dan siap digunakan.
                        <?php else: ?>
                            Halo, <?php echo htmlspecialchars($first_name); ?>. Saat ini Anda belum memiliki kamar aktif.
                        <?php endif; ?>
                    </h2>
                </div>
                <?php if ($hasActiveRoom): ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-secondary/10 px-3 py-1 text-label-sm font-label-sm text-secondary">
                        <span class="material-symbols-outlined text-[16px]">verified</span>
                        Kontrak Aktif
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-warning/10 px-3 py-1 text-label-sm font-label-sm text-warning">
                        <span class="material-symbols-outlined text-[16px]">info</span>
                        Belum Memiliki Kamar
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($hasActiveRoom): ?>
                <div class="mt-6 grid gap-4 lg:grid-cols-3">
                    <div class="rounded-xl border border-outline-variant/30 bg-surface-container-low p-4">
                        <p class="text-label-md font-label-md text-on-surface-variant">Status Hunian</p>
                        <h3 class="mt-2 font-headline-sm text-headline-sm text-on-surface"><?php echo htmlspecialchars($roomNumber ?: 'Kamar'); ?></h3>
                        <p class="mt-2 text-body-md text-on-surface-variant"><?php echo htmlspecialchars($roomType ?: 'Kamar aktif'); ?></p>
                    </div>
                    <div class="rounded-xl border border-outline-variant/30 bg-surface-container-low p-4">
                        <p class="text-label-md font-label-md text-on-surface-variant">Tagihan Bulan Ini</p>
                        <h3 class="mt-2 font-headline-md text-headline-md text-on-surface">Rp <?php echo number_format($billAmount, 0, ',', '.'); ?></h3>
                        <?php if ($isPaid): ?>
                            <span class="mt-3 inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 text-label-sm font-label-sm text-emerald-700 font-bold">
                                <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                                Lunas
                            </span>
                        <?php else: ?>
                            <span class="mt-3 inline-flex rounded-full bg-error/10 px-3 py-1 text-label-sm font-label-sm text-error">Belum Bayar</span>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-xl border border-outline-variant/30 bg-surface-container-low p-4">
                        <p class="text-label-md font-label-md text-on-surface-variant">Tanggal Jatuh Tempo</p>
                        <h3 class="mt-2 font-headline-md text-headline-md text-on-surface"><?php echo htmlspecialchars($dueDateText); ?></h3>
                        <p class="mt-2 text-body-md text-on-surface-variant"><?php echo htmlspecialchars($daysLeftText); ?></p>
                    </div>
                </div>

                <!-- Tombol / Badge Checkout -->
                <?php if ($hasCheckout): ?>
                <div class="mt-4 flex items-center gap-3 bg-amber-50 border border-amber-200 rounded-xl px-5 py-3">
                    <span class="material-symbols-outlined text-amber-500 text-2xl" style="font-variation-settings:'FILL' 1">hourglass_top</span>
                    <p class="text-sm font-bold text-amber-800">⏳ Pengajuan Selesai Sewa Sedang Diproses Pemilik Kos</p>
                </div>
                <?php elseif ($hasActiveRoom): ?>
                <div class="mt-4">
                    <form method="POST" action="proses_checkout.php"
                          onsubmit="return confirm('Anda yakin ingin mengajukan selesai sewa? Pengajuan akan dikirim ke pemilik kos untuk konfirmasi.')">
                        <input type="hidden" name="action" value="ajukan">
                        <input type="hidden" name="transaksi_id" value="<?= $activeTxnId ?>">
                        <button type="submit"
                                class="inline-flex items-center gap-2 border border-rose-400 text-rose-600 hover:bg-rose-50 font-semibold text-sm px-5 py-2.5 rounded-full transition-all active:scale-95">
                            <span class="material-symbols-outlined text-base">logout</span>
                            Ajukan Selesai Sewa
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="mt-6 rounded-2xl border border-dashed border-outline-variant bg-surface-container-low p-8 text-center">
                    <p class="text-lg font-semibold text-on-surface">Belum ada kamar aktif yang terhubung ke akun Anda.</p>
                    <p class="mt-2 text-body-md text-on-surface-variant">Silakan pilih hunian yang tersedia untuk memulai masa sewa.</p>
                    <a href="cari_kos.php" class="mt-5 inline-flex items-center justify-center rounded-full bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-primary/90">
                        Cari Kos Sekarang
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <section class="grid gap-4 md:grid-cols-2">
            <a class="dashboard-card flex items-center justify-between p-5 transition-all duration-300 hover:-translate-y-0.5" href="riwayat_penyewa.php">
                <div>
                    <p class="text-label-md font-label-md text-primary">Bayar / Upload Bukti</p>
                    <p class="mt-1 text-body-md text-on-surface-variant">Selesaikan pembayaran dan unggah bukti transfer.</p>
                </div>
                <span class="material-symbols-outlined text-primary text-2xl">payments</span>
            </a>
            <a class="dashboard-card flex items-center justify-between p-5 transition-all duration-300 hover:-translate-y-0.5" href="kirim_pengaduan.php">
                <div>
                    <p class="text-label-md font-label-md text-primary">Buat Pengaduan Baru</p>
                    <p class="mt-1 text-body-md text-on-surface-variant">Laporkan masalah fasilitas atau kebutuhan lain.</p>
                </div>
                <span class="material-symbols-outlined text-primary text-2xl">support_agent</span>
            </a>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="dashboard-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-headline-sm text-headline-sm text-on-surface">Riwayat Pembayaran Terakhir</h3>
                        <p class="mt-1 text-body-md text-on-surface-variant">Preview 3 transaksi terbaru</p>
                    </div>
                    <a class="text-sm font-semibold text-primary hover:underline" href="riwayat_penyewa.php">Lihat Semua</a>
                </div>
                <?php if (empty($recentPayments)): ?>
                    <div class="mt-6 rounded-xl border border-dashed border-outline-variant bg-surface-container-low p-5 text-center">
                        <p class="font-semibold text-on-surface">Belum ada riwayat transaksi</p>
                    </div>
                <?php else: ?>
                    <div class="mt-6 space-y-3">
                        <?php foreach (array_slice($recentPayments, 0, 3) as $payment): ?>
                            <div class="flex items-center justify-between rounded-xl border border-outline-variant/30 bg-surface-container-low p-4">
                                <div>
                                    <p class="font-semibold text-on-surface">
                                        <?php echo htmlspecialchars($payment['status'] ?? 'Pembayaran'); ?>
                                    </p>
                                    <p class="text-sm text-on-surface-variant">
                                        <?php echo htmlspecialchars(date('d M Y', strtotime($payment['created_at'] ?? date('Y-m-d')))); ?>
                                    </p>
                                </div>
                                <span class="rounded-full bg-secondary/10 px-3 py-1 text-sm font-medium text-secondary">
                                    Rp <?php echo number_format((float)($payment['amount'] ?? 0), 0, ',', '.'); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dashboard-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-headline-sm text-headline-sm text-on-surface">Status Pengaduan Terakhir</h3>
                        <p class="mt-1 text-body-md text-on-surface-variant">Update terkini dari pengaduan Anda</p>
                    </div>
                    <a class="text-sm font-semibold text-primary hover:underline" href="kirim_pengaduan.php">Lihat Semua</a>
                </div>

                <?php if (empty($latestComplaint)): ?>
                    <div class="mt-6 rounded-xl border border-dashed border-outline-variant bg-surface-container-low p-5 text-center">
                        <p class="font-semibold text-on-surface">Belum ada pengaduan yang diajukan</p>
                    </div>
                <?php else: ?>
                    <div class="mt-6 rounded-xl border border-outline-variant/30 bg-surface-container-low p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-on-surface"><?php echo htmlspecialchars($latestComplaint['deskripsi'] ?? 'Pengaduan'); ?></p>
                                <p class="mt-1 text-sm text-on-surface-variant">
                                    Dilaporkan <?php echo htmlspecialchars(date('d M Y', strtotime($latestComplaint['created_at'] ?? date('Y-m-d')))); ?>
                                </p>
                            </div>
                            <span class="rounded-full bg-secondary/10 px-3 py-1 text-sm font-medium text-secondary">
                                <?php echo htmlspecialchars($latestComplaint['status'] ?? 'Baru'); ?>
                            </span>
                        </div>
                        <p class="mt-4 text-body-md text-on-surface-variant">
                            <?php echo htmlspecialchars($latestComplaint['deskripsi'] ?? 'Belum ada detail pengaduan yang tersedia.'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>
<script>
                // Micro-interactions and interactive effects
                document.querySelectorAll('button').forEach(btn => {
                        btn.addEventListener('mousedown', () => {
                                btn.style.transform = 'scale(0.95)';
                        });
                        btn.addEventListener('mouseup', () => {
                                btn.style.transform = '';
                        });
                        btn.addEventListener('mouseleave', () => {
                                btn.style.transform = '';
                        });
                });
        
        // Close any open dropdown when tapping outside
        document.addEventListener('click', (event) => {
            const notifBtn = document.getElementById('notif-btn');
            const notifPanel = document.getElementById('notif-panel');
            const profileBtn = document.getElementById('profile-btn');
            const profilePanel = document.getElementById('profile-panel');

            if (!notifBtn.contains(event.target) && notifPanel && !notifPanel.contains(event.target)) {
                notifPanel.classList.add('hidden');
                notifBtn.setAttribute('aria-expanded', 'false');
            }
            if (!profileBtn.contains(event.target) && profilePanel && !profilePanel.contains(event.target)) {
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
        </script>
</body></html>
