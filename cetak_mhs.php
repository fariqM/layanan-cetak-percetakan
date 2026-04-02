<?php
// File: cetak_mhs.php
// Deskripsi: Modul Antrean & Cetak Kartu Tanda Mahasiswa (Orientasi Landscape)
// Fitur Tambahan: Membaca pilihan "lokasi_foto" dari database untuk menangkal False 404.

require_once 'auth.php';
require_once 'koneksi.php';
require_once 'koneksi_rfid.php'; // Koneksi eksternal Gate

// =========================================================================
// API 1: UPDATE STATUS CETAK, SIMPAN RFID & INTEGRASI GATE (AJAX)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'update_status' && isset($_GET['id_cetak'])) {
    $id_cetak = (int)$_GET['id_cetak'];
    $rfid_code = isset($_GET['rfid_code']) ? ltrim(trim($_GET['rfid_code']), '0') : null;
    $username_login = $_SESSION['username']; 

    try {
        $pdo->beginTransaction();
        $pdo_rfid->beginTransaction();

        $stmtCari = $pdo->prepare("SELECT nim FROM cetak_ktm WHERE id_cetak = :id_cetak LIMIT 1");
        $stmtCari->execute([':id_cetak' => $id_cetak]);
        $dataCetak = $stmtCari->fetch(PDO::FETCH_ASSOC);

        if (!$dataCetak) { throw new Exception("Data transaksi tidak ditemukan."); }
        $nim = $dataCetak['nim'];

        // Hapus akses Gate lama (Pencegahan Duplikat/Penyalahgunaan)
        $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
        $stmtDelGate->execute([':nip' => $nim]);

        // Simpan akses Gate baru
        if (!empty($rfid_code)) {
            $stmtGate = $pdo_rfid->prepare("INSERT INTO gate (id_card, nip, status) VALUES (:id_card, :nip, 'MHS/DLB')");
            $stmtGate->execute([':id_card' => $rfid_code, ':nip' => $nim]);
        }

        // Update status lokal
        $stmtUpdate = $pdo->prepare("UPDATE cetak_ktm SET status_cetak = 'Sudah', rfid = :rfid, printed_at = NOW(), printed_by = :operator, rfid_assigned_by = :operator WHERE id_cetak = :id_cetak");
        $stmtUpdate->execute([
            ':rfid' => $rfid_code,
            ':operator' => $username_login,
            ':id_cetak' => $id_cetak
        ]);
        
        $pdo_rfid->commit();
        $pdo->commit();

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API 2: SUPER-ADMIN "BUKA KUNCI" (RESET ANTREAN & CABUT AKSES GATE LAMA)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'unlock' && isset($_GET['id_cetak'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin') {
        $id_cetak = (int)$_GET['id_cetak'];
        
        try {
            $pdo->beginTransaction();
            $pdo_rfid->beginTransaction();

            $stmtCari = $pdo->prepare("SELECT nim FROM cetak_ktm WHERE id_cetak = :id_cetak LIMIT 1");
            $stmtCari->execute([':id_cetak' => $id_cetak]);
            $nim = $stmtCari->fetchColumn();

            if ($nim) {
                // Cabut akses RFID fisik cacat dari Gate
                $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
                $stmtDelGate->execute([':nip' => $nim]);

                // Reset status antrean ke 'Belum'
                $stmtReset = $pdo->prepare("UPDATE cetak_ktm SET status_cetak = 'Belum', rfid = NULL, printed_at = NULL, printed_by = NULL, rfid_assigned_by = NULL WHERE id_cetak = :id_cetak");
                $stmtReset->execute([':id_cetak' => $id_cetak]);
            }

            $pdo_rfid->commit();
            $pdo->commit();

            header("Location: cetak_mhs.php?tab=riwayat&msg=unlocked");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); }
            die("Gagal membuka kunci antrean: " . htmlspecialchars($e->getMessage()));
        }
    } else {
        die("⛔ Akses Ditolak: Hanya Super-Admin yang memiliki otorisasi untuk membuka kunci cetak ulang.");
    }
}

