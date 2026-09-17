<?php

/**
 * Minimale .xlsx-schrijver met meerdere tabbladen, elk als Excel-tabel
 * met autofilter. Bewust zonder externe library.
 */

function penates_xlsx_column_letter(int $columnNumber): string
{
    $columnNumber = max(1, $columnNumber);
    $letters = '';
    while ($columnNumber > 0) {
        $columnNumber--;
        $letters = chr(65 + ($columnNumber % 26)) . $letters;
        $columnNumber = intdiv($columnNumber, 26);
    }

    return $letters;
}

function penates_xlsx_cell_ref(int $rowNumber, int $columnNumber): string
{
    return penates_xlsx_column_letter($columnNumber) . max(1, $rowNumber);
}

function penates_xlsx_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function penates_xlsx_sanitize_text(string $value): string
{
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
}

function penates_xlsx_cell_xml(string $cellRef, mixed $value): string
{
    if (is_int($value)) {
        return '<c r="' . $cellRef . '"><v>' . (string) $value . '</v></c>';
    }

    if (is_float($value)) {
        if (!is_finite($value)) {
            $value = 0.0;
        }

        return '<c r="' . $cellRef . '"><v>' . rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') . '</v></c>';
    }

    $text = penates_xlsx_sanitize_text((string) $value);
    if ($text === '') {
        return '';
    }

    return '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">'
        . penates_xlsx_xml_escape($text) . '</t></is></c>';
}

function penates_xlsx_table_name(string $name): string
{
    $name = (string) preg_replace('/[^A-Za-z0-9_]/', '', penates_xlsx_sanitize_text($name));
    if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
        $name = 'Tabel' . $name;
    }

    return substr($name, 0, 60);
}

function penates_xlsx_sheet_name(string $name): string
{
    $name = trim((string) preg_replace('#[\\\\/\?\*\[\]:]#', ' ', penates_xlsx_sanitize_text($name)));
    if ($name === '') {
        $name = 'Export';
    }

    return mb_substr($name, 0, 31);
}

function penates_xlsx_sheet_xml(array $headers, array $rows): array
{
    $colCount = count($headers);

    $sheetRows = '<row r="1">';
    foreach ($headers as $index => $header) {
        $sheetRows .= penates_xlsx_cell_xml(penates_xlsx_cell_ref(1, $index + 1), (string) $header);
    }
    $sheetRows .= '</row>';

    $excelRow = 2;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sheetRows .= '<row r="' . $excelRow . '">';
        $colIndex = 0;
        foreach (array_values($row) as $value) {
            if ($colIndex >= $colCount) {
                break;
            }
            $sheetRows .= penates_xlsx_cell_xml(penates_xlsx_cell_ref($excelRow, $colIndex + 1), $value);
            $colIndex++;
        }
        $sheetRows .= '</row>';
        $excelRow++;
    }

    $lastDataRow = max(1, $excelRow - 1);
    $tableRef = 'A1:' . penates_xlsx_cell_ref($lastDataRow, $colCount);

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . $tableRef . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>'
        . '</worksheet>';

    return ['xml' => $xml, 'ref' => $tableRef];
}

function penates_xlsx_table_xml(int $tableId, string $tableName, string $tableRef, array $headers): string
{
    $columnsXml = '';
    $usedNames = [];
    foreach ($headers as $index => $header) {
        $name = trim(penates_xlsx_sanitize_text((string) $header));
        if ($name === '') {
            $name = 'Kolom' . (string) ($index + 1);
        }
        $unique = $name;
        $suffix = 2;
        while (isset($usedNames[mb_strtolower($unique)])) {
            $unique = $name . ' ' . (string) $suffix;
            $suffix++;
        }
        $usedNames[mb_strtolower($unique)] = true;
        $columnsXml .= '<tableColumn id="' . (string) ($index + 1) . '" name="'
            . penates_xlsx_xml_escape($unique) . '"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' id="' . (string) $tableId . '" name="' . $tableName . '" displayName="' . $tableName . '"'
        . ' ref="' . $tableRef . '" totalsRowShown="0">'
        . '<autoFilter ref="' . $tableRef . '"/>'
        . '<tableColumns count="' . (string) count($headers) . '">' . $columnsXml . '</tableColumns>'
        . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
        . '</table>';
}

/**
 * @param list<array{name: string, table: string, headers: list<string>, rows: list<list<string|int|float>>}> $sheets
 */
function penates_build_workbook_xlsx(array $sheets): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is niet beschikbaar voor Excel-export.');
    }

    $sheets = array_values(array_filter($sheets, 'is_array'));
    if ($sheets === []) {
        throw new RuntimeException('Er zijn geen tabbladen om te exporteren.');
    }

    $parts = [];
    $sheetsXml = '';
    $workbookRels = '';
    $contentOverrides = '';

    foreach ($sheets as $index => $sheet) {
        $number = $index + 1;
        $headers = array_values(is_array($sheet['headers'] ?? null) ? $sheet['headers'] : []);
        if ($headers === []) {
            $headers = [''];
        }
        $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];

        $built = penates_xlsx_sheet_xml($headers, $rows);
        $parts['xl/worksheets/sheet' . $number . '.xml'] = $built['xml'];
        $parts['xl/worksheets/_rels/sheet' . $number . '.xml.rels'] =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table"'
            . ' Target="../tables/table' . $number . '.xml"/>'
            . '</Relationships>';
        $parts['xl/tables/table' . $number . '.xml'] = penates_xlsx_table_xml(
            $number,
            penates_xlsx_table_name((string) ($sheet['table'] ?? ('Tabel' . $number))),
            $built['ref'],
            $headers
        );

        $sheetsXml .= '<sheet name="' . penates_xlsx_xml_escape(penates_xlsx_sheet_name((string) ($sheet['name'] ?? '')))
            . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
        $workbookRels .= '<Relationship Id="rId' . $number . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet' . $number . '.xml"/>';
        $contentOverrides .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/tables/table' . $number . '.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
    }

    $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $contentOverrides
        . '</Types>';
    $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        . ' Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $parts['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetsXml . '</sheets>'
        . '</workbook>';
    $parts['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $workbookRels
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'penates_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('Kon tijdelijk Excel-bestand niet aanmaken.');
    }

    $zipPath = $tmp . '.xlsx';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Kon Excel-bestand niet schrijven.');
    }
    foreach ($parts as $path => $contents) {
        $zip->addFromString($path, $contents);
    }
    $zip->close();

    $binary = file_get_contents($zipPath);
    @unlink($zipPath);
    if ($binary === false) {
        throw new RuntimeException('Kon Excel-bestand niet lezen.');
    }

    return $binary;
}
