import requests
from bs4 import BeautifulSoup
import csv
import time

# Konfigurasi Header CSV yang sesuai dengan aplikasi index.php Anda
CSV_HEADERS = ['NIP/NIK/ID Custom', 'Nama Lengkap', 'Kategori', 'Jabatan', 'Unit Kerja']
data_sdm_gabungan = []

def scrape_direktori_uinsa(base_url, start_id, end_id, kategori_default):
    print(f"\n🚀 Memulai ekstraksi data [{kategori_default}] dari {base_url}...")
    print(f"Memindai ID {start_id} hingga {end_id}. Mohon tunggu...")
    
    # Menyamar sebagai browser asli
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    }
    
    data_berhasil = 0
    
    for i in range(start_id, end_id + 1):
        url = f"{base_url}/{i}"
        
        try:
            response = requests.get(url, headers=headers, timeout=10)
            if response.status_code != 200:
                continue 
                
            soup = BeautifulSoup(response.content, 'html.parser')
            
            nip, nama, unit_kerja = "", "", ""
            jabatan = kategori_default 
            
            # Mencari elemen teks dalam halaman
            semua_elemen = soup.find_all(['td', 'th', 'div', 'span'])
            
            for index, elemen in enumerate(semua_elemen):
                teks = elemen.text.strip().upper()
                
                # Pemetaan Data
                if teks == 'NIP' or teks == 'NIP.':
                    nip = semua_elemen[index + 1].text.strip()
                elif teks == 'NAMA' or teks == 'NAMA.':
                    nama = semua_elemen[index + 1].text.strip()
                elif teks == 'UNIT KERJA' or teks == 'UNIT KERJA.':
                    unit_kerja = semua_elemen[index + 1].text.strip()
            
            # Validasi dan penyimpanan
            if nama and nip and nip != "-":
                print(f"✅ [ID: {i}] {nama} | NIP: {nip} | Kategori: {kategori_default}")
                data_sdm_gabungan.append([nip, nama, kategori_default, jabatan, unit_kerja])
                data_berhasil += 1
                
        except Exception as e:
            pass # Abaikan jika ada error jaringan sementara
            
        # Jeda 0.2 detik untuk menghindari pemblokiran IP oleh server
        time.sleep(0.2)
        
    print(f"Selesai! {data_berhasil} data {kategori_default} berhasil ditarik.")

# =========================================================
# EKSEKUSI PENARIKAN DATA
# Anda bisa menyesuaikan rentang ID (misal 1 sampai 2000)
# =========================================================

# 1. Tarik Data Dosen (dari web lecturer)
# Asumsi ID dosen berkisar dari 1 hingga 1500
scrape_direktori_uinsa("https://lecturer.uinsa.ac.id/index.php/example/detaildosen", 1, 2000, "Dosen")

# 2. Tarik Data Pegawai (dari web pegawai)
# Asumsi ID pegawai berkisar dari 1 hingga 1000
scrape_direktori_uinsa("https://pegawai.uinsa.ac.id/index.php/example/detaildosen", 1, 1500, "Tenaga Kependidikan")

# =========================================================
# MENYIMPAN KE FILE CSV
# =========================================================
output_filename = 'data_master_sdm_terpadu.csv'
with open(output_filename, mode='w', newline='', encoding='utf-8') as file:
    writer = csv.writer(file)
    writer.writerow(CSV_HEADERS)
    writer.writerows(data_sdm_gabungan)

print(f"\n🎉 PROSES KESELURUHAN SELESAI!")
print(f"Total {len(data_sdm_gabungan)} data gabungan berhasil diselamatkan.")
print(f"📁 Silakan upload file '{output_filename}' ke menu Dashboard Super-Admin.")