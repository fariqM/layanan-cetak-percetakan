<?php
// File: cetak_sdm.php
// Deskripsi: Modul Antrean & Cetak ID Card Pegawai/Mitra (Orientasi Portrait)

require_once 'auth.php';
require_once 'koneksi.php';
require_once 'koneksi_rfid.php';

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

        // Cari data cetak beserta kategorinya dari tabel SDM
        $stmtCari = $pdo->prepare("SELECT c.nip, s.kategori FROM cetak_sdm c JOIN sdm s ON c.nip = s.nip WHERE c.id_cetak = :id_cetak LIMIT 1");
        $stmtCari->execute([':id_cetak' => $id_cetak]);
        $dataCetak = $stmtCari->fetch(PDO::FETCH_ASSOC);

        if (!$dataCetak) { throw new Exception("Data transaksi SDM tidak ditemukan."); }
        $nip = $dataCetak['nip'];
        
        // Gunakan kategori sebagai status gate (misal: "DOSEN" atau "SECURITY"), jika kosong gunakan "PEGAWAI"
        $status_gate = !empty($dataCetak['kategori']) ? strtoupper(substr($dataCetak['kategori'], 0, 50)) : 'PEGAWAI';

        // Auto-Clearance Gate (Hapus akses lama jika ada)
        $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
        $stmtDelGate->execute([':nip' => $nip]);

        // Binding Gate Baru
        if (!empty($rfid_code)) {
            $stmtGate = $pdo_rfid->prepare("INSERT INTO gate (id_card, nip, status) VALUES (:id_card, :nip, :status_gate)");
            $stmtGate->execute([':id_card' => $rfid_code, ':nip' => $nip, ':status_gate' => $status_gate]);
        }

        // Update status lokal
        $stmtUpdate = $pdo->prepare("UPDATE cetak_sdm SET status_cetak = 'Sudah', rfid = :rfid, printed_at = NOW(), printed_by = :operator, rfid_assigned_by = :operator WHERE id_cetak = :id_cetak");
        $stmtUpdate->execute([':rfid' => $rfid_code, ':operator' => $username_login, ':id_cetak' => $id_cetak]);
        
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
// API 2: SUPER-ADMIN "BUKA KUNCI" (RESET ANTREAN & CABUT AKSES GATE)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'unlock' && isset($_GET['id_cetak'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin') {
        $id_cetak = (int)$_GET['id_cetak'];
        try {
            $pdo->beginTransaction();
            $pdo_rfid->beginTransaction();

            $stmtCari = $pdo->prepare("SELECT nip FROM cetak_sdm WHERE id_cetak = :id_cetak LIMIT 1");
            $stmtCari->execute([':id_cetak' => $id_cetak]);
            $nip = $stmtCari->fetchColumn();

            if ($nip) {
                $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
                $stmtDelGate->execute([':nip' => $nip]);

                $stmtReset = $pdo->prepare("UPDATE cetak_sdm SET status_cetak = 'Belum', rfid = NULL, printed_at = NULL, printed_by = NULL, rfid_assigned_by = NULL WHERE id_cetak = :id_cetak");
                $stmtReset->execute([':id_cetak' => $id_cetak]);
            }

            $pdo_rfid->commit();
            $pdo->commit();

            header("Location: cetak_sdm.php?tab=riwayat&msg=unlocked");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); }
            die("Gagal membuka kunci antrean SDM: " . htmlspecialchars($e->getMessage()));
        }
    } else {
        die("⛔ Akses Ditolak.");
    }
}

// =========================================================================
// API 3: SUPER-ADMIN "BATALKAN ANTREAN" (HAPUS DATA DARI ANTREAN CETAK)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id_cetak'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin') {
        $id_cetak = (int)$_GET['id_cetak'];
        try {
            // Hapus data hanya jika statusnya masih 'Belum' dicetak
            $stmtDel = $pdo->prepare("DELETE FROM cetak_sdm WHERE id_cetak = :id_cetak AND status_cetak = 'Belum'");
            $stmtDel->execute([':id_cetak' => $id_cetak]);
            header("Location: cetak_sdm.php?tab=antrean&msg=cancelled");
            exit;
        } catch (Exception $e) {
            die("Gagal membatalkan antrean: " . htmlspecialchars($e->getMessage()));
        }
    } else {
        die("⛔ Akses Ditolak.");
    }
}

