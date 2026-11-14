-- ============================================================
-- Migrasi Database: Pengaturan Status Pendaftaran (Buka/Tutup)
-- Media Belajar BTIKP Sulawesi Tenggara
-- ============================================================

-- 1. Pastikan tabel site_settings tersedia
CREATE TABLE IF NOT EXISTS `site_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Nilai awal default: Pendaftaran dalam kondisi TERTUTUP (Closed)
-- Mode default: manual, status manual: closed
INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
    ('registration_enabled', '0'),
    ('registration_mode', 'manual'),
    ('registration_manual_status', 'closed'),
    ('registration_start_at', ''),
    ('registration_end_at', ''),
    ('registration_closed_message', 'Pendaftaran akun kontributor sementara ditutup, silahkan hubungi admin Media Belajar - BTIKP Sulawesi Tenggara atau pantau terus web Media Belajar - BTIKP Sulawesi Tenggara.'),
    ('registration_timezone', 'Asia/Makassar')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- 3. Tabel Riwayat Perubahan (Audit Log Sederhana)
CREATE TABLE IF NOT EXISTS `registration_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` VARCHAR(64) NULL,
    `admin_email` VARCHAR(255) NULL,
    `mode` VARCHAR(20) NOT NULL,
    `manual_status` VARCHAR(20) NOT NULL,
    `start_at` DATETIME NULL,
    `end_at` DATETIME NULL,
    `closed_message` TEXT NULL,
    `computed_status` VARCHAR(20) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Perluas tipe role di tabel user_roles agar mendukung 'pending'
ALTER TABLE `user_roles` MODIFY COLUMN `role` ENUM('admin', 'reviewer', 'kontributor', 'user', 'pending') NOT NULL;
