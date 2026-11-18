<?php
/**
 * Prüfstand für libs/MeterHubShared.php (Umbau 3, 0.39.7): die gemeinsamen Bausteine einzeln, ohne ein ganzes Modul zu laden.
 * Die Traits stehen sonst nur über die Module im Test; dieser Prüfstand pinnt ihr Verhalten an Randwerten fest:
 *   MHUB_ModbusCodec    — Registerumwandlung (Grenzen, Vorzeichen, Wortreihenfolge)
 *   MHUB_SharedForm     — Aufbau der Fachpanels, Lizenzhinweis
 *   MHUB_SharedInstance — Bereitschaft, Archiv-Instanz, Verdichtungsmerker, Auswahlliste
 */
require_once dirname(__DIR__) . '/libs/MeterHubShared.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
function call($obj, string $m, ...$a) { return (new ReflectionMethod($obj, $m))->invoke($obj, ...$a); }

class CodecProbe
{
    use MHUB_ModbusCodec;
    public $wordSwap = false;
    public function setWordSwap(bool $s) { $this->wordSwap = $s; }
}

// ---------------------------------------------------------------------------
echo "1) Registerumwandlung\n";
$c = new CodecProbe();
check('u16: Wert und Maske (0x12345 → 0x2345)', $c->u16([0x1234], 0) === 0x1234 && $c->u16([0x12345], 0) === 0x2345 && $c->u16([0xFFFF], 0) === 65535);
check('u16: fehlendes Register ist 0', $c->u16([1], 5) === 0);
check('u32: erstes Register ist das höherwertige', $c->u32([0x0001, 0x0002], 0) === 65538 && $c->u32([0xFFFF, 0xFFFF], 0) === 4294967295);
check('s32: Grenzen', $c->s32([0x7FFF, 0xFFFF], 0) === 2147483647 && $c->s32([0x8000, 0x0000], 0) === -2147483648 && $c->s32([0xFFFF, 0xFFFF], 0) === -1 && $c->s32([0, 5], 0) === 5);
check('u32sw: erstes Register ist das niederwertige', $c->u32sw([0x0002, 0x0001], 0) === 65538);
check('s32sw: Grenzen', $c->s32sw([0xFFFF, 0x7FFF], 0) === 2147483647 && $c->s32sw([0x0000, 0x8000], 0) === -2147483648 && $c->s32sw([0xFFFF, 0xFFFF], 0) === -1);
check('readFloat32: 1,5 = 0x3FC00000 (ABCD)', $c->readFloat32([0x3FC0, 0x0000], 0) === 1.5);
check('readFloat32: Wortreihenfolge getauscht (CDAB)', (function () use ($c) { $c->setWordSwap(true); $r = $c->readFloat32([0x0000, 0x3FC0], 0); $c->setWordSwap(false); return $r === 1.5; })());
check('readFloat32: mit Offset', $c->readFloat32([9, 9, 0x3FC0, 0x0000], 2) === 1.5);
check('readDouble64: 1,5 = 0x3FF8000000000000 (ABCD)', $c->readDouble64([0x3FF8, 0, 0, 0], 0) === 1.5);
check('readDouble64: Wortreihenfolge umgekehrt', (function () use ($c) { $c->setWordSwap(true); $r = $c->readDouble64([0, 0, 0, 0x3FF8], 0); $c->setWordSwap(false); return $r === 1.5; })());
check('packFloat32: 1,5 → [0x3FC0, 0]', $c->packFloat32(1.5) === [0x3FC0, 0x0000]);
check('packFloat32: getauscht → [0, 0x3FC0]', (function () use ($c) { $c->setWordSwap(true); $r = $c->packFloat32(1.5); $c->setWordSwap(false); return $r === [0x0000, 0x3FC0]; })());

