<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['pemilik', 'penyewa'], true)) {
    header('Location: login_daftar.php');
    exit;
}

$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? ($role === 'pemilik' ? 'Pemilik' : 'Penyewa');
$role_label = $role === 'pemilik' ? 'Pemilik' : 'Penyewa';
$initials = '';
$parts = preg_split('/\s+/', trim($full_name));
if ($parts && $parts[0] !== '') {
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    }
}
if (!$initials) {
    $initials = $role === 'pemilik' ? 'PK' : 'PY';
}

if ($role === 'pemilik') {
    $owner_id = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($owner_id <= 0) {
        header('Location: login_daftar.php');
        exit;
    }
} else {
    if (!isset($_SESSION['tenant_id'])) {
        header('Location: login_daftar.php');
        exit;
    }
    $tenant_id = (int)$_SESSION['tenant_id'];
}

$statusFilter = trim($_GET['status'] ?? 'Semua');
$validStatuses = ['Semua', 'Baru', 'Diproses', 'Selesai'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'Semua';
}

try {
    if ($role === 'pemilik') {
        $countStmt = $pdo->prepare('SELECT status, COUNT(*) AS jumlah FROM pengaduan WHERE owner_id = :owner_id GROUP BY status');
        $countStmt->execute([':owner_id' => $owner_id]);
        $counts = array_column($countStmt->fetchAll(PDO::FETCH_ASSOC), 'jumlah', 'status');

        $query = 'SELECT * FROM pengaduan WHERE owner_id = :owner_id';
        $params = [':owner_id' => $owner_id];
    } else {
        $countStmt = $pdo->prepare('SELECT status, COUNT(*) AS jumlah FROM pengaduan WHERE tenant_id = :tenant_id GROUP BY status');
        $countStmt->execute([':tenant_id' => $tenant_id]);
        $counts = array_column($countStmt->fetchAll(PDO::FETCH_ASSOC), 'jumlah', 'status');

        $query = 'SELECT * FROM pengaduan WHERE tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenant_id];
    }

    if ($statusFilter !== 'Semua') {
        $query .= ' AND status = :status';
        $params[':status'] = $statusFilter;
    }
    $query .= ' ORDER BY created_at DESC LIMIT 100';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reports = [];
    $counts = [];
    $errorMessage = 'Gagal memuat data pengaduan: ' . $e->getMessage();
}

