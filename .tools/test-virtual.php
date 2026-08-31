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
    // Formel muss vom Zielmodul auch akzeptiert werden.
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
echo "\n1) CreateVirtual, Rolle 'parent' — flache Formel statt Baum (seit 0.24.0)\n";
$hub = new MeterHub(100);
$hub->Create();
$new = $hub->CreateVirtual(json_encode([['InstanceID' => 200], ['InstanceID' => 300]]), 'parent');
check('neue Instanz angelegt', $new > 0, "Rückgabe=$new");
$nodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
check('drei Zeilen (kein Sammelknoten mehr nötig)', is_array($nodes) && count($nodes) === 3, 'count=' . (is_array($nodes) ? count($nodes) : '-'));
check('kein Kürzel/„hängt hinter" mehr im Datenmodell', !array_key_exists('Key', $nodes[0]) && !array_key_exists('Parent', $nodes[0]), json_encode($nodes[0]));
check('eigener Zähler bekommt Anteil 100', ($nodes[0]['Factor'] ?? null) === 100, json_encode($nodes[0]));
check('Partner bekommen Anteil −100 (Rest = eigener Zähler minus Partner)', ($nodes[1]['Factor'] ?? null) === -100 && ($nodes[2]['Factor'] ?? null) === -100, json_encode([$nodes[1], $nodes[2]]));
check('Umlaut im Namen unverändert übernommen', in_array('Wärmepumpe', array_column($nodes, 'Name'), true), json_encode(array_column($nodes, 'Name')));
check('Datenpunkte gefunden', ($nodes[0]['PowerID'] ?? 0) === 103 && ($nodes[0]['EnergyImportID'] ?? 0) === 104 && ($nodes[0]['EnergyExportID'] ?? 0) === 105);
check('Instanz sitzt neben dem Zähler', IPS_GetObject($new)['ParentID'] === 10);

