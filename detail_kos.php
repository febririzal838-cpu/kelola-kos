<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

$roomId = isset($_GET['id']) ? (int)$_GET['id'] : 1;
$userId = $_SESSION['user_id'] ?? $_SESSION['penyewa_id'] ?? $_SESSION['user'] ?? null;
$isLoggedIn = !empty($userId) || !empty($_SESSION['role']);

$room = [
    'id' => $roomId,
    'nomor_kamar' => 'A-101',
    'tipe_kamar' => 'Premium',
    'harga_sewa' => 2200000,
    'fasilitas' => 'AC, WiFi, Kamar Mandi Dalam, Laundry, Dapur Bersama',
    'status' => 'Kosong'
];

$roomStmt = $pdo->prepare('SELECT * FROM kamar WHERE id = :id LIMIT 1');
$roomStmt->execute([':id' => $roomId]);
$roomFromDb = $roomStmt->fetch(PDO::FETCH_ASSOC);
if ($roomFromDb) {
    $room = $roomFromDb;
}

$roomName = htmlspecialchars($room['nomor_kamar'] ?? 'A-101');
$roomType = htmlspecialchars($room['tipe_kamar'] ?? 'Premium');
$roomPrice = (float)($room['harga_sewa'] ?? 2200000);
$roomFacilities = htmlspecialchars($room['fasilitas'] ?? 'AC, WiFi, Kamar Mandi Dalam');
?>
<!DOCTYPE html>
<html lang="id" class="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Detail Kamar - KelolaKos</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1fbff; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .booking-card { background: white; border: 1px solid rgba(120,117,134,0.16); border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    </style>
</head>
<body class="bg-background text-on-surface">
<nav class="sticky top-0 z-50 h-[72px] bg-surface/80 backdrop-blur-md flex items-center justify-between px-6 border-b border-outline-variant/30">
    <div class="flex items-center gap-3">
        <a href="index.php" class="flex items-center gap-3">
            <span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1;">domain</span>
            <span class="font-bold text-primary tracking-tight text-xl">KelolaKos</span>
        </a>
    </div>
    <div class="flex items-center gap-4">
        <a href="index.php" class="text-sm font-semibold text-on-surface-variant hover:text-primary">Beranda</a>
        <a href="cari_kos.php" class="text-sm font-semibold text-primary">Cari Kost</a>
        <?php if ($isLoggedIn): ?>
            <a href="dashboard_penyewa.php" class="text-sm font-semibold text-on-surface-variant hover:text-primary">Dashboard</a>
            <a href="logout.php" class="text-sm font-semibold text-red-600 hover:text-red-700">Logout</a>
        <?php else: ?>
            <a href="login_daftar.php?redirect=<?php echo urlencode('detail_kos.php?id=' . $roomId); ?>" class="text-sm font-semibold text-primary hover:text-primary-container">Masuk / Daftar</a>
        <?php endif; ?>
    </div>
</nav>

