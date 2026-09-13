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
    protected function RegisterPropertyFloat($n, $v)   { $this->defs[$n] = $v; }
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? false); }
    public function ReadPropertyFloat($n)   { return (float)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0.0); }
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

// ---------------------------------------------------------------------------
echo "\n6) Umschalten ohne Sprung (0.27.0): die Variablen tauschen ihre Rolle, jede behält ihren Zähler (Stände wie PAC2200 am 26.08.2026)\n";
obj(500, 1, 'PAC', 0);
$catE5 = obj(501, 0, 'Energie', 500, 'cat_energy');
vari(502, 'Bezug', $catE5, 'energy_import', 40424.2);
vari(503, 'Abgabe', $catE5, 'energy_export', 89036.5);
vari(507, 'Bezug T2', $catE5, 'energy_import_t2', 0.0);   // ohne Gegenrichtung → kein Paar
$catF5 = obj(504, 0, 'Funktionen', 500, 'cat_function');
vari(505, 'Netz — Bezug', $catF5, 'fn_grid_import', 40424.2);
vari(506, 'Netz — Einspeisung', $catF5, 'fn_grid_export', 89036.5);
vari(508, 'Netz — Leistung', $catF5, 'fn_grid_power', 0.0);
$hub5 = new MeterHub(500);
$hub5->Create();
$sync = new ReflectionMethod('MeterHub', 'SyncInvertLayout');
$sync->invoke($hub5);
check('erster Start mit 0.27.0: bestehende Anordnung übernommen, nichts getauscht', ident(502) === 'energy_import' && ident(505) === 'fn_grid_import' && $GLOBALS['ATTR'][500]['InvertLayout'] === 'off');
$GLOBALS['PROP'][500]['PowerInvert'] = true;
$sync->invoke($hub5);
check('eingeschaltet: Energie- und Sammel-Paar tauschen die Idents', ident(502) === 'energy_export' && ident(503) === 'energy_import' && ident(505) === 'fn_grid_export' && ident(506) === 'fn_grid_import');
check('Namen wandern mit, Einzelvariablen bleiben', IPS_GetName(502) === 'Abgabe' && ident(507) === 'energy_import_t2' && ident(508) === 'fn_grid_power');
check('Meldung im Protokoll nennt die Paare', isset($GLOBALS['LOG']) && str_contains(end($GLOBALS['LOG']), '#502 ↔ #503'));
$hub5->SetVarEnergykWh('energy_import', 40424.3); // Zählerregister „Bezug", nächste Lesung
$hub5->SetVarEnergykWh('energy_export', 89036.6);
check('jedes Register zählt ohne Sprung in derselben Variable weiter', abs(GetValue(502) - 40424.3) < 1e-6 && abs(GetValue(503) - 89036.6) < 1e-6, GetValue(502) . ' / ' . GetValue(503));
$GLOBALS['PROP'][500]['PowerInvert'] = false;
$sync->invoke($hub5);
check('zurückgeschaltet: alles wie vorher', ident(502) === 'energy_import' && ident(503) === 'energy_export' && ident(505) === 'fn_grid_import');
$sync->invoke($hub5);
check('erneutes Übernehmen ohne Änderung tauscht nicht noch einmal', ident(502) === 'energy_import');

// ---------------------------------------------------------------------------
echo "\n7) Archiv-Korrektur Energie: vertauschte Abschnitte erkennen (RetrackStep)\n";
$rt = fn(...$a) => (new ReflectionMethod('MeterHub', 'RetrackStep'))->invoke(null, ...$a);
$cw = fn(...$a) => (new ReflectionMethod('MeterHub', 'CrossWindows'))->invoke(null, ...$a);
$st = ['lvl' => [40424.0, 89036.0], 'on' => [0, 1], 'x' => []];
[$st7, $as7] = $rt($st, [
    [100, 40424.2, 0], [100, 89036.5, 1],
    [200, 89036.6, 0], [200, 40424.3, 1],   // Umschalten: beide springen auf den Stand des anderen
    [300, 89037.0, 0], [300, 40425.0, 1],
    [400, 40425.1, 0], [400, 89037.1, 1],   // zurück
    [500, 40425.2, 0], [500, 89037.2, 1],
]);
$mv7 = array_values(array_filter($as7, fn($r) => $r[2] !== $r[3]));
check('7: genau die 4 Werte im vertauschten Abschnitt wechseln die Variable', count($mv7) === 4 && $mv7[0][0] === 200 && $mv7[3][0] === 300, json_encode($mv7));
check('7: danach wieder normal, beide Variablen einig', $st7['on'] === [0, 1] && count($st7['x']) === 4);
check('7: Abschnitt 200–400', $cw($st7['x'], 999) === [[200, 400, false]], json_encode($cw($st7['x'], 999)));
[$st8, $as8] = $rt(['lvl' => [151893760.0, 19574237184.0], 'on' => [0, 1], 'x' => []],
    [[10, 152421.2, 0], [10, 19735744.5, 1], [20, 152500.0, 0], [20, 19740000.0, 1]]);
