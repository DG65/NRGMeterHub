<?php
/**
 * Prüfstand für PowerInvert bei den Energiezählern (Fund 21.08.2026,
 * Dietmars Inexogy-Instanz): `PowerInvert` kehrte bisher nur das Vorzeichen
 * von `power_total` um. Die beiden Energiezähler (energy_import/
 * energy_export) sind aber getrennte, immer positive Zählerstände — bei
 * vertauschter Anschlussrichtung muss hier das ZIEL vertauscht werden
 * (Bezug↔Abgabe), nicht ein Vorzeichen. Bildet so viel IP-Symcon nach
 * (Objektbaum, Properties, Variablenwerte), dass die echte
 * MeterHub::SetVarEnergyWh()/SetVarEnergykWh()-Logik wirklich läuft —
 * nicht nur ein Treiber-Stub wie in test-inexogy.php.
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
function GetValue($id)          { return $GLOBALS['VAL'][$id] ?? 0; }
function SetValueFloat($id, $v) { $GLOBALS['VAL'][$id] = $v; }
function IPS_GetInstanceListByModuleID($guid) { return []; } // kein Archiv im Test

class IPSModule
{
    public $InstanceID;
    protected $defs = [];
    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v)  { $this->defs[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->defs[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? false); }
    protected function RegisterTimer($n, $i, $s) {}
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

require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
echo "1) Ohne PowerInvert: Werte landen unverändert auf ihrem eigenen Ident\n";
obj(100, 1, 'Zähler', 0);
$catE = obj(101, 0, 'Energie', 100, 'cat_energy');
vari(102, 'Bezug', $catE, 'energy_import', 0.0);
vari(103, 'Abgabe', $catE, 'energy_export', 0.0);

$hub = new MeterHub(100);
$hub->Create();
$hub->SetVarEnergykWh('energy_import', 10512.4169);
$hub->SetVarEnergykWh('energy_export', 4482.9536);
check('energy_import = 10512.4169', abs(GetValue(102) - 10512.4169) < 0.0001, 'ist ' . GetValue(102));
check('energy_export = 4482.9536',  abs(GetValue(103) - 4482.9536) < 0.0001, 'ist ' . GetValue(103));

// ---------------------------------------------------------------------------
echo "\n2) Mit PowerInvert: Ziel vertauscht sich (Bezug↔Abgabe), kein Vorzeichenwechsel\n";
obj(200, 1, 'Zähler invertiert', 0);
$catE2 = obj(201, 0, 'Energie', 200, 'cat_energy');
vari(202, 'Bezug', $catE2, 'energy_import', 0.0);
vari(203, 'Abgabe', $catE2, 'energy_export', 0.0);
$GLOBALS['PROP'][200]['PowerInvert'] = true;

$hub2 = new MeterHub(200);
$hub2->Create();
// Treiber liest weiterhin unter 'energy_import' den vom Zähler als Bezug
// gemeldeten Rohwert -- der landet bei vertauschter Anschlussrichtung aber
// in Wahrheit in der Abgabe-Variable.
$hub2->SetVarEnergykWh('energy_import', 10512.4169);
$hub2->SetVarEnergykWh('energy_export', 4482.9536);
check('energy_import-Aufruf landet in energy_export', abs(GetValue(203) - 10512.4169) < 0.0001, 'ist ' . GetValue(203));
check('energy_export-Aufruf landet in energy_import', abs(GetValue(202) - 4482.9536) < 0.0001, 'ist ' . GetValue(202));
check('energy_import-Variable NICHT vom energy_import-Aufruf verändert', abs(GetValue(202) - 4482.9536) < 0.0001, 'ist ' . GetValue(202));

echo "\n3) power_total weiterhin per Vorzeichen invertiert (Regression)\n";
$catT = obj(210, 0, 'Summe', 200, 'cat_total');
vari(211, 'Wirkleistung', $catT, 'power_total', 0.0);
$hub2->SetVarFloat('power_total', -5216.0);
check('power_total = 5216 (Vorzeichen gedreht)', abs(GetValue(211) - 5216.0) < 0.0001, 'ist ' . GetValue(211));

// ---------------------------------------------------------------------------
echo "\n4) Zähler ohne Gegenrichtung (z. B. Phoenix EEM): Wert bleibt liegen, kein Datenverlust\n";
obj(300, 1, 'Nur-Bezug-Zähler', 0);
$catE3 = obj(301, 0, 'Energie', 300, 'cat_energy');
vari(302, 'Bezug', $catE3, 'energy_import', 0.0); // KEIN energy_export im Baum
$GLOBALS['PROP'][300]['PowerInvert'] = true;

$hub3 = new MeterHub(300);
$hub3->Create();
$hub3->SetVarEnergykWh('energy_import', 777.0);
check('ohne Gegenrichtung bleibt der Wert auf energy_import', abs(GetValue(302) - 777.0) < 0.0001, 'ist ' . GetValue(302));

// ---------------------------------------------------------------------------
echo "\n5) Tarif-/Phasen-Varianten werden paarweise vertauscht (z. B. Shelly je Phase)\n";
obj(400, 1, 'Phasenzähler', 0);
$catE4 = obj(401, 0, 'Energie', 400, 'cat_energy');
vari(402, 'Bezug L1', $catE4, 'energy_import_l1', 0.0);
vari(403, 'Abgabe L1', $catE4, 'energy_export_l1', 0.0);
$GLOBALS['PROP'][400]['PowerInvert'] = true;

$hub4 = new MeterHub(400);
$hub4->Create();
$hub4->SetVarEnergykWh('energy_import_l1', 42.0);
check('energy_import_l1-Aufruf landet in energy_export_l1', abs(GetValue(403) - 42.0) < 0.0001, 'ist ' . GetValue(403));
check('energy_import_l1-Variable unverändert (0)', GetValue(402) === 0.0, 'ist ' . GetValue(402));

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
