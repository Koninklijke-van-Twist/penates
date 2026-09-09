<?php

ini_set('display_errors', '0');
ini_set('memory_limit', '256M');
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/penates_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function penates_refresh_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    penates_refresh_response(405, ['ok' => false, 'error' => 'Alleen POST is toegestaan.']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$token = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['penates_csrf_token'] ?? '');
if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
    penates_refresh_response(403, ['ok' => false, 'error' => 'Ongeldige sessie. Vernieuw de pagina.']);
}

$rowId = trim((string) ($_POST['row_id'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $rowId)) {
    penates_refresh_response(400, ['ok' => false, 'error' => 'Ongeldige regel-id.']);
}

try {
    $result = penates_recheck_snapshot_row($rowId);
    penates_refresh_response(200, [
        'ok' => true,
        'keep' => (bool) ($result['keep'] ?? false),
        'row' => $result['row'] ?? null,
        'message' => (string) ($result['message'] ?? ''),
    ]);
} catch (Throwable $error) {
    penates_refresh_response(500, ['ok' => false, 'error' => $error->getMessage()]);
}