check('7: Einheitenwechsel Wh→kWh (Solarpark 03.–06.09.) ist kein Kreuzsprung', $st8['x'] === [] && !array_filter($as8, fn($r) => $r[2] !== $r[3]));
[$st9] = $rt(['lvl' => [40424.0, 89036.0], 'on' => [0, 1], 'x' => []],
    [[100, 89036.5, 0], [150, 89036.7, 0], [2000, 40424.6, 1], [2100, 89037.0, 0], [2100, 40424.8, 1]]);
check('7: Zählerschutz verzögert die zweite Seite — trotzdem sauber erkannt, bis heute vertauscht', $st9['on'] === [1, 0], json_encode($st9['on']));
check('7: offener Abschnitt reicht bis heute', $cw($st9['x'], 999) === [[100, 999, true]]);
[$st10] = $rt(['lvl' => [40424.0, 89036.0], 'on' => [0, 1], 'x' => []], [[100, 89036.5, 0], [200, 89037.0, 1]]);
check('7: nur eine Seite gewechselt → als unklar erkennbar', $st10['on'][0] === $st10['on'][1]);
[$st11] = $rt(['lvl' => [5000.0, 5100.0], 'on' => [0, 1], 'x' => []], [[1, 5100.2, 0], [1, 5000.1, 1]]);
check('7: fast gleiche Stände sind nicht unterscheidbar → kein Wechsel', $st11['x'] === []);
[, $as12] = $rt(['lvl' => [40424.0, 89036.0], 'on' => [0, 1], 'x' => []], [[1, 0.0, 0], [2, 40424.5, 0]]);
check('7: Nullwert bleibt in seiner Variable', $as12[0][2] === 0 && $as12[0][3] === 0);

// ---------------------------------------------------------------------------
echo "\n8) Archiv-Korrektur Leistung und Richtungsprüfung: reine Rechenschritte\n";
$m8 = fn($name, ...$a) => (new ReflectionMethod('MeterHub', $name))->invoke(null, ...$a);
check('8: Viertelstunde passt / gegenläufig / zu wenig Leistung',
    $m8('BucketVerdict', 500.0, 400.0, 100.0) === 1 && $m8('BucketVerdict', 500.0, -400.0, 100.0) === -1 && $m8('BucketVerdict', 50.0, 400.0, 100.0) === 0);
$v8 = [];
foreach ([1, 1, 0, -1, -1, 0, 0, -1, -1, 1, 1, -1, 1] as $k => $x) { $v8[] = [$k * 900, $x]; }
check('8: gegenläufige Viertelstunden zu Abschnitten, kurze Lücken überbrückt, Einzelne verworfen', $m8('FindMismatchWindows', $v8, 900, 43200, 2) === [[2700, 8100]], json_encode($m8('FindMismatchWindows', $v8, 900, 43200, 2)));
$v8n = [];
foreach (array_merge([-1, -1], array_fill(0, 27, 0), [-1, -1]) as $k => $x) { $v8n[] = [$k * 900, $x]; }
check('8: Nacht mit zu wenig Leistung (PAC2200 27.07. 01:14–08:04) wird überbrückt', $m8('FindMismatchWindows', $v8n, 900, 43200, 2) === [[0, 31 * 900]], json_encode($m8('FindMismatchWindows', $v8n, 900, 43200, 2)));
$v8b = [];
foreach (array_merge([-1, -1], array_fill(0, 50, 0), [-1, -1]) as $k => $x) { $v8b[] = [$k * 900, $x]; }
check('8: unklare Strecke über 12 h trennt zwei Abschnitte', count($m8('FindMismatchWindows', $v8b, 900, 43200, 2)) === 2);
$v8p = [[0, -1], [900, -1], [1800, 0], [2700, 1], [3600, 0], [4500, -1], [5400, -1]];
check('8: eine passende Viertelstunde dazwischen trennt immer', count($m8('FindMismatchWindows', $v8p, 900, 43200, 2)) === 2);
$pts8 = [[10, false], [20, false], [30, false], [40, true], [50, true], [60, false], [70, true], [80, true]];
check('8: Umschaltpunkt am Anfang eines Abschnitts', $m8('ChangePoint', $pts8, true) === 40, (string)$m8('ChangePoint', $pts8, true));
check('8: Umschaltpunkt am Ende eines Abschnitts', $m8('ChangePoint', [[10, true], [20, true], [30, false], [40, false]], false) === 30);
check('8: ohne Werte keine Kante', $m8('ChangePoint', [], true) === null);
$sm8 = $m8('SegmentMeans', [[450, 300.0]], 100.0, 0, 900, 900);
check('8: zeitgewichteter Mittelwert mit Übertrag', isset($sm8[0]) && abs($sm8[0] - 200.0) < 1e-9, json_encode($sm8));
check('8: Zählerstand an den Viertelstunden-Grenzen', $m8('SampleHold', [[100, 5.0], [950, 6.0]], 4.0, 0, 1800, 900) === [0 => 4.0, 900 => 5.0, 1800 => 6.0]);
[$c8, $n8] = $m8('CosinePairs', [0 => 1000.0, 300 => -2000.0, 600 => 50.0], [0 => -900.0, 300 => 2100.0, 600 => -3000.0], 100.0);
check('8: gegenläufige Netzzähler → Gleichlauf ≈ −1, kleine Werte zählen nicht', $c8 < -0.99 && $n8 === 2, json_encode([$c8, $n8]));
[$p8, $pn8] = $m8('PearsonPairs', [0 => 2000.0, 1 => 500.0, 2 => -1500.0, 3 => 1800.0], [0 => 400.0, 1 => 2000.0, 2 => 4000.0, 3 => 100.0], 300.0);
check('8: Netz fällt, wenn PV steigt → negative Korrelation, nur Zeiten mit PV', $p8 < -0.9 && $pn8 === 3, json_encode([$p8, $pn8]));
check('8: Bewertung Netzquelle', $m8('DirectionLevel', 0.97, 'opposite') === ['normal', 0.6] && $m8('DirectionLevel', -0.9, 'same') === ['kritisch', 0.6]
    && $m8('DirectionLevel', 0.1, 'same') === [null, 0.6]);
