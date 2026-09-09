<?php

require_once __DIR__ . '/../web/penates_data.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_base_content(): array
{
    return [
        'Location_Code' => 'KVT',
        'Bin_Code' => 'P100',
        'Item_No' => 'ITEM-1',
        'Variant_Code' => '',
        'Unit_of_Measure_Code' => 'PCS',
        'Quantity_Base' => 2,
        'CalcQtyAvailToTakeUOM' => 2,
        'Pick_Quantity_Base' => 0,
    ];
}

function test_workorder(string $number, string $status = 'Open', bool $noMaterial = false): array
{
    return [
        'No' => $number,
        'Job_No' => 'P100',
        'Job_Task_No' => $number,
        'Status' => $status,
        'KVT_Document_Status' => '10-OPEN',
        'KVT_No_Material_Needed' => $noMaterial,
    ];
}

function test_line(string $workorder, float $quantity, float $picked, bool $complete = false): array
{
    return [
        'Job_No' => 'P100',
        'Job_Task_No' => $workorder,
        'LVS_Work_Order_No' => '',
        'No' => 'ITEM-1',
        'Variant_Code' => '',
        'Quantity' => $quantity,
        'Quantity_Base' => $quantity,
        'KVT_Qty_Picked' => $picked,
        'KVT_Completely_Picked' => $complete,
        'LVS_Cancelled_Original_Line' => false,
    ];
}

$bin = ['Location_Code' => 'KVT', 'Code' => 'P100', 'Description' => 'Project P100'];
$content = test_base_content();
$item = ['No' => 'ITEM-1', 'Description' => 'Testartikel'];

foreach (['Ondertekend', 'Gecontroleerd', 'Gefactureerd', 'Afgesloten', 'Geannuleerd'] as $status) {
    test_assert(penates_is_terminal_status($status), "{$status} moet een eindstatus zijn");
}
foreach (['Open', 'Gepland', 'Onderhanden', 'Uitgevoerd'] as $status) {
    test_assert(!penates_is_terminal_status($status), "{$status} mag geen eindstatus zijn");
}

$needed = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1')],
    [test_line('WO1', 2, 1)],
    $item
);
test_assert($needed === null, 'Gedeeltelijk gepickt artikel moet buiten het resultaat blijven');

$picked = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1')],
    [test_line('WO1', 2, 2)],
    $item
);
test_assert(in_array('fully_picked', $picked['reason_codes'] ?? [], true), 'Volledig gepickt artikel mist reden');

$missing = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1')],
    [],
    $item
);
test_assert(in_array('item_not_on_workorder', $missing['reason_codes'] ?? [], true), 'Ontbrekend artikel mist reden');

$finished = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1', 'Afgesloten'), test_workorder('WO2', 'Geannuleerd')],
    [test_line('WO1', 2, 0)],
    $item
);
test_assert(in_array('workorders_finished', $finished['reason_codes'] ?? [], true), 'Afgeronde werkorders missen reden');

$multiple = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1', 'Afgesloten'), test_workorder('WO2', 'Open')],
    [test_line('WO2', 2, 0)],
    $item
);
test_assert($multiple === null, 'Een actief werkorder met behoefte moet doorslaggevend zijn');

$cancelledLine = test_line('WO1', 2, 0);
$cancelledLine['LVS_Cancelled_Original_Line'] = true;
$cancelled = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1')],
    [$cancelledLine],
    $item
);
test_assert(in_array('item_not_on_workorder', $cancelled['reason_codes'] ?? [], true), 'Geannuleerde regel mag niet als behoefte tellen');

$noMaterial = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1', 'Open', true)],
    [test_line('WO1', 2, 0)],
    $item
);
test_assert(in_array('no_material_needed', $noMaterial['reason_codes'] ?? [], true), 'Geen-materiaal-indicatie mist reden');

echo "OK penates_data_test\n";
