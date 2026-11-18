<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand für die Weichen nach Zählerart (0.39.8, Umbau 4): welcher Zählertyp gehört zu welcher Gruppe (Cloud, HTTP, MQTT,
 * schreibend, Gültigkeitszeit, einphasig, Modbus)? Alle Aufrufer in MeterHub/module.php und libs/ fragen nur die Prädikate
 * IsCloudMeter() usw.; dieser Prüfstand nagelt für JEDEN Zählertyp fest, was sie antworten. Stub-Kopf wie test-powerinvert.php.
 */

const VARIABLETYPE_FLOAT = 2;

$GLOBALS['OBJ']  = [];
$GLOBALS['VAR']  = [];
$GLOBALS['VAL']  = [];
$GLOBALS['PROP'] = [];
$GLOBALS['NEXTID'] = 9000;

function obj($id, $type, $name, $parent, $ident = '') {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => $type, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ParentID' => $parent];
    return $id;
}
function vari($id, $name, $parent, $ident, $value) {
    obj($id, 2, $name, $parent, $ident);
    $GLOBALS['VAR'][$id] = ['VariableType' => VARIABLETYPE_FLOAT, 'VariableProfile' => '', 'VariableCustomProfile' => ''];
    $GLOBALS['VAL'][$id] = $value;
    return $id;
}
function IPS_GetObject($id)       { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetChildrenIDs($id) {
    $out = [];
    foreach ($GLOBALS['OBJ'] as $k => $o) { if ($o['ParentID'] == $id) { $out[] = $k; } }
    return $out;
}
function IPS_GetObjectIDByIdent($ident, $parent) {
    foreach (IPS_GetChildrenIDs($parent) as $c) {
        if ($GLOBALS['OBJ'][$c]['ObjectIdent'] === $ident) { return $c; }
    }
    return false;
}
function IPS_SetIdent($id, $i)  { $GLOBALS['OBJ'][$id]['ObjectIdent'] = $i; return true; }
function IPS_SetName($id, $n)   { $GLOBALS['OBJ'][$id]['ObjectName'] = $n; return true; }
function IPS_GetName($id)       { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ''; }
function IPS_LogMessage($s, $m) { $GLOBALS['LOG'][] = $m; }
function ident($id)             { return $GLOBALS['OBJ'][$id]['ObjectIdent']; }
function GetValue($id)          { return $GLOBALS['VAL'][$id] ?? 0; }
function SetValueFloat($id, $v) { $GLOBALS['VAL'][$id] = $v; }
function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['ACS'] ?? []; } // Standard: kein Archiv im Test
function IPS_VariableExists($id) { return isset($GLOBALS['OBJ'][$id]); }
function AC_ReAggregateVariable($ac, $vid) {
    if (!empty($GLOBALS['AGG_BUSY'])) { trigger_error('Eine andere Aggregation wird aktuell durchgeführt', E_USER_WARNING); return false; }
    $GLOBALS['AGG'][] = $vid;
    return true;
}

foreach (['KL_MESSAGE' => 101, 'KL_WARNING' => 104, 'KL_ERROR' => 103, 'KL_NOTIFY' => 102, 'KL_DEBUG' => 100, 'KL_CUSTOM' => 105] as $c => $v) { if (!defined($c)) { define($c, $v); } }
class IPSModule
{
    use I18nStub;
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) { $this->InstanceID = $id; }
    public function LogMessage($m, $type) { $GLOBALS['LOG'][] = $m; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function ConnectParent($guid) {}
    protected function RegisterPropertyString($n, $v)  { $this->defs[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v)   { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? false); }
    public function ReadPropertyFloat($n)   { return (float)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0.0); }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetStatus($s) {}
    protected function SetVisualizationType($t) {}
    protected function SendDebug($sender, $msg, $format) {}
    public function UpdateFormField($f, $p, $v) { $GLOBALS['UFF'][] = [$f, $p, $v]; }
    protected function ReloadForm() {}
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeBoolean($n)  { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
}

require_once getenv('MHUB_MODULE') ?: dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
function call($obj, string $m, ...$a) { return (new ReflectionMethod($obj, $m))->invoke($obj, ...$a); }

