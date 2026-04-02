<?php
/**
 * File: kelola_operator.php (Manajemen User)
 * Deskripsi: Kelola data akun aplikasi (Super Admin & Operator)
 * Fitur: CRUD Terpusat, Keamanan Password Hash, Validasi Role, Pencarian, Pagination
 */

require_once 'koneksi.php';
require_once 'auth.php';

// TOLAK AKSES JIKA BUKAN SUPER-ADMIN
if ($_SESSION['role'] !== 'super-admin') {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'>
            <h2 style='color:red;'>⛔ Akses Ditolak!</h2>
            <p>Hanya Super-Admin yang diizinkan mengakses halaman ini.</p>
            <a href='index.php'>Kembali ke Dashboard</a>
         </div>");
}

$pesan = "";

// =========================================================================
// 1. LOGIKA HAPUS USER
// =========================================================================
if (isset($_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    
    // Cegah bunuh diri (menghapus akun sendiri yang sedang dipakai)
    if (isset($_SESSION['user_id']) && $id_hapus === (int)$_SESSION['user_id']) {
        $pesan = "<div class='alert error'>⛔ Gagal! Anda tidak bisa menghapus akun Anda sendiri saat sedang login.</div>";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id_user = :id");
        if ($stmt->execute([':id' => $id_hapus])) {
            $pesan = "<div class='alert success'>✅ Akun pengguna berhasil dihapus dari sistem.</div>";
        }
    }
}

// =========================================================================
// 2. LOGIKA TAMBAH & UPDATE USER
// =========================================================================
if (isset($_POST['submit_user']) || isset($_POST['update_user'])) {
    $username = trim($_POST['username']);
    $nama_lengkap = ucwords(trim($_POST['nama_lengkap']));
    $role = trim($_POST['role']);
    $password = $_POST['password']; 
    
    if (isset($_POST['update_user'])) {
        // --- PROSES UPDATE (EDIT) ---
        $id_user = (int)$_POST['id_user'];
        $old_username = trim($_POST['old_username']);

        // Cek apakah username diubah dan apakah username baru sudah dipakai orang lain
        $username_tersedia = true;
        if ($username !== $old_username) {
            $cek = $pdo->prepare("SELECT id_user FROM users WHERE username = :usr");
            $cek->execute([':usr' => $username]);
            if ($cek->fetch()) $username_tersedia = false;
        }

        if (!$username_tersedia) {
            $pesan = "<div class='alert error'>❌ Username <strong>$username</strong> sudah digunakan. Pilih username lain.</div>";
        } else {
            try {
                if (!empty($password)) {
                    // Update dengan mengganti password baru
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username=:usr, nama_lengkap=:nama, role=:role, password=:pass WHERE id_user=:id");
                    $stmt->execute([':usr'=>$username, ':nama'=>$nama_lengkap, ':role'=>$role, ':pass'=>$hashed_password, ':id'=>$id_user]);
                    $pesan = "<div class='alert success'>✅ Data pengguna dan password berhasil diperbarui.</div>";
                } else {
                    // Update tanpa mengganti password
                    $stmt = $pdo->prepare("UPDATE users SET username=:usr, nama_lengkap=:nama, role=:role WHERE id_user=:id");
                    $stmt->execute([':usr'=>$username, ':nama'=>$nama_lengkap, ':role'=>$role, ':id'=>$id_user]);
                    $pesan = "<div class='alert success'>✅ Data profil pengguna berhasil diperbarui.</div>";
                }
            } catch (PDOException $e) {
                $pesan = "<div class='alert error'>❌ Terjadi kesalahan sistem saat memperbarui data.</div>";
            }
        }
    } else {
        // --- PROSES INSERT (TAMBAH BARU) ---
        $cek = $pdo->prepare("SELECT id_user FROM users WHERE username = :usr");
        $cek->execute([':usr' => $username]);
        if ($cek->fetch()) {
            $pesan = "<div class='alert error'>❌ Username sudah terdaftar! Gunakan username lain.</div>";
        } elseif (empty($password)) {
            $pesan = "<div class='alert error'>❌ Password wajib diisi untuk pengguna baru!</div>";
        } else {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, nama_lengkap, role) VALUES (:usr, :pass, :nama, :role)");
                $stmt->execute([':usr'=>$username, ':pass'=>$hashed_password, ':nama'=>$nama_lengkap, ':role'=>$role]);
                $pesan = "<div class='alert success'>✅ Pengguna baru berhasil ditambahkan.</div>";
            } catch (PDOException $e) {
                $pesan = "<div class='alert error'>❌ Terjadi kesalahan saat menyimpan data.</div>";
            }
        }
    }
}

