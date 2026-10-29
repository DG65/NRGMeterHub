<?php
/**
 * Prüfstand für die MigrationsHub-Kopplung in MeterHubDiscovery
 * (LegacyCandidateFor()/PrepareMigration(), Verbund-Konvention 29.07.2026).
 * Bildet die nötigen IPS-Funktionen nach und simuliert MIGHUB_* wahlweise
 * vorhanden/fehlend — ein Syntaxcheck sieht weder das function_exists()-
 * Verhalten noch die Feldnamen-Kette zwischen Formular und Backend.
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
    public function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFIELDS'][$f][$p] = $v; }
    protected function ReloadForm() {}
    protected function RegisterVariableBoolean($n, $c, $p, $pos) {}
    protected function SetStatus($s) {}
    public function GetValue($n) { return false; }
    public function SetValue($n, $v) {}
    protected function RegisterTimer($n, $i, $s) {}
    protected function SendDebug($sender, $msg, $format) {}
}

require_once dirname(__DIR__) . '/MeterHubDiscovery/module.php';

const G_METER  = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
const G_MIGHUB = '{330717BB-E309-41A2-90A8-FDA3179ED948}';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}


// Rekursive Suche über ALLE items (SUITE.md-Falle: ein Label in einem ExpansionPanel wurde beim
// Szenariorechner nie ersetzt, weil nur die oberste Ebene durchsucht wurde).
function findEl(array $els, string $name) {
    foreach ($els as $e) {
        if (is_array($e) && ($e['name'] ?? null) === $name) { return $e; }
        foreach (['items', 'elements'] as $k) {
            if (isset($e[$k]) && is_array($e[$k])) { $r = findEl($e[$k], $name); if ($r) { return $r; } }
        }
    }
    return null;
}
function migLine($d): string {
    $f = json_decode($d->GetConfigurationForm(), true);
    $e = is_array($f) ? findEl($f['elements'] ?? [], 'MigrationStatusLine') : null;
    return $e === null ? '' : (string)($e['caption'] ?? '');
}
// ---------------------------------------------------------------------------
echo "\n1) Ohne MigrationsHub (function_exists false)\n";
$d = new MeterHubDiscovery(1);
$d->Create();
$ref = new ReflectionMethod($d, 'LegacyCandidateFor');
$legacy = $ref->invoke($d, '10.0.0.5', 1);
check('kein Treffer ohne MigrationsHub-Funktionen', $legacy === ['id' => 0, 'name' => '']);

$d->PrepareMigration();
check('Meldung: MigrationsHub nicht installiert', str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', 'nicht installiert'));
check('Statuszeile im ausgelieferten Formular (ℹ️): MigrationsHub nicht installiert, Migration entfällt', str_starts_with(migLine($d), 'ℹ️') && str_contains(migLine($d), 'nicht installiert') && str_contains(migLine($d), 'entfällt'), migLine($d));

// ---------------------------------------------------------------------------
// Ab hier MIGHUB_* simulieren, als wäre MigrationsHub installiert. In eine
// Bedingung verpackt, sonst hebt PHP die Funktionsdefinition beim Parsen an
// den Dateianfang und Abschnitt 1 könnte sie nie als "fehlend" sehen.
$GLOBALS['MIGHUB_PREFILL_CALLS'] = [];
$GLOBALS['MIGHUB_FIND_CALLS'] = [];
if (!function_exists('MIGHUB_FindLegacyCandidates')) {
    // Spiegelt die ECHTE Kernel-Wrapper-Signatur: 5 Parameter, ALLE Pflicht —
    // PREFIX_-Wrapper honorieren PHP-Defaults nicht (SUITE.md-Stolperstein;
    // genau so brach der 4-Arg-Aufruf am 30.08.2026 live Dietmars Formular).
    function MIGHUB_FindLegacyCandidates($id, string $host, int $port, int $unitId, int $excludeInstanceID): array {
        $GLOBALS['MIGHUB_FIND_CALLS'][] = [$id, $host, $port, $unitId, $excludeInstanceID];
        if ($host === '10.0.0.5' && $unitId === 1 && $excludeInstanceID !== 555) {
            return [['instanceID' => 555, 'name' => 'Alter Zähler (Fremdmodul)']];
        }
        return [];
    }
    function MIGHUB_PrefillMigration($id, $oldInstanceID, $newInstanceID): void {
        $GLOBALS['MIGHUB_PREFILL_CALLS'][] = [$id, $oldInstanceID, $newInstanceID];
    }
}

echo "\n1b) Funktionen vorhanden, aber keine MigrationsHub-INSTANZ -> kein Treffer, kein Fehlaufruf\n";
// LegacyCandidateFor() braucht eine MigrationsHub-Instanz als Dispatch-Ziel
// (der Kernel-Wrapper dispatcht auf die übergebene Instanz-ID) und legt
// bewusst keine an — GetConfigurationForm() darf keine Instanzen erzeugen.
$legacy = $ref->invoke($d, '10.0.0.5', 1);
check('kein Treffer ohne MigrationsHub-Instanz', $legacy === ['id' => 0, 'name' => '']);
check('kein MIGHUB-Aufruf abgesetzt', count($GLOBALS['MIGHUB_FIND_CALLS']) === 0);
check('Statuszeile (ℹ️): installiert, aber noch keine Instanz — sagt, wann sie entsteht', str_starts_with(migLine($d), 'ℹ️') && str_contains(migLine($d), 'noch keine Instanz'), migLine($d));

echo "\n2) Mit MigrationsHub — Alt-Instanz gefunden\n";
obj(555, 1, 'Alter Zähler (Fremdmodul)', 0);
$GLOBALS['INSTMOD'][555] = '{SOME-OTHER-GUID}';
// Vorhandene MigrationsHub-Instanz als Dispatch-Ziel.
$migPre = IPS_CreateInstance(G_MIGHUB);
obj($migPre, 1, 'MigrationsHub Bestand', 0);
check('Statuszeile (✅): MigrationsHub-Instanz mit ID und Name genannt', str_starts_with(migLine($d), '✅') && str_contains(migLine($d), '#' . $migPre) && str_contains(migLine($d), 'MigrationsHub Bestand'), migLine($d));

$legacy = $ref->invoke($d, '10.0.0.5', 1);
check('Alt-Instanz #555 gefunden', $legacy['id'] === 555 && $legacy['name'] === 'Alter Zähler (Fremdmodul)');
check('Aufruf dispatcht auf die MigrationsHub-Instanz, NICHT die eigene',
    ($GLOBALS['MIGHUB_FIND_CALLS'][0][0] ?? 0) === $migPre, json_encode($GLOBALS['MIGHUB_FIND_CALLS'][0] ?? null));
check('excludeInstanceID (5. Argument) wird übergeben', array_key_exists(4, $GLOBALS['MIGHUB_FIND_CALLS'][0] ?? []));
$noMatch = $ref->invoke($d, '10.0.0.9', 1);
check('andere IP liefert nichts', $noMatch === ['id' => 0, 'name' => '']);
// Selbstausschluss: die eigene frisch angelegte Instanz darf nicht als
// Alt-Instanz zurückkommen.
$selfEx = $ref->invoke($d, '10.0.0.5', 1, 555);
check('excludeInstanceID schließt den Kandidaten aus', $selfEx === ['id' => 0, 'name' => '']);

echo "\n3) GetConfigurationForm: Active=false + legacy-Spalte bei Treffer\n";
$GLOBALS['ATTR'][1]['ResultsJSON'] = json_encode([
    ['meter' => 'Siemens PAC2200', 'label' => 'Siemens PAC2200', 'ip' => '10.0.0.5', 'unitId' => 1],
    ['meter' => 'Shelly Pro 3EM',  'label' => 'Shelly Pro 3EM',  'ip' => '10.0.0.9', 'unitId' => 1],
]);
$d2 = new MeterHubDiscovery(1);
$d2->Create();
$form = json_decode($d2->GetConfigurationForm(), true);
check('Formular ist gültiges JSON', is_array($form));
$list = null;
foreach ($form['elements'] ?? [] as $el) {
    foreach ($el['items'] ?? [] as $it) {
        if (($it['name'] ?? '') === 'DiscoveryList') { $list = $it; }
    }
}
check('Configurator-Liste gefunden', $list !== null);
$rowMatch  = $list['values'][0] ?? [];
$rowNoMatch = $list['values'][1] ?? [];
check('Treffer-Zeile: legacy-Text gefüllt', str_contains($rowMatch['legacy'] ?? '', '#555'), json_encode($rowMatch['legacy'] ?? null));
check('Treffer-Zeile: Active=false in create.configuration', ($rowMatch['create']['configuration']['Active'] ?? null) === false);
check('Nicht-Treffer-Zeile: legacy-Text leer', ($rowNoMatch['legacy'] ?? 'x') === '');
check('Nicht-Treffer-Zeile: kein Active-Override', !array_key_exists('Active', $rowNoMatch['create']['configuration'] ?? []));
$legacyCol = null;
foreach ($list['columns'] ?? [] as $c) { if ($c['name'] === 'legacy') { $legacyCol = $c; } }
check('legacy-Spalte sichtbar (MigrationsHub vorhanden)', $legacyCol !== null && $legacyCol['visible'] === true);

echo "\n4) PrepareMigration(): erstellte Instanz + Alt-Instanz -> verknüpft\n";
$target = IPS_CreateInstance(G_METER);
IPS_SetProperty($target, 'Host', '10.0.0.5');
IPS_SetProperty($target, 'UnitId', 1);
IPS_SetProperty($target, 'Active', true); // Nutzer hatte es doch aktiv gelassen

$d3 = new MeterHubDiscovery(1);
$d3->Create();
$d3->PrepareMigration();

check('Ziel-Instanz nachträglich deaktiviert', IPS_GetProperty($target, 'Active') === false);
check('ApplyChanges auf Ziel-Instanz aufgerufen', ($GLOBALS['APPLIED'][$target] ?? 0) === 1);
$migIDs = IPS_GetInstanceListByModuleID(G_MIGHUB);
check('genau eine MigrationsHub-Instanz (vorhandene wiederverwendet, keine zweite angelegt)', count($migIDs) === 1, 'count=' . count($migIDs));
check('PrefillMigration mit korrekten IDs aufgerufen',
    count($GLOBALS['MIGHUB_PREFILL_CALLS']) === 1
    && $GLOBALS['MIGHUB_PREFILL_CALLS'][0][1] === 555
    && $GLOBALS['MIGHUB_PREFILL_CALLS'][0][2] === $target);
$lastFind = end($GLOBALS['MIGHUB_FIND_CALLS']);
check('PrepareMigration übergibt die Zielinstanz als excludeInstanceID', ($lastFind[4] ?? -1) === $target, json_encode($lastFind));
check('OpenObjectButton zeigt auf MigrationsHub-Instanz',
    ($GLOBALS['FORMFIELDS']['BtnOpenMigration']['objectID'] ?? null) === $migIDs[0]
    && ($GLOBALS['FORMFIELDS']['BtnOpenMigration']['visible'] ?? null) === true);
check('Erfolgsmeldung nennt beide Instanzen',
    str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', '#555')
    && str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', '#' . $target));

echo "\n5) Zweiter Aufruf: vorhandene MigrationsHub-Instanz wird wiederverwendet, kein zweiter Treffer nötig\n";
$GLOBALS['MIGHUB_PREFILL_CALLS'] = [];
$d3->PrepareMigration();
check('MigrationsHub-Instanz nicht doppelt angelegt', count(IPS_GetInstanceListByModuleID(G_MIGHUB)) === 1);
check('PrefillMigration erneut aufgerufen (Zeile weiterhin ohne Bestätigung im Test)', count($GLOBALS['MIGHUB_PREFILL_CALLS']) === 1);

echo "\n6) Keine erstellte Zielinstanz vorhanden -> klare Meldung, kein Fehler\n";
// Eigene IP (nicht 10.0.0.5) — sonst kollidiert das mit der in Abschnitt 4
// bereits angelegten Ziel-Instanz, die denselben globalen Zustand teilt.
$d4 = new MeterHubDiscovery(2);
$d4->Create();
$GLOBALS['ATTR'][2]['ResultsJSON'] = json_encode([
    ['meter' => 'Siemens PAC2200', 'label' => 'Siemens PAC2200', 'ip' => '10.0.0.42', 'unitId' => 1],
]);
$d4->PrepareMigration();
check('Meldung: keine passende Kombination', str_contains($GLOBALS['FORMFIELDS']['MigrationResult']['caption'] ?? '', 'Keine passende Kombination'));

// ---------------------------------------------------------------------------
echo "\n7) Mehrere MigrationsHub-Instanzen: nicht raten, sondern warnen\n";
$migTwo = IPS_CreateInstance(G_MIGHUB);
obj($migTwo, 1, 'MigrationsHub Zweit', 0);
check('Statuszeile (⚠️): nennt beide Instanzen und die verwendete', str_starts_with(migLine($d), '⚠️') && str_contains(migLine($d), '#' . $migPre) && str_contains(migLine($d), '#' . $migTwo) && str_contains(migLine($d), 'verwendet wird die erste'), migLine($d));

// ---------------------------------------------------------------------------
echo "\n8) Präfix-Umbenennung MIGHUB_ → GTAUSCH_ (Gerätetausch, 13.10.2026): neuer Präfix zuerst, MIGHUB_ als Rückfall\n";
$GLOBALS['GTAUSCH_FIND_CALLS'] = [];
$GLOBALS['GTAUSCH_PREFILL_CALLS'] = [];
$mighubBefore = count($GLOBALS['MIGHUB_FIND_CALLS']);
$apiPrefix = new ReflectionMethod($d, 'MigrationApiPrefix');
check('8a: nur MIGHUB_ vorhanden → Rückfall auf MIGHUB', $apiPrefix->invoke($d) === 'MIGHUB' && $apiPrefix->invoke($d, true) === 'MIGHUB');
if (!function_exists('GTAUSCH_FindLegacyCandidates')) {
    function GTAUSCH_FindLegacyCandidates($id, string $host, int $port, int $unitId, int $excludeInstanceID): array {
        $GLOBALS['GTAUSCH_FIND_CALLS'][] = [$id, $host, $port, $unitId, $excludeInstanceID];
        if ($host === '10.0.0.5' && $unitId === 1 && $excludeInstanceID !== 555) {
            return [['instanceID' => 555, 'name' => 'Alter Zähler (Fremdmodul)']];
        }
        return [];
    }
}
check('8b: nur GTAUSCH_FindLegacyCandidates (ohne Prefill) → für die Suche GTAUSCH, für die Vorbereitung weiter MIGHUB', $apiPrefix->invoke($d) === 'GTAUSCH' && $apiPrefix->invoke($d, true) === 'MIGHUB');
if (!function_exists('GTAUSCH_PrefillMigration')) {
    function GTAUSCH_PrefillMigration($id, $oldInstanceID, $newInstanceID): void {
        $GLOBALS['GTAUSCH_PREFILL_CALLS'][] = [$id, $oldInstanceID, $newInstanceID];
    }
}
check('8c: beide GTAUSCH_-Funktionen vorhanden → GTAUSCH hat Vorrang', $apiPrefix->invoke($d) === 'GTAUSCH' && $apiPrefix->invoke($d, true) === 'GTAUSCH');
$legacy8 = $ref->invoke($d, '10.0.0.5', 1);
check('8d: Kandidatensuche läuft über GTAUSCH_, nicht mehr über MIGHUB_, mit allen 5 Argumenten', ($legacy8['id'] ?? 0) === 555 && count($GLOBALS['GTAUSCH_FIND_CALLS']) === 1 && count($GLOBALS['MIGHUB_FIND_CALLS']) === $mighubBefore && count($GLOBALS['GTAUSCH_FIND_CALLS'][0]) === 5, json_encode([$legacy8, $GLOBALS['GTAUSCH_FIND_CALLS']]));
check('8e: Statuszeile erkennt die Kopplung auch nur mit GTAUSCH_ (kein „nicht installiert")', !str_contains(migLine($d), 'nicht installiert'), migLine($d));
// Vorbereiten über den neuen Präfix: Ziel-Instanz aus Abschnitt 4 existiert weiter ($target, 10.0.0.5/1)
$GLOBALS['MIGHUB_PREFILL_CALLS'] = [];
$GLOBALS['GTAUSCH_PREFILL_CALLS'] = [];
IPS_SetProperty($target, 'Active', true);
$d8 = new MeterHubDiscovery(1);
$d8->Create();
$d8->PrepareMigration();
check('8f: PrepareMigration ruft GTAUSCH_PrefillMigration mit den richtigen IDs auf, MIGHUB_PrefillMigration nicht', count($GLOBALS['GTAUSCH_PREFILL_CALLS']) === 1 && $GLOBALS['GTAUSCH_PREFILL_CALLS'][0][1] === 555 && $GLOBALS['GTAUSCH_PREFILL_CALLS'][0][2] === $target && count($GLOBALS['MIGHUB_PREFILL_CALLS']) === 0, json_encode([$GLOBALS['GTAUSCH_PREFILL_CALLS'], $GLOBALS['MIGHUB_PREFILL_CALLS']]));

// 9) Formular: Aufbau (SUITE.md 3a, EMS 29.10.2026)
$keys9 = ['range', 'scada', 'create'];
$GLOBALS['PROP'][1]['FormLayout'] = '[]';
$d9 = new MeterHubDiscovery(1);
$d9->Create();
$f9 = function () use ($d9) { return json_decode($d9->GetConfigurationForm(), true); };
$panels9 = function ($form) { return array_values(array_filter($form['elements'], function ($e) { return ($e['type'] ?? '') === 'ExpansionPanel'; })); };
$find9 = function ($form, $needle) use ($panels9) {
    foreach ($panels9($form) as $e) { if (mb_strpos((string)($e['caption'] ?? ''), $needle) !== false) { return $e; } }
    return null;
};
$rows9 = function ($form) use ($find9) {
    foreach ($find9($form, 'Formular: Aufbau')['items'] as $it) { if (($it['name'] ?? '') === 'FormLayout') { return $it['values']; } }
    return null;
};
$order9 = function ($form) use ($panels9) { return array_map(function ($e) { return $e['caption']; }, $panels9($form)); };
$std9 = $f9();
check('9a: Panel „Formular: Aufbau“ vorhanden, eingeklappt, listet die drei Fachpanels in Standardreihenfolge', array_column($rows9($std9), 'Key') === $keys9 && $find9($std9, 'Formular: Aufbau')['expanded'] === false);
$ord = $order9($std9);
$iA = array_search($find9($std9, 'Formular: Aufbau')['caption'], $ord);
$iF = null; $iL = null;
foreach ($ord as $i => $c) { if (mb_strpos($c, 'Feedback im Symcon-Forum') !== false) { $iF = $i; } if (mb_strpos($c, 'Über dieses Modul') !== false) { $iL = $i; } }
check('9b: Aufbau-Panel nach den Fachpanels, vor dem Forum-Hinweis; „Über dieses Modul“ ganz unten', ($iF === null || $iA < $iF) && $iL === count($ord) - 1, json_encode([$iA, $iF, $iL]));
$mk9 = function ($k, $o, $c, $h, $pos) { return ['Key' => $k, 'Panel' => '', 'Opened' => $o, 'Closed' => $c, 'Hidden' => $h, 'Pos' => $pos]; };
$GLOBALS['PROP'][1]['FormLayout'] = json_encode([$mk9('scada', true, false, false, 2), $mk9('create', false, true, false, 3), $mk9('range', false, false, false, 1)]);
$f = $f9();
check('9c: „Aufgeklappt“/„Zugeklappt“ wirken', $find9($f, 'SCADA')['expanded'] === true && $find9($f, 'Erstellen')['expanded'] === false);
$GLOBALS['PROP'][1]['FormLayout'] = json_encode([$mk9('create', true, true, true, 1)]);
$f = $f9();
$hp = $find9($f, 'Erstellen');
check('9d: „Ausgeblendet“ = visible:false, Felder bleiben im Formular, Widerspruch → ausgeblendet', ($hp['visible'] ?? true) === false && count($hp['items']) > 0 && mb_strpos($find9($f, 'Formular: Aufbau')['caption'], '(1 ausgeblendet)') !== false);
check('9d: ausgeblendet, aber das Panel „Suchbereich“ (Rückfall auf Standard) bleibt sichtbar', ($find9($f, 'Suchbereich')['visible'] ?? true) !== false);
$GLOBALS['PROP'][1]['FormLayout'] = json_encode([$mk9('create', false, false, false, 1), $mk9('range', false, false, false, 2), $mk9('scada', false, false, false, 3)]);
$o = array_map(function ($c) { return preg_replace('/\s*\(.*$/u', '', $c); }, $order9($f9()));
$pc = array_search('🛠️  Erstellen', $o); $pr = array_search('🔎  Suchbereich', $o);
check('9e: Position wirkt — „Erstellen“ steht vor „Suchbereich“', $pc !== false && $pr !== false && $pc < $pr, json_encode($o));
$GLOBALS['PROP'][1]['FormLayout'] = json_encode([$mk9('x', false, false, true, 1), $mk9('range', false, true, false, 1)]);
check('9f: unbekannter Schlüssel ignoriert, fehlende fallen auf Standard zurück', array_column($rows9($f9()), 'Key') === $keys9);
foreach (['', 'kein json', '{"a":1}', 'null', '5'] as $junk) {
    $GLOBALS['PROP'][1]['FormLayout'] = $junk;
    check('9g: ungültiger Inhalt ' . var_export($junk, true) . ' → Standard', $order9($f9()) === $ord);
}
$GLOBALS['PROP'][1]['FormLayout'] = json_encode([$mk9('range', false, false, true, 9)]);
unset($GLOBALS['FORMFIELDS']['FormLayout']);
$msg9 = $d9->ResetFormLayout();
$rr = json_decode($GLOBALS['FORMFIELDS']['FormLayout']['values'] ?? 'null', true);
check('9h: ResetFormLayout() setzt per UpdateFormField auf Standard, Klartext zurück, Property unverändert',
    is_array($rr) && array_column($rr, 'Key') === $keys9 && array_column($rr, 'Pos') === [1, 2, 3] && array_sum(array_map(function ($r) { return (int)$r['Opened'] + (int)$r['Closed'] + (int)$r['Hidden']; }, $rr)) === 0
    && mb_strpos($msg9, 'Übernehmen') !== false && mb_strpos((string)$GLOBALS['PROP'][1]['FormLayout'], 'range') !== false);
$btn9 = null; foreach ($find9($std9, 'Formular: Aufbau')['items'] as $it) { if (($it['type'] ?? '') === 'Button') { $btn9 = $it; } }
check('9i: Knopf ruft MHUBD_ResetFormLayout', $btn9 !== null && mb_strpos($btn9['onClick'], 'MHUBD_ResetFormLayout($id)') !== false);
$GLOBALS['PROP'][1]['FormLayout'] = '[]';

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
