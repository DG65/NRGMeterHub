<?php
/**
 * Prüfstand für MHUB_GetIdentMapping() (Verbund-Vertrag 29.07.2026, mit
 * MigrationsHub/ChargerHub/InverterHub abgestimmt). Fest-arität-gebundene
 * öffentliche Funktion — ein Fehler hier bricht MigrationsHubs Aufrufer
 * still (function_exists()===false) oder mit falschen Idents/Typen, ein
 * Syntaxcheck sieht das nicht.
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;

$GLOBALS['PROP'] = [];
$GLOBALS['ATTR'] = [];

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
    protected function RegisterAttributeString($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeString($n)  { return (string)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? ''); }
    public function WriteAttributeString($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeInteger($n)  { return (int)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? 0); }
    public function WriteAttributeInteger($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->defs['@' . $n] = $v; }
    public function ReadAttributeBoolean($n)  { return (bool)($GLOBALS['ATTR'][$this->InstanceID][$n] ?? $this->defs['@' . $n] ?? false); }
    public function WriteAttributeBoolean($n, $v) { $GLOBALS['ATTR'][$this->InstanceID][$n] = $v; }
    protected function RegisterTimer($n, $i, $s) {}
}

require_once dirname(__DIR__) . '/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

const DISCOVERGY_GUID = '{C0F160B2-0B9D-2AAE-0527-C0FA4BDEE743}';

echo "\n1) Unbekanntes Alt-Modul -> leeres Ergebnis, kein Fehler\n";
$h = new MeterHub(1);
$h->Create();
$r = $h->GetIdentMapping('{UNBEKANNT-GUID}', ['energy', 'power']);
check('leeres Array bei unbekannter GUID', $r === []);

echo "\n2) Bekanntes Alt-Modul (Discovergy), Standard-Zähler (Shelly Pro 3EM)\n";
$GLOBALS['PROP'][2]['Meter'] = 'shelly_pro3em';
$h2 = new MeterHub(2);
$h2->Create();
$r2 = $h2->GetIdentMapping(DISCOVERGY_GUID, ['energy', 'energyout', 'power', 'phase1', 'voltage1', 'unbekanntesfeld']);
check('energy -> energy_import (Float)', ($r2['energy'] ?? null) === ['ident' => 'energy_import', 'type' => VARIABLETYPE_FLOAT]);
check('energyout -> energy_export (Float)', ($r2['energyout'] ?? null) === ['ident' => 'energy_export', 'type' => VARIABLETYPE_FLOAT]);
check('power -> power_total (Float)', ($r2['power'] ?? null) === ['ident' => 'power_total', 'type' => VARIABLETYPE_FLOAT]);
check('phase1 -> p_l1 (Float)', ($r2['phase1'] ?? null) === ['ident' => 'p_l1', 'type' => VARIABLETYPE_FLOAT]);
check('voltage1 -> u_l1_n (Float)', ($r2['voltage1'] ?? null) === ['ident' => 'u_l1_n', 'type' => VARIABLETYPE_FLOAT]);
check('unbekanntes Alt-Feld fehlt im Ergebnis', !array_key_exists('unbekanntesfeld', $r2));
check('nur angefragte Idents kommen zurück (keine Übergabe = kein Treffer)', !array_key_exists('phase2', $r2));

echo "\n3) Nur tatsächlich übergebene Alt-Idents werden aufgelöst\n";
$r3 = $h2->GetIdentMapping(DISCOVERGY_GUID, ['energy']);
check('nur energy angefragt -> nur energy im Ergebnis', array_keys($r3) === ['energy']);

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
