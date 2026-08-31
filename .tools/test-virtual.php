<?php
/**
 * Prüfstand für die Brücke MeterHub → MeterHubVirtual.
 * Bildet so viel IP-Symcon nach, dass CreateVirtual() und ScanMeters() wirklich
 * laufen — ein Syntaxcheck hätte den letzten Laufzeitfehler hier nicht gefunden.
 */

const VARIABLETYPE_FLOAT = 2;

$GLOBALS['OBJ'] = [];      // id => ObjectType/ObjectIdent/ObjectName/ParentID
$GLOBALS['VAR'] = [];      // id => VariableType/Profile/Updated
$GLOBALS['VAL'] = [];
$GLOBALS['PROP'] = [];     // iid => name => wert
$GLOBALS['INSTMOD'] = [];  // iid => GUID
$GLOBALS['MODOBJ'] = [];   // iid => PHP-Objekt
$GLOBALS['NEXTID'] = 9000;
$GLOBALS['FORMFIELDS'] = [];

function obj($id, $type, $name, $parent, $ident = '') {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => $type, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ParentID' => $parent];
    return $id;
}
function vari($id, $name, $parent, $ident, $profile, $value, $age = 0) {
    obj($id, 2, $name, $parent, $ident);
    $GLOBALS['VAR'][$id] = ['VariableType' => 2, 'VariableProfile' => $profile,
                            'VariableCustomProfile' => '', 'VariableUpdated' => time() - $age];
    $GLOBALS['VAL'][$id] = $value;
    return $id;
}

function IPS_ObjectExists($id)    { return isset($GLOBALS['OBJ'][$id]); }
function IPS_InstanceExists($id)  { return isset($GLOBALS['INSTMOD'][$id]); }
function IPS_VariableExists($id)  { return isset($GLOBALS['VAR'][$id]); }
function IPS_GetObject($id)       { return $GLOBALS['OBJ'][$id] ?? null; }
function IPS_GetVariable($id)     { return $GLOBALS['VAR'][$id] ?? false; }
function IPS_GetName($id)         { return $GLOBALS['OBJ'][$id]['ObjectName'] ?? ('#' . $id); }
function IPS_GetParent($id)       { return $GLOBALS['OBJ'][$id]['ParentID'] ?? 0; }
function IPS_SetName($id, $n)     { $GLOBALS['OBJ'][$id]['ObjectName'] = $n; }
function IPS_SetParent($id, $p)   { $GLOBALS['OBJ'][$id]['ParentID'] = $p; }
function IPS_SetIdent($id, $i)    { $GLOBALS['OBJ'][$id]['ObjectIdent'] = $i; }
function IPS_SetPosition($id, $p) {}
function IPS_GetVariableList()    { return array_keys($GLOBALS['VAR']); }
function GetValue($id)            { return $GLOBALS['VAL'][$id] ?? 0; }
function SetValueFloat($id, $v)   { $GLOBALS['VAL'][$id] = $v; }

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
function IPS_CreateVariable($t) {
    $id = $GLOBALS['NEXTID']++;
    obj($id, 2, 'neu', 0);
    $GLOBALS['VAR'][$id] = ['VariableType' => $t, 'VariableProfile' => '', 'VariableCustomProfile' => '', 'VariableUpdated' => time()];
    $GLOBALS['VAL'][$id] = 0;
    return $id;
}
function IPS_SetVariableCustomProfile($id, $p) { $GLOBALS['VAR'][$id]['VariableCustomProfile'] = $p; }
function IPS_DeleteVariable($id) { unset($GLOBALS['OBJ'][$id], $GLOBALS['VAR'][$id]); }
// Echte, zustandsbehaftete Profil-Registry (statt fixer Whitelist) — nötig,
// um zu verifizieren, dass ensureSharedProfile() ein bereits vorhandenes
// Profil NICHT überschreibt (Verbund-Konvention: kein Eigentümer-Modul).
$GLOBALS['PROFILES'] = [
    'MHB.W'   => ['Digits' => 0, 'Suffix' => ' W'],
    'MHB.kWh' => ['Digits' => 1, 'Suffix' => ' kWh'],
    'MHB.A'   => ['Digits' => 1, 'Suffix' => ' A'],
];
function IPS_VariableProfileExists($n) { return isset($GLOBALS['PROFILES'][$n]); }
function IPS_GetVariableProfile($n) { return $GLOBALS['PROFILES'][$n] ?? ['Suffix' => '']; }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['PROFILES'][$n] = $GLOBALS['PROFILES'][$n] ?? ['Digits' => 0, 'Suffix' => '']; }
function IPS_SetVariableProfileDigits($n, $d) { $GLOBALS['PROFILES'][$n]['Digits'] = $d; }
function IPS_SetVariableProfileText($n, $a, $b) { $GLOBALS['PROFILES'][$n]['Suffix'] = $b; }
function IPS_SetVariableProfileIcon($n, $i) { $GLOBALS['PROFILES'][$n]['Icon'] = $i; }
function IPS_GetInstanceListByModuleID($guid) {
    $out = [];
    foreach ($GLOBALS['INSTMOD'] as $iid => $g) { if ($g === $guid) { $out[] = $iid; } }
    return $out;
}
function IPS_GetInstance($iid) {
    return ['ModuleInfo' => ['ModuleID' => $GLOBALS['INSTMOD'][$iid] ?? '']];
}
function IPS_CreateInstance($guid) {
    $id = $GLOBALS['NEXTID']++;
    obj($id, 1, 'neue Instanz', 0);
    $GLOBALS['INSTMOD'][$id] = $guid;
    return $id;
}
function IPS_SetProperty($iid, $n, $v) { $GLOBALS['PROP'][$iid][$n] = $v; }
function IPS_GetProperty($iid, $n)     { return $GLOBALS['PROP'][$iid][$n] ?? ''; }
function IPS_ApplyChanges($iid) {
    // Das ist der eigentliche Integrationstest: Die frisch erzeugte
    // Verdrahtung muss vom Zielmodul auch akzeptiert werden.
    if ($GLOBALS['INSTMOD'][$iid] === '{ADF18291-2E60-4354-92F5-B96863C127C8}') {
        $m = new MeterHubVirtual($iid);
        $m->Create();
        $GLOBALS['MODOBJ'][$iid] = $m;
        $m->ApplyChanges();
    }
}
function AC_SetLoggingStatus($a, $b, $c) {}
function AC_SetAggregationType($a, $b, $c) {}
function AC_GetLoggingStatus($a, $vid) { return isset($GLOBALS['ARCHIVED'][$vid]); }

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
    protected function SetTimerInterval($n, $i) {}
    protected function SetStatus($s) { $GLOBALS['STATUS'][$this->InstanceID] = $s; }
    protected function SetVisualizationType($t) {}
    protected function SendDebug($sender, $msg, $format) {}
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = $v; }
    protected function ReloadForm() {}
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
}

