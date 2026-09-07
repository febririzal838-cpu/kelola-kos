<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';

/**
 * Pastikan owner_id di tabel transaksi selalu tersedia dan sinkron (data lama juga ikut tertampil).
 */
function ensureOwnerIdSync(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'owner_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE transaksi ADD COLUMN owner_id INT NOT NULL DEFAULT 0 AFTER id");
        }
        // Sync owner_id yang 0 / NULL / mismatch dari tabel kamar
        $pdo->exec(
            "UPDATE transaksi t
             LEFT JOIN kamar k ON k.id = t.kamar_id
             SET t.owner_id = k.owner_id
             WHERE (t.owner_id IS NULL OR t.owner_id = 0 OR t.owner_id <> k.owner_id)
               AND k.owner_id IS NOT NULL AND k.owner_id <> 0"
        );
    } catch (Throwable $e) { /* ignore */ }
}

function ensureTxEnum(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'status'")->fetch();
        if ($col && strpos($col['Type'], 'Menunggu Checkout') === false) {
            $pdo->exec("ALTER TABLE transaksi MODIFY COLUMN status ENUM(
                'Menunggu','Menunggu Verifikasi','Pending',
                'Disetujui','Ditolak','Lunas','Belum Bayar',
                'Menunggu Checkout','Selesai'
            ) NOT NULL DEFAULT 'Menunggu Verifikasi'");
        }
    } catch (Throwable $e) { /* ignore */ }
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai pemilik kos untuk mengakses verifikasi pembayaran.') . '&type=error');
    exit;
}

$owner_id = (int)($_SESSION['owner_id'] ?? $_SESSION['pemilik_id'] ?? $_SESSION['user_id'] ?? 0);
$full_name = $_SESSION['full_name'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pemilik';

// Selalu ambil nama terbaru dari DB agar sesi lama tidak menampilkan nama usang
try {
    if ($owner_id > 0) {
        $stmtName = $pdo->prepare('SELECT full_name FROM pemilik WHERE id = :id LIMIT 1');
        $stmtName->execute([':id' => $owner_id]);
        $rowName = $stmtName->fetch(PDO::FETCH_ASSOC);
        if ($rowName && !empty($rowName['full_name'])) {
            $full_name = $rowName['full_name'];
            $_SESSION['full_name'] = $full_name;
        }
    }
} catch (PDOException $e) {
    // Gunakan nama dari sesi jika DB gagal
}

$initials = '';
$parts = preg_split('/\s+/', trim($full_name));
if ($parts && $parts[0] !== '') {
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    }
}
if (!$initials) {
    $initials = 'PK';
}

$msg = trim($_GET['msg'] ?? '');
$msgType = ($_GET['type'] ?? ($_GET['status'] ?? 'success')) === 'error' ? 'error' : 'success';

// Pastikan tabel & owner_id sinkron SEBELUM query apapun (krusial!)
ensureOwnerIdSync($pdo);
ensureTxEnum($pdo);

