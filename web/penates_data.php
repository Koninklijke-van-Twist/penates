<?php

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

const PENATES_SNAPSHOT_VERSION = 1;
const PENATES_SNAPSHOT_FILE = __DIR__ . '/data/penates_snapshot.json';
const PENATES_SNAPSHOT_LOCK = __DIR__ . '/data/penates_snapshot.lock';
const PENATES_ODATA_BATCH_SIZE = 12;

const PENATES_BIN_SELECT = 'Location_Code,Code,Description,Empty,KVT_Job_Bin';
const PENATES_CONTENT_SELECT = 'Location_Code,Bin_Code,Item_No,Variant_Code,Unit_of_Measure_Code,Quantity_Base,Pick_Quantity_Base,CalcQtyAvailToTakeUOM';
const PENATES_WORKORDER_SELECT = 'No,Job_No,Job_Task_No,Task_Description,Status,KVT_Document_Status,KVT_No_Material_Needed,Start_Date';
const PENATES_LINE_SELECT = 'Job_No,Job_Task_No,Line_No,Type,No,Description,Variant_Code,Quantity,Quantity_Base,Unit_of_Measure_Code,KVT_Qty_Picked,KVT_Completely_Picked,LVS_Cancelled_Original_Line,LVS_Work_Order_No,Location_Code,Bin_Code';
const PENATES_ITEM_SELECT = 'No,Description,LVS_Description_2,Description_2,Base_Unit_of_Measure,Safety_Stock_Quantity,Inventory,Unit_Cost';

const PENATES_REASON_LABELS = [
    'below_minimum' => 'Artikelvoorraad is onder minimumvoorraad',
    'insufficient_stock' => 'Te weinig voorraad voor open werkorderbehoefte',
    'no_workorder' => 'Geen gekoppeld werkorder gevonden',
    'workorders_finished' => 'Alle gekoppelde werkorders zijn afgerond of geannuleerd',
    'item_not_on_workorder' => 'Artikel staat niet op een relevant actief werkorder',
    'no_material_needed' => 'Actieve werkorders geven aan dat geen materiaal nodig is',
    'fully_picked' => 'Alle overeenkomende werkorderregels zijn volledig gepickt',
];

function penates_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

