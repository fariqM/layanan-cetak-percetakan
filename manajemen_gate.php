<?php
/**
 * File: manajemen_gate.php
 * Deskripsi: Kelola data binding RFID ke sistem Gate (Transisi & Sinkronisasi Manual)
 * Fitur: Strict Transaction Mode, Pelacakan Error SQL, Multi-DB Handler, Auto-Clearance, RBAC
 */

// =========================================================================
// [DEBUGGING] Tampilkan pesan error PHP jika ada masalah
// =========================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Wajib ada paling atas untuk mengecek tiket login
require_once 'auth.php'; 

// =========================================================================
// FUNGSI HELPER: Hapus angka 0 HANYA di karakter pertama (Index 0)
// =========================================================================
function hapusNolPertama($uid) {
    if (substr($uid, 0, 1) === '0') {
        return substr($uid, 1);
    }
    return $uid;
}

// =========================================================================
// API ENDPOINT: Lacak Master Mahasiswa Berdasarkan NIM (AJAX - LOGIKA BARU)
// =========================================================================
if (isset($_GET['cek_nim'])) {
    require_once 'koneksi.php';
    $nim = trim($_GET['cek_nim']);
    
    $stmt = $pdo->prepare("SELECT nim, nama, fakultas, prodi FROM mahasiswa WHERE nim = :nim LIMIT 1");
    $stmt->execute([':nim' => $nim]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($data ? $data : ['error' => true]);
    exit;
}

// =========================================================================
// MANAJEMEN KONEKSI GANDA (Panggil Koneksi Utama Dulu, Baru RFID)
// =========================================================================
require_once 'koneksi.php';      
require_once 'koneksi_rfid.php'; 

// Paksa PDO Fingerprint untuk melempar error jika SQL gagal
$pdo_rfid->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pesan = "";
$role_login = $_SESSION['role'] ?? '';

// =========================================================================
// 1. LOGIKA ASSIGN RFID (CREATE) - DENGAN STRICT AUDIT TRAIL
// =========================================================================
if (isset($_POST["submit_rfid"])) {
    $nim = trim($_POST['nim'] ?? '');
    $id_card = trim($_POST['id_card'] ?? '');
    $username_login = $_SESSION['username'] ?? 'Unknown'; 
    
    $id_card_normalized = hapusNolPertama($id_card);

    if (!empty($nim) && !empty($id_card)) {
        // Cari ke Master Data Mahasiswa
        $stmtCariMhs = $pdo->prepare("SELECT nim FROM mahasiswa WHERE nim = :nim LIMIT 1");
        $stmtCariMhs->execute([':nim' => $nim]);
        
        if ($stmtCariMhs->fetch()) {
            try {
                $pdo->beginTransaction();
                $pdo_rfid->beginTransaction();

                // 1. EKSEKUSI KE MESIN GATE (Menghapus yang lama & Memasukkan yang baru)
                $stmtDelGate = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
                $stmtDelGate->execute([':nip' => $nim]);

                $stmtGate = $pdo_rfid->prepare("INSERT INTO gate (id_card, nip, status) VALUES (:id_card, :nip, 'MHS/DLB')");
                $stmtGate->execute([':id_card' => $id_card_normalized, ':nip' => $nim]);
                
                // 2. EKSEKUSI PENCATATAN AUDIT TRAIL KE TABEL CETAK_KTM
                $stmtCekHistory = $pdo->prepare("SELECT id_cetak FROM cetak_ktm WHERE nim = :nim ORDER BY id_cetak DESC LIMIT 1");
                $stmtCekHistory->execute([':nim' => $nim]);
                $history = $stmtCekHistory->fetch(PDO::FETCH_ASSOC);

                if ($history) {
                    $tag_petugas = $username_login . " (Manual Sync)";
                    $stmtUpdateAudit = $pdo->prepare("UPDATE cetak_ktm SET rfid = :rfid, rfid_assigned_by = :operator, printed_at = NOW() WHERE id_cetak = :id_cetak");
                    $stmtUpdateAudit->execute([
                        ':rfid' => $id_card_normalized, 
                        ':operator' => $tag_petugas, 
                        ':id_cetak' => $history['id_cetak']
                    ]);
                } else {
                    $tag_petugas = $username_login . " (Migrasi)";
                    $stmtInsertAudit = $pdo->prepare("INSERT INTO cetak_ktm (nim, kriteria, status_cetak, rfid, created_by, printed_by, rfid_assigned_by, created_at, printed_at) VALUES (:nim, 'Migrasi Sistem', 'Sudah', :rfid, :operator, :operator, :operator, NOW(), NOW())");
                    $stmtInsertAudit->execute([
                        ':nim' => $nim,
                        ':rfid' => $id_card_normalized,
                        ':operator' => $tag_petugas
                    ]);
                }

                $pdo_rfid->commit();
                $pdo->commit();

                $pesan = "<div class='alert success'>✅ Sinkronisasi & Audit Berhasil! Kartu RFID untuk NIM <strong>$nim</strong> telah terhubung ke Gate dan riwayat petugas telah dicatat.</div>";
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($pdo_rfid->inTransaction()) { $pdo_rfid->rollBack(); }
                $pesan = "<div class='alert error'>❌ <b>Gagal Menyimpan Data & Audit:</b><br>" . htmlspecialchars($e->getMessage()) . "</div>";
            }
        } else {
            $pesan = "<div class='alert error'>❌ Gagal! Mahasiswa dengan NIM <strong>$nim</strong> tidak ditemukan di Master Data. Harap Import data mahasiswa terlebih dahulu di menu Dashboard.</div>";
        }
    } else {
        $pesan = "<div class='alert error'>❌ Validasi gagal. Pastikan NIM dan UID Kartu terisi.</div>";
    }
}

// =========================================================================
// 2. LOGIKA HAPUS BINDING (DELETE) - KHUSUS SUPER ADMIN
// =========================================================================
if (isset($_GET['hapus'])) {
    if ($role_login === 'super-admin') {
        $nip_hapus = trim($_GET['hapus']);
        
        try {
            $stmt = $pdo_rfid->prepare("DELETE FROM gate WHERE nip = :nip");
            $stmt->execute([':nip' => $nip_hapus]);
            
            if ($stmt->rowCount() > 0) {
                $pesan = "<div class='alert success'>Akses Gate untuk NIM <strong>" . htmlspecialchars($nip_hapus) . "</strong> berhasil dicabut secara manual.</div>";
            } else {
                $pesan = "<div class='alert error'>⚠️ Data dengan NIM <strong>" . htmlspecialchars($nip_hapus) . "</strong> tidak ditemukan di dalam tabel Gate.</div>";
            }
        } catch (PDOException $e) {
            $pesan = "<div class='alert error'>❌ <b>Gagal Hapus dari Database Fingerprint:</b><br>" . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $pesan = "<div class='alert error'>⛔ Akses Ditolak: Hanya Super-Admin yang memiliki wewenang untuk mencabut akses Gate.</div>";
    }
}

// =========================================================================
// 3. LOGIKA PENCARIAN & RELASI DATA TAMPILAN
// =========================================================================
$limit = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_clean = hapusNolPertama($search); 
$search_lower = strtolower($search);

$filtered_gate = [];
$totalRows = 0;
$totalPages = 0;
$data_rfid = [];

if ($search !== '') {
    $stmtMhs = $pdo->query("SELECT nim, nama, fakultas, prodi FROM mahasiswa");
    $master_mhs = [];
    while ($row = $stmtMhs->fetch(PDO::FETCH_ASSOC)) {
        $master_mhs[$row['nim']] = $row;
    }

    try {
        $stmtGate = $pdo_rfid->query("SELECT id_card, nip FROM gate WHERE status = 'MHS/DLB' ORDER BY nip DESC");
        $all_gate = $stmtGate->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_gate as $gate) {
            $nip = $gate['nip'] ?? '';
            $id_card = $gate['id_card'] ?? '';
            
            $nama = $master_mhs[$nip]['nama'] ?? 'Data Tidak Ditemukan';
            $fakultas = $master_mhs[$nip]['fakultas'] ?? '-';
            $prodi = $master_mhs[$nip]['prodi'] ?? '-';
            
            if (strpos(strtolower($nip), $search_lower) !== false || 
                strpos(strtolower($nama), $search_lower) !== false || 
                strpos(strtolower($id_card), $search_clean) !== false) {
                
                $gate['nama'] = $nama;
                $gate['fakultas'] = $fakultas;
                $gate['prodi'] = $prodi;
                $filtered_gate[] = $gate;
            }
        }

        $totalRows = count($filtered_gate);
        $totalPages = ceil($totalRows / $limit);
        $data_rfid = array_slice($filtered_gate, $offset, $limit);
        
    } catch (PDOException $e) {
        $pesan = "<div class='alert error'>❌ <b>Gagal Membaca Data Tabel Gate:</b><br>Pastikan tabel `gate` ada di dalam database Fingerprint Anda. Detail Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

$page_title = "Data Binding RFID & Gate";
include 'header.php'; 
?>

        <h2>Manajemen Akses Mesin Gate (RFID)</h2>
        <?= $pesan; ?>

        <div class="forms-wrapper">
            <div class="form-panel panel-manual" style="max-width: 800px; margin: 0 auto; border-top-color: var(--purple-color);">
                
                <div style="background-color: #fff8f8; text-align: center; padding: 18px; border-radius: 8px; border: 2px solid #dc3545; margin-bottom: 25px; box-shadow: 0 4px 10px rgba(220, 53, 69, 0.15);">
                    <div style="font-size: 24px; color: #dc3545; font-weight: 900; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; text-align: center; gap: 8px;">
                        🚨 PENTING!
                    </div>
                    <p style="margin: 0; font-size: 14.5px; color: #495057; line-height: 1.6;">
                        Untuk KTM baru, pendaftaran Gate terjadi secara <b>OTOMATIS</b> di menu <b>🖨️ Cetak Kartu</b>.<br><br>
                        Gunakan form di bawah ini <b>HANYA UNTUK</b> menghubungkan <b>KTM Fisik Lama</b> (dari sistem manual sebelumnya) atau <b>jika terjadi kegagalan jaringan</b> yang menyebabkan kartu fisik yang tercetak ditolak saat tapping di Gerbang Masuk.
                    </p>
                </div>

                <form action="manajemen_gate.php" method="POST" id="formAssign" onsubmit="return konfirmasiAssign(event, this)">
                    <input type="hidden" name="submit_rfid" value="1">

                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px;">
                        
                        <div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
                            <label style="font-size: 14px; font-weight: bold; color: #333; display: block; margin-bottom: 5px;">NIM Mahasiswa</label>
                            <input type="text" name="nim" class="form-control" 
                                   style="background-color: #fff; font-size:16px; padding: 12px; border: 2px solid #ced4da; width: 100%; box-sizing: border-box;" 
                                   placeholder="Ketik NIM Mahasiswa..." required autofocus autocomplete="off">
                        </div>

                        <div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
                            <label style="font-size: 14px; font-weight: bold; color: #333; display: block; margin-bottom: 5px;">Nomor UID Kartu (ID Card)</label>
                            <input type="text" name="id_card" class="form-control" 
                                   style="background-color: #f8f9fa; font-weight: bold; letter-spacing: 1.5px; font-size:16px; text-align: center; padding: 12px; border: 2px solid #ced4da; width: 100%; box-sizing: border-box;" 
                                   placeholder="Tap kartu pada alat USB Reader..." required autocomplete="off">
                        </div>
                        
                    </div>

                    <div style="text-align: right; margin-top: 25px;">
                        <button type="submit" class="btn btn-purple" style="padding:12px 35px; font-size:15px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; width: auto; display: inline-block; cursor: pointer;">
                            🔄 Sinkronisasi Data
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="search-box" style="margin-top: 40px;">
            <form action="manajemen_gate.php" method="GET" style="margin: 0; display: flex; gap: 10px; width: 100%;">
                <input type="text" name="search" placeholder="Cari NIM atau Nama untuk melihat akses Gate..." value="<?= htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-blue" style="width: auto;">🔍 Audit Data Gate</button>
                <?php if ($search !== ''): ?>
                    <a href="manajemen_gate.php" class="btn btn-red" style="padding: 10px 15px; font-size: 14px; text-decoration: none;">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($search === ''): ?>
            <div style="text-align: center; padding: 50px 20px; background: #f8f9fa; border-radius: 8px; border: 2px dashed #ccc; margin-top: 20px; color: #555;">
                <h3 style="margin-top: 0; color: #6c757d;">Mode Keamanan Aktif</h3>
                <p style="margin-bottom: 0;">Untuk mencegah modifikasi data tanpa pengawasan, seluruh isi tabel disembunyikan.<br>Gunakan kotak pencarian di atas untuk melakukan <b>Audit Data</b></p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th width="5%" style="text-align: center;">No</th>
                        <th width="8%">NIM</th>
                        <th width="32%">Nama Lengkap</th>
                        <th width="32%">Fakultas / Prodi</th>
                        <th width="12%" style="color: var(--purple-color); text-align: center;">Kode RFID</th>
                        
                        <?php if ($role_login === 'super-admin'): ?>
                        <th width="13%" style="text-align: center;">Aksi</th>
                        <?php endif; ?>
                        
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($data_rfid) > 0): ?>
                        <?php 
                        $no = $offset + 1; 
                        foreach ($data_rfid as $row): 
                        ?>
                            <tr>
                                <td style="text-align: center;"><?= $no++; ?></td>
                                <td><strong><?= htmlspecialchars($row['nip'] ?? ''); ?></strong></td>
                                <td style="text-transform: capitalize;"><?= htmlspecialchars($row['nama'] ?? ''); ?></td>
                                <td>
                                    <?= htmlspecialchars($row['fakultas'] ?? ''); ?> / <br>
                                    <?= htmlspecialchars($row['prodi'] ?? ''); ?>
                                </td>
                                <td style="font-family: monospace; font-size: 14px; color: var(--purple-color); font-weight: bold; background: #e3f2fd; text-align: center;">
                                    <?= htmlspecialchars($row['id_card'] ?? ''); ?>
                                </td>
                                
                                <?php if ($role_login === 'super-admin'): ?>
                                <td style="text-align: center;">
                                    <a href="?hapus=<?= urlencode($row['nip']); ?><?= $search ? '&search='.urlencode($search) : '' ?>" 
                                       class="btn btn-red" 
                                       title="Cabut Akses Gerbang" 
                                       onclick="return confirm('Pencabutan Akses Darurat:\n\nNIM: <?= htmlspecialchars($row['nip'] ?? ''); ?>\nNama: <?= htmlspecialchars($row['nama'] ?? ''); ?>\n\nApakah Anda yakin ingin memblokir kartu ini dari mesin Gate?');">
                                       🚫 Cabut Akses
                                    </a>
                                </td>
                                <?php endif; ?>
                                
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <?php $colspan = ($role_login === 'super-admin') ? 6 : 5; ?>
                            <td colspan="<?= $colspan; ?>" style="text-align: center; color: #dc3545; font-weight: bold; padding: 20px;">
                                ❌ Tidak ditemukan data UID di tabel Gate untuk kata kunci "<?= htmlspecialchars($search); ?>".
                            </td>
                        </tr>
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
                    Menampilkan rentang data ke <strong><?= $offset + 1; ?> - <?= min($offset + $limit, $totalRows); ?></strong> dari total <strong><?= $totalRows; ?></strong> data RFID yang tersimpan di Gate.
                <?php endif; ?>
            </div>
        <?php endif; ?>

<?php include 'footer.php'; ?>

<script>
async function konfirmasiAssign(event, form) {
    event.preventDefault(); 
    
    let nim = form.nim.value.trim();
    let id_card = form.id_card.value.trim();
    
    if (!nim || !id_card) return;

    if (id_card.charAt(0) === '0') {
        id_card = id_card.substring(1);
        form.id_card.value = id_card; 
    }

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Memvalidasi NIM...';
    btn.disabled = true;

    try {
        // Melakukan Fetch ke API menggunakan manajemen_gate.php
        const response = await fetch(`manajemen_gate.php?cek_nim=${nim}`);
        const data = await response.json();

        btn.innerHTML = originalText;
        btn.disabled = false;

        if (data.error) {
            alert(`❌ Peringatan: NIM ${nim} tidak terdaftar di Master Data Mahasiswa!\n\nHarap hubungi Super-Admin untuk mengunggah CSV data mahasiswa terlebih dahulu di Dashboard.`);
            form.nim.focus();
            return false;
        }

        const pesanKonfirmasi = 
            "PERINGATAN SINKRONISASI MANUAL:\n\n" +
            "Anda akan mendaftarkan kartu KTM Lama ini ke sistem gerbang.\n" +
            "Identitas Pemilik (Dilacak dari Master Data):\n" +
            "NIM       : " + data.nim + "\n" +
            "Nama      : " + data.nama + "\n" +
            "Fakultas  : " + data.fakultas + "\n" +
            "UID Kartu : " + id_card + "\n\n" +
            "Klik OK untuk melanjutkan.";

        if (confirm(pesanKonfirmasi)) {
            form.submit();
        }
    } catch (error) {
        alert("Terjadi kesalahan jaringan.");
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
</script>