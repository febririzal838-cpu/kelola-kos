<?php
session_start();
require_once __DIR__ . '/data_kamar.php';

$isLoggedIn = !empty($_SESSION['user']) || !empty($_SESSION['user_id']) || !empty($_SESSION['pemilik_id']) || !empty($_SESSION['penyewa_id']) || !empty($_SESSION['owner_id']);
$role = $_SESSION['role'] ?? (!empty($_SESSION['pemilik_id']) || !empty($_SESSION['owner_id']) ? 'pemilik' : 'penyewa');
$fullName = $_SESSION['full_name'] ?? $_SESSION['nama'] ?? (is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['nama'] ?? $_SESSION['user']['full_name'] ?? 'User') : 'User');
$initial = strtoupper(substr(strtok((string)$fullName, ' '), 0, 1) ?: 'U');
$dashboardUrl = ($role === 'pemilik') ? 'dashboard_pemilik_kos.php' : 'dashboard_penyewa.php';
$roleLabel = ($role === 'pemilik') ? 'Pemilik Kos' : 'Penyewa';
$rooms = getKamarList();
?>
<!DOCTYPE html>

<html class="light" lang="id"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>KelolaKos - Temukan Kost Impianmu</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
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
                    "headline-md": ["Inter"],
                    "headline-sm": ["Inter"],
                    "body-lg": ["Inter"],
                    "body-md": ["Inter"],
                    "label-md": ["Inter"],
                    "label-sm": ["Inter"]
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
          }
        }
      }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .hero-pattern {
            background-color: #f1fbff;
            background-image: radial-gradient(#5341cd 0.5px, transparent 0.5px), radial-gradient(#5341cd 0.5px, #f1fbff 0.5px);
            background-size: 20px 20px;
            background-position: 0 0, 10px 10px;
            opacity: 0.1;
        }
        .card-shadow {
            box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.05);
        }
        .card-shadow:hover {
            box-shadow: 0px 8px 24px rgba(0, 0, 0, 0.08);
        }
        .search-shell {
            box-shadow: 0 20px 50px rgba(83, 65, 205, 0.08);
        }
        .pill {
            border: 1px solid rgba(83, 65, 205, 0.16);
            background: rgba(255, 255, 255, 0.8);
            color: #474554;
        }
        .property-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .property-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px -4px rgba(83, 65, 205, 0.12);
        }
        .hero-visual {
    background: linear-gradient(135deg, rgba(83, 65, 205, 0.12), rgba(0, 107, 85, 0.08));
}

/* New styling for hero background */
.hero-bg {
    background: linear-gradient(rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.75)), url('assets/images/kamar.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
}

/* Glassmorphism card style */
.glass-card {
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
    color: #e2e8f0;
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-12px); }
}
.animate-float {
    animation: float 4s ease-in-out infinite;
}
    </style>
<style>
    body {
      min-height: max(884px, 100dvh);
      scroll-behavior: smooth;
    }
  </style>
  </head>
<body class="bg-background font-body-md text-on-surface selection:bg-primary-container selection:text-on-primary-container">
<nav class="sticky top-0 z-50 h-[72px] bg-surface/80 backdrop-blur-md flex items-center justify-between px-container-padding border-b border-outline-variant/30">
<div class="flex items-center gap-3">
<a href="index.php" class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1;">domain</span><span class="font-headline-md text-headline-md font-bold text-primary tracking-tight">KelolaKos</span></a>
</div>
<div class="hidden md:flex items-center gap-8">
<a class="font-label-md text-label-md text-primary font-bold" href="index.php">Beranda</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="cari_kos.php">Cari Kost</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="#fasilitas">Fasilitas</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="#tentang-kami">Tentang Kami</a>
</div>
<div class="flex items-center gap-3">
<?php if ($isLoggedIn): ?>
  <div class="relative flex items-center gap-3 bg-primary/5 border border-primary/20 pl-4 pr-1.5 py-1.5 rounded-full">
    <div class="flex flex-col text-right">
      <span class="text-[11px] font-bold text-primary leading-none"><?php echo htmlspecialchars($roleLabel); ?></span>
      <span class="text-xs font-semibold text-on-surface leading-normal mt-0.5"><?php echo htmlspecialchars($fullName); ?></span>
    </div>
    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-on-primary font-bold shadow-md shadow-primary/25"><?php echo htmlspecialchars($initial); ?></div>
  </div>
  <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full font-label-md text-label-md bg-primary text-on-primary hover:bg-primary-container shadow-md shadow-primary/20 transition-all font-semibold">
    <span class="material-symbols-outlined text-[18px]">dashboard</span> Dashboard
  </a>
  <a href="logout.php" title="Keluar / Logout" class="flex h-9 w-9 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-all shadow-sm">
    <span class="material-symbols-outlined text-[18px]">logout</span>
  </a>
