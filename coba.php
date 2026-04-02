<?php
// Memastikan sesi sudah berjalan
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
</head>
<body>
    <nav class="navbar-top">
        <div class="nav-links">
            <a href="/foto-ktm/public/" style="background: rgba(255,255,255,0.2); border-radius: 4px; padding: 5px 10px; margin-right: 15px; font-weight: bold; color: #fff; text-decoration: none;">🏠 BERANDA UTAMA</a>
            
            <div class="dropdown dropdown-mhs">
                <a href="javascript:void(0)" class="dropbtn <?= $is_mhs_active ?>">🎓 Data Mahasiswa ▾</a>
                <div class="dropdown-content">
                    <a href="antrean_mhs.php" class="<?= $current_page == 'antrean_mhs.php' ? 'sub-active' : '' ?>">📝 Antrean Foto & Cetak</a>
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
                👤 <?= htmlspecialchars($_SESSION['nama'] ?? 'Operator'); ?>
            </a>
            <a href="logout.php" class="logout-btn" onclick="return confirm('Yakin ingin keluar?')">🚪 Keluar</a>
        </div>
    </nav>