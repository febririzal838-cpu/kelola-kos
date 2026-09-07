<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'pemilik') {
    header('Location: login_daftar.php?msg=' . urlencode('Silakan login sebagai pemilik kos untuk mengakses detail kamar.') . '&type=error');
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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: manajemen_kamar.php?msg=' . urlencode('ID kamar tidak valid.') . '&type=error');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM kamar WHERE id = :id AND owner_id = :owner_id');
    $stmt->execute([':id' => $id, ':owner_id' => $owner_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        header('Location: manajemen_kamar.php?msg=' . urlencode('Kamar tidak ditemukan.') . '&type=error');
        exit;
    }
} catch (PDOException $e) {
    header('Location: manajemen_kamar.php?msg=' . urlencode('Gagal memuat detail kamar: ' . $e->getMessage()) . '&type=error');
    exit;
}

function escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Detail Kamar <?= escape($room['nomor_kamar']) ?> - KelolaKos</title>
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
            <a href="manajemen_kamar.php" class="p-2 rounded-xl text-slate-600 hover:bg-slate-100 transition-colors">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <h2 class="text-xl font-bold text-slate-800">Detail Kamar <?= escape($room['nomor_kamar']) ?></h2>
        </div>
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-full bg-[#5341cd] flex items-center justify-center text-white font-bold text-sm">
                <?= htmlspecialchars($initials) ?>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="md:ml-[260px] p-6 md:p-8 min-h-[calc(100vh-72px)]">
        <div class="max-w-4xl mx-auto space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h3 class="text-2xl font-bold text-slate-800">Informasi Unit Kamar</h3>
                    <p class="text-xs text-slate-500 mt-1">Status ketersediaan, tipe, harga, dan fasilitas yang tersedia.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="manajemen_kamar.php" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition-all">
                        <span class="material-symbols-outlined text-base">arrow_back</span>
                        Kembali
                    </a>
                    <a href="kamar_form.php?action=edit&id=<?= (int)$room['id'] ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-[#5341cd] hover:bg-[#4029ba] text-white font-semibold text-xs rounded-xl shadow-sm transition-all">
                        <span class="material-symbols-outlined text-base">edit</span>
                        Edit Kamar
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-8 shadow-sm border border-slate-100 space-y-8">
                <div class="grid gap-6 md:grid-cols-2">
                    <div class="space-y-4">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Nomor Kamar</span>
                            <p class="text-lg font-bold text-[#5341cd] mt-0.5"><?= escape($room['nomor_kamar']) ?></p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Tipe Kamar</span>
                            <p class="text-base font-semibold text-slate-800 mt-0.5"><?= escape($room['tipe_kamar'] ?? $room['tipe'] ?? '') ?></p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Harga / Bulan</span>
                            <p class="text-lg font-bold text-slate-900 mt-0.5">Rp <?= number_format((float)($room['harga_sewa'] ?? $room['harga'] ?? 0), 0, ',', '.') ?></p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Alamat Properti / Lokasi</span>
                            <p class="text-sm font-medium text-slate-700 mt-0.5"><?= escape(!empty($room['alamat']) ? $room['alamat'] : 'Belum diatur') ?></p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Status Ketersediaan</span>
                            <div class="mt-1">
                                <?php
                                    $status = $room['status'] ?? 'Kosong';
                                    $badgeStyle = 'bg-blue-50 text-blue-700 border-blue-200';
                                    if ($status === 'Terisi') { $badgeStyle = 'bg-emerald-50 text-emerald-700 border-emerald-200'; }
                                    if ($status === 'Menunggak') { $badgeStyle = 'bg-rose-50 text-rose-700 border-rose-200'; }
                                ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold border <?= $badgeStyle ?>">
                                    <?= escape($status) ?>
                                </span>
                            </div>
                        </div>
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Fasilitas</span>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <?php foreach (array_filter(array_map('trim', explode(',', $room['fasilitas']))) as $feature): ?>
                                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700"><?= escape($feature) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-5">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Informasi Tambahan</h4>
                    <p class="text-xs text-slate-600 leading-relaxed">
                        Data kamar ini dapat diperbarui sewaktu-waktu. Untuk mengubah tarif sewa, menambah/mengurangi daftar fasilitas, atau memperbarui status penghuni, silakan klik tombol edit di atas.
                    </p>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
