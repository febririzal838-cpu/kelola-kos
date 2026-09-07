<?php
session_start();

// Jika admin/pemilik sudah login, langsung ke form penambahan kamar/dashboard
if (isset($_SESSION['owner_id']) || ($_SESSION['role'] ?? '') === 'pemilik') {
    $redirect = !empty($_GET['redirect']) ? $_GET['redirect'] : 'kamar_form.php';
    header("Location: {$redirect}");
    exit();
}

$redirectTarget = !empty($_GET['redirect']) ? urlencode($_GET['redirect']) : 'kamar_form.php';
header("Location: login_daftar.php?role=admin&redirect={$redirectTarget}");
exit();
