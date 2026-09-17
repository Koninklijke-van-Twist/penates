<?php

ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/penates_data.php';
require_once __DIR__ . '/penates_xlsx.php';

function penates_export_date_time(string $value): string
{
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Europe/Amsterdam'))
            ->format('d-m-Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function penates_export_overview_headers(): array
{
    return [
        'Bedrijf', 'Vestiging', 'Project / bin', 'Bin-omschrijving',
        'Artikel', 'Variant', 'Omschrijving', 'Omschrijving 2',
        'Aantal', 'Eenheid', 'Stukprijs', 'Voorraadwaarde',
        'Artikelvoorraad', 'Minimumvoorraad', 'Openstaande behoefte', 'Gepickt',
        'Projectstatus', 'Werkorders', 'Opslaglocaties', 'Redenen', 'Gecontroleerd',
    ];
}

function penates_export_overview_row(array $row): array
{
    $workorders = [];
    foreach (($row['workorders'] ?? []) as $workorder) {
        $number = trim((string) ($workorder['no'] ?? ''));
        $status = trim((string) ($workorder['status'] ?? ''));
        if ($number !== '') {
            $workorders[] = trim($number . ($status !== '' ? ' · ' . $status : ''));
        }
    }

    $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : [];
    $locations = [];
    foreach ((is_array($warehouse['locations'] ?? null) ? $warehouse['locations'] : []) as $location) {
        $bin = trim((string) ($location['bin'] ?? ''));
        if ($bin !== '') {
            $locations[] = penates_format_qty((float) ($location['quantity'] ?? 0)) . 'x ' . $bin;
        }
    }

    $project = is_array($row['project'] ?? null) ? $row['project'] : [];
    $projectStatus = trim((string) ($project['document_status'] ?? $project['status'] ?? ''));

    return [
        (string) ($row['company'] ?? ''),
        (string) ($row['location'] ?? ''),
        (string) ($row['bin'] ?? ''),
        (string) ($row['bin_description'] ?? ''),
        (string) ($row['item_no'] ?? ''),
        (string) ($row['variant_code'] ?? ''),
        (string) ($row['description'] ?? ''),
        (string) ($row['description_2'] ?? ''),
        (float) ($row['quantity'] ?? 0),
        (string) ($row['unit'] ?? ''),
        round((float) ($row['unit_cost'] ?? 0), 2),
        round((float) ($row['stock_value'] ?? 0), 2),
        (float) ($row['inventory'] ?? 0),
        (float) ($row['minimum_stock'] ?? 0),
        (float) ($row['remaining_need'] ?? 0),
        (float) ($row['workorder_picked_quantity'] ?? 0),
        $projectStatus,
        implode(', ', $workorders),
        implode(', ', $locations),
        implode(' | ', array_map('strval', $row['reasons'] ?? [])),
        penates_export_date_time((string) ($row['checked_at'] ?? '')),
    ];
}

function penates_export_location_headers(): array
{
    return [
        'Bedrijf', 'Artikel', 'Variant', 'Omschrijving',
        'Vestiging', 'Opslaglocatie', 'Omschrijving locatie',
        'Aantal', 'Eenheid', 'Stukprijs', 'Waarde',
    ];
}

/**
 * Eén regel per artikel/opslaglocatie. Hetzelfde artikel komt in meerdere
 * projectbins voor, dus dubbele combinaties worden samengevoegd.
 */
function penates_export_location_rows(array $rows): array
{
    $unique = [];
    foreach ($rows as $row) {
        $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : [];
        foreach ((is_array($warehouse['locations'] ?? null) ? $warehouse['locations'] : []) as $location) {
            $bin = trim((string) ($location['bin'] ?? ''));
            if ($bin === '') {
                continue;
            }
            $key = implode('|', [
                (string) ($row['company'] ?? ''),
                (string) ($row['item_no'] ?? ''),
                (string) ($row['variant_code'] ?? ''),
                (string) ($location['location'] ?? ''),
                $bin,
            ]);
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = [
                (string) ($row['company'] ?? ''),
                (string) ($row['item_no'] ?? ''),
                (string) ($row['variant_code'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($location['location'] ?? ''),
                $bin,
                (string) ($location['bin_description'] ?? ''),
                (float) ($location['quantity'] ?? 0),
                (string) ($location['unit'] ?? ''),
                round((float) ($location['unit_cost'] ?? 0), 2),
                round((float) ($location['stock_value'] ?? 0), 2),
            ];
        }
    }

    $values = array_values($unique);
    usort($values, static function (array $left, array $right): int {
        return strnatcasecmp(
            implode('|', [$left[0], $left[1], $left[4], $left[5]]),
            implode('|', [$right[0], $right[1], $right[4], $right[5]])
        );
    });

    return $values;
}

$snapshot = penates_read_snapshot();
$rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];

try {
    $overview = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $overview[] = penates_export_overview_row($row);
        }
    }

    $workbook = penates_build_workbook_xlsx([
        [
            'name' => 'Restvoorraad',
            'table' => 'Restvoorraad',
            'headers' => penates_export_overview_headers(),
            'rows' => $overview,
        ],
        [
            'name' => 'Opslaglocaties',
            'table' => 'Opslaglocaties',
            'headers' => penates_export_location_headers(),
            'rows' => penates_export_location_rows($rows),
        ],
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export mislukt: ' . $error->getMessage();
    exit;
}

$generatedAt = (string) ($snapshot['generated_at'] ?? '');
$stamp = $generatedAt !== '' ? substr(penates_export_date_time($generatedAt), 0, 10) : gmdate('d-m-Y');
$filename = 'penates-' . str_replace('-', '', $stamp) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($workbook));
header('Cache-Control: no-store');
echo $workbook;
