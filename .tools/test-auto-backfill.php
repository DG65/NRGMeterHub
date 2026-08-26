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
function IPS_GetInstanceListByModuleID($guid) { return []; } // kein Archiv-Modul im Test -> DoBackfillInexogyArchive() bricht früh ab, unschädlich für diesen Prüfstand

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

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
