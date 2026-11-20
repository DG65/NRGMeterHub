<?php
putenv('I18N_LANG=en');   // die Prüfungen lesen die englischen Quelltexte; Block 8 prüft die deutsche Oberfläche
require_once __DIR__ . '/i18n-test-support.php';
/**
 * Prüfstand Schätzzähler (MeterHubEstimate): Modul mit nachgebildetem Symcon, Datenbank-Anbindung gegen einen lokalen
 * Testserver (php -S) mit nachgebauter Powercalc-Struktur (library.json, model.json, csv/csv.gz).
 *
 *   php .tools/test-estimate.php    # 0 = alle Prüfungen bestanden
 */
const VARIABLETYPE_BOOLEAN = 0; const VARIABLETYPE_INTEGER = 1; const VARIABLETYPE_FLOAT = 2; const VARIABLETYPE_STRING = 3;
const KR_READY = 10103; const IPS_KERNELMESSAGE = 10100; const VM_UPDATE = 10603;
const VARIABLE_PRESENTATION_LEGACY = '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}';

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : ($detail !== '' ? "  — $detail" : '')) . "\n";
    if (!$ok) { $fails++; }
}
function near($a, $b, $eps = 1e-6): bool { return abs($a - $b) <= $eps * max(1.0, abs($b)); }

// --- nachgebautes Symcon -----------------------------------------------------------------------------------------------
$GLOBALS['OBJ'] = []; $GLOBALS['VAR'] = []; $GLOBALS['VAL'] = []; $GLOBALS['PROP'] = []; $GLOBALS['ATTR'] = [];
$GLOBALS['NEXTID'] = 9000; $GLOBALS['FORMFIELDS'] = []; $GLOBALS['TIMER'] = []; $GLOBALS['STATUS'] = []; $GLOBALS['MSGS'] = [];
$GLOBALS['ARCHIVED'] = []; $GLOBALS['AGG'] = []; $GLOBALS['REAGG'] = []; $GLOBALS['PROFILES'] = []; $GLOBALS['BUF'] = []; $GLOBALS['INSTMOD'] = [];
$GLOBALS['RUNLEVEL'] = KR_READY;
function IPS_GetKernelRunlevel() { return $GLOBALS['RUNLEVEL']; }
function IPS_InstanceExists($id) { return isset($GLOBALS['INSTMOD'][$id]); }
function IPS_GetName($id) { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ('Objekt ' . $id); }
function IPS_SetName($id, $n) { $GLOBALS['OBJ'][$id]['ObjectName'] = $n; return true; }
function IPS_SetPosition($id, $p) { $GLOBALS['OBJ'][$id]['ObjectPosition'] = $p; return true; }
function IPS_SetIdent($id, $i) { $GLOBALS['OBJ'][$id]['ObjectIdent'] = $i; return true; }
function IPS_SetParent($id, $p) { $GLOBALS['OBJ'][$id]['ParentID'] = $p; return true; }
function IPS_GetObject($id) { return $GLOBALS['OBJ'][$id]; }
function IPS_GetObjectIDByIdent($ident, $parent) {
    foreach ($GLOBALS['OBJ'] as $id => $o) { if ($o['ParentID'] === $parent && $o['ObjectIdent'] === $ident) { return $id; } }
    return false;
}
function IPS_CreateVariable($type) {
    $id = $GLOBALS['NEXTID']++;
    $GLOBALS['OBJ'][$id] = ['ObjectType' => 2, 'ObjectIdent' => '', 'ObjectName' => 'Var', 'ParentID' => 0, 'ObjectPosition' => 0];
    $GLOBALS['VAR'][$id] = ['VariableType' => $type, 'VariableProfile' => '', 'VariableCustomProfile' => ''];
    $GLOBALS['VAL'][$id] = $type === VARIABLETYPE_BOOLEAN ? false : 0;
    return $id;
}
function IPS_DeleteVariable($id) { unset($GLOBALS['OBJ'][$id], $GLOBALS['VAR'][$id], $GLOBALS['VAL'][$id]); return true; }
function IPS_VariableExists($id) { return isset($GLOBALS['VAR'][$id]); }
function IPS_GetVariable($id) { return $GLOBALS['VAR'][$id]; }
function GetValue($id) { return $GLOBALS['VAL'][$id]; }
function IPS_VariableProfileExists($n) { return isset($GLOBALS['PROFILES'][$n]); }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['PROFILES'][$n] = ['MaxValue' => 0]; return true; }
function IPS_SetVariableProfileDigits($n, $d) { return true; }
function IPS_SetVariableProfileText($n, $a, $b) { return true; }
function IPS_GetVariableProfile($n) { return $GLOBALS['PROFILES'][$n]; }
function IPS_GetInstanceListByModuleID($g) { return array_keys(array_filter($GLOBALS['INSTMOD'], fn($m) => $m === $g)); }
function AC_GetLoggingStatus($a, $vid) { return isset($GLOBALS['ARCHIVED'][$vid]); }
function AC_SetLoggingStatus($a, $vid, $on) { if ($on) { $GLOBALS['ARCHIVED'][$vid] = true; } else { unset($GLOBALS['ARCHIVED'][$vid]); } return true; }
function AC_GetAggregationType($a, $vid) { return $GLOBALS['AGG'][$vid] ?? 0; }
function AC_SetAggregationType($a, $vid, $t) { $GLOBALS['AGG'][$vid] = $t; return true; }
function AC_ReAggregateVariable($a, $vid) { $GLOBALS['REAGG'][] = $vid; return true; }
const G_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