// =========================================================================
// 3. TARIK DATA UNTUK MODE EDIT
// =========================================================================
$edit_mode = false;
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id_user = :id");
    $stmt->execute([':id' => $_GET['edit']]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_data) { $edit_mode = false; }
}

// =========================================================================
// 4. DATA FETCHING (Daftar Seluruh User) & PAGINATION
// =========================================================================
$limit = 10; 
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; 
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = $search !== '' ? "WHERE username LIKE :s OR nama_lengkap LIKE :s" : "";

$stmt = $pdo->prepare("SELECT * FROM users $where ORDER BY role ASC, nama_lengkap ASC LIMIT $limit OFFSET $offset");
if($search !== '') $stmt->bindValue(':s', "%$search%");
$stmt->execute();
$data_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
if($search !== '') $countStmt->bindValue(':s', "%$search%");
$countStmt->execute();
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$page_title = "Kelola Operator";
include 'header.php'; 
?>

<h2>Manajemen Hak Akses Aplikasi</h2>
<?= $pesan; ?>

<div class="forms-wrapper justify-center">
    <div class="form-panel panel-manual <?= $edit_mode ? 'mode-edit' : '' ?>" id="formManual" style="max-width: 800px; margin: 0 auto;">
        <h3><?= $edit_mode ? '✏️ Edit Data Pengguna' : '➕ Tambah Pengguna Baru' ?></h3>
        
        <form action="kelola_operator.php" method="POST">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id_user" value="<?= $edit_data['id_user'] ?>">
                <input type="hidden" name="old_username" value="<?= $edit_data['username'] ?>">
            <?php endif; ?>

            <div class="form-row">
                <div class="form-col-1">
                    <label>Nama Lengkap</label>
                    <input type="text" name="nama_lengkap" class="form-control input-nama" required 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['nama_lengkap']) : '' ?>"
                           placeholder="Nama asli petugas..." autocomplete="off">
                </div>
                <div class="form-col-1">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required autocomplete="off" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['username']) : '' ?>" 
                           placeholder="Ketik username tanpa spasi...">
                </div>
            </div>

            <div class="form-row">
                <div class="form-col-1">
                    <label>Otoritas (Role)</label>
                    <select name="role" class="form-control" required>
                        <option value="operator" <?= ($edit_mode && $edit_data['role'] === 'operator') ? 'selected' : '' ?>>Operator Biasa</option>
                        <option value="super-admin" <?= ($edit_mode && $edit_data['role'] === 'super-admin') ? 'selected' : '' ?>>Super-Admin</option>
                    </select>
                </div>
                <div class="form-col-1">
                    <label>Password Akun <?= $edit_mode ? '<small style="color:#d9534f;">(Kosongkan jika tidak diubah)</small>' : '' ?></label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password"
                           <?= $edit_mode ? '' : 'required' ?> placeholder="<?= $edit_mode ? 'Ketik password baru...' : 'Wajib diisi...' ?>">
                </div>
            </div>

            <?php if ($edit_mode): ?>
                <div class="form-row mt-10">
                    <button type="submit" name="update_user" class="btn btn-info form-col-2">💾 Simpan Perubahan</button>
                    <a href="kelola_operator.php" class="btn btn-red form-col-1" style="display:flex; justify-content:center; align-items:center; text-decoration:none;">❌ Batal Edit</a>
                </div>
            <?php else: ?>
                <button type="submit" name="submit_user" class="btn btn-green btn-full mt-10">➕ Simpan Pengguna</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<h2>Daftar Pengguna Sistem</h2>