<main class="mx-auto max-w-6xl px-6 py-10">
    <div class="grid gap-8 lg:grid-cols-[1.3fr_0.7fr]">
        <section class="space-y-6">
            <div class="rounded-3xl overflow-hidden border border-outline-variant bg-white shadow-sm">
                <img src="assets/images/detail_kos_main.jpg" alt="Kamar" class="h-80 w-full object-cover" onerror="this.src='https://placehold.co/1200x600/f1fbff/5341cd?text=Kamar+KelolaKos';" />
            </div>

            <div class="booking-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-primary">Kamar <?php echo $roomName; ?></p>
                        <h1 class="mt-2 text-3xl font-bold text-on-surface"><?php echo $roomType; ?> • <?php echo $roomName; ?></h1>
                    </div>
                    <span class="rounded-full bg-secondary/10 px-3 py-1 text-sm font-medium text-secondary"><?php echo ucfirst(htmlspecialchars($room['status'] ?? 'Kosong')); ?></span>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl bg-surface-container-low p-4">
                        <p class="text-sm text-on-surface-variant">Harga / Bulan</p>
                        <p class="mt-2 text-2xl font-bold text-primary">Rp <?php echo number_format($roomPrice, 0, ',', '.'); ?></p>
                    </div>
                    <div class="rounded-2xl bg-surface-container-low p-4">
                        <p class="text-sm text-on-surface-variant">Lokasi</p>
                        <p class="mt-2 text-xl font-bold text-on-surface">Tebet, Jakarta Selatan</p>
                    </div>
                </div>

                <div class="mt-6">
                    <h2 class="text-xl font-bold text-on-surface">Fasilitas</h2>
                    <p class="mt-3 text-body-md text-on-surface-variant"><?php echo $roomFacilities; ?></p>
                </div>
            </div>
        </section>

        <aside>
            <div class="booking-card p-6 space-y-5">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-primary">Pilih Periode</p>
                    <div class="mt-3 flex gap-2 rounded-full bg-surface-container-low p-1 w-fit">
                        <button type="button" class="rounded-full bg-primary px-4 py-2 text-sm font-semibold text-on-primary" id="btn-monthly">Per Bulan</button>
                        <button type="button" class="rounded-full px-4 py-2 text-sm font-semibold text-on-surface-variant" id="btn-yearly">Per Tahun</button>
                    </div>
                </div>

                <div class="rounded-2xl bg-gradient-to-br from-primary/5 to-primary/10 border border-primary/15 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-primary/70">Harga Per Bulan</p>
                    <div class="mt-2 flex items-end gap-2">
                        <p class="text-3xl font-extrabold text-primary leading-none" id="price-value">Rp <?php echo number_format($roomPrice, 0, ',', '.'); ?></p>
                        <p class="text-sm text-on-surface-variant pb-1">/bulan</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-on-surface mb-2" for="move-in-date">Tanggal Mulai Sewa</label>
                    <input type="date" id="move-in-date" class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-3 text-body-md text-on-surface focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" />
                </div>

                <div>
                    <label class="block text-sm font-semibold text-on-surface mb-2" for="duration-select">Durasi Sewa</label>
                    <select id="duration-select" class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-3 text-body-md text-on-surface focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="1">1 Bulan</option>
                        <option value="3">3 Bulan</option>
                        <option value="6">6 Bulan</option>
                        <option value="12">12 Bulan</option>
                    </select>
                </div>

                <div class="flex items-center justify-between rounded-xl bg-surface-container-low px-4 py-3 border border-outline-variant/30">
                    <span class="text-sm text-on-surface-variant">Estimasi Total</span>
                    <span class="font-bold text-on-surface" id="total-price">Rp <?php echo number_format($roomPrice, 0, ',', '.'); ?></span>
                </div>

                <?php if (strtolower(trim($room['status'] ?? '')) === 'terisi'): ?>
                    <button type="button" disabled class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-slate-400 px-5 py-3 text-sm font-bold text-white shadow-md cursor-not-allowed">
                        <span class="material-symbols-outlined">lock</span>
                        Sudah Terisi / Di Pakai
                    </button>
                <?php elseif ($isLoggedIn): ?>
                    <form id="booking-form" method="POST" action="proses_sewa.php">
                        <input type="hidden" name="kamar_id" value="<?php echo (int)$room['id']; ?>" />
                        <input type="hidden" name="tanggal_mulai" id="booking-start-date" value="" />
                        <input type="hidden" name="durasi_bulan" id="booking-duration" value="1" />
                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-bold text-on-primary shadow-lg shadow-primary/20 hover:bg-primary-container transition-all">
                            <span class="material-symbols-outlined">home_work</span>
                            Ajukan Sewa / Booking
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login_daftar.php?redirect=<?php echo urlencode('detail_kos.php?id=' . $roomId); ?>" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-bold text-on-primary shadow-lg shadow-primary/20 hover:bg-primary-container transition-all">
                        <span class="material-symbols-outlined">lock</span>
                        Sewa Sekarang (Login Terlebih Dahulu)
                    </a>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</main>

<script>
    const roomPrice = <?php echo (float)$roomPrice; ?>;
    const monthlyBtn = document.getElementById('btn-monthly');
    const yearlyBtn = document.getElementById('btn-yearly');
    const durationSelect = document.getElementById('duration-select');
    const moveInDate = document.getElementById('move-in-date');
    const totalPriceEl = document.getElementById('total-price');
    const priceValueEl = document.getElementById('price-value');
    const bookingForm = document.getElementById('booking-form');
    const bookingStartDate = document.getElementById('booking-start-date');
    const bookingDuration = document.getElementById('booking-duration');

    function formatRupiah(value) {
        return 'Rp ' + Number(value).toLocaleString('id-ID');
    }

    function updateTotal() {
        const duration = Number(durationSelect.value || 1);
        const total = roomPrice * duration;
        totalPriceEl.textContent = formatRupiah(total);
    }

    durationSelect.addEventListener('change', updateTotal);

    monthlyBtn.addEventListener('click', () => {
        monthlyBtn.classList.add('bg-primary', 'text-on-primary');
        monthlyBtn.classList.remove('text-on-surface-variant');
        yearlyBtn.classList.remove('bg-primary', 'text-on-primary');
        yearlyBtn.classList.add('text-on-surface-variant');
        priceValueEl.textContent = formatRupiah(roomPrice);
        updateTotal();
    });

    yearlyBtn.addEventListener('click', () => {
        yearlyBtn.classList.add('bg-primary', 'text-on-primary');
        yearlyBtn.classList.remove('text-on-surface-variant');
        monthlyBtn.classList.remove('bg-primary', 'text-on-primary');
        monthlyBtn.classList.add('text-on-surface-variant');
        priceValueEl.textContent = formatRupiah(roomPrice * 12);
        durationSelect.value = '12';
        updateTotal();
    });

    if (bookingForm) {
        bookingForm.addEventListener('submit', function (e) {
            if (!moveInDate.value) {
                e.preventDefault();
                alert('Pilih tanggal mulai sewa terlebih dahulu');
                moveInDate.focus();
                return;
            }

            bookingStartDate.value = moveInDate.value;
            bookingDuration.value = durationSelect.value;
        });
    }

    const today = new Date();
    const nextDay = new Date(today);
    nextDay.setDate(today.getDate() + 1);
    const formatDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    moveInDate.min = formatDate(today);
    moveInDate.value = formatDate(nextDay);
    updateTotal();
</script>
</body>
</html>