class IPSModule
{
    use I18nStub;
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {}
    public function Destroy() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v)  { $this->defs[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyFloat($n, $v)   { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? false); }
    public function ReadPropertyFloat($n)   { return (float)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0.0); }
    protected function RegisterTimer($n, $i, $s) { $GLOBALS['TIMER'][$this->InstanceID][$n] = $i; }
    protected function SetTimerInterval($n, $i) { $GLOBALS['TIMER'][$this->InstanceID][$n] = $i; }
    private function t_reg($type, $ident, $name, $pres, $pos) {
        $vid = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!$vid) {
            $vid = IPS_CreateVariable($type);
            IPS_SetIdent($vid, $ident); IPS_SetParent($vid, $this->InstanceID); IPS_SetName($vid, $name); IPS_SetPosition($vid, $pos);
        }
        $GLOBALS['VAR'][$vid]['VariablePresentation'] = $pres;
        return $vid;
    }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { return $this->t_reg(VARIABLETYPE_FLOAT, $i, $n, $p, $pos); }
    protected function SetValue($ident, $v) { $GLOBALS['VAL'][IPS_GetObjectIDByIdent($ident, $this->InstanceID)] = $v; }
    protected function SetStatus($s) { $GLOBALS['STATUS'][$this->InstanceID] = $s; }
    protected function RegisterMessage($senderID, $message) { $GLOBALS['MSGS'][$this->InstanceID][$senderID . ':' . $message] = true; }
    protected function UnregisterMessage($senderID, $message) { unset($GLOBALS['MSGS'][$this->InstanceID][$senderID . ':' . $message]); }
    protected function SendDebug($sender, $msg, $format) {}
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = (in_array($p, ['caption', 'label'], true) && is_string($v)) ? i18n_tr($v) : $v; }
    public function GetBuffer($k) { return $GLOBALS['BUF'][$this->InstanceID][$k] ?? ''; }
    public function SetBuffer($k, $v) { $GLOBALS['BUF'][$this->InstanceID][$k] = $v; }
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n) { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeFloat($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeFloat($n) { return (float)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0.0); }
    public function WriteAttributeFloat($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeBoolean($n) { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
}

require_once dirname(__DIR__) . '/MeterHubEstimate/module.php';

/** Prüf-Unterklasse mit fester Uhr. */
class TEstimate extends MeterHubEstimate
{
    public $t = 1000000.0;
    protected function Now(): float { return $this->t; }
    public function T() { return $this->Update(); }
    /** Zeit in Schritten wie der Zeitgeber (60 s) vorrücken und jeweils rechnen. */
    public function Adv(float $sec, float $step = 60.0): void
    {
        for ($left = $sec; $left > 0; $left -= $step) { $this->t += min($step, $left); $this->Update(); }
    }
}
function MHUBE_GetDismissState($iid) { return ($GLOBALS['MODOBJ'][$iid] ?? null)->GetDismissState(); }
function MHUBE_AdoptDismissState($iid, $what, $value) { return ($GLOBALS['MODOBJ'][$iid] ?? null)->AdoptDismissState($what, $value); }

const G_EST = '{D9F12FFD-BA63-4A91-B1DE-B1CFCB118B6D}';
function make(array $props = [], int $iid = 500): TEstimate
{
    $GLOBALS['INSTMOD'][$iid] = G_EST;
    $GLOBALS['OBJ'][$iid] = ['ObjectType' => 1, 'ObjectIdent' => '', 'ObjectName' => 'Schätzzähler', 'ParentID' => 0, 'ObjectPosition' => 0];
    $GLOBALS['PROP'][$iid] = $props;
    $m = new TEstimate($iid);
    $GLOBALS['MODOBJ'][$iid] = $m;
    $m->Create();
    $m->ApplyChanges();
    return $m;
}
function boolvar(bool $v): int { $id = IPS_CreateVariable(VARIABLETYPE_BOOLEAN); $GLOBALS['VAL'][$id] = $v; return $id; }
function numvar(float $v, int $type = VARIABLETYPE_FLOAT, string $profile = ''): int { $id = IPS_CreateVariable($type); $GLOBALS['VAL'][$id] = $v; $GLOBALS['VAR'][$id]['VariableProfile'] = $profile; return $id; }
function setv(int $id, $v): void { $GLOBALS['VAL'][$id] = $v; }
function outp(int $iid, string $ident): float { return (float)$GLOBALS['VAL'][IPS_GetObjectIDByIdent($ident, $iid)]; }
function elements(array $form): array
{
    $out = [];
    $walk = function ($n) use (&$walk, &$out) {
        foreach ($n as $k => $v) {
            if (is_array($v)) { $walk($v); }
        }
        if (isset($n['name'])) { $out[] = $n; }
    };
    $walk($form['elements']);
    return $out;
}

// --- Datenbank-Testserver ---------------------------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/mhube-test-' . getmypid();
mkdir($tmp . '/ikea/E1603', 0777, true); mkdir($tmp . '/ikea/LED1', 0777, true); mkdir($tmp . '/ikea/E2007', 0777, true);
mkdir($tmp . '/hue/SUB', 0777, true); mkdir($tmp . '/hue/BAD', 0777, true); mkdir($tmp . '/hue/EMPTY', 0777, true);
mkdir($tmp . '/my brand/M 1', 0777, true);
file_put_contents($tmp . '/library.json', json_encode(['manufacturers' => [
    ['name' => 'ikea', 'full_name' => 'IKEA', 'dir_name' => 'ikea', 'aliases' => [], 'models' => [
        ['id' => 'E1603', 'name' => 'TRADFRI control outlet', 'calculation_strategy' => 'fixed', 'device_type' => 'smart_switch'],
        ['id' => 'LED1', 'name' => 'TRADFRI bulb', 'calculation_strategy' => 'lut', 'device_type' => 'light', 'color_modes' => ['color_temp', 'hs']],
        ['id' => 'E2007', 'name' => 'Fan', 'calculation_strategy' => 'linear', 'device_type' => 'fan'],
    ]],
    ['name' => 'hue', 'full_name' => 'Hue', 'dir_name' => 'hue', 'aliases' => [], 'models' => [
        ['id' => 'SUB', 'name' => 'Hue Bulb', 'calculation_strategy' => 'lut', 'sub_profile_count' => 2, 'color_modes' => ['hs']],
        ['id' => 'BAD', 'name' => 'Hue Strip', 'calculation_strategy' => 'lut', 'color_modes' => ['brightness']],
        ['id' => 'EMPTY', 'name' => 'Hue Empty', 'calculation_strategy' => 'lut', 'color_modes' => ['brightness']],
    ]],
    ['name' => 'my brand', 'full_name' => 'My Brand', 'dir_name' => 'my brand', 'aliases' => [], 'models' => [
        ['id' => 'M 1', 'name' => 'Plug with space', 'calculation_strategy' => 'fixed', 'device_type' => 'smart_switch'],
    ]],
]]));
file_put_contents($tmp . '/ikea/E1603/model.json', json_encode(['calculation_strategy' => 'fixed', 'name' => 'TRADFRI control outlet', 'standby_power' => 0.23, 'standby_power_on' => 0.98, 'authors' => [['name' => 'Marius', 'github' => 'x']]]));
file_put_contents($tmp . '/ikea/E2007/model.json', json_encode(['calculation_strategy' => 'linear', 'name' => 'Fan', 'device_type' => 'fan', 'standby_power' => 0.2, 'linear_config' => ['calibrate' => ['0 -> 1', '100 -> 21']]]));
file_put_contents($tmp . '/ikea/LED1/model.json', json_encode(['calculation_strategy' => 'lut', 'name' => 'TRADFRI bulb', 'device_type' => 'light', 'standby_power' => 0.4]));
copy(__DIR__ . '/fixtures/powercalc/color_temp.csv', $tmp . '/ikea/LED1/color_temp.csv');                    // Klartext
file_put_contents($tmp . '/ikea/LED1/hs.csv.gz', gzencode((string)file_get_contents(__DIR__ . '/fixtures/powercalc/hs.csv')));   // gzip
file_put_contents($tmp . '/hue/SUB/model.json', json_encode(['calculation_strategy' => 'lut', 'name' => 'Hue Bulb', 'sub_profile_count' => 2]));
file_put_contents($tmp . '/hue/EMPTY/model.json', json_encode(['calculation_strategy' => 'lut', 'name' => 'Hue Empty']));
file_put_contents($tmp . '/hue/EMPTY/brightness.csv', "bri,watt\n");   // nur die Kopfzeile
file_put_contents($tmp . '/my brand/M 1/model.json', json_encode(['calculation_strategy' => 'fixed', 'name' => 'Plug with space', 'fixed_config' => ['power' => 2.5]]));
file_put_contents($tmp . '/big.bin', str_repeat('x', 5000));
file_put_contents($tmp . '/hue/BAD/model.json', json_encode(['calculation_strategy' => 'lut', 'name' => 'Hue Strip']));   // keine CSV-Dateien
$port = 18000 + (getmypid() % 1000);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $tmp], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($srv, $tmp) { proc_terminate($srv); exec('rm -rf ' . escapeshellarg($tmp)); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
MHUBE_Http::$rawBase = "http://127.0.0.1:$port/";

// ===========================================================================
echo "1: feste Leistung, Zähler\n";
$sw = boolvar(true);
$m = make(['Mode' => 'fixed', 'PowerOn' => 10.0, 'StandbyPower' => 0.5, 'SwitchID' => $sw, 'Function' => 'light']);
check('1a: Status 102 (schätzt)', ($GLOBALS['STATUS'][500] ?? 0) === 102, (string)($GLOBALS['STATUS'][500] ?? 'null'));
check('1b: zwei Ausgabevariablen mit den Idents power / energy_import', IPS_GetObjectIDByIdent('power', 500) && IPS_GetObjectIDByIdent('energy_import', 500));
check('1c: Leistung an = 10 W', near(outp(500, 'power'), 10.0), (string)outp(500, 'power'));
check('1d: Zähler startet bei 0', outp(500, 'energy_import') === 0.0);
$m->Adv(3600);
check('1e: nach 1 h bei 10 W = 0,010 kWh', near(outp(500, 'energy_import'), 0.010), (string)outp(500, 'energy_import'));
$m->Adv(1800); $m->t += 10; setv($sw, false); $m->T();     // Schaltvorgang 10 s nach dem letzten Takt: die bisherigen 10 W zählen noch bis jetzt
$e1f = 0.010 + 0.005 + 10 * 10 / 3.6e6;
check('1f: Schaltvorgang: bisherige Leistung (10 W, nicht die neue) zählt bis zum Schaltpunkt', near(outp(500, 'energy_import'), $e1f, 1e-7), outp(500, 'energy_import') . ' ≠ ' . $e1f);
check('1g: aus = Standby 0,5 W', near(outp(500, 'power'), 0.5));
$m->Adv(3600);
check('1h: Standby zählt mit (+0,0005 kWh)', near(outp(500, 'energy_import'), $e1f + 0.0005, 1e-7), (string)outp(500, 'energy_import'));
$m->t += 100000; $m->T();
check('1i: lange Lücke (Symcon war aus) wird nicht überbrückt', near(outp(500, 'energy_import'), $e1f + 0.0005, 1e-7), (string)outp(500, 'energy_import'));
$m->t += 299; $m->T();
check('1i2: Lücke knapp unter der Grenze (299 s) wird dagegen mitgezählt', outp(500, 'energy_import') > $e1f + 0.0005);
$m->t += 60; $m->T();
check('1j: danach zählt er wieder', outp(500, 'energy_import') > 0.0155);
$GLOBALS['PROP'][500]['Active'] = false; $m->ApplyChanges();
check('1k: deaktiviert → Status 104, Zeitgeber aus', $GLOBALS['STATUS'][500] === 104 && $GLOBALS['TIMER'][500]['Update'] === 0);
$GLOBALS['PROP'][500]['Active'] = true; $m->ApplyChanges();
check('1l: wieder aktiv → Status 102, Zeitgeber 60 s', $GLOBALS['STATUS'][500] === 102 && $GLOBALS['TIMER'][500]['Update'] === 60000);
$GLOBALS['PROP'][500]['Interval'] = 3; $m->ApplyChanges();
check('1m: Intervall wird auf mindestens 10 s begrenzt', $GLOBALS['TIMER'][500]['Update'] === 10000);

echo "2: ohne Schalter, linear, Helligkeit\n";
$bri = numvar(100.0, VARIABLETYPE_INTEGER);
$m = make(['Mode' => 'linear', 'PowerMin' => 1.0, 'PowerMax' => 11.0, 'BrightnessID' => $bri, 'BrightnessScale' => '100'], 501);
check('2a: Helligkeit 100 → Maximum', near(outp(501, 'power'), 11.0));
setv($bri, 50); $m->T();
check('2b: 50 % → 6 W', near(outp(501, 'power'), 6.0));
setv($bri, 0); $m->T();
check('2c: Helligkeit 0 ohne Schalter = aus (Standby 0)', near(outp(501, 'power'), 0.0));
$GLOBALS['PROP'][501]['BrightnessScale'] = '255'; setv($bri, 255); $m->T();
check('2d: Skala 255', near(outp(501, 'power'), 11.0));
$GLOBALS['PROP'][501]['BrightnessScale'] = 'auto'; setv($bri, 128); $m->T();
check('2e: auto ohne Profil, Wert > 100 → 255 angenommen (50 %)', near(outp(501, 'power'), 6.0, 1e-2), (string)outp(501, 'power'));
$GLOBALS['PROFILES']['Test.Max255'] = ['MaxValue' => 255]; $GLOBALS['VAR'][$bri]['VariableProfile'] = 'Test.Max255'; setv($bri, 51); $m->T();
check('2f: auto liest die Obergrenze aus dem Variablenprofil (51/255 = 20 %)', near(outp(501, 'power'), 3.0, 1e-3), (string)outp(501, 'power'));
$GLOBALS['PROP'][501]['PowerMax'] = 0.5; $m->ApplyChanges();
check('2g: Maximum unter Minimum → Status 201', $GLOBALS['STATUS'][501] === 201);
$GLOBALS['PROP'][501]['PowerMax'] = 11.0; $GLOBALS['PROP'][501]['Gamma'] = 2.0; $GLOBALS['VAR'][$bri]['VariableProfile'] = ''; $GLOBALS['PROP'][501]['BrightnessScale'] = '100'; $m->ApplyChanges(); setv($bri, 50); $m->T();
check('2h: Gamma 2 bei 50 % → 1 + 10 × 0,25', near(outp(501, 'power'), 3.5), (string)outp(501, 'power'));
$fb = numvar(0.5, VARIABLETYPE_FLOAT);
$GLOBALS['PROP'][501]['BrightnessID'] = $fb; $GLOBALS['PROP'][501]['BrightnessScale'] = 'auto'; $GLOBALS['PROP'][501]['Gamma'] = 0.0; $m->ApplyChanges();
check('2i: auto, Gleitkomma 0–1 ohne Profil → 0,5 = 50 %', near(outp(501, 'power'), 6.0), (string)outp(501, 'power'));

setv($bri, 150); $GLOBALS['PROP'][501]['BrightnessID'] = $bri; $GLOBALS['PROP'][501]['BrightnessScale'] = '100'; $GLOBALS['PROP'][501]['PowerMax'] = 11.0; $GLOBALS['PROP'][501]['Gamma'] = 0.0; $m->ApplyChanges();
check('2j: Wert über der Obergrenze der Skala wird auf 100 % begrenzt', near(outp(501, 'power'), 11.0), (string)outp(501, 'power'));
$GLOBALS['PROP'][501]['PowerMax'] = 0.5; $m->ApplyChanges();
$pBefore = outp(501, 'power'); $msg = $m->Update();
check('2k: ungültige Linear-Angaben: kein Rechenschritt, Hinweis statt Zahl', str_contains($msg, 'Nothing to calculate') && near(outp(501, 'power'), $pBefore));

echo "3: Beobachtung der Quellen\n";
$sw3 = boolvar(false); $b3 = numvar(10.0, VARIABLETYPE_INTEGER);
$m = make(['Mode' => 'fixed', 'PowerOn' => 5.0, 'SwitchID' => $sw3, 'BrightnessID' => $b3], 502);
check('3a: Schalter und Helligkeit werden beobachtet', isset($GLOBALS['MSGS'][502]["$sw3:" . VM_UPDATE]) && isset($GLOBALS['MSGS'][502]["$b3:" . VM_UPDATE]));
$GLOBALS['PROP'][502]['BrightnessID'] = 0; $m->ApplyChanges();
check('3b: nicht mehr gewählte Quelle wird gelöst', !isset($GLOBALS['MSGS'][502]["$b3:" . VM_UPDATE]) && isset($GLOBALS['MSGS'][502]["$sw3:" . VM_UPDATE]));
setv($sw3, true); $m->t += 10; $m->MessageSink(0, $sw3, VM_UPDATE, [true, true, false]);
check('3c: Meldung einer Quelle rechnet sofort neu', near(outp(502, 'power'), 5.0));
$GLOBALS['PROP'][502]['Active'] = false; setv($sw3, false); $m->t += 10; $m->MessageSink(0, $sw3, VM_UPDATE, [false, true, true]);
check('3d: deaktiviert → Meldung wird ignoriert', near(outp(502, 'power'), 5.0));
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
check('3e: Kernel-Start wendet neu an (Status 104 bei deaktiviert)', $GLOBALS['STATUS'][502] === 104);

echo "4: Datenbank (lokaler Testserver)\n";
$sw4 = boolvar(true); $b4 = numvar(100.0, VARIABLETYPE_INTEGER); $ct4 = numvar(2700.0, VARIABLETYPE_INTEGER);
$m = make(['Mode' => 'database', 'SwitchID' => $sw4, 'BrightnessID' => $b4, 'BrightnessScale' => '100', 'ColorTempID' => $ct4], 503);
check('4a: Datenbank-Modus ohne Modell → Status 201', $GLOBALS['STATUS'][503] === 201);
check('4b: Suche mit zu kurzem Text wird abgewiesen', str_contains($m->SearchModels('x'), 'two characters'));
$r = $m->SearchModels('tradfri');
check('4c: Suche findet beide IKEA-Modelle', str_contains($r, '2 usable'), $r);
$opts = json_decode($GLOBALS['FORMFIELDS']['SearchResult']['options'], true);
check('4d: Auswahlliste enthält Verzeichnis|ID', ($opts[0]['value'] ?? '') === 'ikea|E1603' && ($opts[1]['value'] ?? '') === 'ikea|LED1', json_encode($opts));
$r = $m->SearchModels('hue');
check('4e: Modell mit Unterprofilen wird gezählt, aber nicht angeboten', str_contains($r, '2 usable') && str_contains($r, '1 more not shown') && !str_contains(json_encode($GLOBALS['FORMFIELDS']['SearchResult']['options']), 'hue|SUB'), $r);
check('4f: ohne Treffer: freundlicher Hinweis', str_contains($m->SearchModels('gibtesnicht'), 'No match'));
check('4g: Laden ohne Auswahl', str_contains($m->LoadModel(''), 'search first'));
check('4h: unbekanntes Modell wird nicht geladen (keine Adresse aus Formulareingabe)', str_contains($m->LoadModel('../../etc|passwd'), 'not in the list'));
check('4i: Modell mit Unterprofilen wird abgewiesen', str_contains($m->LoadModel('hue|SUB'), 'not in the list'));
check('4j: Messtabellen-Modell ohne Dateien → Fehlermeldung', str_contains($m->LoadModel('hue|BAD'), 'measurement tables'));
check('4k: dabei bleibt kein halbes Profil zurück', $GLOBALS['ATTR'][503]['Profile'] ?? '' === '');
$r = $m->LoadModel('ikea|LED1');
check('4l: Lampe geladen', str_contains($r, 'Model loaded'), $r);
$prof = json_decode($GLOBALS['ATTR'][503]['Profile'], true);
check('4m: beide Tabellen (Klartext und gzip) abgelegt', isset($prof['luts']['color_temp'], $prof['luts']['hs']) && !isset($prof['luts']['brightness']));
check('4n: Formular springt auf „Datenbank“, Statuszeile gesetzt', ($GLOBALS['FORMFIELDS']['Mode']['value'] ?? '') === 'database' && str_contains($GLOBALS['FORMFIELDS']['ModelStatus']['caption'] ?? '', 'TRADFRI bulb'));
$m->ApplyChanges();
check('4o: mit Modell → Status 102', $GLOBALS['STATUS'][503] === 102);
$ct = MHUBE_Engine::ParseLut((string)file_get_contents(__DIR__ . '/fixtures/powercalc/color_temp.csv'), 'color_temp');
$want = MHUBE_Engine::LutPower($ct, 'color_temp', 255, MHUBE_Engine::KelvinToMired(2700));
check('4p: Leistung aus der Messtabelle (Helligkeit 100 %, 2700 K)', near(outp(503, 'power'), round($want, 3)), outp(503, 'power') . ' ≠ ' . $want);
setv($b4, 40); $m->T();
check('4q: Helligkeit 40 % → Tabellenwert bei 102/255', near(outp(503, 'power'), round(MHUBE_Engine::LutPower($ct, 'color_temp', (int)round(40 * 2.55), MHUBE_Engine::KelvinToMired(2700)), 3)));
setv($ct4, 370.0); $m->T();
check('4r: Farbtemperatur in Mired (370 < 1000 → automatisch erkannt) ergibt dieselbe Leistung wie 2703 K', near(outp(503, 'power'), round(MHUBE_Engine::LutPower($ct, 'color_temp', 102, 370), 3)));
setv($sw4, false); $m->T();
check('4s: aus = Standby des Modells (0,4 W)', near(outp(503, 'power'), 0.4));
$r = $m->LoadModel('ikea|E1603');
check('4t: Zwischenstecker geladen, Autor wird genannt', str_contains($r, 'Marius'), $r);
setv($sw4, true); $m->T();
check('4u: Stecker an = Eigenverbrauch 0,98 W', near(outp(503, 'power'), 0.98), (string)outp(503, 'power'));
$r = $m->LoadModel('ikea|E2007'); setv($b4, 50); $m->T();
check('4v: lineares Modell: 50 % von 1…21 W = 11 W (Skala 100 bei Nicht-Licht)', near(outp(503, 'power'), 11.0), $r . ' / ' . outp(503, 'power'));
check('4w: Modell entfernen', str_contains($m->ClearModel(), 'removed') && $GLOBALS['ATTR'][503]['Profile'] === '');
$m->ApplyChanges();
check('4x: ohne Modell wieder Status 201', $GLOBALS['STATUS'][503] === 201);
MHUBE_Http::$rawBase = 'http://127.0.0.1:1/';
$GLOBALS['BUF'] = [];
check('4y: Datenbank nicht erreichbar → verständliche Meldung statt Fehler', str_contains($m->SearchModels('ikea'), 'could not be loaded'));
MHUBE_Http::$rawBase = "http://127.0.0.1:$port/";
$GLOBALS['BUF'] = [];
$tmpSave = file_get_contents($tmp . '/library.json'); file_put_contents($tmp . '/library.json', 'kein json');
check('4z: kaputte Listendatei → Meldung', str_contains($m->SearchModels('ikea'), 'could not be loaded'));
file_put_contents($tmp . '/library.json', $tmpSave);

$GLOBALS['BUF'] = [];
check('4aa: Hersteller/Modell mit Leerzeichen werden kodiert geladen', str_contains($m->LoadModel('my brand|M 1'), 'Model loaded'));
setv($sw4, true); $m->T();
check('4ab: und rechnen (2,5 W)', near(outp(503, 'power'), 2.5), (string)outp(503, 'power'));
check('4ac: Tabelle nur aus Kopfzeile gilt nicht als geladen', str_contains($m->LoadModel('hue|EMPTY'), 'measurement tables'));
$g = MHUBE_Http::Get(MHUBE_Http::$rawBase . 'big.bin', 10, 1000);
check('4ad: Antwort über der Größenbegrenzung wird abgebrochen', $g['body'] === '' && $g['error'] === 'response too large', json_encode($g));
$g = MHUBE_Http::Get(MHUBE_Http::$rawBase . 'big.bin', 10, 100000);
check('4ae: Antwort unter der Grenze kommt vollständig', strlen($g['body']) === 5000 && $g['code'] === 200);
check('4af: nicht vorhandene Datei: Statuscode 404', MHUBE_Http::Get(MHUBE_Http::$rawBase . 'gibt-es-nicht.json')['code'] === 404);
// Index-Alter: die Liste wird höchstens einen Tag lang gemerkt
$GLOBALS['BUF'] = [];
$m->SearchModels('ikea');
$lib = json_decode(file_get_contents($tmp . '/library.json'), true);
$lib['manufacturers'][0]['models'][] = ['id' => 'NEU1', 'name' => 'Neues Modell', 'calculation_strategy' => 'fixed'];
file_put_contents($tmp . '/library.json', json_encode($lib));
$m->SearchModels('neues');
check('4ag: frische Liste wird gemerkt (Neuzugang noch nicht sichtbar)', str_contains(json_encode($GLOBALS['FORMFIELDS']['SearchResult']['options']), 'kein brauchbarer Treffer') || str_contains(json_encode($GLOBALS['FORMFIELDS']['SearchResult']['options']), 'no usable'));
$GLOBALS['BUF'][503]['IndexTs'] = (string)(time() - 90000);
$m->SearchModels('neues');
check('4ah: nach einem Tag wird die Liste neu geholt', str_contains(json_encode($GLOBALS['FORMFIELDS']['SearchResult']['options']), 'ikea|NEU1'));
// fehlende Datei → Statusmeldung im Fehlertext
$GLOBALS['BUF'] = [];
rename($tmp . '/library.json', $tmp . '/library.off');
check('4ai: fehlende Listendatei meldet den Statuscode', str_contains($m->SearchModels('ikea'), 'HTTP 404'), $m->SearchModels('ikea'));
rename($tmp . '/library.off', $tmp . '/library.json');
// Profil mit Messtabellen-Rechenart, aber ohne Tabellen → unvollständig
$GLOBALS['PROP'][503]['Mode'] = 'database';
$m->WriteAttributeString('Profile', json_encode(['profile' => ['strategy' => 'lut', 'name' => 'X', 'standby' => 0.1], 'luts' => []]));
$m->ApplyChanges();
check('4aj: Messtabellen-Profil ohne Tabellen → Status 201', $GLOBALS['STATUS'][503] === 201);
$m->WriteAttributeString('Profile', 'kein json');
$m->ApplyChanges();
check('4ak: beschädigtes Profil → Status 201 statt Fehler', $GLOBALS['STATUS'][503] === 201);

echo "5: Lampe mit Farbe\n";
$GLOBALS['BUF'] = [];
$sw5 = boolvar(true); $b5 = numvar(100.0, VARIABLETYPE_INTEGER); $c5 = IPS_CreateVariable(VARIABLETYPE_INTEGER); $GLOBALS['VAL'][$c5] = 0xFF8000;
$m = make(['Mode' => 'database', 'SwitchID' => $sw5, 'BrightnessID' => $b5, 'BrightnessScale' => '100', 'ColorID' => $c5], 504);
$m->LoadModel('ikea|LED1'); $m->ApplyChanges();
$hs = MHUBE_Engine::ParseLut((string)file_get_contents(__DIR__ . '/fixtures/powercalc/hs.csv'), 'hs');
[$h, $s] = MHUBE_Engine::ColorIntToHsv(0xFF8000);
$want = MHUBE_Engine::LutPower($hs, 'hs', 255, 0, (int)($h / 360 * 65535), (int)($s / 100 * 255));
check('5a: nur Farbe gewählt → Farb-Tabelle', near(outp(504, 'power'), round($want, 3)), outp(504, 'power') . ' ≠ ' . $want);
$GLOBALS['PROP'][504]['LightMode'] = 'color_temp'; $m->ApplyChanges();
check('5b: erzwungene Weißlicht-Tabelle ohne Farbtemperatur → warmweiß 2700 K angenommen', near(outp(504, 'power'), round(MHUBE_Engine::LutPower($ct, 'color_temp', 255, 370), 3)));

echo "6: Vertrag und Formular\n";
$m = make(['Mode' => 'fixed', 'PowerOn' => 3.0, 'Function' => 'light', 'Interval' => 30], 505);
$f = json_decode($m->GetFunctions(), true);
$a = $f['assignments'][0] ?? [];
check('6a2: contractType = meter (Instanz und Zuordnung), `meter` bleibt estimate', ($f['contractType'] ?? '') === 'meter' && ($a['contractType'] ?? '') === 'meter' && $f['meter'] === 'estimate');
check('6a: Vertrag: Version, Zählerart, geschätzt', $f['contractVersion'] === '1.6' && $f['meter'] === 'estimate' && $a['measured'] === false && $a['energyMeasured'] === false && $f['energyMeasured'] === false);
check('6b: Vertrag: Leistungs- und Energie-ID zeigen auf die Ausgaben', $a['powerID'] === IPS_GetObjectIDByIdent('power', 505) && $a['energyImportID'] === IPS_GetObjectIDByIdent('energy_import', 505) && $a['energyExportID'] === 0);
check('6c: Vertrag: Funktion, Güte, Takt', $a['function'] === 'light' && $a['energyKind'] === 'counter' && $a['pollInterval'] === 30 && $a['latency'] === 'realtime' && $a['authority'] === 'auxiliary');
$m2 = make(['Function' => 'none'], 506);
check('6d: ohne Funktion keine Zuordnung', json_decode($m2->GetFunctions(), true)['assignments'] === []);
$m3 = make(['Function' => 'bogus'], 507);
check('6e: unbekannte Funktion keine Zuordnung', json_decode($m3->GetFunctions(), true)['assignments'] === []);
$GLOBALS['RUNLEVEL'] = 0;
check('6f: beim Neuladen: ready=false statt Warnungen', json_decode($m->GetFunctions(), true)['ready'] === false);
$GLOBALS['RUNLEVEL'] = KR_READY;
$form = json_decode(cf($m), true);
$names = array_column(elements($form), 'name');
check('6g: Formularnamen sind eindeutig', count($names) === count(array_unique($names)), implode(',', array_diff_assoc($names, array_unique($names))));
$missing = array_diff(['Active', 'Function', 'Location', 'Mode', 'SwitchID', 'BrightnessID', 'BrightnessScale', 'ColorTempID', 'ColorTempUnit', 'ColorID', 'LightMode', 'PowerOn', 'StandbyPower', 'PowerMin', 'PowerMax', 'Gamma', 'Interval', 'SearchText', 'SearchResult', 'FormLayout'], $names);
check('6h: jede Einstellung steht im Formular', !$missing, implode(',', $missing));
$el = array_column(elements($form), null, 'name');
check('6i: Betriebsart „fest“: nur der feste Bereich sichtbar', $el['ModelFixed']['visible'] === true && $el['ModelLinear']['visible'] === false && $el['ModelDatabase']['visible'] === false && $el['SourceColor']['visible'] === false && $el['SourceBrightness']['visible'] === false);
$m->OnChangeMode('database');
check('6j: Wechsel auf „Datenbank“ blendet um', $GLOBALS['FORMFIELDS']['ModelDatabase']['visible'] === true && $GLOBALS['FORMFIELDS']['ModelFixed']['visible'] === false && $GLOBALS['FORMFIELDS']['SourceColor']['visible'] === true && $GLOBALS['FORMFIELDS']['StandbyPower']['visible'] === false);
check('6k: Powercalc-Hinweis (Quelle, MIT) im Formular', str_contains(cf($m), 'MIT license') || str_contains(cf($m), 'MIT-Lizenz'));
$GLOBALS['INSTMOD'][900] = G_ARCHIVE;
$json = cf($m);
check('6l: Hinweis „Archivierung fehlt“ erscheint, bis geklickt wird', str_contains($json, 'PanelLogging'));
check('6m: die Instanz schaltet das Logging nie von selbst ein', $GLOBALS['ARCHIVED'] === []);
$before = $GLOBALS['ARCHIVED'];
$m->ApplyChanges();
check('6n: ApplyChanges lässt das Logging aus', array_keys($GLOBALS['ARCHIVED']) === array_keys($before) && !isset($GLOBALS['ARCHIVED'][IPS_GetObjectIDByIdent('energy_import', 505)]));
check('6o: Klick auf „Archivierung aktivieren“ loggt beide Variablen, Energie als Zähler', str_contains($m->EnableArchiving(), '2')
    && isset($GLOBALS['ARCHIVED'][IPS_GetObjectIDByIdent('power', 505)], $GLOBALS['ARCHIVED'][IPS_GetObjectIDByIdent('energy_import', 505)])
    && $GLOBALS['AGG'][IPS_GetObjectIDByIdent('energy_import', 505)] === 1 && ($GLOBALS['AGG'][IPS_GetObjectIDByIdent('power', 505)] ?? 0) === 0);
check('6p: Zähler-Typwechsel stößt die Neuaggregation an', in_array(IPS_GetObjectIDByIdent('energy_import', 505), $GLOBALS['REAGG'], true));
$m->ApplyChanges();
check('6q: danach kein Archivierungs-Hinweis mehr', !str_contains(cf($m), 'PanelLogging'));
$m->RenameInstance('  Esszimmer Lampe ');
check('6r: Umbenennen', IPS_GetName(505) === 'Esszimmer Lampe');
$m->AckPurposeIntro();
check('6s: Zweck-Hinweis weg, Zustand lesbar für Geschwister', $m->GetDismissState()['purposeIntroGone'] === true);

echo "7: Schreibweise der Ausgaben\n";
$o = IPS_GetObjectIDByIdent('power', 505);
check('7a: Profil als Standarddarstellung, kein benutzerdefiniertes Profil', ($GLOBALS['VAR'][$o]['VariablePresentation']['PROFILE'] ?? '') === 'NRG.Watt' && ($GLOBALS['VAR'][$o]['VariableCustomProfile'] ?? '') === '');
check('7b: Energie im Profil NRG.kWh', ($GLOBALS['VAR'][IPS_GetObjectIDByIdent('energy_import', 505)]['VariablePresentation']['PROFILE'] ?? '') === 'NRG.kWh');
check('7c: nach dem Anlegen keine Unterkategorien unter der Instanz', count(array_filter($GLOBALS['OBJ'], fn($x) => $x['ParentID'] === 505 && $x['ObjectType'] !== 2)) === 0);

echo "8: deutsche Oberfläche\n";
putenv('I18N_LANG=de');
$m = make(['Mode' => 'fixed', 'PowerOn' => 3.0, 'Function' => 'light'], 508);
$json = cf($m);
check('8a: Formular auf Deutsch (Konsole übersetzt Beschriftungen)', str_contains($json, 'Leistungsmodell') && str_contains($json, 'Feste Leistung') && !str_contains($json, 'Power model'));
check('8b: Meldung auf Deutsch', str_contains($m->SearchModels('x'), 'mindestens zwei Zeichen'));
check('8c: Variablennamen auf Deutsch', IPS_GetName(IPS_GetObjectIDByIdent('power', 508)) === 'Leistung (geschätzt)');
check('8d: Vorschau mit deutschem Dezimalkomma', str_contains($m->Update(), '3,00 W'), $m->Update());
putenv('I18N_LANG=en');
check('8e: Vorschau englisch mit Punkt', str_contains($m->Update(), '3.00 W'), $m->Update());

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
