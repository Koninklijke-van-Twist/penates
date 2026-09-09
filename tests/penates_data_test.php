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
$item = ['No' => 'ITEM-1', 'Description' => 'Testartikel', 'Unit_Cost' => 12.5];

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
test_assert(($picked['stock_value'] ?? 0) === 25.0, 'Voorraadwaarde moet stukprijs × aantal zijn');

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

$atMinimum = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1', 'Afgesloten')],
    [test_line('WO1', 2, 0)],
    ['No' => 'ITEM-1', 'Description' => 'Testartikel', 'Safety_Stock_Quantity' => 2, 'Inventory' => 2]
);
test_assert($atMinimum === null, 'Restvoorraad op de minimumvoorraad mag niet in het resultaat');

$belowMinimum = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $content,
    [test_workorder('WO1', 'Afgesloten')],
    [test_line('WO1', 2, 0)],
    ['No' => 'ITEM-1', 'Description' => 'Testartikel', 'Safety_Stock_Quantity' => 5, 'Inventory' => 2]
);
test_assert(in_array('below_minimum', $belowMinimum['reason_codes'] ?? [], true), 'Voorraad onder minimum mist reden');
test_assert(!in_array('workorders_finished', $belowMinimum['reason_codes'] ?? [], true), 'Onder minimum is geen restvoorraad');

$shortContent = test_base_content();
$shortContent['Quantity_Base'] = 15;
$shortContent['CalcQtyAvailToTakeUOM'] = 15;
$shortage = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $shortContent,
    [test_workorder('WO1')],
    [test_line('WO1', 20, 0)],
    $item
);
test_assert(in_array('insufficient_stock', $shortage['reason_codes'] ?? [], true), 'Tekort t.o.v. werkorder mist reden');
test_assert(($shortage['remaining_need'] ?? 0) === 20.0, 'Openstaande werkorderbehoefte is onjuist');

$pickedAway = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $shortContent,
    [test_workorder('WO1')],
    [test_line('WO1', 20, 20, true)],
    $item
);
test_assert(in_array('fully_picked', $pickedAway['reason_codes'] ?? [], true), 'Volledig gepickte behoefte mag niet als tekort tellen');

$enoughForWorkorder = penates_classify_content(
    'Koninklijke van Twist',
    $bin,
    $shortContent,
    [test_workorder('WO1')],
    [test_line('WO1', 20, 5)],
    $item
);
test_assert($enoughForWorkorder === null, 'Binvoorraad die de openstaande pick dekt moet buiten het resultaat blijven');

$warehouse = penates_warehouse_rows_from_contents(
    [
        ['Location_Code' => 'KVT', 'Bin_Code' => 'A-01', 'Quantity_Base' => 4, 'Unit_of_Measure_Code' => 'PCS'],
        ['Location_Code' => 'KVT', 'Bin_Code' => 'P100', 'Quantity_Base' => 2, 'Unit_of_Measure_Code' => 'PCS'],
    ],
    [
        'KVT|A-01' => ['Location_Code' => 'KVT', 'Code' => 'A-01', 'Description' => 'Stelling', 'KVT_Job_Bin' => false],
    ],
    10.0
);
test_assert(count($warehouse) === 1, 'Projectbins mogen niet in de warehouse-cache');
test_assert(($warehouse[0]['stock_value'] ?? 0) === 40.0, 'Warehouse-waarde moet stukprijs × aantal zijn');

$cached = penates_cached_warehouse_payload([
    'item_no' => 'ITEM-1',
    'variant_code' => '',
    'description' => 'Testartikel',
    'unit_cost' => 10,
    'warehouse' => ['locations' => $warehouse, 'total_quantity' => 4, 'total_value' => 40],
]);
test_assert($cached['cached'] === true, 'Snapshotregel moet als cache worden herkend');
test_assert(count($cached['locations']) === 1, 'Cache-payload mist opslaglocaties');

echo "OK penates_data_test\n";
