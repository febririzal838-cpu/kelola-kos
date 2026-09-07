<?php
session_start();

// 1. Kosongkan array session
$_SESSION = array();

// 2. Hapus cookie session dari browser jika ada
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Hapus cookie session tambahan jika terkonfigurasi
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// 3. Hancurkan session
session_unset();
session_destroy();

// 4. Redirect pengguna kembali ke beranda utama (index.php)
header("Location: index.php");
exit();