<?php else: ?>
  <!-- Tombol Profil Kosong / Guest Profil (Trigger Pop-up Modal Login) -->
  <button type="button" onclick="openLoginModal()" title="Masuk ke Akun" class="flex items-center gap-2 px-4 py-2 rounded-full border border-primary/30 bg-primary/5 text-primary hover:bg-primary hover:text-on-primary transition-all duration-300 shadow-sm font-semibold text-sm group">
    <span class="material-symbols-outlined text-2xl group-hover:scale-110 transition-transform">account_circle</span>
    <span class="font-label-md text-label-md">Masuk</span>
  </button>
<?php endif; ?>
</div>
</nav>
<main class="w-full">
<section class="relative overflow-hidden hero-bg px-container-padding py-12 md:py-16">
  <div class="absolute inset-0 hero-pattern -z-10"></div>
  <div class="mx-auto grid max-w-[1440px] items-center gap-10 lg:grid-cols-[1.1fr_0.9fr]">
    <div class="space-y-6">
      <div class="inline-flex items-center gap-2 rounded-full bg-secondary-container px-4 py-1.5 text-label-md font-label-md text-on-secondary-container">
        <span class="material-symbols-outlined text-[18px]">verified</span>
        <span>1.200+ kamar terverifikasi</span>
      </div>
      <h1 class="font-display-lg text-display-lg leading-tight text-white">
        Cari kost nyaman, <span class="text-white">praktis</span>, dan dekat kebutuhanmu.
      </h1>
      <p class="max-w-xl text-body-lg text-white">
        Temukan hunian eksklusif dengan fasilitas lengkap, lokasi strategis, dan proses sewa yang mudah.
      </p>
      <div class="search-shell rounded-2xl border border-outline-variant/40 glass-card p-4 transition-all duration-300">
        <div class="grid gap-3 md:grid-cols-[1.4fr_1fr_auto]">
          <div class="flex items-center gap-3 rounded-xl border border-outline-variant/60 bg-surface-container-low px-4 py-2.5 focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20 transition-all duration-300">
            <span class="material-symbols-outlined text-primary text-[20px]">location_on</span>
            <div class="flex-1">
              <label class="block text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 leading-none mb-1">Lokasi / Area</label>
              <input id="search-location" class="w-full border-none bg-transparent p-0 text-body-md font-semibold text-on-surface placeholder:text-on-surface-variant/40 focus:ring-0" placeholder="Masukkan lokasi (contoh: Tebet)" type="text" />
            </div>
          </div>
          <div class="flex items-center gap-3 rounded-xl border border-outline-variant/60 bg-surface-container-low px-4 py-2.5 focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20 transition-all duration-300">
            <span class="material-symbols-outlined text-primary text-[20px]">home</span>
            <div class="flex-1">
              <label class="block text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 leading-none mb-1">Tipe Kost</label>
              <select id="search-gender" class="w-full border-none bg-transparent p-0 text-body-md font-semibold text-on-surface focus:ring-0">
                <option value="">Semua Tipe</option>
                <option value="Putra">Putra</option>
                <option value="Putri">Putri</option>
                <option value="Campur">Campur</option>
              </select>
            </div>
          </div>
          <a href="cari_kos.php" class="inline-flex items-center justify-center rounded-xl bg-primary px-8 py-3 text-label-md font-label-md font-bold text-on-primary shadow-lg shadow-primary/20 hover:bg-primary-container transition-all hover:scale-[1.02] active:scale-[0.98]">
            <span class="material-symbols-outlined mr-2">search</span>Cari
          </a>
        </div>
      </div>
      <div class="space-y-3 pt-2">
        <div class="flex items-center gap-2 text-on-surface-variant/80">
          <span class="material-symbols-outlined text-[18px] text-primary">widgets</span>
          <p class="text-[12px] font-bold uppercase tracking-wider text-white">Kategori Cepat</p>
        </div>
        <div class="space-y-2">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold text-on-surface-variant/60 mr-1">Fasilitas Utama:</span>
            <button onclick="toggleAmenityFilter('WiFi', this)" class="quick-filter-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">wifi</span>WiFi</button>
            <button onclick="toggleAmenityFilter('AC', this)" class="quick-filter-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">ac_unit</span>AC</button>
            <button onclick="toggleAmenityFilter('KM Dalam', this)" class="quick-filter-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">shower</span>KM Dalam</button>
            <button onclick="toggleAmenityFilter('Balkon', this)" class="quick-filter-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">balcony</span>Balkon</button>
            <button onclick="toggleAmenityFilter('Smart TV', this)" class="quick-filter-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">tv</span>Smart TV</button>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold text-on-surface-variant/60 mr-1">Lokasi Populer:</span>
            <button onclick="selectLocationFilter('Tebet', this)" class="quick-location-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">location_on</span>Tebet</button>
            <button onclick="selectLocationFilter('Setiabudi', this)" class="quick-location-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">location_on</span>Setiabudi</button>
            <button onclick="selectLocationFilter('Kuningan', this)" class="quick-location-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">location_on</span>Kuningan</button>
            <button onclick="selectLocationFilter('Kemang', this)" class="quick-location-btn flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest px-3 py-1 text-xs font-semibold text-on-surface-variant hover:border-primary hover:text-primary transition-all duration-200"><span class="material-symbols-outlined text-[15px]">location_on</span>Kemang</button>
          </div>
        </div>
      </div>
    </div>
    <div class="relative flex items-center justify-center">
      <!-- Glow effect behind frame -->
      <div class="absolute -inset-1.5 rounded-3xl bg-gradient-to-r from-primary/50 to-secondary/30 blur-2xl opacity-60"></div>
      
      <!-- Single Glassmorphism Image Card -->
      <div class="glass-card relative overflow-hidden rounded-3xl border border-white/30 p-3 shadow-2xl backdrop-blur-xl w-full transition-all duration-500 hover:shadow-[0_25px_60px_rgba(83,65,205,0.35)]">
        <div class="overflow-hidden rounded-2xl w-full h-[420px] lg:h-[480px]">
          <img class="h-full w-full object-cover transition-transform duration-700 hover:scale-105" src="assets/images/tampilan kamar kost 2.webp" alt="Kamar Kos Eksklusif" />
        </div>
      </div>
    </div>
  </div>
