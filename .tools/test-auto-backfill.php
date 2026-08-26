<?php
/**
 * Prüfstand für den automatischen wiederkehrenden Inexogy-Lastgang-
 * Nachtrag (MaybeAutoBackfillInexogy(), 27.08.2026, überarbeitet nach
 * Dietmars Rückfrage "warum nicht alle 15 Min. die letzten 30 Tage").
 * Prüft ausschließlich die Auslöse-Logik (Intervall seit letztem Lauf
 * abgelaufen? Cloud-Zähler? überhaupt aktiviert?) — der eigentliche
 * Archiv-Nachtrag (DoBackfillInexogyArchive()) ist derselbe, bereits live
 * verifizierte Code wie beim manuellen Knopf und hier bewusst NICHT
 * erneut geprüft (kein Netzwerkzugriff in diesem Prüfstand).
 */

const VARIABLETYPE_FLOAT = 2;

$GLOBALS['PROP'] = [];
$GLOBALS['ATTR'] = [];
$GLOBALS['OBJ']  = [];      // id => ['ObjectType', 'ObjectIdent', 'ParentID'] -- fuer FindVarByIdent()
$GLOBALS['ARCHIVE_IDS'] = [];    // [] = kein Archiv-Modul installiert (Standardfall fuer die Intervall-Tests oben)
$GLOBALS['ARCHIVE_LATEST'] = []; // vid => letzter bekannter TimeStamp, fuer AC_GetLoggedValues(..., Limit=1)

function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['ARCHIVE_IDS']; }
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
function AC_GetLoggedValues($archiveID, $vid, $start, $end, $limit) {
    if (!isset($GLOBALS['ARCHIVE_LATEST'][$vid])) { return []; }
    return [['TimeStamp' => $GLOBALS['ARCHIVE_LATEST'][$vid], 'Value' => 0]];
}
function obj($id, $parent, $ident) {
    $GLOBALS['OBJ'][$id] = ['ObjectType' => 2, 'ObjectIdent' => $ident, 'ParentID' => $parent];
    return $id;
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
    public function ReadPropertyString($n)  { return (string)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? ''); }
    public function ReadPropertyInteger($n) { return (int)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? 0); }
    public function ReadPropertyBoolean($n) { return (bool)($GLOBALS['PROP'][$this->InstanceID][$n] ?? $this->defs[$n] ?? false); }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function SetStatus($s) {}
    protected function SetVisualizationType($t) {}
    public function UpdateFormField($f, $p, $v) {}
    protected function ReloadForm() {}
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
}

require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

function tick(MeterHub $hub) {
    $m = new ReflectionMethod($hub, 'MaybeAutoBackfillInexogy');
    $m->invoke($hub);
}
function lastRunTs(MeterHub $hub): int {
    $m = new ReflectionMethod($hub, 'ReadAttributeInteger');
    return $m->invoke($hub, 'InexogyAutoBackfillLastRunTs');
}

// ---------------------------------------------------------------------------
echo "1) Kein Cloud-Zähler -> nie auslösen, egal was sonst konfiguriert ist\n";
$GLOBALS['PROP'][100] = ['Meter' => 'siemens_pac2200', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillIntervalMin' => 15];
$h100 = new MeterHub(100); $h100->Create();
tick($h100);
check('kein Lauf-Zeitstempel gesetzt', lastRunTs($h100) === 0);

// ---------------------------------------------------------------------------
echo "\n2) Cloud-Zähler, aber Funktion nicht aktiviert -> kein Auslösen\n";
$GLOBALS['PROP'][200] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => false, 'InexogyAutoBackfillIntervalMin' => 15];
$h200 = new MeterHub(200); $h200->Create();
tick($h200);
check('kein Lauf-Zeitstempel gesetzt', lastRunTs($h200) === 0);

// ---------------------------------------------------------------------------
echo "\n3) Aktiviert, noch nie gelaufen -> erster Tick löst sofort aus (kein Warten auf ein volles Intervall)\n";
$GLOBALS['PROP'][300] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillIntervalMin' => 15];
$h300 = new MeterHub(300); $h300->Create();
tick($h300);
check('Lauf-Zeitstempel gesetzt', abs(lastRunTs($h300) - time()) <= 2, 'ist ' . lastRunTs($h300));

