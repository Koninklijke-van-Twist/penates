<?php

require_once __DIR__ . '/../web/penates_data.php';

function jb_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

jb_assert(!penates_company_has_job_bin('KVT Germany GmbH'), 'KVT Germany GmbH heeft geen KVT_Job_Bin');
foreach (['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'] as $company) {
    jb_assert(penates_company_has_job_bin($company), $company . ' moet KVT_Job_Bin blijven opvragen');
}

jb_assert(
    penates_strip_job_bin_filter('KVT_Job_Bin eq true and Empty eq false') === 'Empty eq false',
    'Voorloop-voorwaarde niet verwijderd'
);
jb_assert(
    penates_strip_job_bin_filter("Location_Code eq 'KVT' and KVT_Job_Bin eq false and (Code eq 'A')") === "Location_Code eq 'KVT' and (Code eq 'A')",
    'Middelste voorwaarde niet verwijderd'
);
jb_assert(penates_strip_job_bin_filter('KVT_Job_Bin eq false') === '', 'Losse voorwaarde niet verwijderd');

jb_assert(
    penates_is_missing_job_bin_error(new RuntimeException("HTTP 400 from OData: Could not find a property named 'KVT_Job_Bin' on type 'NAV.Bins'.")),
    'BC-fout voor ontbrekend veld niet herkend'
);
jb_assert(!penates_is_missing_job_bin_error(new RuntimeException('HTTP 500')), 'Andere fout onterecht herkend');

// Voor Duitsland mag geen Bins-aanroep met KVT_Job_Bin plaatsvinden: projectbins zijn leeg zonder netwerk.
jb_assert(penates_fetch_project_bins('KVT Germany GmbH') === [], 'Duitsland hoort geen projectbins te hebben');

// Runtime-ontdekt ontbrekend veld wordt per bedrijf onthouden.
$GLOBALS['penates_job_bin_missing']['Testbedrijf'] = true;
jb_assert(!penates_company_has_job_bin('Testbedrijf'), 'Ontdekt ontbrekend veld niet onthouden');
jb_assert(penates_fetch_project_bins('Testbedrijf') === [], 'Bedrijf zonder veld hoort geen projectbins te hebben');

echo "OK job_bin_fallback_test\n";