</section>
<section id="fasilitas" class="relative overflow-hidden bg-surface-container-low border-y border-outline-variant/30 px-container-padding py-20 text-on-surface">
  <div class="relative z-10 mx-auto max-w-[1440px]">
    <div class="text-center max-w-2xl mx-auto mb-16">
      <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-primary text-xs font-bold uppercase tracking-wider mb-4">
        <span class="material-symbols-outlined text-[16px]">stars</span> Fasilitas Modern
      </span>
      <h2 class="text-headline-lg font-extrabold text-on-surface tracking-tight">Fasilitas Terbaik Untuk Kenyamananmu</h2>
      <p class="mt-3 text-body-lg text-on-surface-variant">Nikmati standar fasilitas premium di setiap hunian yang dirancang untuk mendukung produktivitas dan gaya hidup modern.</p>
    </div>

    <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
      <!-- Card 1: High-Speed WiFi -->
      <div class="bg-white border border-slate-200/80 group rounded-2xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-sm group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">wifi</span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-2 group-hover:text-primary transition-colors">WiFi Super Cepat</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">Koneksi internet fiber optic hingga 100 Mbps 24 jam non-stop untuk kebutuhan kerja, streaming, dan gaming tanpa hambatan.</p>
      </div>

      <!-- Card 2: AC -->
      <div class="bg-white border border-slate-200/80 group rounded-2xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-sm group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">ac_unit</span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-2 group-hover:text-primary transition-colors">AC & Pendingin Ruangan</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">Setiap kamar dilengkapi pendingin udara inverter yang hemat energi, dingin maksimal, dan rutin dirawat secara berkala.</p>
      </div>

      <!-- Card 3: KM Dalam -->
      <div class="bg-white border border-slate-200/80 group rounded-2xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-sm group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">shower</span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-2 group-hover:text-primary transition-colors">Kamar Mandi Dalam</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">Privasi penuh dengan kamar mandi dalam yang bersih, shower modern, serta opsi pemanas air (water heater).</p>
      </div>

      <!-- Card 4: Security 24/7 -->
      <div class="bg-white border border-slate-200/80 group rounded-2xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-sm group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">verified_user</span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-2 group-hover:text-primary transition-colors">Keamanan 24/7 & CCTV</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">Sistem akses kartu pintar (access card), pengawasan kamera CCTV 24 jam, dan petugas keamanan yang selalu bersiaga.</p>
      </div>

      <!-- Card 5: Kitchen / Dapur -->
      <div class="bg-white border border-slate-200/80 group rounded-2xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-sm group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">kitchen</span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-2 group-hover:text-primary transition-colors">Dapur & Dining Lounge</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">Fasilitas dapur bersama modern lengkap dengan kompor, kulkas, microwave, dispenser air minum, dan meja makan bersih.</p>
      </div>

      <!-- Card 6: Parking Area -->
      <div class="bg-white border border-slate-200/80 group rounded-2xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-sm group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300">
          <span class="material-symbols-outlined text-3xl">local_parking</span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-2 group-hover:text-primary transition-colors">Area Parkir Luas</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">Area parkir kendaraan roda dua dan roda empat yang aman, teratur, terlindung dari panas dan hujan.</p>
      </div>
    </div>
  </section>