// ---------------------------------------------------------------------------
echo "\n4) Direkt danach erneuter Tick -> Intervall noch nicht abgelaufen, kein zweiter Lauf\n";
$before = lastRunTs($h300);
tick($h300);
check('Lauf-Zeitstempel unverändert', lastRunTs($h300) === $before);

// ---------------------------------------------------------------------------
echo "\n5) Letzter Lauf liegt länger als das konfigurierte Intervall zurück -> löst wieder aus\n";
$GLOBALS['PROP'][400] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillIntervalMin' => 15, 'InexogyAutoBackfillDays' => 2];
$h400 = new MeterHub(400); $h400->Create();
$setTs = new ReflectionMethod($h400, 'WriteAttributeInteger');
$setTs->invoke($h400, 'InexogyAutoBackfillLastRunTs', time() - 16 * 60); // 16 Min. her, Intervall ist 15 Min.
tick($h400);
check('neuer Lauf-Zeitstempel gesetzt', abs(lastRunTs($h400) - time()) <= 2, 'ist ' . lastRunTs($h400));

// ---------------------------------------------------------------------------
echo "\n6) Letzter Lauf liegt knapp UNTER dem Intervall zurück -> löst noch nicht aus\n";
$GLOBALS['PROP'][500] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillIntervalMin' => 15];
$h500 = new MeterHub(500); $h500->Create();
$oldTs = time() - 10 * 60; // 10 Min. her, Intervall ist 15 Min.
$setTs = new ReflectionMethod($h500, 'WriteAttributeInteger');
$setTs->invoke($h500, 'InexogyAutoBackfillLastRunTs', $oldTs);
tick($h500);
check('Lauf-Zeitstempel unverändert', lastRunTs($h500) === $oldTs, 'ist ' . lastRunTs($h500));

// ---------------------------------------------------------------------------
echo "\n7) Minimum-Intervall wird gegen zu kleine/fehlende Konfiguration abgesichert (mindestens 15 Min.)\n";
$GLOBALS['PROP'][600] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillIntervalMin' => 0];
$h600 = new MeterHub(600); $h600->Create();
$setTs = new ReflectionMethod($h600, 'WriteAttributeInteger');
$recentTs = time() - 5 * 60; // 5 Min. her -- bei "0 Min. Intervall" faelschlich sofort wieder faellig, bei erzwungenem Minimum 15 nicht
$setTs->invoke($h600, 'InexogyAutoBackfillLastRunTs', $recentTs);
tick($h600);
check('Lauf-Zeitstempel unverändert (Minimum 15 Min. greift)', lastRunTs($h600) === $recentTs, 'ist ' . lastRunTs($h600));

function range_(MeterHub $hub): array {
    $m = new ReflectionMethod($hub, 'ComputeAutoBackfillRange');
    return $m->invoke($hub);
}

// ---------------------------------------------------------------------------
echo "\n8) Nachsehen statt raten: Bereich richtet sich nach dem, was schon archiviert ist\n";
// Dietmars eigentliche Rückfrage (27.08.2026): "wie wäre es nachzusehen,
// welche Daten schon da sind, und nur das Notwendige zu holen?" -- diese und
// die folgenden Fälle prüfen genau das, isoliert vom Netzwerkzugriff.
$GLOBALS['ARCHIVE_IDS'] = [9999];
obj(701, 700, 'energy_import');
obj(702, 700, 'energy_export');
obj(703, 700, 'power_total');
$GLOBALS['OBJ'][700] = ['ObjectType' => 1, 'ObjectIdent' => '', 'ParentID' => 0]; // Instanz selbst
$GLOBALS['PROP'][700] = ['Meter' => 'inexogy', 'InexogyAutoBackfillDays' => 3];
$h700 = new MeterHub(700); $h700->Create();

echo "  8a) Alle drei Variablen 20 Min. alt -> Bereich beginnt kurz davor (20 Min. + 30 Min. Puffer), nicht bei der Obergrenze\n";
$watermark = time() - 20 * 60;
$GLOBALS['ARCHIVE_LATEST'] = [701 => $watermark, 702 => $watermark, 703 => $watermark];
[$from, $to, $err] = range_($h700);
check('kein Fehler', $err === null, (string) $err);
check('from = watermark - 30 Min., nicht -3 Tage', abs($from - ($watermark - 1800)) <= 2, "from=$from erwartet~=" . ($watermark - 1800));
check('to = jetzt', abs($to - time()) <= 2);