$expected = [
    'IsCloudMeter'    => ['inexogy'],
    'IsHttpMeter'     => ['tasmota'],
    'IsMqttMeter'     => ['shelly_mqtt'],
    'IsWritableMeter' => ['bluelog_rpc', 'bluelog_powercontrol'],
    'HasRpcValidTime' => ['bluelog_rpc'],
    'IsSinglePhaseType' => ['eastron_sdm120', 'eastron_sdm220', 'eastron_sdm230', 'tasmota'],
];
$drivers = array_keys((new ReflectionClassConstant('MeterHub', 'DRIVERS'))->getValue());
$keys = array_merge($drivers, ['unbekannt', '']);
echo "1) Alle " . count($drivers) . " Zählertypen aus DRIVERS (plus unbekannt und leer) gegen die erwarteten Gruppen\n";
foreach ($expected as $m => $members) {
    foreach ($members as $k) { check("$m: $k ist in DRIVERS", in_array($k, $drivers, true)); }
}
$id = 100;
foreach ($keys as $key) {
    $id++;
    $GLOBALS['PROP'][$id]['Meter'] = $key;
    $hub = new MeterHub($id);
    $hub->Create();
    $ok = true; $bad = '';
    foreach ($expected as $m => $members) {
        $want = in_array($key, $members, true);
        if (call($hub, $m) !== $want || call($hub, $m, $key) !== $want) { $ok = false; $bad .= " $m"; }
    }
    $modbus = !in_array($key, array_merge($expected['IsCloudMeter'], $expected['IsHttpMeter'], $expected['IsMqttMeter']), true);
    if (call($hub, 'UsesModbus') !== $modbus) { $ok = false; $bad .= ' UsesModbus'; }
    check("'$key': Gruppen und Modbus-Weg stimmen (Eigenschaft und übergebener Schlüssel)", $ok, trim($bad));
}
echo "\n2) Ein übergebener Schlüssel hat Vorrang vor der Einstellung\n";
$GLOBALS['PROP'][900]['Meter'] = 'inexogy';
$hub = new MeterHub(900); $hub->Create();
check('eingestellt inexogy, gefragt tasmota: nicht Cloud, aber HTTP', call($hub, 'IsCloudMeter', 'tasmota') === false && call($hub, 'IsHttpMeter', 'tasmota') === true);
check('ohne Angabe gilt die Einstellung', call($hub, 'IsCloudMeter') === true && call($hub, 'IsHttpMeter') === false);

// ---------------------------------------------------------------------------
echo "\n3) Formular beim Umschalten des Zählertyps (OnChangeMeter): Sollwert-Panel, Gültigkeitszeit, Messmodus\n";
function lastVisible(string $field) { $v = null; foreach ($GLOBALS['UFF'] ?? [] as [$f, $p, $val]) { if ($f === $field && $p === 'visible') { $v = $val; } } return $v; }
$id3 = 300;
foreach ([
    // Schlüssel         Sollwert  Gültigkeit  Messmodus sichtbar (kein Einphasen-Typ)
    ['siemens_pac2200',  false, false, true],
    ['bluelog_rpc',      true,  true,  true],
    ['bluelog_powercontrol', true, false, true],
    ['eastron_sdm120',   false, false, false],
    ['tasmota',          false, false, false],
] as [$key, $writable, $valid, $mode]) {
    $id3++; $GLOBALS['PROP'][$id3]['Meter'] = $key; $GLOBALS['UFF'] = [];
    $hub = new MeterHub($id3); $hub->Create();
    $hub->OnChangeMeter($key);
    check("$key: Sollwert-Panel " . ($writable ? 'sichtbar' : 'verborgen') . ", Gültigkeitszeit " . ($valid ? 'sichtbar' : 'verborgen') . ", Messmodus " . ($mode ? 'sichtbar' : 'verborgen'),
        lastVisible('WriteWarningPanel') === $writable && lastVisible('RpcValidTimeMin') === $valid && lastVisible('MeasureMode') === $mode,
        json_encode([lastVisible('WriteWarningPanel'), lastVisible('RpcValidTimeMin'), lastVisible('MeasureMode')]));
}
foreach (['switch' => false, 'em3' => true] as $kind => $mode) {
    $id3++; $GLOBALS['PROP'][$id3]['Meter'] = 'shelly_mqtt'; $GLOBALS['ATTR'][$id3]['ShellyKind'] = $kind; $GLOBALS['UFF'] = [];
    $hub = new MeterHub($id3); $hub->Create();
    $hub->OnChangeMeter('shelly_mqtt');
    check("shelly_mqtt ($kind): Messmodus " . ($mode ? 'sichtbar' : 'verborgen'), lastVisible('MeasureMode') === $mode, json_encode(lastVisible('MeasureMode')));
}

