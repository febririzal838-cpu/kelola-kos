<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/koneksi.php';

function sanitizeRedirectTarget($target) {
    $target = trim($target);
    if ($target === '') {
        return '';
    }
    if (strpos($target, '://') !== false || strpos($target, '\\n') !== false || strpos($target, '\\r') !== false) {
        return '';
    }
    if (strpos($target, '..') !== false) {
        return '';
    }
    if ($target[0] === '/') {
        return $target;
    }
    return preg_match('/^[a-zA-Z0-9_\.\/-]+$/', $target) ? $target : '';
}

$redirect = '';
if (!empty($_GET['redirect'])) {
    $redirect = sanitizeRedirectTarget($_GET['redirect']);
}

$rawRole = strtolower(trim($_REQUEST['role'] ?? $_GET['role'] ?? 'penyewa'));
if (in_array($rawRole, ['pemilik', 'owner', 'admin', 'pemilik_kos', 'owner_kos'], true)) {
    $role = 'pemilik';
    $roleTitle = 'Pemilik Kos';
    $roleIcon = 'real_estate_agent';
    $roleColor = 'amber';
} else {
    $role = 'penyewa';
    $roleTitle = 'Penyewa Kos';
    $roleIcon = 'meeting_room';
    $roleColor = 'purple';
}

/**
 * Tetapkan SEMUA session key secara lengkap setelah autentikasi berhasil.
 * - Berlaku untuk login manual & callback Google / OAuth.
 * - Pastikan tidak ada lagi session 'nama / owner_id / user_id yang default/lama.
 * @param PDO   $pdo       Koneksi DB
 * @param string $role        'pemilik' | 'penyewa'
 * @param array  $userRow   Row user (dari tabel pemilik atau penyewa)
 * @return void
 */
function establishFullSessionForUser(PDO $pdo, string $role, array $userRow): void {
    // Bersihkan session key usang supaya TIDAK ada lagi default/lama
    foreach (['user_id','pemilik_id','penyewa_id','owner_id','full_name','nama','role','username','user_email','pengajuan_sukses','success_message'] as $k) {
        if (isset($_SESSION[$k])) { unset($_SESSION[$k]); }
    }

    $uid = (int)($userRow['id'] ?? 0);
    if ($uid <= 0) { return; }

    $nama = trim((string)($userRow['full_name'] ?? $userRow['nama'] ?? 'Pengguna'));
    $email = trim((string)($userRow['email'] ?? ''));

    if ($role === 'pemilik') {
        $_SESSION['pemilik_id'] = $uid;
        $_SESSION['user_id']    = $uid;
        $_SESSION['owner_id']   = $uid;               // ⭐ Wajib — multi-tenant di dasbor pemilik
        $_SESSION['full_name'] = $nama;
        $_SESSION['nama']      = $nama;                 // ⭐ Sinkron kunci 'nama' juga
        $_SESSION['username']  = $nama;                 // backward-compat
        $_SESSION['role']       = 'pemilik';
        if ($email !== '') { $_SESSION['user_email'] = $email; }

        // ✅ Jamin owner_id DI DATABASE untuk user email= febririzal838@gmail.com → pastikan cocok
        try {
            $pdo->prepare("UPDATE pemilik SET full_name = :nm WHERE id = :id AND (full_name IS NULL OR full_name = '')")
                ->execute([':nm' => $nama, ':id' => $uid]);
        } catch (Throwable $e) { /* ignore */ }
    } else {
        $_SESSION['penyewa_id'] = $uid;
        $_SESSION['user_id']   = $uid;
        $_SESSION['full_name'] = $nama;
        $_SESSION['nama']      = $nama;
        $_SESSION['username']  = $nama;
        $_SESSION['role']       = 'penyewa';
        if ($email !== '') { $_SESSION['user_email'] = $email; }
    }
}

$message = "";
$status_type = "";