check('8: Bewertung PV — schwach gegenläufig nur auffällig, stark gegenläufig kritisch', $m8('DirectionLevel', -0.5, 'pv') === ['auffaellig', 0.3]
    && $m8('DirectionLevel', -0.9, 'pv') === ['kritisch', 0.3] && $m8('DirectionLevel', 0.5, 'pv') === ['normal', 0.3] && $m8('DirectionLevel', 0.1, 'pv') === [null, 0.3]);
[$pa8, $pan8] = $m8('PearsonPairs', [0 => -9000.0, 1 => -4000.0, 2 => -500.0], [0 => 9100.0, 1 => 4050.0, 2 => 600.0], 300.0);
check('8: Solarpark richtig herum: Netz = −PV → Korrelation −1, also „passt"', $pa8 < -0.99 && $m8('DirectionLevel', -$pa8, 'pv')[0] === 'normal');
check('8: Gegen-Idents', $m8('CounterpartIdent', 'energy_import_t2') === 'energy_export_t2' && $m8('CounterpartIdent', 'fn_wallbox1_export') === 'fn_wallbox1_import' && $m8('CounterpartIdent', 'power_total') === null);

// ---------------------------------------------------------------------------
echo "\n9) Verdichtung neu bilden: nur eine gleichzeitig (live 13.09.2026 „Eine andere Aggregation wird aktuell durchgeführt\")\n";
$ra = new ReflectionMethod('MeterHub', 'ReAggregate');
$pq = new ReflectionMethod('MeterHub', 'ProcessReAggQueue');
$GLOBALS['AGG'] = [];
$GLOBALS['AGG_BUSY'] = false;
$ra->invoke($hub5, 1, 502);                 // erste läuft an
$GLOBALS['AGG_BUSY'] = true;                // jetzt ist eine in Arbeit
$ra->invoke($hub5, 1, 503);
$ra->invoke($hub5, 1, 505);
$ra->invoke($hub5, 1, 503);                 // doppelt → nur einmal in der Schlange
check('9: erste sofort, zwei weitere warten, keine doppelt', $GLOBALS['AGG'] === [502] && $GLOBALS['ATTR'][500]['ReAggQueue'] === '[503,505]', $GLOBALS['ATTR'][500]['ReAggQueue'] ?? '');
$GLOBALS['ACS'] = [1];
$pq->invoke($hub5);
check('9: solange noch eine läuft, bleibt die Schlange stehen', $GLOBALS['ATTR'][500]['ReAggQueue'] === '[503,505]');
$GLOBALS['AGG_BUSY'] = false;
$pq->invoke($hub5);
check('9: je Lesezyklus eine weiter', $GLOBALS['AGG'] === [502, 503] && $GLOBALS['ATTR'][500]['ReAggQueue'] === '[505]');
$pq->invoke($hub5);
check('9: Schlange leer, alle neu verdichtet', $GLOBALS['AGG'] === [502, 503, 505] && $GLOBALS['ATTR'][500]['ReAggQueue'] === '[]');
$GLOBALS['ATTR'][500]['ReAggQueue'] = '[999999]';
$pq->invoke($hub5);
check('9: gelöschte Variable fällt aus der Schlange', $GLOBALS['ATTR'][500]['ReAggQueue'] === '[]');
unset($GLOBALS['ACS']);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
