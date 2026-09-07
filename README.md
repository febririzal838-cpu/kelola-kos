# KelolaKos - Sistem Manajemen Properti Kost

Aplikasi berbasis web untuk memudahkan pemilik kost mengelola unit kamar, melacak transaksi pembayaran penyewa, serta memproses pengaduan/keluhan fasilitas kost secara efisien.

## Fitur Utama
* **Manajemen Kamar & Properti**: Kelola status kamar (Kosong, Terisi, Menunggak), harga, tipe kamar (Putra, Putri, Campur), dan fasilitas.
* **Transaksi Sewa**: Catat pengajuan sewa masuk dari penyewa, durasi sewa, dan verifikasi status pembayaran.
* **Kanal Pengaduan**: Fasilitasi penyewa untuk melaporkan kerusakan atau komplain langsung ke pemilik kos.
* **Laporan Keuangan**: Laporan otomatis pemasukan bulanan dan persentase okupansi properti yang dapat diunduh/diekspor.

---

## Panduan Pemasangan (Instalasi & Koneksi Database)

Proyek ini telah dilengkapi dengan fitur **Auto-Setup Koneksi & Database**. Ketika Anda menjalankan proyek ini untuk pertama kali di server lokal (misalnya menggunakan Laragon/XAMPP):

1. **Jalankan Apache & MySQL** di panel control lokal Anda.
2. **Kloning Proyek** ke direktori server web Anda (contoh: `C:/laragon/www/kelola-kos-main`).
3. **Buka Aplikasi di Browser**: Akses `http://localhost/kelola-kos-main/index.php`.
4. **Selesai!** Berkas [config/koneksi.php](config/koneksi.php) secara otomatis mendeteksi jika database `kelolakos` belum ada di MySQL lokal Anda. Sistem akan membuat database tersebut secara dinamis dan memulihkan seluruh struktur tabel beserta data default (seeder) langsung dari file [kelolakos_backup.sql](kelolakos_backup.sql).

---

## Kredensial Default Uji Coba

Setelah instalasi otomatis selesai, Anda dapat langsung login menggunakan akun default berikut:

### 1. Akun Pemilik Kos
* **Email**: `febririzal838@gmail.com`
* **Password**: `kelolakos123`

### 2. Akun Penyewa Kos
* **Email**: `penyewa@gmail.com`
* **Password**: `kelolakos123`

---

## Pemulihan Manual (Jika Diperlukan)
Jika Anda ingin memulihkan database secara manual melalui **HeidiSQL / phpMyAdmin**:
1. Buat database baru bernama `kelolakos`.
2. Impor file cadangan [kelolakos_backup.sql](kelolakos_backup.sql) ke dalam database tersebut.
