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
function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['ACS']; }
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

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