function penates_company_entity_url(
    string $baseUrl,
    string $environment,
    string $company,
    string $entitySet,
    array $query = []
): string {
    $safeCompany = rawurlencode(penates_escape_odata_string($company));
    $url = rtrim($baseUrl, '/')
        . '/' . rawurlencode($environment)
        . "/ODataV4/Company('" . $safeCompany . "')/"
        . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

/**
 * Leest alle OData-pagina's rechtstreeks uit BC. Penates gebruikt geen
 * OData-cache: alleen de door nightly gebouwde snapshot is de dagcache.
 */
function penates_fetch_url_live(string $url, array $auth): array
{
    $rows = [];
    $next = $url;

    while ($next !== '') {
        $response = odata_get_json($next, $auth);
        $page = $response['value'] ?? null;
        if (!is_array($page)) {
            throw new RuntimeException("OData-response voor {$url} bevat geen value-array.");
        }

        foreach ($page as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $nextLink = $response['@odata.nextLink'] ?? '';
        $next = is_string($nextLink) ? $nextLink : '';
    }

    return $rows;
}

function penates_fetch_rows_live(string $company, string $entitySet, array $query = []): array
{
    global $baseUrl;

    $environment = auth_get_environment_for_company($company);
    $auth = auth_get_auth_for_environment($environment);
    $url = penates_company_entity_url($baseUrl, $environment, $company, $entitySet, $query);

    return penates_fetch_url_live($url, $auth);
}

function penates_odata_equals(string $field, string $value): string
{
    return $field . " eq '" . penates_escape_odata_string($value) . "'";
}

function penates_odata_or(string $field, array $values): string
{
    $parts = [];
    foreach (array_values(array_unique(array_filter(array_map('strval', $values)))) as $value) {
        $parts[] = penates_odata_equals($field, $value);
    }
    return implode(' or ', $parts);
}

function penates_chunks(array $values): array
{
    return array_chunk(array_values(array_unique(array_filter(array_map('strval', $values)))), PENATES_ODATA_BATCH_SIZE);
}

function penates_fetch_project_bins(string $company): array
{
    try {
        return penates_fetch_rows_live($company, 'Bins', [
            '$select' => PENATES_BIN_SELECT,
            '$filter' => 'KVT_Job_Bin eq true and Empty eq false',
            '$orderby' => 'Location_Code asc,Code asc',
        ]);
    } catch (Throwable $error) {
        // Niet ieder actief environment heeft de KVT-magazijnextensie.
        // Zonder deze marker kunnen bins niet betrouwbaar als projectbin
        // worden aangemerkt, dus levert dat bedrijf bewust geen regels.
        if (str_contains($error->getMessage(), "property named 'KVT_Job_Bin'")) {
            return [];
        }
        throw $error;
    }
}

function penates_fetch_bin_contents(string $company, array $bins): array
{
    $codesByLocation = [];
    foreach ($bins as $bin) {
        $location = trim((string) ($bin['Location_Code'] ?? ''));
        $code = trim((string) ($bin['Code'] ?? ''));
        if ($location !== '' && $code !== '') {
            $codesByLocation[$location][] = $code;
        }
    }

    $rows = [];
    foreach ($codesByLocation as $location => $codes) {
        foreach (penates_chunks($codes) as $chunk) {
            $binFilter = penates_odata_or('Bin_Code', $chunk);
            $rows = array_merge($rows, penates_fetch_rows_live($company, 'BinContent', [
                '$select' => PENATES_CONTENT_SELECT,
                '$filter' => penates_odata_equals('Location_Code', $location)
                    . ' and Quantity_Base gt 0 and (' . $binFilter . ')',
            ]));
        }
    }

    return $rows;
}

function penates_fetch_workorders_for_bins(string $company, array $binCodes): array
{
    $rows = [];
    foreach (penates_chunks($binCodes) as $chunk) {
        $rows = array_merge($rows, penates_fetch_rows_live($company, 'Werkorders', [
            '$select' => PENATES_WORKORDER_SELECT,
            '$filter' => penates_odata_or('Job_No', $chunk),
        ]));
        $rows = array_merge($rows, penates_fetch_rows_live($company, 'Werkorders', [
            '$select' => PENATES_WORKORDER_SELECT,
            '$filter' => penates_odata_or('No', $chunk),
        ]));
    }
    return penates_unique_rows($rows, static fn(array $row): string => (string) ($row['No'] ?? ''));
}

function penates_fetch_lines_for_context(string $company, array $binCodes, array $workorders): array
{
    $jobNumbers = $binCodes;

    foreach ($workorders as $workorder) {
        $jobNumbers[] = (string) ($workorder['Job_No'] ?? '');
    }

    $rows = [];
    foreach (penates_chunks($jobNumbers) as $chunk) {
        $rows = array_merge($rows, penates_fetch_rows_live($company, 'WerkordersLines', [
            '$select' => PENATES_LINE_SELECT,
            '$filter' => "Type eq 'Artikel' and (" . penates_odata_or('Job_No', $chunk) . ')',
        ]));
    }

    return penates_unique_rows($rows, static function (array $row): string {
        return implode('|', [
            (string) ($row['Job_No'] ?? ''),
            (string) ($row['Job_Task_No'] ?? ''),
            (string) ($row['Line_No'] ?? ''),
            (string) ($row['LVS_Work_Order_No'] ?? ''),
        ]);
    });
}

function penates_fetch_items(string $company, array $itemNumbers): array
{
    $rows = [];
    try {
        foreach (penates_chunks($itemNumbers) as $chunk) {
            $rows = array_merge($rows, penates_fetch_rows_live($company, 'AppItemCard', [
                '$select' => PENATES_ITEM_SELECT,
                '$filter' => penates_odata_or('No', $chunk),
            ]));
        }
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), "property named '")) {
            throw $error;
        }
        $rows = [];
        $fallbackSelect = 'No,Description,LVS_Description_2,Description_2,Base_Unit_of_Measure,Unit_Cost';
        foreach (penates_chunks($itemNumbers) as $chunk) {
            $rows = array_merge($rows, penates_fetch_rows_live($company, 'AppItems', [
                '$select' => $fallbackSelect,
                '$filter' => penates_odata_or('No', $chunk),
            ]));
        }
    }
    return penates_unique_rows($rows, static fn(array $row): string => (string) ($row['No'] ?? ''));
}

