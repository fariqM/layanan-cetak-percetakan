<?php
/**
 * File: profile.php
 * Deskripsi: Halaman bagi pengguna (Operator/Admin) untuk mengelola profil sendiri.
 * Fitur: Update Nama Lengkap, Update Password Opsional, Sinkronisasi Sesi Berjalan.
 */

require_once 'koneksi.php';
require_once 'auth.php'; // Memastikan pengguna sudah login

$pesan = "";
// Ambil ID pengguna dari sesi yang sedang aktif
$user_id_aktif = (int)$_SESSION['user_id']; 

// =========================================================================
// 1. LOGIKA UPDATE PROFIL
// =========================================================================
if (isset($_POST['update_profile'])) {
    $nama_baru = ucwords(trim($_POST['nama_lengkap']));
    $password_baru = $_POST['password_baru'];
    $konfirmasi_password = $_POST['konfirmasi_password'];

    // Jika pengguna ingin mengganti password
    if (!empty($password_baru)) {
        if ($password_baru !== $konfirmasi_password) {
            $pesan = "<div class='alert error'>❌ Gagal! Konfirmasi password tidak cocok dengan password baru.</div>";
        } else {
            try {
                $hashed_password = password_hash($password_baru, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = :nama, password = :pass WHERE id_user = :id");
                $stmt->execute([
                    ':nama' => $nama_baru,
                    ':pass' => $hashed_password,
                    ':id' => $user_id_aktif
                ]);
                
                // Sinkronisasi Sesi agar nama di pojok/dashboard langsung berubah
                $_SESSION['nama_lengkap'] = $nama_baru;
                $pesan = "<div class='alert success'>✅ Profil dan Password berhasil diperbarui!</div>";
            } catch (PDOException $e) {
                $pesan = "<div class='alert error'>❌ Terjadi kesalahan saat menyimpan data.</div>";
            }
        }
    } 
    // Jika hanya memperbarui nama (password dikosongkan)
    else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = :nama WHERE id_user = :id");
            $stmt->execute([
                ':nama' => $nama_baru,
                ':id' => $user_id_aktif
            ]);
            
            // Sinkronisasi Sesi
            $_SESSION['nama_lengkap'] = $nama_baru;
            $pesan = "<div class='alert success'>✅ Profil berhasil diperbarui!</div>";
        } catch (PDOException $e) {
            $pesan = "<div class='alert error'>❌ Terjadi kesalahan saat menyimpan data.</div>";
        }
    }
}

// =========================================================================
// 2. AMBIL DATA PENGGUNA SAAT INI UNTUK DITAMPILKAN DI FORM
// =========================================================================
$stmt = $pdo->prepare("SELECT username, nama_lengkap, role FROM users WHERE id_user = :id LIMIT 1");
$stmt->execute([':id' => $user_id_aktif]);
$data_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Jika terjadi anomali data tidak ditemukan
if (!$data_user) {
    die("<div style='text-align:center; padding:50px;'><h2>Terjadi Kesalahan Sinkronisasi Akun.</h2></div>");
}

$page_title = "Profil Saya";
include 'header.php'; 
?>

<style>
    /* Desain Pembungkus Tengah & Responsif */
    .profile-wrapper {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 20px 10px;
        min-height: 70vh;
    }

    .profile-panel {
        background: #ffffff;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        width: 100%;
        max-width: 650px;
        border-top: 5px solid #007bff; /* Warna Aksen Biru */
        box-sizing: border-box;
    }

    /* Pengaturan Baris Form (Otomatis menyesuaikan layar) */
    .profile-form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
        flex-wrap: wrap; /* Kunci responsif: membiarkan kolom turun jika sempit */
    }

    .profile-form-col {
        flex: 1;
        min-width: 250px; /* Jika layar HP < 500px, kolom akan patah ke bawah */
        display: flex;
        flex-direction: column;
    }

    .profile-form-col label {
        font-size: 13.5px;
        font-weight: 700;
        color: #495057;
        margin-bottom: 8px;
    }

    .profile-form-col input {
        width: 100%;
        padding: 12px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        font-size: 15px;
        box-sizing: border-box;
        transition: border-color 0.2s;
    }

    .profile-form-col input:focus {
        outline: none;
        border-color: #80bdff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
    }

    .btn-save {
        width: 100%;
        padding: 14px;
        font-size: 16px;
        font-weight: bold;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        transition: 0.2s;
        margin-top: 10px;
    }

    .btn-save:hover {
        background-color: #0056b3;
    }
</style>

<div style="text-align: center; margin-bottom: 25px;">
    <h2 style="margin: 0; color: #343a40;">Pengaturan Profil Saya</h2>
    <p style="color: #6c757d; margin-top: 5px; font-size: 15px;">Kelola informasi identitas akun Anda di sistem.</p>
</div>

<div class="profile-wrapper">
    <div class="profile-panel">
        
        <?= $pesan; ?>

        <h3 style="color: #007bff; border-bottom: 2px solid #e9ecef; padding-bottom: 12px; margin-top: 0; margin-bottom: 25px; font-size: 18px;">
            👤 Detail Akun
        </h3>
        
        <form action="profile.php" method="POST">
            
            <div class="profile-form-row" style="margin-bottom: 15px;">
                <div class="profile-form-col">
                    <label>Username (ID Login)</label>
                    <input type="text" value="<?= htmlspecialchars($data_user['username']); ?>" 
                           style="background-color: #e9ecef; cursor: not-allowed; font-weight: bold; color: #555;" readonly>
                    <small style="color: #888; margin-top: 5px;">Username bersifat permanen dan tidak dapat diubah.</small>
                </div>
            </div>

            <div class="profile-form-row">
                <div class="profile-form-col">
                    <label>Nama Lengkap</label>
                    <input type="text" name="nama_lengkap" required 
                           value="<?= htmlspecialchars($data_user['nama_lengkap']); ?>"
                           placeholder="Masukkan nama asli Anda...">
                </div>
                <div class="profile-form-col">
                    <label>Hak Akses (Role)</label>
                    <input type="text" value="<?= strtoupper($data_user['role']); ?>" 
                           style="background-color: #e9ecef; cursor: not-allowed; text-align: center; font-weight: bold; 
                                  color: <?= $data_user['role'] === 'super-admin' ? '#17a2b8' : '#6c757d' ?>;" readonly>
                </div>
            </div>

            <h3 style="color: #495057; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-top: 35px; margin-bottom: 20px; font-size: 18px;">
                🔒 Ganti Password <small style="font-weight: normal; color: #dc3545; font-size: 14px;">(Opsional)</small>
            </h3>

            <div class="profile-form-row" style="margin-bottom: 5px;">
                <div class="profile-form-col">
                    <label>Password Baru</label>
                    <input type="password" name="password_baru" autocomplete="new-password"
                           placeholder="Ketik jika ingin mengganti...">
                </div>
                <div class="profile-form-col">
                    <label>Konfirmasi Password Baru</label>
                    <input type="password" name="konfirmasi_password" autocomplete="new-password"
                           placeholder="Ketik ulang password baru...">
                </div>
            </div>
            <small style="color: #888; display: block; margin-bottom: 25px; font-size: 13px;">Biarkan kedua kolom sandi kosong jika Anda tidak ingin mengganti password saat ini.</small>

            <button type="submit" name="update_profile" class="btn-save">
                💾 Simpan Perubahan Profil
            </button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>