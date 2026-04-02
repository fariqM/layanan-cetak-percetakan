<?php
// Wajib ada untuk melindungi halaman ini dari akses tanpa login
require_once 'auth.php'; 
require_once 'koneksi.php';
require_once 'koneksi_rfid.php'; 

// =====================================================================
// FITUR DOWNLOAD TEMPLATE CSV (KHUSUS SUPER-ADMIN)
// =====================================================================
if (isset($_GET['download_template']) && isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin') {
    $jenis = $_GET['download_template'];
    header('Content-Type: text/csv; charset=utf-8');
    
    if ($jenis === 'import_mhs') {
        header('Content-Disposition: attachment; filename=template_import_mahasiswa.csv');
        $output = fopen('php://output', 'w'); fputcsv($output, ['NIM', 'Nama Lengkap', 'Fakultas', 'Prodi']); fputcsv($output, ['0123456789', 'Budi Santoso', 'Sains dan Teknologi', 'Sistem Informasi']); fclose($output); exit;
    } elseif ($jenis === 'delete_mhs') {
        header('Content-Disposition: attachment; filename=template_hapus_mahasiswa.csv');
        $output = fopen('php://output', 'w'); fputcsv($output, ['NIM']); fputcsv($output, ['0123456789']); fclose($output); exit;
    } elseif ($jenis === 'import_sdm') {
        header('Content-Disposition: attachment; filename=template_import_sdm.csv');
        $output = fopen('php://output', 'w'); 
        fputcsv($output, ['NIP/NIK/ID Custom', 'Nama Lengkap', 'Kategori (Dosen/Security/Visitor/dll)', 'Jabatan', 'Unit Kerja']); 
        fputcsv($output, ['198001012005011001', 'Ahmad Dahlan', 'Dosen', 'Lektor Kepala', 'Fakultas Tarbiyah']); 
        fputcsv($output, ['3578012345678901', 'Budi Penjaga', 'Security', 'Komandan Regu', 'Keamanan Kampus']); 
        fputcsv($output, ['VST-0001', 'Tamu Universitas', 'Visitor', '', '']); 
        fclose($output); exit;
    } elseif ($jenis === 'delete_sdm') {
        header('Content-Disposition: attachment; filename=template_hapus_sdm.csv');
        $output = fopen('php://output', 'w'); fputcsv($output, ['NIP/NIK/ID']); fputcsv($output, ['198001012005011001']); fclose($output); exit;
    }
}

$pesan_sistem = "";

