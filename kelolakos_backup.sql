-- KelolaKos Database Backup
-- Generated: 2026-08-20

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Create Database
-- ----------------------------
CREATE DATABASE IF NOT EXISTS `kelolakos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kelolakos`;

-- ----------------------------
-- Table structure for pemilik
-- ----------------------------
DROP TABLE IF EXISTS `pemilik`;
CREATE TABLE `pemilik` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `phone_number` VARCHAR(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Seed data for pemilik
-- ----------------------------
INSERT INTO `pemilik` (`id`, `full_name`, `email`, `password`, `phone_number`) VALUES
(1, 'Febri Rizal', 'febririzal838@gmail.com', '$2y$10$N.oqmuvMBOf/0fzxH5QkROTY1zpwB6TfjePnEhg9gf.j0m0S.jYOC', '081234567890');

-- ----------------------------
-- Table structure for penyewa
-- ----------------------------
DROP TABLE IF EXISTS `penyewa`;
CREATE TABLE `penyewa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `kamar_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Seed data for penyewa
-- ----------------------------
INSERT INTO `penyewa` (`id`, `full_name`, `email`, `password`, `phone_number`, `kamar_id`) VALUES
(1, 'Penyewa Default', 'penyewa@gmail.com', '$2y$10$k.WLtpo1uLmOTskTnjec1.VtJtyWuHf.CIWycMfHyw5NRcumIY.BS', '089876543210', 1);

-- ----------------------------
-- Table structure for kamar
-- ----------------------------
DROP TABLE IF EXISTS `kamar`;
CREATE TABLE `kamar` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `nomor_kamar` VARCHAR(50) NOT NULL,
  `tipe_kamar` VARCHAR(100) NOT NULL,
  `fasilitas` TEXT DEFAULT NULL,
  `alamat` TEXT DEFAULT NULL,
  `harga_sewa` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` ENUM('Kosong', 'Terisi', 'Menunggak') NOT NULL DEFAULT 'Kosong'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Seed data for kamar
-- ----------------------------
INSERT INTO `kamar` (`id`, `owner_id`, `nomor_kamar`, `tipe_kamar`, `fasilitas`, `alamat`, `harga_sewa`, `status`) VALUES
(1, 1, 'A-01', 'Campur', 'AC, WiFi, Kamar Mandi Dalam', 'Jl. Tebet Raya No. 12, Jakarta Selatan', 1500000.00, 'Terisi'),
(2, 1, 'B-02', 'Putri', 'AC, WiFi', 'Jl. Setiabudi Tengah No. 5, Jakarta Selatan', 1200000.00, 'Kosong'),
(3, 1, 'C-102', 'Putra', 'AC, WiFi, Kamar Mandi Dalam, TV', 'Jl. Kuningan Barat No. 8, Jakarta Selatan', 2200000.00, 'Kosong'),
(4, 1, 'D-04', 'Campur', 'Balkon, AC, Kamar Mandi Dalam', 'Jl. Kemang Selatan No. 15, Jakarta Selatan', 3500000.00, 'Kosong');

-- ----------------------------
-- Table structure for transaksi
-- ----------------------------
DROP TABLE IF EXISTS `transaksi`;
CREATE TABLE `transaksi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL DEFAULT 0,
  `penyewa_id` INT NOT NULL,
  `kamar_id` INT NOT NULL,
  `nomor_kamar` VARCHAR(50) NOT NULL,
  `total_harga` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` ENUM('Menunggu','Menunggu Verifikasi','Pending','Disetujui','Ditolak','Lunas','Belum Bayar','Menunggu Checkout','Selesai') NOT NULL DEFAULT 'Menunggu Verifikasi',
  `tanggal_pengajuan` DATE NOT NULL,
  `durasi_bulan` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Seed data for transaksi
-- ----------------------------
INSERT INTO `transaksi` (`id`, `owner_id`, `penyewa_id`, `kamar_id`, `nomor_kamar`, `total_harga`, `status`, `tanggal_pengajuan`, `durasi_bulan`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 'A-01', 1500000.00, 'Disetujui', '2026-08-19', 1, '2026-08-19 12:00:00', '2026-08-19 12:00:00');

-- ----------------------------
-- Table structure for pengaduan
-- ----------------------------
DROP TABLE IF EXISTS `pengaduan`;
CREATE TABLE `pengaduan` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `tenant_id` INT NOT NULL,
  `kamar_id` INT NOT NULL,
  `nomor_kamar` VARCHAR(50) NOT NULL,
  `jenis_pengaduan` VARCHAR(100) NOT NULL,
  `deskripsi` TEXT NOT NULL,
  `prioritas` ENUM('Rendah', 'Sedang', 'Tinggi') NOT NULL DEFAULT 'Sedang',
  `status` ENUM('Baru', 'Proses', 'Selesai') NOT NULL DEFAULT 'Baru',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
