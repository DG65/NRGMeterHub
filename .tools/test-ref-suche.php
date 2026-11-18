<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Referenzlauf der Gerätesuche (MeterHubDiscovery, 18.11.2026): vorher liefen 35 von 56 Funktionen in keinem Prüfstand
 * (Discover, probeMeter, probeManyParallel, scanPortOpen, DiscoverBlueLog, identifyBlueLog*, modbusRead, …).
 *
 *   php .tools/test-ref-suche.php            # vergleicht mit .tools/ref/suche.json
 *   UPDATE_REF=1 php .tools/test-ref-suche.php
 *
 * Die Suche läuft gegen einen echten kleinen Modbus-TCP-Server auf 127.0.0.1 (ref/modbus-server.php, Kindprozess), der je
 * Szenario die Register eines Gerätetyps beantwortet. Die Registerwerte sind aus den Plausibilitätsprüfungen der Suche
 * abgeleitet (Frequenz 45 bis 65 Hz, Spannung 30 bis 500 V) und nicht an Geräten gemessen: der Lauf hält fest, was die Suche
 * heute daraus macht. Es verlässt nie das lokale Netz.
 */

const VARIABLETYPE_FLOAT = 2;

$GLOBALS['OBJ'] = [];
$GLOBALS['PROP'] = [];
$GLOBALS['ATTR'] = [];
$GLOBALS['INSTMOD'] = [];
$GLOBALS['NEXTID'] = 9000;
$GLOBALS['FORMFIELDS'] = [];
$GLOBALS['APPLIED'] = [];   // iid => Anzahl ApplyChanges-Aufrufe (Aktivitätsnachweis)