echo "\n4) Cloud-Weichen im Archivpfad\n";
$id4 = 400;
foreach (['tasmota' => false, 'inexogy' => true] as $key => $cloud) {
    $id4 += 10; $GLOBALS['PROP'][$id4]['Meter'] = $key; $GLOBALS['PROP'][$id4]['FuncTotal'] = 'grid';
    obj($id4, 1, 'Zähler ' . $key, 0);
    vari($id4 + 1, 'Leistung', $id4, 'power_total', 0.0);
    $hub = new MeterHub($id4); $hub->Create();
    $GLOBALS['ACS'] = [55];
    if (!function_exists('AC_GetLoggingStatus')) { eval('function AC_GetLoggingStatus($a, $v) { return false; }'); }
    $d = call($hub, 'ComputeDiagnostics');
    $reason = (string)($d['entries'][0]['reason'] ?? '');
    check("Richtungsprüfung $key: " . ($cloud ? 'Cloud-Zähler bekommt keinen Leistungsabgleich' : 'lokaler Zähler wird weiter geprüft'),
        $cloud ? str_contains($reason, 'Cloud') : ($reason !== '' && !str_contains($reason, 'Cloud')), $reason);
}
$GLOBALS['ACS'] = [];

echo "\n5) Cloud-Weichen bei Diagnose und Archivstatus\n";
$id5 = 500; $GLOBALS['PROP'][$id5]['Meter'] = 'tasmota';
$hub = new MeterHub($id5); $hub->Create();
$warn = [];
set_error_handler(function ($no, $msg) use (&$warn) { $warn[] = $msg; return true; });
$hub->DiagnoseInexogyReadings();
restore_error_handler();
check('DiagnoseInexogyReadings an einem Nicht-Cloud-Zähler: Warnung statt Abfrage', count($warn) === 1 && str_contains($warn[0], 'Inexogy'), json_encode($warn));
$GLOBALS['UFF'] = [];
call($hub, 'UpdateInexogyArchiveStatusField');
check('Archivstatus-Zeile: ein Nicht-Cloud-Zähler fasst das Formular nicht an', $GLOBALS['UFF'] === [], json_encode($GLOBALS['UFF']));

echo "\n6) Verbindungstest meldet bei HTTP-Geräten den Fehler des Geräts\n";
$id6 = 600; $GLOBALS['PROP'][$id6]['Meter'] = 'tasmota'; $GLOBALS['PROP'][$id6]['Host'] = '';
$hub = new MeterHub($id6); $hub->Create();
$msg = $hub->TestConnection();
check('Tasmota ohne Host: Fehlertext des Clients statt „Host/Port/Unit-ID prüfen“', str_contains($msg, '❌') && !str_contains($msg, 'Unit-ID') && !str_contains($msg, 'unit ID'), $msg);
$id6++; $GLOBALS['PROP'][$id6]['Meter'] = 'shelly_mqtt'; $GLOBALS['PROP'][$id6]['MqttBridgeID'] = 0;
$hub = new MeterHub($id6); $hub->Create();
$msg = $hub->TestConnection();
check('Shelly (MQTT) ohne Brücke: Fehlertext des Clients statt „Host/Port/Unit-ID prüfen“', str_contains($msg, '❌') && !str_contains($msg, 'Unit-ID') && !str_contains($msg, 'unit ID'), $msg);
$id6++; $GLOBALS['PROP'][$id6]['Meter'] = 'siemens_pac2200'; $GLOBALS['PROP'][$id6]['Host'] = '';
$hub = new MeterHub($id6); $hub->Create();
$msg = $hub->TestConnection();
check('Modbus-Zähler ohne Host: allgemeiner Hinweis „Host/Port/Unit-ID prüfen“', str_contains($msg, '❌') && (str_contains($msg, 'Unit-ID') || str_contains($msg, 'unit ID')), $msg);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
