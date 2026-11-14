<?php
/**
 * Registration Helper & Gatekeeper Middleware
 * Media Belajar BTIKP Sulawesi Tenggara
 *
 * Mengelola evaluasi status pendaftaran (Manual / Terjadwal)
 * secara real-time pada setiap request berbasis zona waktu WITA (Asia/Makassar).
 */

require_once __DIR__ . '/config.php';

// Pastikan zona waktu konsisten untuk seluruh kalkulasi waktu pendaftaran
date_default_timezone_set('Asia/Makassar');

const DEFAULT_CLOSED_MESSAGE = 'Pendaftaran akun kontributor sementara ditutup, silahkan hubungi admin Media Belajar - BTIKP Sulawesi Tenggara atau pantau terus web Media Belajar - BTIKP Sulawesi Tenggara.';

/**
 * Memastikan tabel site_settings dan registration_audit_logs siap digunakan
 * Kondisi awal pendaftaran adalah TERTUTUP (Closed).
 */
function ensureRegistrationTables(PDO $db): void {
    static $ensured = false;
    if ($ensured) return;

    $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS registration_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id VARCHAR(64) NULL,
        admin_email VARCHAR(255) NULL,
        mode VARCHAR(20) NOT NULL,
        manual_status VARCHAR(20) NOT NULL,
        start_at DATETIME NULL,
        end_at DATETIME NULL,
        closed_message TEXT NULL,
        computed_status VARCHAR(20) NOT NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Pastikan kolom role pada tabel user_roles mendukung 'pending' atau VARCHAR agar tidak terjadi error 1265
    try {
        $db->exec("ALTER TABLE `user_roles` MODIFY COLUMN `role` VARCHAR(50) NOT NULL");
    } catch (\Throwable $t) {
        // Abaikan jika tabel belum ada atau sudah bertipe VARCHAR
    }

    // Inisialisasi pengaturan awal dengan kondisi TERTUTUP jika belum pernah ada
    $defaults = [
        'registration_enabled'       => '0',
        'registration_mode'          => 'manual',
        'registration_manual_status' => 'closed',
        'registration_start_at'      => '',
        'registration_end_at'        => '',
        'registration_closed_message'=> DEFAULT_CLOSED_MESSAGE,
        'registration_timezone'      => 'Asia/Makassar',
    ];

    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = ?");
    $stmtInsert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)");

    foreach ($defaults as $key => $val) {
        $stmtCheck->execute([$key]);
        if ($stmtCheck->fetchColumn() == 0) {
            $stmtInsert->execute([$key, $val]);
        }
    }

    $ensured = true;
}

/**
 * Mengambil semua baris site_settings dalam bentuk key-value array
 */
function getRawSettings(PDO $db): array {
    ensureRegistrationTables($db);
    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    return $settings;
}

/**
 * Menghitung status pendaftaran saat request berlangsung (Real-time).
 * - Jika mode manual: status mengikuti manual_status ('open' / 'closed').
 * - Jika mode terjadwal: status terbuka HANYA jika waktu WITA sekarang berada di antara waktu buka dan waktu tutup.
 */