echo "\n2) Zielmodul akzeptiert die erzeugte Formel und rechnet vorzeichenrichtig\n";
$virt = $GLOBALS['MODOBJ'][$new];
check('Status = aktiv (102)', ($GLOBALS['STATUS'][$new] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$outs = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $outs[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('genau eine Leistungs-, Bezugs- und Einspeisungs-Ausgabe (keine Zeilen-Idents mehr)', isset($outs['power']) && isset($outs['energy_import']) && isset($outs['energy_export']), implode(', ', array_keys($outs)));
$virt->Recalc();
check('Leistung = 5000 − 1200 − 1800', abs(GetValue($outs['power']) - 2000.0) < 0.01, 'ist ' . GetValue($outs['power']));
check('Bezug = 40000 − 9000 − 5000',   abs(GetValue($outs['energy_import']) - 26000.0) < 0.01, 'ist ' . GetValue($outs['energy_import']));
check('Einspeisung = 8000 (nur Hausanschluss hat einen Einspeisungszähler)', abs(GetValue($outs['energy_export']) - 8000.0) < 0.01, 'ist ' . GetValue($outs['energy_export']));

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

echo "\n5) Rolle 'sibling' — reine Summe, alle Zeilen gleichrangig \"+\"\n";
// Frische Zähler — 100/200/300 stecken bereits im ersten virtuellen Zähler und
// werden von der Prüfung (zu Recht) abgelehnt.
meter(700, 'Wallbox 2', 1800.0, 5000.0);
meter(800, 'Garage',    1200.0, 9000.0);
$hub3 = new MeterHub(700); $hub3->Create();
$sib = $hub3->CreateVirtual(json_encode([['InstanceID' => 800]]), 'sibling');
$snodes = json_decode(IPS_GetProperty($sib, 'Nodes'), true);
check('zwei Zeilen — kein eigener Sammelknoten mehr nötig', count($snodes) === 2, json_encode($snodes));
check('beide Zeilen tragen ihren eigenen Zähler', (int)$snodes[0]['PowerID'] === 703 && (int)$snodes[1]['PowerID'] === 803, json_encode($snodes));
check('beide Zeilen Anteil 100', ($snodes[0]['Factor'] ?? null) === 100 && ($snodes[1]['Factor'] ?? null) === 100, json_encode($snodes));
$souts = [];
foreach (IPS_GetChildrenIDs($sib) as $c) { $souts[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('nur "power"/"energy_import" (kein Einspeisungszähler vorhanden)', isset($souts['power']) && isset($souts['energy_import']) && !isset($souts['energy_export']), implode(', ', array_keys($souts)));
$GLOBALS['MODOBJ'][$sib]->Recalc();
check('Summe Leistung = 1800 + 1200', abs(GetValue($souts['power']) - 3000.0) < 0.01, 'ist ' . GetValue($souts['power']));
check('Summe Bezug = 5000 + 9000', abs(GetValue($souts['energy_import']) - 14000.0) < 0.01, 'ist ' . GetValue($souts['energy_import']));

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

// Seit 0.24.5 (Dietmars Rückmeldung "nicht wirklich intelligent") schreibt
// ScanMeters() nicht mehr in die Nodes-Liste — es ist reine Anzeige. Der
// Test liest deshalb den Ergebnistext (ScanResult) statt eines Live-Werts
// der Liste.
$run = function ($root, $filter, $needEnergy, $onlyActive, $onlyUsedElsewhere = false) use ($fresh) {
    $GLOBALS['FORMFIELDS'] = [];
    $fresh->ScanMeters($root, $filter, $needEnergy, $onlyActive, $onlyUsedElsewhere);
    return $GLOBALS['FORMFIELDS']['ScanResult']['caption'] ?? '';
};
$foundNames = function (string $caption): array {
    if (!preg_match('/gefunden: (.*?)\. Zum Aufnehmen/', $caption, $m)) {
        return [];
    }
    return array_map('trim', explode(', ', $m[1]));
};

$allCap = $run(0, '', false, false);
$all = $foundNames($allCap);
// Nur die drei Steckdosen: Hausanschluss/Wärmepumpe/Wallbox/Wallbox 2/Garage
// stecken zu diesem Zeitpunkt bereits in den virtuellen Zählern aus Block 1
// und 5 und werden von der Kreuz-Instanz-Prüfung (Block 6d) korrekt aus dem
// Suchlauf ausgeblendet.
check('ungefiltert findet die drei noch unbenutzten Steckdosen', count($all) === 3, implode(' | ', $all));
check('Fundtext verweist aufs manuelle Aufnehmen über "+", schreibt nichts automatisch', str_contains($allCap, 'Zum Aufnehmen unten in der Tabelle „+"'), $allCap);
check('Nodes-Formularfeld bleibt beim Suchlauf unberührt (kein automatisches Eintragen mehr)', !isset($GLOBALS['FORMFIELDS']['Nodes']));
$bereich = $foundNames($run(10, '', false, false));
check('Bereichsfilter schließt anderen Ast aus', !in_array('Steckdose Garten', $bereich, true), implode(' | ', $bereich));
$name = $foundNames($run(0, 'keller', false, false));
check('Namensfilter greift (auch ohne Groß/Klein)', $name === ['Steckdose Keller'], implode(' | ', $name));
$energie = $foundNames($run(0, '', true, false));
check('nur mit Energiezähler', !in_array('Steckdose Küche', $energie, true), implode(' | ', $energie));
$aktiv = $foundNames($run(0, '', false, true));
check('Karteileiche ausgeblendet', !in_array('Steckdose Keller', $aktiv, true), implode(' | ', $aktiv));

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

$afterEmsCap = $run(0, '', false, false);
$afterEms = $foundNames($afterEmsCap);
check('EMS-Variable NICHT im Suchlauf', !in_array('Energy Management System', $afterEms, true), implode(' | ', $afterEms));
check('echtes Gerät daneben weiter gefunden', in_array('Kaffeemaschine', $afterEms, true), implode(' | ', $afterEms));
check('Meldungstext nennt den Verbund-Ausschluss', str_contains($afterEmsCap, 'NRG-Stack-Modulen'), $afterEmsCap);

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

$presScan = $foundNames($run(0, '', false, false));
check('Darstellung mit SUFFIX " W" gefunden', in_array('KNX Aktor Neu', $presScan, true), implode(' | ', $presScan));
check('Darstellung mit PROFILE-Referenz (kWh) gefunden', in_array('Shelly Neu', $presScan, true), implode(' | ', $presScan));
check('Darstellung mit °C-Suffix NICHT gefunden', !in_array('Thermostat Neu', $presScan, true), implode(' | ', $presScan));

echo "\n6d) Kreuz-Instanz-Prüfung: schon in einer ANDEREN virtuellen Instanz verwendete Datenpunkte (Dietmars Anregung 31.08.2026)\n";
// Ein zweiter, unabhängiger virtueller Zähler benutzt "Steckdose Küche" (Var.
// 511) als Term — die darf im Suchlauf einer DRITTEN Instanz nicht mehr als
// unbenutzt erscheinen, auch wenn sie in DEREN eigener Nodes-Liste nicht
// vorkommt. (Direkt über IPS_SetProperty verdrahtet, nicht über den
// Suchlauf — sonst würde der Suchlauf sich seinen eigenen Testzustand
// verändern, bevor er geprüft wird.)
$otherVirtIid = IPS_CreateInstance('{ADF18291-2E60-4354-92F5-B96863C127C8}');
obj($otherVirtIid, 1, 'Zweiter virtueller Zähler', 10);
IPS_SetProperty($otherVirtIid, 'Nodes', json_encode([
    ['Name' => 'Steckdose Küche', 'Factor' => 100, 'PowerID' => 511, 'EnergyImportID' => 0, 'EnergyExportID' => 0],
]));

$normalCap = $run(0, '', false, false);
$normalScan = $foundNames($normalCap);
check('Steckdose Keller (unbenutzt) normal sichtbar', in_array('Steckdose Keller', $normalScan, true), implode(' | ', $normalScan));
check('Steckdose Küche (in einer anderen Instanz verwendet) standardmäßig ausgeblendet', !in_array('Steckdose Küche', $normalScan, true), implode(' | ', $normalScan));
check('Übersprungen-Zähler nennt die andere Instanz', str_contains($normalCap, 'anderen virtuellen Zähler-Instanz'), $normalCap);
$onlyCrossCap = $run(0, '', false, false, true);
$onlyCross = $foundNames($onlyCrossCap);
// Zu diesem Zeitpunkt sind auch Hausanschluss/Wärmepumpe/Wallbox/Wallbox 2/
// Garage (aus Block 1 und 5) bereits in einer anderen Instanz verwendet —
// die gehören zu Recht mit in diese Liste. Geprüft wird hier nur, dass
// "Steckdose Küche" korrekt dazugehört und "Steckdose Keller" (nirgends
// verwendet) korrekt NICHT.
check('Schalter "nur schon verwendete" enthält "Steckdose Küche"', in_array('Steckdose Küche', $onlyCross, true), implode(' | ', $onlyCross));
check('Schalter "nur schon verwendete" lässt unbenutzte Steckdose Keller weg', !in_array('Steckdose Keller', $onlyCross, true), implode(' | ', $onlyCross));
check('Fund-Hinweis nennt den Instanznamen, in der der Zähler schon steckt', str_contains($onlyCrossCap, 'bereits verwendet in „Zweiter virtueller Zähler“'), $onlyCrossCap);

echo "\n7) Rückkopplungsschutz\n";
$feedback = $foundNames($run(0, '', false, false));
$virtOutNames = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $virtOutNames[] = IPS_GetName($c); }
$overlap = array_intersect($feedback, $virtOutNames);
check('keine Ausgabe eines virtuellen Zählers als Quelle', $overlap === [], implode(' | ', $overlap));

echo "\n8) Vertragserweiterung MHUBV_GetFunctions — Funktion jetzt Instanz-Property statt Zeilen-Feld\n";
IPS_SetProperty($new, 'Function', 'house');
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
$gf = json_decode($GLOBALS['MODOBJ'][$new]->GetFunctions(), true);
check('contractVersion = 1.1', ($gf['contractVersion'] ?? '') === '1.1', json_encode($gf['contractVersion'] ?? null));
check('latency = realtime',   ($gf['latency'] ?? '') === 'realtime', json_encode($gf['latency'] ?? null));
check('authority = auxiliary', ($gf['authority'] ?? '') === 'auxiliary');
check('pollInterval gesetzt',  ($gf['pollInterval'] ?? 0) >= 2);
check('genau EINE Zuordnung (Instanz-Funktion statt Zeilen-Funktion)', count($gf['assignments'] ?? []) === 1, json_encode($gf['assignments'] ?? null));
$a0 = $gf['assignments'][0] ?? [];
check('energyKind = counter',  ($a0['energyKind'] ?? '') === 'counter');
check('sourceCount = 3 (alle Formel-Zeilen)', ($a0['sourceCount'] ?? -1) === 3, 'ist ' . ($a0['sourceCount'] ?? 'fehlt'));
// authority/latency MÜSSEN auch je Zuordnung stehen (InverterHub filtert dort).
check('authority je Zuordnung', ($a0['authority'] ?? '') === 'auxiliary');
check('latency je Zuordnung',   ($a0['latency'] ?? '') === 'realtime');

echo "\n9) NRG-Stack-Profile: gemeinsam, aber eigentümerlos\n";
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

echo "\n10) Sicherheitsnetz gegen den 25.07.2026-Vorfall (#16933)\n";
// Nachstellung des realen Vorfalls: eine Instanz mit funktionierender Formel
// (Ausgaben existieren) verliert alle Datenpunkte — vorher wurden dabei ALLE
// Ausgabevariablen auf einen Schlag geloescht, obwohl nur die Verdrahtung
// betroffen war.
$before = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $before[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('Ausgangslage: Ausgaben vorhanden', isset($before['power']), implode(', ', array_keys($before)));

// 10a) Alle Datenpunkt-Felder auf 0 — keine einzige Zeile hat mehr einen
// Zähler. Das MUSS das Sicherheitsnetz auslösen: Status Fehler, Ausgaben
// bleiben unangetastet.
$emptyNodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
foreach ($emptyNodes as &$r) { $r['PowerID'] = 0; $r['EnergyImportID'] = 0; $r['EnergyExportID'] = 0; }
unset($r);
IPS_SetProperty($new, 'Nodes', json_encode($emptyNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10a: Status = Fehler (201)', ($GLOBALS['STATUS'][$new] ?? 0) === 201, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$after10a = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $after10a[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10a: vorhandene Ausgaben NICHT gelöscht', $after10a === $before, 'vorher=' . implode(',', array_keys($before)) . ' nachher=' . implode(',', array_keys($after10a)));
$checkSafetyErr = (new ReflectionMethod('MeterHubVirtual', 'Validate'))->invoke($GLOBALS['MODOBJ'][$new]);
check('10a: Fehlermeldung nennt die Ursache', !empty($checkSafetyErr) && str_contains(implode(' ', $checkSafetyErr), 'keine einzige Ausgabe'), implode(' | ', $checkSafetyErr));

// 10b) Ein echter Fehler (verweist auf gelöschte Variable), unabhängig vom
// Sicherheitsnetz — auch der muss die Löschung verhindern. Ein Zähler bleibt
// dabei bewusst gültig, damit klar ist: nicht das Sicherheitsnetz greift
// hier, sondern die allgemeine Fehlerprüfung.
$badNodes = $emptyNodes;
$badNodes[0]['PowerID'] = 103; // Hausanschluss-Leistung wieder gültig
$badNodes[1]['EnergyImportID'] = 999999; // existiert nicht
IPS_SetProperty($new, 'Nodes', json_encode($badNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10b: Status = Fehler (201)', ($GLOBALS['STATUS'][$new] ?? 0) === 201, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$after10b = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $after10b[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10b: vorhandene Ausgaben weiterhin NICHT gelöscht (allgemeiner Fehlerfall)', $after10b === $after10a, 'nachher=' . implode(',', array_keys($after10b)));

// 10c) Gegenprobe: Formel sauber reparieren — jetzt MUSS RegisterVariables()
// wieder normal arbeiten, sonst würde das Sicherheitsnetz zur Dauerblockade.
$cleanNodes = $badNodes;
$cleanNodes[0]['EnergyImportID'] = 104; // Hausanschluss-Bezug, real vorhanden
$cleanNodes[0]['EnergyExportID'] = 105; // Hausanschluss-Einspeisung, real vorhanden
$cleanNodes[1]['EnergyImportID'] = 204; // Wärmepumpe-Bezug, real vorhanden
$cleanNodes[1]['PowerID'] = 203;
$cleanNodes[2]['PowerID'] = 303;
$cleanNodes[2]['EnergyImportID'] = 304;
IPS_SetProperty($new, 'Nodes', json_encode($cleanNodes));
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
check('10c: nach Reparatur wieder aktiv (102)', ($GLOBALS['STATUS'][$new] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$new] ?? '-'));
$after10c = [];
foreach (IPS_GetChildrenIDs($new) as $c) { $after10c[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('10c: Ausgaben nach Reparatur weiter vorhanden', isset($after10c['power']), implode(',', array_keys($after10c)));

echo "\n11) Formular-Konvention: News-Panel + Doku-Panel (aktualisiert für das flache Modell, 31.08.2026)\n";
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
check('Doku-Panel erklärt Anteil statt Baum', str_contains($dokuText, 'Anteil') && !str_contains($dokuText, 'hängt hinter'), $dokuText);
check('Doku-Panel nennt Verkettung für mehrstufige Fälle', str_contains($dokuText, 'mehrere Instanzen') || str_contains($dokuText, 'verketteten'), $dokuText);
check('Doku-Panel erklärt die Aufteilung eines Zählers (PV-Quotierung/Mieter)', str_contains($dokuText, 'Aufteilen') && str_contains($dokuText, 'Quotierung'), $dokuText);

$meterPanel = null;
foreach ($form11['elements'] ?? [] as $el) { if (($el['caption'] ?? '') === '🔌  Zähler') { $meterPanel = $el; } }
check('Zähler-Panel vorhanden (vorher "Verdrahtung")', $meterPanel !== null);
$listField = null;
foreach ($meterPanel['items'] ?? [] as $it) { if (($it['name'] ?? '') === 'Nodes') { $listField = $it; } }
check('Formel-Liste hat eine Anteil(%)-Spalte statt "hängt hinter"', $listField !== null && in_array('Factor', array_column($listField['columns'] ?? [], 'name'), true), json_encode(array_column($listField['columns'] ?? [], 'name')));
check('kein Kürzel-Spalte mehr', !in_array('Key', array_column($listField['columns'] ?? [], 'name'), true));
check('Liste erlaubt Drag & Drop zum Umsortieren', ($listField['changeOrder'] ?? false) === true, json_encode($listField));
$funcField = null;
foreach ($form11['elements'] ?? [] as $el) { if (($el['name'] ?? '') === 'Function') { $funcField = $el; } }
check('Funktion jetzt ein Instanz-Feld im Formular (nicht mehr Zeilen-Spalte)', $funcField !== null && ($funcField['type'] ?? '') === 'Select');

$GLOBALS['MODOBJ'][$new]->AckNews();
check('AckNews() merkt die Version dauerhaft', ($GLOBALS['ATTR'][$new]['SeenNews'] ?? null) === $newsVersion, 'ist ' . ($GLOBALS['ATTR'][$new]['SeenNews'] ?? '(leer)'));
check('AckNews() blendet das Panel im offenen Formular sofort aus', ($GLOBALS['FORMFIELDS']['NewsPanel']['visible'] ?? null) === false);
$form11b = json_decode($GLOBALS['MODOBJ'][$new]->GetConfigurationForm(), true);
$panelCaptions2 = array_column($form11b['elements'] ?? [], 'caption');
check('News-Panel bleibt nach Bestätigung auch bei einem Neuaufbau weg', !in_array('🆕  Neu in dieser Version', $panelCaptions2, true), implode(' | ', $panelCaptions2));

echo "\n12) Migration einer alten Baum-Verdrahtung (Kürzel/„hängt hinter“) ins neue flache Modell\n";
// Simuliert exakt Dietmars Live-Zustand: eine Instanz mit Nodes im ALTEN
// Format lädt nach dem Modul-Update. Sicherheitsnetz: NICHTS automatisch
// übernehmen — sonst würde beim ersten ApplyChanges nach dem Update sofort
// jede lose Kandidatenzeile mitsummiert (still falscher Wert, live genutzte
// Anlage).
$legacyRows = [
    ['Key' => 'hausanschluss', 'Name' => 'Hausanschluss', 'Parent' => '', 'PowerID' => 103, 'EnergyImportID' => 104, 'EnergyExportID' => 105, 'Function' => 'grid'],
    ['Key' => 'waermepumpe', 'Name' => 'Wärmepumpe', 'Parent' => 'hausanschluss', 'PowerID' => 203, 'EnergyImportID' => 204, 'EnergyExportID' => 0, 'Function' => 'heatpump'],
    ['Key' => 'staubsauger', 'Name' => 'Staubsauger', 'Parent' => '', 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 0, 'Function' => 'none'],
];
$legIid = IPS_CreateInstance('{ADF18291-2E60-4354-92F5-B96863C127C8}');
obj($legIid, 1, 'Migrationstest', 10);
IPS_SetProperty($legIid, 'Nodes', json_encode($legacyRows));
IPS_ApplyChanges($legIid);
check('12a: Status = Migration nötig (202), nichts wird berechnet', ($GLOBALS['STATUS'][$legIid] ?? 0) === 202, 'Status=' . ($GLOBALS['STATUS'][$legIid] ?? '-'));
check('12a: keine Ausgabevariablen angelegt, solange Migration offen ist', IPS_GetChildrenIDs($legIid) === [], implode(',', IPS_GetChildrenIDs($legIid)));

$legForm = json_decode($GLOBALS['MODOBJ'][$legIid]->GetConfigurationForm(), true);
$migPanel = null;
foreach ($legForm['elements'] ?? [] as $el) { if (($el['name'] ?? '') === 'MigrationPanel') { $migPanel = $el; } }
check('12b: Migrations-Panel erscheint im Formular', $migPanel !== null);
check('12b: News-Panel bleibt während offener Migration weg (Migration hat Vorrang)', !in_array('🆕  Neu in dieser Version', array_column($legForm['elements'] ?? [], 'caption'), true));
$legListField = null;
foreach ($legForm['elements'] ?? [] as $el) {
    if (($el['caption'] ?? '') === '🔌  Zähler') {
        foreach ($el['items'] ?? [] as $it) { if (($it['name'] ?? '') === 'Nodes') { $legListField = $it; } }
    }
}
check('12b: Liste bekommt die migrierten Zeilen als Vorschlag (noch nicht gespeichert)', $legListField !== null && isset($legListField['value']));
$proposed = json_decode($legListField['value'] ?? '[]', true);
check('12b: alle drei alten Zeilen stehen als Vorschlag da, Anteil 100', count($proposed) === 3 && array_unique(array_column($proposed, 'Factor')) === [100], json_encode($proposed));
check('12b: Kürzel/„hängt hinter"/Funktion sind aus dem Vorschlag verschwunden', !array_key_exists('Key', $proposed[0]) && !array_key_exists('Parent', $proposed[0]) && !array_key_exists('Function', $proposed[0]));

// Dietmar prüft den Vorschlag: den nie verdrahteten "Staubsauger" (Muster
// eines alten Suchlauf-Fundes, der nie tatsächlich Teil der Formel wurde)
// wirft er raus, den Rest bestätigt er mit "Übernehmen".
$confirmed = array_values(array_filter($proposed, fn ($r) => $r['Name'] !== 'Staubsauger'));
IPS_SetProperty($legIid, 'Nodes', json_encode($confirmed));
IPS_ApplyChanges($legIid);
check('12c: nach Bestätigung normaler Betrieb (102)', ($GLOBALS['STATUS'][$legIid] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$legIid] ?? '-'));
$legOuts = [];
foreach (IPS_GetChildrenIDs($legIid) as $c) { $legOuts[$GLOBALS['OBJ'][$c]['ObjectIdent']] = $c; }
check('12c: Ausgaben entstehen nach der Migration', isset($legOuts['power']), implode(',', array_keys($legOuts)));
$GLOBALS['MODOBJ'][$legIid]->Recalc();
check('12c: Summe (beide "+") = 5000 + 1200', abs(GetValue($legOuts['power']) - 6200.0) < 0.01, 'ist ' . GetValue($legOuts['power']));

echo "\n13) Standort — freies Label, getrennt vom Dashboard-Vertrag \"Function\" (Dietmars Auftrag 31.08.2026)\n";
// $new hat aus Block 8 bereits Function='house'; Location ist bislang leer.
$form13 = json_decode($GLOBALS['MODOBJ'][$new]->GetConfigurationForm(), true);
$locPreset = null;
$locField = null;
foreach ($form13['elements'] ?? [] as $el) {
    if (($el['name'] ?? '') === 'LocationPreset') { $locPreset = $el; }
    if (($el['name'] ?? '') === 'Location')       { $locField = $el; }
}
check('13a: Standort-Freitextfeld vorhanden', $locField !== null && ($locField['type'] ?? '') === 'ValidationTextBox');
check('13a: Standort-Vorschlagsliste vorhanden (noch ohne Einträge, frisches System)', $locPreset !== null && count($locPreset['options'] ?? []) === 1, json_encode($locPreset['options'] ?? null));

IPS_SetProperty($new, 'Location', 'Keller');
$GLOBALS['MODOBJ'][$new]->ApplyChanges();
$form13b = json_decode($GLOBALS['MODOBJ'][$sib]->GetConfigurationForm(), true);
$locPreset2 = null;
foreach ($form13b['elements'] ?? [] as $el) { if (($el['name'] ?? '') === 'LocationPreset') { $locPreset2 = $el; } }
check('13b: Vorschlag "Keller" taucht bei einer ANDEREN Instanz auf (wächst mit echter Nutzung)', in_array('Keller', array_column($locPreset2['options'] ?? [], 'value'), true), json_encode($locPreset2['options'] ?? null));

$GLOBALS['FORMFIELDS'] = [];
$GLOBALS['MODOBJ'][$sib]->ApplyLocationPreset('Keller');
check('13c: ApplyLocationPreset() übernimmt den Vorschlag ins Freitextfeld (nur im offenen Formular, noch nicht gespeichert)', ($GLOBALS['FORMFIELDS']['Location']['value'] ?? null) === 'Keller');
check('13c: gespeicherte Property bleibt unangetastet, bis "Übernehmen" geklickt wird', IPS_GetProperty($sib, 'Location') === '');

$GLOBALS['FORMFIELDS'] = [];
$GLOBALS['MODOBJ'][$sib]->ApplyLocationPreset('');
check('13d: leerer Vorschlag (Platzhalter "— Vorschlag wählen —") tut nichts', !array_key_exists('value', $GLOBALS['FORMFIELDS']['Location'] ?? []));

echo "\n14) Prüfung & Vorschau zeigt die aktuellen Live-Werte, nicht nur die Struktur (Dietmars Anregung 31.08.2026)\n";
$form14 = json_decode($GLOBALS['MODOBJ'][$new]->GetConfigurationForm(), true);
$checkPanel14 = null;
foreach ($form14['elements'] ?? [] as $el) { if (($el['caption'] ?? '') === '🔎  Prüfung & Vorschau') { $checkPanel14 = $el; } }
check('14: Prüfung-Panel vorhanden', $checkPanel14 !== null);
$checkText14 = implode(' ', array_column($checkPanel14['items'] ?? [], 'caption'));
check('14: Leistung zeigt die Einzelwerte je Zeile', str_contains($checkText14, '5.000 W') && str_contains($checkText14, '1.200 W') && str_contains($checkText14, '1.800 W'), $checkText14);
check('14: Leistung zeigt das Rechenergebnis (5000 − 1200 − 1800 = 2000 W)', str_contains($checkText14, '2.000 W'), $checkText14);
check('14: Bezug zeigt das Rechenergebnis (26.000,0 kWh)', str_contains($checkText14, '26.000,0 kWh'), $checkText14);

echo "\n15) Zähler aufteilen: Anteil (%) statt nur +/− — Dietmars PV-Quotierungs-/Mieter-Fall (31.08.2026)\n";
// "Wenn eine PV-Anlage mehrere PV-Anlagen mit unterschiedlichem Baujahr hat,
// bekommt sie die Einspeisevergütung aus der Quotierung" — dieselbe
// Einspeisungs-Variable (#105, Wert 8000.0) wird anteilig auf zwei
// UNABHÄNGIGE Instanzen aufgeteilt (60 % / 40 %), z. B. zwei Mieter.
$mieterA = IPS_CreateInstance('{ADF18291-2E60-4354-92F5-B96863C127C8}');
obj($mieterA, 1, 'Mieter A', 10);
IPS_SetProperty($mieterA, 'Nodes', json_encode([
    ['Name' => 'Einspeisung Haus (60 %)', 'Factor' => 60, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 105],
]));
IPS_ApplyChanges($mieterA);
$mieterB = IPS_CreateInstance('{ADF18291-2E60-4354-92F5-B96863C127C8}');
obj($mieterB, 1, 'Mieter B', 10);
IPS_SetProperty($mieterB, 'Nodes', json_encode([
    ['Name' => 'Einspeisung Haus (40 %)', 'Factor' => 40, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 105],
]));
IPS_ApplyChanges($mieterB);

check('15a: Mieter A bleibt fehlerfrei — dieselbe Variable in zwei Instanzen ist ausdrücklich erlaubt', ($GLOBALS['STATUS'][$mieterA] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$mieterA] ?? '-'));
check('15a: Mieter B ebenfalls fehlerfrei', ($GLOBALS['STATUS'][$mieterB] ?? 0) === 102, 'Status=' . ($GLOBALS['STATUS'][$mieterB] ?? '-'));
$GLOBALS['MODOBJ'][$mieterA]->Recalc();
$GLOBALS['MODOBJ'][$mieterB]->Recalc();
$outA = (int)@IPS_GetObjectIDByIdent('energy_export', $mieterA);
$outB = (int)@IPS_GetObjectIDByIdent('energy_export', $mieterB);
check('15b: Mieter A bekommt 60 % von 8000 = 4800', abs(GetValue($outA) - 4800.0) < 0.01, 'ist ' . GetValue($outA));
check('15b: Mieter B bekommt 40 % von 8000 = 3200', abs(GetValue($outB) - 3200.0) < 0.01, 'ist ' . GetValue($outB));

echo "\n  15c) Gegenprobe: dieselbe Variable ZWEIMAL in DERSELBEN Instanz bleibt ein Fehler (Doppelzählung)\n";
IPS_SetProperty($mieterA, 'Nodes', json_encode([
    ['Name' => 'Einspeisung Haus (60 %)', 'Factor' => 60, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 105],
    ['Name' => 'Einspeisung Haus (nochmal)', 'Factor' => 10, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 105],
]));
$errA = (new ReflectionMethod('MeterHubVirtual', 'Validate'))->invoke($GLOBALS['MODOBJ'][$mieterA]);
check('15c: innerhalb einer Instanz weiterhin ein Fehler', !empty($errA) && str_contains(implode(' ', $errA), 'doppelt gerechnet'), implode(' | ', $errA));
// Zustand zurücksetzen, damit nachfolgende Blöcke (falls je erweitert) sauber starten.
IPS_SetProperty($mieterA, 'Nodes', json_encode([
    ['Name' => 'Einspeisung Haus (60 %)', 'Factor' => 60, 'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 105],
]));
IPS_ApplyChanges($mieterA);

echo "\n  15d) \"Prüfung & Vorschau\" zeigt den Anteil UND den anteiligen Beitrag, nicht nur +/−\n";
$formA = json_decode($GLOBALS['MODOBJ'][$mieterA]->GetConfigurationForm(), true);
$checkPanelA = null;
foreach ($formA['elements'] ?? [] as $el) { if (($el['caption'] ?? '') === '🔎  Prüfung & Vorschau') { $checkPanelA = $el; } }
$checkTextA = implode(' ', array_column($checkPanelA['items'] ?? [], 'caption'));
check('15d: zeigt "× 60 %"', str_contains($checkTextA, '× 60 %'), $checkTextA);
check('15d: zeigt Rohwert UND anteiligen Beitrag (8.000,0 kWh → 4.800,0 kWh)', str_contains($checkTextA, '8.000,0 kWh → 4.800,0 kWh'), $checkTextA);

echo "\n16) MHUB_CreateVirtual() schreibt jetzt \"Factor\" statt \"Sign\" (Brücke im Hauptmodul aktualisiert)\n";
$bridgeNodes = json_decode(IPS_GetProperty($new, 'Nodes'), true);
check('16: Zeilen haben Factor-Feld', array_key_exists('Factor', $bridgeNodes[0] ?? []), json_encode($bridgeNodes[0] ?? null));
check('16: kein Sign-Feld mehr in frisch erzeugten Zeilen', !array_key_exists('Sign', $bridgeNodes[0] ?? []), json_encode($bridgeNodes[0] ?? null));

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