if (isset($_POST['login'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $message = "Email dan password harus diisi.";
        $status_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM pemilik WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $pemilik = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($pemilik) {
                if (isset($pemilik['password']) && (password_verify($password, $pemilik['password']) || $password === $pemilik['password'])) {
                    establishFullSessionForUser($pdo, 'pemilik', $pemilik);

                    $destination = $redirect ?: 'dashboard_pemilik_kos.php';
                    header("Location: {$destination}");
                    exit();
                } else {
                    $message = "Password yang Anda masukkan salah untuk akun pemilik.";
                    $status_type = "error";
                }
            } else {
                $stmt2 = $pdo->prepare('SELECT * FROM penyewa WHERE email = :email LIMIT 1');
                $stmt2->execute([':email' => $email]);
                $penyewa = $stmt2->fetch(PDO::FETCH_ASSOC);

                if ($penyewa) {
                    if (isset($penyewa['password']) && (password_verify($password, $penyewa['password']) || $password === $penyewa['password'])) {
                        establishFullSessionForUser($pdo, 'penyewa', $penyewa);

                        $destination = $redirect ?: 'dashboard_penyewa.php';
                        header("Location: {$destination}");
                        exit();
                    } else {
                        $message = "Password yang Anda masukkan salah untuk akun penyewa.";
                        $status_type = "error";
                    }
                } else {
                    $message = "Email belum terdaftar. Silakan daftar sebagai Pemilik atau Penyewa terlebih dahulu.";
                    $status_type = "error";
                }
            }
        } catch (PDOException $e) {
            $message = "Terjadi kesalahan saat memeriksa login: " . $e->getMessage();
            $status_type = "error";
        }
    }
}