function calculateRegistrationStatus(array $rawSettings, ?DateTimeImmutable $now = null): array {
    $tz = new DateTimeZone('Asia/Makassar');
    $now = $now ?? new DateTimeImmutable('now', $tz);
    $nowStr = $now->format('Y-m-d H:i:s');

    $mode = strtolower(trim($rawSettings['registration_mode'] ?? 'manual'));
    if (!in_array($mode, ['manual', 'scheduled'], true)) {
        $mode = 'manual';
    }

    $manualStatus = strtolower(trim($rawSettings['registration_manual_status'] ?? ''));
    if ($manualStatus === '') {
        // Fallback jika hanya registration_enabled yang tersimpan sebelumnya
        $legacy = $rawSettings['registration_enabled'] ?? '0';
        $manualStatus = ($legacy === '1' || $legacy === 'true') ? 'open' : 'closed';
    }

    $startAt = trim($rawSettings['registration_start_at'] ?? '');
    $endAt = trim($rawSettings['registration_end_at'] ?? '');
    $closedMessage = trim($rawSettings['registration_closed_message'] ?? '');
    if ($closedMessage === '') {
        $closedMessage = DEFAULT_CLOSED_MESSAGE;
    }

    $isOpen = false;
    $reason = '';
    $notice = '';

    if ($mode === 'manual') {
        $isOpen = ($manualStatus === 'open' || $manualStatus === '1');
        if ($isOpen) {
            $reason = 'Pendaftaran saat ini DIBUKA (Mode Manual)';
        } else {
            $reason = 'Pendaftaran saat ini DITUTUP (Mode Manual)';
            $notice = $closedMessage;
        }
    } else {
        // Mode Terjadwal
        $startDate = null;
        $endDate = null;

        if ($startAt !== '') {
            try {
                $startDate = new DateTimeImmutable($startAt, $tz);
            } catch (\Exception $e) {
                $startDate = null;
            }
        }

        if ($endAt !== '') {
            try {
                $endDate = new DateTimeImmutable($endAt, $tz);
            } catch (\Exception $e) {
                $endDate = null;
            }
        }

        if (!$startDate || !$endDate) {
            $isOpen = false;
            $reason = 'Jadwal pendaftaran belum diatur dengan lengkap oleh Admin.';
            $notice = $closedMessage;
        } elseif ($endDate <= $startDate) {
            $isOpen = false;
            $reason = 'Pengaturan jadwal tidak valid (Waktu tutup harus lebih besar dari waktu buka).';
            $notice = $closedMessage;
        } elseif ($now < $startDate) {
            $isOpen = false;
            $formattedStart = $startDate->format('d/m/Y H:i');
            $formattedEnd = $endDate->format('d/m/Y H:i');
            $reason = "Pendaftaran belum dibuka. Pendaftaran dijadwalkan buka pada {$formattedStart} WITA.";
            $notice = $closedMessage . "\n\nInformasi Jadwal: Pendaftaran dibuka pada " . $startDate->format('d F Y, Pukul H:i') . " s/d " . $endDate->format('d F Y, Pukul H:i') . " WITA.";
        } elseif ($now > $endDate) {
            $isOpen = false;
            $formattedEnd = $endDate->format('d/m/Y H:i');
            $reason = "Pendaftaran telah berakhir pada {$formattedEnd} WITA.";
            $notice = $closedMessage . "\n\nInformasi Jadwal: Masa pendaftaran telah berakhir pada " . $endDate->format('d F Y, Pukul H:i') . " WITA.";
        } else {
            $isOpen = true;
            $formattedEnd = $endDate->format('d/m/Y H:i');
            $reason = "Pendaftaran sedang DIBUKA sesuai jadwal aktif (Hingga {$formattedEnd} WITA).";
        }
    }

    return [
        'is_open'                    => $isOpen,
        'registration_enabled'       => $isOpen, // Kompatibilitas dengan SPA frontend yang sudah ada
        'registration_mode'          => $mode,
        'registration_manual_status' => $manualStatus,
        'registration_start_at'      => $startAt,
        'registration_end_at'        => $endAt,
        'registration_closed_message'=> $closedMessage,
        'current_time_wita'          => $nowStr,
        'timezone'                   => 'Asia/Makassar (WITA)',
        'status_label'               => $isOpen ? 'Terbuka' : 'Tertutup',
        'reason'                     => $reason,
        'notice'                     => $notice ?: $closedMessage,
    ];
}

/**
 * Helper global untuk membaca status pendaftaran saat ini
 */
function getRegistrationStatus(?PDO $db = null): array {
    $db = $db ?? getDB();
    $raw = getRawSettings($db);
    return calculateRegistrationStatus($raw);
}

/**
 * Middleware/Gatekeeper: Wajibkan status pendaftaran terbuka.
 * Jika tertutup, hentikan eksekusi dan kirim respons HTTP 403 Forbidden.
 */
function requireRegistrationOpen(?PDO $db = null): array {
    $status = getRegistrationStatus($db);
    if (!$status['is_open']) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'              => 'error',
            'error'               => !empty($status['notice']) ? $status['notice'] : 'Pendaftaran akun kontributor saat ini sedang ditutup.',
            'registration_closed' => true,
            'details'             => $status,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $status;
}

/**
 * Menyimpan pembaruan pengaturan pendaftaran oleh Admin dengan validasi ketat
 */
