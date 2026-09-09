<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/penates_data.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (empty($_SESSION['penates_csrf_token'])) {
    $_SESSION['penates_csrf_token'] = bin2hex(random_bytes(24));
}

function penates_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function penates_number(float $value): string
{
    return number_format($value, abs($value - round($value)) < 0.00001 ? 0 : 2, ',', '.');
}

function penates_date_time(string $value): string
{
    if ($value === '') {
        return 'Nog niet opgebouwd';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('d-m-Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function penates_workorder_label(array $workorder): string
{
    $number = trim((string) ($workorder['no'] ?? ''));
    $status = trim((string) ($workorder['status'] ?? ''));
    return trim($number . ($status !== '' ? ' · ' . $status : ''));
}

function penates_page_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

$snapshot = penates_read_snapshot();
$allRows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
$companyNames = array_values(array_unique(array_filter(array_map(
    static fn(array $row): string => (string) ($row['company'] ?? ''),
    $allRows
))));
natcasesort($companyNames);
$companyNames = array_values($companyNames);

$reasonLabels = [];
foreach ($allRows as $row) {
    foreach (($row['reason_codes'] ?? []) as $index => $code) {
        $reasonLabels[(string) $code] = (string) (($row['reasons'] ?? [])[$index] ?? $code);
    }
}
ksort($reasonLabels);

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$companyFilter = trim((string) ($_GET['company'] ?? ''));
$reasonFilter = trim((string) ($_GET['reason'] ?? ''));
$sortKey = trim((string) ($_GET['sort'] ?? 'company'));
$sortDirection = strtolower(trim((string) ($_GET['direction'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
$allowedSorts = ['company', 'bin', 'item_no', 'description', 'quantity'];
if (!in_array($sortKey, $allowedSorts, true)) {
    $sortKey = 'company';
}

$normalizedQuery = penates_normalize_text($searchQuery);
$filteredRows = array_values(array_filter($allRows, static function (array $row) use ($normalizedQuery, $companyFilter, $reasonFilter): bool {
    if ($companyFilter !== '' && (string) ($row['company'] ?? '') !== $companyFilter) {
        return false;
    }
    if ($reasonFilter !== '' && !in_array($reasonFilter, $row['reason_codes'] ?? [], true)) {
        return false;
    }
    if ($normalizedQuery === '') {
        return true;
    }

    $searchParts = [
        $row['company'] ?? '', $row['location'] ?? '', $row['bin'] ?? '',
        $row['item_no'] ?? '', $row['description'] ?? '', $row['description_2'] ?? '',
    ];
    foreach (($row['workorders'] ?? []) as $workorder) {
        $searchParts[] = penates_workorder_label($workorder);
    }
    return str_contains(penates_normalize_text(implode(' ', $searchParts)), $normalizedQuery);
}));

usort($filteredRows, static function (array $left, array $right) use ($sortKey, $sortDirection): int {
    if ($sortKey === 'quantity') {
        $comparison = ((float) ($left[$sortKey] ?? 0)) <=> ((float) ($right[$sortKey] ?? 0));
    } else {
        $comparison = strnatcasecmp((string) ($left[$sortKey] ?? ''), (string) ($right[$sortKey] ?? ''));
    }
    return $sortDirection === 'desc' ? -$comparison : $comparison;
});

$perPage = 200;
$resultCount = count($filteredRows);
$pageCount = max(1, (int) ceil($resultCount / $perPage));
$page = max(1, min($pageCount, (int) ($_GET['page'] ?? 1)));
$rows = array_slice($filteredRows, ($page - 1) * $perPage, $perPage);
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0099cc">
    <title>Penates · Restvoorraad projectbins</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="doc.svg" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7fb; color: var(--kvt-text); }
        button, input, select { font: inherit; }
        .page { width: min(1540px, 100%); margin: 0 auto; padding: 24px; }
        .hero {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 24px;
            margin-bottom: 20px; padding: 24px; border-radius: 18px; color: #fff;
            background: linear-gradient(120deg, #00529b, #0099cc);
            box-shadow: 0 16px 38px rgba(0, 82, 155, .16);
        }
        .hero-main { display: flex; gap: 18px; align-items: center; }
        .hero-logo {
            display: grid; place-items: center; flex: 0 0 auto;
            padding: 12px 16px; border-radius: 16px; background: #fff;
            box-shadow: 0 8px 20px rgba(0, 30, 60, .16);
        }
        .hero-logo img { display: block; width: auto; height: 40px; }
        h1 { margin: 0 0 6px; font-size: clamp(1.65rem, 3vw, 2.35rem); }
        .hero p { margin: 0; max-width: 760px; color: rgba(255,255,255,.86); }
        .snapshot { flex: 0 0 auto; text-align: right; font-size: .83rem; color: rgba(255,255,255,.8); }
        .snapshot strong { display: block; margin-top: 4px; color: #fff; font-size: .98rem; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .stat { padding: 16px 18px; border: 1px solid var(--kvt-line); border-radius: 14px; background: #fff; }
        .stat-label { color: var(--kvt-muted); font-size: .82rem; }
        .stat-value { display: block; margin-top: 4px; font-size: 1.55rem; font-weight: 800; color: #00529b; }
        .panel { border: 1px solid var(--kvt-line); border-radius: 16px; background: #fff; overflow: hidden; }
        .toolbar {
            display: grid; grid-template-columns: minmax(240px, 1fr) repeat(2, minmax(170px, .35fr)) auto;
            gap: 10px; padding: 16px; border-bottom: 1px solid var(--kvt-line);
            align-items: end;
        }
        .field { display: grid; gap: 5px; }
        .field label { color: var(--kvt-muted); font-size: .76rem; }
        .field input, .field select {
            width: 100%; min-height: 42px; padding: 9px 12px; border: 1px solid var(--kvt-line);
            border-radius: 10px; color: var(--kvt-text); background: #fff;
        }
        .field input:focus, .field select:focus { outline: 3px solid rgba(0,153,204,.16); border-color: #0099cc; }
        .filter-button {
            min-height: 42px; padding: 9px 18px; border: 1px solid #0099cc; border-radius: 10px;
            background: #0099cc; color: #fff; cursor: pointer;
        }
        .notice { margin-bottom: 16px; padding: 13px 16px; border-radius: 12px; background: #fff8e7; border: 1px solid #f3d691; color: #704d00; }
        .notice.error { background: #fff0f0; border-color: #efb3b3; color: #8b2020; }
        .table-wrap { overflow: auto; min-height: 240px; }
        table { width: 100%; border-collapse: collapse; font-size: .89rem; }
        th {
            position: sticky; top: 0; z-index: 2; padding: 12px; border-bottom: 1px solid var(--kvt-line);
            background: #f8fafc; color: #34445a; text-align: left; white-space: nowrap; cursor: pointer;
        }
        th a { color: inherit; text-decoration: none; }
        th a::after { content: " ↕"; color: #91a0b3; }
        th.is-asc a::after { content: " ↑"; color: #00529b; }
        th.is-desc a::after { content: " ↓"; color: #00529b; }
        td { padding: 12px; border-bottom: 1px solid #e8edf4; vertical-align: top; }
        tbody tr { position: relative; transition: background .14s ease; }
        tbody tr:hover { background: #f3faff; }
        tbody tr.is-updating { opacity: .58; }
        tbody tr.is-removed { opacity: 0; transform: translateX(8px); transition: .25s ease; }
        .strong { font-weight: 800; color: #1d2d42; }
        .muted { color: var(--kvt-muted); font-size: .8rem; margin-top: 3px; }
        .nowrap { white-space: nowrap; }
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }
        .reason-list, .workorders { display: flex; flex-wrap: wrap; gap: 5px; max-width: 410px; }
        .badge { display: inline-flex; padding: 4px 7px; border-radius: 999px; line-height: 1.2; font-size: .75rem; }
        .reason { background: #fff2cc; color: #684900; border: 1px solid #f2d47b; }
        .workorder { background: #eaf4ff; color: #174f82; border: 1px solid #c7e0f8; }
        .check-cell { width: 54px; text-align: right; }
        .recheck {
            opacity: 0; transform: translateX(5px); pointer-events: none;
            width: 34px; height: 34px; border: 1px solid #a9cde1; border-radius: 9px;
            color: #00529b; background: #fff; cursor: pointer; transition: .14s ease;
        }
        tr:hover .recheck, .recheck:focus, .recheck.is-busy { opacity: 1; transform: none; pointer-events: auto; }
        .recheck:hover { color: #fff; background: #0099cc; border-color: #0099cc; }
        .recheck.is-busy { animation: pulse 1s infinite alternate; cursor: progress; }
        @keyframes pulse { to { opacity: .45; } }
        .empty { display: none; padding: 54px 20px; text-align: center; color: var(--kvt-muted); }
        .empty.is-visible { display: block; }
        .pagination {
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            padding: 14px 16px; border-top: 1px solid var(--kvt-line); color: var(--kvt-muted); font-size: .86rem;
        }
        .pagination-links { display: flex; gap: 7px; }
        .pagination a {
            display: inline-flex; padding: 7px 11px; border: 1px solid var(--kvt-line);
            border-radius: 8px; color: #00529b; text-decoration: none; background: #fff;
        }
        .toast {
            position: fixed; right: 20px; bottom: 20px; z-index: 20; max-width: 430px;
            padding: 12px 15px; border-radius: 11px; color: #fff; background: #26374d;
            box-shadow: 0 12px 35px rgba(15,23,42,.24); opacity: 0; transform: translateY(10px);
            pointer-events: none; transition: .2s ease;
        }
        .toast.show { opacity: 1; transform: none; }
        .toast.error { background: #a52a2a; }
        @media (max-width: 850px) {
            .page { padding: 12px; }
            .hero { padding: 18px; flex-direction: column; }
            .snapshot { text-align: left; }
            .toolbar { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
            .hero-logo { padding: 9px 12px; }
            .hero-logo img { height: 28px; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="hero">
        <div class="hero-main">
            <div class="hero-logo">
                <img src="logo-website.png" alt="Koninklijke van Twist" width="1378" height="364">
            </div>
            <div>
                <h1>Penates</h1>
                <p>Artikelen die nog in een projectbin liggen, maar door geen actief werkorder meer nodig zijn.</p>
            </div>
        </div>
        <div class="snapshot">
            Laatste nachtelijke controle
            <strong><?= penates_h(penates_date_time((string) ($snapshot['generated_at'] ?? ''))) ?></strong>
        </div>
    </header>

    <?php if (($snapshot['generated_at'] ?? '') === ''): ?>
        <div class="notice">Er is nog geen snapshot. Laat <strong>nightly.php</strong> één keer draaien.</div>
    <?php endif; ?>
    <?php if (($snapshot['errors'] ?? []) !== []): ?>
        <div class="notice error">
            De laatste nachtelijke controle was niet voor ieder bedrijf succesvol. Eerdere data is waar mogelijk behouden.
        </div>
    <?php endif; ?>

    <section class="stats" aria-label="Samenvatting">
        <div class="stat"><span class="stat-label">Resultaten</span><strong class="stat-value" id="visible-count"><?= $resultCount ?></strong></div>
        <div class="stat"><span class="stat-label">Artikelen in snapshot</span><strong class="stat-value"><?= count($allRows) ?></strong></div>
        <div class="stat"><span class="stat-label">Bedrijven met resultaat</span><strong class="stat-value"><?= count($companyNames) ?></strong></div>
    </section>

    <section class="panel">
        <form class="toolbar" method="get">
            <div class="field">
                <label for="search">Zoeken</label>
                <input id="search" name="q" type="search" value="<?= penates_h($searchQuery) ?>" placeholder="Project, bin, artikel, omschrijving of werkorder…">
            </div>
            <div class="field">
                <label for="company-filter">Bedrijf</label>
                <select id="company-filter" name="company">
                    <option value="">Alle bedrijven</option>
                    <?php foreach ($companyNames as $companyName): ?>
                        <option value="<?= penates_h($companyName) ?>"<?= $companyFilter === $companyName ? ' selected' : '' ?>><?= penates_h($companyName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="reason-filter">Reden</label>
                <select id="reason-filter" name="reason">
                    <option value="">Alle redenen</option>
                    <?php foreach ($reasonLabels as $code => $label): ?>
                        <option value="<?= penates_h($code) ?>"<?= $reasonFilter === $code ? ' selected' : '' ?>><?= penates_h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="filter-button" type="submit">Filteren</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php foreach (['company' => 'Bedrijf', 'bin' => 'Project / bin', 'item_no' => 'Artikel', 'description' => 'Omschrijving', 'quantity' => 'Aantal'] as $key => $label): ?>
                            <?php $nextDirection = $sortKey === $key && $sortDirection === 'asc' ? 'desc' : 'asc'; ?>
                            <th class="<?= $sortKey === $key ? 'is-' . penates_h($sortDirection) : '' ?>"><a href="<?= penates_h(penates_page_url(['sort' => $key, 'direction' => $nextDirection, 'page' => 1])) ?>"><?= penates_h($label) ?></a></th>
                        <?php endforeach; ?>
                        <th>Werkorders</th>
                        <th>Waarom zichtbaar</th>
                        <th class="check-cell"><span class="sr-only">Acties</span></th>
                    </tr>
                </thead>
                <tbody id="results">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $searchParts = [
                        $row['company'] ?? '', $row['location'] ?? '', $row['bin'] ?? '',
                        $row['item_no'] ?? '', $row['description'] ?? '', $row['description_2'] ?? '',
                    ];
                    foreach (($row['workorders'] ?? []) as $workorder) {
                        $searchParts[] = penates_workorder_label($workorder);
                    }
                    ?>
                    <tr
                        data-id="<?= penates_h($row['id'] ?? '') ?>"
                        data-company="<?= penates_h($row['company'] ?? '') ?>"
                        data-reasons="<?= penates_h(implode('|', $row['reason_codes'] ?? [])) ?>"
                        data-search="<?= penates_h(penates_normalize_text(implode(' ', $searchParts))) ?>"
                        data-company-sort="<?= penates_h($row['company'] ?? '') ?>"
                        data-bin-sort="<?= penates_h($row['bin'] ?? '') ?>"
                        data-item_no-sort="<?= penates_h($row['item_no'] ?? '') ?>"
                        data-description-sort="<?= penates_h($row['description'] ?? '') ?>"
                        data-quantity-sort="<?= penates_h($row['quantity'] ?? 0) ?>"
                    >
                        <td><?= penates_h($row['company'] ?? '') ?><div class="muted"><?= penates_h($row['location'] ?? '') ?></div></td>
                        <td><span class="strong"><?= penates_h($row['bin'] ?? '') ?></span><div class="muted"><?= penates_h($row['bin_description'] ?? '') ?></div></td>
                        <td><span class="strong nowrap"><?= penates_h($row['item_no'] ?? '') ?></span><?php if (($row['variant_code'] ?? '') !== ''): ?><div class="muted">Variant <?= penates_h($row['variant_code']) ?></div><?php endif; ?></td>
                        <td><?= penates_h($row['description'] ?? '') ?><div class="muted"><?= penates_h($row['description_2'] ?? '') ?></div></td>
                        <td class="nowrap"><span data-role="quantity"><?= penates_h(penates_number((float) ($row['quantity'] ?? 0))) ?></span> <?= penates_h($row['unit'] ?? '') ?></td>
                        <td><div class="workorders" data-role="workorders"><?php foreach (($row['workorders'] ?? []) as $workorder): ?><span class="badge workorder"><?= penates_h(penates_workorder_label($workorder)) ?></span><?php endforeach; ?></div></td>
                        <td><div class="reason-list" data-role="reasons"><?php foreach (($row['reasons'] ?? []) as $reason): ?><span class="badge reason"><?= penates_h($reason) ?></span><?php endforeach; ?></div><div class="muted" data-role="checked">Gecontroleerd <?= penates_h(penates_date_time((string) ($row['checked_at'] ?? ''))) ?></div></td>
                        <td class="check-cell"><button class="recheck" type="button" title="Controleer dit artikel nu opnieuw in BC" aria-label="Controleer artikel opnieuw">↻</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="empty<?= $rows === [] ? ' is-visible' : '' ?>" id="empty-state">
                <strong>Geen regels gevonden.</strong><br>
                Pas de filters aan of wacht op de volgende nachtelijke controle.
            </div>
            <?php if ($resultCount > 0): ?>
                <nav class="pagination" aria-label="Paginering">
                    <span>Pagina <?= $page ?> van <?= $pageCount ?> · <?= $resultCount ?> resultaten</span>
                    <span class="pagination-links">
                        <?php if ($page > 1): ?><a href="<?= penates_h(penates_page_url(['page' => $page - 1])) ?>">Vorige</a><?php endif; ?>
                        <?php if ($page < $pageCount): ?><a href="<?= penates_h(penates_page_url(['page' => $page + 1])) ?>">Volgende</a><?php endif; ?>
                    </span>
                </nav>
            <?php endif; ?>
        </div>
    </section>
</main>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>
(() => {
    const csrfToken = <?= json_encode((string) $_SESSION['penates_csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const body = document.getElementById('results');
    const visibleCount = document.getElementById('visible-count');
    const toast = document.getElementById('toast');
    let toastTimer = null;

    function normalize(value) {
        return String(value || '').trim().toLocaleLowerCase('nl-NL');
    }

    function showToast(message, isError = false) {
        toast.textContent = message;
        toast.classList.toggle('error', isError);
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 4200);
    }

    function badge(text, className) {
        const element = document.createElement('span');
        element.className = 'badge ' + className;
        element.textContent = text;
        return element;
    }

    function workorderLabel(workorder) {
        const number = String(workorder.no || '').trim();
        const status = String(workorder.status || '').trim();
        return number + (status ? ' · ' + status : '');
    }

    function updateRowElement(element, data) {
        element.dataset.id = String(data.id);
        element.dataset.reasons = (data.reason_codes || []).join('|');
        element.dataset.quantitySort = String(data.quantity || 0);
        element.querySelector('[data-role="quantity"]').textContent =
            Number(data.quantity || 0).toLocaleString('nl-NL', {maximumFractionDigits: 2});

        const workorders = element.querySelector('[data-role="workorders"]');
        workorders.replaceChildren(...(data.workorders || []).map(item => badge(workorderLabel(item), 'workorder')));
        const reasons = element.querySelector('[data-role="reasons"]');
        reasons.replaceChildren(...(data.reasons || []).map(item => badge(item, 'reason')));
        element.querySelector('[data-role="checked"]').textContent = 'Zojuist live gecontroleerd';

        const searchable = [
            data.company, data.location, data.bin, data.item_no, data.description, data.description_2,
            ...(data.workorders || []).map(workorderLabel)
        ];
        element.dataset.search = normalize(searchable.join(' '));
    }

    async function recheck(button) {
        const row = button.closest('tr');
        if (!row || button.classList.contains('is-busy')) return;
        const rowId = row.dataset.id;
        button.classList.add('is-busy');
        row.classList.add('is-updating');
        button.disabled = true;
        try {
            const form = new URLSearchParams({row_id: rowId, csrf_token: csrfToken});
            const response = await fetch('refresh.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
                body: form
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Hercontrole mislukt.');
            }
            if (!payload.keep) {
                row.classList.add('is-removed');
                setTimeout(() => {
                    row.remove();
                    const count = Math.max(0, Number(String(visibleCount.textContent).replace(/\D/g, '')) - 1);
                    visibleCount.textContent = count.toLocaleString('nl-NL');
                    if (!body.querySelector('tr')) {
                        window.location.reload();
                    }
                }, 260);
            } else if (payload.row) {
                updateRowElement(row, payload.row);
            }
            showToast(payload.message || 'Controle afgerond.');
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Hercontrole mislukt.', true);
        } finally {
            button.classList.remove('is-busy');
            row.classList.remove('is-updating');
            button.disabled = false;
        }
    }

    body.addEventListener('click', event => {
        const button = event.target.closest('.recheck');
        if (button) recheck(button);
    });
})();
</script>
</body>
</html>