<section id="tentang-kami" class="relative overflow-hidden px-container-padding py-20 bg-surface">
  <div class="mx-auto max-w-[1440px]">
    <div class="grid gap-12 lg:grid-cols-2 items-center">
      <!-- Sisi Kiri: Poin Keunggulan -->
      <div class="space-y-6">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-primary/10 text-primary text-xs font-bold uppercase tracking-wider">
          <span class="material-symbols-outlined text-[16px]">info</span>
          <span>Tentang Kami</span>
        </div>
        <h2 class="text-headline-lg font-extrabold text-on-background tracking-tight leading-snug">
          Solusi Terpercaya Pencarian & Pengelolaan Kost Modern
        </h2>
        <p class="text-body-lg text-on-surface-variant leading-relaxed">
          KelolaKos menghadirkan pengalaman mencari dan mengelola kost yang aman, transparan, dan efisien. Kami menjembatani kebutuhan penyewa dengan kenyamanan hunian ideal serta membantu pemilik mengoptimalkan manajemen bisnis kosnya.
        </p>

        <!-- List Poin Keunggulan -->
        <div class="grid gap-4 pt-2 sm:grid-cols-2">
          <div class="flex items-start gap-3.5 rounded-xl border border-outline-variant/30 bg-surface-container-low p-4 transition-all hover:border-primary/40 hover:shadow-md">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-primary text-on-primary">
              <span class="material-symbols-outlined text-[22px]">verified_user</span>
            </div>
            <div>
              <h3 class="font-bold text-on-background text-sm">Terverifikasi 100%</h3>
              <p class="text-xs text-on-surface-variant mt-0.5">Seluruh properti terjamin keaslian lokasi & fasilitasnya.</p>
            </div>
          </div>

          <div class="flex items-start gap-3.5 rounded-xl border border-outline-variant/30 bg-surface-container-low p-4 transition-all hover:border-primary/40 hover:shadow-md">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-primary text-on-primary">
              <span class="material-symbols-outlined text-[22px]">credit_card</span>
            </div>
            <div>
              <h3 class="font-bold text-on-background text-sm">Sewa & Bayar Praktis</h3>
              <p class="text-xs text-on-surface-variant mt-0.5">Sistem pembayaran digital otomatis dan terlacak rapi.</p>
            </div>
          </div>

          <div class="flex items-start gap-3.5 rounded-xl border border-outline-variant/30 bg-surface-container-low p-4 transition-all hover:border-primary/40 hover:shadow-md">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-primary text-on-primary">
              <span class="material-symbols-outlined text-[22px]">support_agent</span>
            </div>
            <div>
              <h3 class="font-bold text-on-background text-sm">Laporan & Bantuan 24/7</h3>
              <p class="text-xs text-on-surface-variant mt-0.5">Pengaduan kendala ditangani langsung oleh tim profesional.</p>
            </div>
          </div>

          <div class="flex items-start gap-3.5 rounded-xl border border-outline-variant/30 bg-surface-container-low p-4 transition-all hover:border-primary/40 hover:shadow-md">
            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-primary text-on-primary">
              <span class="material-symbols-outlined text-[22px]">home_work</span>
            </div>
            <div>
              <h3 class="font-bold text-on-background text-sm">Dashboard Pemilik</h3>
              <p class="text-xs text-on-surface-variant mt-0.5">Kemudahan memantau kamar, kamar terisi, & laporan keuangan.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Sisi Kanan: Statistik Ber-efek Melayang (animate-float) -->
      <div class="relative flex justify-center items-center py-6">
        <!-- Floating Backdrop Glow -->
        <div class="absolute h-80 w-80 rounded-full bg-primary/20 blur-[100px] pointer-events-none"></div>

        <div class="animate-float relative w-full max-w-md space-y-5">
          <!-- Main Stat Card -->
          <div class="glass-card relative overflow-hidden rounded-3xl border border-primary/30 bg-gradient-to-br from-slate-900/90 to-primary/80 p-8 text-white shadow-2xl backdrop-blur-xl">
            <div class="flex items-center justify-between mb-6">
              <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/20 text-white backdrop-blur-md">
                <span class="material-symbols-outlined text-3xl">domain</span>
              </div>
              <span class="rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300 border border-emerald-500/30 flex items-center gap-1">
                <span class="material-symbols-outlined text-[14px]">trending_up</span> Active Growth
              </span>
            </div>
            
            <h3 class="text-3xl font-extrabold text-white">KelolaKos In Numbers</h3>
            <p class="text-xs text-slate-200 mt-1">Kepercayaan pengguna di seluruh kota besar di Indonesia.</p>

            <div class="mt-8 grid grid-cols-3 gap-4 text-center border-t border-white/20 pt-6">
              <!-- Stat 1 -->
              <div class="space-y-1">
                <div class="flex justify-center mb-1 text-primary-fixed-dim">
                  <span class="material-symbols-outlined text-2xl">bed</span>
                </div>
                <p class="text-2xl font-black text-white tracking-tight">1,200+</p>
                <p class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider">Kamar</p>
              </div>

              <!-- Stat 2 -->
              <div class="space-y-1 border-x border-white/15 px-2">
                <div class="flex justify-center mb-1 text-secondary-fixed">
                  <span class="material-symbols-outlined text-2xl">real_estate_agent</span>
                </div>
                <p class="text-2xl font-black text-white tracking-tight">500+</p>
                <p class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider">Pemilik</p>
              </div>

              <!-- Stat 3 -->
              <div class="space-y-1">
                <div class="flex justify-center mb-1 text-amber-300">
                  <span class="material-symbols-outlined text-2xl">star</span>
                </div>
                <p class="text-2xl font-black text-white tracking-tight">4.9/5</p>
                <p class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider">Rating</p>
              </div>
            </div>
          </div>

          <!-- Secondary Decorative Badge floating -->
          <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4 text-slate-800 shadow-xl">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                <span class="material-symbols-outlined text-xl">shield</span>
              </div>
              <div>
                <p class="text-xs font-bold text-slate-800">100% Kepuasan Penyewa</p>
                <p class="text-[11px] text-slate-500">Dukungan penuh & jaminan layanan</p>
              </div>
            </div>
            <span class="material-symbols-outlined text-emerald-600">check_circle</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="cara-kerja" class="relative overflow-hidden bg-surface-container-low border-t border-b border-outline-variant/30 px-container-padding py-24 text-on-surface">
  <div class="relative z-10 mx-auto max-w-[1440px]">
    <!-- Section Header -->
    <div class="text-center max-w-2xl mx-auto mb-16 space-y-4">
      <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-primary text-xs font-bold uppercase tracking-wider">
        <span class="material-symbols-outlined text-[16px]">alt_route</span> Cara Kerja
      </span>
      <h2 class="text-headline-lg font-extrabold text-on-surface tracking-tight leading-tight">
        Langkah Praktis Menggunakan KelolaKos
      </h2>
      <p class="text-body-lg text-on-surface-variant">
        Mulai penyewaan atau pengelolaan kos Anda dalam 3 langkah mudah dengan alur transparan dan aman.
      </p>
    </div>

    <!-- Steps Grid -->
    <div class="grid gap-8 md:grid-cols-3 relative">
      <!-- Step 1 -->
      <div class="bg-white border border-slate-200/80 relative z-10 group rounded-3xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="flex items-center justify-between mb-8">
          <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary text-on-primary shadow-md shadow-primary/20 group-hover:scale-110 transition-transform duration-300">
            <span class="material-symbols-outlined text-3xl">person_add</span>
          </div>
          <span class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-base font-black text-primary shadow-inner">
            01
          </span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-3 group-hover:text-primary transition-colors">1. Buat Akun & Login</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">
          Daftar secara gratis dalam hitungan detik sebagai Penyewa atau Pemilik Kos untuk mendapatkan akses penuh ke platform dashboard.
        </p>
      </div>

      <!-- Step 2 -->
      <div class="bg-white border border-slate-200/80 relative z-10 group rounded-3xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="flex items-center justify-between mb-8">
          <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary text-on-primary shadow-md shadow-primary/20 group-hover:scale-110 transition-transform duration-300">
            <span class="material-symbols-outlined text-3xl">add_home_work</span>
          </div>
          <span class="flex h-10 w-10 items-center justify-center rounded-full border border-secondary/20 bg-secondary/10 text-base font-black text-secondary shadow-inner">
            02
          </span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-3 group-hover:text-primary transition-colors">2. Lengkapi Data Properti</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">
          Pemilik dapat mengunggah detail kamar, harga, lokasi & foto properti, sementara penyewa dapat memfilter & memilih kamar impian.
        </p>
      </div>

      <!-- Step 3 -->
      <div class="bg-white border border-slate-200/80 relative z-10 group rounded-3xl p-8 shadow-sm transition-all duration-300 hover:-translate-y-2 hover:shadow-xl hover:border-primary/40">
        <div class="flex items-center justify-between mb-8">
          <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary text-on-primary shadow-md shadow-primary/20 group-hover:scale-110 transition-transform duration-300">
            <span class="material-symbols-outlined text-3xl">analytics</span>
          </div>
          <span class="flex h-10 w-10 items-center justify-center rounded-full border border-amber-300/40 bg-amber-50 text-base font-black text-amber-600 shadow-inner">
            03
          </span>
        </div>
        <h3 class="text-xl font-bold text-on-surface mb-3 group-hover:text-primary transition-colors">3. Kelola Penyewa & Keuangan</h3>
        <p class="text-sm text-on-surface-variant leading-relaxed">
          Lakukan transaksi sewa aman, pantau pembayaran bulanan otomatis, kirim pengaduan kendala, dan pantau laporan keuangan real-time.
        </p>
      </div>
    </div>
  </div>
