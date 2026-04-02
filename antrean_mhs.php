<?php
/**
 * File: antrean_mhs.php
 * Deskripsi: Kelola transaksi cetak KTM (Berelasi dengan Master Mahasiswa)
 * Fitur: RBAC, Audit Trail, Validasi Foreign Key, AJAX Auto-Retrieve, Smart Photo Selector
 */

// Wajib ada paling atas untuk mengecek tiket login
require_once 'auth.php'; 

// =========================================================================
// API ENDPOINT 1: AUTO-RETRIEVE DATA MAHASISWA VIA AJAX
// =========================================================================
if (isset($_GET['ajax_nim'])) {
    require_once 'koneksi.php';
    $nim_cari = trim($_GET['ajax_nim']);
    $stmtAjax = $pdo->prepare("SELECT nama, fakultas, prodi FROM mahasiswa WHERE nim = :nim LIMIT 1");
    $stmtAjax->execute([':nim' => $nim_cari]);
    $dataMhs = $stmtAjax->fetch(PDO::FETCH_ASSOC);
    
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    if ($dataMhs) { echo json_encode($dataMhs); } else { echo json_encode(['error' => 'not_found']); }
    exit; 
}

// =========================================================================
// API ENDPOINT 2: SIMPAN PILIHAN LOKASI FOTO (UTAMA / THUMB)
// =========================================================================
if (isset($_POST['simpan_lokasi_foto'])) {
    require_once 'koneksi.php';
    $nim = trim($_POST['nim']);
    $lokasi = trim($_POST['lokasi']); // Berisi 'utama' atau 'thumb'
    
    try {
        $stmtUpdateFoto = $pdo->prepare("UPDATE mahasiswa SET lokasi_foto = :lokasi WHERE nim = :nim");
        $stmtUpdateFoto->execute([':lokasi' => $lokasi, ':nim' => $nim]);
        if (ob_get_length()) { ob_clean(); }
        echo json_encode(['status' => 'success']);
    } catch (PDOException $e) {
        if (ob_get_length()) { ob_clean(); }
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// Panggil file koneksi database UTAMA & KEDUA
require_once 'koneksi.php';
require_once 'koneksi_rfid.php';

$pesan = "";
$username_login = $_SESSION['username']; 
$role_login = $_SESSION['role']; 

// =========================================================================
// 1. LOGIKA HAPUS TRANSAKSI (HANYA SUPER-ADMIN)
// =========================================================================
if (isset($_GET['hapus'])) {
    if ($role_login === 'super-admin') {
        $id_cetak_hapus = (int)$_GET['hapus'];
        $stmt = $pdo->prepare("DELETE FROM cetak_ktm WHERE id_cetak = :id_cetak");
        if ($stmt->execute([':id_cetak' => $id_cetak_hapus])) {
            $pesan = "<div class='alert success'>Riwayat transaksi cetak berhasil dihapus.</div>";
        } else {
            $pesan = "<div class='alert error'>Gagal menghapus riwayat transaksi.</div>";
        }
    } else {
        $pesan = "<div class='alert error'>⛔ Akses Ditolak: Hanya Super-Admin yang dapat menghapus data.</div>";
    }
}

// =========================================================================
// 2. LOGIKA UNGGAH DATA CSV (DENGAN BLOKIR INSTAN & CEK ANTREAN)
// =========================================================================
if (isset($_POST["submit_csv"])) {
    if (isset($_FILES["file_csv"]) && $_FILES["file_csv"]["error"] == 0) {
        $fileTmp = $_FILES["file_csv"]["tmp_name"];
        $fileExt = strtolower(pathinfo($_FILES["file_csv"]["name"], PATHINFO_EXTENSION));
        
        if ($fileExt === "csv") {
            $fileContent = file_get_contents($fileTmp);
            $fileContent = str_replace(["\r\n", "\r"], "\n", $fileContent);
            $lines = explode("\n", $fileContent);
            
            $row = 1; $berhasil = 0; $gagal_nim = 0; $gagal_antrean = 0;
            
            $cekNimStmt = $pdo->prepare("SELECT nim FROM mahasiswa WHERE nim = :nim LIMIT 1");
            $cekAntreanStmt = $pdo->prepare("SELECT id_cetak FROM cetak_ktm WHERE nim = :nim AND status_cetak = 'Belum' LIMIT 1");
            $stmtCetak = $pdo->prepare("INSERT INTO cetak_ktm (nim, kriteria, status_cetak, created_by) VALUES (:nim, :kriteria, 'Belum', :operator)");
            $stmtBlokir = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue; 
                
                $data = str_getcsv($line, ",");
                if ($row == 1) { $row++; continue; } 
                
                if (count($data) >= 2) {
                    $nim = trim($data[0]);
                    $kriteria_csv = strtolower(trim($data[1]));
                    
                    $kriteria_db = '';
                    if ($kriteria_csv === 'maba') { $kriteria_db = 'Cetak Baru'; } 
                    elseif ($kriteria_csv === 'hilang') { $kriteria_db = 'Cetak Hilang'; } 
                    elseif ($kriteria_csv === 'rusak') { $kriteria_db = 'Cetak Rusak'; } 
                    else { continue; }
                    
                    $cekNimStmt->execute([':nim' => $nim]);
                    if ($cekNimStmt->fetch()) {
                        
                        $cekAntreanStmt->execute([':nim' => $nim]);
                        if ($cekAntreanStmt->fetch()) {
                            $gagal_antrean++; 
                            continue; 
                        }

                        try {
                            $pdo->beginTransaction();
                            $stmtCetak->execute([':nim' => $nim, ':kriteria' => $kriteria_db, ':operator' => $username_login]);
                            
                            if ($kriteria_db === 'Cetak Hilang' || $kriteria_db === 'Cetak Rusak') {
                                $stmtBlokir->execute([':nip' => $nim]);
                            }

                            $pdo->commit();
                            $berhasil++;
                        } catch (PDOException $e) { 
                            if ($pdo->inTransaction()) { $pdo->rollBack(); }
                            continue; 
                        }
                    } else {
                        $gagal_nim++; 
                    }
                }
                $row++;
            }
            
            $pesan = "<div class='alert success'>✅ Proses CSV selesai! <strong>$berhasil</strong> antrean dicatat dan keamanan Gate disesuaikan.</div>";
            
            if ($gagal_nim > 0 || $gagal_antrean > 0) {
                $pesan .= "<div class='alert error' style='margin-top:-15px; background-color:#fff3cd; color:#856404; border-color:#ffeeba;'>";
                $pesan .= "⚠️ <b>Laporan Penolakan Data (Skipped):</b><br>";
                if ($gagal_nim > 0) { $pesan .= "- <strong>$gagal_nim</strong> baris ditolak karena NIM tidak ditemukan di Master Data.<br>"; }
                if ($gagal_antrean > 0) { $pesan .= "- <strong>$gagal_antrean</strong> baris ditolak karena mahasiswa tersebut masih memiliki antrean aktif (Double Queue)."; }
                $pesan .= "</div>";
            }
            
        } else { 
            $pesan = "<div class='alert error'>❌ Format tidak valid. Harap unggah file .csv.</div>"; 
        }
    }
}

// =========================================================================
// 3. LOGIKA CATAT TRANSAKSI MANUAL (DENGAN BLOKIR INSTAN & CEK ANTREAN)
// =========================================================================
if (isset($_POST["submit_manual"])) {
    $nim = trim($_POST['nim']);
    $kriteria = trim($_POST['kriteria']);

    if (!empty($nim) && !empty($kriteria)) {
        $cekNimStmt = $pdo->prepare("SELECT nim FROM mahasiswa WHERE nim = :nim LIMIT 1");
        $cekNimStmt->execute([':nim' => $nim]);
        
        if ($cekNimStmt->fetch()) {
            $cekAntreanStmt = $pdo->prepare("SELECT id_cetak FROM cetak_ktm WHERE nim = :nim AND status_cetak = 'Belum' LIMIT 1");
            $cekAntreanStmt->execute([':nim' => $nim]);
            
            if ($cekAntreanStmt->fetch()) {
                $pesan = "<div class='alert error'>❌ Penolakan Sistem: Mahasiswa dengan NIM <strong>$nim</strong> masih memiliki antrean cetak yang belum diselesaikan. Harap selesaikan atau batalkan antrean sebelumnya.</div>";
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmtCetak = $pdo->prepare("INSERT INTO cetak_ktm (nim, kriteria, status_cetak, created_by) VALUES (:nim, :kriteria, 'Belum', :operator)");
                    $stmtCetak->execute([':nim' => $nim, ':kriteria' => $kriteria, ':operator' => $username_login]);
                    
                    if ($kriteria === 'Cetak Hilang' || $kriteria === 'Cetak Rusak') {
                        $stmtBlokir = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
                        $stmtBlokir->execute([':nip' => $nim]);
                    }

                    $pdo->commit();
                    $pesan = "<div class='alert success'>✅ Transaksi <strong>" . htmlspecialchars($kriteria) . "</strong> untuk NIM $nim berhasil dicatat. ";
                    if ($kriteria === 'Cetak Hilang' || $kriteria === 'Cetak Rusak') {
                        $pesan .= "<br>🔒 <b>Sistem Keamanan Aktif:</b> Akses kartu lama ke Gate telah diblokir secara otomatis!</div>";
                    } else {
                        $pesan .= "</div>";
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $pesan = "<div class='alert error'>❌ Gagal mencatat transaksi sistem: " . $e->getMessage() . "</div>";
                }
            }
        } else {
            $pesan = "<div class='alert error'>❌ Gagal! Mahasiswa dengan NIM <strong>$nim</strong> tidak ditemukan di Master Data.</div>";
        }
    }
}

// =========================================================================
// 4. LOGIKA UPDATE TRANSAKSI (HANYA SUPER-ADMIN)
// =========================================================================
if (isset($_POST["update_manual"])) {
    if ($role_login === 'super-admin') {
        $id_cetak = (int)$_POST['id_cetak'];
        $nim = trim($_POST['nim']); 
        $kriteria = trim($_POST['kriteria']);

        if (!empty($nim)) {
            $cekNimStmt = $pdo->prepare("SELECT nim FROM mahasiswa WHERE nim = :nim LIMIT 1");
            $cekNimStmt->execute([':nim' => $nim]);
            
            if ($cekNimStmt->fetch()) {
                try {
                    $stmtCetak = $pdo->prepare("UPDATE cetak_ktm SET nim = :nim, kriteria = :kriteria WHERE id_cetak = :id_cetak");
                    $stmtCetak->execute([':nim' => $nim, ':kriteria' => $kriteria, ':id_cetak' => $id_cetak]);
                    $pesan = "<div class='alert success'>Data transaksi berhasil diperbarui oleh Super-Admin.</div>";
                } catch (PDOException $e) {
                    $pesan = "<div class='alert error'>Gagal update transaksi.</div>";
                }
            } else {
                $pesan = "<div class='alert error'>❌ Update Gagal! NIM $nim tidak terdaftar di Master Data.</div>";
            }
        }
    } else {
        $pesan = "<div class='alert error'>⛔ Akses Ditolak: Hanya Super-Admin yang dapat mengubah data.</div>";
    }
}

$edit_mode = false;
$edit_data = null;
if (isset($_GET['edit']) && $role_login === 'super-admin') {
    $edit_mode = true;
    $stmt = $pdo->prepare("SELECT c.id_cetak, c.kriteria, m.nim, m.nama, m.fakultas, m.prodi FROM cetak_ktm c JOIN mahasiswa m ON c.nim = m.nim WHERE c.id_cetak = :id_cetak");
    $stmt->execute([':id_cetak' => $_GET['edit']]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_data) { $edit_mode = false; }
}

// =========================================================================
// 5. LOGIKA PENCARIAN & TAMPIL DATA 
// =========================================================================
$limit = 5; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$countSql = "SELECT COUNT(*) FROM cetak_ktm c JOIN mahasiswa m ON c.nim = m.nim";
if ($search !== '') {
    $countSql .= " WHERE c.nim LIKE :search OR m.nama LIKE :search";
}
$countStmt = $pdo->prepare($countSql);
if ($search !== '') { $countStmt->bindValue(':search', "%$search%"); }
$countStmt->execute();
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT c.*, m.nama, m.fakultas, m.prodi 
        FROM cetak_ktm c 
        JOIN mahasiswa m ON c.nim = m.nim";
if ($search !== '') {
    $sql .= " WHERE c.nim LIKE :search OR m.nama LIKE :search";
}
$sql .= " ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
if ($search !== '') { $stmt->bindValue(':search', "%$search%"); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$riwayat_cetak = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($riwayat_cetak as $index => $row) {
    $cek_gate = $pdo_rfid->prepare("SELECT nip FROM gate WHERE nip = :nim LIMIT 1");
    $cek_gate->execute([':nim' => $row['nim']]);
    $riwayat_cetak[$index]['status_binding'] = $cek_gate->fetch() ? 'Sudah' : 'Belum';
}
?>

<?php 
$page_title = "Kelola Data & Transaksi KTM";
include 'header.php'; 
?>

        <h2>Kelola Transaksi Cetak KTM</h2>
        <?= $pesan; ?>

        <div class="forms-wrapper">
            <div class="form-panel panel-foto">
                <h3>📸 Cek Kesesuaian Foto</h3>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" id="nim_cek" class="form-control" placeholder="Ketik NIM..." onkeypress="if(event.key === 'Enter') cekFoto();">
                    <button type="button" class="btn btn-yellow" onclick="cekFoto()">🔍 Cari</button>
                </div>
                <div id="hasil_foto_box"><span style="color: #999; font-size: 13px;">Pratinjau foto di sini</span></div>
            </div>

            <div class="form-panel panel-csv">
                <h3>📁 Unggah CSV (Transaksi Massal)</h3>
                
                <p style="font-size:13px; color:#666; margin-top:-10px; margin-bottom: 10px; white-space: pre-line;"><b>Kolom Wajib: NIM, Kriteria</b>
                Isi kolom Kriteria HANYA dengan: <b>maba</b>, <b>hilang</b>, atau <b>rusak</b>.
                Data identitas akan direlasikan otomatis. Pastikan NIM terdaftar di <b>Data Master Mahasiswa</b>.</p>
                
                <a href="data:text/csv;charset=utf-8,NIM,Kriteria%0A" download="template_transaksi_cetak.csv" class="btn btn-outline">
                    📥 Download Template CSV</a>
                
                <div><br></div>
                <form action="antrean_mhs.php" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <input type="file" name="file_csv" accept=".csv" required class="form-control" style="background: white; padding: 5px;">
                    </div>
                    <button type="submit" name="submit_csv" class="btn btn-blue btn-full">⬆️ Unggah & Catat Transaksi</button>
                </form>
            </div>

            <div class="form-panel panel-manual <?= $edit_mode ? 'mode-edit' : '' ?>" id="formManual">
                <h3><?= $edit_mode ? '✏️ Edit Riwayat Transaksi' : '✍️ Entry Transaksi Satuan' ?></h3>
                <p style="font-size:13px; color:#888; margin-top:-10px; margin-bottom:10px; white-space: pre-line;">Ketik NIM, data identitas akan otomatis ditarik dari Master Mahasiswa.</p>
                
                <form action="antrean_mhs.php" method="post">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="id_cetak" value="<?= htmlspecialchars($edit_data['id_cetak']); ?>">
                    <?php endif; ?>

                    <div style="display: flex; gap: 10px;">
                        <div class="form-group" style="flex: 1;">
                            <label>NIM Mahasiswa</label>
                            <input type="text" name="nim" id="nim_input" class="form-control" placeholder="Ketik NIM..." required 
                                   value="<?= $edit_mode ? htmlspecialchars($edit_data['nim']) : '' ?>" autocomplete="off" autofocus>
                        </div>
                        <div class="form-group" style="flex: 2;">
                            <label>Nama Lengkap</label>
                            <input type="text" id="nama_input" class="form-control" readonly 
                                   style="background-color: #e9ecef; cursor: not-allowed; color: #495057; font-weight:bold;" 
                                   value="<?= $edit_mode ? htmlspecialchars($edit_data['nama']) : '' ?>" placeholder="Menunggu input NIM...">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <div class="form-group" style="flex: 1;">
                            <label>Fakultas</label>
                            <input type="text" id="fakultas_input" class="form-control" readonly 
                                   style="background-color: #e9ecef; cursor: not-allowed; color: #495057;" 
                                   value="<?= $edit_mode ? htmlspecialchars($edit_data['fakultas']) : '' ?>" placeholder="-">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Program Studi</label>
                            <input type="text" id="prodi_input" class="form-control" readonly 
                                   style="background-color: #e9ecef; cursor: not-allowed; color: #495057;" 
                                   value="<?= $edit_mode ? htmlspecialchars($edit_data['prodi']) : '' ?>" placeholder="-">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label style="color: #dc3545;">Kriteria Cetak KTM</label>
                        <select name="kriteria" class="form-control" style="border-color: #dc3545; font-weight: bold;" required>
                            <option value="Cetak Baru" <?= ($edit_mode && $edit_data['kriteria'] === 'Cetak Baru') ? 'selected' : '' ?>>Cetak Baru (Maba)</option>
                            <option value="Cetak Hilang" <?= ($edit_mode && $edit_data['kriteria'] === 'Cetak Hilang') ? 'selected' : '' ?>>Cetak Hilang</option>
                            <option value="Cetak Rusak" <?= ($edit_mode && $edit_data['kriteria'] === 'Cetak Rusak') ? 'selected' : '' ?>>Cetak Rusak</option>
                        </select>
                    </div>
                    
                    <?php if ($edit_mode): ?>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" name="update_manual" id="btn_submit_manual" class="btn btn-info" style="flex: 2;">💾 Simpan Perubahan</button>
                            <a href="antrean_mhs.php" class="btn btn-red" style="flex: 1; display: flex; align-items: center; justify-content: center;">❌ Batal</a>
                        </div>
                    <?php else: ?>
                        <button type="submit" name="submit_manual" id="btn_submit_manual" class="btn btn-green btn-full" disabled style="opacity:0.5;">➕ Catat Transaksi</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <h2>Daftar Riwayat Transaksi Cetak</h2>

        <div class="search-box">
            <form action="antrean_mhs.php" method="GET" style="margin: 0; display: flex; gap: 10px; width: 100%;">
                <input type="text" name="search" placeholder="Cari berdasarkan NIM atau Nama..." value="<?= htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-blue" style="width: auto;">🔍 Cari</button>
                <?php if ($search !== ''): ?>
                    <a href="antrean_mhs.php" class="btn btn-red" style="padding: 10px 15px; font-size: 14px;">Reset Filter</a>
                <?php endif; ?>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="4%" style="text-align: center;">No</th>
                    <th width="10%">NIM</th>
                    <th width="18%">Nama Lengkap</th>
                    <th width="17%">Fakultas</th>
                    <th width="13%" style="text-align: center;">Waktu Transaksi</th>
                    <th width="10%" style="text-align: center;">Kriteria</th>
                    <th width="8%" style="text-align: center;">Cetak</th>
                    <?php if ($role_login === 'super-admin'): ?>
                        <th width="20%" style="text-align: center;">Operator</th>
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
                            <td><strong><?= htmlspecialchars($row['nim']); ?></strong></td>
                            <td style="text-transform: capitalize;"><?= htmlspecialchars($row['nama']); ?></td>
                            <td><?= htmlspecialchars($row['fakultas']); ?></td>
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
                                    <div style="color: <?= !empty($row['printed_by']) ? 'var(--primary-color)' : '#ccc' ?>;">
                                        🖨️ Cetak &nbsp;: <strong><?= htmlspecialchars($row['printed_by'] ?? '-'); ?></strong>
                                    </div>
                                    <div style="color: <?= !empty($row['rfid_assigned_by']) ? 'var(--success-color)' : '#ccc' ?>;">
                                        📡 Assign : <strong><?= htmlspecialchars($row['rfid_assigned_by'] ?? '-'); ?></strong>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <a href="?edit=<?= $row['id_cetak']; ?>&page=<?= $page; ?><?= $search ? '&search='.urlencode($search) : '' ?>#formManual" class="btn btn-edit">✏️</a>
                                    <a href="?hapus=<?= $row['id_cetak']; ?>" class="btn btn-red" onclick="return confirm('Hapus?')">🗑️</a>
                                </td>
                            <?php else: ?>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
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
                    <a href="?page=<?= $i; ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="<?= ($page == $i) ? 'active' : ''; ?>"><?= $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1; ?><?= $search ? '&search='.urlencode($search) : '' ?>">Next &raquo;</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a href="?page=<?= $totalPages; ?><?= $search ? '&search='.urlencode($search) : '' ?>">Last &raquo;&raquo;</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="info-data">
            Menampilkan data <?= $totalRows > 0 ? ($offset + 1) . " - " . min($offset + $limit, $totalRows) : "0" ?> dari total <?= $totalRows; ?> riwayat transaksi.
        </div>

<?php include 'footer.php'; ?>

<script>
// ==========================================================
// PENDETEKSI INPUT NIM OTOMATIS
// ==========================================================
document.addEventListener('DOMContentLoaded', function() {
    const nimInput = document.getElementById('nim_input');
    const namaInput = document.getElementById('nama_input');
    const fakultasInput = document.getElementById('fakultas_input');
    const prodiInput = document.getElementById('prodi_input');
    const btnSubmit = document.getElementById('btn_submit_manual');

    <?php if ($edit_mode): ?>
        btnSubmit.disabled = false;
        btnSubmit.style.opacity = '1';
    <?php endif; ?>

    if(nimInput) {
        nimInput.addEventListener('input', async function() {
            const nim = this.value.trim();
            
            if (nim.length >= 5) { 
                namaInput.value = 'Mencari data...';
                
                try {
                    const response = await fetch(`antrean_mhs.php?ajax_nim=${nim}`);
                    const data = await response.json();
                    
                    if (data && !data.error) {
                        namaInput.value = data.nama;
                        fakultasInput.value = data.fakultas;
                        prodiInput.value = data.prodi;
                        namaInput.style.color = '#198754'; 
                        
                        btnSubmit.disabled = false;
                        btnSubmit.style.opacity = '1';
                        btnSubmit.style.cursor = 'pointer';
                    } else {
                        namaInput.value = '❌ NIM Tidak Ditemukan di Master!';
                        fakultasInput.value = '-';
                        prodiInput.value = '-';
                        namaInput.style.color = '#dc3545'; 
                        
                        btnSubmit.disabled = true;
                        btnSubmit.style.opacity = '0.5';
                        btnSubmit.style.cursor = 'not-allowed';
                    }
                } catch(e) {
                    console.error('Error fetching data:', e);
                }
            } else {
                namaInput.value = '';
                fakultasInput.value = '';
                prodiInput.value = '';
                namaInput.style.color = '#495057';
                
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.5';
                btnSubmit.style.cursor = 'not-allowed';
            }
        });
    }
});

// ==========================================================
// FUNGSI CEK & BANDINGKAN FOTO MAHASISWA (MANUAL CHECKLIST)
// ==========================================================
// ==========================================================
// FUNGSI CEK & BANDINGKAN FOTO MAHASISWA (MANUAL CHECKLIST)
// ==========================================================
function cekFoto() {
    const nimInput = document.getElementById('nim_cek').value.trim();
    const hasilBox = document.getElementById('hasil_foto_box');

    if (nimInput === '') {
        hasilBox.innerHTML = '<span style="color: #dc3545; font-size: 13px; font-weight: bold;">❌ Harap ketik NIM terlebih dahulu.</span>';
        return;
    }

    // [PERBAIKAN] Tambahkan Timestamp untuk mencegah Cache Browser (Cache-Busting)
    const waktuSekarang = new Date().getTime();
    const urlUtama = `https://sinau.uinsa.ac.id/uploads/fotomhs/${nimInput}.jpg?v=${waktuSekarang}`;
    const urlThumb = `https://sinau.uinsa.ac.id/uploads/fotomhs/thumb/${nimInput}.jpg?v=${waktuSekarang}`;

    hasilBox.innerHTML = `
        <div style="margin-bottom: 12px; font-size: 13px; color: #555; text-align: center;">
            Bandingkan kedua sumber di bawah ini dan <b>pilih foto</b> yang benar:
        </div>
        <div style="display: flex; gap: 15px; justify-content: center; margin-bottom: 15px;">
            
            <div style="text-align: center; border: 2px solid #ccc; padding: 10px; border-radius: 8px; width: 45%; background: #f8f9fa;">
                <div style="font-size: 11px; font-weight: bold; color: #666; margin-bottom: 5px;">/thumb/</div>
                <img src="${urlThumb}" onerror="this.src='https://via.placeholder.com/150x200/cc0000/ffffff?text=Error'" style="width: 100%; height: 160px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 10px;">
                <label style="cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; font-weight: bold; font-size: 13px; color: #007bff;">
                    <input type="radio" name="pilihan_foto" value="thumb"> Pilih Ini
                </label>
            </div>
            
            <div style="text-align: center; border: 2px solid #ccc; padding: 10px; border-radius: 8px; width: 45%; background: #f8f9fa;">
                <div style="font-size: 11px; font-weight: bold; color: #666; margin-bottom: 5px;">/fotomhs/</div>
                <img src="${urlUtama}" onerror="this.src='https://via.placeholder.com/150x200/cc0000/ffffff?text=Error'" style="width: 100%; height: 160px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 10px;">
                <label style="cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; font-weight: bold; font-size: 13px; color: #28a745;">
                    <input type="radio" name="pilihan_foto" value="utama" checked> Pilih Ini
                </label>
            </div>

        </div>
        <div style="text-align: center;">
            <button type="button" onclick="simpanPilihanFoto('${nimInput}')" class="btn btn-blue" style="width: 100%; padding: 10px; font-weight: bold;">💾 Simpan Sumber Foto ke Master Data</button>
            <div id="notif_simpan_foto" style="margin-top: 8px; font-size: 12.5px; font-weight: bold;"></div>
        </div>
    `;
}

// Eksekusi penyimpanan ke Database via AJAX
async function simpanPilihanFoto(nim) {
    const pilihan = document.querySelector('input[name="pilihan_foto"]:checked').value;
    const notifBox = document.getElementById('notif_simpan_foto');
    
    notifBox.innerHTML = '<span style="color: #17a2b8;">⏳ Menyimpan pilihan ke database...</span>';
    
    try {
        const formData = new FormData();
        formData.append('simpan_lokasi_foto', '1');
        formData.append('nim', nim);
        formData.append('lokasi', pilihan);

        const response = await fetch('antrean_mhs.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.status === 'success') {
            notifBox.innerHTML = '<span style="color: #28a745;">✅ Berhasil! Saat KTM dicetak, sistem akan menggunakan folder <b>' + pilihan.toUpperCase() + '</b>.</span>';
        } else {
            notifBox.innerHTML = '<span style="color: #dc3545;">❌ Gagal menyimpan. Pastikan NIM sudah terdaftar di Master Data.</span>';
        }
    } catch (e) {
        notifBox.innerHTML = '<span style="color: #dc3545;">❌ Terjadi kesalahan jaringan.</span>';
    }
}
</script>