// Action handler for status updates (Approve/Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestAction = trim($_POST['action']);
    $requestId     = (int)($_POST['request_id'] ?? 0);

    // ── Konfirmasi Checkout (Pemilik: tandai Selesai & kembalikan kamar Kosong) ──
    if ($requestAction === 'konfirmasi_checkout' && $requestId > 0) {
        try {
            $pdo->beginTransaction();

            // Ambil kamar_id dan penyewa_id transaksi ini — 🔑 HANYA jika transaksi.owner_id = owner_id
            $chkTxn = $pdo->prepare(
                'SELECT t.kamar_id, t.penyewa_id FROM transaksi t
                 WHERE t.id = :id AND t.status = :st AND t.owner_id = :oid LIMIT 1'
            );
            $chkTxn->execute([':id' => $requestId, ':st' => 'Menunggu Checkout', ':oid' => $owner_id]);
            $chkRow = $chkTxn->fetch(PDO::FETCH_ASSOC);

            if (!$chkRow) {
                throw new RuntimeException('Transaksi tidak ditemukan atau tidak berhak dikonfirmasi.');
            }

            // 1. Transaksi → Selesai
            $pdo->prepare('UPDATE transaksi SET status = :s, updated_at = NOW() WHERE id = :id')
                ->execute([':s' => 'Selesai', ':id' => $requestId]);

            // 2. Kamar → Kosong
            $pdo->prepare('UPDATE kamar SET status = :s WHERE id = :id')
                ->execute([':s' => 'Kosong', ':id' => (int)$chkRow['kamar_id']]);

            // 3. Putuskan relasi penyewa-kamar
            $pdo->prepare('UPDATE penyewa SET kamar_id = NULL WHERE id = :pid')
                ->execute([':pid' => (int)$chkRow['penyewa_id']]);

            $pdo->commit();
            header('Location: verifikasi_pembayaran.php?msg=' . urlencode('Checkout dikonfirmasi. Kamar kini kembali berstatus Kosong.') . '&type=success');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg     = 'Gagal konfirmasi checkout: ' . $e->getMessage();
            $msgType = 'error';
        }
    }

    // ── Approve / Reject biasa ────────────────────────────────────────────────
    if ($requestAction === 'update_status') {
    $newStatus = trim($_POST['status'] ?? '');

    if ($requestId > 0 && in_array($newStatus, ['Disetujui', 'Ditolak'], true)) {
        try {
            // 🔑 Ganti WHERE JOIN ke t.owner_id langsung (sudah kita sync ensureOwnerIdSync)
            $updateStmt = $pdo->prepare(
                'UPDATE transaksi SET status = :status, updated_at = NOW()
                 WHERE id = :id AND owner_id = :oid'
            );
            $updateStmt->execute([
                ':status' => $newStatus,
                ':id'     => $requestId,
                ':oid'    => $owner_id,
            ]);

            if ($newStatus === 'Disetujui') {
                $requestStmt = $pdo->prepare(
                    'SELECT t.penyewa_id, t.kamar_id FROM transaksi t
                     WHERE t.id = :id AND t.owner_id = :oid LIMIT 1'
                );
                $requestStmt->execute([':id' => $requestId, ':oid' => $owner_id]);
                $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    $pdo->prepare('UPDATE penyewa SET kamar_id = :kamar_id WHERE id = :penyewa_id')->execute([
                        ':kamar_id' => (int)$request['kamar_id'],
                        ':penyewa_id' => (int)$request['penyewa_id'],
                    ]);
                    $pdo->prepare('UPDATE kamar SET status = :status WHERE id = :id')->execute([
                        ':status' => 'Terisi',
                        ':id' => (int)$request['kamar_id'],
                    ]);
                }
            }
            header('Location: verifikasi_pembayaran.php?msg=' . urlencode('Status transaksi berhasil diperbarui menjadi ' . $newStatus) . '&type=success');
            exit;
        } catch (PDOException $e) {
            $msg = 'Gagal memperbarui status transaksi: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
    } // end update_status
}

// 🔑 FIX 3A: validStatuses harus mencakup semua enum.
$statusFilter = trim($_GET['status'] ?? 'Semua');
$validStatuses = ['Semua', 'Menunggu', 'Menunggu Verifikasi', 'Pending', 'Disetujui', 'Lunas', 'Belum Bayar', 'Menunggu Checkout', 'Ditolak', 'Selesai'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'Semua';
}

$search = trim($_GET['q'] ?? '');
$transactions = [];
$counts = ['Menunggu' => 0, 'Disetujui' => 0, 'Menunggu Checkout' => 0, 'Ditolak' => 0, 'Selesai' => 0];

try {
    // 🔑 FIX 3B: Count query sekarang pakai t.owner_id langsung (bukan kamar.owner_id/p.owner_id yang salah)
    $countStmt = $pdo->prepare('SELECT t.status, COUNT(*) AS jumlah FROM transaksi t WHERE t.owner_id = :oid GROUP BY t.status');
    $countStmt->execute([':oid' => $owner_id]);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $st  = trim($row['status'] ?? 'Menunggu');
        $stLower = strtolower($st);
        $jml = (int)$row['jumlah'];
        if (in_array($stLower, ['menunggu', 'menunggu verifikasi', 'pending'], true)) {
            $counts['Menunggu'] += $jml;
        } elseif (in_array($stLower, ['disetujui', 'lunas', 'approved', 'paid'], true)) {
            $counts['Disetujui'] += $jml;
        } elseif (array_key_exists($st, $counts)) {
            $counts[$st] += $jml;
        }
    }

    // 🔑 FIX 3C: WHERE clause utama — PAKAI t.owner_id LANGSUNG! (telah disync ensureOwnerIdSync)
    $whereClauses = ['t.owner_id = :oid'];
    $params = [':oid' => $owner_id];

    if ($statusFilter !== 'Semua') {
        if ($statusFilter === 'Menunggu') {
            $whereClauses[] = "t.status IN ('Menunggu', 'Menunggu Verifikasi', 'Pending')";
        } else {
            $whereClauses[] = 't.status = :status';
            $params[':status'] = $statusFilter;
        }
    }

    if ($search !== '') {
        // 🔑 FIX 3D: cari juga bukti_pembayaran & id transaksi; tapi TIDAK menyembunyikan jika bukti NULL
        $whereClauses[] = '(k.nomor_kamar LIKE :s1 OR p.full_name LIKE :s2 OR p.email LIKE :s3 OR CAST(t.id AS CHAR) LIKE :s4)';
        $params[':s1'] = "%{$search}%";
        $params[':s2'] = "%{$search}%";
        $params[':s3'] = "%{$search}%";
        $params[':s4'] = "%{$search}%";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
    // ✅ Fix SQLSTATE 1054: Gunakan p.phone_number yang ada di skema
    $query = "SELECT t.*,
                     p.full_name AS penyewa_nama, p.email AS penyewa_email,
                     COALESCE(NULLIF(p.phone_number,''), '-') AS penyewa_hp,
                     k.tipe_kamar AS kamar_tipe, k.nomor_kamar AS kamar_nomor
              FROM transaksi t
              LEFT JOIN penyewa p ON p.id = t.penyewa_id
              LEFT JOIN kamar k ON k.id = t.kamar_id
              {$whereSql}
              ORDER BY
                CASE WHEN t.status IN ('Menunggu','Menunggu Verifikasi','Pending') THEN 0 ELSE 1 END ASC,
                t.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    $msg = 'Terjadi kesalahan saat memuat data transaksi: ' . $e->getMessage();
    $msgType = 'error';
}