if (!function_exists('escape')) {
    function escape($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// ── Ambil notifikasi dinamis (Pemilik / Penyewa) ─────────────────────────
$notificationsList = [];
try {
    if ($role === 'pemilik') {
        // Owner notifications
        $owner_id = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
        // 1. Transactions
        $notifTxnStmt = $pdo->prepare(
            "SELECT t.id, k.nomor_kamar, t.status, t.updated_at, t.created_at, p.full_name AS penyewa_nama
             FROM transaksi t
             LEFT JOIN penyewa p ON p.id = t.penyewa_id
             LEFT JOIN kamar k ON k.id = t.kamar_id
             WHERE t.owner_id = :oid
             ORDER BY COALESCE(t.updated_at, t.created_at) DESC
             LIMIT 5"
        );
        $notifTxnStmt->execute([':oid' => $owner_id]);
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

            $penyewaName = htmlspecialchars($nt['penyewa_nama'] ?? 'Penyewa');
            $noKamar = htmlspecialchars($nt['nomor_kamar']);

            if (in_array($status, ['menunggu', 'menunggu verifikasi', 'pending'], true)) {
                $notificationsList[] = [
                    'title' => 'Pengajuan Sewa Baru ⏳',
                    'desc' => "Pengajuan sewa Kamar $noKamar oleh $penyewaName menunggu verifikasi.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-amber-50 text-amber-800 border-amber-100'
                ];
            } elseif (in_array($status, ['disetujui', 'lunas', 'approved', 'paid'], true)) {
                $notificationsList[] = [
                    'title' => 'Pengajuan Sewa Disetujui! 🎉',
                    'desc' => "Pengajuan sewa Kamar $noKamar oleh $penyewaName telah disetujui.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-emerald-50 text-emerald-800 border-emerald-100'
                ];
            } elseif ($status === 'selesaimenyewa' || $status === 'selesai') {
                $notificationsList[] = [
                    'title' => 'Sewa Selesai 🏁',
                    'desc' => "Masa sewa Kamar $noKamar oleh $penyewaName telah selesai.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-blue-50 text-blue-800 border-blue-100'
                ];
            } elseif ($status === 'menunggu checkout') {
                $notificationsList[] = [
                    'title' => 'Menunggu Checkout 🚪',
                    'desc' => "Sewa Kamar $noKamar oleh $penyewaName sedang menunggu checkout.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-violet-50 text-violet-800 border-violet-100'
                ];
            } elseif ($status === 'ditolak') {
                $notificationsList[] = [
                    'title' => 'Pengajuan Sewa Ditolak ❌',
                    'desc' => "Pengajuan sewa Kamar $noKamar oleh $penyewaName telah ditolak.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-rose-50 text-rose-800 border-rose-100'
                ];
            }
        }

        // 2. Complaints
        $notifCompStmt = $pdo->prepare(
            "SELECT p.id, p.nomor_kamar, p.jenis_pengaduan, p.deskripsi, p.status, p.created_at, p.nama_penyewa
             FROM pengaduan p
             WHERE p.owner_id = :oid
             ORDER BY p.created_at DESC
             LIMIT 5"
        );
        $notifCompStmt->execute([':oid' => $owner_id]);
        $notifComps = $notifCompStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifComps as $nc) {
            $status = strtolower(trim($nc['status']));
            $timeStr = $nc['created_at'];
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

            $penyewaName = htmlspecialchars($nc['nama_penyewa'] ?? 'Penyewa');
            $noKamar = htmlspecialchars($nc['nomor_kamar']);
            $jenis = htmlspecialchars($nc['jenis_pengaduan']);

            if ($status === 'baru') {
                $notificationsList[] = [
                    'title' => 'Pengaduan Baru ⚠️',
                    'desc' => "Pengaduan baru dari Kamar $noKamar ($jenis) oleh $penyewaName.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-rose-50 text-rose-800 border-rose-100'
                ];
            } elseif ($status === 'proses') {
                $notificationsList[] = [
                    'title' => 'Pengaduan Diproses ⚙️',
                    'desc' => "Pengaduan Kamar $noKamar ($jenis) sedang diproses.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-amber-50 text-amber-800 border-amber-100'
                ];
            } elseif ($status === 'selesai') {
                $notificationsList[] = [
                    'title' => 'Pengaduan Selesai ✅',
                    'desc' => "Pengaduan Kamar $noKamar ($jenis) telah diselesaikan.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-emerald-50 text-emerald-800 border-emerald-100'
                ];
            }
        }
    } else {
        // Tenant notifications
        $userId = (int)($_SESSION['tenant_id'] ?? $_SESSION['user_id'] ?? 0);
        // 1. Transactions
        $notifTxnStmt = $pdo->prepare(
            "SELECT t.id, k.nomor_kamar, t.status, t.updated_at, t.created_at,
                    COALESCE(pm.full_name, 'Pemilik Kos') AS pemilik_nama
             FROM transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             LEFT JOIN pemilik pm ON pm.id = k.owner_id
             WHERE t.penyewa_id = :uid
             ORDER BY COALESCE(t.updated_at, t.created_at) DESC
             LIMIT 5"
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

            $pemilikName = htmlspecialchars($nt['pemilik_nama']);
            $noKamar = htmlspecialchars($nt['nomor_kamar']);

            if (in_array($status, ['menunggu', 'menunggu verifikasi', 'pending'], true)) {
                $notificationsList[] = [
                    'title' => 'Pengajuan Dikirim ⏳',
                    'desc' => "Pengajuan sewa Kamar $noKamar Anda sedang menunggu verifikasi oleh $pemilikName.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-amber-50 text-amber-800 border-amber-100'
                ];
            } elseif (in_array($status, ['disetujui', 'lunas', 'approved', 'paid'], true)) {
                $notificationsList[] = [
                    'title' => 'Pengajuan Disetujui! 🎉',
                    'desc' => "Pengajuan sewa Kamar $noKamar telah disetujui oleh $pemilikName! Silakan cek dasbor.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-emerald-50 text-emerald-800 border-emerald-100'
                ];
            } elseif ($status === 'selesaimenyewa' || $status === 'selesai') {
                $notificationsList[] = [
                    'title' => 'Sewa Selesai 🏁',
                    'desc' => "Masa sewa Kamar $noKamar Anda telah selesai. Terima kasih!",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-blue-50 text-blue-800 border-blue-100'
                ];
            } elseif ($status === 'menunggu checkout') {
                $notificationsList[] = [
                    'title' => 'Menunggu Checkout 🚪',
                    'desc' => "Kamar $noKamar Anda dalam status menunggu checkout.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-violet-50 text-violet-800 border-violet-100'
                ];
            } elseif ($status === 'ditolak') {
                $notificationsList[] = [
                    'title' => 'Pengajuan Ditolak ❌',
                    'desc' => "Pengajuan sewa Kamar $noKamar ditolak oleh $pemilikName.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-rose-50 text-rose-800 border-rose-100'
                ];
            }
        }

        // 2. Complaints
        $notifCompStmt = $pdo->prepare(
            "SELECT p.id, p.nomor_kamar, p.jenis_pengaduan, p.deskripsi, p.status, p.created_at
             FROM pengaduan p
             WHERE p.tenant_id = :uid
             ORDER BY p.created_at DESC
             LIMIT 5"
        );
        $notifCompStmt->execute([':uid' => $userId]);
        $notifComps = $notifCompStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifComps as $nc) {
            $status = strtolower(trim($nc['status']));
            $timeStr = $nc['created_at'];
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

            $noKamar = htmlspecialchars($nc['nomor_kamar']);
            $jenis = htmlspecialchars($nc['jenis_pengaduan']);

            if ($status === 'baru') {
                $notificationsList[] = [
                    'title' => 'Pengaduan Dikirim ⚠️',
                    'desc' => "Pengaduan Kamar $noKamar ($jenis) telah berhasil dikirim.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-rose-50 text-rose-800 border-rose-100'
                ];
            } elseif ($status === 'proses') {
                $notificationsList[] = [
                    'title' => 'Pengaduan Diproses ⚙️',
                    'desc' => "Pengaduan Kamar $noKamar ($jenis) sedang diproses oleh pemilik.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-amber-50 text-amber-800 border-amber-100'
                ];
            } elseif ($status === 'selesai') {
                $notificationsList[] = [
                    'title' => 'Pengaduan Selesai ✅',
                    'desc' => "Pengaduan Kamar $noKamar ($jenis) telah dinyatakan selesai.",
                    'time' => $timeAgo,
                    'timestamp' => strtotime($timeStr),
                    'bg' => 'bg-emerald-50 text-emerald-800 border-emerald-100'
                ];
            }
        }
    }

    // Urutkan notifikasi gabungan berdasarkan timestamp DESC
    usort($notificationsList, function($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });

    // Batasi maksimal 5 notifikasi saja
    $notificationsList = array_slice($notificationsList, 0, 5);

} catch (PDOException $e) {
    // Fallback
}