if (isset($_POST['register'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $phone     = trim($_POST['phone_number'] ?? '');
    $role_input = strtolower(trim($_POST['role'] ?? $role));
    if (in_array($role_input, ['owner', 'pemilik', 'owner_kos', 'pemilik_kos'], true)) {
        $role = 'pemilik';
    } else {
        $role = 'penyewa';
    }

    if ($full_name === '' || $email === '' || $password === '' || $phone === '') {
        $message = "Lengkapi semua kolom pendaftaran dengan benar.";
        $status_type = "error";
    } else {
        try {
            $cekPem = $pdo->prepare('SELECT id FROM pemilik WHERE email = :email LIMIT 1');
            $cekPem->execute([':email' => $email]);
            $cekPen = $pdo->prepare('SELECT id FROM penyewa WHERE email = :email LIMIT 1');
            $cekPen->execute([':email' => $email]);
            if ($cekPem->fetch() || $cekPen->fetch()) {
                $message = "Email ini sudah terdaftar. Silakan masuk atau gunakan alamat lain.";
                $status_type = "error";
            } else {
                $pdo->beginTransaction();
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($role === 'pemilik') {
                    $ins = $pdo->prepare('INSERT INTO pemilik (full_name, email, password, phone_number) VALUES (:full_name, :email, :password, :phone)');
                    $ins->execute([
                        ':full_name' => $full_name,
                        ':email' => $email,
                        ':password' => $passwordHash,
                        ':phone' => $phone,
                    ]);
                    $ownerId = $pdo->lastInsertId();
                    $pdo->commit();

                    // 🔑 Gunakan helper session yang bersih & konsisten
                    establishFullSessionForUser($pdo, 'pemilik', [
                        'id' => $ownerId,
                        'full_name' => $full_name,
                        'email' => $email,
                    ]);

                    $destination = $redirect ?: 'dashboard_pemilik_kos.php';
                    header("Location: {$destination}");
                    exit();
                } else {
                    $ins = $pdo->prepare('INSERT INTO penyewa (full_name, email, password, phone_number) VALUES (:full_name, :email, :password, :phone)');
                    $ins->execute([
                        ':full_name' => $full_name,
                        ':email' => $email,
                        ':password' => $passwordHash,
                        ':phone' => $phone,
                    ]);
                    $penyewaId = $pdo->lastInsertId();
                    $pdo->commit();

                    establishFullSessionForUser($pdo, 'penyewa', [
                        'id' => $penyewaId,
                        'full_name' => $full_name,
                        'email' => $email,
                    ]);

                    $destination = $redirect ?: 'dashboard_penyewa.php';
                    header("Location: {$destination}");
                    exit();
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Gagal mendaftar: " . $e->getMessage();
            $status_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login & Daftar - KelolaKos</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#5341cd',
                        'primary-container': '#6c5ce7',
                        'primary-fixed': '#e4dfff',
                        'primary-fixed-dim': '#c6bfff',
                        secondary: '#006b55',
                        background: '#f1fbff',
                        surface: '#f1fbff',
                        'surface-container': '#e4f0f4',
                        'surface-container-low': '#eaf5fa',
                        'surface-container-lowest': '#ffffff',
                        'on-surface': '#131d21',
                        'on-surface-variant': '#474554',
                        'on-primary': '#ffffff',
                        'outline-variant': '#c8c4d7',
                        outline: '#787586',
                    },
                    borderRadius: {
                        xl: '0.75rem',
                        '2xl': '1rem',
                        '3xl': '1.5rem',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .auth-bg {
            background: linear-gradient(135deg, #f1fbff 0%, #e4dfff 50%, #eaf5fa 100%);
            background-attachment: fixed;
        }
        .auth-bg::before {
            content: '';
            position: fixed;
            top: -10%;
            right: -5%;
            width: 500px;
            height: 500px;
            border-radius: 9999px;
            background: radial-gradient(circle, rgba(108,92,231,0.15) 0%, transparent 70%);
            pointer-events: none;
        }
        .auth-bg::after {
            content: '';
            position: fixed;
            bottom: -10%;
            left: -5%;
            width: 500px;
            height: 500px;
            border-radius: 9999px;
            background: radial-gradient(circle, rgba(0,107,85,0.12) 0%, transparent 70%);
            pointer-events: none;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 25px 60px -15px rgba(83, 65, 205, 0.2);
        }
        .tab-active {
            background: linear-gradient(135deg, #5341cd 0%, #6c5ce7 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(83, 65, 205, 0.35);
        }
        .tab-inactive {
            color: #474554;
        }
        .tab-inactive:hover {
            background: rgba(83, 65, 205, 0.06);
            color: #5341cd;
        }
        .form-input {
            transition: all 0.2s ease;
        }
        .form-input:focus {
            border-color: #5341cd;
            box-shadow: 0 0 0 3px rgba(83, 65, 205, 0.12);
        }
        .btn-primary {
            background: linear-gradient(135deg, #5341cd 0%, #6c5ce7 100%);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px -8px rgba(83, 65, 205, 0.5);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.4s ease forwards;
        }
    </style>
</head>
<body class="auth-bg">
    <div class="relative z-10 min-h-screen flex items-center justify-center p-4 py-10">
        <div class="w-full max-w-md">
            <a href="index.php" class="flex items-center justify-center gap-3 mb-8 group">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary-container shadow-lg shadow-primary/25 group-hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined text-white text-2xl" style="font-variation-settings: 'FILL' 1;">domain</span>
                </div>
                <span class="text-2xl font-extrabold tracking-tight text-on-surface">
                    Kelola<span class="text-primary">Kos</span>
                </span>
            </a>

            <?php if (!empty($message)): ?>
                <div class="mb-6 rounded-2xl border px-5 py-4 animate-fade-in <?php echo ($status_type === 'error') ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'; ?>">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-xl flex-shrink-0 mt-0.5">
                            <?php echo ($status_type === 'error') ? 'error' : 'check_circle'; ?>
                        </span>
                        <p class="text-sm font-medium leading-relaxed"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="glass-card rounded-3xl p-7 md:p-9 animate-fade-in">
                <div class="text-center mb-7">
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border mb-4 <?php echo ($roleColor === 'amber') ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-purple-50 border-purple-200 text-purple-700'; ?>">
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1;"><?php echo htmlspecialchars($roleIcon); ?></span>
                        <span class="text-xs font-bold tracking-wide uppercase"><?php echo htmlspecialchars($roleTitle); ?></span>
                    </div>
                    <h1 class="text-2xl md:text-[28px] font-extrabold text-on-surface tracking-tight leading-tight">
                        Selamat Datang Kembali
                    </h1>
                    <p class="mt-2 text-sm text-on-surface-variant leading-relaxed">
                        Masukkan detail akun Anda untuk mengakses dashboard
                    </p>
                </div>

                <div class="flex items-center gap-2 p-1.5 rounded-2xl bg-surface-container-low mb-7">
                    <button type="button" id="tab-login" onclick="switchTab('login')" class="tab-active flex-1 flex items-center justify-center gap-2 py-3 rounded-xl text-sm font-bold transition-all">
                        <span class="material-symbols-outlined text-lg">login</span>
                        <span>Masuk</span>
                    </button>
                    <button type="button" id="tab-register" onclick="switchTab('register')" class="tab-inactive flex-1 flex items-center justify-center gap-2 py-3 rounded-xl text-sm font-bold transition-all">
                        <span class="material-symbols-outlined text-lg">person_add</span>
                        <span>Daftar</span>
                    </button>
                </div>

                <form id="form-login" action="" method="POST" class="space-y-5 animate-fade-in">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">

                    <div class="space-y-2">
                        <label for="login-email" class="block text-sm font-semibold text-on-surface">
                            Email
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-[20px] text-on-surface-variant/70">alternate_email</span>
                            <input id="login-email" type="email" name="email" placeholder="contoh@email.com" required
                                class="form-input w-full pl-12 pr-4 py-3.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/50 text-sm font-medium outline-none">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="login-password" class="block text-sm font-semibold text-on-surface">
                            Password
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-[20px] text-on-surface-variant/70">lock</span>
                            <input id="login-password" type="password" name="password" placeholder="Masukkan password" required
                                class="form-input w-full pl-12 pr-12 py-3.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/50 text-sm font-medium outline-none">
                            <button type="button" onclick="togglePassword('login-password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-primary transition-colors">
                                <span class="material-symbols-outlined text-lg">visibility</span>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="login" class="btn-primary w-full flex items-center justify-center gap-2 py-4 rounded-xl text-on-primary font-bold text-sm shadow-lg">
                        <span class="material-symbols-outlined text-lg">arrow_forward</span>
                        <span>Masuk ke Akun</span>
                    </button>
                </form>

                <form id="form-register" action="" method="POST" class="space-y-4 hidden">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">

                    <div class="space-y-2">
                        <label for="reg-name" class="block text-sm font-semibold text-on-surface">
                            Nama Lengkap
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-[20px] text-on-surface-variant/70">badge</span>
                            <input id="reg-name" type="text" name="full_name" placeholder="Nama lengkap Anda" required
                                class="form-input w-full pl-12 pr-4 py-3.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/50 text-sm font-medium outline-none">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="reg-email" class="block text-sm font-semibold text-on-surface">
                            Email
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-[20px] text-on-surface-variant/70">alternate_email</span>
                            <input id="reg-email" type="email" name="email" placeholder="contoh@email.com" required
                                class="form-input w-full pl-12 pr-4 py-3.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/50 text-sm font-medium outline-none">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="reg-password" class="block text-sm font-semibold text-on-surface">
                            Password
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-[20px] text-on-surface-variant/70">lock</span>
                            <input id="reg-password" type="password" name="password" placeholder="Buat password (min. 6 karakter)" required
                                class="form-input w-full pl-12 pr-12 py-3.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/50 text-sm font-medium outline-none">
                            <button type="button" onclick="togglePassword('reg-password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-primary transition-colors">
                                <span class="material-symbols-outlined text-lg">visibility</span>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="reg-phone" class="block text-sm font-semibold text-on-surface">
                            Nomor HP / WhatsApp
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-symbols-outlined text-[20px] text-on-surface-variant/70">call</span>
                            <input id="reg-phone" type="text" name="phone_number" placeholder="08xxxxxxxxxx" required
                                class="form-input w-full pl-12 pr-4 py-3.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest text-on-surface placeholder:text-on-surface-variant/50 text-sm font-medium outline-none">
                        </div>
                    </div>

                    <button type="submit" name="register" class="btn-primary w-full flex items-center justify-center gap-2 py-4 rounded-xl text-on-primary font-bold text-sm shadow-lg mt-1">
                        <span class="material-symbols-outlined text-lg">how_to_reg</span>
                        <span>Buat Akun Baru</span>
                    </button>
                </form>

                <div class="mt-8 pt-6 border-t border-outline-variant/30">
                    <p class="text-center text-sm text-on-surface-variant">
                        <span id="alt-text-login">Belum punya akun?</span>
                        <button type="button" id="alt-switch" onclick="switchTab('register')" class="ml-1 font-bold text-primary hover:text-primary-container transition-colors underline decoration-primary/30 underline-offset-4 hover:decoration-primary">
                            Daftar sekarang
                        </button>
                    </p>
                    <div class="mt-5 flex items-center justify-center gap-2 text-xs text-on-surface-variant/70">
                        <a href="index.php" class="inline-flex items-center gap-1 hover:text-primary transition-colors font-medium">
                            <span class="material-symbols-outlined text-sm">arrow_back</span>
                            <span>Kembali ke Beranda</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="mt-8 text-center text-xs text-on-surface-variant/60">
                <p>&copy; 2024 KelolaKos. Platform pencarian & pengelolaan kos modern.</p>
            </div>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const initialMode = urlParams.get('mode') === 'register' ? 'register' : 'login';

        function switchTab(mode) {
            const tabLogin = document.getElementById('tab-login');
            const tabRegister = document.getElementById('tab-register');
            const formLogin = document.getElementById('form-login');
            const formRegister = document.getElementById('form-register');
            const altText = document.getElementById('alt-text-login');
            const altSwitch = document.getElementById('alt-switch');

            if (mode === 'login') {
                tabLogin.classList.remove('tab-inactive');
                tabLogin.classList.add('tab-active');
                tabRegister.classList.remove('tab-active');
                tabRegister.classList.add('tab-inactive');
                formLogin.classList.remove('hidden');
                formLogin.classList.add('animate-fade-in');
                formRegister.classList.add('hidden');
                formRegister.classList.remove('animate-fade-in');
                altText.textContent = 'Belum punya akun?';
                altSwitch.textContent = 'Daftar sekarang';
                altSwitch.setAttribute('onclick', "switchTab('register')");
            } else {
                tabRegister.classList.remove('tab-inactive');
                tabRegister.classList.add('tab-active');
                tabLogin.classList.remove('tab-active');
                tabLogin.classList.add('tab-inactive');
                formRegister.classList.remove('hidden');
                formRegister.classList.add('animate-fade-in');
                formLogin.classList.add('hidden');
                formLogin.classList.remove('animate-fade-in');
                altText.textContent = 'Sudah punya akun?';
                altSwitch.textContent = 'Masuk di sini';
                altSwitch.setAttribute('onclick', "switchTab('login')");
            }

            try {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('mode', mode);
                window.history.replaceState({}, '', newUrl);
            } catch (e) {}
        }

        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('.material-symbols-outlined');
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                icon.textContent = 'visibility';
            }
        }

        if (initialMode === 'register') {
            switchTab('register');
        }
    </script>
</body>
</html>