$totalTransactions = array_sum($counts);

function escape($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Verifikasi Pembayaran - KelolaKos</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#5341cd",
                        "primary-container": "#6c5ce7",
                        "on-primary": "#ffffff",
                        "background": "#f8fafc",
                        "surface": "#ffffff"
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            vertical-align: middle;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="text-slate-800 bg-[#f8fafc] antialiased">
    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex flex-col fixed inset-y-0 left-0 w-[260px] bg-white shadow-[0px_4px_12px_rgba(0,0,0,0.05)] border-r border-slate-200 z-50">
        <a class="p-6 flex items-center gap-3" href="dashboard_pemilik_kos.php">
            <img alt="KelolaKos Logo" class="w-10 h-10 rounded-lg object-contain" src="assets/images/logo.png"/>
            <div>
                <h1 class="text-xl font-bold text-[#5341cd]">KelolaKos</h1>
                <p class="text-xs text-slate-500 font-medium">Property Management</p>
            </div>
        </a>
        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto custom-scrollbar">
            <a class="flex items-center gap-3 px-4 py-3 text-slate-600 font-medium rounded-xl hover:bg-slate-100 transition-all duration-200" href="dashboard_pemilik_kos.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="text-sm font-semibold">Dashboard</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-slate-600 font-medium rounded-xl hover:bg-slate-100 transition-all duration-200" href="manajemen_kamar.php">
                <span class="material-symbols-outlined">domain</span>
                <span class="text-sm font-semibold">Kamar</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-[#5341cd] font-bold border-l-4 border-[#5341cd] bg-purple-50/60 rounded-r-xl transition-all duration-200" href="verifikasi_pembayaran.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="text-sm font-semibold">Transaksi</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-slate-600 font-medium rounded-xl hover:bg-slate-100 transition-all duration-200" href="laporan_keuangan.php">
                <span class="material-symbols-outlined">assessment</span>
                <span class="text-sm font-semibold">Laporan</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-slate-600 font-medium rounded-xl hover:bg-slate-100 transition-all duration-200" href="kanal_pengaduan.php">
                <span class="material-symbols-outlined">room_service</span>
                <span class="text-sm font-semibold">Pengaduan</span>
            </a>
        </nav>
        <div class="p-4 border-t border-slate-200 space-y-3">
            <div class="flex items-center gap-3 p-2 bg-slate-50 rounded-xl border border-slate-100">
                <div class="w-10 h-10 rounded-full bg-[#5341cd] flex items-center justify-center text-white font-bold text-sm"><?= htmlspecialchars($initials) ?></div>
                <div class="overflow-hidden">
                    <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($full_name) ?></p>
                    <p class="text-xs text-slate-500 truncate">Pemilik Kos</p>
                </div>
            </div>
            <a class="flex items-center gap-3 px-4 py-2.5 text-red-600 font-medium hover:bg-red-50 rounded-xl transition-all duration-200" href="logout.php">
                <span class="material-symbols-outlined">logout</span>
                <span class="text-sm font-semibold">Keluar</span>
            </a>
        </div>
    </aside>

    <!-- Top AppBar -->
    <header class="sticky top-0 left-0 right-0 z-40 flex items-center justify-between h-[72px] px-8 ml-0 md:ml-[260px] bg-white/80 backdrop-blur-md border-b border-slate-200 shadow-sm">
        <div class="flex items-center gap-4">
            <button id="mobile-menu-btn" class="md:hidden p-2 rounded-xl text-slate-600 hover:bg-slate-100 transition-colors" type="button">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <h2 class="text-xl font-bold text-slate-800">Verifikasi Pembayaran & Transaksi</h2>
        </div>
        <div class="flex items-center gap-4">
            <div class="hidden sm:flex items-center gap-3 bg-slate-100/80 px-4 py-2 rounded-full border border-slate-200 focus-within:border-[#5341cd] focus-within:bg-white transition-all duration-300">
                <span class="material-symbols-outlined text-slate-400">search</span>
                <form action="verifikasi_pembayaran.php" method="GET" class="flex items-center">
                    <input name="q" value="<?= escape($search) ?>" class="bg-transparent border-none focus:outline-none focus:ring-0 text-sm text-slate-700 w-48 placeholder-slate-400" type="text" placeholder="Cari transaksi..." />
                </form>
            </div>
            <div class="w-10 h-10 rounded-full bg-[#5341cd] flex items-center justify-center text-white font-bold text-sm">
                <?= htmlspecialchars($initials) ?>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="md:ml-[260px] p-6 md:p-8 min-h-[calc(100vh-72px)]">
        <div class="max-w-[1440px] mx-auto space-y-6">

            <!-- Alert Notification -->
            <?php if ($msg): ?>
                <div class="rounded-2xl px-5 py-4 flex items-center gap-3 text-sm font-medium border <?= $msgType === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200' ?>">
                    <span class="material-symbols-outlined text-lg"><?= $msgType === 'error' ? 'error' : 'check_circle' ?></span>
                    <span><?= escape($msg) ?></span>
                </div>
            <?php endif; ?>

            <!-- 4 Stat Cards -->
            <section class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Transaksi</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= $totalTransactions ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-[#5341cd] flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">receipt_long</span>
                    </div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Menunggu Verifikasi</p>
                        <h3 class="text-2xl font-bold text-amber-600 mt-1"><?= $counts['Menunggu'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">pending_actions</span>
                    </div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Disetujui</p>
                        <h3 class="text-2xl font-bold text-emerald-600 mt-1"><?= $counts['Disetujui'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">task_alt</span>
                    </div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Ditolak</p>
                        <h3 class="text-2xl font-bold text-rose-600 mt-1"><?= $counts['Ditolak'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">cancel</span>
                    </div>
                </div>
            </section>

            <!-- Table Container -->
            <section class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100 space-y-6">
                <!-- Action Header Inside Container -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">Daftar Pengajuan Sewa & Pembayaran</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Verifikasi pembayaran dan persetujuan sewa dari calon penyewa</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <?php
                        // Tab yang ditampilkan (hanya subset agar tidak terlalu lebar).
                        // Query tetap menerima semua status via URL validStatuses.
                        $displayTabs = ['Semua', 'Menunggu', 'Disetujui', 'Menunggu Checkout', 'Ditolak', 'Selesai'];
                        ?>
                        <div class="flex items-center bg-slate-100 p-1 rounded-xl gap-1 flex-wrap">
                            <?php foreach ($displayTabs as $st): ?>
                                <a href="verifikasi_pembayaran.php?status=<?= urlencode($st) ?>&q=<?= urlencode($search) ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all <?= $statusFilter === $st ? 'bg-white text-[#5341cd] shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
                                    <?= $st ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Table Content -->
                <div class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-100">
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">PENYEWA</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">KAMAR</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">DURASI</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">TOTAL BIAYA</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">TANGGAL</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">STATUS</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 text-right">VERIFIKASI AKSI</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                            <?php if (count($transactions) === 0): ?>
                                <tr>
                                    <td class="px-6 py-16 text-center" colspan="7">
                                        <div class="flex flex-col items-center justify-center max-w-md mx-auto space-y-3">
                                            <div class="w-16 h-16 bg-purple-50 text-[#5341cd] rounded-full flex items-center justify-center">
                                                <span class="material-symbols-outlined text-3xl">receipt_long</span>
                                            </div>
                                            <h4 class="text-base font-bold text-slate-800">Tidak ada data transaksi.</h4>
                                            <p class="text-xs text-slate-500">Belum ada pengajuan pembayaran sewa yang tercatat sesuai kriteria filter.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $tx): ?>
                                    <tr class="hover:bg-slate-50/70 transition-colors">
                                        <td class="px-6 py-4">
                                            <p class="font-bold text-slate-900"><?= escape($tx['penyewa_nama'] ?: 'Penyewa') ?></p>
                                            <p class="text-xs text-slate-500"><?= escape($tx['penyewa_email'] ?: '-') ?></p>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="font-bold text-[#5341cd]"><?= escape($tx['kamar_nomor'] ?? ($tx['nomor_kamar'] ?? '-')) ?></span>
                                            <?php if (!empty($tx['kamar_tipe'])): ?>
                                                <span class="text-xs text-slate-500 block"><?= escape($tx['kamar_tipe']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 font-medium">
                                            <?= (int)($tx['durasi_bulan'] ?? 1) ?> Bulan
                                        </td>
                                        <td class="px-6 py-4 font-bold text-slate-900">
                                            Rp <?= number_format((float)($tx['total_harga'] ?? ($tx['nominal'] ?? 0)), 0, ',', '.') ?>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-slate-500">
                                            <?= escape($tx['tanggal_pengajuan'] ?? ($tx['tanggal_bayar'] ?? date('Y-m-d', strtotime($tx['created_at'] ?? 'now')))) ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php
                                            $st = $tx['status'] ?? 'Menunggu';
                                            $isCheckoutPending = ($st === 'Menunggu Checkout');
                                            $badgeStyle = 'bg-amber-50 text-amber-700 border-amber-200';
                                            if ($st === 'Disetujui') { $badgeStyle = 'bg-emerald-50 text-emerald-700 border-emerald-200'; }
                                            if ($st === 'Ditolak')   { $badgeStyle = 'bg-rose-50 text-rose-700 border-rose-200'; }
                                            if ($isCheckoutPending)  { $badgeStyle = 'bg-orange-50 text-orange-700 border-orange-200'; }
                                            if ($st === 'Selesai')   { $badgeStyle = 'bg-slate-100 text-slate-600 border-slate-300'; }
                                        ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold border <?= $badgeStyle ?>">
                                            <?php if ($isCheckoutPending): ?>
                                                <span class="material-symbols-outlined text-[13px]" style="font-variation-settings:'FILL' 1">logout</span>
                                                Ajuan Selesai Sewa
                                            <?php else: ?>
                                                <?= escape($st) ?>
                                            <?php endif; ?>
                                        </span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if (in_array($tx['status'] ?? '', ['Menunggu', 'Menunggu Verifikasi', 'Pending'], true)): ?>
                                            <div class="flex items-center justify-end gap-2">
                                                <form method="POST" action="verifikasi_pembayaran.php" class="inline">
                                                    <input type="hidden" name="action" value="update_status"/>
                                                    <input type="hidden" name="request_id" value="<?= (int)$tx['id'] ?>"/>
                                                    <input type="hidden" name="status" value="Disetujui"/>
                                                    <button type="submit" onclick="return confirm('Setujui transaksi pembayaran untuk kamar <?= escape($tx['kamar_nomor'] ?? ($tx['nomor_kamar'] ?? '-')) ?>?');" class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs rounded-lg transition-all shadow-sm">
                                                        <span class="material-symbols-outlined text-sm">check</span>
                                                        Setujui
                                                    </button>
                                                </form>
                                                <form method="POST" action="verifikasi_pembayaran.php" class="inline">
                                                    <input type="hidden" name="action" value="update_status"/>
                                                    <input type="hidden" name="request_id" value="<?= (int)$tx['id'] ?>"/>
                                                    <input type="hidden" name="status" value="Ditolak"/>
                                                    <button type="submit" onclick="return confirm('Tolak transaksi pembayaran ini?');" class="inline-flex items-center gap-1 px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-semibold text-xs rounded-lg transition-all shadow-sm">
                                                        <span class="material-symbols-outlined text-sm">close</span>
                                                        Tolak
                                                    </button>
                                                </form>
                                            </div>
                                        <?php elseif (($tx['status'] ?? '') === 'Menunggu Checkout'): ?>
                                            <!-- Tombol Konfirmasi Checkout -->
                                            <form method="POST" action="verifikasi_pembayaran.php">
                                                <input type="hidden" name="action" value="konfirmasi_checkout"/>
                                                <input type="hidden" name="request_id" value="<?= (int)$tx['id'] ?>"/>
                                                <button type="submit"
                                                        onclick="return confirm('Konfirmasi checkout penyewa ini? Kamar akan dikembalikan ke status Kosong.')"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white font-semibold text-xs rounded-lg transition-all shadow-sm">
                                                    <span class="material-symbols-outlined text-sm">done_all</span>
                                                    Konfirmasi Selesai / Terima Checkout
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">Selesai</span>
                                        <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