if (empty($notificationsList)) {
    $notificationsList[] = [
        'title' => 'Belum ada notifikasi',
        'desc' => 'Aktivitas Anda akan muncul di sini jika ada transaksi atau pengaduan baru.',
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
    <title>KelolaKos - Kanal Pengaduan</title>
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
                        "background": "#f1fbff",
                        "on-background": "#131d21",
                        "surface-container-low": "#eaf5fa",
                        "error-container": "#ffdad6",
                        "surface-tint": "#5847d2",
                        "primary-container": "#6c5ce7",
                        "secondary": "#006b55",
                        "surface-container": "#e4f0f4",
                        "surface-variant": "#d9e4e9",
                        "on-surface": "#131d21",
                        "outline-variant": "#c8c4d7",
                        "outline": "#787586",
                        "on-primary": "#ffffff",
                        "on-surface-variant": "#474554",
                        "error": "#ba1a1a",
                        "surface": "#f1fbff",
                        "primary": "#5341cd",
                    },
                    borderRadius: {
                        DEFAULT: '0.25rem',
                        lg: '0.5rem',
                        xl: '0.75rem',
                        full: '9999px'
                    },
                    spacing: {
                        'topbar-height': '72px',
                        'sidebar-width': '260px',
                    },
                    fontFamily: {
                        display: ['Inter', 'sans-serif'],
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1fbff;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            vertical-align: middle;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d9e4e9; border-radius: 10px; }
        .dropdown-panel { min-width: 16rem; }
    </style>
</head>
<body class="text-on-surface bg-[#f1fbff]">

    <!-- Sidebar Navigation -->
    <aside class="hidden md:flex flex-col fixed inset-y-0 left-0 w-[260px] bg-surface shadow-[0px_4px_12px_rgba(0,0,0,0.05)] border-r border-outline-variant z-50">
        <a class="p-6 flex items-center gap-3" href="<?= ($role === 'pemilik') ? 'dashboard_pemilik_kos.php' : 'cari_kos.php' ?>">
            <img alt="KelolaKos Logo" class="w-10 h-10 rounded-lg" src="assets/images/logo.png" />
            <div>
                <h1 class="text-xl font-bold text-primary">KelolaKos</h1>
                <p class="text-xs text-on-surface-variant">Property Management</p>
            </div>
        </a>
        <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto custom-scrollbar">
            <?php if ($role === 'pemilik'): ?>
                <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all duration-200" href="dashboard_pemilik_kos.php">
                    <span class="material-symbols-outlined">dashboard</span>
                    <span class="text-sm font-semibold">Dashboard</span>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all duration-200" href="manajemen_kamar.php">
                    <span class="material-symbols-outlined">domain</span>
                    <span class="text-sm font-semibold">Kamar</span>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all duration-200" href="verifikasi_pembayaran.php">
                    <span class="material-symbols-outlined">payments</span>
                    <span class="text-sm font-semibold">Transaksi</span>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all duration-200" href="laporan_keuangan.php">
                    <span class="material-symbols-outlined">assessment</span>
                    <span class="text-sm font-semibold">Laporan</span>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 text-[#5341cd] font-bold border-l-4 border-[#5341cd] bg-purple-50/60 rounded-r-xl transition-all duration-200" href="kanal_pengaduan.php">
                    <span class="material-symbols-outlined">room_service</span>
                    <span class="text-sm font-semibold">Pengaduan</span>
                </a>
            <?php else: ?>
                <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all duration-200" href="dashboard_penyewa.php">
                    <span class="material-symbols-outlined">dashboard</span>
                    <span class="text-sm font-semibold">Dashboard</span>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all duration-200" href="cari_kos_logged_in.php">
                    <span class="material-symbols-outlined">search</span>
                    <span class="text-sm font-semibold">Cari Kos</span>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 text-[#5341cd] font-bold border-l-4 border-[#5341cd] bg-purple-50/60 rounded-r-xl transition-all duration-200" href="kanal_pengaduan.php">
                    <span class="material-symbols-outlined">room_service</span>
                    <span class="text-sm font-semibold">Pengaduan</span>
                </a>
            <?php endif; ?>
        </nav>
        <div class="p-4 border-t border-outline-variant">
            <div class="flex items-center gap-3 p-2 bg-surface-container-low rounded-xl">
                <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-on-primary font-bold"><?= escape($initials) ?></div>
                <div class="overflow-hidden">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= escape($full_name) ?></p>
                    <p class="text-xs text-on-surface-variant truncate"><?= escape($role_label) ?></p>
                </div>
            </div>
            <a class="mt-4 flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl transition-all" href="logout.php">
                <span class="material-symbols-outlined">logout</span>
                <span class="text-sm font-semibold">Keluar</span>
            </a>
        </div>
    </aside>

    <!-- Topbar Header -->
    <header class="sticky top-0 left-0 right-0 z-40 flex items-center justify-between h-[72px] px-6 md:px-8 ml-0 md:ml-[260px] bg-surface/80 backdrop-blur-md shadow-[0px_4px_12px_rgba(0,0,0,0.05)] border-b border-outline-variant">
        <div class="flex items-center gap-4">
            <button id="mobile-menu-btn" class="md:hidden p-2 rounded-full hover:bg-surface-container-high transition-colors" type="button">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <h2 class="text-xl font-bold text-primary">Kanal Pengaduan</h2>
        </div>
        <div class="flex items-center gap-4">
            <div class="hidden sm:flex items-center gap-3 bg-surface-container px-4 py-2 rounded-full border border-outline-variant focus-within:border-primary transition-all duration-300">
                <span class="material-symbols-outlined text-outline">search</span>
                <input class="bg-transparent border-none focus:ring-0 text-sm w-48 text-slate-700 placeholder-slate-400" type="text" placeholder="Cari pengaduan..." />
            </div>
            <div class="relative">
                <button id="notif-btn" class="p-2 rounded-full hover:bg-surface-container-high transition-colors relative" type="button" aria-expanded="false" aria-haspopup="true" title="Notifikasi">
                    <span class="material-symbols-outlined text-slate-600">notifications</span>
                    <?php if (!empty($notificationsList) && $notificationsList[0]['title'] !== 'Belum ada notifikasi'): ?>
                    <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-error"></span>
                    <?php endif; ?>
                </button>
                <div id="notif-panel" class="hidden absolute right-0 mt-3 w-80 rounded-2xl border border-outline-variant bg-surface p-4 shadow-lg shadow-black/10 ring-1 ring-black/5 z-50">
                    <p class="font-label-sm text-label-sm text-on-surface-variant mb-3 uppercase tracking-[0.18em]">Notifikasi</p>
                    <div class="space-y-3 max-h-80 overflow-y-auto custom-scrollbar">
                        <?php foreach ($notificationsList as $notif): ?>
                            <div class="rounded-xl border p-3 <?= $notif['bg'] ?? 'bg-surface-container-low border-outline-variant' ?>">
                                <p class="text-sm font-semibold text-on-surface"><?= htmlspecialchars($notif['title']) ?></p>
                                <p class="text-xs mt-1 leading-normal text-on-surface-variant"><?= htmlspecialchars($notif['desc']) ?></p>
                                <?php if (!empty($notif['time'])): ?>
                                    <p class="text-[10px] opacity-70 mt-1.5 flex items-center gap-1 text-on-surface-variant">
                                        <span class="material-symbols-outlined text-[12px]">schedule</span>
                                        <?= htmlspecialchars($notif['time']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="relative">
                <button id="profile-btn" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-on-primary font-bold hover:opacity-90 transition-opacity" type="button" aria-expanded="false" aria-haspopup="true">
                    <?= escape($initials) ?>
                </button>
                <div id="profile-panel" class="hidden absolute right-0 mt-3 w-56 rounded-2xl border border-outline-variant bg-surface p-4 shadow-lg shadow-black/10 ring-1 ring-black/5 z-50">
                    <div class="mb-4">
                        <p class="font-semibold text-on-surface"><?= escape($full_name) ?></p>
                        <p class="text-sm text-on-surface-variant"><?= escape($role_label) ?></p>
                    </div>
                    <?php if ($role === 'pemilik'): ?>
                        <a href="dashboard_pemilik_kos.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Dashboard</a>
                        <a href="manajemen_kamar.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Kelola Kamar</a>
                    <?php else: ?>
                        <a href="dashboard_penyewa.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Dashboard</a>
                    <?php endif; ?>
                    <a href="logout.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Keluar</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="md:ml-[260px] p-6 md:p-8 min-h-[calc(100vh-72px)]">
        <div class="max-w-[1440px] mx-auto space-y-6">

            <!-- Title & Action Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="text-2xl font-bold text-slate-800">Daftar Pengaduan & Keluhan</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Kelola laporan pengaduan fasilitas kos dari para penyewa Anda</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($role === 'penyewa'): ?>
                        <a href="kirim_pengaduan.php" class="inline-flex items-center gap-2 rounded-xl bg-[#5341cd] hover:bg-[#4332b8] px-5 py-2.5 text-xs font-bold text-white transition-all shadow-sm">
                            <span class="material-symbols-outlined text-base">add</span>
                            Kirim Pengaduan Baru
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errorMessage)): ?>
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700"><?= escape($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Pengaduan Baru</p>
                        <h3 class="text-2xl font-bold text-[#5341cd] mt-1"><?= $counts['Baru'] ?? 0 ?></h3>
                        <p class="text-[11px] text-slate-400 mt-1">Laporan perlu ditinjau</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-purple-50 text-[#5341cd] flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">mark_email_unread</span>
                    </div>
                </div>

                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Sedang Diproses</p>
                        <h3 class="text-2xl font-bold text-amber-600 mt-1"><?= $counts['Diproses'] ?? 0 ?></h3>
                        <p class="text-[11px] text-slate-400 mt-1">Dalam penanganan fisik/perbaikan</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">hourglass_top</span>
                    </div>
                </div>

                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Selesai</p>
                        <h3 class="text-2xl font-bold text-emerald-600 mt-1"><?= $counts['Selesai'] ?? 0 ?></h3>
                        <p class="text-[11px] text-slate-400 mt-1">Laporan terselesaikan</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">task_alt</span>
                    </div>
                </div>
            </section>

            <!-- Main Filter & List Container -->
            <section class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100 space-y-6">
                <!-- Filter Tabs -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">Laporan Masuk</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Filter laporan berdasarkan status penanganan</p>
                    </div>
                    <div class="flex items-center bg-slate-100 p-1 rounded-xl gap-1">
                        <?php foreach ($validStatuses as $st): ?>
                            <a href="kanal_pengaduan.php?status=<?= urlencode($st) ?>" class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-all <?= $statusFilter === $st ? 'bg-white text-[#5341cd] shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                                <?= escape($st) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Reports Cards List -->
                <?php if (count($reports) === 0): ?>
                    <div class="py-12 text-center text-slate-500 space-y-3">
                        <div class="w-16 h-16 bg-purple-50 text-[#5341cd] rounded-full flex items-center justify-center mx-auto">
                            <span class="material-symbols-outlined text-3xl">inbox</span>
                        </div>
                        <h4 class="text-base font-bold text-slate-800">Tidak ada pengaduan.</h4>
                        <p class="text-xs text-slate-500">Belum ada laporan pengaduan yang tercatat sesuai filter yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="grid gap-4">
                        <?php foreach ($reports as $report): ?>
                            <div class="rounded-2xl border border-slate-100 bg-slate-50/40 p-5 hover:bg-white hover:border-slate-200 transition-all shadow-none hover:shadow-sm space-y-3">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <?php
                                            $priority = $report['prioritas'] ?? 'Sedang';
                                            $pStyle = 'bg-blue-50 text-blue-700 border-blue-200';
                                            if ($priority === 'Tinggi') { $pStyle = 'bg-rose-50 text-rose-700 border-rose-200'; }
                                            if ($priority === 'Sedang') { $pStyle = 'bg-amber-50 text-amber-700 border-amber-200'; }

                                            $status = $report['status'] ?? 'Baru';
                                            $sStyle = 'bg-purple-50 text-[#5341cd] border-purple-200';
                                            if ($status === 'Diproses') { $sStyle = 'bg-amber-50 text-amber-700 border-amber-200'; }
                                            if ($status === 'Selesai') { $sStyle = 'bg-emerald-50 text-emerald-700 border-emerald-200'; }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $pStyle ?>">
                                            Prioritas <?= escape($priority) ?>
                                        </span>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $sStyle ?>">
                                            <?= escape($status) ?>
                                        </span>
                                    </div>
                                    <span class="text-xs text-slate-400">
                                        <?= date('d M Y, H:i', strtotime($report['created_at'] ?? 'now')) ?>
                                    </span>
                                </div>

                                <div>
                                    <h4 class="text-base font-bold text-slate-800">
                                        <?= escape($report['jenis_pengaduan']) ?> &bull; Kamar <?= escape($report['nomor_kamar'] ?: '-') ?>
                                    </h4>
                                    <p class="text-sm text-slate-600 mt-1 leading-relaxed">
                                        <?= nl2br(escape($report['deskripsi'])) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        const menuBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.querySelector('aside');
        const notifBtn = document.getElementById('notif-btn');
        const notifPanel = document.getElementById('notif-panel');
        const profileBtn = document.getElementById('profile-btn');
        const profilePanel = document.getElementById('profile-panel');
        let menuOpen = false;

        menuBtn?.addEventListener('click', () => {
            menuOpen = !menuOpen;
            if (menuOpen) {
                sidebar.classList.remove('hidden');
                sidebar.classList.add('fixed', 'inset-0', 'w-[280px]', 'z-[60]');
            } else {
                sidebar.classList.add('hidden');
            }
        });

        document.addEventListener('click', (event) => {
            if (notifPanel && profilePanel) {
                if (!notifPanel.contains(event.target) && event.target !== notifBtn) {
                    notifPanel.classList.add('hidden');
                    notifBtn?.setAttribute('aria-expanded', 'false');
                }
                if (!profilePanel.contains(event.target) && event.target !== profileBtn) {
                    profilePanel.classList.add('hidden');
                    profileBtn?.setAttribute('aria-expanded', 'false');
                }
            }
        });

        notifBtn?.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = !notifPanel.classList.contains('hidden');
            notifPanel.classList.toggle('hidden', open);
            notifBtn?.setAttribute('aria-expanded', String(!open));
        });

        profileBtn?.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = !profilePanel.classList.contains('hidden');
            profilePanel.classList.toggle('hidden', open);
            profileBtn?.setAttribute('aria-expanded', String(!open));
        });
    </script>
</body>
</html>
