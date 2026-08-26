<?php
/**
 * Prüfstand für den automatischen täglichen Inexogy-Lastgang-Nachtrag
 * (MaybeAutoBackfillInexogy(), 27.08.2026). Prüft ausschließlich die
 * Auslöse-Logik (Uhrzeit erreicht? heute schon gelaufen? Cloud-Zähler?
 * überhaupt aktiviert?) — der eigentliche Archiv-Nachtrag
 * (DoBackfillInexogyArchive()) ist derselbe, bereits live verifizierte
 * Code wie beim manuellen Knopf und hier bewusst NICHT erneut geprüft
 * (kein Netzwerkzugriff in diesem Prüfstand). Die Zielzeit wird relativ
 * zur echten aktuellen Uhrzeit gesetzt, damit der Test unabhängig davon
 * läuft, wann er ausgeführt wird.
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
}

require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

function timeProp(int $offsetSeconds): string {
    $t = time() + $offsetSeconds;
    return json_encode(['hour' => (int) date('H', $t), 'minute' => (int) date('i', $t), 'second' => (int) date('s', $t)]);
}
function tick(MeterHub $hub) {
    $m = new ReflectionMethod($hub, 'MaybeAutoBackfillInexogy');
    $m->invoke($hub);
}

// ---------------------------------------------------------------------------
echo "1) Kein Cloud-Zähler -> nie auslösen, egal was sonst konfiguriert ist\n";
$GLOBALS['PROP'][100] = ['Meter' => 'siemens_pac2200', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillTime' => timeProp(-60)];
$h100 = new MeterHub(100); $h100->Create();
tick($h100);
check('kein Lauf-Attribut gesetzt', $h100->ReadAttributeString('InexogyAutoBackfillLastRunDate') === '');

// ---------------------------------------------------------------------------
echo "\n2) Cloud-Zähler, aber Funktion nicht aktiviert -> kein Auslösen\n";
$GLOBALS['PROP'][200] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => false, 'InexogyAutoBackfillTime' => timeProp(-60)];
$h200 = new MeterHub(200); $h200->Create();
tick($h200);
check('kein Lauf-Attribut gesetzt', $h200->ReadAttributeString('InexogyAutoBackfillLastRunDate') === '');

// ---------------------------------------------------------------------------
echo "\n3) Aktiviert, Zielzeit noch in der Zukunft -> heute noch nicht auslösen\n";
$GLOBALS['PROP'][300] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillTime' => timeProp(3600)];
$h300 = new MeterHub(300); $h300->Create();
tick($h300);
check('kein Lauf-Attribut gesetzt (Zielzeit noch nicht erreicht)', $h300->ReadAttributeString('InexogyAutoBackfillLastRunDate') === '');

// ---------------------------------------------------------------------------
echo "\n4) Aktiviert, Zielzeit bereits erreicht -> löst aus und merkt sich heutiges Datum\n";
$GLOBALS['PROP'][400] = ['Meter' => 'inexogy', 'InexogyAutoBackfillEnabled' => true, 'InexogyAutoBackfillTime' => timeProp(-60), 'InexogyAutoBackfillDays' => 5];
$h400 = new MeterHub(400); $h400->Create();
tick($h400);
check('Lauf-Attribut = heute', $h400->ReadAttributeString('InexogyAutoBackfillLastRunDate') === date('Y-m-d'), 'ist ' . $h400->ReadAttributeString('InexogyAutoBackfillLastRunDate'));

// ---------------------------------------------------------------------------
echo "\n5) Bereits heute gelaufen -> kein zweiter Lauf am selben Tag (auch bei erneutem Takt)\n";
// h400 hat aus Schritt 4 bereits das heutige Datum gesetzt -- erneuter Tick darf
// nichts mehr tun (Attribut bliebe unveraendert, kein zweiter Archiv-Aufruf).
$before = $h400->ReadAttributeString('InexogyAutoBackfillLastRunDate');
tick($h400);
check('Lauf-Attribut unveraendert', $h400->ReadAttributeString('InexogyAutoBackfillLastRunDate') === $before);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