</section>
<section class="py-16 px-container-padding bg-on-background relative overflow-hidden">
<div class="absolute top-0 right-0 w-64 h-64 bg-primary opacity-20 blur-3xl -mr-32 -mt-32"></div>
<div class="absolute bottom-0 left-0 w-64 h-64 bg-secondary opacity-20 blur-3xl -ml-32 -mb-32"></div>
<div class="max-w-4xl mx-auto text-center relative z-10">
<h2 class="font-display-lg text-display-lg text-background mb-4">Punya Properti Kost Kosong?</h2>
<p class="font-body-lg text-body-lg text-surface-variant/80 mb-8">Bergabunglah dengan ribuan pemilik kost lainnya. Kelola properti, penyewa, dan transaksi keuangan Anda dalam satu dashboard profesional yang mudah digunakan.</p>
<div class="flex flex-col sm:flex-row justify-center gap-4">
<a class="inline-flex items-center justify-center px-8 py-3.5 bg-primary text-on-primary rounded-xl font-label-md text-label-md font-bold shadow-xl shadow-primary/30 hover:scale-105 transition-transform" href="login_daftar.php?role=admin&redirect=kamar_form.php">Daftarkan Kost Saya</a>
<a class="inline-flex items-center justify-center px-8 py-3.5 bg-surface-container-highest/10 text-background border border-surface-variant/30 rounded-xl font-label-md text-label-md font-bold hover:bg-surface-container-highest/20 transition-all" href="#cara-kerja">Pelajari Cara Kerja</a>
</div>
</div>
</section>
<footer id="footer" class="border-t border-outline-variant/30 bg-surface px-container-padding py-16">
  <div class="mx-auto grid max-w-[1440px] gap-10 md:grid-cols-2 lg:grid-cols-4">
    <div class="space-y-6">
      <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1;">domain</span>
        <span class="font-headline-md text-headline-md font-bold text-primary tracking-tight">KelolaKos</span>
      </div>
      <p class="text-body-md text-on-surface-variant leading-relaxed">Platform pencarian dan pengelolaan kost yang modern, cepat, dan terpercaya untuk penyewa dan pemilik.</p>
      <div class="flex gap-4">
        <a class="flex h-10 w-10 items-center justify-center rounded-full bg-surface-container text-on-surface-variant transition-all hover:bg-primary hover:text-on-primary" href="https://www.google.com" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined">public</span></a>
        <a class="flex h-10 w-10 items-center justify-center rounded-full bg-surface-container text-on-surface-variant transition-all hover:bg-primary hover:text-on-primary" href="mailto:info@kelolakos.com"><span class="material-symbols-outlined">alternate_email</span></a>
      </div>
    </div>
    <div>
      <h4 class="mb-6 text-label-md font-label-md font-bold text-on-surface">Navigasi</h4>
      <ul class="space-y-4 text-body-md text-on-surface-variant">
        <li><a class="transition-colors hover:text-primary" href="cari_kos.php">Cari Kost</a></li>
        <li><a class="transition-colors hover:text-primary" href="dashboard_penyewa.php">Dashboard Penyewa</a></li>
        <li><a class="transition-colors hover:text-primary" href="dashboard_pemilik_kos.php">Dashboard Pemilik</a></li>
        <li><a class="transition-colors hover:text-primary" href="login_daftar.php">Login &amp; Daftar</a></li>
      </ul>
    </div>
    <div>
      <h4 class="mb-6 text-label-md font-label-md font-bold text-on-surface">Layanan</h4>
      <ul class="space-y-4 text-body-md text-on-surface-variant">
        <li><a class="transition-colors hover:text-primary" href="riwayat_penyewa.php">Riwayat Pembayaran</a></li>
        <li><a class="transition-colors hover:text-primary" href="kirim_pengaduan.php">Pengaduan Penyewa</a></li>
        <li><a class="transition-colors hover:text-primary" href="dashboard_pemilik_kos.php">Manajemen Properti</a></li>
        <li><a class="transition-colors hover:text-primary" href="login_daftar.php">Bantuan</a></li>
      </ul>
    </div>
    <div>
      <h4 class="mb-6 text-label-md font-label-md font-bold text-on-surface">Hubungi Kami</h4>
      <p class="mb-4 text-body-md text-on-surface-variant">Jl. Sudirman No. 123, Jakarta Pusat<br />DKI Jakarta, 10210</p>
      <p class="text-body-md text-on-surface-variant">Email: info@kelolakos.com<br />Phone: +62 21 555 0123</p>
    </div>
  </div>
  <div class="mx-auto mt-12 flex max-w-[1440px] flex-col items-center justify-between gap-4 border-t border-outline-variant/30 pt-8 text-label-sm font-label-sm text-on-surface-variant/70 md:flex-row">
    <p>© 2024 KelolaKos. Seluruh hak cipta dilindungi.</p>
    <div class="flex gap-6">
      <a class="transition-colors hover:text-primary" href="login_daftar.php">Kebijakan Privasi</a>
      <a class="transition-colors hover:text-primary" href="login_daftar.php">Syarat &amp; Ketentuan</a>
    </div>
  </div>