<div class="search-box">
    <form action="kelola_operator.php" method="GET" class="form-row" style="width: 100%;">
        <input type="text" name="search" placeholder="Cari Nama atau Username..." value="<?= htmlspecialchars($search); ?>" class="form-control form-col-1">
        <button type="submit" class="btn btn-blue">🔍 Cari Data</button>
        <?php if ($search !== ''): ?><a href="kelola_operator.php" class="btn btn-red" style="text-decoration:none;">Reset</a><?php endif; ?>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th width="5%" class="text-center">No</th>
            <th width="25%">Nama Lengkap</th>
            <th width="20%">Username</th>
            <th width="20%" class="text-center">Role / Otoritas</th>
            <th width="15%" class="text-center">Tanggal Dibuat</th>
            <th width="15%" class="text-center">Aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($data_users) > 0): ?>
            <?php $no = $offset + 1; foreach ($data_users as $row): ?>
                <tr>
                    <td class="text-center"><?= $no++; ?></td>
                    <td class="input-nama"><?= htmlspecialchars($row['nama_lengkap']); ?></td>
                    <td><strong class="text-purple"><?= htmlspecialchars($row['username']); ?></strong></td>
                    <td class="text-center">
                        <span class="badge" style="background-color: <?= $row['role'] === 'super-admin' ? '#17a2b8' : '#6c757d' ?>; color: white;">
                            <?= strtoupper($row['role']); ?>
                        </span>
                    </td>
                    <td class="text-center text-muted" style="font-size: 13px;">
                        <?= date('d M Y, H:i', strtotime($row['created_at'])); ?>
                    </td>
                    <td class="text-center">
                        <a href="?edit=<?= $row['id_user'] ?>#formManual" class="btn btn-edit">✏️</a>
                        <?php if (isset($_SESSION['user_id']) && $row['id_user'] !== (int)$_SESSION['user_id']): ?>
                            <a href="?hapus=<?= $row['id_user'] ?>" class="btn btn-red" onclick="return confirm('Hapus akses operator ini secara permanen?');">🗑️</a>
                        <?php else: ?>
                            <span style="font-size: 11px; color: #999;">(Anda)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="6" class="text-center">Belum ada data pengguna.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?><a href="?page=1<?= $search ? '&search='.urlencode($search) : '' ?>">&laquo;&laquo; First</a><?php endif; ?>
    <?php if ($page > 1): ?><a href="?page=<?= $page - 1; ?><?= $search ? '&search='.urlencode($search) : '' ?>">&laquo; Prev</a><?php endif; ?>
    <?php 
    $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2);
    if ($startPage == 1) { $endPage = min(5, $totalPages); } else if ($endPage == $totalPages) { $startPage = max(1, $totalPages - 4); }
    for ($i = $startPage; $i <= $endPage; $i++): 
    ?>
        <a href="?page=<?= $i; ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="<?= ($page == $i) ? 'active' : ''; ?>"><?= $i; ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1; ?><?= $search ? '&search='.urlencode($search) : '' ?>">Next &raquo;</a><?php endif; ?>
    <?php if ($page < $totalPages): ?><a href="?page=<?= $totalPages; ?><?= $search ? '&search='.urlencode($search) : '' ?>">Last &raquo;&raquo;</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="info-data">
    <?php if ($totalRows > 0): ?>
        Menampilkan rentang data ke <strong><?= $offset + 1; ?> - <?= min($offset + $limit, $totalRows); ?></strong> dari total <strong><?= $totalRows; ?></strong> pengguna.
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>