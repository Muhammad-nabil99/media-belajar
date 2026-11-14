<?php
/**
 * Site Settings API
 * Media Belajar BTIKP Sulawesi Tenggara
 *
 * GET   /api/settings.php           → Get public settings (registration status, turnstile, etc.)
 * PATCH /api/settings.php           → Update settings (admin only)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/registration_helper.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
    case 'PATCH':
    case 'PUT':
        handlePatch();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function handleGet(): void {
    $db = getDB();
    ensureRegistrationTables($db);

    $raw = getRawSettings($db);
    $regStatus = calculateRegistrationStatus($raw);

    $result = [
        'turnstile_enabled' => defined('TURNSTILE_ENABLED') ? TURNSTILE_ENABLED : true,
    ];

    // Sertakan semua setting umum dari database
    foreach ($raw as $k => $v) {
        if (!str_starts_with($k, 'registration_')) {
            $result[$k] = $v;
        }
    }

    // Gabungkan dengan kalkulasi status pendaftaran (WITA)
    $result = array_merge($result, $regStatus);

    jsonResponse($result);
}

function handlePatch(): void {
    $user = requireAuth();
    $uid = $user['sub'] ?? ($user['id'] ?? '');
    if (!$uid || !userHasRole($uid, 'admin')) {
        jsonResponse(['error' => 'Forbidden: Akses hanya untuk Administrator'], 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'Data input tidak valid'], 400);
    }

    $db = getDB();
    saveRegistrationSettings($db, $data, $user);

    handleGet();
}