function penates_unique_rows(array $rows, callable $keyBuilder): array
{
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) $keyBuilder($row));
        if ($key !== '') {
            $result[$key] = $row;
        }
    }
    return array_values($result);
}

function penates_normalize_text(string $value): string
{
    $value = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function penates_is_terminal_status(string $status): bool
{
    $normalized = penates_normalize_text($status);
    if ($normalized === '') {
        return false;
    }

    $terminal = [
        'ondertekend', 'gecontroleerd', 'gefactureerd', 'afgesloten', 'geannuleerd',
        'signed', 'checked', 'invoiced', 'closed', 'cancelled', 'canceled',
        'unterschrieben', 'geprüft', 'fakturiert', 'abgeschlossen', 'storniert',
    ];

    return in_array($normalized, $terminal, true);
}

function penates_is_cancelled_line(array $line): bool
{
    return filter_var($line['LVS_Cancelled_Original_Line'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function penates_line_is_fully_picked(array $line): bool
{
    if (filter_var($line['KVT_Completely_Picked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return true;
    }

    $quantity = (float) ($line['Quantity_Base'] ?? $line['Quantity'] ?? 0);
    $picked = (float) ($line['KVT_Qty_Picked'] ?? 0);
    return $quantity > 0 && $picked >= $quantity;
}

function penates_line_remaining_need(array $line): float
{
    if (penates_is_cancelled_line($line) || penates_line_is_fully_picked($line)) {
        return 0.0;
    }

    $quantity = (float) ($line['Quantity_Base'] ?? $line['Quantity'] ?? 0);
    $picked = (float) ($line['KVT_Qty_Picked'] ?? 0);
    return max(0.0, $quantity - $picked);
}

function penates_item_minimum_stock(array $item): float
{
    return max(0.0, (float) ($item['Safety_Stock_Quantity'] ?? 0));
}

function penates_item_inventory(array $item): float
{
    foreach (['Inventory', 'InventoryField'] as $field) {
        if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '') {
            return (float) $item[$field];
        }
    }
    return 0.0;
}

function penates_item_unit_cost(array $item): float
{
    return max(0.0, (float) ($item['Unit_Cost'] ?? $item['Unit_Cost_LCY'] ?? 0));
}

function penates_format_qty(float $value): string
{
    $formatted = number_format($value, 2, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

function penates_line_belongs_to_workorder(array $line, array $workorder): bool
{
    $lineWorkorder = trim((string) ($line['LVS_Work_Order_No'] ?? ''));
    $workorderNo = trim((string) ($workorder['No'] ?? ''));
    if ($lineWorkorder !== '') {
        return $workorderNo !== '' && $lineWorkorder === $workorderNo;
    }

    $lineJob = trim((string) ($line['Job_No'] ?? ''));
    $lineTask = trim((string) ($line['Job_Task_No'] ?? ''));
    $workorderJob = trim((string) ($workorder['Job_No'] ?? ''));
    $workorderTask = trim((string) ($workorder['Job_Task_No'] ?? ''));

    return $lineJob !== ''
        && $lineJob === $workorderJob
        && $lineTask !== ''
        && ($lineTask === $workorderTask || $lineTask === $workorderNo);
}

function penates_same_item(array $content, array $line): bool
{
    $itemMatches = trim((string) ($content['Item_No'] ?? '')) === trim((string) ($line['No'] ?? ''));
    if (!$itemMatches) {
        return false;
    }

    return trim((string) ($content['Variant_Code'] ?? '')) === trim((string) ($line['Variant_Code'] ?? ''));
}

function penates_workorder_summary(array $workorder): array
{
    return [
        'no' => trim((string) ($workorder['No'] ?? '')),
        'status' => trim((string) ($workorder['Status'] ?? '')),
        'document_status' => trim((string) ($workorder['KVT_Document_Status'] ?? '')),
        'task' => trim((string) ($workorder['Task_Description'] ?? '')),
        'start_date' => trim((string) ($workorder['Start_Date'] ?? '')),
    ];
}

function penates_reason_labels(array $rows = []): array
{
    $labels = PENATES_REASON_LABELS;
    foreach ($rows as $row) {
        foreach (($row['reason_codes'] ?? []) as $index => $code) {
            $code = (string) $code;
            if ($code === '' || isset($labels[$code])) {
                continue;
            }
            $labels[$code] = (string) (($row['reasons'] ?? [])[$index] ?? $code);
        }
    }
    return $labels;
}

/**
 * Geeft null terug als de binvoorraad in orde is: een actief werkorder
 * heeft het artikel nog nodig én er ligt genoeg, of restvoorraad dekt
 * precies de minimumvoorraad.
 */
function penates_classify_content(
    string $company,
    array $bin,
    array $content,
    array $workorders,
    array $lines,
    array $item = []
): ?array {
    $binCode = trim((string) ($bin['Code'] ?? $content['Bin_Code'] ?? ''));
    $associated = array_values(array_filter($workorders, static function (array $workorder) use ($binCode): bool {
        return trim((string) ($workorder['Job_No'] ?? '')) === $binCode
            || trim((string) ($workorder['No'] ?? '')) === $binCode;
    }));

    $active = array_values(array_filter($associated, static function (array $workorder): bool {
        return !penates_is_terminal_status((string) ($workorder['Status'] ?? ''));
    }));

    $matchingActiveLines = [];
    $activeWithoutMaterial = [];
    foreach ($active as $workorder) {
        if (filter_var($workorder['KVT_No_Material_Needed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $activeWithoutMaterial[] = $workorder;
            continue;
        }
        foreach ($lines as $line) {
            if (
                !penates_is_cancelled_line($line)
                && penates_same_item($content, $line)
                && penates_line_belongs_to_workorder($line, $workorder)
            ) {
                $matchingActiveLines[] = $line;
            }
        }
    }

    $quantity = (float) ($content['Quantity_Base'] ?? 0);
    $remainingNeed = 0.0;
    foreach ($matchingActiveLines as $line) {
        $remainingNeed += penates_line_remaining_need($line);
    }

    $minimumStock = penates_item_minimum_stock($item);
    $inventory = penates_item_inventory($item);
    $belowMinimum = $minimumStock > 0 && $inventory < $minimumStock;
    $atOrBelowMinimum = $minimumStock > 0 && $inventory <= $minimumStock;
    $insufficientStock = $remainingNeed > $quantity + 0.00001;

    $reasonCodes = [];
    $reasons = [];
    if ($belowMinimum) {
        $reasonCodes[] = 'below_minimum';
        $reasons[] = PENATES_REASON_LABELS['below_minimum']
            . ' (' . penates_format_qty($inventory) . ' op voorraad, minimum ' . penates_format_qty($minimumStock) . ')';
    }
    if ($insufficientStock) {
        $reasonCodes[] = 'insufficient_stock';
        $reasons[] = PENATES_REASON_LABELS['insufficient_stock']
            . ' (' . penates_format_qty($quantity) . ' in bin, ' . penates_format_qty($remainingNeed) . ' nog nodig)';
    }

    $leftover = $remainingNeed <= 0.00001;
    if ($leftover && !$atOrBelowMinimum) {
        if ($associated === []) {
            $reasonCodes[] = 'no_workorder';
            $reasons[] = PENATES_REASON_LABELS['no_workorder'];
        } elseif ($active === []) {
            $reasonCodes[] = 'workorders_finished';
            $statuses = array_values(array_unique(array_filter(array_map(
                static fn(array $workorder): string => trim((string) ($workorder['Status'] ?? '')),
                $associated
            ))));
            $reasons[] = PENATES_REASON_LABELS['workorders_finished']
                . ($statuses !== [] ? ' (' . implode(', ', $statuses) . ')' : '');
        } elseif ($matchingActiveLines === []) {
            if (count($activeWithoutMaterial) === count($active)) {
                $reasonCodes[] = 'no_material_needed';
                $reasons[] = PENATES_REASON_LABELS['no_material_needed'];
            } else {
                $reasonCodes[] = 'item_not_on_workorder';
                $reasons[] = PENATES_REASON_LABELS['item_not_on_workorder'];
                if ($activeWithoutMaterial !== []) {
                    $reasonCodes[] = 'no_material_needed';
                    $reasons[] = 'Een actief werkorder geeft aan dat geen materiaal nodig is';
                }
            }
        } else {
            $reasonCodes[] = 'fully_picked';
            $reasons[] = PENATES_REASON_LABELS['fully_picked'];
        }
    }

    if ($reasonCodes === []) {
        return null;
    }

    $description = trim((string) ($item['Description'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($matchingActiveLines[0]['Description'] ?? ''));
    }

    $row = [
        'company' => $company,
        'location' => trim((string) ($content['Location_Code'] ?? $bin['Location_Code'] ?? '')),
        'bin' => $binCode,
        'bin_description' => trim((string) ($bin['Description'] ?? '')),
        'item_no' => trim((string) ($content['Item_No'] ?? '')),
        'variant_code' => trim((string) ($content['Variant_Code'] ?? '')),
        'description' => $description,
        'description_2' => trim((string) ($item['LVS_Description_2'] ?? $item['Description_2'] ?? '')),
        'quantity' => $quantity,
        'available_quantity' => (float) ($content['CalcQtyAvailToTakeUOM'] ?? 0),
        'pick_quantity' => (float) ($content['Pick_Quantity_Base'] ?? 0),
        'unit_cost' => penates_item_unit_cost($item),
        'stock_value' => round(penates_item_unit_cost($item) * $quantity, 2),
        'remaining_need' => $remainingNeed,
        'minimum_stock' => $minimumStock,
        'inventory' => $inventory,
        'unit' => trim((string) ($content['Unit_of_Measure_Code'] ?? $item['Base_Unit_of_Measure'] ?? '')),
        'workorders' => array_map('penates_workorder_summary', $associated),
        'reason_codes' => $reasonCodes,
        'reasons' => $reasons,
        'checked_at' => gmdate('c'),
    ];
    $row['id'] = penates_row_id($row);
    return $row;
}

function penates_row_id(array $row): string
{
    return hash('sha256', implode('|', [
        trim((string) ($row['company'] ?? '')),
        trim((string) ($row['location'] ?? '')),
        trim((string) ($row['bin'] ?? '')),
        trim((string) ($row['item_no'] ?? '')),
        trim((string) ($row['variant_code'] ?? '')),
        trim((string) ($row['unit'] ?? '')),
    ]));
}

function penates_build_company_rows(string $company): array
{
    $bins = penates_fetch_project_bins($company);
    if ($bins === []) {
        return [];
    }

    $contents = penates_fetch_bin_contents($company, $bins);
    $binCodes = array_values(array_unique(array_column($bins, 'Code')));
    $workorders = penates_fetch_workorders_for_bins($company, $binCodes);
    $lines = penates_fetch_lines_for_context($company, $binCodes, $workorders);
    $items = penates_fetch_items($company, array_column($contents, 'Item_No'));

    $binsByKey = [];
    foreach ($bins as $bin) {
        $binsByKey[(string) ($bin['Location_Code'] ?? '') . '|' . (string) ($bin['Code'] ?? '')] = $bin;
    }
    $itemsByNumber = [];
    foreach ($items as $item) {
        $itemsByNumber[(string) ($item['No'] ?? '')] = $item;
    }
    $workordersByJob = [];
    $workordersByNumber = [];
    foreach ($workorders as $workorder) {
        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        $number = trim((string) ($workorder['No'] ?? ''));
        if ($jobNo !== '') {
            $workordersByJob[$jobNo][] = $workorder;
        }
        if ($number !== '') {
            $workordersByNumber[$number][] = $workorder;
        }
    }
    $linesByItem = [];
    foreach ($lines as $line) {
        $itemKey = trim((string) ($line['No'] ?? '')) . '|' . trim((string) ($line['Variant_Code'] ?? ''));
        $linesByItem[$itemKey][] = $line;
    }

    $rows = [];
    foreach ($contents as $content) {
        $binKey = (string) ($content['Location_Code'] ?? '') . '|' . (string) ($content['Bin_Code'] ?? '');
        $bin = $binsByKey[$binKey] ?? null;
        if (!is_array($bin)) {
            continue;
        }
        $item = $itemsByNumber[(string) ($content['Item_No'] ?? '')] ?? [];
        $binCode = trim((string) ($bin['Code'] ?? ''));
        $associatedWorkorders = array_merge(
            $workordersByJob[$binCode] ?? [],
            $workordersByNumber[$binCode] ?? []
        );
        $associatedWorkorders = penates_unique_rows(
            $associatedWorkorders,
            static fn(array $workorder): string => (string) ($workorder['No'] ?? '')
        );
        $itemKey = trim((string) ($content['Item_No'] ?? '')) . '|' . trim((string) ($content['Variant_Code'] ?? ''));
        $row = penates_classify_content(
            $company,
            $bin,
            $content,
            $associatedWorkorders,
            $linesByItem[$itemKey] ?? [],
            $item
        );
        if ($row !== null) {
            $rows[] = $row;
        }
    }

    $rows = penates_attach_warehouse_locations($company, $rows);
    usort($rows, 'penates_compare_rows');
    return $rows;
}

function penates_compare_rows(array $left, array $right): int
{
    return strnatcasecmp(
        implode('|', [(string) ($left['company'] ?? ''), (string) ($left['bin'] ?? ''), (string) ($left['item_no'] ?? '')]),
        implode('|', [(string) ($right['company'] ?? ''), (string) ($right['bin'] ?? ''), (string) ($right['item_no'] ?? '')])
    );
}

function penates_empty_snapshot(): array
{
    return [
        'version' => PENATES_SNAPSHOT_VERSION,
        'generated_at' => '',
        'rows' => [],
        'companies' => [],
        'errors' => [],
    ];
}

function penates_read_snapshot(): array
{
    if (!is_file(PENATES_SNAPSHOT_FILE)) {
        return penates_empty_snapshot();
    }

    $raw = @file_get_contents(PENATES_SNAPSHOT_FILE);
    $snapshot = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($snapshot) || !is_array($snapshot['rows'] ?? null)) {
        return penates_empty_snapshot();
    }
    return array_merge(penates_empty_snapshot(), $snapshot);
}

function penates_with_snapshot_lock(callable $callback): mixed
{
    $directory = dirname(PENATES_SNAPSHOT_FILE);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Snapshotmap kon niet worden aangemaakt.');
    }

    $lock = @fopen(PENATES_SNAPSHOT_LOCK, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Snapshot-lock kon niet worden geopend.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Snapshot-lock kon niet worden verkregen.');
        }
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function penates_write_snapshot(array $snapshot): void
{
    $snapshot['version'] = PENATES_SNAPSHOT_VERSION;
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Snapshot kon niet als JSON worden gecodeerd.');
    }

    $temporary = PENATES_SNAPSHOT_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Tijdelijke snapshot kon niet worden geschreven.');
    }
    if (!@rename($temporary, PENATES_SNAPSHOT_FILE)) {
        @unlink($temporary);
        throw new RuntimeException('Snapshot kon niet atomair worden vervangen.');
    }
}

function penates_discover_companies(): array
{
    $result = auth_discover_companies_across_active_environments();
    return is_array($result['companies'] ?? null) ? $result['companies'] : [];
}

function penates_run_nightly(): array
{
    $companies = penates_discover_companies();
    $rows = [];
    $companyStats = [];
    $errors = [];

    foreach ($companies as $company) {
        $startedAt = hrtime(true);
        try {
            $companyRows = penates_build_company_rows($company);
            $rows = array_merge($rows, $companyRows);
            $companyStats[] = [
                'company' => $company,
                'rows' => count($companyRows),
                'stale' => false,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
        } catch (Throwable $error) {
            $previous = penates_read_snapshot();
            $oldRows = array_values(array_filter(
                $previous['rows'] ?? [],
                static fn(array $row): bool => (string) ($row['company'] ?? '') === $company
            ));
            unset($previous);
            $rows = array_merge($rows, $oldRows);
            $errors[] = ['company' => $company, 'error' => $error->getMessage()];
            $companyStats[] = [
                'company' => $company,
                'rows' => count($oldRows),
                'stale' => true,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }
    }

    usort($rows, 'penates_compare_rows');
    $snapshot = [
        'version' => PENATES_SNAPSHOT_VERSION,
        'generated_at' => gmdate('c'),
        'rows' => $rows,
        'companies' => $companyStats,
        'errors' => $errors,
    ];

    penates_with_snapshot_lock(static function () use ($snapshot): void {
        penates_write_snapshot($snapshot);
    });

    return $snapshot;
}

function penates_fetch_single_content(string $company, string $location, string $bin, string $itemNo, string $variant): ?array
{
    $filter = penates_odata_equals('Location_Code', $location)
        . ' and ' . penates_odata_equals('Bin_Code', $bin)
        . ' and ' . penates_odata_equals('Item_No', $itemNo)
        . ' and ' . penates_odata_equals('Variant_Code', $variant)
        . ' and Quantity_Base gt 0';
    $rows = penates_fetch_rows_live($company, 'BinContent', [
        '$select' => PENATES_CONTENT_SELECT,
        '$filter' => $filter,
    ]);
    return is_array($rows[0] ?? null) ? $rows[0] : null;
}

function penates_recheck_row(array $existingRow): array
{
    $company = trim((string) ($existingRow['company'] ?? ''));
    $location = trim((string) ($existingRow['location'] ?? ''));
    $binCode = trim((string) ($existingRow['bin'] ?? ''));
    $itemNo = trim((string) ($existingRow['item_no'] ?? ''));
    $variant = trim((string) ($existingRow['variant_code'] ?? ''));
    if ($company === '' || $location === '' || $binCode === '' || $itemNo === '') {
        throw new InvalidArgumentException('Onvolledige regel voor hercontrole.');
    }

    $content = penates_fetch_single_content($company, $location, $binCode, $itemNo, $variant);
    if ($content === null) {
        return ['keep' => false, 'row' => null, 'message' => 'Artikel ligt niet meer in deze bin.'];
    }

    $bins = penates_fetch_rows_live($company, 'Bins', [
        '$select' => PENATES_BIN_SELECT,
        '$filter' => penates_odata_equals('Location_Code', $location)
            . ' and ' . penates_odata_equals('Code', $binCode)
            . ' and KVT_Job_Bin eq true',
    ]);
    $bin = $bins[0] ?? null;
    if (!is_array($bin)) {
        return ['keep' => false, 'row' => null, 'message' => 'Bin is geen actieve projectbin meer.'];
    }

    $workorders = penates_fetch_workorders_for_bins($company, [$binCode]);
    $lines = penates_fetch_lines_for_context($company, [$binCode], $workorders);
    $items = penates_fetch_items($company, [$itemNo]);
    $row = penates_classify_content($company, $bin, $content, $workorders, $lines, $items[0] ?? []);
    if ($row === null) {
        return ['keep' => false, 'row' => null, 'message' => 'Voorraad is in orde voor dit artikel.'];
    }
    if (isset($existingRow['warehouse']) && is_array($existingRow['warehouse'])) {
        $row['warehouse'] = $existingRow['warehouse'];
    }

    return ['keep' => true, 'row' => $row, 'message' => 'Regel is live bijgewerkt.'];
}

function penates_find_snapshot_row(string $rowId, ?array $snapshot = null): array
{
    $snapshot ??= penates_read_snapshot();
    foreach ($snapshot['rows'] ?? [] as $row) {
        if (is_array($row) && hash_equals((string) ($row['id'] ?? ''), $rowId)) {
            return $row;
        }
    }
    throw new RuntimeException('Regel bestaat niet meer in de snapshot.');
}

function penates_bin_lookup_key(string $location, string $code): string
{
    return $location . '|' . $code;
}

function penates_fetch_bins_for_contents(string $company, array $contents): array
{
    $codesByLocation = [];
    foreach ($contents as $content) {
        $location = trim((string) ($content['Location_Code'] ?? ''));
        $code = trim((string) ($content['Bin_Code'] ?? ''));
        if ($location !== '' && $code !== '') {
            $codesByLocation[$location][$code] = $code;
        }
    }

    $bins = [];
    foreach ($codesByLocation as $location => $codes) {
        foreach (penates_chunks($codes) as $chunk) {
            $bins = array_merge($bins, penates_fetch_rows_live($company, 'Bins', [
                '$select' => PENATES_BIN_SELECT,
                '$filter' => penates_odata_equals('Location_Code', $location)
                    . ' and KVT_Job_Bin eq false and (' . penates_odata_or('Code', $chunk) . ')',
            ]));
        }
    }

    $byKey = [];
    foreach ($bins as $bin) {
        $key = penates_bin_lookup_key(
            trim((string) ($bin['Location_Code'] ?? '')),
            trim((string) ($bin['Code'] ?? ''))
        );
        if ($key !== '|') {
            $byKey[$key] = $bin;
        }
    }
    return $byKey;
}

function penates_item_variant_key(string $itemNo, string $variant = ''): string
{
    return trim($itemNo) . '|' . trim($variant);
}

function penates_fetch_bin_contents_for_items(string $company, array $itemNumbers): array
{
    $rows = [];
    foreach (penates_chunks($itemNumbers) as $chunk) {
        $rows = array_merge($rows, penates_fetch_rows_live($company, 'BinContent', [
            '$select' => PENATES_CONTENT_SELECT,
            '$filter' => 'Quantity_Base gt 0 and (' . penates_odata_or('Item_No', $chunk) . ')',
        ]));
    }
    return $rows;
}

function penates_warehouse_rows_from_contents(array $contents, array $warehouseBins, float $unitCost): array
{
    $rows = [];
    foreach ($contents as $content) {
        $location = trim((string) ($content['Location_Code'] ?? ''));
        $code = trim((string) ($content['Bin_Code'] ?? ''));
        $bin = $warehouseBins[penates_bin_lookup_key($location, $code)] ?? null;
        if (!is_array($bin)) {
            continue;
        }
        $quantity = (float) ($content['Quantity_Base'] ?? 0);
        $rows[] = [
            'location' => $location,
            'bin' => $code,
            'bin_description' => trim((string) ($bin['Description'] ?? '')),
            'quantity' => $quantity,
            'unit' => trim((string) ($content['Unit_of_Measure_Code'] ?? '')),
            'unit_cost' => $unitCost,
            'stock_value' => round($unitCost * $quantity, 2),
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        return strnatcasecmp(
            implode('|', [$left['location'], $left['bin']]),
            implode('|', [$right['location'], $right['bin']])
        );
    });
    return $rows;
}

function penates_warehouse_payload(array $row, array $locations): array
{
    $totalQuantity = 0.0;
    $totalValue = 0.0;
    foreach ($locations as $location) {
        $totalQuantity += (float) ($location['quantity'] ?? 0);
        $totalValue += (float) ($location['stock_value'] ?? 0);
    }

    return [
        'locations' => $locations,
        'total_quantity' => $totalQuantity,
        'total_value' => round($totalValue, 2),
    ];
}

function penates_attach_warehouse_locations(string $company, array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $contents = penates_fetch_bin_contents_for_items($company, array_column($rows, 'item_no'));
    $warehouseBins = penates_fetch_bins_for_contents($company, $contents);
    $contentsByItem = [];
    foreach ($contents as $content) {
        $key = penates_item_variant_key(
            (string) ($content['Item_No'] ?? ''),
            (string) ($content['Variant_Code'] ?? '')
        );
        $contentsByItem[$key][] = $content;
    }

    foreach ($rows as $index => $row) {
        $key = penates_item_variant_key(
            (string) ($row['item_no'] ?? ''),
            (string) ($row['variant_code'] ?? '')
        );
        $unitCost = (float) ($row['unit_cost'] ?? 0);
        $locations = penates_warehouse_rows_from_contents(
            $contentsByItem[$key] ?? [],
            $warehouseBins,
            $unitCost
        );
        $rows[$index]['warehouse'] = penates_warehouse_payload($row, $locations);
    }

    return $rows;
}

function penates_cached_warehouse_payload(array $row): array
{
    $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : ['locations' => [], 'total_quantity' => 0.0, 'total_value' => 0.0];
    return [
        'item_no' => trim((string) ($row['item_no'] ?? '')),
        'variant_code' => trim((string) ($row['variant_code'] ?? '')),
        'description' => trim((string) ($row['description'] ?? '')),
        'unit_cost' => (float) ($row['unit_cost'] ?? 0),
        'locations' => is_array($warehouse['locations'] ?? null) ? $warehouse['locations'] : [],
        'total_quantity' => (float) ($warehouse['total_quantity'] ?? 0),
        'total_value' => (float) ($warehouse['total_value'] ?? 0),
        'cached' => array_key_exists('warehouse', $row),
    ];
}

function penates_recheck_snapshot_row(string $rowId): array
{
    $existing = penates_find_snapshot_row($rowId);
    $result = penates_recheck_row($existing);
    penates_with_snapshot_lock(static function () use ($rowId, $result): void {
        $current = penates_read_snapshot();
        $updated = [];
        $found = false;
        foreach ($current['rows'] as $row) {
            if (!hash_equals((string) ($row['id'] ?? ''), $rowId)) {
                $updated[] = $row;
                continue;
            }
            $found = true;
            if ($result['keep'] && is_array($result['row'])) {
                $updated[] = $result['row'];
            }
        }
        if (!$found && $result['keep'] && is_array($result['row'])) {
            $updated[] = $result['row'];
        }
        usort($updated, 'penates_compare_rows');
        $current['rows'] = $updated;
        $current['last_live_update_at'] = gmdate('c');
        penates_write_snapshot($current);
    });

    return $result;
}
