<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';

/**
 * Auto-sync owner_id transaksi (wajib dipanggil SEBELUM query apapun di pemilik dashboard
 * agar data pengajuan lama yang owner_id-nya nol ikut ter-multitenant dengan benar).
 */
function syncTxOwnerIdForDashboard(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'owner_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE transaksi ADD COLUMN owner_id INT NOT NULL DEFAULT 0 AFTER id");
        }
        $pdo->exec(
            "UPDATE transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             SET t.owner_id = k.owner_id
             WHERE (t.owner_id IS NULL OR t.owner_id = 0 OR t.owner_id <> k.owner_id)
               AND k.owner_id IS NOT NULL AND k.owner_id <> 0"
        );
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Pastikan ENUM status transaksi punya 'Menunggu Checkout' & 'Selesai' agar tidak error.
 */
function ensureTxEnumForDashboard(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'status'")->fetch();
        if ($col && strpos($col['Type'], 'Menunggu Checkout') === false) {
            $pdo->exec("ALTER TABLE transaksi MODIFY COLUMN status ENUM(
                'Menunggu','Menunggu Verifikasi','Pending','Disetujui','Ditolak','Lunas','Belum Bayar','Menunggu Checkout','Selesai'
            ) NOT NULL DEFAULT 'Menunggu Verifikasi'");
        }
    } catch (Throwable $e) { /* ignore */ }
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
    header('Location: login_daftar.php');
    exit();
}

$owner_id = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
$full_name = $_SESSION['full_name'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pemilik';
$role_label = ($_SESSION['role'] ?? '') === 'pemilik' ? 'Pemilik' : (($_SESSION['role'] ?? '') === 'penyewa' ? 'Penyewa' : 'Pengguna');

// Selalu ambil nama terbaru dari DB agar sesi lama tidak menampilkan nama usang
try {
    if ($owner_id > 0) {
        $stmtName = $pdo->prepare('SELECT full_name FROM pemilik WHERE id = :id LIMIT 1');
        $stmtName->execute([':id' => $owner_id]);
        $rowName = $stmtName->fetch(PDO::FETCH_ASSOC);
        if ($rowName && !empty($rowName['full_name'])) {
            $full_name = $rowName['full_name'];
            $_SESSION['full_name'] = $full_name; // Sinkronkan sesi
        }
    }
} catch (PDOException $e) {
    // Gunakan nama dari sesi jika DB gagal
}

$initials = '';
$parts = preg_split('/\s+/', trim($full_name));
if ($parts) {
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    }
}

$penyewaRequests = [];
$totalRooms = 0;
$occupiedRooms = 0;
$vacantRooms = 0;
$totalIncome = 0;

