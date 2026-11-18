<?php
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand: Inexogy-Nachtrag bei gesetztem PowerInvert (0.38.6, Befund hbraun 17.11.2026).
 * Der Live-Pfad leitet das Bezugsregister bei gesetztem Haken in die andere Energie-Variable um und
 * dreht die Leistung. Der Nachtrag schrieb bisher ohne beides: Bezug und Abgabe landeten in der
 * jeweils falschen Variable, die Leistung mit umgekehrtem Vorzeichen. Geprüft wird, dass Live- und
 * Nachtragswert desselben Zählerregisters in DERSELBEN Variable landen.
 */
const VARIABLETYPE_FLOAT = 2;

$GLOBALS['PROP'] = [];
$GLOBALS['ATTR'] = [];
$GLOBALS['OBJ']  = [];      // id => ['ObjectType', 'ObjectIdent', 'ParentID'] -- fuer FindVarByIdent()
$GLOBALS['ARCHIVE_IDS'] = [];    // [] = kein Archiv-Modul installiert (Standardfall fuer die Intervall-Tests oben)
$GLOBALS['ARCHIVE_LATEST'] = []; // vid => letzter bekannter TimeStamp, fuer AC_GetLoggedValues(..., Limit=1)

function IPS_GetObject($id) { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetChildrenIDs($id) {
    $out = [];
    foreach ($GLOBALS['OBJ'] as $k => $o) { if ($o['ParentID'] == $id) { $out[] = $k; } }
    return $out;
}
// Bildet nur nach, was ComputeAutoBackfillRange() tatsächlich nutzt: den
// per Limit=1 abgefragten neuesten Datensatz je Variable (AC_GetLoggedValues
// ist laut SDK-Doku absteigend sortiert -- Limit=1 liefert also exakt den
// neuesten TimeStamp, kein Scan der Historie nötig).
$GLOBALS['NOT_LOGGED'] = [];     // vid => true: Variable ohne Logging (Symcon wirft dann eine Warnung)
function obj($id, $parent, $ident) {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => 2, 'ObjectIdent' => $ident, 'ParentID' => $parent];
    return $id;
}

class IPSModule
{
    use I18nStub;
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) { $this->InstanceID = $id; }
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
    protected function SetTimerInterval($n, $i) {}
    protected function SetStatus($s) {}
    protected function SetVisualizationType($t) {}
    protected function SendDebug($sender, $msg, $format) {}
    public function UpdateFormField($f, $p, $v) {}
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

foreach (['KL_MESSAGE' => 101, 'KL_WARNING' => 104, 'KL_ERROR' => 103, 'KL_NOTIFY' => 102, 'KL_DEBUG' => 100] as $c => $v) { if (!defined($c)) { define($c, $v); } }
$GLOBALS['ARCHIVE_IDS'] = [1];
$GLOBALS['LOGGED'] = [];   // vid => [ts => value]
$GLOBALS['VAL'] = [];
function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['ARCHIVE_IDS']; }
function IPS_GetName($id) { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ''; }
function AC_GetLoggingStatus($a, $vid) { return true; }
function AC_GetLoggedValues($a, $vid, $from, $to, $limit) {
    $rows = [];
    foreach ($GLOBALS['LOGGED'][$vid] ?? [] as $ts => $v) { if ($ts >= $from && $ts <= $to) { $rows[] = ['TimeStamp' => $ts, 'Value' => $v]; } }
    usort($rows, fn($x, $y) => $y['TimeStamp'] <=> $x['TimeStamp']);
    return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}
function AC_AddLoggedValues($a, $vid, $rows) { foreach ($rows as $r) { $GLOBALS['LOGGED'][$vid][$r['TimeStamp']] = $r['Value']; } return true; }
function AC_DeleteVariableData($a, $vid, $from, $to) { foreach (array_keys($GLOBALS['LOGGED'][$vid] ?? []) as $ts) { if ($ts >= $from && $ts <= $to) { unset($GLOBALS['LOGGED'][$vid][$ts]); } } return true; }
function AC_ReAggregateVariable($a, $vid) { return true; }
function GetValue($id) { return $GLOBALS['VAL'][$id] ?? 0.0; }
function SetValueFloat($id, $v) { $GLOBALS['VAL'][$id] = $v; }
function IPS_SetIdent($id, $i) { $GLOBALS['OBJ'][$id]['ObjectIdent'] = $i; return true; }
function IPS_SetName($id, $n) { $GLOBALS['OBJ'][$id]['ObjectName'] = $n; return true; }

require_once getenv('MHUB_MODULE') ?: dirname(__DIR__) . '/MeterHub/module.php';

