<?php
/**
 * File: auth.php
 * Deskripsi: Memeriksa sesi login dan mengatur Auto-Logout jika tidak aktif
 */

// Mulai sesi jika belum berjalan
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. CEK APAKAH PENGGUNA SUDAH LOGIN
// Jika tidak ada session user_id, tendang kembali ke halaman login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// =========================================================================
// 2. LOGIKA AUTO-LOGOUT (SESSION TIMEOUT)
// =========================================================================

// Atur durasi maksimal tidak ada aktivitas (dalam detik)
// Contoh: 900 detik = 15 Menit
$timeout_duration = 900; 

// Cek apakah variabel 'last_action' sudah ada di sesi
if (isset($_SESSION['last_action'])) {
    // Hitung selisih waktu sekarang dengan waktu aktivitas terakhir
    $waktu_idle = time() - $_SESSION['last_action'];
    
    // Jika waktu idle melebihi batas durasi
    if ($waktu_idle >= $timeout_duration) {
        // Hapus semua data sesi
        session_unset();
        session_destroy();
        
        // Arahkan ke halaman login dengan mengirim parameter pesan timeout
        header("Location: login.php?pesan=timeout");
        exit;
    }
}

// Jika masih aktif atau baru login, perbarui 'last_action' dengan waktu detik ini
// Ini akan mereset hitung mundur setiap kali pengguna memuat ulang/berpindah halaman
$_SESSION['last_action'] = time();

?>