// =========================================================================
// MODE 1: TAMPILAN PREVIEW & CETAK SATUAN (PORTRAIT)
// =========================================================================
if (isset($_GET['nip']) && isset($_GET['id_cetak']) && !isset($_GET['search']) && !isset($_GET['page']) && !isset($_GET['tab']) && !isset($_GET['action'])) {
    $nip = $_GET['nip'];
    $id_cetak = (int)$_GET['id_cetak'];
    
    $stmt = $pdo->prepare("SELECT s.*, c.status_cetak FROM sdm s JOIN cetak_sdm c ON s.nip = c.nip WHERE s.nip = :nip AND c.id_cetak = :id_cetak");
    $stmt->execute([':nip' => $nip, ':id_cetak' => $id_cetak]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) die("Data SDM dengan NIP/ID $nip tidak ditemukan.");
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Preview ID Card SDM - <?= htmlspecialchars($row['nama']); ?></title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #2c3e50; margin: 0; display: flex; flex-direction: column; align-items: center; padding: 20px 0; }
            .preview-controls { background-color: #ecf0f1; padding: 15px 30px; border-radius: 8px; margin-bottom: 20px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
            .btn-action { padding: 10px 20px; font-weight: bold; border: none; border-radius: 4px; cursor: pointer; margin: 0 5px; color: white;}
            .btn-print { background-color: #28a745; }
            .btn-cancel { background-color: #ffffff; color: #dc3545; border: 1px solid #dc3545; }
			.btn-cancel:hover { background-color: #f8d7da; }
            
            /* CSS KHUSUS PORTRAIT ID CARD SDM */
            .id-card-sdm { 
                width: 54mm; height: 86mm; /* Ukuran Portrait */
                position: relative; 
                background-image: url('assets/sdm.png'); 
                background-size: cover; background-position: center; 
                border-radius: 3mm; overflow: hidden; 
                box-sizing: border-box; box-shadow: 0 10px 25px rgba(0,0,0,0.5); 
                background-color: #fff;
            }
            .content-wrapper { position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 2; }
            
            .photo-box {
                position: absolute; top: 23mm; left: 50%; transform: translateX(-50%);
                width: 19mm; height: 24.19mm;
                background: transparent;
                border: none; /* Menghapus garis tepi bingkai */
                border-radius: 4px; overflow: hidden;
            }
            .foto-sdm { width: 100%; height: 100%; object-fit: cover; object-position: top center; }
            
            .text-box {
                position: absolute; top: 47mm; left: 0; right: 0;
                text-align: center; line-height: 0.3;
                padding: 0 3mm; box-sizing: border-box;
            }
            .nama-sdm {
                font-size: 8pt; font-weight: bold; color: #003311; 
                /* Baris text-transform: uppercase; telah dihapus dari sini */
                
                /* KUNCI PROPORSIONAL 2 BARIS */
                margin: 0 auto 2px auto;
                height: 7.5mm; 
                display: flex; align-items: center; justify-content: center; 
                overflow: hidden; 
            }
            .nip-sdm {
                font-size: 6.5pt; color: #222; margin: 0; font-weight: bold;
            }

            .qr-box {
                position: absolute; top: 62mm; left: 50%; transform: translateX(-50%);
                width: 10mm; height: 10mm; background: white; padding: 1mm; border-radius: 2px;
            }
            .qr-code { width: 100%; height: 100%; }

            .rfid-input-hidden { position: absolute; opacity: 0; pointer-events: none; }
            #rfid-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); justify-content: center; align-items: center; }
            .modal-content { background: white; padding: 30px; border-radius: 10px; text-align: center; width: 400px; }
            
            .d-none-print { display: block; }
            @media print {
                .d-none-print { display: none !important; }
                body { background-color: white; padding: 0; margin: 0; }
                @page { size: 54mm 86mm; margin: 0; }
                .id-card-sdm { box-shadow: none; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div id="rfid-modal" class="d-none-print">
            <div class="modal-content">
                <h3 style="color: #28a745; margin-top: 0;">Tempelkan Kartu SDM</h3>
                <p>Tempelkan kartu kosong pada <b>USB RFID Reader</b>.</p>
                <input type="text" id="rfid_scanner" class="rfid-input-hidden">
                <button onclick="tutupModal()" class="btn-action btn-cancel">Batal</button>
                
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                <div style="margin-top: 15px;">
                    <button onclick="simulasiScanRFID()" class="btn-action" style="background-color: #ffc107; color: #333; width: 100%;">⚡ Gunakan UID Dummy (Bypass)</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="preview-controls d-none-print">
            <h3 style="margin-top:0;">Preview ID Card Portrait</h3>
            <div>
                <?php if ($row['status_cetak'] === 'Sudah'): ?>
                    <button class="btn-action" style="background-color: #6c757d;" disabled>✅ Sudah Dicetak</button>
                <?php else: ?>
                    <button onclick="bukaModalRFID(<?= $id_cetak; ?>)" class="btn-action btn-print">🖨️ Lanjutkan Cetak</button>
                <?php endif; ?>
                <button onclick="kembaliKeAntrean()" class="btn-action btn-cancel">❌ Tutup & Batal</button>
            </div>
        </div>

        <div class="id-card-sdm">
            <div class="content-wrapper">
                <div class="photo-box">
                    <img src="assets/sdm/<?= htmlspecialchars($row['nip']); ?>.jpg?v=<?= time(); ?>" class="foto-sdm" onerror="this.src='https://via.placeholder.com/150x200/cccccc/666666?text=FOTO'">
                </div>
                
                <div class="text-box">
                    <p class="nama-sdm"><?= htmlspecialchars($row['nama']); ?></p>
                    <p class="nip-sdm"><?= htmlspecialchars($row['nip']); ?></p>
                </div>

                <div class="qr-box">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($row['nip']); ?>&margin=0" class="qr-code">
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
                fetch(`cetak_sdm.php?action=update_status&id_cetak=${idCetak}&rfid_code=${rfidCode}`)
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
                    window.location.href = 'cetak_sdm.php'; 
                }
            }

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
            function simulasiScanRFID() {
                const dummyUID = "77777" + Math.floor(Math.random() * 10000).toString().padStart(4, '0');
                if(window.currentIdCetak) { 
                    prosesUpdateDanPrint(window.currentIdCetak, dummyUID); 
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
// MODE 2: TAMPILAN ANTREAN TRANSAKSI SDM (UI TAB PEMISAH)
// =========================================================================
$tab_aktif = isset($_GET['tab']) && $_GET['tab'] === 'riwayat' ? 'riwayat' : 'antrean';
$status_filter = ($tab_aktif === 'riwayat') ? 'Sudah' : 'Belum';

$limit = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Kueri dengan pencarian yang memperhitungkan kolom NIP, Nama, dan Kategori
$countSql = "SELECT COUNT(*) FROM cetak_sdm c JOIN sdm s ON c.nip = s.nip WHERE c.status_cetak = :status";
if ($search !== '') { $countSql .= " AND (c.nip LIKE :search OR s.nama LIKE :search OR s.kategori LIKE :search)"; }
$countStmt = $pdo->prepare($countSql);
$countStmt->bindValue(':status', $status_filter);
if ($search !== '') { $countStmt->bindValue(':search', "%$search%"); }
$countStmt->execute();
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT c.id_cetak, c.created_at, c.printed_at, c.rfid, c.kriteria, c.status_cetak, s.nip, s.nama, s.kategori, s.unit_kerja 
        FROM cetak_sdm c JOIN sdm s ON c.nip = s.nip WHERE c.status_cetak = :status";
if ($search !== '') { $sql .= " AND (c.nip LIKE :search OR s.nama LIKE :search OR s.kategori LIKE :search)"; }
$orderBy = ($tab_aktif === 'antrean') ? "ASC" : "DESC";
$sql .= " ORDER BY c.created_at $orderBy LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':status', $status_filter);
if ($search !== '') { $stmt->bindValue(':search', "%$search%"); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$antrean_cetak = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Antrean Cetak ID Card SDM";
include 'header.php'; 
?>

        <h2 style="color: #28a745; margin-bottom: 25px;">🖨️ Papan Antrean Cetak ID Card SDM</h2>
        
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'unlocked'): ?>
            <div class="alert success" style="margin-bottom: 20px;">🔓 <b>Buka Kunci Berhasil!</b> Transaksi SDM dikembalikan ke Tab "Menunggu Cetak".</div>
        <?php endif; ?>
        
        <div style="display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid #dee2e6; padding-bottom: 15px;">
            <a href="?tab=antrean" class="btn <?= $tab_aktif === 'antrean' ? 'btn-blue' : 'btn-outline' ?>" style="flex: 1; padding: 12px; font-size: 16px; <?= $tab_aktif === 'antrean' ? 'background-color: #28a745; border-color: #28a745;' : '' ?>">
                ⏳ Menunggu Cetak 
                <?php
                    $jmlAntre = $pdo->query("SELECT COUNT(*) FROM cetak_sdm WHERE status_cetak = 'Belum'")->fetchColumn();
                    if ($jmlAntre > 0) echo "<span style='background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 5px;'>$jmlAntre</span>";
                ?>
            </a>
            <a href="?tab=riwayat" class="btn <?= $tab_aktif === 'riwayat' ? 'btn-blue' : 'btn-outline' ?>" style="flex: 1; padding: 12px; font-size: 16px; <?= $tab_aktif === 'riwayat' ? 'background-color: #28a745; border-color: #28a745;' : '' ?>">✅ Riwayat Selesai</a>
        </div>

        <div class="search-box">
            <form action="cetak_sdm.php" method="GET" style="margin: 0; display: flex; gap: 10px; width: 100%;">
                <input type="hidden" name="tab" value="<?= $tab_aktif; ?>">
                <input type="text" name="search" placeholder="Cari NIP, Nama, atau Kategori SDM..." value="<?= htmlspecialchars($search); ?>">
                <button type="submit" class="btn-blue" style="width: auto; background-color: #28a745;">🔍 Cari Antrean</button>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="5%" style="text-align: center;">No</th>
                    <th width="15%">NIP/ID</th>
                    <th width="25%">Nama Pegawai</th>
                    <th width="15%" style="text-align: center;">Kriteria</th>
                    <th width="20%" style="text-align: center;"><?= $tab_aktif === 'riwayat' ? 'UID Gate & Selesai' : 'Waktu Antre' ?></th>
                    <th width="15%" style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($antrean_cetak) > 0): ?>
                    <?php 
                    $no = $offset + 1; 
                    foreach ($antrean_cetak as $row): 
                        $status_cetak = $row['status_cetak'] ?? 'Belum';
                    ?>
                        <tr>
                            <td style="text-align: center;"><?= $no++; ?></td>
                            <td><strong><?= htmlspecialchars($row['nip']); ?></strong></td>
                            <td style="text-transform: capitalize;">
                                <?= htmlspecialchars($row['nama']); ?><br>
                                <span class="badge" style="background: #28a745; font-size: 10px;"><?= htmlspecialchars($row['kategori']); ?></span>
                            </td>
                            <td style="text-align: center; font-size: 13px; font-weight: bold; color: #555;">
                                <?= htmlspecialchars($row['kriteria']); ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($tab_aktif === 'antrean'): ?>
                                    <span style="font-size: 13px; color: #666;"><?= date('d M Y, H:i', strtotime($row['created_at'])); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-sudah">Sudah Selesai</span>
                                    <div style="font-size: 12px; margin-top:5px; font-family: monospace; font-weight: bold; color: #007bff;"><?= htmlspecialchars($row['rfid']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($tab_aktif === 'antrean'): ?>
                                    
                                    <div style="display: flex; justify-content: center; gap: 8px;">
                                        <button onclick="bukaPreviewSDM('<?= htmlspecialchars($row['nip']); ?>', <?= $row['id_cetak']; ?>)" class="btn" style="background: #17a2b8; color: white; padding: 6px 12px; font-size: 11.5px; border: none; border-radius: 4px; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                            🖨️ Cetak / Preview
                                        </button>
                                        
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                                            <a href="cetak_sdm.php?action=cancel&id_cetak=<?= $row['id_cetak']; ?>" class="btn" style="background-color: #ffffff; color: #dc3545; border: 1px solid #dc3545; font-size: 11.5px; font-weight: bold; padding: 5px 11px; text-decoration: none; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; align-items: center; transition: 0.2s;" onmouseover="this.style.backgroundColor='#f8d7da'" onmouseout="this.style.backgroundColor='#ffffff'" onclick="return confirm('⚠️ HAPUS ANTREAN:\n\nApakah Anda yakin ingin membatalkan dan menghapus data ini dari antrean cetak?');">
											❌ Batal
											</a>
                                        <?php endif; ?>
                                    </div>

                                <?php else: ?>
                                    <span style="color: #28a745; font-weight: bold; font-size: 13px;">✅ Terekam</span>
                                    
                                    <div style="margin-top: 6px; margin-bottom: 6px;">
                                        <button onclick="bukaPreviewSDM('<?= htmlspecialchars($row['nip']); ?>', <?= $row['id_cetak']; ?>)" class="btn" style="background-color: #007bff; color: white; font-size: 11px; padding: 4px 8px; border: none; border-radius: 4px; cursor: pointer;">🔍 Lihat Preview</button>
                                    </div>

                                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super-admin'): ?>
                                        <div style="margin-top: 8px;">
                                            <a href="cetak_sdm.php?action=unlock&id_cetak=<?= $row['id_cetak']; ?>" class="btn" style="background-color: #343a40; color: #ffffff; font-size: 11px; padding: 4px 8px; text-decoration: none; border-radius: 4px;" onclick="return confirm('Buka kunci antrean SDM ini dan cabut akses Gate-nya?');">🔓 Buka Kunci</a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 30px; color: #6c757d;">Tidak ada data antrean untuk ditampilkan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php 
            $baseUrl = "?tab=" . $tab_aktif . ($search ? "&search=" . urlencode($search) : "");
            if ($page > 1): ?><a href="<?= $baseUrl ?>&page=<?= $page - 1; ?>">&laquo; Prev</a><?php endif; ?>
            <?php 
            for ($i = 1; $i <= $totalPages; $i++): 
            ?>
                <a href="<?= $baseUrl ?>&page=<?= $i; ?>" class="<?= ($page == $i) ? 'active' : ''; ?>" <?= ($page == $i) ? 'style="background-color:#28a745; border-color:#28a745;"' : '' ?>><?= $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $page + 1; ?>">Next &raquo;</a><?php endif; ?>
        </div>
        <?php endif; ?>

        <style>
            dialog#previewDialogSDM::backdrop { background-color: rgba(0, 0, 0, 0.75); }
            dialog#previewDialogSDM { padding: 0; border: none; border-radius: 12px; background: transparent; overflow: hidden; }
            #previewDialogSDM .dialog-wrapper { background: #2c3e50; border-radius: 12px; overflow: hidden; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        </style>

        <dialog id="previewDialogSDM">
            <div class="dialog-wrapper">
                <iframe id="previewFrameSDM" src="" width="400" height="600" style="border: none; display: block;"></iframe>
            </div>
        </dialog>

        <script>
            function bukaPreviewSDM(nip, idCetak) {
                const dialog = document.getElementById('previewDialogSDM');
                const frame = document.getElementById('previewFrameSDM');
                frame.src = `cetak_sdm.php?nip=${nip}&id_cetak=${idCetak}`;
                dialog.showModal();
            }
        </script>

<?php include 'footer.php'; ?>