// =====================================================================
// [KHUSUS SUPER-ADMIN] 1. LOGIKA IMPORT, HAPUS & TAMBAH SATUAN
// =====================================================================
if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin') {
    
    // --- A. IMPORT/UPDATE MAHASISWA ---
    if (isset($_POST['submit_import_mhs']) && isset($_FILES["csv_import_mhs"]["error"]) && $_FILES["csv_import_mhs"]["error"] == 0) {
        $fileExt = strtolower(pathinfo($_FILES["csv_import_mhs"]["name"], PATHINFO_EXTENSION));
        if ($fileExt === "csv") {
            $fileContent = file_get_contents($_FILES["csv_import_mhs"]["tmp_name"]); $fileContent = str_replace(["\r\n", "\r"], "\n", $fileContent); $lines = explode("\n", $fileContent); $row = 1; $berhasil = 0; $diperbarui = 0;
            $stmtImport = $pdo->prepare("INSERT INTO mahasiswa (nim, nama, fakultas, prodi) VALUES (:nim, :nama, :fakultas, :prodi) ON DUPLICATE KEY UPDATE nama = VALUES(nama), fakultas = VALUES(fakultas), prodi = VALUES(prodi)");
            $pdo->beginTransaction();
            try {
                foreach ($lines as $line) {
                    $line = trim($line); if (empty($line)) continue; 
                    if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') { $line = substr($line, 1, -1); $line = str_replace('""', '"', $line); }
                    $delimiter = strpos($line, ';') !== false ? ';' : ','; $data = str_getcsv($line, $delimiter); 
                    if ($row == 1) { $row++; continue; } 
                    
                    if (count($data) >= 4 && !empty(trim($data[0])) && !empty(trim($data[1]))) {
                        $stmtImport->execute([':nim' => trim($data[0]), ':nama' => trim($data[1]), ':fakultas' => trim($data[2]), ':prodi' => trim($data[3])]);
                        if ($stmtImport->rowCount() == 1) { $berhasil++; } elseif ($stmtImport->rowCount() == 2) { $diperbarui++; }
                    } $row++;
                } $pdo->commit(); $pesan_sistem = "<div class='alert success'>✅ <b>Import Mahasiswa Berhasil!</b> Baru: $berhasil | Diperbarui: $diperbarui</div>";
            } catch (PDOException $e) { $pdo->rollBack(); $pesan_sistem = "<div class='alert error'>❌ <b>Gagal Import:</b> " . htmlspecialchars($e->getMessage()) . "</div>"; }
        }
    }

    // --- B. HAPUS MASSAL MAHASISWA ---
    if (isset($_POST['submit_delete_mhs']) && isset($_FILES["csv_delete_mhs"]["error"]) && $_FILES["csv_delete_mhs"]["error"] == 0) {
        $fileExt = strtolower(pathinfo($_FILES["csv_delete_mhs"]["name"], PATHINFO_EXTENSION));
        if ($fileExt === "csv") {
            $fileContent = file_get_contents($_FILES["csv_delete_mhs"]["tmp_name"]); $fileContent = str_replace(["\r\n", "\r"], "\n", $fileContent); $lines = explode("\n", $fileContent); $row = 1; $berhasilHapus = 0;
            $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip"); $stmtDelCetak = $pdo->prepare("DELETE FROM cetak_ktm WHERE nim = :nim"); $stmtDeleteMhs = $pdo->prepare("DELETE FROM mahasiswa WHERE nim = :nim");
            $pdo->beginTransaction(); $pdo_rfid->beginTransaction();
            try {
                foreach ($lines as $line) {
                    $line = trim($line); if (empty($line)) continue; 
                    if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') { $line = substr($line, 1, -1); $line = str_replace('""', '"', $line); }
                    $delimiter = strpos($line, ';') !== false ? ';' : ','; $data = str_getcsv($line, $delimiter); 
                    if ($row == 1) { $row++; continue; } $nim = trim($data[0]); 
                    if (!empty($nim)) { $stmtDelGate->execute([':nip' => $nim]); $stmtDelCetak->execute([':nim' => $nim]); $stmtDeleteMhs->execute([':nim' => $nim]); if ($stmtDeleteMhs->rowCount() > 0) { $berhasilHapus++; } } $row++;
                } $pdo_rfid->commit(); $pdo->commit(); $pesan_sistem = "<div class='alert success'>🗑️ <b>Hapus Tuntas!</b> $berhasilHapus data mahasiswa dihapus.</div>";
            } catch (PDOException $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); } $pesan_sistem = "<div class='alert error'>❌ <b>Gagal Hapus:</b> " . htmlspecialchars($e->getMessage()) . "</div>"; }
        }
    }

    // --- C. IMPORT/UPDATE SDM (PEGAWAI & NON-PEGAWAI) ---
    if (isset($_POST['submit_import_sdm']) && isset($_FILES["csv_import_sdm"]["error"]) && $_FILES["csv_import_sdm"]["error"] == 0) {
        $fileExt = strtolower(pathinfo($_FILES["csv_import_sdm"]["name"], PATHINFO_EXTENSION));
        if ($fileExt === "csv") {
            $fileContent = file_get_contents($_FILES["csv_import_sdm"]["tmp_name"]); $fileContent = str_replace(["\r\n", "\r"], "\n", $fileContent); $lines = explode("\n", $fileContent); $row = 1; $berhasil = 0; $diperbarui = 0;
            $stmtImport = $pdo->prepare("INSERT INTO sdm (nip, nama, kategori, jabatan, unit_kerja) VALUES (:nip, :nama, :kategori, :jabatan, :unit_kerja) ON DUPLICATE KEY UPDATE nama = VALUES(nama), kategori = VALUES(kategori), jabatan = VALUES(jabatan), unit_kerja = VALUES(unit_kerja)");
            $pdo->beginTransaction();
            try {
                foreach ($lines as $line) {
                    $line = trim($line); if (empty($line)) continue; 
                    if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') { $line = substr($line, 1, -1); $line = str_replace('""', '"', $line); }
                    $delimiter = strpos($line, ';') !== false ? ';' : ','; $data = str_getcsv($line, $delimiter); 
                    if ($row == 1) { $row++; continue; } 
                    if (count($data) >= 3 && !empty(trim($data[0])) && !empty(trim($data[1]))) {
                        $nip = trim($data[0]); $nama = trim($data[1]); $kategori_sdm = trim($data[2]); $jabatan = isset($data[3]) ? trim($data[3]) : null; $unit_kerja = isset($data[4]) ? trim($data[4]) : null;
                        $stmtImport->execute([':nip' => $nip, ':nama' => $nama, ':kategori' => $kategori_sdm, ':jabatan' => $jabatan, ':unit_kerja' => $unit_kerja]);
                        if ($stmtImport->rowCount() == 1) { $berhasil++; } elseif ($stmtImport->rowCount() == 2) { $diperbarui++; }
                    } $row++;
                } $pdo->commit(); $pesan_sistem = "<div class='alert success'>✅ <b>Import SDM Berhasil!</b> Baru: $berhasil | Diperbarui: $diperbarui</div>";
            } catch (PDOException $e) { $pdo->rollBack(); $pesan_sistem = "<div class='alert error'>❌ <b>Gagal Import SDM:</b> " . htmlspecialchars($e->getMessage()) . "</div>"; }
        }
    }

    // --- D. HAPUS MASSAL SDM ---
    if (isset($_POST['submit_delete_sdm']) && isset($_FILES["csv_delete_sdm"]["error"]) && $_FILES["csv_delete_sdm"]["error"] == 0) {
        $fileExt = strtolower(pathinfo($_FILES["csv_delete_sdm"]["name"], PATHINFO_EXTENSION));
        if ($fileExt === "csv") {
            $fileContent = file_get_contents($_FILES["csv_delete_sdm"]["tmp_name"]); $fileContent = str_replace(["\r\n", "\r"], "\n", $fileContent); $lines = explode("\n", $fileContent); $row = 1; $berhasilHapus = 0;
            $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip"); $stmtDelCetak = $pdo->prepare("DELETE FROM cetak_sdm WHERE nip = :nip"); $stmtDeleteSdm = $pdo->prepare("DELETE FROM sdm WHERE nip = :nip");
            $pdo->beginTransaction(); $pdo_rfid->beginTransaction();
            try {
                foreach ($lines as $line) {
                    $line = trim($line); if (empty($line)) continue; 
                    if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') { $line = substr($line, 1, -1); $line = str_replace('""', '"', $line); }
                    $delimiter = strpos($line, ';') !== false ? ';' : ','; $data = str_getcsv($line, $delimiter); 
                    if ($row == 1) { $row++; continue; } $nip = trim($data[0]); 
                    if (!empty($nip)) { $stmtDelGate->execute([':nip' => $nip]); $stmtDelCetak->execute([':nip' => $nip]); $stmtDeleteSdm->execute([':nip' => $nip]); if ($stmtDeleteSdm->rowCount() > 0) { $berhasilHapus++; } } $row++;
                } $pdo_rfid->commit(); $pdo->commit(); $pesan_sistem = "<div class='alert success'>🗑️ <b>Hapus Tuntas!</b> $berhasilHapus data SDM dihapus.</div>";
            } catch (PDOException $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); } $pesan_sistem = "<div class='alert error'>❌ <b>Gagal Hapus SDM:</b> " . htmlspecialchars($e->getMessage()) . "</div>"; }
        }
    }

    // --- E. TAMBAH SDM SATUAN (MANUAL) + FOTO ---
    if (isset($_POST['submit_single_sdm'])) {
        $nip = trim($_POST['nip']);
        $nama = trim($_POST['nama']);
        $kategori_sdm = trim($_POST['kategori']);
        $jabatan = trim($_POST['jabatan']);
        $unit_kerja = trim($_POST['unit_kerja']);

        $stmtCek = $pdo->prepare("SELECT nip FROM sdm WHERE nip = :nip");
        $stmtCek->execute([':nip' => $nip]);
        
        if ($stmtCek->fetch()) {
            $pesan_sistem = "<div class='alert error'>❌ <b>Gagal:</b> NIP/ID <strong>$nip</strong> sudah terdaftar di Master Data. Silakan gunakan fitur Cari Data.</div>";
        } else {
            $uploadOk = true;
            $direktori_foto = "assets/sdm/";
            if (!file_exists($direktori_foto)) { mkdir($direktori_foto, 0777, true); }

            if (isset($_FILES['foto_sdm']) && $_FILES['foto_sdm']['error'] == 0) {
                $imageFileType = strtolower(pathinfo($_FILES["foto_sdm"]["name"], PATHINFO_EXTENSION));
                $target_file = $direktori_foto . $nip . ".jpg";

                $check = getimagesize($_FILES["foto_sdm"]["tmp_name"]);
                if ($check === false) { $pesan_sistem = "<div class='alert error'>❌ Gagal: File yang diunggah bukan gambar valid.</div>"; $uploadOk = false; }
                elseif ($_FILES["foto_sdm"]["size"] > 2000000) { $pesan_sistem = "<div class='alert error'>❌ Gagal: Ukuran foto maksimal 2MB.</div>"; $uploadOk = false; }
                elseif($imageFileType != "jpg" && $imageFileType != "jpeg" && $imageFileType != "png") { $pesan_sistem = "<div class='alert error'>❌ Gagal: Format foto harus JPG, JPEG, atau PNG.</div>"; $uploadOk = false; }
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
                $pesan_sistem = "<div class='alert error'>❌ <b>Gagal:</b> Foto pegawai wajib diunggah untuk penambahan manual.</div>";
                $uploadOk = false;
            }

            if ($uploadOk) {
                try {
                    $stmtInsert = $pdo->prepare("INSERT INTO sdm (nip, nama, kategori, jabatan, unit_kerja) VALUES (:nip, :nama, :kategori, :jabatan, :unit_kerja)");
                    $stmtInsert->execute([
                        ':nip' => $nip, ':nama' => $nama, ':kategori' => $kategori_sdm, ':jabatan' => $jabatan, ':unit_kerja' => $unit_kerja
                    ]);
                    $pesan_sistem = "<div class='alert success'>✅ <b>Berhasil!</b> Data SDM <strong>$nama</strong> beserta foto telah berhasil ditambahkan ke Master Data. Anda kini dapat memprosesnya di menu Antrean.</div>";
                } catch (PDOException $e) {
                    $pesan_sistem = "<div class='alert error'>❌ <b>Gagal Menyimpan Data:</b> " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
    }
}

// =====================================================================
// 2. MENGAMBIL DATA STATISTIK GABUNGAN
// =====================================================================
try {
    $antreanMhs = $pdo->query("SELECT COUNT(*) FROM cetak_ktm WHERE status_cetak = 'Belum'")->fetchColumn();
    $antreanSdm = $pdo->query("SELECT COUNT(*) FROM cetak_sdm WHERE status_cetak = 'Belum'")->fetchColumn();
    $totalMhs = $pdo->query("SELECT COUNT(*) FROM mahasiswa")->fetchColumn();
    $totalSdm = $pdo->query("SELECT COUNT(*) FROM sdm")->fetchColumn();
} catch (PDOException $e) { $antreanMhs = 0; $antreanSdm = 0; $totalMhs = 0; $totalSdm = 0; }

// =====================================================================
// 3. LOGIKA PENCARIAN DINAMIS & PAGINATION
// =====================================================================
$kategori = isset($_GET['kategori']) ? $_GET['kategori'] : ''; 
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$data_master = [];
$totalRows = 0;
$totalPages = 0;

if ($kategori !== '' && $search !== '') {
    if ($kategori === 'mhs') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE nim LIKE :search OR nama LIKE :search OR fakultas LIKE :search");
        $countStmt->bindValue(':search', "%$search%"); $countStmt->execute();
        $totalRows = $countStmt->fetchColumn(); $totalPages = ceil($totalRows / $limit);

        $stmtData = $pdo->prepare("SELECT nim as id, nama, fakultas as col3, prodi as col4, JK as col5 FROM mahasiswa WHERE nim LIKE :search OR nama LIKE :search OR fakultas LIKE :search ORDER BY nim ASC LIMIT :limit OFFSET :offset");
        $stmtData->bindValue(':search', "%$search%"); $stmtData->bindValue(':limit', $limit, PDO::PARAM_INT); $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT); $stmtData->execute();
        $data_master = $stmtData->fetchAll(PDO::FETCH_ASSOC);
        
    } else if ($kategori === 'sdm') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sdm WHERE nip LIKE :search OR nama LIKE :search OR kategori LIKE :search OR unit_kerja LIKE :search");
        $countStmt->bindValue(':search', "%$search%"); $countStmt->execute();
        $totalRows = $countStmt->fetchColumn(); $totalPages = ceil($totalRows / $limit);

        $stmtData = $pdo->prepare("SELECT nip as id, nama, kategori as col3, unit_kerja as col4 FROM sdm WHERE nip LIKE :search OR nama LIKE :search OR kategori LIKE :search OR unit_kerja LIKE :search ORDER BY nip ASC LIMIT :limit OFFSET :offset");
        $stmtData->bindValue(':search', "%$search%"); $stmtData->bindValue(':limit', $limit, PDO::PARAM_INT); $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT); $stmtData->execute();
        $data_master = $stmtData->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Menyiapkan Variabel Desain Dinamis
