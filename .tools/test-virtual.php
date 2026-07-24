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
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = $v; }
    protected function ReloadForm() {}
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
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

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