// =========================================================================
// MODE 1: TAMPILAN PREVIEW & CETAK SATUAN (Diload ke dalam iframe popup)
// =========================================================================
if (isset($_GET['nim']) && isset($_GET['id_cetak']) && !isset($_GET['search']) && !isset($_GET['page']) && !isset($_GET['tab']) && !isset($_GET['action'])) {
    $nim = $_GET['nim'];
    $id_cetak = (int)$_GET['id_cetak'];
    $bg_param = isset($_GET['bg']) ? $_GET['bg'] : 'bsi';
    
    $bg_image = 'assets/bsi.png';
    if ($bg_param === 'btn') { $bg_image = 'assets/btn.png'; } 
    elseif ($bg_param === 'bjs') { $bg_image = 'assets/bjs.png'; }
    
    $stmt = $pdo->prepare("SELECT m.*, c.status_cetak FROM mahasiswa m JOIN cetak_ktm c ON m.nim = c.nim WHERE m.nim = :nim AND c.id_cetak = :id_cetak");
    $stmt->execute([':nim' => $nim, ':id_cetak' => $id_cetak]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) die("Data mahasiswa dengan NIM $nim tidak ditemukan.");

    // =======================================================================
    // PENENTUAN URL FOTO BERDASARKAN DATABASE (HASIL PILIHAN OPERATOR)
    // =======================================================================
    $lokasi_folder = (isset($row['lokasi_foto']) && $row['lokasi_foto'] === 'utama') ? '' : 'thumb/';
    $url_foto_final = "https://sinau.uinsa.ac.id/uploads/fotomhs/{$lokasi_folder}" . htmlspecialchars($row['nim']) . ".jpg";

    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Preview KTM - <?= htmlspecialchars($row['nama']); ?></title>
        <link rel="icon" href="assets/uinsa.png" type="image/png">
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #2c3e50; margin: 0; display: flex; flex-direction: column; align-items: center; padding: 30px 0; }
            .preview-controls { background-color: #ecf0f1; padding: 20px 30px; border-radius: 8px; margin-bottom: 30px; text-align: center; color: #2c3e50; box-shadow: 0 4px 15px rgba(0,0,0,0.3); border: 2px solid #bdc3c7; }
            .preview-controls h3 { margin: 0 0 8px 0; font-size: 18px; color: #2980b9; }
            .preview-controls p { margin: 0 0 20px 0; font-size: 14px; color: #555; }
            .btn-action { padding: 10px 20px; font-size: 14px; font-weight: bold; border: none; border-radius: 4px; cursor: pointer; margin: 0 5px; transition: 0.2s; }
            .btn-print { background-color: #28a745; color: white; }
            .btn-print:hover { background-color: #218838; }
            .btn-cancel { background-color: #dc3545; color: white; }
            .btn-cancel:hover { background-color: #c82333; }
            .id-card { width: 86mm; height: 54mm; position: relative; background-color: #fff; border-radius: 3mm; overflow: hidden; box-sizing: border-box; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
            .content-wrapper { position: absolute; top: 13.5mm; left: 6mm; right: 6mm; bottom: 4mm; z-index: 2; display: flex; justify-content: space-between; }
            .left-section { width: 53mm; display: flex; flex-direction: column; justify-content: flex-start; }
            .text-data { flex-grow: 1; }
            .nama-mhs { font-size: 10.5pt; font-weight: bold; color: #003311; margin: 0 0 1.5mm 0; line-height: 1.1; text-transform: capitalize; }
            .detail-table { font-size: 7.5pt; color: #003311; border-collapse: collapse; line-height: 1.1; }
            .detail-table td { padding: 0.5px 0; vertical-align: top; }
            .detail-table td:first-child { width: 13mm; }
            .detail-table td:nth-child(2) { width: 2mm; text-align: center; }
            .qr-wrapper { margin-top: auto; }
            .qr-code { width: 1.5cm; height: 1.5cm; background-color: #ffffff; padding: 1.5mm; box-sizing: border-box; }
            .right-section { display: flex; flex-direction: column; justify-content: flex-start; }
            .foto-mhs { width: 20mm; height: 26mm; object-fit: cover; background-color: #cc0000; border-radius: 2px; }

            .rfid-input-hidden { position: absolute; opacity: 0; pointer-events: none; }
            #rfid-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); justify-content: center; align-items: center; }
            .modal-content { background: white; padding: 30px; border-radius: 10px; text-align: center; width: 400px; }
            .scanning-loader { font-size: 50px; margin-bottom: 15px; animation: blinker 1.5s linear infinite; }
            @keyframes blinker { 50% { opacity: 0; } }

            .d-none-print { display: block; }
            @media print {
                .d-none-print, .preview-controls { display: none !important; }
                body { background-color: white; padding: 0; margin: 0; }
                @page { size: 86mm 54mm; margin: 0; }
                .id-card { box-shadow: none; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div id="rfid-modal" class="d-none-print">
            <div class="modal-content">
                <div class="scanning-loader">📡</div>
                <h3 style="color: #2980b9; margin-top: 0;">Tempelkan Kartu</h3>
                <p style="color: #555;">Tempelkan kartu pada <b>USB RFID Reader</b> untuk merekam data UID.</p>
                <input type="text" id="rfid_scanner" class="rfid-input-hidden">
                <button onclick="tutupModal()" class="btn-action btn-cancel" style="margin-top:20px;">Batal</button>
                
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                <div style="margin-top: 20px; padding: 15px; border: 2px dashed #ffc107; background: #fff3cd; border-radius: 6px;">
                    <p style="font-size: 13px; color: #856404; margin: 0 0 10px 0;"><b>[Mode Testing]</b> Simulasi tanpa alat scanner:</p>
                    <button onclick="simulasiScanRFID()" class="btn-action" style="background-color: #ffc107; color: #333; width: 100%;">
                        ⚡ Gunakan UID Dummy & Cetak
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="preview-controls d-none-print">
            <h3>🔍 Konfirmasi Preview KTM</h3>
            <p>Background: <strong><?= strtoupper(htmlspecialchars($bg_param)) ?></strong></p>
            <div>
                <?php if (isset($row['status_cetak']) && $row['status_cetak'] === 'Sudah'): ?>
                    <button class="btn-action" style="background-color: #6c757d; color: white; cursor: not-allowed; opacity: 0.7;" disabled>✅ Sudah Dicetak</button>
                <?php else: ?>
                    <button onclick="bukaModalRFID(<?= $id_cetak; ?>)" class="btn-action btn-print">🖨️ Lanjutkan Cetak</button>
                <?php endif; ?>
                <button onclick="kembaliKeAntrean()" class="btn-action btn-cancel">❌ Batal & Kembali</button>
            </div>
        </div>

        <div class="id-card" style="background-image: url('<?= $bg_image ?>'); background-size: cover; background-position: center;">
            <div class="content-wrapper">
                <div class="left-section">
                    <div class="text-data">
                        <h2 class="nama-mhs"><?= htmlspecialchars($row['nama']); ?></h2>
                        <table class="detail-table">
                            <tr><td>NIM</td><td>:</td><td><?= htmlspecialchars($row['nim']); ?></td></tr>
                            <tr><td>Fakultas</td><td>:</td><td><?= htmlspecialchars($row['fakultas']); ?></td></tr>
                            <tr><td>Prodi</td><td>:</td><td><?= htmlspecialchars($row['prodi']); ?></td></tr>
                        </table>
                    </div>
                    <div class="qr-wrapper">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($row['nim']); ?>&margin=0" class="qr-code">
                    </div>
                </div>
                <div class="right-section">
                    <img src="<?= $url_foto_final ?>" class="foto-mhs" onerror="this.src='https://via.placeholder.com/150x200/cc0000/ffffff?text=No+Photo'">
                </div>
            </div>
        </div>

        <script>
            let scanTimeout;
            let scannerInput = document.getElementById('rfid_scanner');
            window.currentIdCetak = null;

            function bukaModalRFID(idCetak) {
                window.currentIdCetak = idCetak;
                document.getElementById('rfid-modal').style.display = 'flex';
                scannerInput.value = '';
                scannerInput.focus();
            }

            function tutupModal() {
                document.getElementById('rfid-modal').style.display = 'none';
                window.currentIdCetak = null;
            }

            document.addEventListener('click', function() {
                if (document.getElementById('rfid-modal').style.display === 'flex') {
                    scannerInput.focus();
                }
            });

            scannerInput.addEventListener('input', function() {
                clearTimeout(scanTimeout);
                scanTimeout = setTimeout(() => {
                    let rfidValue = scannerInput.value.trim();
                    if (rfidValue.length >= 5 && window.currentIdCetak) {
                        prosesUpdateDanPrint(window.currentIdCetak, rfidValue);
                    }
                }, 300); 
            });

            function prosesUpdateDanPrint(idCetak, rfidCode) {
                document.getElementById('rfid-modal').style.display = 'none';
                fetch(`cetak_mhs.php?action=update_status&id_cetak=${idCetak}&rfid_code=${rfidCode}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') { 
                        window.print(); 
                    } else { 
                        alert('Gagal memperbarui status: ' + data.message); 
                    }
                });
            }

            function kembaliKeAntrean() {
                if (window.parent && window.parent !== window) {
                    window.parent.location.reload(); 
                } else {
                    window.location.href = 'cetak_mhs.php';
                }
            }

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
            function simulasiScanRFID() {
                const angkaAcak = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
                const dummyUID = "99999" + angkaAcak; 
                const idCetak = window.currentIdCetak; 
                
                if(idCetak) {
                    prosesUpdateDanPrint(idCetak, dummyUID);
                } else {
                    alert("Error: ID Cetak tidak ditemukan.");
                }
            }
            <?php endif; ?>
        </script>
    </body>
    </html>
    <?php
    exit; 
}

// =========================================================================
// MODE 2: TAMPILAN ANTREAN TRANSAKSI CETAK (UI DENGAN TAB PEMISAH)
// =========================================================================
$tab_aktif = isset($_GET['tab']) && $_GET['tab'] === 'riwayat' ? 'riwayat' : 'antrean';
$status_filter = ($tab_aktif === 'riwayat') ? 'Sudah' : 'Belum';

// Konsistensi Layout UI: Batasan Pagination menjadi 5 baris data per halaman
$limit = 5; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$countSql = "SELECT COUNT(*) FROM cetak_ktm c JOIN mahasiswa m ON c.nim = m.nim WHERE c.status_cetak = :status";
if ($search !== '') {
    $countSql .= " AND (c.nim LIKE :search OR m.nama LIKE :search)";
}
$countStmt = $pdo->prepare($countSql);
$countStmt->bindValue(':status', $status_filter);
if ($search !== '') { $countStmt->bindValue(':search', "%$search%"); }
$countStmt->execute();
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT c.id_cetak, c.created_at, c.printed_at, c.rfid, c.kriteria, c.status_cetak, m.nim, m.nama, m.fakultas, m.prodi 
        FROM cetak_ktm c JOIN mahasiswa m ON c.nim = m.nim WHERE c.status_cetak = :status";
if ($search !== '') {
    $sql .= " AND (c.nim LIKE :search OR m.nama LIKE :search)";
}
$orderBy = ($tab_aktif === 'antrean') ? "ASC" : "DESC";
$sql .= " ORDER BY c.created_at $orderBy LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':status', $status_filter);
if ($search !== '') { $stmt->bindValue(':search', "%$search%"); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$antrean_cetak = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Antrean Cetak KTM";
include 'header.php'; 
?>

        <h2>Data Perekaman & Cetak KTM Fisik</h2>
        
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'unlocked'): ?>
            <div class="alert success" style="margin-bottom: 20px;">
                🔓 <b>Buka Kunci Berhasil!</b> Transaksi dikembalikan ke Tab "Menunggu Cetak" dan akses RFID kartu yang gagal telah dicabut dari mesin Gate.
            </div>
        <?php endif; ?>
        
        <div style="display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px;">
            <a href="?tab=antrean" class="btn <?= $tab_aktif === 'antrean' ? 'btn-blue' : 'btn-outline' ?>" style="flex: 1; padding: 12px; font-size: 16px;">
                ⏳ Menunggu Cetak 
                <?php
                    $stmtBadge = $pdo->query("SELECT COUNT(*) FROM cetak_ktm WHERE status_cetak = 'Belum'");
                    $jmlAntre = $stmtBadge->fetchColumn();
                    if ($jmlAntre > 0) echo "<span style='background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 5px;'>$jmlAntre</span>";
                ?>
            </a>
            <a href="?tab=riwayat" class="btn <?= $tab_aktif === 'riwayat' ? 'btn-blue' : 'btn-outline' ?>" style="flex: 1; padding: 12px; font-size: 16px;">
                ✅ Riwayat Selesai
            </a>
        </div>

        <div class="search-box">
            <form action="cetak_mhs.php" method="GET" style="margin: 0; display: flex; gap: 10px; width: 100%;">
                <input type="hidden" name="tab" value="<?= $tab_aktif; ?>">
                <input type="text" name="search" placeholder="Cari berdasarkan NIM atau Nama Mahasiswa..." value="<?= htmlspecialchars($search); ?>">
                <button type="submit" class="btn-blue" style="width: auto;">🔍 Cari Data</button>
                <?php if ($search !== ''): ?>
                    <a href="cetak_mhs.php?tab=<?= $tab_aktif; ?>" class="btn-red" style="padding: 10px 15px; border-radius: 4px; font-size: 14px; text-decoration: none;">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="5%" style="text-align: center;">No</th>
                    <th width="12%">NIM</th>
                    <th width="20%">Nama Lengkap</th>
                    <th width="18%">Fakultas</th>
                    <th width="13%" style="text-align: center;">Kriteria</th>
                    <th width="17%" style="text-align: center;"><?= $tab_aktif === 'riwayat' ? 'Waktu Selesai & UID' : 'Status' ?></th>
                    <th width="15%" style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($antrean_cetak) > 0): ?>
                    <?php 
                    $no = $offset + 1; 
                    foreach ($antrean_cetak as $row): 
                        $status_cetak = $row['status_cetak'] ?? 'Belum';
                        $badgeStatus = ($status_cetak === 'Sudah') ? 'bg-sudah' : 'bg-belum';
                    ?>
                        <tr>
                            <td style="text-align: center;"><?= $no++; ?></td>
                            <td><strong><?= htmlspecialchars($row['nim']); ?></strong></td>
                            <td style="text-transform: capitalize;"><?= htmlspecialchars($row['nama']); ?></td>
                            <td><?= htmlspecialchars($row['fakultas']); ?></td>
                            <td style="text-align: center;">
                                <?php if($row['kriteria'] === 'Cetak Rusak'): ?>
                                    <span class="badge" style="background-color: #ffc107; color: #333;"><?= $row['kriteria']; ?></span>
                                <?php elseif($row['kriteria'] === 'Cetak Hilang'): ?>
                                    <span class="badge" style="background-color: #dc3545;"><?= $row['kriteria']; ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background-color: #28a745;"><?= $row['kriteria']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;">
                                    <span class="badge <?= $badgeStatus; ?>"><?= $status_cetak; ?></span>
                                    
                                    <?php if ($tab_aktif === 'riwayat'): ?>
                                        <?php if (!empty($row['rfid'])): ?>
                                            <span style="font-family: monospace; font-size: 12px; color: #6f42c1; font-weight: bold; background: #f8f9fa; padding: 2px 6px; border-radius: 4px; border: 1px solid #ddd;">
                                                UID: <?= htmlspecialchars($row['rfid']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <div style="font-size: 11px; color: #555; font-weight: bold;">
                                            <?= !empty($row['printed_at']) ? date('d M Y, H:i', strtotime($row['printed_at'])) : '-'; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($tab_aktif === 'antrean'): ?>
                                    <form onsubmit="bukaPreviewDialog(event, this)" class="form-cetak">
                                        <input type="hidden" name="nim" value="<?= htmlspecialchars($row['nim']); ?>">
                                        <input type="hidden" name="id_cetak" value="<?= htmlspecialchars($row['id_cetak']); ?>">
                                        <select name="bg" class="form-control" style="width: 70px; padding: 6px; border: 1px solid #ccc;">
                                            <option value="bsi">BSI</option>
                                            <option value="btn">BTN</option>
                                            <option value="bjs">BJS</option>
                                        </select>
                                        <button type="submit" class="btn-cetak">🖨️ Cetak</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #28a745; font-weight: bold; font-size: 13px;">✅ Selesai</span>
                                    
                                    <div style="margin-top: 6px; margin-bottom: 6px;">
                                        <button onclick="bukaPreviewSelesai('<?= htmlspecialchars($row['nim']); ?>', <?= $row['id_cetak']; ?>)" class="btn" style="background-color: #007bff; color: white; font-size: 11px; padding: 4px 8px; border: none; border-radius: 4px; cursor: pointer;">🔍 Lihat Preview</button>
                                    </div>
                                    
                                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                                        <div>
                                            <a href="cetak_mhs.php?action=unlock&id_cetak=<?= $row['id_cetak']; ?>" 
                                               class="btn" style="background-color: #343a40; color: #ffffff; font-size: 11.5px; font-weight: 600; padding: 5px 10px; text-decoration: none; border: 1px solid #23272b; border-radius: 5px; box-shadow: 0 3px 6px rgba(0,0,0,0.15); transition: 0.2s;"
                                               onclick="return confirm('🔓 PERINGATAN SUPER-ADMIN:\n\nApakah Anda yakin ingin membuka kunci transaksi ini agar bisa dicetak ulang?\n\nAkses RFID pada kartu lama akan otomatis HANGUS dari mesin Gate.');"
                                               onmouseover="this.style.backgroundColor='#23272b'" 
                                               onmouseout="this.style.backgroundColor='#343a40'">
                                               🔓 Buka Kunci
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: #666;">
                            <?= $tab_aktif === 'antrean' ? '🎉 Hebat! Tidak ada antrean cetak saat ini.' : 'Belum ada riwayat cetak yang diselesaikan.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php 
                $baseUrl = "?tab=$tab_aktif" . ($search ? '&search='.urlencode($search) : '');
                if ($page > 1): ?><a href="<?= $baseUrl ?>&page=1">&laquo;&laquo; First</a><?php endif; ?>
                <?php if ($page > 1): ?><a href="<?= $baseUrl ?>&page=<?= $page - 1; ?>">&laquo; Prev</a><?php endif; ?>
                
                <?php 
                $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2);
                if ($startPage == 1) { $endPage = min(5, $totalPages); } else if ($endPage == $totalPages) { $startPage = max(1, $totalPages - 4); }
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                    <a href="<?= $baseUrl ?>&page=<?= $i; ?>" class="<?= ($page == $i) ? 'active' : ''; ?>"><?= $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page + 1; ?>">Next &raquo;</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $totalPages; ?>">Last &raquo;&raquo;</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="info-data">Menampilkan data <?= $totalRows > 0 ? ($offset + 1) . " - " . min($offset + $limit, $totalRows) : "0" ?> dari total <?= $totalRows; ?> baris pada kategori ini.</div>

        <style>
            dialog#previewDialog::backdrop { background-color: rgba(0, 0, 0, 0.75); backdrop-filter: blur(3px); }
            dialog#previewDialog { padding: 0; border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); background: transparent; }
            #previewDialog .dialog-wrapper { background: #2c3e50; border-radius: 12px; overflow: hidden; padding: 0; display: flex; flex-direction: column; }
        </style>

        <dialog id="previewDialog">
            <div class="dialog-wrapper">
                <iframe id="previewFrame" src="" width="600" height="750" style="border: none; display: block;"></iframe>
            </div>
        </dialog>

        <script>
            // Dialog preview untuk Cetak Baru
            function bukaPreviewDialog(event, form) {
                event.preventDefault();
                const nim = form.nim.value;
                const idCetak = form.id_cetak.value;
                const bg = form.bg.value;
                
                const dialog = document.getElementById('previewDialog');
                const frame = document.getElementById('previewFrame');
                frame.src = `cetak_mhs.php?nim=${nim}&id_cetak=${idCetak}&bg=${bg}`;
                dialog.showModal();
            }

            // Dialog preview untuk Riwayat Selesai
            function bukaPreviewSelesai(nim, idCetak) {
                const dialog = document.getElementById('previewDialog');
                const frame = document.getElementById('previewFrame');
                frame.src = `cetak_mhs.php?nim=${nim}&id_cetak=${idCetak}&bg=bsi`;
                dialog.showModal();
            }
        </script>

<?php include 'footer.php'; ?>