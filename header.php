<?php
// Memastikan sesi sudah berjalan agar kita bisa memanggil nama dan role operator
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mendapatkan nama file yang sedang dibuka
$current_page = basename($_SERVER['PHP_SELF']);

// Logika untuk mendeteksi Dropdown mana yang sedang aktif
$is_mhs_active = in_array($current_page, ['antrean_mhs.php', 'cetak_mhs.php']) ? 'active' : '';
$is_sdm_active = in_array($current_page, ['antrean_sdm.php', 'cetak_sdm.php']) ? 'active' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/uinsa.png" type="image/png">
    <title><?= isset($page_title) ? $page_title : 'Sistem Layanan Terpadu'; ?> - UIN Sunan Ampel</title>
    
    <link rel="stylesheet" href="style.css">
    
    <style>
        /* CSS Variabel Global */
        :root {
            --blue: #007bff;
            --green: #28a745;
            --dark: #343a40;
            --light: #f8f9fa;
            --danger: #dc3545;
        }
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        
        /* DESAIN NAVBAR GLOBAL */
        .navbar { background-color: var(--dark); padding: 15px 25px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.15); }
        .navbar-brand { font-size: 20px; font-weight: bold; color: white; text-decoration: none; display: flex; align-items: center; gap: 10px; }
        .nav-links { display: flex; gap: 15px; align-items: center; }
        .nav-links a { color: #d1d8e0; text-decoration: none; font-size: 14.5px; padding: 8px 12px; border-radius: 5px; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
        .nav-links a:hover { color: white; background-color: rgba(255,255,255,0.1); }
        .nav-links a.active { color: white; background-color: var(--blue); font-weight: bold; }
        
        /* WARNA KHUSUS SDM & GATE */
        .nav-links .dropdown-sdm > a.active { background-color: var(--green); }
        .nav-links a.menu-gate.active { background-color: #6c757d; }

        /* ================================================================= */
        /* CSS DROPDOWN MENU */
        /* ================================================================= */
        .dropdown { position: relative; display: inline-block; }
        .dropbtn { cursor: pointer; }
        .dropdown-content {
            display: none; position: absolute; background-color: #23272b;
            min-width: 170px; box-shadow: 0px 8px 20px 0px rgba(0,0,0,0.4);
            z-index: 1000; border-radius: 6px; top: 100%; left: 0;
            overflow: hidden; border: 1px solid rgba(255,255,255,0.05); padding: 5px 0;
        }
        /* Memunculkan dropdown saat mouse diarahkan (hover) */
        .dropdown:hover .dropdown-content { display: block; }
        
        .dropdown-content a {
            color: #d1d8e0; padding: 10px 15px; text-decoration: none;
            display: block; font-size: 13.5px; border-radius: 0; transition: 0.2s;
        }
        .dropdown-content a:hover { background-color: rgba(255,255,255,0.1); color: white; padding-left: 20px; }
        
        /* Penanda aktif di dalam sub-menu dropdown */
        .dropdown-content a.sub-active { background-color: rgba(255,255,255,0.15); color: white; font-weight: bold; border-left: 3px solid var(--blue); }
        .dropdown-sdm .dropdown-content a.sub-active { border-left-color: var(--green); }
        /* ================================================================= */

        .nav-user { font-size: 13.5px; background: rgba(0,0,0,0.2); padding: 6px 15px; border-radius: 10px; display:flex; align-items:center; gap:15px; border: 1px solid rgba(255,255,255,0.1); }
        .btn-logout { background: var(--danger); color: white; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; }
        .btn-logout:hover { background: #c82333; }
        
        /* PEMBUNGKUS KONTEN UTAMA */
        .container { max-width: 1200px; margin: 30px auto; padding: 30px; background: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        /* CSS GLOBAL (Tabel, Tombol, Alert) */
        .btn { display: inline-block; padding: 8px 15px; cursor: pointer; border-radius: 4px; border: none; font-size: 14px; text-align: center; }
        .btn-blue { background-color: var(--blue); color: white; }
        .btn-red { background-color: var(--danger); color: white; }
        .btn-outline { border: 1px solid #ccc; background: white; color: #333; text-decoration: none; transition: 0.2s; }
        .btn-outline:hover { background: var(--light); }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px 15px; border: 1px solid #dee2e6; text-align: left; }
        th { background-color: var(--light); color: var(--dark); }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; }
        .bg-sudah { background-color: var(--green); }
        .bg-belum { background-color: var(--danger); }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .pagination { margin-top: 20px; display: flex; gap: 5px; justify-content: center; }
        .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; color: var(--blue); border-radius: 4px; }
        .pagination a.active { background: var(--blue); color: white; border-color: var(--blue); }
    </style>
</head>
<body>

    <header class="main-header">
        <img src="assets/uinsa.png" alt="Logo UINSA" class="header-logo">
        <div class="header-title">
            <h1>Sistem Layanan Cetak ID Card Terpadu</h1>
            <p>UPT Percetakan - UIN Sunan Ampel Surabaya</p>
        </div>
        <img src="assets/uinsa_press.png" alt="Logo UINSA Press" class="header-logo">
    </header>

    <nav class="navbar">
        <div class="nav-links">
            <a href="https://id-card-percetakan.uinsa.ac.id" style="background: rgba(255,255,255,0.2); border-radius: 4px; padding: 5px 10px; margin-right: 15px; font-weight: bold; color: #fff; text-decoration: none;">🏠 BERANDA UTAMA</a>
            
            <div class="dropdown">
                <a href="javascript:void(0)" class="dropbtn <?= $is_mhs_active ?>">🎓 Data Mahasiswa ▾</a>
                <div class="dropdown-content">
                    <a href="antrean_mhs.php" class="<?= $current_page == 'antrean_mhs.php' ? 'sub-active' : '' ?>">📝 Lapor Insiden & Antrean</a>
                    <a href="cetak_mhs.php" class="<?= $current_page == 'cetak_mhs.php' ? 'sub-active' : '' ?>">🖨️ Cetak Fisik KTM</a>
                </div>
            </div>
            
            <div class="dropdown dropdown-sdm">
                <a href="javascript:void(0)" class="dropbtn <?= $is_sdm_active ?>" style="color: #d4edda;">👔 Data SDM & Mitra ▾</a>
                <div class="dropdown-content">
                    <a href="antrean_sdm.php" class="<?= $current_page == 'antrean_sdm.php' ? 'sub-active' : '' ?>">📝 Lapor Insiden & Antrean</a>
                    <a href="cetak_sdm.php" class="<?= $current_page == 'cetak_sdm.php' ? 'sub-active' : '' ?>">🖨️ Cetak Fisik ID Card</a>
                </div>
            </div>
            
            <a href="manajemen_gate.php" class="menu-gate <?= $current_page == 'manajemen_gate.php' ? 'active' : '' ?>">📡 Manajemen Gate</a>
        </div>

        <div class="nav-user">
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                <a href="kelola_operator.php" style="background-color: #17a2b8; color: white; border-radius: 4px; padding: 4px 8px; text-decoration:none; font-weight:bold;">👥 Operator</a>
            <?php endif; ?>
            
            <a href="profile.php" style="color: #ffc107; text-decoration: none; font-weight: bold;">
                👤 <?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Profil'); ?>
            </a>

            <a href="logout.php" class="btn-logout" onclick="return confirm('Apakah Anda yakin ingin keluar dari sistem?');">
                🚪 Logout
            </a>
        </div>
    </nav>

    <div class="container">