<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/penates-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['PENATES_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Compan(?:y|ies)(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/penates_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Penates] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}
if (php_sapi_name() === 'cli' && odata_mimir_timeout_seconds_for_sapi(php_sapi_name()) !== 600) {
    fail('php_sapi_name cli moet de lange timeout gebruiken');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag een fallback loggen, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Penates] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$GLOBALS['demeter_company_environment_map'] = ['KVT Gas' => 'Production'];
$beforeLive = count($calls);
$liveRows = penates_fetch_rows_live('KVT Gas', 'Bins', ['$select' => 'Code'], 60);
if (($liveRows[0]['No'] ?? '') !== 'WO-1') {
    fail('penates_fetch_rows_live viel niet terug op de pre-Mímir route');
}
if (!odata_mimir_circuit_open()) {
    fail('live fetch moet het circuit openen');
}
$liveCall = $calls[$beforeLive] ?? null;
$expectedLivePrefix = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/Bins?";
if (!is_array($liveCall) || strpos((string) $liveCall['url'], $expectedLivePrefix) !== 0 || $liveCall['user'] !== 'bcuser') {
    fail('live-fallback gebruikte niet de oude company-URL: ' . json_encode($liveCall));
}
if (strpos((string) ($liveCall['url'] ?? ''), 'mimir.invalid') !== false) {
    fail('live-fallback bleef op de synthetische Mímir-host');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
unset($GLOBALS['demeter_company_environment_map']);
$beforeDiscover = count($calls);
$discovered = auth_discover_companies_across_active_environments(30);
$discoverNames = $discovered['companies'] ?? null;
// De pre-Mímir auth-route leest alleen Name/Display_Name, niet de lowercase name-key.
if ($discoverNames !== ['Hunter van Twist', 'KVT Gas']) {
    fail('company-discovery viel niet terug op BC: ' . json_encode($discovered));
}
if (($discovered['map']['KVT Gas'] ?? '') !== 'Production') {
    fail('discovery-map mist de BC-environment: ' . json_encode($discovered['map'] ?? null));
}
$discoverCall = $calls[$beforeDiscover] ?? null;
if (!is_array($discoverCall) || strpos($discoverCall['url'], 'https://bc.example:7148/Production/ODataV4/Compan') !== 0 || $discoverCall['user'] !== 'bcuser') {
    fail('discovery-fallback riep de pre-Mímir companies-URL niet aan: ' . json_encode($discoverCall));
}
if (!odata_mimir_circuit_open()) {
    fail('discovery moet het circuit openen');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list = [
    'Production' => $auth,
    'Sandbox Two' => $sandboxAuth,
];
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Sandbox Two'];

$beforeSecondList = count($calls);
$loggedBeforeSecondList = fallback_count();
$secondNames = odata_mimir_list_companies(null);
if ($secondNames !== $expectedNames) {
    fail('company-lijst over meerdere environments gaf ' . json_encode($secondNames));
}
$secondListCalls = array_slice($calls, $beforeSecondList);
if (count($secondListCalls) !== 2) {
    fail('company-lijst moet elke auth_list-environment bevragen: ' . json_encode($secondListCalls));
}
if (($secondListCalls[0]['url'] ?? '') !== 'https://bc.example:7148/Production/ODataV4/Company' || ($secondListCalls[0]['user'] ?? '') !== 'bcuser') {
    fail('primaire environment hield niet de eigen credentials: ' . json_encode($secondListCalls[0] ?? null));
}
if (($secondListCalls[1]['url'] ?? '') !== 'https://bc.example:7148/Sandbox%20Two/ODataV4/Company' || ($secondListCalls[1]['user'] ?? '') !== 'sandbox-user') {
    fail('tweede environment werd niet apart bevraagd: ' . json_encode($secondListCalls[1] ?? null));
}
if (fallback_count() !== $loggedBeforeSecondList + 1) {
    fail('company-lijst over twee environments mag maar één keer loggen');
}

$loggedAfterSecondList = fallback_count();
$beforeSecondQuery = count($calls);
$secondQuery = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 60);
$secondQueryCall = $calls[$beforeSecondQuery] ?? null;
$expectedSecondQuery = "https://bc.example:7148/Sandbox%20Two/ODataV4/Company('Hunter%20van%20Twist')/AppResource?";
if (($secondQuery[0]['No'] ?? '') !== 'WO-1' || !is_array($secondQueryCall) || strpos((string) $secondQueryCall['url'], $expectedSecondQuery) !== 0 || $secondQueryCall['user'] !== 'sandbox-user') {
    fail('query voor een bedrijf in de tweede environment gebruikte de primaire env: ' . json_encode($secondQueryCall));
}
if (fallback_count() !== $loggedAfterSecondList) {
    fail('open circuit mag niet bij elke call opnieuw loggen');
}

$beforeEncoded = count($calls);
$encodedRows = odata_get_all(
    "https://mimir.invalid/Sandbox%20Two/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    30
);
$encodedCall = $calls[$beforeEncoded] ?? null;
$expectedEncoded = "https://bc.example:7148/Sandbox%20Two/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
if (($encodedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($encodedCall) || $encodedCall['url'] !== $expectedEncoded || $encodedCall['user'] !== 'sandbox-user') {
    fail('environment-segment werd niet één keer geëncodeerd of de verkeerde auth gebruikt: ' . json_encode($encodedCall));
}
if (strpos((string) ($encodedCall['url'] ?? ''), 'Sandbox%2520Two') !== false) {
    fail('environment-segment is dubbel geëncodeerd');
}
if (fallback_count() !== $loggedAfterSecondList) {
    fail('herschrijven na een open circuit mag niet opnieuw loggen');
}

odata_mimir_circuit_reset();
$beforeMapped = count($calls);
$mappedRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    30
);
$mappedCall = $calls[$beforeMapped] ?? null;
if (($mappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($mappedCall) || $mappedCall['url'] !== $expectedEncoded || $mappedCall['user'] !== 'sandbox-user') {
    fail('mimir-segment moet via de company-map naar de tweede environment: ' . json_encode($mappedCall));
}

odata_mimir_circuit_reset();
$loggedBeforeCaller = fallback_count();
$callsBeforeCaller = count($calls);
$callerError = null;
try {
    odata_mimir_fetch_all('https://mimir.invalid/not-an-odata-path', 10);
    fail('onvertaalbare OData-URL moet een fout geven');
} catch (Throwable $exception) {
    $callerError = $exception;
}
if (!$callerError instanceof Throwable || strpos($callerError->getMessage(), 'kon niet worden vertaald') === false) {
    fail('onvertaalbare URL gaf niet de vertaalfout: ' . ($callerError instanceof Throwable ? $callerError->getMessage() : 'geen'));
}
if (odata_mimir_circuit_open()) {
    fail('een fout uit de caller mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeCaller || count($calls) !== $callsBeforeCaller) {
    fail('een fout uit de caller mag geen fallback starten');
}

$GLOBALS['demeter_company_environment_map']['Other Co'] = 'Absent Env';
odata_mimir_circuit_reset();
$callsBeforeAbsent = count($calls);
$absentError = null;
try {
    odata_mimir_query('Other Co', 'AppResource', ['$select' => 'No'], 60);
    fail('een environment zonder eigen credentials mag niet met andermans auth bevraagd worden');
} catch (Throwable $exception) {
    $absentError = $exception;
}
if (count($calls) !== $callsBeforeAbsent) {
    fail('query naar een environment zonder credentials riep toch BC aan: ' . json_encode(array_slice($calls, $callsBeforeAbsent)));
}
if (!$absentError instanceof Throwable || strpos($absentError->getMessage(), 'Mímir') === false) {
    fail('environment zonder credentials moet de Mímir-fout teruggeven: ' . ($absentError instanceof Throwable ? $absentError->getMessage() : 'geen'));
}

odata_mimir_circuit_reset();
$callsBeforeAbsentGet = count($calls);
$absentGetError = null;
try {
    odata_get_all(
        "https://mimir.invalid/Absent%20Env/ODataV4/Company('Other%20Co')/AppWerkorders?\$select=No",
        $auth,
        30
    );
    fail('odata_get_all mag de meegegeven auth niet voor een andere environment gebruiken');
} catch (Throwable $exception) {
    $absentGetError = $exception;
}
if (count($calls) !== $callsBeforeAbsentGet) {
    fail('odata_get_all gebruikte fallback-credentials voor Absent Env: ' . json_encode(array_slice($calls, $callsBeforeAbsentGet)));
}
if (!$absentGetError instanceof Throwable || strpos($absentGetError->getMessage(), 'Mímir') === false) {
    fail('odata_get_all zonder environment-credentials moet de Mímir-fout teruggeven');
}

odata_mimir_circuit_reset();
$callsBeforeAbsentList = count($calls);
$absentListError = null;
try {
    odata_mimir_list_companies('Absent Env');
    fail('company-lijst voor een environment zonder credentials moet de Mímir-fout teruggeven');
} catch (Throwable $exception) {
    $absentListError = $exception;
}
if (count($calls) !== $callsBeforeAbsentList) {
    fail('company-lijst gebruikte credentials van een andere environment: ' . json_encode(array_slice($calls, $callsBeforeAbsentList)));
}
if (!$absentListError instanceof Throwable || strpos($absentListError->getMessage(), 'Mímir') === false) {
    fail('company-lijst zonder environment-credentials gaf niet de Mímir-fout terug');
}

$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox%20Two/ODataV4/Company('Hunter%20van%20Twist')/Bins",
    $sandboxAuth
);
if (substr($cacheKey, -strlen('|sandbox-user|Sandbox Two')) !== '|sandbox-user|Sandbox Two') {
    fail('cache-key moet de echte BC-environment gebruiken: ' . $cacheKey);
}
$placeholderKey = build_cache_key(
    "https://bc.example:7148/mimir/ODataV4/Company('Hunter%20van%20Twist')/Bins",
    $sandboxAuth
);
if (substr($placeholderKey, -strlen('|sandbox-user|Sandbox Two')) !== '|sandbox-user|Sandbox Two') {
    fail('cache-key mag geen mimir-placeholder houden: ' . $placeholderKey);
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials kwam er geen fout terug');
}
if (strpos($rethrown->getMessage(), 'Mímir') !== 0 && strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$tmpAuth = tempnam(sys_get_temp_dir(), 'penates-auth');
if (!is_string($tmpAuth)) {
    fail('tijdelijke auth.php kon niet worden aangemaakt');
}
file_put_contents($tmpAuth, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$environment = 'LoadedEnv';
$auth = ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'];
$auth_list = ['LoadedEnv' => $auth];
$base = 'https://loaded-base.example:7148/';
PHP
);
$baseUrl = 'https://keep.example:7148/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
unset($GLOBALS['base']);
$GLOBALS['PENATES_AUTH_PHP_PATH'] = $tmpAuth;
odata_bc_ensure_auth_loaded();
if (odata_bc_base_url() !== 'https://keep.example:7148/') {
    fail('een gezette baseUrl werd overschreven: ' . (string) odata_bc_base_url());
}
if (($GLOBALS['environment'] ?? null) !== 'LoadedEnv') {
    fail('placeholder-environment werd niet uit auth.php gekopieerd');
}
if (($GLOBALS['auth']['user'] ?? '') !== 'loaded-user') {
    fail('lege $auth werd niet uit auth.php gekopieerd');
}
if (($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '') !== 'loaded-user') {
    fail('lege $auth_list werd niet uit auth.php gekopieerd');
}
if (($GLOBALS['base'] ?? '') !== 'https://loaded-base.example:7148/') {
    fail('$base werd niet naar $GLOBALS gekopieerd');
}
require_once $tmpAuth;
if (odata_bc_base_url() !== 'https://keep.example:7148/' || ($GLOBALS['environment'] ?? null) !== 'LoadedEnv') {
    fail('tweede require_once mocht de gekopieerde globals niet wissen');
}
unset($GLOBALS['PENATES_AUTH_PHP_PATH']);
@unlink($tmpAuth);
$log = fallback_log();
if (strpos($log, 'loaded-secret') !== false || strpos($log, 'sandbox-secret') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}

echo "OK\n";