echo "\n  8b) Eine Variable hinkt weiter hinterher als die anderen -> Minimum entscheidet (alle drei gemeinsam nachholen)\n";
$now = time();
$GLOBALS['ARCHIVE_LATEST'] = [701 => $now - 5 * 60, 702 => $now - 5 * 60, 703 => $now - 6 * 3600];
[$from, $to, $err] = range_($h700);
check('from richtet sich nach der ältesten (power_total)', abs($from - ($now - 6 * 3600 - 1800)) <= 2, "from=$from");

echo "\n  8c) Noch nie archiviert (Zeitstempel 0) -> Obergrenze (InexogyAutoBackfillDays) greift, kein unbegrenzter Nachtrag\n";
$GLOBALS['ARCHIVE_LATEST'] = [701 => 0, 702 => 0, 703 => 0];
[$from, $to, $err] = range_($h700);
check('from = jetzt - 3 Tage (konfigurierte Obergrenze)', abs($from - (time() - 3 * 86400)) <= 2, "from=$from");

echo "\n  8d) Bereits aktuell (Watermark liegt in der Zukunft, z. B. direkt nach einem Lauf) -> kein Nachtrag ausgelöst\n";
$future = time() + 3600;
$GLOBALS['ARCHIVE_LATEST'] = [701 => $future, 702 => $future, 703 => $future];
[$from, $to, $err] = range_($h700);
check('Meldung statt Bereich', $err === 'ℹ️ Archiv bereits auf dem neuesten Stand.', (string) $err);

// ---------------------------------------------------------------------------
echo "\n9) GetFunctions() meldet den Wasserstand als archiveWatermarkTs (Dashboard-Konsum)\n";
$wm9 = time() - 12 * 60;
$GLOBALS['ARCHIVE_LATEST'] = [701 => $wm9, 702 => $wm9, 703 => $wm9];
$gf = json_decode($h700->GetFunctions(), true);
check('contractVersion = 1.2', ($gf['contractVersion'] ?? '') === '1.2', (string) ($gf['contractVersion'] ?? ''));
check('archiveWatermarkTs auf oberster Ebene gesetzt', abs(($gf['archiveWatermarkTs'] ?? 0) - $wm9) <= 2, json_encode($gf['archiveWatermarkTs'] ?? null));

echo "\n  9b) Echtzeit-Zähler (kein Cloud-Zähler) -> archiveWatermarkTs bleibt null, keine unnötige Archiv-Abfrage\n";
$GLOBALS['PROP'][750] = ['Meter' => 'siemens_pac2200'];
obj(751, 750, 'energy_import');
obj(752, 750, 'energy_export');
obj(753, 750, 'power_total');
$GLOBALS['OBJ'][750] = ['ObjectType' => 1, 'ObjectIdent' => '', 'ParentID' => 0];
$GLOBALS['ARCHIVE_LATEST'][751] = $wm9; // waere vorhanden, darf aber fuer Echtzeit-Zaehler gar nicht erst abgefragt werden
$h750 = new MeterHub(750); $h750->Create();
$gf750 = json_decode($h750->GetFunctions(), true);
check('archiveWatermarkTs = null für realtime-Zähler', array_key_exists('archiveWatermarkTs', $gf750) && $gf750['archiveWatermarkTs'] === null, var_export($gf750['archiveWatermarkTs'] ?? '(fehlt)', true));
check('latency = realtime', ($gf750['latency'] ?? '') === 'realtime');

echo "\n  8e) Kein Archiv-Modul installiert -> klare Fehlermeldung, kein Absturz\n";
$GLOBALS['ARCHIVE_IDS'] = [];
[$from, $to, $err] = range_($h700);
check('Fehlermeldung: kein Archiv-Modul', str_contains((string) $err, 'Archiv-Modul'), (string) $err);

echo "\n  8f) Keine der drei Zielvariablen vorhanden -> klare Fehlermeldung, kein Absturz\n";
$GLOBALS['ARCHIVE_IDS'] = [9999];
$GLOBALS['OBJ'] = ['700' => ['ObjectType' => 1, 'ObjectIdent' => '', 'ParentID' => 0]]; // Variablen entfernt
[$from, $to, $err] = range_($h700);
check('Fehlermeldung: keine Zielvariable', str_contains((string) $err, 'Zielvariable'), (string) $err);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
