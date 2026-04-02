/**
 * File: script.js
 * Deskripsi: Kumpulan logika Client-Side (AJAX & DOM Manipulation)
 */

// ==========================================================
// 1. FITUR AUTO-DETECT SCANNER RFID (Halaman rfid.php)
// ==========================================================
document.addEventListener('DOMContentLoaded', function() {
    const inputRfidGate = document.getElementById('input_rfid');
    const hiddenNipGate = document.getElementById('hidden_nip');
    const infoBoxGate = document.getElementById('info_mahasiswa');
    const textNamaGate = document.getElementById('text_nama');
    const textNimGate = document.getElementById('text_nim');
    const btnAssignGate = document.getElementById('btn_assign');

    if (inputRfidGate) {
        inputRfidGate.addEventListener('keypress', async function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                let rfidVal = this.value.trim().replace(/^0+/, ''); 
                
                if (rfidVal === '') return;
                
                infoBoxGate.style.display = 'block';
                infoBoxGate.style.borderLeftColor = '#ccc';
                textNamaGate.innerHTML = '<span style="color:#666;"><i>Mencari data ke database...</i></span>';
                textNimGate.innerHTML = '';
                
                if (btnAssignGate) {
                    btnAssignGate.disabled = true;
                    btnAssignGate.style.opacity = '0.5';
                    btnAssignGate.style.cursor = 'not-allowed';
                }

                try {
                    const response = await fetch(`rfid.php?ajax_rfid=${rfidVal}`);
                    const data = await response.json();
                    
                    if (data.error) {
                        infoBoxGate.style.borderLeftColor = 'var(--danger-color)';
                        textNamaGate.innerHTML = '<span style="color: var(--danger-color);">❌ Data Tidak Ditemukan</span>';
                        textNimGate.innerHTML = 'Kartu ini belum terdaftar di antrean Cetak KTM.';
                        hiddenNipGate.value = '';
                    } else {
                        infoBoxGate.style.borderLeftColor = 'var(--success-color)';
                        textNamaGate.innerHTML = '👤 ' + data.nama;
                        textNimGate.innerHTML = 'NIM : ' + data.nim;
                        hiddenNipGate.value = data.nim;
                        
                        if (btnAssignGate) {
                            btnAssignGate.disabled = false;
                            btnAssignGate.style.opacity = '1';
                            btnAssignGate.style.cursor = 'pointer';
                        }
                    }
                } catch (err) {
                    textNamaGate.innerHTML = '<span style="color: var(--danger-color);">❌ Terjadi kesalahan jaringan.</span>';
                }
            }
        });
    }
});

// ==========================================================
// 2. HTML DIALOG POPUP LOGIC (Berdasarkan Tutorial YouTube)
// ==========================================================
function bukaPreviewDialog(event, formElement) {
    event.preventDefault(); // Mencegah form membuka tab baru
    
    const nim = formElement.nim.value;
    const idCetak = formElement.id_cetak.value;
    const bg = formElement.bg.value;
    
    const url = `cetak_ktm.php?nim=${nim}&id_cetak=${idCetak}&bg=${bg}`;
    
    const dialog = document.getElementById('previewDialog');
    const iframe = document.getElementById('previewFrame');
    
    if (dialog && iframe) {
        iframe.src = url;
        dialog.showModal(); // Membuka popup dialog HTML5 [00:00:56]
    }
}

window.tutupPreviewDialog = function(refresh = false) {
    const dialog = document.getElementById('previewDialog');
    const iframe = document.getElementById('previewFrame');
    if (dialog) {
        dialog.close(); // Tutup popup [00:02:56]
        if (iframe) iframe.src = ''; 
        if (refresh) location.reload(); // Refresh tabel antrean
    }
};

// Menutup modal jika user klik di luar area konten (klik backdrop transparan) [00:03:56]
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('previewDialog');
    if (dialog) {
        dialog.addEventListener('click', (event) => {
            const wrapper = document.querySelector('.dialog-wrapper');
            if (wrapper && !wrapper.contains(event.target)) {
                window.tutupPreviewDialog(false); 
            }
        });
    }
});

// ==========================================================
// 3. FUNGSI GLOBAL: Tutup Popup/Tab & Refresh Halaman Induk
// ==========================================================
window.tutupDanRefresh = function() {
    // 1. Cek apakah di dalam iframe (Dialog Popup)
    if (window.parent && window.parent !== window && typeof window.parent.tutupPreviewDialog === 'function') {
        window.parent.tutupPreviewDialog(true);
    }
    // 2. Cek apakah tab induk (opener) masih ada (jika terlanjur buka tab baru)
    else if (window.opener && !window.opener.closed) {
        window.opener.location.reload(); 
        window.close(); 
    } else {
        window.close();
    }
};

// ==========================================================
// 4. LOGIKA SCANNER RFID & MODAL PREVIEW CETAK
// ==========================================================
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById('rfid-modal');
    const scannerInput = document.getElementById('rfid_scanner');

    window.bukaModalRFID = function(idCetak) {
        if (!modal || !scannerInput) return;
        window.currentIdCetak = idCetak; 
        modal.style.display = 'flex';
        scannerInput.value = '';
        scannerInput.focus();
    };

    window.tutupModal = function() {
        if (modal) modal.style.display = 'none';
    };

    if (scannerInput) {
        scannerInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                let rfidVal = this.value.trim().replace(/^0+/, ''); 
                if (rfidVal !== '') {
                    prosesUpdateDanPrint(window.currentIdCetak, rfidVal);
                }
            }
        });

        document.addEventListener('click', function() {
            if (modal && modal.style.display === 'flex') {
                scannerInput.focus();
            }
        });
    }
});

// ==========================================================
// 5. EKSEKUSI API UPDATE STATUS & CETAK PRINTER
// ==========================================================
async function prosesUpdateDanPrint(idCetak, rfidCode) {
    window.tutupModal();
    try {
        const response = await fetch(`cetak_ktm.php?action=update_status&id_cetak=${idCetak}&rfid_code=${rfidCode}`);
        const result = await response.json();
        
        if (result.status === 'success') {
            window.print(); // Memanggil dialog print bawaan browser
            window.tutupDanRefresh(); // Menutup popup dan refresh antrean otomatis
        } else {
            alert('Gagal memperbarui status di database: ' + result.message);
        }
    } catch (err) {
        console.error('Error:', err);
        alert('Terjadi kesalahan koneksi ke server.');
    }
}