// ---------------------------------------------------------------------------
class FormProbe
{
    use MHUB_SharedForm;
    use MHUB_SharedInstance;
    private const LICENSE_URL = 'https://example.test/license';
    private const PAYPAL_URL = 'https://example.test/pay';
    private const NEWS_VERSION = '9.9';
    private const COMPACTION_SECONDS = [0 => 60, 1 => 300, 2 => 3600, 3 => 86400, 4 => 604800, 5 => 2629746, 6 => 31556952];
    private function txt(string $text, ...$args): string { $GLOBALS['TXT'][] = $text; return $args ? sprintf($text, ...$args) : $text; }
    private function fnPrefix(): string { return 'PROBE'; }
    private function siblingModuleGuid(): string { return '{PROBE-GUID}'; }
    public $attrS = [];
    public $ints = [];
    public function ReadPropertyInteger($n) { return (int)($this->ints[$n] ?? 0); }
    public function ReadAttributeString($n) { return (string)($this->attrS[$n] ?? ''); }
    public function WriteAttributeString($n, $v) { $this->attrS[$n] = $v; }
    public $layout = '[]';
    public $attr = [];
    public $props = [];
    public $instanceId = 77;
    public $InstanceID = 77;
    public function ReadPropertyString($n) { return $n === 'FormLayout' ? $this->layout : ''; }
    public function ReadPropertyBoolean($n)
    {
        if (!empty($GLOBALS['WARN_ON_READ'])) { trigger_error($GLOBALS['WARN_ON_READ'], E_USER_WARNING); }
        return (bool)($this->props[$n] ?? false);
    }
    public function ReadAttributeBoolean($n) { return (bool)($this->attr[$n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $this->attr[$n] = $v; $GLOBALS['WRITES'][] = $n; }
}
echo "\n2) Aufbau der Fachpanels (formLayout, ApplyFormLayout)\n";
$f = new FormProbe();
$std = call($f, 'formLayout', ['a', 'b', 'c']);
check('ohne Einstellung: Standardreihenfolge, alles „std“', array_keys($std) === ['a', 'b', 'c'] && $std['b'] === ['open' => 'std', 'pos' => 2, 'idx' => 1]);
$f->layout = json_encode([
    ['Key' => 'a', 'Opened' => true, 'Closed' => true, 'Hidden' => true, 'Pos' => 3],
    ['Key' => 'b', 'Opened' => true, 'Closed' => true, 'Pos' => 0],
    ['Key' => 'c', 'Opened' => true, 'Pos' => 150],
    ['Key' => 'unbekannt', 'Hidden' => true],
]);
$l = call($f, 'formLayout', ['a', 'b', 'c', 'd']);
check('Vorrang: ausgeblendet vor zugeklappt vor aufgeklappt', $l['a']['open'] === 'hidden' && $l['b']['open'] === 'closed' && $l['c']['open'] === 'open' && $l['d']['open'] === 'std');
check('Position wird auf 1 bis 99 begrenzt', $l['b']['pos'] === 1 && $l['c']['pos'] === 99 && $l['a']['pos'] === 3);
check('Sortierung: nach Position, bei Gleichstand nach Standardreihenfolge', array_keys($l) === ['b', 'a', 'd', 'c'], implode(',', array_keys($l)));
$f->layout = 'kein json';
check('kaputte Einstellung fällt auf den Standard zurück', array_keys(call($f, 'formLayout', ['x', 'y'])) === ['x', 'y']);
$f->layout = json_encode([['Key' => 'a', 'Opened' => true], ['Key' => 'b', 'Closed' => true], ['Key' => 'c', 'Hidden' => true]]);
$r = call($f, 'ApplyFormLayout', ['a' => ['expanded' => false], 'b' => ['expanded' => true], 'c' => ['expanded' => true], 'd' => ['expanded' => true]]);
check('Aufklappen, Zuklappen, Ausblenden (visible=false, Panel bleibt im Formular)', $r['panels'][0]['expanded'] === true && $r['panels'][1]['expanded'] === false && $r['panels'][2]['visible'] === false && !isset($r['panels'][3]['visible']) && count($r['panels']) === 4);
check('Standard lässt das Panel unverändert', $r['panels'][3] === ['expanded' => true]);

echo "\n3) Lizenzhinweis\n";
$h = call($f, 'LicenseHint');
$clicks = array_values(array_filter(array_column($h['items'], 'onClick')));
check('eingeklappt, Verknüpfungen auf Lizenz und PayPal', $h['expanded'] === false && $clicks === ["echo 'https://example.test/license';", "echo 'https://example.test/pay';"], json_encode($clicks));

// ---------------------------------------------------------------------------
echo "\n4) Bereitschaft beim Neuladen (InstanceReady)\n";
define('KR_READY', 10103);
$GLOBALS['RUNLEVEL'] = KR_READY; $GLOBALS['EXISTS'] = true; $GLOBALS['WARN_ON_READ'] = '';
function IPS_GetKernelRunlevel() { return $GLOBALS['RUNLEVEL']; }
function IPS_InstanceExists($id) { return $GLOBALS['EXISTS']; }
$f = new FormProbe();
check('alles in Ordnung: bereit', call($f, 'InstanceReady') === true);
$GLOBALS['RUNLEVEL'] = 10102; check('Kernel noch nicht bereit: nicht bereit', call($f, 'InstanceReady') === false); $GLOBALS['RUNLEVEL'] = KR_READY;
$GLOBALS['EXISTS'] = false; check('Instanz existiert nicht: nicht bereit', call($f, 'InstanceReady') === false); $GLOBALS['EXISTS'] = true;
$GLOBALS['WARN_ON_READ'] = 'InstanceInterface is not available';
check('SDK meldet „InstanceInterface is not available“: nicht bereit', call($f, 'InstanceReady') === false);
$GLOBALS['WARN_ON_READ'] = 'irgendeine andere Warnung';
check('andere Warnung: bereit', call($f, 'InstanceReady') === true);
$GLOBALS['WARN_ON_READ'] = '';

echo "\n5) Archiv-Instanz, Verdichtungsmerker, Auswahlliste\n";
$GLOBALS['ACS'] = [55, 66];
function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['INST'][$guid] ?? $GLOBALS['ACS']; }
check('ohne Archivfunktion: 0', call($f, 'ArchiveInstanceID') === 0);
eval('function AC_GetLoggingStatus($a, $v) { return true; }');
check('mit Archivfunktion: die erste Archiv-Instanz', call($f, 'ArchiveInstanceID') === 55);
$GLOBALS['ACS'] = [];
check('mit Archivfunktion, aber ohne Archiv-Instanz: 0', call($f, 'ArchiveInstanceID') === 0);

$GLOBALS['WRITES'] = [];
$f->props = ['AutoCompactionPower' => true, 'AutoCompactionEnergy' => false];
$f->attr = ['CompactionAppliedPower' => false, 'CompactionAppliedEnergy' => false];
call($f, 'CommitCompactionState');
check('Merker folgt dem Schalter, geschrieben wird nur bei Abweichung', $f->attr['CompactionAppliedPower'] === true && $f->attr['CompactionAppliedEnergy'] === false && $GLOBALS['WRITES'] === ['CompactionAppliedPower'], json_encode($GLOBALS['WRITES']));
$GLOBALS['WRITES'] = [];
call($f, 'CommitCompactionState');
check('zweiter Durchlauf schreibt nichts', $GLOBALS['WRITES'] === []);
$f->props['AutoCompactionPower'] = false;
call($f, 'CommitCompactionState');
check('Wechsel an → aus wird gemerkt', $f->attr['CompactionAppliedPower'] === false);

$o = call($f, 'CompactionTypeOptions');
check('Auswahlliste: Werte −1 und 0 bis 7 in dieser Reihenfolge', array_column($o, 'value') === [-1, 0, 1, 2, 3, 4, 5, 6, 7], json_encode(array_column($o, 'value')));
check('Auswahlliste: „aus“ und „Werte löschen“', $o[0]['caption'] === '— off —' && $o[8]['caption'] === 'Delete values');

// ---------------------------------------------------------------------------
echo "\n6) Fachpanel-Aufbau im Formular (layoutPanelName, LayoutPanel) mit den Haken der Klasse\n";
$f = new FormProbe();
check('layoutPanelName: Klammerzusatz fällt weg, Name wird übersetzt', call($f, 'layoutPanelName', ['caption' => '📊  Zähler (Details)'], 'k') === '📊  Zähler' && in_array('📊  Zähler (Details)', $GLOBALS['TXT'], true));
check('layoutPanelName: ohne Beschriftung gilt der Schlüssel', call($f, 'layoutPanelName', [], 'meters') === 'meters');
$free = ['a' => ['caption' => '🔌 Verbindung (Modbus)'], 'b' => ['caption' => '📈 Archiv'], 'c' => ['caption' => 'Plain']];
$lp = call($f, 'LayoutPanel', [
    'a' => ['open' => 'open', 'pos' => 1, 'idx' => 0],
    'b' => ['open' => 'hidden', 'pos' => 2, 'idx' => 1],
    'c' => ['open' => 'closed', 'pos' => 3, 'idx' => 2],
], $free);
$items = $lp['items'];
check('mit ausgeblendetem Panel: Überschrift nennt die Zahl, Hinweiszeile nennt den Namen ohne Symbol', str_contains($lp['caption'], '(1 hidden)') && $items[0]['type'] === 'Label' && str_contains($items[0]['caption'], 'Archiv') && !str_contains($items[0]['caption'], '📈'), $lp['caption'] . ' | ' . ($items[0]['caption'] ?? ''));
$list = array_values(array_filter($items, fn($i) => ($i['name'] ?? '') === 'FormLayout'))[0];
check('Liste: eine Zeile je Panel mit Aufklapp-Zustand und Position', count($list['values']) === 3 && $list['values'][0]['Opened'] === true && $list['values'][1]['Hidden'] === true && $list['values'][2]['Closed'] === true && $list['values'][2]['Pos'] === 3 && $list['rowCount'] === 3);
$btn = array_values(array_filter($items, fn($i) => ($i['type'] ?? '') === 'Button'))[0];
check('Zurücksetzen-Knopf ruft die Funktion mit dem Präfix der Klasse', $btn['onClick'] === 'echo PROBE_ResetFormLayout($id);', $btn['onClick']);
$lp2 = call($f, 'LayoutPanel', ['a' => ['open' => 'std', 'pos' => 1, 'idx' => 0]], ['a' => ['caption' => 'A']]);
check('ohne ausgeblendetes Panel: keine Hinweiszeile, Überschrift ohne Zahl', $lp2['items'][0]['type'] === 'Label' && !str_contains($lp2['items'][0]['caption'] ?? '', 'Hidden:') && !str_contains($lp2['caption'], 'hidden') && $lp2['expanded'] === false, $lp2['caption']);

echo "\n7) Ausblenden über Geschwister-Instanzen (PropagateDismiss, AdoptDismissFromSibling)\n";
$GLOBALS['INST']['{PROBE-GUID}'] = [77, 78, 79];
$GLOBALS['ADOPT'] = [];
function PROBE_AdoptDismissState($id, $what, $value) { if ($id === 79) { throw new \RuntimeException('im Reload'); } $GLOBALS['ADOPT'][] = [$id, $what, $value]; }
function PROBE_GetDismissState($id) { $GLOBALS['GETS'] = ($GLOBALS['GETS'] ?? 0) + 1; return $GLOBALS['DISMISS'][$id] ?? null; }
$f = new FormProbe();
call($f, 'PropagateDismiss', 'forumHint', '1');
check('PropagateDismiss: nur die anderen Instanzen, ein Fehler bei einer reißt die Übrigen nicht mit', $GLOBALS['ADOPT'] === [[78, 'forumHint', '1']], json_encode($GLOBALS['ADOPT']));
$GLOBALS['INST']['{PROBE-GUID}'] = [];
$GLOBALS['ADOPT'] = []; call($f, 'PropagateDismiss', 'x');
check('PropagateDismiss: ohne Geschwister passiert nichts', $GLOBALS['ADOPT'] === []);

$GLOBALS['INST']['{PROBE-GUID}'] = [77, 80, 81, 82];
$GLOBALS['DISMISS'] = [80 => 'kein Array', 81 => ['purposeIntroGone' => true, 'forumHintGone' => false, 'seenNews' => '9.9'], 82 => ['forumHintGone' => true]];
$f = new FormProbe();
call($f, 'AdoptDismissFromSibling');
check('Übernahme: die erste brauchbare Geschwister-Instanz gilt (80 ist kein Array, wird übersprungen)', $f->attr['PurposeIntroGone'] === true && ($f->attr['ForumHintGone'] ?? false) === false && $f->attrS['SeenNews'] === '9.9', json_encode([$f->attr, $f->attrS]));
$f = new FormProbe(); $f->attr = ['PurposeIntroGone' => true, 'ForumHintGone' => true]; $f->attrS = ['SeenNews' => '9.9'];
$GLOBALS['WRITES'] = [];
$GLOBALS['GETS'] = 0;
call($f, 'AdoptDismissFromSibling');
check('Übernahme: ist schon alles bestätigt, wird nichts geschrieben und kein Nachbar gefragt', $f->attr === ['PurposeIntroGone' => true, 'ForumHintGone' => true] && $f->attrS === ['SeenNews' => '9.9'] && $GLOBALS['GETS'] === 0, 'Abfragen: ' . $GLOBALS['GETS']);
$GLOBALS['DISMISS'] = [81 => ['purposeIntroGone' => false, 'forumHintGone' => false, 'seenNews' => '1.0']];
$f = new FormProbe();
call($f, 'AdoptDismissFromSibling');
check('Übernahme: ein älterer Stand der Nachbarn zieht nichts vor', ($f->attr['PurposeIntroGone'] ?? false) === false && ($f->attrS['SeenNews'] ?? '') === '');

echo "\n8) Verdichtungsplan (CompactionPlan)\n";
$f = new FormProbe();
check('Schalter aus und nie angewendet: leer (Archiv unangetastet)', call($f, 'CompactionPlan', 10, 'Power') === []);
$f->attr['CompactionAppliedPower'] = true; $f->ints = ['CompactStage2MonthsPower' => 1, 'CompactStage3MonthsPower' => 12];
check('Schalter aus, aber vorher an: alle drei Stufen aktiv auf −1 zurücksetzen', call($f, 'CompactionPlan', 10, 'Power') === [[-1, -1], [1, -1], [12, -1]]);
$f = new FormProbe(); $f->props = ['AutoCompactionPower' => true];
$f->ints = ['CompactDirectPower' => 0, 'CompactStage2MonthsPower' => 1, 'CompactStage2TypePower' => 1, 'CompactStage3MonthsPower' => 12, 'CompactStage3TypePower' => 2];
check('Intervall 10 s: jede Stufe, deren Auflösung gröber ist, bleibt', call($f, 'CompactionPlan', 10, 'Power') === [[-1, 0], [1, 1], [12, 2]], json_encode(call($f, 'CompactionPlan', 10, 'Power')));
check('Intervall 600 s: Stufen, die nicht gröber sind, werden −1, die gröbere bleibt', call($f, 'CompactionPlan', 600, 'Power') === [[-1, -1], [1, -1], [12, 2]], json_encode(call($f, 'CompactionPlan', 600, 'Power')));
$f->ints['CompactStage3TypePower'] = 7;
check('Intervall genau gleich der Auflösung (300 s, Typ 1): kein Gewinn, also −1', call($f, 'CompactionPlan', 300, 'Power')[1] === [1, -1], json_encode(call($f, 'CompactionPlan', 300, 'Power')));
check('Typ „löschen“ bleibt unabhängig vom Intervall', call($f, 'CompactionPlan', 99999, 'Power')[2] === [12, 7]);
$f->ints['CompactStage3TypePower'] = -1;
check('ausgeschaltete Stufe: Typ −1 am eigenen Monats-Offset', call($f, 'CompactionPlan', 10, 'Power')[2] === [12, -1]);
check('Leistung und Energie lesen getrennte Einstellungen', call($f, 'CompactionPlan', 10, 'Energy') === []);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