require_once dirname(__DIR__) . '/MeterHub/module.php';
require_once dirname(__DIR__) . '/MeterHubVirtual/module.php';

// ---------------------------------------------------------------------------
// Anlage aufbauen: drei MeterHub-Instanzen mit je eigenen Kategorien
// ---------------------------------------------------------------------------
const G_METER = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
$root = obj(10, 0, 'Geräte', 0);

function meter($iid, $name, $p, $imp, $exp = null) {
    obj($iid, 1, $name, 10);
    $GLOBALS['INSTMOD'][$iid] = G_METER;
    $GLOBALS['PROP'][$iid]['MeasureMode'] = 'combined';
    $cat = obj($iid + 1, 0, 'Summenwerte', $iid, 'cat_total');
    $cat2 = obj($iid + 2, 0, 'Energiezähler', $iid, 'cat_energy');
    vari($iid + 3, 'Wirkleistung gesamt', $cat, 'power_total', 'MHB.W', $p);
    vari($iid + 4, 'Bezug', $cat2, 'energy_import', 'MHB.kWh', $imp);
    if ($exp !== null) { vari($iid + 5, 'Einspeisung', $cat2, 'energy_export', 'MHB.kWh', $exp); }
}
meter(100, 'Hausanschluss', 5000.0, 40000.0, 8000.0);
meter(200, 'Wärmepumpe',   1200.0,  9000.0);
meter(300, 'Wallbox',      1800.0,  5000.0);

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
echo "\n1) CreateVirtual, Rolle 'parent'\n";
$hub = new MeterHub(100);
$hub->Create();
$new = $hub->CreateVirtual(json_encode([['InstanceID' => 200], ['InstanceID' => 300]]), 'parent');
check('neue Instanz angelegt', $new > 0, "Rückgabe=$new");
$nodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
check('drei Knoten', is_array($nodes) && count($nodes) === 3, 'count=' . (is_array($nodes) ? count($nodes) : '-'));
check('Wurzel ohne Elternbezug', ($nodes[0]['Parent'] ?? 'x') === '');
check('Kinder hängen an der Wurzel', ($nodes[1]['Parent'] ?? '') === $nodes[0]['Key'] && ($nodes[2]['Parent'] ?? '') === $nodes[0]['Key']);
check('Umlaut im Kürzel umgesetzt', ($nodes[2]['Key'] ?? '') === 'waermepumpe' || ($nodes[1]['Key'] ?? '') === 'waermepumpe', json_encode(array_column($nodes, 'Key')));
check('Datenpunkte gefunden', ($nodes[0]['PowerID'] ?? 0) === 103 && ($nodes[0]['EnergyImportID'] ?? 0) === 104 && ($nodes[0]['EnergyExportID'] ?? 0) === 105);
check('Funktionen bleiben leer', array_unique(array_column($nodes, 'Function')) === ['none']);
check('Instanz sitzt neben dem Zähler', IPS_GetObject($new)['ParentID'] === 10);