</footer>
</main>

<!-- MODAL POP-UP PILIHAN LOGIN LIGHT MODE -->
<div id="login-choice-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-md transition-opacity duration-300 opacity-0 pointer-events-none">
  
  <!-- Backdrop Click Listener -->
  <div class="absolute inset-0" onclick="closeLoginModal()"></div>

  <!-- Main Pop-Up Modal Container -->
  <div class="relative z-10 w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-white p-8 text-slate-800 shadow-2xl transition-all duration-300 scale-95 transform" id="login-modal-box">
    
    <!-- Tombol Close (X) -->
    <button onclick="closeLoginModal()" type="button" class="absolute top-5 right-5 flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 border border-slate-200 text-slate-500 hover:bg-slate-200 hover:text-slate-800 transition-all duration-200" aria-label="Tutup Modal">
      <span class="material-symbols-outlined text-xl">close</span>
    </button>

    <!-- Header & Logo Kelola Kos -->
    <div class="text-center space-y-3 mb-8">
      <div class="inline-flex items-center justify-center gap-2.5 px-4 py-2 rounded-2xl bg-primary/10 border border-primary/20">
        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">domain</span>
        <span class="font-extrabold tracking-tight text-xl text-slate-800">Kelola<span class="text-primary">Kos</span></span>
      </div>

      <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight pt-1">Masuk ke Kelola Kos</h2>
      <p class="text-sm font-medium text-slate-500">Saya ingin masuk sebagai</p>
    </div>

    <!-- 2 Tombol Pilihan Role -->
    <div class="grid gap-4">
      
      <!-- Option 1: Pencari Kos -->
      <a href="login_daftar.php?role=penyewa" class="group relative flex items-center gap-4.5 rounded-2xl border border-purple-200 bg-purple-50/60 p-5 transition-all duration-300 hover:scale-[1.02] hover:border-purple-300 hover:bg-purple-100/80 hover:shadow-md">
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

      <!-- Option 2: Pemilik Kos -->
      <a href="login_daftar.php?role=pemilik" class="group relative flex items-center gap-4.5 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 transition-all duration-300 hover:scale-[1.02] hover:border-amber-300 hover:bg-amber-100/80 hover:shadow-md">
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

    <!-- Note footer -->
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

        document.querySelectorAll('button, a').forEach(el => {
            el.addEventListener('mousedown', () => {
                el.classList.add('scale-[0.98]');
            });
            el.addEventListener('mouseup', () => {
                el.classList.remove('scale-[0.98]');
            });
            el.addEventListener('mouseleave', () => {
                el.classList.remove('scale-[0.98]');
            });
        });

        const searchInput = document.querySelector('#search-location');
        const searchContainer = searchInput?.closest('div');
        if (searchInput && searchContainer) {
            searchInput.addEventListener('focus', () => { searchContainer.classList.add('border-primary','ring-2','ring-primary/20'); });
            searchInput.addEventListener('blur', () => { searchContainer.classList.remove('border-primary','ring-2','ring-primary/20'); });
        }

        function toggleAmenityFilter(amenity, button) {
            const isActive = button.classList.toggle('bg-primary/10');
            button.classList.toggle('border-primary', isActive);
            button.classList.toggle('text-primary', isActive);
            const icon = button.querySelector('.material-symbols-outlined');
            if (icon) icon.style.fontVariationSettings = isActive ? "'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24" : "'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24";
        }

        function selectLocationFilter(location, button) {
            document.querySelectorAll('.quick-location-btn').forEach(el => {
                el.classList.remove('bg-primary/10', 'border-primary', 'text-primary');
            });
            button.classList.add('bg-primary/10', 'border-primary', 'text-primary');
            const input = document.getElementById('search-location');
            if (input) input.value = location;
        }

        function resetFilters() {
            document.querySelectorAll('.quick-filter-btn, .quick-location-btn').forEach(el => {
                el.classList.remove('bg-primary/10', 'border-primary', 'text-primary');
            });
            const input = document.getElementById('search-location');
            if (input) input.value = '';
            const select = document.getElementById('search-gender');
            if (select) select.value = '';
        }

        function toggleFavorite(button, event) {
            event.preventDefault();
            const icon = button.querySelector('.material-symbols-outlined');
            if (!icon) return;
            const isFilled = icon.textContent.trim() === 'favorite';
            icon.textContent = isFilled ? 'favorite_border' : 'favorite';
            icon.style.color = isFilled ? '#474554' : '#ef4444';
            icon.style.fontVariationSettings = isFilled ? "'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24" : "'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24";
        }
</script>
</body></html>
