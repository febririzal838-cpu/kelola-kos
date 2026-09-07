<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai pemilik kos untuk mengakses manajemen kamar.') . '&type=error');
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
$msgType = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$whereClauses = ['owner_id = :owner_id'];
$params = [':owner_id' => $owner_id];

if ($search !== '') {
    $whereClauses[] = '(nomor_kamar LIKE :s1 OR tipe_kamar LIKE :s2 OR fasilitas LIKE :s3 OR alamat LIKE :s4)';
    $params[':s1'] = "%{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':s3'] = "%{$search}%";
    $params[':s4'] = "%{$search}%";
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM kamar {$whereSql}");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $statusStmt = $pdo->prepare("SELECT status, COUNT(*) AS jumlah FROM kamar WHERE owner_id = :owner_id GROUP BY status");
    $statusStmt->execute([':owner_id' => $owner_id]);
    $statusCounts = array_column($statusStmt->fetchAll(PDO::FETCH_ASSOC), 'jumlah', 'status');

    $stmt = $pdo->prepare("SELECT * FROM kamar {$whereSql} ORDER BY nomor_kamar ASC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rooms = [];
    $totalCount = 0;
    $statusCounts = [];
    $msg = 'Terjadi kesalahan saat memuat data kamar: ' . $e->getMessage();
    $msgType = 'error';
}

$totalPages = max(1, (int)ceil($totalCount / $limit));

function escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Manajemen Kamar - KelolaKos</title>
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
                        "surface": "#ffffff",
                        "on-surface": "#0f172a",
                        "on-surface-variant": "#64748b",
                        "outline-variant": "#e2e8f0"
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
            <a class="flex items-center gap-3 px-4 py-3 text-[#5341cd] font-bold border-l-4 border-[#5341cd] bg-purple-50/60 rounded-r-xl transition-all duration-200" href="manajemen_kamar.php">
                <span class="material-symbols-outlined">domain</span>
                <span class="text-sm font-semibold">Kamar</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-slate-600 font-medium rounded-xl hover:bg-slate-100 transition-all duration-200" href="verifikasi_pembayaran.php">
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
            <h2 class="text-xl font-bold text-slate-800">Manajemen Kamar</h2>
        </div>
        <div class="flex items-center gap-4">
            <div class="hidden sm:flex items-center gap-3 bg-slate-100/80 px-4 py-2 rounded-full border border-slate-200 focus-within:border-[#5341cd] focus-within:bg-white transition-all duration-300">
                <span class="material-symbols-outlined text-slate-400">search</span>
                <form action="manajemen_kamar.php" method="GET" class="flex items-center">
                    <input name="q" value="<?= escape($search) ?>" class="bg-transparent border-none focus:outline-none focus:ring-0 text-sm text-slate-700 w-48 placeholder-slate-400" type="text" placeholder="Cari kamar..." />
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

            <!-- Flash Message Alert -->
            <?php if ($msg): ?>
                <div class="rounded-2xl px-5 py-4 flex items-center gap-3 text-sm font-medium border <?= $msgType === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200' ?>">
                    <span class="material-symbols-outlined text-lg"><?= $msgType === 'error' ? 'error' : 'check_circle' ?></span>
                    <span><?= escape($msg) ?></span>
                </div>
            <?php endif; ?>

            <!-- Requirement 2: 4 Kartu Statistik -->
            <section class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Card 1: Total Kamar -->
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between transition-all hover:shadow-md">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Kamar</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= $totalCount ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-[#5341cd] flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">meeting_room</span>
                    </div>
                </div>

                <!-- Card 2: Terisi -->
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between transition-all hover:shadow-md">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Terisi</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= $statusCounts['Terisi'] ?? 0 ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">person_check</span>
                    </div>
                </div>

                <!-- Card 3: Kosong -->
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between transition-all hover:shadow-md">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Kosong</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= $statusCounts['Kosong'] ?? 0 ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">check_circle</span>
                    </div>
                </div>

                <!-- Card 4: Menunggak -->
                <div class="rounded-2xl bg-white p-5 shadow-sm border border-slate-100 flex items-center justify-between transition-all hover:shadow-md">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Menunggak</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= $statusCounts['Menunggak'] ?? 0 ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">warning</span>
                    </div>
                </div>
            </section>

            <!-- Requirement 3: Container Tabel Data Kamar Modern -->
            <section class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100 space-y-6">
                <!-- Action Header Inside Container -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-100">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">Daftar Kamar Properti</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Kelola status, tipe, fasilitas, dan harga unit kamar Anda</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <form action="manajemen_kamar.php" method="GET" class="relative">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                            <input name="q" value="<?= escape($search) ?>" class="pl-9 pr-4 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#5341cd]/20 focus:border-[#5341cd] text-slate-700 w-full sm:w-64 transition-all" placeholder="Cari nomor, tipe, fasilitas..." type="text"/>
                        </form>
                        <a href="kamar_form.php?action=add" class="inline-flex items-center gap-2 px-4 py-2 bg-[#5341cd] hover:bg-[#4029ba] text-white font-semibold text-sm rounded-xl shadow-sm transition-all active:scale-95">
                            <span class="material-symbols-outlined text-lg">add</span>
                            Tambah Kamar
                        </a>
                    </div>
                </div>

                <!-- Table Content -->
                <div class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 border-b border-slate-100">
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">NO. KAMAR</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">TIPE</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">ALAMAT / LOKASI</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">FASILITAS</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">HARGA / BULAN</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500">STATUS</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 text-right">AKSI</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                            <?php if (count($rooms) === 0): ?>
                                <tr>
                                    <td class="px-6 py-16 text-center" colspan="7">
                                        <div class="flex flex-col items-center justify-center max-w-md mx-auto space-y-3">
                                            <div class="w-16 h-16 bg-purple-50 text-[#5341cd] rounded-full flex items-center justify-center">
                                                <span class="material-symbols-outlined text-3xl">meeting_room</span>
                                            </div>
                                            <h4 class="text-base font-bold text-slate-800">Belum ada kamar yang terdaftar.</h4>
                                            <p class="text-xs text-slate-500">Tambahkan kamar baru untuk mulai mengelola data properti Anda.</p>
                                            <a href="kamar_form.php?action=add" class="inline-flex items-center gap-2 mt-2 px-4 py-2 bg-[#5341cd] hover:bg-[#4029ba] text-white font-semibold text-xs rounded-xl shadow-sm transition-all">
                                                <span class="material-symbols-outlined text-base">add</span>
                                                Tambah Kamar Baru
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rooms as $room): ?>
                                    <tr class="hover:bg-slate-50/70 transition-colors">
                                        <td class="px-6 py-4 font-bold text-[#5341cd]">
                                            <?= escape($room['nomor_kamar']) ?>
                                        </td>
                                        <td class="px-6 py-4 font-medium">
                                            <?= escape($room['tipe_kamar'] ?? $room['tipe'] ?? '') ?>
                                        </td>
                                        <td class="px-6 py-4 text-xs font-medium text-slate-600 max-w-[200px] truncate" title="<?= escape($room['alamat'] ?? '-') ?>">
                                            <div class="flex items-center gap-1">
                                                <span class="material-symbols-outlined text-slate-400 text-sm">location_on</span>
                                                <span class="truncate"><?= escape(!empty($room['alamat']) ? $room['alamat'] : '-') ?></span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-wrap gap-1.5">
                                                <?php foreach (array_filter(array_map('trim', explode(',', $room['fasilitas']))) as $feature): ?>
                                                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"><?= escape($feature) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 font-semibold text-slate-900">
                                            Rp <?= number_format((float)($room['harga_sewa'] ?? $room['harga'] ?? 0), 0, ',', '.') ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php
                                                $status = $room['status'] ?? 'Kosong';
                                                $badgeStyle = 'bg-blue-50 text-blue-700 border-blue-200';
                                                if ($status === 'Terisi') { $badgeStyle = 'bg-emerald-50 text-emerald-700 border-emerald-200'; }
                                                if ($status === 'Menunggak') { $badgeStyle = 'bg-rose-50 text-rose-700 border-rose-200'; }
                                            ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold border <?= $badgeStyle ?>">
                                                <?= escape($status) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <a href="kamar_detail.php?id=<?= (int)$room['id'] ?>" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors" title="Lihat Detail">
                                                    <span class="material-symbols-outlined text-lg">visibility</span>
                                                </a>
                                                <a href="kamar_form.php?action=edit&id=<?= (int)$room['id'] ?>" class="w-8 h-8 rounded-lg flex items-center justify-center text-[#5341cd] hover:bg-purple-50 transition-colors" title="Edit Kamar">
                                                    <span class="material-symbols-outlined text-lg">edit</span>
                                                </a>
                                                <form action="kamar_action.php" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus kamar <?= escape($room['nomor_kamar']) ?>?');">
                                                    <input type="hidden" name="action" value="delete"/>
                                                    <input type="hidden" name="id" value="<?= (int)$room['id'] ?>"/>
                                                    <button type="submit" class="w-8 h-8 rounded-lg flex items-center justify-center text-rose-500 hover:bg-rose-50 transition-colors" title="Hapus Kamar">
                                                        <span class="material-symbols-outlined text-lg">delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="pt-2 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-500">
                    <span>Menampilkan <?= $totalCount === 0 ? 0 : min($totalCount, $offset + 1) ?> - <?= min($totalCount, $offset + $limit) ?> dari <?= $totalCount ?> kamar</span>
                    <?php if ($totalPages > 1): ?>
                        <div class="flex items-center gap-1.5">
                            <?php if ($page > 1): ?>
                                <a href="manajemen_kamar.php?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>" class="w-8 h-8 rounded-lg border border-slate-200 flex items-center justify-center hover:bg-slate-50 text-slate-600 transition-colors">
                                    <span class="material-symbols-outlined text-base">chevron_left</span>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="manajemen_kamar.php?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="w-8 h-8 rounded-lg flex items-center justify-center font-medium <?= $i === $page ? 'bg-[#5341cd] text-white shadow-sm' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' ?> transition-colors"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="manajemen_kamar.php?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>" class="w-8 h-8 rounded-lg border border-slate-200 flex items-center justify-center hover:bg-slate-50 text-slate-600 transition-colors">
                                    <span class="material-symbols-outlined text-base">chevron_right</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