echo "\n2) Zielmodul akzeptiert die erzeugte Verdrahtung\n";
$virt = $GLOBALS['MODOBJ'][$new];
check('Status = aktiv (102)', ($GLOBALS['STATUS'][$new] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$outs = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $outs[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('Summe Leistung existiert', isset($outs['hausanschluss_sum_power']), implode(', ', array_keys($outs)));
check('Rest Leistung existiert',  isset($outs['hausanschluss_rest_power']));
$virt->Recalc();
check('Summe = 1200 + 1800', abs(GetValue($outs['hausanschluss_sum_power']) - 3000.0) < 0.01, 'ist ' . GetValue($outs['hausanschluss_sum_power']));
check('Rest = 5000 − 3000',   abs(GetValue($outs['hausanschluss_rest_power']) - 2000.0) < 0.01, 'ist ' . GetValue($outs['hausanschluss_rest_power']));
check('Rest Bezug = 40000 − 14000', abs(GetValue($outs['hausanschluss_rest_imp']) - 26000.0) < 0.01, 'ist ' . GetValue($outs['hausanschluss_rest_imp']));

echo "\n3) Prüfung verhindert den zweiten virtuellen Zähler\n";
$GLOBALS['FORMFIELDS'] = [];
$again = $hub->CreateVirtual(json_encode([['InstanceID' => 200]]), 'parent');
check('nichts angelegt', $again === 0, "Rückgabe=$again");
check('Begründung genannt', str_contains($GLOBALS['FORMFIELDS']['VirtualResult']['caption'] ?? '', 'bereits Teil von'));

echo "\n4) Leere Auswahl\n";
$GLOBALS['FORMFIELDS'] = [];
$hub2 = new MeterHub(200); $hub2->Create();
check('ohne Partner nichts angelegt', $hub2->CreateVirtual('[]', 'parent') === 0);
check('Hinweis erklärt warum', str_contains($GLOBALS['FORMFIELDS']['VirtualResult']['caption'] ?? '', 'kein weiterer Zähler'));

echo "\n5) Rolle 'sibling'\n";
// Frische Zähler — 100/200/300 stecken bereits im ersten virtuellen Zähler und
// werden von der Prüfung (zu Recht) abgelehnt.
meter(700, 'Wallbox 2', 1800.0, 5000.0);
meter(800, 'Garage',    1200.0, 9000.0);
$hub3 = new MeterHub(700); $hub3->Create();
$sib = $hub3->CreateVirtual(json_encode([['InstanceID' => 800]]), 'sibling');
$snodes = json_decode(IPS_GetProperty($sib, 'Nodes'), true);
check('Sammelknoten + zwei Zähler', count($snodes) === 3 && $snodes[0]['Key'] === 'summe');
check('Sammelknoten ohne Datenpunkt', (int)$snodes[0]['PowerID'] === 0);
$souts = [];
foreach (IPS_GetChildrenIDs($sib) as $c) { $souts[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('nur Summe, kein Rest', isset($souts['summe_sum_power']) && !isset($souts['summe_rest_power']), implode(', ', array_keys($souts)));
$GLOBALS['MODOBJ'][$sib]->Recalc();
check('Summe = 1800 + 1200', abs(GetValue($souts['summe_sum_power']) - 3000.0) < 0.01, 'ist ' . GetValue($souts['summe_sum_power']));

echo "\n6) Suchlauf mit Filtern\n";
// Streuobst: eine Steckdose ohne Energie, eine Karteileiche, eine in anderem Ast
$plugs = obj(500, 0, 'Steckdosen', 10);
$p1 = obj(510, 0, 'Steckdose Küche', $plugs);   vari(511, 'Leistung', $p1, '', 'MHB.W', 42.0);
$p2 = obj(520, 0, 'Steckdose Keller', $plugs);  vari(521, 'Leistung', $p2, '', 'MHB.W', 7.0, 30 * 86400);
                                                 vari(522, 'Energie', $p2, '', 'MHB.kWh', 12.0, 30 * 86400);
$other = obj(600, 0, 'Sonstiges', 0);
$p3 = obj(610, 0, 'Steckdose Garten', $other);  vari(611, 'Leistung', $p3, '', 'MHB.W', 3.0);
                                                 vari(612, 'Energie', $p3, '', 'MHB.kWh', 5.0);

$fresh = new MeterHubVirtual(8000);
$GLOBALS['INSTMOD'][8000] = '{ADF18291-2E60-4354-92F5-B96863C127C8}';
obj(8000, 1, 'Virtuell leer', 10);
$fresh->Create();

$run = function ($root, $filter, $needEnergy, $onlyActive) use ($fresh) {
    $GLOBALS['FORMFIELDS'] = [];
    $fresh->ScanMeters($root, $filter, $needEnergy, $onlyActive);
    $rows = json_decode($GLOBALS['FORMFIELDS']['Nodes']['values'] ?? '[]', true);
    return array_column($rows, 'Name');
};
$all = $run(0, '', false, false);
check('ungefiltert findet alles', count($all) >= 6, implode(' | ', $all));
$bereich = $run(10, '', false, false);
check('Bereichsfilter schließt anderen Ast aus', !in_array('Steckdose Garten', $bereich, true), implode(' | ', $bereich));
$name = $run(0, 'keller', false, false);
check('Namensfilter greift (auch ohne Groß/Klein)', $name === ['Steckdose Keller'], implode(' | ', $name));
$energie = $run(0, '', true, false);
check('nur mit Energiezähler', !in_array('Steckdose Küche', $energie, true), implode(' | ', $energie));
$aktiv = $run(0, '', false, true);
check('Karteileiche ausgeblendet', !in_array('Steckdose Keller', $aktiv, true), implode(' | ', $aktiv));
check('Zeilenzahl wächst mit', ($GLOBALS['FORMFIELDS']['Nodes']['rowCount'] ?? 0) >= 12);

echo "\n6b) Ausschluss bekannter NRG-Stack-Module\n";
// Genau der Fall, der an Dietmars Installation den 197-Zeilen-Befund erzeugt
// hat: eine W-Variable, die innerhalb einer EMS-Instanz liegt (auch verschachtelt
// über eine Kategorie), darf nicht als "Fremdzähler" auftauchen — auch wenn
// Profil/Suffix technisch passen. Ein normales Gerät direkt daneben schon.
$emsRoot = obj(900, 1, 'Energy Management System', 10);
$GLOBALS['INSTMOD'][900] = '{31C61A7B-28C4-4F97-9651-1A64B3469E3C}'; // echte EMS-GUID
$emsCat  = obj(901, 0, 'Berechnete Werte', $emsRoot);
vari(902, 'Hauslast (berechnet)', $emsCat, '', 'MHB.W', 1234.0);
$realDevice = obj(910, 0, 'Kaffeemaschine', 10);
vari(911, 'Leistung', $realDevice, '', 'MHB.W', 800.0);

$afterEms = $run(0, '', false, false);
check('EMS-Variable NICHT im Suchlauf', !in_array('Energy Management System', $afterEms, true), implode(' | ', $afterEms));
check('echtes Gerät daneben weiter gefunden', in_array('Kaffeemaschine', $afterEms, true), implode(' | ', $afterEms));
$GLOBALS['FORMFIELDS'] = [];
$fresh->ScanMeters(0, '', false, false);
check('Meldungstext nennt den Verbund-Ausschluss', str_contains($GLOBALS['FORMFIELDS']['ScanResult']['caption'] ?? '', 'NRG-Stack-Modulen'));

echo "\n6c) Neue Darstellungen (IPS 7+/8) statt Profile — Testerfund von Sepp, 30.08.2026\n";
// KNX-Watt-Variablen und Shellys mit Darstellung statt klassischem Profil
// fielen komplett durch den Suchlauf. Zwei reale Formen (live an Dietmars
// Anlage abgelesen): Darstellung mit direktem SUFFIX, und Darstellung, die
// ein Alt-Profil referenziert.
$presDev1 = obj(920, 0, 'KNX Aktor Neu', 10);
vari(921, 'Wirkleistung', $presDev1, '', '', 1500.0);
$GLOBALS['VAR'][921]['VariableCustomPresentation'] = ['SUFFIX' => ' W', 'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
$presDev2 = obj(930, 0, 'Shelly Neu', 10);
vari(931, 'Energie', $presDev2, '', '', 42.0);
$GLOBALS['VAR'][931]['VariablePresentation'] = ['PROFILE' => 'MHB.kWh', 'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
// Gegenprobe: Darstellung mit fremdem Suffix darf NICHT auftauchen.
$presDev3 = obj(940, 0, 'Thermostat Neu', 10);
vari(941, 'Temperatur', $presDev3, '', '', 21.5);
$GLOBALS['VAR'][941]['VariableCustomPresentation'] = ['SUFFIX' => ' °C', 'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];

$presScan = $run(0, '', false, false);
check('Darstellung mit SUFFIX " W" gefunden', in_array('KNX Aktor Neu', $presScan, true), implode(' | ', $presScan));
check('Darstellung mit PROFILE-Referenz (kWh) gefunden', in_array('Shelly Neu', $presScan, true), implode(' | ', $presScan));
check('Darstellung mit °C-Suffix NICHT gefunden', !in_array('Thermostat Neu', $presScan, true), implode(' | ', $presScan));

echo "\n7) Rückkopplungsschutz\n";
$feedback = $run(0, '', false, false);
$virtOutNames = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $virtOutNames[] = IPS_GetName($c); }
$overlap = array_intersect($feedback, $virtOutNames);
check('keine Ausgabe eines virtuellen Zählers als Quelle', $overlap === [], implode(' | ', $overlap));

echo "\n8) Vertragserweiterung MHUBV_GetFunctions\n";
// Der erste virtuelle Zähler (#$new, Rolle parent) hat noch Funktion 'none' an
// allen Knoten -> leere assignments. Für den Vertragstest einem Knoten eine
// Funktion geben.
$vnodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
$vnodes[0]['Function'] = 'house';
IPS_SetProperty($new, 'Nodes', json_encode($vnodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
$gf = json_decode($GLOBALS['MODOBJ'][$new]->GetFunctions(), true);
check('contractVersion = 1.1', ($gf['contractVersion'] ?? '') === '1.1', json_encode($gf['contractVersion'] ?? null));
check('latency = realtime',   ($gf['latency'] ?? '') === 'realtime', json_encode($gf['latency'] ?? null));
check('authority = auxiliary', ($gf['authority'] ?? '') === 'auxiliary');
check('pollInterval gesetzt',  ($gf['pollInterval'] ?? 0) >= 2);
$a0 = $gf['assignments'][0] ?? [];
check('assignment vorhanden',  !empty($gf['assignments']), json_encode($gf['assignments'] ?? null));
check('energyKind = counter',  ($a0['energyKind'] ?? '') === 'counter');
check('sourceCount = 2 Kinder', ($a0['sourceCount'] ?? -1) === 2, 'ist ' . ($a0['sourceCount'] ?? 'fehlt'));
// authority/latency MÜSSEN auch je Zuordnung stehen (InverterHub filtert dort).
check('authority je Zuordnung', ($a0['authority'] ?? '') === 'auxiliary');
check('latency je Zuordnung',   ($a0['latency'] ?? '') === 'realtime');

echo "\n9) NRG-Stack-Profile: gemeinsam, aber eigentümerlos\n";
// MeterHubVirtual hat oben (Block 1-8) mehrfach ApplyChanges() durchlaufen —
// NRG.Watt/NRG.kWh müssen dabei mit den korrekten Werten entstanden sein.
check('NRG.Watt entstanden (0 Nachkommastellen, " W")',
    ($GLOBALS['PROFILES']['NRG.Watt'] ?? null) === ['Digits' => 0, 'Suffix' => ' W'],
    json_encode($GLOBALS['PROFILES']['NRG.Watt'] ?? null));
check('NRG.kWh entstanden (1 Nachkommastelle, " kWh")',
    ($GLOBALS['PROFILES']['NRG.kWh'] ?? null) === ['Digits' => 1, 'Suffix' => ' kWh'],
    json_encode($GLOBALS['PROFILES']['NRG.kWh'] ?? null));

// Die eigentliche Pointe der Konvention: Ein ANDERES NRG-Stack-Modul hat
// NRG.Watt bereits mit abweichenden Werten angelegt — MeterHub darf das
// beim nächsten ApplyChanges NICHT zurechtbiegen (kein Eigentümer-Modul).
$GLOBALS['PROFILES']['NRG.Watt'] = ['Digits' => 9, 'Suffix' => ' FREMD'];
$ensureShared = new ReflectionMethod('MeterHub', 'ensureSharedProfile');
$hubForProfile = new MeterHub(999);
$ensureShared->invoke($hubForProfile, 'NRG.Watt', ' W', 0, 'Electricity');
check('fremd angelegtes NRG.Watt bleibt unangetastet',
    $GLOBALS['PROFILES']['NRG.Watt'] === ['Digits' => 9, 'Suffix' => ' FREMD'],
    json_encode($GLOBALS['PROFILES']['NRG.Watt']));

// Gegenprobe: fehlt das Profil komplett, legt MeterHub es korrekt an.
unset($GLOBALS['PROFILES']['NRG.Ampere']);
$ensureShared->invoke($hubForProfile, 'NRG.Ampere', ' A', 1, 'Electricity');
check('fehlendes NRG.Ampere wird korrekt angelegt',
    ($GLOBALS['PROFILES']['NRG.Ampere'] ?? null) === ['Digits' => 1, 'Suffix' => ' A', 'Icon' => 'Electricity'],
    json_encode($GLOBALS['PROFILES']['NRG.Ampere'] ?? null));

// Modulspezifische (nicht geteilte) Profile bleiben im alten Verhalten:
// MeterHub IST Eigentümer und setzt Digits/Suffix bei jedem Aufruf durch.
$GLOBALS['PROFILES']['MHB.Hz'] = ['Digits' => 9, 'Suffix' => ' FREMD'];
$ensureOwn = new ReflectionMethod('MeterHub', 'ensureProfile');
$ensureOwn->invoke($hubForProfile, 'MHB.Hz', ' Hz', 2, '');
check('modulspezifisches MHB.Hz wird weiterhin durchgesetzt',
    ($GLOBALS['PROFILES']['MHB.Hz'] ?? null) === ['Digits' => 2, 'Suffix' => ' Hz'],
    json_encode($GLOBALS['PROFILES']['MHB.Hz'] ?? null));

echo "\n10) Sicherheitsnetz gegen den 25.07.2026-Vorfall (#16933) + Durchreichung seit 31.08.2026\n";
// Nachstellung des realen Vorfalls: eine Instanz mit funktionierender
// Verdrahtung (Summe/Rest existieren) verliert die Verdrahtung — vorher
// wurden dabei ALLE Ausgabevariablen auf einen Schlag geloescht, obwohl nur
// eine einzelne Zeile betroffen war.
$before = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $before[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('Ausgangslage: Ausgaben vorhanden', isset($before['hausanschluss_sum_power']), implode(', ', array_keys($before)));
check('Ausgangslage: Kinder haben (seit 31.08.2026) schon eigene Durchreichung', isset($before['waermepumpe_rest_power']), implode(', ', array_keys($before)));

// 10a) Verdrahtung komplett flach machen (kein Knoten hat mehr Kinder) —
// bis 31.08.2026 der Zustand, der RegisterVariables() "nichts ist mehr
// gueltig" lesen liess. Seit dem Fund von Sepp/Dietmar (kinderlose Steckdose
// ohne jede Ausgabe) tragen alle drei Knoten hier aber weiterhin ihren
// EIGENEN Zaehler — das Sicherheitsnetz darf deshalb NICHT mehr greifen,
// sondern muss auf reine Durchreichungs-Ausgaben umschalten (kein
// "_sum_" mehr, "_rest_" bleibt als 1:1-Durchreichung des eigenen Werts).
$flatNodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
foreach ($flatNodes as &$r) { $r['Parent'] = ''; }
unset($r);
IPS_SetProperty($new, 'Nodes', json_encode($flatNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10a: Status bleibt aktiv (102) — kein Fehler mehr, jeder Knoten hat ja einen eigenen Zähler', ($GLOBALS['STATUS'][$new] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$after10a = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $after10a[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10a: "_sum_"-Ausgaben verschwinden (hausanschluss hat keine Kinder mehr)', !isset($after10a['hausanschluss_sum_power']), implode(',', array_keys($after10a)));
check('10a: "_rest_"-Durchreichung bleibt für alle drei erhalten', isset($after10a['hausanschluss_rest_power']) && isset($after10a['waermepumpe_rest_power']) && isset($after10a['wallbox_rest_power']), implode(',', array_keys($after10a)));
$GLOBALS['MODOBJ'][$new]->Recalc();
$hausPower = null;
foreach (IPS_GetChildrenIDs($new) as $c) { if ($GLOBALS['OBJ'][$c]['ObjectIdent'] === 'hausanschluss_rest_power') { $hausPower = GetValue($c); } }
check('10a: Durchreichung = eigener Zählerwert (5000 W), kein "Rest" mehr abgezogen', abs(($hausPower ?? -1) - 5000.0) < 0.01, 'ist ' . $hausPower);

// 10a-safety) Gegenprobe zum eigentlichen Sicherheitsnetz: ein Knoten OHNE
// jeden eigenen Zähler UND ohne Kinder ergibt weiterhin exakt NULL Ausgaben
// — das ist der einzige Fall, in dem die 25.07.2026-Sperre noch greifen muss.
$emptyNodes = [['Key' => 'leer', 'Name' => 'Ohne alles', 'Parent' => '', 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none']];
IPS_SetProperty($new, 'Nodes', json_encode($emptyNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10a-safety: Status = Fehler (201) — wirklich NICHTS mehr zu berechnen', ($GLOBALS['STATUS'][$new] ?? 0) === 201, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$afterSafety = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $afterSafety[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10a-safety: vorhandene Ausgaben NICHT geloescht', $afterSafety === $after10a, 'vorher=' . implode(',', array_keys($after10a)) . ' nachher=' . implode(',', array_keys($afterSafety)));
$checkSafetyErr = (new ReflectionMethod('MeterHubVirtual', 'Validate'))->invoke($GLOBALS['MODOBJ'][$new]);
check('10a-safety: Fehlermeldung nennt die Ursache', !empty($checkSafetyErr) && str_contains(implode(' ', $checkSafetyErr), 'keine einzige Ausgabe'), implode(' | ', $checkSafetyErr));

// 10b) Zurück zur flachen, aber gültigen Verdrahtung (Zustand von 10a) und
// zusätzlich einen echten Fehler einbauen (verweist auf geloeschte
// Variable) — unabhaengig vom Flach-Fall muss JEDER Validate()-Fehler die
// Loeschung verhindern.
$fixedNodes = $flatNodes;
foreach ($fixedNodes as &$r) {
    if (($r['Key'] ?? '') !== 'hausanschluss') { $r['Parent'] = 'hausanschluss'; }
}
unset($r);
$fixedNodes[0]['EnergyExportID'] = 999999; // existiert nicht
IPS_SetProperty($new, 'Nodes', json_encode($fixedNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10b: Status = Fehler (201)', ($GLOBALS['STATUS'][$new] ?? 0) === 201, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$after10b = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $after10b[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10b: vorhandene Ausgaben NICHT geloescht (allgemeiner Fehlerfall)', $after10b === $afterSafety, 'nachher=' . implode(',', array_keys($after10b)));

// 10c) Gegenprobe: Verdrahtung sauber reparieren (kein Fehler mehr) — jetzt
// MUSS RegisterVariables() wieder normal arbeiten, damit das Sicherheitsnetz
// nicht zur Dauerblockade wird.
$cleanNodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
foreach ($cleanNodes as &$r) {
    if (($r['Key'] ?? '') !== 'hausanschluss') { $r['Parent'] = 'hausanschluss'; }
    $r['EnergyExportID'] = ($r['Key'] ?? '') === 'hausanschluss' ? 105 : (int)$r['EnergyExportID'];
}
unset($r);
IPS_SetProperty($new, 'Nodes', json_encode($cleanNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10c: nach Reparatur wieder aktiv (102)', ($GLOBALS['STATUS'][$new] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$after10c = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $after10c[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10c: Ausgaben nach Reparatur weiter vorhanden', isset($after10c['hausanschluss_sum_power']), implode(',', array_keys($after10c)));

echo "\n11) Formular-Konvention: News-Panel + Doku-Panel (31.08.2026, Dietmars Auftrag \"hier maximal nachbessern\")\n";
$newsVersion = (new ReflectionClass('MeterHubVirtual'))->getConstant('NEWS_VERSION');
$form11 = json_decode($GLOBALS['MODOBJ'][$new]->GetConfigurationForm(), true);
check('Formular bleibt gültiges JSON', is_array($form11));
$panelCaptions = array_column($form11['elements'] ?? [], 'caption');
check('News-Panel erscheint vor ungesehener Bestätigung', in_array('🆕  Neu in dieser Version', $panelCaptions, true), implode(' | ', $panelCaptions));
$dokuPanel = null;
foreach ($form11['elements'] ?? [] as $el) { if (($el['caption'] ?? '') === '📖  Dokumentation & Hilfe') { $dokuPanel = $el; } }
check('Doku-Panel vorhanden', $dokuPanel !== null);
$dokuText = implode(' ', array_column($dokuPanel['items'] ?? [], 'caption'));
check('Doku-Panel nennt die Versionsnummer', str_contains($dokuText, $newsVersion), $dokuText);
check('Doku-Panel enthält alle drei Verdrahtungs-Muster', str_contains($dokuText, '①') && str_contains($dokuText, '②') && str_contains($dokuText, '③'));
check('Doku-Panel erklärt den neuen Durchreichungs-Fall', str_contains($dokuText, 'Durchreichung'));

// Schritt-für-Schritt-Anleitung + "?"-PopupButtons im Verdrahtungs-Panel
// (Dietmars zweite Rückmeldung 31.08.2026: die Doku allein genügte noch
// nicht — SUITE.md "Feld-Hilfestellung" sieht PopupButton mit caption="?"
// für genau diesen Fall vor, kein natives Mouseover in Symcon).
$wiringPanel = null;
foreach ($form11['elements'] ?? [] as $el) { if (($el['caption'] ?? '') === '🔌  Verdrahtung') { $wiringPanel = $el; } }
check('Verdrahtungs-Panel vorhanden', $wiringPanel !== null);
$wiringItems = $wiringPanel['items'] ?? [];
check('Nummerierte Schritt-für-Schritt-Anleitung direkt im Panel', str_contains(implode(' ', array_column($wiringItems, 'caption')), '1. Zeile anlegen'));
$popups = array_values(array_filter($wiringItems, fn ($it) => ($it['type'] ?? '') === 'PopupButton'));
check('Genau zwei "?"-PopupButtons im Verdrahtungs-Panel', count($popups) === 2, 'gefunden: ' . count($popups));
$parentPopup = null;
foreach ($popups as $p) { if (str_contains($p['caption'] ?? '', 'hängt hinter')) { $parentPopup = $p; } }
check('PopupButton "hängt hinter" vorhanden', $parentPopup !== null);
$parentPopupText = implode(' ', array_column($parentPopup['popup']['items'] ?? [], 'caption'));
check('Popup-Inhalt "hängt hinter" enthält alle drei Muster', str_contains($parentPopupText, '①') && str_contains($parentPopupText, '②') && str_contains($parentPopupText, '③'), $parentPopupText);
$keyPopup = null;
foreach ($popups as $p) { if (str_contains($p['caption'] ?? '', 'Kürzel')) { $keyPopup = $p; } }
check('PopupButton "Kürzel" vorhanden', $keyPopup !== null);
check('Popup-Inhalt "Kürzel" warnt vor Historie-Verlust', str_contains(implode(' ', array_column($keyPopup['popup']['items'] ?? [], 'caption')), 'Historie'));

$GLOBALS['MODOBJ'][$new]->AckNews();
check('AckNews() merkt die Version dauerhaft', ($GLOBALS['ATTR'][$new]['SeenNews'] ?? null) === $newsVersion, 'ist ' . ($GLOBALS['ATTR'][$new]['SeenNews'] ?? '(leer)'));
check('AckNews() blendet das Panel im offenen Formular sofort aus', ($GLOBALS['FORMFIELDS']['NewsPanel']['visible'] ?? null) === false);
$form11b = json_decode($GLOBALS['MODOBJ'][$new]->GetConfigurationForm(), true);
$panelCaptions2 = array_column($form11b['elements'] ?? [], 'caption');
check('News-Panel bleibt nach Bestätigung auch bei einem Neuaufbau weg', !in_array('🆕  Neu in dieser Version', $panelCaptions2, true), implode(' | ', $panelCaptions2));

echo "\n12) CombineSelected() — Schnellweg zum Verdrahten (Dietmars Anstoß 31.08.2026)\n";
$combineHub = $GLOBALS['MODOBJ'][$new];

echo "  12a) Neue Sammelzeile (Muster ①) aus zwei ausgewählten Zeilen\n";
$rowsA = [
    ['Key' => 'kuehlschrank', 'Name' => 'Kühlschrank', 'Parent' => '', 'PowerID' => 511, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true],
    ['Key' => 'brunnenpumpe', 'Name' => 'Brunnenpumpe', 'Parent' => '', 'PowerID' => 521, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true],
];
$msgA = $combineHub->CombineSelected(json_encode($rowsA), '__NEU__');
check('Ergebnistext meldet Erfolg', str_contains($msgA, '✅'), $msgA);
$writtenA = json_decode($GLOBALS['FORMFIELDS']['Nodes']['values'], true);
check('drei Zeilen nach dem Zusammenfassen (2 Geräte + 1 neue Sammelzeile)', count($writtenA) === 3, json_encode($writtenA));
$newRowA = end($writtenA);
check('neue Zeile ohne eigenen Zähler', ((int) $newRowA['PowerID']) === 0);
check('neue Zeile heißt sinnvoll (aus den Gerätenamen)', $newRowA['Name'] === 'Kühlschrank + Brunnenpumpe', $newRowA['Name']);
check('beide Geräte hängen jetzt hinter der neuen Zeile', $writtenA[0]['Parent'] === $newRowA['Key'] && $writtenA[1]['Parent'] === $newRowA['Key']);
check('Auswahl-Häkchen werden zurückgesetzt', $writtenA[0]['Selected'] === false && $writtenA[1]['Selected'] === false && $newRowA['Selected'] === false);

echo "\n  12b) Key-Kollision: „sammelzaehler“ ist schon vergeben -> automatisch sammelzaehler_2\n";
$rowsB = [
    ['Key' => 'sammelzaehler', 'Name' => 'Alt', 'Parent' => '', 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => false],
    ['Key' => 'geraet1', 'Name' => 'Gerät 1', 'Parent' => '', 'PowerID' => 1, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true],
    ['Key' => 'geraet2', 'Name' => 'Gerät 2', 'Parent' => '', 'PowerID' => 2, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true],
    ['Key' => 'geraet3', 'Name' => 'Gerät 3', 'Parent' => '', 'PowerID' => 3, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true],
    ['Key' => 'geraet4', 'Name' => 'Gerät 4', 'Parent' => '', 'PowerID' => 4, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true],
];
$msgB = $combineHub->CombineSelected(json_encode($rowsB), '__NEU__');
$writtenB = json_decode($GLOBALS['FORMFIELDS']['Nodes']['values'], true);
$newRowB = end($writtenB);
check('Kürzel weicht bei Kollision aus (sammelzaehler_2)', $newRowB['Key'] === 'sammelzaehler_2', $newRowB['Key']);
check('bei mehr als 3 Geräten wird generisch benannt', $newRowB['Name'] === 'Sammelzähler (4 Geräte)', $newRowB['Name']);

echo "\n  12c) Von einer vorhandenen Zeile abziehen (Muster ②, Dietmars Ergänzung \"Zähler von einem anderen abziehen\")\n";
$rowsC = [
    ['Key' => 'hausanschluss', 'Name' => 'Hausanschluss', 'Parent' => '', 'PowerID' => 100, 'EnergyImportID' => 101, 'EnergyExportID' => 102, 'Function' => 'grid', 'Selected' => false],
    ['Key' => 'waermepumpe', 'Name' => 'Wärmepumpe', 'Parent' => '', 'PowerID' => 200, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'heatpump', 'Selected' => true],
    ['Key' => 'wallbox', 'Name' => 'Wallbox', 'Parent' => '', 'PowerID' => 300, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'wallbox1', 'Selected' => true],
];
$msgC = $combineHub->CombineSelected(json_encode($rowsC), 'hausanschluss');
check('Ergebnistext nennt die Zielzeile', str_contains($msgC, 'Hausanschluss'), $msgC);
$writtenC = json_decode($GLOBALS['FORMFIELDS']['Nodes']['values'], true);
check('keine neue Zeile — weiterhin genau drei', count($writtenC) === 3, json_encode($writtenC));
check('Wärmepumpe hängt jetzt hinter Hausanschluss', $writtenC[1]['Parent'] === 'hausanschluss');
check('Wallbox hängt jetzt hinter Hausanschluss', $writtenC[2]['Parent'] === 'hausanschluss');
check('Funktionszuordnung bleibt beim Verschieben erhalten', $writtenC[1]['Function'] === 'heatpump' && $writtenC[2]['Function'] === 'wallbox1');

echo "\n  12d) Randfälle: keine Auswahl / Zielzeile ist Teil der eigenen Auswahl / unbekanntes Ziel\n";
$rowsD = [['Key' => 'x', 'Name' => 'X', 'Parent' => '', 'PowerID' => 1, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => false]];
$msgD1 = $combineHub->CombineSelected(json_encode($rowsD), '__NEU__');
check('keine Auswahl -> verständliche Meldung, kein Absturz', str_contains($msgD1, 'ℹ️') && str_contains($msgD1, 'ausgewählt'), $msgD1);

$rowsE = [['Key' => 'einzelzeile', 'Name' => 'Einzelzeile', 'Parent' => '', 'PowerID' => 1, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true]];
$msgE = $combineHub->CombineSelected(json_encode($rowsE), 'einzelzeile');
check('Zielzeile ist ihre eigene einzige Auswahl -> nichts zu tun, kein Selbstbezug', str_contains($msgE, 'ℹ️'), $msgE);

$rowsF = [['Key' => 'y', 'Name' => 'Y', 'Parent' => '', 'PowerID' => 1, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none', 'Selected' => true]];
$msgF = $combineHub->CombineSelected(json_encode($rowsF), 'gibtsnicht');
check('unbekanntes Ziel -> klare Fehlermeldung, kein Absturz', str_contains($msgF, '❌'), $msgF);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
