<?php
/**
 * Dedicated Registration Settings & Audit Log API
 * Media Belajar BTIKP Sulawesi Tenggara
 *
 * GET   /api/registration_settings.php   → Get registration status & schedule (public, audit logs if admin)
 * PATCH /api/registration_settings.php   → Update registration settings (admin only)
 * POST  /api/registration_settings.php   → Update registration settings (admin only)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/registration_helper.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetRegistration();
        break;
    case 'POST':
    case 'PATCH':
    case 'PUT':
        handleUpdateRegistration();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function handleGetRegistration(): void {
    $db = getDB();
    ensureRegistrationTables($db);

    $raw = getRawSettings($db);
    $status = calculateRegistrationStatus($raw);

    // Cek apakah pemohon adalah admin, jika ya sertakan audit logs
    $currentUser = getCurrentUser();
    $isAdmin = false;
    if ($currentUser && !empty($currentUser['sub'])) {
        $isAdmin = userHasRole($currentUser['sub'], 'admin');
    }

    if ($isAdmin) {
        $stmtLogs = $db->query("SELECT id, admin_email, mode, manual_status, start_at, end_at, computed_status, ip_address, created_at 
            FROM registration_audit_logs 
            ORDER BY created_at DESC 
            LIMIT 10");
        $status['audit_logs'] = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    }

    jsonResponse($status);
}

function handleUpdateRegistration(): void {
    $user = requireAuth();
    $uid = $user['sub'] ?? ($user['id'] ?? '');
    if (!$uid || !userHasRole($uid, 'admin')) {
        jsonResponse(['error' => 'Forbidden: Akses khusus Administrator'], 403);
    }

    $data = jsonBody();
    if (empty($data)) {
        jsonResponse(['error' => 'Data pengaturan pendaftaran tidak boleh kosong.'], 400);
    }

    $db = getDB();
    $updatedStatus = saveRegistrationSettings($db, $data, $user);

    jsonResponse([
        'ok'      => true,
        'message' => 'Pengaturan pendaftaran berhasil diperbarui.',
        'data'    => $updatedStatus,
    ]);
}