class FakeInexogy
{
    public $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function getReadings($m, $f, $t, $r) { return $this->rows; }
    public function getLastHttpCode() { return 200; }
    public function getLastError() { return ''; }
}
class BackfillHub extends MeterHub
{
    public $fake;
    protected function GetTransport() { return $this->fake; }
}

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** Instanz mit Bezug-/Abgabe-/Leistungsvariable aufbauen; $invert wie im Formular gesetzt. */
function setup(int $id, bool $invert): array {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => 1, 'ObjectIdent' => '', 'ObjectName' => 'Hauptzähler', 'ParentID' => 0];
    $vi = $id + 1; $ve = $id + 2; $vp = $id + 3;
    obj($vi, $id, 'energy_import'); $GLOBALS['OBJ'][$vi]['ObjectName'] = 'Bezug';
    obj($ve, $id, 'energy_export'); $GLOBALS['OBJ'][$ve]['ObjectName'] = 'Abgabe';
    obj($vp, $id, 'power_total');   $GLOBALS['OBJ'][$vp]['ObjectName'] = 'Wirkleistung';
    $GLOBALS['PROP'][$id] = ['Meter' => 'inexogy', 'InexogyMeterID' => 'abc', 'PowerInvert' => $invert];
    $h = new BackfillHub($id); $h->Create();
    return [$h, $vi, $ve, $vp];
}
function readings(int $t0): array {
    // zwei Viertelstunden: Bezug 456,0 → 456,1 kWh, Abgabe 75,2 kWh, Leistung 600 W / 640 W
    $mk = fn($ts, $imp, $exp, $p) => ['time' => $ts * 1000, 'values' => ['energy' => (int)round($imp * 1e10), 'energyOut' => (int)round($exp * 1e10), 'power' => $p * 1000]];
    return [$mk($t0, 456.0, 75.2, 600), $mk($t0 + 900, 456.1, 75.2, 640)];
}
function run(BackfillHub $h, int $t0): string {
    $h->fake = new FakeInexogy(readings($t0));
    return (new ReflectionMethod('MeterHub', 'DoBackfillInexogyArchive'))->invoke($h, $t0 - 60, $t0 + 3600);
}

$t0 = intdiv(time() - 7200, 900) * 900;

echo "1) Haken aus: Bezug → Bezug-Variable, Abgabe → Abgabe-Variable, Leistung unverändert\n";
[$h, $vi, $ve, $vp] = setup(100, false);
run($h, $t0);
check('1a Bezug 456,0 steht in der Bezug-Variable', abs(($GLOBALS['LOGGED'][$vi][$t0] ?? -1) - 456.0) < 1e-6, json_encode($GLOBALS['LOGGED'][$vi] ?? null));
check('1b Abgabe 75,2 steht in der Abgabe-Variable', abs(($GLOBALS['LOGGED'][$ve][$t0] ?? -1) - 75.2) < 1e-6, json_encode($GLOBALS['LOGGED'][$ve] ?? null));
check('1c Leistung +600 W', abs(($GLOBALS['LOGGED'][$vp][$t0] ?? 0) - 600.0) < 1e-6, json_encode($GLOBALS['LOGGED'][$vp] ?? null));

echo "\n2) Haken an: gleiches Ziel und Vorzeichen wie der Live-Pfad\n";
[$h2, $vi2, $ve2, $vp2] = setup(200, true);
// Live-Pfad: Bezugsregister (energy_import) landet bei gesetztem Haken in der anderen Variable
$h2->SetVarEnergykWh('energy_import', 456.0);
$h2->SetVarFloat('power_total', 600.0);
$liveEnergyVar = $GLOBALS['VAL'][$ve2] ?? null;
check('2a Live: Bezugsregister landet in der Variable mit Ident energy_export', $liveEnergyVar !== null && abs($liveEnergyVar - 456.0) < 1e-6 && !isset($GLOBALS['VAL'][$vi2]), json_encode($GLOBALS['VAL']));
check('2b Live: Leistung negiert', abs(($GLOBALS['VAL'][$vp2] ?? 0) + 600.0) < 1e-6);
run($h2, $t0);
check('2c Nachtrag: Bezug 456,0 steht in DERSELBEN Variable wie der Live-Wert (energy_export)', abs(($GLOBALS['LOGGED'][$ve2][$t0] ?? -1) - 456.0) < 1e-6, json_encode($GLOBALS['LOGGED'][$ve2] ?? null));
check('2d Nachtrag: Abgabe 75,2 steht in der anderen Variable (energy_import)', abs(($GLOBALS['LOGGED'][$vi2][$t0] ?? -1) - 75.2) < 1e-6, json_encode($GLOBALS['LOGGED'][$vi2] ?? null));
check('2e Nachtrag: keine Bezugswerte in der Abgabe-Reihe (keine Mischreihe)', max(array_values($GLOBALS['LOGGED'][$vi2] ?? [0])) < 100);
check('2f Nachtrag: Leistung mit dem Vorzeichen des Live-Pfads (−600 W / −640 W)', abs(($GLOBALS['LOGGED'][$vp2][$t0] ?? 0) + 600.0) < 1e-6 && abs(($GLOBALS['LOGGED'][$vp2][$t0 + 900] ?? 0) + 640.0) < 1e-6, json_encode($GLOBALS['LOGGED'][$vp2] ?? null));

echo "\n3) Zweiter Lauf: keine Doppelungen\n";
$before = json_encode($GLOBALS['LOGGED']);
run($h2, $t0);
check('3a Wiederholung ändert das Archiv nicht', json_encode($GLOBALS['LOGGED']) === $before);

echo $fails === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fails Prüfung(en) fehlgeschlagen.\n";
exit($fails === 0 ? 0 : 1);