function obj($id, $type, $name, $parent, $ident = '') {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => $type, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ParentID' => $parent];
    return $id;
}
function IPS_GetObject($id)   { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetName($id)     { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ('#' . $id); }
function IPS_ObjectExists($id){ return isset($GLOBALS['OBJ'][$id]); }
function IPS_GetInstanceListByModuleID($guid) {
    $out = [];
    foreach ($GLOBALS['INSTMOD'] as $iid => $g) { if ($g === $guid) { $out[] = $iid; } }
    return $out;
}
function IPS_CreateInstance($guid) {
    $id = $GLOBALS['NEXTID']++;
    obj($id, 1, 'neue Instanz', 0);
    $GLOBALS['INSTMOD'][$id] = $guid;
    return $id;
}
function IPS_SetProperty($iid, $n, $v) { $GLOBALS['PROP'][$iid][$n] = $v; }
function IPS_GetProperty($iid, $n)     { return $GLOBALS['PROP'][$iid][$n] ?? ''; }
function IPS_ApplyChanges($iid) { $GLOBALS['APPLIED'][$iid] = ($GLOBALS['APPLIED'][$iid] ?? 0) + 1; }

class IPSModule
{
    use I18nStub;
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v)  { $this->defs[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeBoolean($n)  { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = (in_array($p, ['caption', 'label'], true) && is_string($v)) ? i18n_tr($v) : $v; }   // die Konsole übersetzt auch nachgeschobene Texte
    protected function ReloadForm() {}
    protected function RegisterVariableBoolean($n, $c, $p, $pos) {}
    protected function SetStatus($s) {}
    public function GetValue($n) { return false; }
    public function SetValue($n, $v) {}
    protected function RegisterTimer($n, $i, $s) {}
    protected function SendDebug($sender, $msg, $format) {}
}

date_default_timezone_set('UTC');
$GLOBALS['PHPNOTES'] = [];
set_error_handler(function ($no, $str, $file, $line) { $GLOBALS['PHPNOTES'][preg_replace('/\s+in\s+\S+$/', '', $str) . ' (' . basename($file) . ')'] = true; return true; });
function IPS_GetObjectIDByIdent($ident, $parent) { return $GLOBALS['SCANABORT_VAR'] ?? false; }
function IPS_SetHidden($id, $h) {}
function IPS_GetKernelDir() { return sys_get_temp_dir() . '/'; }
require_once __DIR__ . '/ref/harness.php';   // lädt MeterHub/module.php (oder MHUB_MODULE) und die gemeinsamen Hilfen
$discPath = getenv('MHUB_DISCOVERY') ?: dirname(__DIR__) . '/MeterHubDiscovery/module.php';
require_once $discPath;

class RefDisc extends MeterHubDiscovery
{
    public array $captions = [];
    public int $abortAfter = -1;        // ab dem n-ten scanAborted()-Aufruf gilt „Abbrechen“ als geklickt
    public int $abortCalls = 0;
    public function UpdateFormField($f, $p, $v) { if ($f === 'ScanProgress' && $p === 'caption') { $this->captions[] = (string)$v; } $GLOBALS['FORMFIELDS'][$f][$p] = $v; }
    public function GetValue($n) { if ($n === 'ScanAbort') { $this->abortCalls++; return $this->abortAfter >= 0 && $this->abortCalls > $this->abortAfter; } return false; }
    public function SetValue($n, $v) { }
    protected function SetStatus($s) { $GLOBALS['STATUS'][$this->InstanceID] = $s; }
}

// ---- Profil-Bausteine -----------------------------------------------------------------------------------------
function f32(array &$w, int $addr, float $v, bool $swap = false): void { $u = array_values(unpack('n2', pack('G', $v))); $w[(string)$addr] = $swap ? $u[1] : $u[0]; $w[(string)($addr + 1)] = $swap ? $u[0] : $u[1]; }
function ascii(array &$w, int $start, int $count, string $text): void
{
    $bytes = str_pad(substr($text, 0, $count * 2), $count * 2, "\0");
    for ($j = 0; $j < $count; $j++) { $w[(string)($start + $count - 1 - $j)] = (ord($bytes[$j * 2]) << 8) | ord($bytes[$j * 2 + 1]); }
}
$profiles = [
    'pac2200_Unit247'  => fn() => ['units' => ['247' => ['3' => (function () { $w = []; f32($w, 55, 50.0); f32($w, 1, 231.0); return $w; })()]]],
    'janitza_klassisch' => fn() => ['units' => ['1' => ['3' => (function () { $w = []; f32($w, 19050, 50.0); f32($w, 19000, 231.0); return $w; })()]]],
    'janitza_umg800'    => fn() => ['units' => ['1' => ['3' => (function () { $w = []; f32($w, 19054, 50.0); f32($w, 19050, 0.97); f32($w, 19000, 231.0); return $w; })()]]],
    'shelly_pro3em'     => fn() => ['units' => ['1' => ['4' => (function () { $w = []; f32($w, 1033, 50.0, true); f32($w, 1020, 231.0, true); return $w; })()]]],
    'carlo_gavazzi'     => fn() => ['units' => ['1' => ['4' => (function () { $w = []; $v = 2310; $w['0'] = $v & 0xFFFF; $w['1'] = $v >> 16; $w['51'] = 500; return $w; })()]]],
    'whatwatt'          => fn() => ['units' => ['1' => ['4' => (function () { $w = []; f32($w, 1, 231.0); f32($w, 3, 232.0); return $w; })()]]],
    'phoenix_Unit255'   => fn() => ['units' => ['255' => ['4' => (function () { $w = []; f32($w, 4096, 231.0); f32($w, 4098, 232.0); return $w; })()]]],
    'eastron_sdm'       => fn() => ['units' => ['1' => ['4' => (function () { $w = []; f32($w, 0, 231.0); f32($w, 70, 50.0); return $w; })()]]],
    'goe_controller'    => fn() => ['units' => ['1' => ['4' => (function () { $w = []; f32($w, 1000, 231.0); f32($w, 1002, 232.0); return $w; })()]]],
    'frequenz_unplausibel' => fn() => ['units' => ['247' => ['3' => (function () { $w = []; f32($w, 55, 71.0); f32($w, 1, 231.0); return $w; })()]]],
    'spannung_unplausibel' => fn() => ['units' => ['247' => ['3' => (function () { $w = []; f32($w, 55, 50.0); f32($w, 1, 12.0); return $w; })()]]],
    'unbelegte_Register_NaN' => fn() => ['units' => ['1' => ['4' => ['1000' => 0xFFFF, '1001' => 0xFFFF, '1002' => 0xFFFF, '1003' => 0xFFFF, '0' => 0xFFFF, '1' => 0xFFFF, '2' => 0xFFFF, '3' => 0xFFFF]]]],
    'bluelog_Logger_und_Wechselrichter' => fn() => ['units' => [
        '97'  => ['3' => (function () { $w = ['40000' => 0]; ascii($w, 40033, 32, 'blue\'Log XC'); $w['40113'] = 97; return $w; })()],
        '101' => ['3' => (function () { $w = ['40000' => 1]; ascii($w, 40033, 32, 'AE 3TL 20-IEC (Gen 2)'); $w['40113'] = 3; return $w; })()],
        '150' => ['3' => (function () { $w = ['40000' => 3]; ascii($w, 40033, 32, 'Janitza UMG 604'); $w['40113'] = 9; return $w; })()],
        '160' => ['3' => (function () { $w = ['40000' => 2]; ascii($w, 40033, 32, 'Sensorbox'); $w['40113'] = 1; return $w; })()],   // Typ 2: gefunden, aber nicht vorgeschlagen
    ]],
    'bluelog_nur_Power_Control' => fn() => ['units' => ['1' => ['3' => (function () { $w = []; f32($w, 98, 50.0, true); return $w; })()]]],
    'bluelog_nur_RPC' => fn() => ['units' => ['10' => ['3' => (function () { $w = []; f32($w, 42, 50.0, true); return $w; })()]]],
    'bluelog_Logger_unter_falscher_Adresse' => fn() => ['units' => ['101' => ['3' => ['40000' => 0]]]],
    'nichts_antwortet' => fn() => ['units' => ['200' => ['3' => []]]],
];

/** Server mit Profil starten; Rückgabe [Prozess, Port]. */
function startServer(array $profile): array
{
    $file = tempnam(sys_get_temp_dir(), 'mhprof');
    file_put_contents($file, json_encode($profile));
    $proc = proc_open([PHP_BINARY, __DIR__ . '/ref/modbus-server.php', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $line = fgets($pipes[1]);
    return [$proc, (int)substr(trim((string)$line), 5), $pipes, $file];
}
function stopServer(array $srv): void { proc_terminate($srv[0]); proc_close($srv[0]); @unlink($srv[3]); }

function normCaption(string $c, int $port): string { return preg_replace('/\d{2}:\d{2}:\d{2}/', 'HH:MM:SS', $port > 1000 ? str_replace((string)$port, 'PORT', $c) : $c); }
/** Eine Suche als Referenzfall: Instanz mit Bereich 127.0.0.1, Port des Servers. */
function discoverCase(?array $profile, array $props = [], int $abortAfter = -1): array
{
    static $iid = 100;
    $iid++;
    $GLOBALS['FORMFIELDS'] = [];
    $srv = $profile === null ? null : startServer($profile);
    $port = $srv ? $srv[1] : 1;     // Port 1: nichts hört zu
    $GLOBALS['PROP'][$iid] = $props + ['RangeStart' => '127.0.0.1', 'RangeEnd' => '127.0.0.1', 'Port' => $port, 'BlueLogPort' => $port, 'ScadaAddrStart' => 100, 'ScadaAddrEnd' => 119];
    $GLOBALS['ATTR'][$iid] = [];
    $d = new RefDisc($iid);
    $d->Create();
    $d->abortAfter = $abortAfter;
    $warn = [];
    set_error_handler(function ($no, $str) use (&$warn) { $warn[] = $str; return true; });
    $d->Discover();
    restore_error_handler();
    if ($srv) { stopServer($srv); }
    $results = json_decode($GLOBALS['ATTR'][$iid]['ResultsJSON'] ?? '[]', true);
    foreach ($results as &$r) { $r['port'] = ($r['port'] ?? 0) === $port ? 'PORT' : $r['port']; }
    unset($r);
    $captions = array_values(array_unique(array_filter(array_map(fn($c) => normCaption($c, $port), $d->captions), fn($c) => !str_starts_with($c, 'Portscan läuft') && !str_starts_with($c, 'Port scan running'))));
    return ['ergebnisse' => $results, 'status' => $GLOBALS['STATUS'][$iid] ?? null, 'letzte_Suche_gesetzt' => ((int)($GLOBALS['ATTR'][$iid]['LastScanTs'] ?? 0)) > 0,
            'meldungen' => $captions, 'zusammenfassung' => normCaption((string)($GLOBALS['FORMFIELDS']['ScanSummary']['caption'] ?? ''), $port), 'warnungen' => $warn];
}

$result = ['_hinweis' => 'Gerätesuche gegen einen lokalen Modbus-TCP-Server; Registerwerte aus den Plausibilitätsprüfungen abgeleitet, nicht am Gerät gemessen. Referenz des heutigen Verhaltens.'];

// Vollständige Suchläufe (jeweils mehrere Sekunden: die Suche wartet bei blue'Log-Proben auf Zeitgrenzen)
$result['suche_kein_Server'] = discoverCase(null);
$result['suche_Bereich_leer'] = discoverCase(null, ['RangeStart' => '', 'RangeEnd' => '']);
$result['suche_Ausschlussliste_trifft'] = discoverCase($profiles['eastron_sdm'](), ['IgnoreIPs' => '127.0.0.1']);
$result['suche_Eastron_gefunden'] = discoverCase($profiles['eastron_sdm']());
$result['suche_nichts_antwortet'] = discoverCase($profiles['nichts_antwortet']());
$result['suche_blueLog_Logger_und_Geraete'] = discoverCase($profiles['bluelog_Logger_und_Wechselrichter']());
$result['suche_Abbruch_waehrend_der_Suche'] = discoverCase($profiles['bluelog_Logger_und_Wechselrichter'](), [], 3);

// Erkennung je Zählertyp (identifyMeter() direkt, ohne die blue'Log-Proben)
$serverCall = function (array $profile, callable $fn) {
    $srv = startServer($profile);
    try { $r = $fn($srv[1]); } finally { stopServer($srv); }
    return $r;
};
$dd = new RefDisc(800); $dd->Create();
$priv = function (string $m, ...$args) use ($dd) { $r = new ReflectionMethod($dd, $m); return $r->invoke($dd, ...$args); };
foreach ($profiles as $name => $make) {
    if (str_starts_with($name, 'bluelog')) { continue; }
    $result['erkennung_' . $name] = $serverCall($make(), function ($port) use ($priv) {
        $r = $priv('identifyMeter', '127.0.0.1', $port);
        if ($r !== null) { $r['port'] = $r['port'] === $port ? 'PORT' : $r['port']; }
        return $r;
    });
}
$result['erkennung_kein_Server'] = $priv('identifyMeter', '127.0.0.1', 1);

// Modbus-Exception bei offen bleibender Verbindung (so verhält sich ein echtes Gerät): die Suche darf nicht bis zur Zeitgrenze warten (0.39.2, Fund 3)
$exc = $serverCall(['units' => ['1' => ['3' => [], '4' => []]], 'keepOpenOnException' => true], function ($port) use ($priv) {
    $t = microtime(true);
    $v = $priv('readFloat', '127.0.0.1', $port, 1, 55, 1.0);   // FC3, Adresse 55: nicht vorhanden → Exception 02
    $input = $priv('readFloatInput', '127.0.0.1', $port, 1, 1, 1.0);
    $elapsed = microtime(true) - $t;
    return ['wert' => $v, 'wert_input' => $input, 'schnell' => $elapsed < 0.7, 'dauer_s' => round($elapsed, 1)];
});
$result['exception_ohne_warten'] = ['wert' => $exc['wert'], 'wert_input' => $exc['wert_input'], 'schnell' => $exc['schnell']];
$excSlow = $exc['dauer_s'];

// Zeitgrenze mit Nachkommaanteil: 1,5 s darf nicht auf 1 s abgeschnitten werden und keine PHP-Meldung erzeugen (0.39.4, Fund 5)
$timeoutCase = $serverCall(['units' => ['1' => ['3' => []]], 'silentUnits' => [1]], function ($port) use ($priv) {
    $t = microtime(true);
    $v = $priv('readHolding', '127.0.0.1', $port, 1, 40000, 1, 1.5);
    return ['wert' => $v, 'dauer' => microtime(true) - $t];
});
$result['zeitgrenze_nachkommaanteil'] = ['wert' => $timeoutCase['wert'], 'volle_zeitgrenze_gewartet' => $timeoutCase['dauer'] >= 1.4 && $timeoutCase['dauer'] < 2.4];
$timeoutDauer = round($timeoutCase['dauer'], 1);

// Gegenseite schließt die Verbindung (unbekannte Unit-ID): die parallele Probe darf nicht bis zur Zeitgrenze mit voller CPU drehen (0.39.3, Fund 4)
$closedCase = $serverCall(['units' => ['97' => ['3' => ['40000' => 0]]]], function ($port) use ($priv) {
    $targets = [];
    foreach ([200, 201, 202] as $u) { $targets[] = ['host' => '127.0.0.1', 'port' => $port, 'unitId' => $u, 'reg' => 40000, 'count' => 1]; }
    $t0 = microtime(true); $c0 = getrusage();
    $res = $priv('probeManyParallel', $targets, 0.7);
    $wall = microtime(true) - $t0; $c1 = getrusage();
    $cpu = ($c1['ru_utime.tv_sec'] - $c0['ru_utime.tv_sec']) + ($c1['ru_utime.tv_usec'] - $c0['ru_utime.tv_usec']) / 1e6 + ($c1['ru_stime.tv_sec'] - $c0['ru_stime.tv_sec']) + ($c1['ru_stime.tv_usec'] - $c0['ru_stime.tv_usec']) / 1e6;
    $mixed = array_merge([['host' => '127.0.0.1', 'port' => $port, 'unitId' => 97, 'reg' => 40000, 'count' => 1]], $targets);
    $t1 = microtime(true);
    $resMixed = $priv('probeManyParallel', $mixed, 0.7);
    $wall2 = microtime(true) - $t1;
    return ['nur_geschlossen' => $res, 'schnell' => $wall < 0.4, 'wenig_cpu' => $cpu < 0.3, 'gemischt' => $resMixed, 'gemischt_schnell' => $wall2 < 0.4, 'wand_s' => round($wall, 2), 'cpu_s' => round($cpu, 2)];
});
$result['parallel_geschlossene_gegenseite'] = ['nur_geschlossen' => $closedCase['nur_geschlossen'], 'schnell' => $closedCase['schnell'], 'wenig_cpu' => $closedCase['wenig_cpu'], 'gemischt' => $closedCase['gemischt'], 'gemischt_schnell' => $closedCase['gemischt_schnell']];

// blue'Log-Erkennung des Hosts und der Geräte (identifyBlueLogHost()/identifyBlueLogDevicesBatch() direkt)
foreach (['bluelog_Logger_und_Wechselrichter', 'bluelog_nur_Power_Control', 'bluelog_nur_RPC', 'bluelog_Logger_unter_falscher_Adresse', 'nichts_antwortet'] as $name) {
    $result['bluelog_Host_' . $name] = $serverCall($profiles[$name](), fn($port) => $priv('identifyBlueLogHost', '127.0.0.1', $port));
}
$result['bluelog_Geraete_Bündel'] = $serverCall($profiles['bluelog_Logger_und_Wechselrichter'](), function ($port) use ($priv) {
    $found = $priv('identifyBlueLogDevicesBatch', '127.0.0.1', $port, [97, 101, 150, 160, 170]);
    foreach ($found as &$f) { $f['port'] = $f['port'] === $port ? 'PORT' : $f['port']; }
    return $found;
});

// Reine Hilfsfunktionen über Reflection
$d = new RefDisc(900);
$d->Create();
$call = function (string $m, ...$args) use ($d) { $r = new ReflectionMethod($d, $m); return $r->invoke($d, ...$args); };
$helpers = [];
foreach ([['192.168.1.250', '192.168.2.3'], ['10.0.0.5', '10.0.0.5'], ['10.0.0.9', '10.0.0.1'], ['x', '10.0.0.1'], ['255.255.255.254', '255.255.255.255']] as [$a, $b]) { $helpers["expandRange($a,$b)"] = $call('expandRange', $a, $b); }
foreach (['10.0.0.5, 10.0.0.6;10.0.0.7 10.0.0.5', 'kein.ip, 10.0.0.300, 192.168.1.1', ''] as $raw) {
    $GLOBALS['PROP'][900]['IgnoreIPs'] = $raw;
    $helpers['ParseIgnoreIPs(' . $raw . ')'] = array_values($call('ParseIgnoreIPs'));
}
foreach ([[100, 119], [119, 100], [97, 97], [1, 247], [0, 3]] as [$from, $to]) {
    $GLOBALS['PROP'][900]['ScadaAddrStart'] = $from; $GLOBALS['PROP'][900]['ScadaAddrEnd'] = $to;
    $helpers["blueLogScadaSampleIds($from,$to)"] = $call('blueLogScadaSampleIds');
}
foreach ([[0x4248, 0x0000], [0x0000, 0x4248], [0x7FC0, 0x0000], [0xFFFF, 0xFFFF]] as [$hi, $lo]) { $helpers["wordSwappedFloat($hi,$lo)"] = RefNorm::value($call('wordSwappedFloat', $hi, $lo)); }
$result['hilfsfunktionen'] = $helpers;

// blue'Log-Lizenzprüfung und zweiter Suchmodus
$licCase = function (array $profile, string $host = '127.0.0.1') {
    $srv = startServer($profile);
    $d = new RefDisc(901);
    $d->Create();
    $text = $d->CheckBlueLogLicenses($host, $srv[1]);
    stopServer($srv);
    return explode("\n", str_replace((string)$srv[1], 'PORT', $text));
};
$result['Lizenzpruefung_nur_SCADA'] = $licCase(['units' => ['97' => ['3' => ['0' => 0]]]]);
$result['Lizenzpruefung_Exception_zaehlt'] = $licCase(['units' => ['1' => ['3' => []], '10' => ['3' => []], '97' => ['3' => []]]]);
$result['Lizenzpruefung_ohne_Host'] = [(new RefDisc(902))->CheckBlueLogLicenses('  ', 502)];
$blCase = function (array $profile, array $props) {
    $srv = startServer($profile);
    $GLOBALS['PROP'][903] = $props + ['BlueLogHost' => '127.0.0.1', 'BlueLogPort' => $srv[1], 'Port' => $srv[1], 'ScadaAddrStart' => 100, 'ScadaAddrEnd' => 119];
    $GLOBALS['ATTR'][903] = []; $GLOBALS['FORMFIELDS'] = [];
    $d = new RefDisc(903);
    $d->Create();
    $d->DiscoverBlueLog();
    stopServer($srv);
    $res = json_decode($GLOBALS['ATTR'][903]['ResultsJSON'] ?? '[]', true);
    foreach ($res as &$r) { $r['port'] = $r['port'] === $srv[1] ? 'PORT' : $r['port']; }
    return ['ergebnisse' => $res, 'meldungen' => array_values(array_unique(array_map(fn($c) => str_replace((string)$srv[1], 'PORT', $c), $d->captions)))];
};
$result['DiscoverBlueLog_Logger_und_Geraete'] = $blCase($profiles['bluelog_Logger_und_Wechselrichter'](), []);
$result['DiscoverBlueLog_ohne_Host'] = $blCase($profiles['bluelog_Logger_und_Wechselrichter'](), ['BlueLogHost' => '']);

$result['php_hinweise'] = array_keys($GLOBALS['PHPNOTES']);
sort($result['php_hinweise']);

// ---- Vergleich -------------------------------------------------------------------------------------------------
$refFile = __DIR__ . '/ref/suche.json';
$ref = RefFile::load($refFile);
if (getenv('UPDATE_REF') || $ref === null) {
    RefFile::save($refFile, $result);
    echo "Referenz geschrieben: $refFile (" . (count($result) - 1) . " Fälle)\n";
    $ref = $result;
}
$bad = [];
foreach ($result as $k => $v) { if (json_encode($v) !== json_encode($ref[$k] ?? null)) { $bad[] = $k; } }
foreach (array_keys($ref) as $k) { if (!array_key_exists($k, $result)) { $bad[] = "$k (in der Referenz, nicht mehr erzeugt)"; } }
check('Gerätesuche: ' . (count($result) - 1) . ' Fälle stimmen mit der Referenz überein', $bad === [], implode(', ', array_slice($bad, 0, 5)));
$names = fn(string $k) => array_column($result[$k]['ergebnisse'] ?? [], 'meter');
$idm = fn(string $k) => $result[$k]['meter'] ?? null;
check('Fund 5: Zeitgrenze 1,5 s wird voll genutzt (' . $timeoutDauer . ' s statt abgeschnitten auf 1,0 s), keine PHP-Meldung zur Fließkomma-Umwandlung', $result['zeitgrenze_nachkommaanteil']['volle_zeitgrenze_gewartet'] === true && $result['zeitgrenze_nachkommaanteil']['wert'] === null && !preg_grep('/Implicit conversion/', $result['php_hinweise']));
check('Fund 4: geschlossene Gegenseite in der parallelen Probe: ' . $closedCase['wand_s'] . ' s Wartezeit, ' . $closedCase['cpu_s'] . ' s CPU (vorher bis zur Zeitgrenze mit voller CPU), gemischt mit einer antwortenden Unit richtig ausgewertet', $result['parallel_geschlossene_gegenseite']['schnell'] === true && $result['parallel_geschlossene_gegenseite']['wenig_cpu'] === true && $result['parallel_geschlossene_gegenseite']['gemischt_schnell'] === true && $result['parallel_geschlossene_gegenseite']['gemischt'][0] === [0] && $result['parallel_geschlossene_gegenseite']['gemischt'][1] === null);
check('Fund 3: eine Modbus-Exception bei offen bleibender Verbindung kostet keine Sekunde Wartezeit (zwei Proben in ' . $excSlow . ' s statt 2 × 1,0 s)', $result['exception_ohne_warten']['schnell'] === true && $result['exception_ohne_warten']['wert'] === null && $result['exception_ohne_warten']['wert_input'] === null);
check('Siemens PAC2200 wird unter Unit-ID 247 erkannt', $idm('erkennung_pac2200_Unit247') === 'siemens_pac2200' && $result['erkennung_pac2200_Unit247']['unitId'] === 247);
check('jeder der 9 Zählertypen wird an seinem Profil erkannt (Janitza klassisch als UMG 604)', array_map($idm, array_combine(['pac','j','j8','sh','cg','ww','ph','ea','go'], ['erkennung_pac2200_Unit247','erkennung_janitza_klassisch','erkennung_janitza_umg800','erkennung_shelly_pro3em','erkennung_carlo_gavazzi','erkennung_whatwatt','erkennung_phoenix_Unit255','erkennung_eastron_sdm','erkennung_goe_controller'])) === ['pac' => 'siemens_pac2200', 'j' => 'janitza_umg604', 'j8' => 'janitza_umg800', 'sh' => 'shelly_pro3em', 'cg' => 'carlo_gavazzi_em', 'ww' => 'whatwatt', 'ph' => 'phoenix_eem375', 'ea' => 'eastron_sdm72d', 'go' => 'goe_controller']);
check('ohne Server und bei unplausiblen Werten wird nichts erkannt', $idm('erkennung_kein_Server') === null && $idm('erkennung_frequenz_unplausibel') === null && $idm('erkennung_spannung_unplausibel') === null);
check('blue\'Log: Logger (97) und die Geräte mit unterstütztem Typ werden vorgeschlagen, ein Sensor (Typ 2) nicht', array_column($result['bluelog_Geraete_Bündel'], 'meter') === ['bluelog_scada_logger', 'bluelog_scada_inverter', 'bluelog_scada_meter']);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
