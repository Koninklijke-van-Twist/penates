<?php

/**
 * De enige volledige BC-refresh voor Penates.
 *
 * Productie: GET /nightly.php
 * Lokaal:    php nightly.php
 */

set_time_limit(1800);
ini_set('max_execution_time', '1800');
ini_set('memory_limit', '512M');
ignore_user_abort(true);

require_once __DIR__ . '/auth.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/logincheck.php';
}
require_once __DIR__ . '/penates_data.php';

$startedAt = hrtime(true);

try {
    $snapshot = penates_run_nightly();
    $payload = [
        'ok' => ($snapshot['errors'] ?? []) === [],
        'generated_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
        'rows' => count($snapshot['rows'] ?? []),
        'companies' => $snapshot['companies'] ?? [],
        'errors' => $snapshot['errors'] ?? [],
        'total_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
    ];

    if (PHP_SAPI === 'cli') {
        echo sprintf(
            "%s generated_at=%s rows=%d duration=%dms\n",
            $payload['ok'] ? 'OK' : 'PARTIAL',
            $payload['generated_at'],
            $payload['rows'],
            $payload['total_duration_ms']
        );
        foreach ($payload['companies'] as $company) {
            echo sprintf(
                "  %s: rows=%d duration=%dms%s\n",
                (string) ($company['company'] ?? ''),
                (int) ($company['rows'] ?? 0),
                (int) ($company['duration_ms'] ?? 0),
                !empty($company['stale']) ? ' (oude data behouden)' : ''
            );
        }
        foreach ($payload['errors'] as $error) {
            echo sprintf(
                "  ERROR %s: %s\n",
                (string) ($error['company'] ?? ''),
                (string) ($error['error'] ?? '')
            );
        }
        exit($payload['ok'] ? 0 : 1);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($payload['ok'] ? 200 : 207);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $payload = [
        'ok' => false,
        'generated_at' => gmdate('c'),
        'error' => $error->getMessage(),
        'total_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
    ];
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
        exit(1);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(500);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
