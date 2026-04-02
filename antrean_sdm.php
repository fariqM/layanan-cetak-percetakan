<?php
// File: antrean_sdm.php
// Deskripsi: Form Lapor Insiden & Input Antrean Cetak SDM (Dengan Upload Foto)

require_once 'auth.php';
require_once 'koneksi.php';
require_once 'koneksi_rfid.php';

$pesan = "";
$data_pencarian = null;
$direktori_foto = "assets/sdm/"; 

$username_login = $_SESSION['username']; 
$role_login = $_SESSION['role']; 

// Pastikan folder penyimpanan ada
if (!file_exists($direktori_foto)) {
    mkdir($direktori_foto, 0777, true);
}

// =========================================================================
// 1. LOGIKA HAPUS TRANSAKSI (HANYA SUPER-ADMIN)
// =========================================================================
if (isset($_GET['hapus'])) {
    if ($role_login === 'super-admin') {
        $id_cetak_hapus = (int)$_GET['hapus'];
        $stmt = $pdo->prepare("DELETE FROM cetak_sdm WHERE id_cetak = :id_cetak");
        if ($stmt->execute([':id_cetak' => $id_cetak_hapus])) {
            $pesan = "<div class='alert success'>Riwayat transaksi cetak SDM berhasil dihapus.</div>";
        } else {
            $pesan = "<div class='alert error'>Gagal menghapus riwayat transaksi.</div>";
        }
    } else {
        $pesan = "<div class='alert error'>⛔ Akses Ditolak: Hanya Super-Admin yang dapat menghapus data.</div>";
    }
}

// =========================================================================
// 2. LOGIKA PENCARIAN SDM (BERDASARKAN NIP/NIK/ID)
// =========================================================================
if (isset($_GET['cari_nip']) && trim($_GET['cari_nip']) !== '') {
    $nip_cari = trim($_GET['cari_nip']);
    
    $stmtCari = $pdo->prepare("SELECT nip, nama, kategori, jabatan, unit_kerja FROM sdm WHERE nip = :nip LIMIT 1");
    $stmtCari->execute([':nip' => $nip_cari]);
    $data_pencarian = $stmtCari->fetch(PDO::FETCH_ASSOC);

    if (!$data_pencarian) {
        $pesan = "<div class='alert error'>❌ <b>Data tidak ditemukan!</b><br>SDM dengan NIP/ID <strong>" . htmlspecialchars($nip_cari) . "</strong> tidak terdaftar di Master Data.</div>";
    }
}