function saveRegistrationSettings(PDO $db, array $data, ?array $adminUser = null): array {
    ensureRegistrationTables($db);

    $currentRaw = getRawSettings($db);
    $tz = new DateTimeZone('Asia/Makassar');

    // 1. Tentukan Mode
    $mode = isset($data['registration_mode']) ? strtolower(trim($data['registration_mode'])) : ($currentRaw['registration_mode'] ?? 'manual');
    if (!in_array($mode, ['manual', 'scheduled'], true)) {
        jsonResponse(['error' => 'Mode pendaftaran tidak valid. Pilih "manual" atau "scheduled".'], 400);
    }

    // 2. Tentukan Manual Status
    $manualStatus = $currentRaw['registration_manual_status'] ?? 'closed';
    if (isset($data['registration_manual_status'])) {
        $val = $data['registration_manual_status'];
        if (is_bool($val)) {
            $manualStatus = $val ? 'open' : 'closed';
        } else {
            $valStr = strtolower(trim((string)$val));
            $manualStatus = in_array($valStr, ['open', '1', 'true'], true) ? 'open' : 'closed';
        }
    } elseif (isset($data['registration_enabled']) && $mode === 'manual') {
        // Dukung update langsung via toggle registration_enabled
        $val = $data['registration_enabled'];
        $isOpen = is_bool($val) ? $val : in_array(strtolower(trim((string)$val)), ['1', 'true', 'open'], true);
        $manualStatus = $isOpen ? 'open' : 'closed';
    }

    // 3. Tentukan Jadwal
    $startAt = isset($data['registration_start_at']) ? trim((string)$data['registration_start_at']) : ($currentRaw['registration_start_at'] ?? '');
    $endAt = isset($data['registration_end_at']) ? trim((string)$data['registration_end_at']) : ($currentRaw['registration_end_at'] ?? '');

    // Validasi mode terjadwal: waktu tutup harus lebih besar dari waktu buka
    if ($mode === 'scheduled') {
        if ($startAt === '' || $endAt === '') {
            jsonResponse(['error' => 'Waktu buka dan waktu tutup pendaftaran wajib diisi untuk mode terjadwal.'], 400);
        }

        try {
            $startDate = new DateTimeImmutable($startAt, $tz);
            $endDate = new DateTimeImmutable($endAt, $tz);
        } catch (\Exception $e) {
            jsonResponse(['error' => 'Format tanggal dan jam tidak valid.'], 400);
        }

        if ($endDate <= $startDate) {
            jsonResponse(['error' => 'Waktu tutup pendaftaran harus lebih besar dari waktu buka.'], 400);
        }

        // Standardisasi format penyimpanan Y-m-d H:i:s
        $startAt = $startDate->format('Y-m-d H:i:s');
        $endAt = $endDate->format('Y-m-d H:i:s');
    }

    // 4. Pesan Kustom Saat Ditutup
    $closedMessage = isset($data['registration_closed_message'])
        ? trim((string)$data['registration_closed_message'])
        : ($currentRaw['registration_closed_message'] ?? DEFAULT_CLOSED_MESSAGE);
    if ($closedMessage === '') {
        $closedMessage = DEFAULT_CLOSED_MESSAGE;
    }

    // 5. Hitung status aktif (is_open) berdasarkan data baru
    $updatedSettings = array_merge($currentRaw, [
        'registration_mode'          => $mode,
        'registration_manual_status' => $manualStatus,
        'registration_start_at'      => $startAt,
        'registration_end_at'        => $endAt,
        'registration_closed_message'=> $closedMessage,
        'registration_timezone'      => 'Asia/Makassar',
    ]);

    $calc = calculateRegistrationStatus($updatedSettings);
    $computedEnabled = $calc['is_open'] ? '1' : '0';
    $updatedSettings['registration_enabled'] = $computedEnabled;

    // 6. Simpan ke database site_settings
    $stmtUpsert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) 
        VALUES (:key, :val) 
        ON DUPLICATE KEY UPDATE setting_value = :val2");

    $saveKeys = [
        'registration_enabled'       => $computedEnabled,
        'registration_mode'          => $mode,
        'registration_manual_status' => $manualStatus,
        'registration_start_at'      => $startAt,
        'registration_end_at'        => $endAt,
        'registration_closed_message'=> $closedMessage,
        'registration_timezone'      => 'Asia/Makassar',
    ];

    foreach ($saveKeys as $k => $v) {
        $stmtUpsert->execute([
            ':key'  => $k,
            ':val'  => (string)$v,
            ':val2' => (string)$v,
        ]);
    }

    // Simpan juga pengaturan lain yang dikirim selain registration_* (jika ada)
    foreach ($data as $k => $v) {
        if (!array_key_exists($k, $saveKeys)) {
            $strV = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
            $stmtUpsert->execute([
                ':key'  => $k,
                ':val'  => $strV,
                ':val2' => $strV,
            ]);
            $updatedSettings[$k] = $strV;
        }
    }

    // 7. Catat Audit Log
    try {
        $adminId = $adminUser['sub'] ?? ($adminUser['id'] ?? null);
        $adminEmail = $adminUser['email'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

        $stmtLog = $db->prepare("INSERT INTO registration_audit_logs 
            (admin_id, admin_email, mode, manual_status, start_at, end_at, closed_message, computed_status, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        $stmtLog->execute([
            $adminId,
            $adminEmail,
            $mode,
            $manualStatus,
            $startAt ?: null,
            $endAt ?: null,
            $closedMessage,
            $calc['is_open'] ? 'open' : 'closed',
            $ip,
        ]);
    } catch (\Throwable $t) {
        logDbError("Gagal mencatat audit log pendaftaran: " . $t->getMessage());
    }

    return $calc;
}