$themeColor = '#6c757d'; 
$themeTitle = 'Pilih Master Data';
$placeholderText = 'Pilih kategori terlebih dahulu...';

if ($kategori === 'mhs') {
    $themeColor = '#007bff';
    $themeTitle = 'Data Mahasiswa';
    $placeholderText = 'Ketik NIM, Nama, atau Fakultas...';
} else if ($kategori === 'sdm') {
    $themeColor = '#28a745';
    $themeTitle = 'Data SDM';
    $placeholderText = 'Ketik NIP/ID, Nama, atau Unit Kerja...';
}

$page_title = "Pusat Kendali Sistem Identitas";
include 'header.php';
?>

        <h2>Dashboard Aplikasi Cetak ID Card UINSA</h2>
        
        <div style="background-color: #e8f5e9; color: #1b5e20; padding: 15px 20px; border-radius: 8px; border: 1px solid #c8e6c9; display: flex; align-items: center; gap: 15px; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <div style="font-size: 30px;">🛡️</div>
            <div>
                <strong style="display: block; font-size: 15px; margin-bottom: 3px;">Sistem Keamanan Gate Otomatis (Auto-Clearance) Telah Aktif</strong>
                <span style="font-size: 13.5px;">Akses RFID akan otomatis dicabut saat pelaporan hilang/rusak. Pencatatan UID ke mesin Gate juga terjadi otomatis saat menekan tombol "Print".</span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px;">
            <div style="background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.08); border: 1px solid #dee2e6;">
                <div style="background: #007bff; color: white; padding: 15px; text-align: center;"><h3 style="margin: 0; font-size: 18px;">🎓 Modul Mahasiswa</h3></div>
                <div style="padding: 20px; display: flex; flex-direction: column; gap: 10px;">
                    <a href="antrean_mhs.php" class="btn btn-outline" style="text-align: left; padding: 10px 15px;">📝 Lapor Hilang/Rusak KTM</a>
                    <a href="cetak_mhs.php" class="btn btn-outline" style="text-align: left; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center;">
                    <span>🖨️ Antrean Cetak KTM</span>
                    <span style="background: #007bff; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: bold;"><?= $antreanMhs ?></span></a>
                </div>
            </div>
            <div style="background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.08); border: 1px solid #dee2e6;">
                <div style="background: #28a745; color: white; padding: 15px; text-align: center;"><h3 style="margin: 0; font-size: 18px;">👔 Modul SDM & Mitra</h3></div>
                <div style="padding: 20px; display: flex; flex-direction: column; gap: 10px;">
                    <a href="antrean_sdm.php" class="btn btn-outline" style="text-align: left; padding: 10px 15px;">📝 Lapor Hilang/Rusak Kartu SDM</a>
                    <a href="cetak_sdm.php" class="btn btn-outline" style="text-align: left; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center;">
                    <span>🖨️ Antrean Cetak SDM</span>
                    <span style="background: #28a745; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: bold;"><?= $antreanSdm ?></span></a>
                </div>
            </div>
            <div style="background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.08); border: 1px solid #dee2e6;">
                <div style="background: #343a40; color: white; padding: 15px; text-align: center;"><h3 style="margin: 0; font-size: 18px;">🔐 Manajemen Gate</h3></div>
                <div style="padding: 20px; display: flex; flex-direction: column; gap: 10px;">
                    <a href="manajemen_gate.php" class="btn btn-outline" style="text-align: left; padding: 10px 15px;">📡 Sinkronisasi KTM Lama</a>
                    <a href="manajemen_gate.php?search=" class="btn btn-outline" style="text-align: left; padding: 10px 15px;">🔍 Audit Akses UID Gate</a>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 20px; margin-bottom: 40px;">
            <div style="flex: 1; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 5px solid #007bff;">
                <h4 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">👥 Total Mahasiswa</h4>
                <div style="font-size: 28px; font-weight: bold; color: #333;"><?= number_format($totalMhs, 0, ',', '.'); ?></div>
            </div>
            <div style="flex: 1; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 5px solid #28a745;">
                <h4 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">👥 Total SDM & Mitra</h4>
                <div style="font-size: 28px; font-weight: bold; color: #333;"><?= number_format($totalSdm, 0, ',', '.'); ?></div>
            </div>
        </div>

        <hr style="border: 0; height: 1px; background: #ccc; margin: 40px 0;">

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="color: #343a40; margin: 0;">Manajemen Master Data</h2>
            
            <form action="index.php" method="GET" style="margin: 0;">
                <select name="kategori" class="form-control" onchange="this.form.submit()" style="width: 320px; padding: 10px 15px; font-size: 15px; font-weight: bold; border: 2px solid <?= $themeColor ?>; border-radius: 6px; background-color: #f8f9fa; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                    <option value="" <?= $kategori === '' ? 'selected' : '' ?> disabled>⬇️ Pilih Master Data...</option>
                    <option value="mhs" <?= $kategori === 'mhs' ? 'selected' : '' ?>>🎓 Data Mahasiswa</option>
                    <option value="sdm" <?= $kategori === 'sdm' ? 'selected' : '' ?>>👔 Data Sivitas, Pegawai & Mitra</option>
                </select>
            </form>
        </div>

        <?= $pesan_sistem; ?>

        <?php if ($kategori === ''): ?>
            <div style="text-align: center; padding: 60px 40px; background: #f8f9fa; border-radius: 8px; border: 2px dashed #ced4da; color: #6c757d;">
                <div style="font-size: 45px; margin-bottom: 15px;">🗂️</div>
                <h3 style="margin: 0 0 10px 0; color: #495057;">Pilih Kategori Master Data</h3>
                <p style="margin: 0; font-size: 14.5px;">Silakan pilih <b>Data Mahasiswa</b> atau <b>Data Sivitas, Pegawai & Mitra</b> pada menu <i>dropdown</i> di atas<br>untuk membuka panel pengelolaan dan memulai pencarian data.</p>
            </div>
        <?php else: ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                <details style="margin-bottom: 25px; background: #fff; border: 1px solid <?= $themeColor ?>; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                    <summary style="padding: 15px 20px; font-weight: bold; font-size: 15px; color: <?= $themeColor ?>; cursor: pointer; background: #f8f9fa; border-radius: 8px; list-style: none; display: flex; justify-content: space-between; align-items: center;">
                        <span>⚙️ Buka Panel Eksekusi Administrator (Import CSV, Hapus Massal & Entry Baru)</span>
                        <span style="font-size: 12px; background: <?= $themeColor ?>; color: white; padding: 4px 10px; border-radius: 12px;">Klik Buka / Tutup</span>
                    </summary>
                    <div style="padding: 20px; border-top: 1px solid #dee2e6;">
                        
                        <div style="display: flex; gap: 20px; margin-bottom: <?= $kategori === 'sdm' ? '25px' : '0' ?>;">
                            <div style="flex: 1; border: 1px solid #dee2e6; padding: 15px; border-radius: 8px; background: #fcfcfc;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <span style="font-weight: bold; color: <?= $themeColor ?>;">➕ Import / Update via CSV</span>
                                    <a href="?download_template=import_<?= $kategori ?>" style="font-size: 11px; background: #e8f5e9; padding: 3px 8px; border-radius: 4px; text-decoration: none; color: #28a745;">📥 Template CSV</a>
                                </div>
                                <form action="index.php?kategori=<?= $kategori ?>" method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px;">
                                    <input type="file" name="csv_import_<?= $kategori ?>" accept=".csv" required style="flex: 1; padding: 6px; border: 1px solid #ccc; background: #fff;">
                                    <button type="submit" name="submit_import_<?= $kategori ?>" class="btn" style="background: <?= $themeColor ?>; color: white;">Upload</button>
                                </form>
                            </div>
                            
                            <div style="flex: 1; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; background: #fff8f8;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <span style="font-weight: bold; color: #dc3545;">🗑️ Hapus Massal via CSV</span>
                                    <a href="?download_template=delete_<?= $kategori ?>" style="font-size: 11px; background: #fce8e8; padding: 3px 8px; border-radius: 4px; text-decoration: none; color: #dc3545;">📥 Template CSV</a>
                                </div>
                                <form action="index.php?kategori=<?= $kategori ?>" method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px;" onsubmit="return confirm('⚠️ PERINGATAN: Semua data <?= $themeTitle ?> yang ada di dalam CSV akan dihapus permanen. Lanjutkan?');">
                                    <input type="file" name="csv_delete_<?= $kategori ?>" accept=".csv" required style="flex: 1; padding: 6px; border: 1px solid #ccc; background: #fff;">
                                    <button type="submit" name="submit_delete_<?= $kategori ?>" class="btn" style="background: #dc3545; color: white;">Hapus Permanen</button>
                                </form>
                            </div>
                        </div>

                        <?php if ($kategori === 'sdm'): ?>
                        <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #c8e6c9; border-left: 5px solid #28a745;">
                            <div style="margin-bottom: 15px;">
                                <span style="font-weight: bold; color: #28a745; font-size: 16px;">✍️ Entry SDM Satuan (Manual)</span>
                                <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">Tambahkan 1 data pegawai/mitra beserta foto secara instan tanpa perlu CSV.</p>
                            </div>
                            
                            <form action="index.php?kategori=sdm" method="POST" enctype="multipart/form-data" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px;">NIP / ID Custom *</label>
                                    <input type="text" name="nip" class="form-control" placeholder="Contoh: 198001012005011001" required style="padding: 8px; border: 1px solid #ced4da;">
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px;">Nama Lengkap (Beserta Gelar) *</label>
                                    <input type="text" name="nama" class="form-control" placeholder="Ketik nama lengkap..." required style="padding: 8px; border: 1px solid #ced4da;">
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px;">Kategori *</label>
                                    <select name="kategori" class="form-control" required style="padding: 8px; border: 1px solid #ced4da;">
                                        <option value="" disabled selected>-- Pilih Kategori --</option>
                                        <option value="Dosen">Dosen</option>
                                        <option value="Tenaga Kependidikan">Tenaga Kependidikan</option>
                                        <option value="Security">Security</option>
                                        <option value="Cleaning Service">Cleaning Service</option>
                                        <option value="Mitra">Mitra / Visitor</option>
                                        <option value="Lainnya">Lainnya</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px;">Jabatan</label>
                                    <input type="text" name="jabatan" class="form-control" placeholder="Opsional..." style="padding: 8px; border: 1px solid #ced4da;">
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px;">Unit Kerja</label>
                                    <input type="text" name="unit_kerja" class="form-control" placeholder="Opsional..." style="padding: 8px; border: 1px solid #ced4da;">
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px;">Upload Pas Foto * (Max 2MB)</label>
                                    <input type="file" name="foto_sdm" accept="image/jpeg, image/png" class="form-control" required style="padding: 5px; background: #f8f9fa; border: 1px solid #ced4da;">
                                </div>
                                
                                <div style="grid-column: 1 / -1; text-align: right; margin-top: 5px;">
                                    <button type="submit" name="submit_single_sdm" class="btn" style="background: #28a745; color: white; padding: 10px 25px; font-weight: bold;">
                                        💾 Simpan Data SDM
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                    </div>
                </details>
            <?php endif; ?>

            <div style="background: <?= $themeColor ?>; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; gap: 15px; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                <h3 style="margin: 0; color: white; font-size: 16px; min-width: 220px;">🔍 Cari <?= $themeTitle ?>:</h3>
                <form action="index.php" method="GET" style="margin: 0; display: flex; gap: 10px; width: 100%;">
                    <input type="hidden" name="kategori" value="<?= $kategori ?>">
                    <input type="text" name="search" placeholder="<?= $placeholderText ?>" value="<?= htmlspecialchars($search); ?>" style="flex: 1; padding: 10px 15px; font-size: 15px; border-radius: 4px; border: none; outline: none;" autofocus>
                    <button type="submit" class="btn" style="background: #343a40; color: white; border: 1px solid #fff; padding: 10px 20px; font-weight: bold;">Tampilkan</button>
                    <?php if ($search !== ''): ?>
                        <a href="index.php?kategori=<?= $kategori ?>" class="btn" style="background: #dc3545; color: white; text-decoration: none; border: 1px solid #fff; display: flex; align-items: center; padding: 0 15px;">Reset Filter</a>
                    <?php endif; ?>
                </form>
            </div>

            <div style="border: 1px solid <?= $themeColor ?>; border-top: none; border-radius: 0 0 8px 8px; padding: <?= $search === '' ? '40px' : '20px' ?>; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                <?php if ($search === ''): ?>
                    <div style="text-align: center; color: #6c757d;">
                        <div style="font-size: 45px; margin-bottom: 15px;">🕵️‍♂️</div>
                        <h3 style="margin: 0 0 5px 0; color: <?= $themeColor ?>;">Tabel Data Disembunyikan</h3>
                        <p style="margin: 0; font-size: 14px;">Ketik kata kunci pencarian pada kotak di atas lalu tekan Enter<br>untuk menampilkan data secara spesifik.</p>
                    </div>
                <?php else: ?>
                    <table style="margin: 0;">
                        <thead>
                            <tr style="background-color: <?= $themeColor ?>; color: white;">
                                <th width="5%" style="text-align: center; border-color: <?= $themeColor ?>;">No</th>
                                <th width="15%" style="border-color: <?= $themeColor ?>;"><?= $kategori === 'mhs' ? 'NIM' : 'NIP / NIK / ID' ?></th>
                                <th width="30%" style="border-color: <?= $themeColor ?>;">Nama Lengkap</th>
                                <th width="20%" style="border-color: <?= $themeColor ?>;"><?= $kategori === 'mhs' ? 'Fakultas' : 'Kategori SDM' ?></th>
                                <th width="25%" style="border-color: <?= $themeColor ?>;"><?= $kategori === 'mhs' ? 'Program Studi' : 'Unit Kerja' ?></th>
								<th width="5%" style="text-align: center; border-color: <?= $themeColor ?>;"><?= $kategori === 'mhs' ? 'JK' : 'Unit Kerja' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($data_master) > 0): ?>
                                <?php $no = $offset + 1; foreach ($data_master as $row): ?>
                                    <tr>
                                        <td style="text-align: center;"><?= $no++; ?></td>
                                        <td><strong style="color: <?= $themeColor ?>;"><?= htmlspecialchars($row['id']); ?></strong></td>
                                        <td style="text-transform: capitalize;"><?= htmlspecialchars($row['nama']); ?></td>
                                        <td>
                                            <?php if($kategori === 'sdm'): ?>
                                                <span style="background-color: #e8f5e9; color: #28a745; padding: 2px 8px; border-radius: 12px; font-size: 11.5px; font-weight: bold; border: 1px solid #c8e6c9;">
                                                    <?= htmlspecialchars($row['col3']); ?>
                                                </span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($row['col3']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['col4'] ?? '-'); ?></td>
										<td style="text-align: center;"><?= htmlspecialchars($row['col5'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 30px; color: #dc3545; font-weight: bold; background: #fff8f8;">❌ Data tidak ditemukan untuk pencarian "<?= htmlspecialchars($search); ?>".</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination" style="margin-top: 20px;">
                        <?php 
                        $baseUrl = "?kategori=" . urlencode($kategori) . "&search=" . urlencode($search);
                        if ($page > 1): ?><a href="<?= $baseUrl ?>&page=1">&laquo;&laquo; First</a><?php endif; ?>
                        <?php if ($page > 1): ?><a href="<?= $baseUrl ?>&page=<?= $page - 1; ?>">&laquo; Prev</a><?php endif; ?>
                        
                        <?php 
                        $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2);
                        if ($startPage == 1) { $endPage = min(5, $totalPages); } else if ($endPage == $totalPages) { $startPage = max(1, $totalPages - 4); }
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <a href="<?= $baseUrl ?>&page=<?= $i; ?>" class="<?= ($page == $i) ? 'active' : ''; ?>" <?= ($page == $i) ? 'style="background-color:'.$themeColor.'; border-color:'.$themeColor.';"' : '' ?>><?= $i; ?></a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page + 1; ?>">Next &raquo;</a><?php endif; ?>
                        <?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $totalPages; ?>">Last &raquo;&raquo;</a><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="info-data">
                        Menampilkan hasil pencarian <?= $totalRows > 0 ? ($offset + 1) . " - " . min($offset + $limit, $totalRows) : "0" ?> dari total <?= number_format($totalRows, 0, ',', '.'); ?> data yang cocok.
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

<?php include 'footer.php'; ?>