if ($owner_id > 0) {
    // 🔑 SYNC SEBELUM QUERY — supaya transaksi owner_id=0 / salah terisi benar terlebih dahulu
    syncTxOwnerIdForDashboard($pdo);
    ensureTxEnumForDashboard($pdo);

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kamar WHERE owner_id = :owner_id');
        $stmt->execute([':owner_id' => $owner_id]);
        $totalRooms = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kamar WHERE owner_id = :owner_id AND status = "Terisi"');
        $stmt->execute([':owner_id' => $owner_id]);
        $occupiedRooms = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kamar WHERE owner_id = :owner_id AND status = "Kosong"');
        $stmt->execute([':owner_id' => $owner_id]);
        $vacantRooms = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(harga_sewa), 0) FROM kamar WHERE owner_id = :owner_id AND status IN ("Terisi", "Menunggak")');
        $stmt->execute([':owner_id' => $owner_id]);
        $totalIncome = (int)$stmt->fetchColumn();

        $tableCheck = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $tableCheck->execute([':table_name' => 'transaksi']);
        if ($tableCheck->fetch()) {
            if ($owner_id > 0) {
                // 🔑 FIX SQLSTATE 1054:
                //   - WHERE hanya pakai t.owner_id (SUDAH di-sync)
                //   - TIDAK menyertakan p.hp / p.phone_number / apapun kolom HP
                //   - Kompatibel kolom total_harga ATAU nominal via COALESCE
                $requestStmt = $pdo->prepare(
                    "SELECT t.id, t.penyewa_id, t.kamar_id,
                            COALESCE(NULLIF(t.total_harga,0), NULLIF(t.nominal,0), 0) AS total_harga,
                            t.status,
                            COALESCE(t.tanggal_pengajuan, t.created_at) AS tanggal_pengajuan,
                            p.full_name AS penyewa_nama,
                            k.nomor_kamar AS kamar_nomor
                     FROM transaksi t
                     LEFT JOIN penyewa p ON p.id = t.penyewa_id
                     LEFT JOIN kamar k ON k.id = t.kamar_id
                     WHERE t.status IN ('Menunggu', 'Menunggu Verifikasi', 'Pending')
                       AND t.owner_id = :oid
                     ORDER BY t.created_at DESC"
                );
                $requestStmt->execute([':oid' => $owner_id]);
            } else {
                $requestStmt = $pdo->prepare(
                    "SELECT t.id, t.penyewa_id, t.kamar_id,
                            COALESCE(NULLIF(t.total_harga,0), NULLIF(t.nominal,0), 0) AS total_harga,
                            t.status,
                            COALESCE(t.tanggal_pengajuan, t.created_at) AS tanggal_pengajuan,
                            p.full_name AS penyewa_nama,
                            k.nomor_kamar AS kamar_nomor
                     FROM transaksi t
                     LEFT JOIN penyewa p ON p.id = t.penyewa_id
                     LEFT JOIN kamar k ON k.id = t.kamar_id
                     WHERE t.status IN ('Menunggu', 'Menunggu Verifikasi', 'Pending')
                     ORDER BY t.created_at DESC"
                );
                $requestStmt->execute();
            }
            $penyewaRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $penyewaRequests = [];
    }

    // ── Ambil notifikasi dinamis untuk Pemilik Kos ─────────────────────────
    $notificationsList = [];
    try {
        // 1. Ambil transaksi sewa terakhir milik pemilik ini
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

        // 2. Ambil pengaduan sewa terakhir milik pemilik ini
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
            'desc' => 'Aktivitas kos Anda akan muncul di sini jika ada transaksi atau pengaduan baru.',
            'time' => '',
            'bg' => 'bg-slate-50 text-slate-600 border-slate-100'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KelolaKos - Dashboard Pemilik Kos</title>
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
                        "surface-container-highest": "#eaf5fa",
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
                    borderRadius: {
                        DEFAULT: '0.25rem',
                        lg: '0.5rem',
                        xl: '0.75rem',
                        full: '9999px'
                    },
                    spacing: {
                        'stack-sm': '8px',
                        'stack-lg': '24px',
                        'topbar-height': '72px',
                        'sidebar-width': '260px',
                        'stack-md': '16px',
                        'container-padding': '32px',
                        'gutter': '24px'
                    },
                    fontFamily: {
                        display: ['Inter', 'sans-serif'],
                        sans: ['Inter', 'sans-serif']
                    },
                    fontSize: {
                        'display-lg': ['32px', { lineHeight: '40px', letterSpacing: '-0.02em', fontWeight: '700' }],
                        'headline-md': ['24px', { lineHeight: '32px', letterSpacing: '-0.01em', fontWeight: '600' }],
                        'body-md': ['14px', { lineHeight: '20px', fontWeight: '400' }],
                        'label-md': ['13px', { lineHeight: '18px', letterSpacing: '0.05em', fontWeight: '600' }]
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
        .bento-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 24px; }
        @media (max-width: 768px) { .bento-grid { grid-template-columns: 1fr; } }
        .dropdown-panel { min-width: 16rem; }
    </style>
</head>
<body class="text-on-surface">
    <aside class="hidden md:flex flex-col fixed inset-y-0 left-0 w-[260px] bg-surface shadow-[0px_4px_12px_rgba(0,0,0,0.05)] border-r border-outline-variant z-50">
        <a class="p-6 flex items-center gap-3" href="dashboard_pemilik_kos.php">
            <img alt="KelolaKos Logo" class="w-10 h-10 rounded-lg" src="assets/images/logo.png" />
            <div>
                <h1 class="font-headline-md text-headline-md font-bold text-primary">KelolaKos</h1>
                <p class="font-label-sm text-label-sm text-on-surface-variant">Property Management</p>
            </div>
        </a>
        <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto custom-scrollbar">
            <a class="flex items-center gap-3 px-4 py-3 text-primary font-bold border-l-4 border-primary bg-surface-container-low" href="dashboard_pemilik_kos.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="font-label-md text-label-md">Dashboard</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container" href="manajemen_kamar.php">
                <span class="material-symbols-outlined">domain</span>
                <span class="font-label-md text-label-md">Kamar</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container" href="verifikasi_pembayaran.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-label-md text-label-md">Transaksi</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container" href="laporan_keuangan.php">
                <span class="material-symbols-outlined">assessment</span>
                <span class="font-label-md text-label-md">Laporan</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container" href="kanal_pengaduan.php">
                <span class="material-symbols-outlined">room_service</span>
                <span class="font-label-md text-label-md">Pengaduan</span>
            </a>
        </nav>
        <div class="p-4 border-t border-outline-variant">
            <div class="flex items-center gap-3 p-2 bg-surface-container-low rounded-xl">
                <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-on-primary font-bold"><?php echo htmlspecialchars($initials); ?></div>
                <div class="overflow-hidden">
                    <p class="font-label-md text-label-md truncate"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="font-label-sm text-label-sm text-on-surface-variant truncate">Pemilik</p>
                </div>
            </div>
            <a class="mt-4 flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container rounded-xl" href="logout.php">
                <span class="material-symbols-outlined">logout</span>
                <span class="font-label-md text-label-md">Keluar</span>
            </a>
        </div>
    </aside>

    <header class="sticky top-0 left-0 right-0 z-40 flex items-center justify-between h-[72px] px-8 ml-0 md:ml-[260px] bg-surface/80 backdrop-blur-md shadow-[0px_4px_12px_rgba(0,0,0,0.05)]">
        <div class="flex items-center gap-4">
            <button id="mobile-menu-btn" class="md:hidden p-2 rounded-full hover:bg-surface-container-high transition-colors" type="button">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <h2 class="font-headline-sm text-headline-sm font-bold text-primary">Dashboard Pemilik</h2>
        </div>
        <div class="flex items-center gap-4">
            <div class="hidden sm:flex items-center gap-3 bg-surface-container px-4 py-2 rounded-full border border-outline-variant focus-within:border-primary transition-all duration-300">
                <span class="material-symbols-outlined text-outline">search</span>
                <input class="bg-transparent border-none focus:ring-0 text-body-md w-48" type="text" placeholder="Cari data..." />
            </div>
            <div class="relative">
                <button id="notif-btn" class="p-2 rounded-full hover:bg-surface-container-high transition-colors relative" type="button" aria-expanded="false" aria-haspopup="true" title="Notifikasi">
                    <span class="material-symbols-outlined">notifications</span>
                    <?php if (!empty($notificationsList) && $notificationsList[0]['title'] !== 'Belum ada notifikasi'): ?>
                    <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-error"></span>
                    <?php endif; ?>
                </button>
                <div id="notif-panel" class="hidden absolute right-0 mt-3 w-80 rounded-2xl border border-outline-variant bg-surface p-4 shadow-lg shadow-black/10 ring-1 ring-black/5 z-50">
                    <p class="font-label-sm text-label-sm text-on-surface-variant mb-3 uppercase tracking-[0.18em]">Notifikasi</p>
                    <div class="space-y-3 max-h-80 overflow-y-auto custom-scrollbar">
                        <?php foreach ($notificationsList as $notif): ?>
                            <div class="rounded-xl border p-3 <?= $notif['bg'] ?? 'bg-surface-container-low border-outline-variant' ?>">
                                <p class="text-sm font-semibold"><?= htmlspecialchars($notif['title']) ?></p>
                                <p class="text-xs mt-1 leading-normal"><?= htmlspecialchars($notif['desc']) ?></p>
                                <?php if (!empty($notif['time'])): ?>
                                    <p class="text-[10px] opacity-70 mt-1.5 flex items-center gap-1">
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
                <button id="profile-btn" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-on-primary font-bold hover:opacity-90 transition-opacity" type="button" aria-expanded="false" aria-haspopup="true" aria-label="Profil <?php echo htmlspecialchars($full_name); ?>">
                    <?php echo htmlspecialchars($initials); ?>
                </button>
                <div id="profile-panel" class="hidden absolute right-0 mt-3 w-56 rounded-2xl border border-outline-variant bg-surface p-4 shadow-lg shadow-black/10 ring-1 ring-black/5">
                    <div class="mb-4">
                        <p class="font-semibold text-on-surface"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-on-surface-variant"><?php echo htmlspecialchars($role_label); ?></p>
                    </div>
                    <a href="dashboard_pemilik_kos.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Dashboard</a>
                    <a href="manajemen_kamar.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Kelola Kamar</a>
                    <a href="logout.php" class="block rounded-xl px-3 py-2 text-sm text-on-surface hover:bg-surface-container transition">Keluar</a>
                </div>
            </div>
        </div>
    </header>

    <main class="md:ml-[260px] p-8 min-h-screen">
        <div class="max-w-[1440px] mx-auto space-y-8">
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)] relative overflow-hidden">
                    <p class="font-label-md text-label-md text-on-surface-variant mb-1">Kamar Terisi</p>
                    <h3 class="font-headline-md text-headline-md font-bold text-on-surface"><?php echo $occupiedRooms; ?> <span class="text-label-sm font-medium text-on-surface-variant">/ <?php echo $totalRooms; ?></span></h3>
                </div>
                <div class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)] relative overflow-hidden">
                    <p class="font-label-md text-label-md text-on-surface-variant mb-1">Kamar Kosong</p>
                    <h3 class="font-headline-md text-headline-md font-bold text-on-surface"><?php echo $vacantRooms; ?> <span class="text-label-sm font-medium text-on-surface-variant">Kamar</span></h3>
                </div>
                <div class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)] relative overflow-hidden">
                    <p class="font-label-md text-label-md text-on-surface-variant mb-1">Pemasukan Bulan Ini</p>
                    <h3 class="font-headline-md text-headline-md font-bold text-emerald-600">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></h3>
                </div>
                <div class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)] relative overflow-hidden">
                    <p class="font-label-md text-label-md text-on-surface-variant mb-1">Total Properti Kamar</p>
                    <h3 class="font-headline-md text-headline-md font-bold text-primary"><?php echo $totalRooms; ?> <span class="text-label-sm font-medium text-on-surface-variant">Unit</span></h3>
                </div>
            </section>

            <section class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)]">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="font-label-md text-label-md text-on-surface-variant">Selamat datang, <?php echo htmlspecialchars($full_name); ?></p>
                        <h2 class="mt-2 font-headline-md text-headline-md font-bold text-on-surface">Kelola properti Anda dengan aman dan cepat.</h2>
                    </div>
                    <a href="manajemen_kamar.php" class="inline-flex items-center rounded-full bg-primary px-5 py-3 text-sm font-bold text-on-primary hover:bg-primary/90 transition">Buka Manajemen Kamar</a>
                </div>
            </section>

            <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)]">
                    <h3 class="font-headline-sm text-headline-sm font-bold text-on-surface mb-4">Ajuan Sewa Masuk</h3>
                    <?php if (empty($penyewaRequests)): ?>
                        <div class="rounded-xl bg-surface-container-low p-4 text-body-md text-on-surface-variant">
                            Belum ada ajuan sewa masuk.
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($penyewaRequests as $request): ?>
                                <div class="rounded-xl border border-outline-variant bg-surface-container-low p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="font-semibold text-on-surface"><?php echo htmlspecialchars($request['penyewa_nama'] ?? 'Penyewa'); ?></p>
                                            <p class="text-sm text-on-surface-variant">
                                                Kamar <?php echo htmlspecialchars($request['kamar_nomor'] ?: ($request['nomor_kamar'] ?? '-')); ?> •
                                                <?php echo htmlspecialchars(date('d M Y', strtotime($request['tanggal_pengajuan'] ?? date('Y-m-d')))); ?>
                                            </p>
                                            <p class="mt-1 text-sm font-medium text-primary">
                                                Rp <?php echo number_format((float)($request['total_harga'] ?? 0), 0, ',', '.'); ?>
                                            </p>
                                        </div>
                                        <div class="flex gap-2">
                                            <form method="POST" action="update_status_sewa.php">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>" />
                                                <input type="hidden" name="status" value="Disetujui" />
                                                <button type="submit" class="rounded-full bg-secondary px-3 py-2 text-xs font-semibold text-on-secondary hover:opacity-90">Setujui</button>
                                            </form>
                                            <form method="POST" action="update_status_sewa.php">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>" />
                                                <input type="hidden" name="status" value="Ditolak" />
                                                <button type="submit" class="rounded-full bg-error px-3 py-2 text-xs font-semibold text-on-error hover:opacity-90">Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-xs text-on-surface-variant">Status: <?php echo htmlspecialchars($request['status'] ?? 'Menunggu Konfirmasi'); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="bg-surface p-6 rounded-xl shadow-[0px_4px_12px_rgba(0,0,0,0.05)]">
                    <h3 class="font-headline-sm text-headline-sm font-bold text-on-surface mb-4">Shortcut</h3>
                    <div class="space-y-3">
                        <a class="block rounded-xl border border-outline-variant px-4 py-3 hover:border-primary hover:bg-surface-container transition" href="laporan_keuangan.php">Lihat Laporan Keuangan</a>
                        <a class="block rounded-xl border border-outline-variant px-4 py-3 hover:border-primary hover:bg-surface-container transition" href="kanal_pengaduan.php">Buka Kanal Pengaduan</a>
                        <a class="block rounded-xl border border-outline-variant px-4 py-3 hover:border-primary hover:bg-surface-container transition" href="verifikasi_pembayaran.php">Verifikasi Pembayaran</a>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-surface shadow-[0px_-4px_12px_rgba(0,0,0,0.05)] border-t border-outline-variant flex justify-around items-center h-16 z-50">
        <a class="flex flex-col items-center gap-1 text-primary" href="dashboard_pemilik_kos.php"><span class="material-symbols-outlined">dashboard</span><span class="text-[10px] font-bold">Dash</span></a>
        <a class="flex flex-col items-center gap-1 text-on-surface-variant" href="manajemen_kamar.php"><span class="material-symbols-outlined">domain</span><span class="text-[10px] font-medium">Kamar</span></a>
        <a class="flex flex-col items-center gap-1 text-on-surface-variant" href="laporan_keuangan.php"><span class="material-symbols-outlined">assessment</span><span class="text-[10px] font-medium">Lapor</span></a>
        <a class="flex flex-col items-center gap-1 text-on-surface-variant" href="logout.php"><span class="material-symbols-outlined">logout</span><span class="text-[10px] font-medium">Keluar</span></a>
    </nav>

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