// =========================================================================
// 3. LOGIKA INPUT ANTREAN, UPLOAD FOTO & AUTO-CLEARANCE GATE
// =========================================================================
if (isset($_POST['submit_antrean'])) {
    $nip = trim($_POST['nip']);
    $kriteria = trim($_POST['kriteria']);
    $nama_sdm = trim($_POST['nama_sdm']);
    
    $stmtCek = $pdo->prepare("SELECT id_cetak FROM cetak_sdm WHERE nip = :nip AND status_cetak = 'Belum'");
    $stmtCek->execute([':nip' => $nip]);
    
    if ($stmtCek->rowCount() > 0) {
        $pesan = "<div class='alert error'>⚠️ <b>Pendaftaran Ditolak:</b><br>NIP/ID <strong>$nip</strong> saat ini masih berada dalam antrean Menunggu Cetak.</div>";
    } else {
        $uploadOk = true;
        
        if (isset($_FILES['foto_sdm']) && $_FILES['foto_sdm']['error'] == 0) {
            $imageFileType = strtolower(pathinfo($_FILES["foto_sdm"]["name"], PATHINFO_EXTENSION));
            $target_file = $direktori_foto . $nip . ".jpg"; 
            
            $check = getimagesize($_FILES["foto_sdm"]["tmp_name"]);
            if ($check === false) {
                $pesan = "<div class='alert error'>❌ File yang diunggah bukan gambar yang valid.</div>"; $uploadOk = false;
            }
            elseif ($_FILES["foto_sdm"]["size"] > 2000000) {
                $pesan = "<div class='alert error'>❌ Ukuran foto terlalu besar. Maksimal 2MB.</div>"; $uploadOk = false;
            }
            elseif($imageFileType != "jpg" && $imageFileType != "jpeg" && $imageFileType != "png") {
                $pesan = "<div class='alert error'>❌ Format foto harus JPG, JPEG, atau PNG.</div>"; $uploadOk = false;
            }
            else {
                if ($imageFileType == 'png') {
                    $image = imagecreatefrompng($_FILES["foto_sdm"]["tmp_name"]);
                    $bg = imagecreatetruecolor(imagesx($image), imagesy($image));
                    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                    imagealphablending($bg, TRUE);
                    imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                    imagejpeg($bg, $target_file, 90);
                    imagedestroy($image);
                    imagedestroy($bg);
                } else {
                    move_uploaded_file($_FILES["foto_sdm"]["tmp_name"], $target_file);
                }
            }
        } else {
            if (!file_exists($direktori_foto . $nip . ".jpg")) {
                $pesan = "<div class='alert error'>❌ <b>Foto Diperlukan!</b> SDM ini belum memiliki pas foto di server. Wajib mengunggah foto.</div>";
                $uploadOk = false;
            }
        }

        if ($uploadOk) {
            try {
                $pdo->beginTransaction();
                $pdo_rfid->beginTransaction();

                if ($kriteria === 'Cetak Hilang' || $kriteria === 'Cetak Rusak') {
                    $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
                    $stmtDelGate->execute([':nip' => $nip]);
                }

                $stmtInsert = $pdo->prepare("INSERT INTO cetak_sdm (nip, kriteria, status_cetak, created_by, created_at) VALUES (:nip, :kriteria, 'Belum', :operator, NOW())");
                $stmtInsert->execute([':nip' => $nip, ':kriteria' => $kriteria, ':operator' => $username_login]);
                
                $pdo_rfid->commit();
                $pdo->commit();

                $pesan = "<div class='alert success'>✅ <b>Berhasil!</b><br>Data <strong>" . htmlspecialchars($nama_sdm) . "</strong> telah masuk antrean cetak.</div>";
                $data_pencarian = null; 

            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); }
                $pesan = "<div class='alert error'>❌ <b>Terjadi Kesalahan Sistem:</b><br>" . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

// =========================================================================
// 4. LOGIKA PENCARIAN & TAMPIL DATA RIWAYAT (DENGAN PAGINATION)
// =========================================================================
$limit = 5; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$countSql = "SELECT COUNT(*) FROM cetak_sdm c JOIN sdm s ON c.nip = s.nip";
if ($search !== '') {
    $countSql .= " WHERE c.nip LIKE :search OR s.nama LIKE :search";
}
$countStmt = $pdo->prepare($countSql);
if ($search !== '') { $countStmt->bindValue(':search', "%$search%"); }
$countStmt->execute();
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT c.*, s.nama, s.kategori, s.jabatan, s.unit_kerja 
        FROM cetak_sdm c 
        JOIN sdm s ON c.nip = s.nip";
if ($search !== '') {
    $sql .= " WHERE c.nip LIKE :search OR s.nama LIKE :search";
}
$sql .= " ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
if ($search !== '') { $stmt->bindValue(':search', "%$search%"); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$riwayat_cetak = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Lapor Insiden SDM";
include 'header.php';
?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; color: #28a745;">Lapor Insiden & Input Antrean SDM</h2>
            <a href="cetak_sdm.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none;">➡️ Pergi ke Papan Cetak</a>
        </div>
        
        <?= $pesan; ?>

        <div style="display: flex; gap: 30px; align-items: flex-start; margin-bottom: 40px;">
            <div style="flex: 1; border-top: 4px solid #28a745; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                <h3 style="color: #28a745; margin-top: 0; border-bottom: 2px solid #e8f5e9; padding-bottom: 10px;">🔍 Cari Data SDM</h3>
                <form action="antrean_sdm.php" method="GET">
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; font-size: 14px; display: block; margin-bottom: 5px;">NIP / NIK / ID Custom</label>
                        <input type="text" name="cari_nip" class="form-control" placeholder="Ketik NIP / ID lalu Enter..." value="<?= htmlspecialchars($_GET['cari_nip'] ?? '') ?>" required autofocus>
                    </div>
                    <button type="submit" class="btn" style="background-color: #28a745; color: white; width: 100%; padding: 12px; font-weight: bold;">Cari Data</button>
                    <?php if (isset($_GET['cari_nip'])): ?>
                        <a href="antrean_sdm.php" class="btn btn-outline" style="display: block; text-align: center; width: 100%; padding: 10px; margin-top: 10px; box-sizing: border-box; color: #dc3545; text-decoration: none;">Reset Form</a>
                    <?php endif; ?>
                </form>
            </div>

            <div style="flex: 1.5; border-top: 4px solid #343a40; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                <h3 style="color: #343a40; margin-top: 0; border-bottom: 2px solid #f8f9fa; padding-bottom: 10px;">📋 Formulir Pengajuan & Upload Foto</h3>
                
                <?php if ($data_pencarian): 
                    $foto_tersedia = file_exists($direktori_foto . $data_pencarian['nip'] . ".jpg");
                ?>
                    <div style="display: flex; gap: 20px; background: #f8fff9; padding: 15px; border: 1px solid #c8e6c9; border-radius: 6px; margin-bottom: 20px;">
                        <div style="width: 90px; height: 120px; border: 2px dashed #a5d6a7; border-radius: 4px; overflow: hidden; background: #e8f5e9; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <?php if ($foto_tersedia): ?>
                                <img src="<?= $direktori_foto . $data_pencarian['nip'] . ".jpg?" . time() ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span style="font-size: 30px; color: #a5d6a7;">📷</span>
                            <?php endif; ?>
                        </div>
                        
                        <div style="flex: 1;">
                            <table style="width: 100%; border: none; margin: 0; box-shadow: none;">
                                <tr style="background: transparent;"><td style="width: 100px; border: none; padding: 2px 0; color: #555;">NIP / ID</td><td style="border: none; padding: 2px 0; font-weight: bold;">: <?= htmlspecialchars($data_pencarian['nip']); ?></td></tr>
                                <tr style="background: transparent;"><td style="border: none; padding: 2px 0; color: #555;">Nama Lengkap</td><td style="border: none; padding: 2px 0; font-weight: bold;">: <?= htmlspecialchars($data_pencarian['nama']); ?></td></tr>
                                <tr style="background: transparent;"><td style="border: none; padding: 2px 0; color: #555;">Kategori</td><td style="border: none; padding: 2px 0;">: <span class="badge" style="background: #28a745;"><?= htmlspecialchars($data_pencarian['kategori']); ?></span></td></tr>
                                <tr style="background: transparent;"><td style="border: none; padding: 2px 0; color: #555;">Jabatan</td><td style="border: none; padding: 2px 0;">: <?= htmlspecialchars($data_pencarian['jabatan'] ?? '-'); ?></td></tr>
                            </table>
                        </div>
                    </div>

                    <form action="antrean_sdm.php" method="POST" enctype="multipart/form-data" onsubmit="return confirm('Proses data ini ke antrean?');">
                        <input type="hidden" name="nip" value="<?= htmlspecialchars($data_pencarian['nip']); ?>">
                        <input type="hidden" name="nama_sdm" value="<?= htmlspecialchars($data_pencarian['nama']); ?>">
                        
                        <div style="margin-bottom: 15px;">
                            <label style="font-weight: bold; font-size: 14px; display: block; margin-bottom: 5px;">Upload Pas Foto (Rasio Portrait 3x4 / 4x6)</label>
                            <input type="file" name="foto_sdm" accept="image/jpeg, image/png" class="form-control" style="padding: 8px; border: 2px dashed #28a745; background: #f8f9fa;" <?= $foto_tersedia ? '' : 'required' ?>>
                            <?php if ($foto_tersedia): ?>
                                <small style="color: #28a745; font-weight: bold; display: block; margin-top: 5px;">✔️ Foto sudah ada. Upload baru hanya jika ingin mengganti foto.</small>
                            <?php else: ?>
                                <small style="color: #dc3545; font-weight: bold; display: block; margin-top: 5px;">⚠️ Wajib unggah foto (JPG/PNG, Maks. 2MB).</small>
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="font-weight: bold; font-size: 14px; display: block; margin-bottom: 5px;">Kriteria Cetak</label>
                            <select name="kriteria" class="form-control" required style="padding: 10px; font-size: 15px;">
                                <option value="" disabled selected>-- Pilih Kriteria --</option>
                                <option value="Cetak Baru">Cetak Baru</option>
                                <option value="Cetak Hilang">Lapor Hilang (Cabut Akses Gate Lama)</option>
                                <option value="Cetak Rusak">Lapor Rusak (Cabut Akses Gate Lama)</option>
                            </select>
                        </div>

                        <button type="submit" name="submit_antrean" class="btn" style="background-color: #28a745; color: white; width: 100%; padding: 14px; font-size: 15px; font-weight: bold;">
                            📥 Daftarkan ke Antrean Cetak
                        </button>
                    </form>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px; color: #6c757d;">
                        <div style="font-size: 45px; margin-bottom: 10px;">📸</div>
                        <h4 style="margin: 0 0 5px 0; color: #495057;">Pilih Data Terlebih Dahulu</h4>
                        <p style="margin: 0; font-size: 14px;">Formulir unggah foto dan pendaftaran antrean<br>akan muncul setelah data ditemukan.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <h2>Daftar Riwayat Transaksi Cetak SDM</h2>

        <div class="search-box">
            <form action="antrean_sdm.php" method="GET" style="margin: 0; display: flex; gap: 10px; width: 100%;">
                <input type="text" name="search" placeholder="Cari berdasarkan NIP atau Nama..." value="<?= htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-blue" style="width: auto; background-color: #28a745;">🔍 Cari</button>
                <?php if ($search !== ''): ?>
                    <a href="antrean_sdm.php" class="btn btn-red" style="padding: 10px 15px; font-size: 14px; text-decoration: none;">Reset Filter</a>
                <?php endif; ?>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="4%" style="text-align: center;">No</th>
                    <th width="10%">NIP/ID</th>
                    <th width="22%">Nama Pegawai</th>
                    <th width="17%">Kategori</th>
                    <th width="13%" style="text-align: center;">Waktu Transaksi</th>
                    <th width="12%" style="text-align: center;">Kriteria</th>
                    <th width="7%" style="text-align: center;">Cetak</th>
                    <?php if ($role_login === 'super-admin'): ?>
                        <th width="15%" style="text-align: center;">Operator</th>
                        <th width="12%" style="text-align: center;">Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (count($riwayat_cetak) > 0): ?>
                    <?php 
                    $no = $offset + 1; 
                    foreach ($riwayat_cetak as $row): 
                        $waktu = date('d M Y, H:i', strtotime($row['created_at']));
                        $badgeCetak = ($row['status_cetak'] === 'Sudah') ? 'bg-sudah' : 'bg-belum';
                    ?>
                        <tr>
                            <td style="text-align: center;"><?= $no++; ?></td>
                            <td><strong><?= htmlspecialchars($row['nip']); ?></strong></td>
                            <td style="text-transform: capitalize;"><?= htmlspecialchars($row['nama']); ?></td>
                            <td>
                                <span style="font-size: 12px; color: #555;"><?= htmlspecialchars($row['jabatan'] ?? '-'); ?></span>
                            </td>
                            <td style="text-align: center; color: #555; font-size: 12px;"><?= $waktu; ?></td>
                            <td style="text-align: center;">
                                <span class="badge" style="background-color: <?= ($row['kriteria'] == 'Cetak Baru') ? '#28a745' : (($row['kriteria'] == 'Cetak Rusak') ? '#ffc107' : '#dc3545') ?>;">
                                    <?= $row['kriteria']; ?>
                                </span>
                            </td>
                            <td style="text-align: center;"><span class="badge <?= $badgeCetak; ?>"><?= $row['status_cetak'] ?? 'Belum'; ?></span></td>
                            
                            <?php if ($role_login === 'super-admin'): ?>
                                <td style="font-size: 11.5px; line-height: 1.5; color: #555;">
                                    <div>📥 Entry &nbsp;: <strong><?= htmlspecialchars($row['created_by'] ?? '-'); ?></strong></div>
                                    <div style="color: <?= !empty($row['printed_by']) ? '#007bff' : '#ccc' ?>;">
                                        🖨️ Cetak &nbsp;: <strong><?= htmlspecialchars($row['printed_by'] ?? '-'); ?></strong>
                                    </div>
                                    <div style="color: <?= !empty($row['rfid_assigned_by']) ? '#28a745' : '#ccc' ?>;">
                                        📡 Assign : <strong><?= htmlspecialchars($row['rfid_assigned_by'] ?? '-'); ?></strong>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <a href="?hapus=<?= $row['id_cetak']; ?>" class="btn btn-red" onclick="return confirm('Hapus riwayat cetak ini?')">🗑️ Hapus</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?= ($role_login === 'super-admin') ? '9' : '7' ?>" style="text-align: center; padding: 20px; color: #666;">Data tidak ditemukan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="?page=1<?= $search ? '&search='.urlencode($search) : '' ?>">&laquo;&laquo; First</a><?php endif; ?>
                <?php if ($page > 1): ?><a href="?page=<?= $page - 1; ?><?= $search ? '&search='.urlencode($search) : '' ?>">&laquo; Prev</a><?php endif; ?>
                
                <?php 
                $startPage = max(1, $page - 2); 
                $endPage = min($totalPages, $page + 2);
                if ($startPage == 1) { 
                    $endPage = min(5, $totalPages); 
                } else if ($endPage == $totalPages) { 
                    $startPage = max(1, $totalPages - 4); 
                }
                
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                    <a href="?page=<?= $i; ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="<?= ($page == $i) ? 'active' : ''; ?>" <?= ($page == $i) ? 'style="background-color:#28a745; border-color:#28a745;"' : '' ?>><?= $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1; ?><?= $search ? '&search='.urlencode($search) : '' ?>">Next &raquo;</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a href="?page=<?= $totalPages; ?><?= $search ? '&search='.urlencode($search) : '' ?>">Last &raquo;&raquo;</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="info-data">
            Menampilkan data <?= $totalRows > 0 ? ($offset + 1) . " - " . min($offset + $limit, $totalRows) : "0" ?> dari total <?= $totalRows; ?> riwayat transaksi SDM.
        </div>

<?php include 'footer.php'; ?>