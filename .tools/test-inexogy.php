<?php
/**
 * Prüfstand für den Inexogy-Cloud-Treiber.
 *
 * Verifizierbar OHNE echte Zugangsdaten sind zwei Dinge — und genau die sind
 * die Fehlerquellen: die OAuth-1.0a-Signierung (Prozentkodierung + Basisstring)
 * und die Skalierung/Feldzuordnung im Treiber. Der eigentliche Handshake gegen
 * api.inexogy.com ist erst an einem echten Login prüfbar; er ist hier bewusst
 * NICHT abgedeckt.
 */

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_FLOAT   = 2;

// --- Minimal-IPS, nur so viel, dass module.php lädt -----------------------
class IPSModule {
    public $InstanceID;
    public function __construct($id = 0) { $this->InstanceID = $id; }
}
function IPS_GetInstanceListByModuleID($g) { return []; }
function IPS_GetKernelDate() { return time(); }

require '/Users/dietmar/Nextcloud/Claude/MeterHub/MeterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    echo ($cond ? '  ok    ' : '  FEHLT ') . $label . ($detail !== '' && !$cond ? "  ($detail)" : '') . "\n";
    if (!$cond) { $fails++; }
}

// ==========================================================================
echo "1) OAuth-Prozentkodierung (RFC 3986)\n";
$enc = new ReflectionMethod('InexogyClient', 'enc');
$e = fn($s) => $enc->invoke(null, $s);
check('Leerzeichen → %20', $e('a b') === 'a%20b', $e('a b'));
check('Tilde bleibt ~',    $e('a~b') === 'a~b', $e('a~b'));
check('Slash → %2F',       $e('a/b') === 'a%2Fb', $e('a/b'));
check('Gleich → %3D',      $e('a=b') === 'a%3Db', $e('a=b'));
check('Plus → %2B',        $e('a+b') === 'a%2Bb', $e('a+b'));

// ==========================================================================
echo "\n2) OAuth-Signatur deterministisch (fixe nonce/timestamp)\n";
// authHeader ist privat; über Reflection mit fixierten oauth-Parametern
// aufrufen, sodass das Ergebnis reproduzierbar ist. Zwei gleiche Aufrufe müssen
// dieselbe Signatur ergeben; ein geänderter Parameter eine andere.
$c = new InexogyClient('ck', 'csecret', 'tok', 'tsecret');
$ah = new ReflectionMethod('InexogyClient', 'authHeader');
$fixed = ['oauth_nonce' => 'abc123', 'oauth_timestamp' => '1700000000'];
$h1 = $ah->invoke($c, 'GET', 'https://api.inexogy.com/public/v1/last_reading', ['meterId' => 'XYZ'], $fixed);
$h2 = $ah->invoke($c, 'GET', 'https://api.inexogy.com/public/v1/last_reading', ['meterId' => 'XYZ'], $fixed);
check('Signatur reproduzierbar', $h1 === $h2);
check('Header ist OAuth', strpos($h1, 'OAuth ') === 0);
check('enthält HMAC-SHA1', strpos($h1, 'oauth_signature_method="HMAC-SHA1"') !== false);
check('enthält Consumer-Key', strpos($h1, 'oauth_consumer_key="ck"') !== false);
check('enthält Token', strpos($h1, 'oauth_token="tok"') !== false);
check('Signatur vorhanden', preg_match('/oauth_signature="[^"]+"/', $h1) === 1);
$hDiff = $ah->invoke($c, 'GET', 'https://api.inexogy.com/public/v1/last_reading', ['meterId' => 'OTHER'], $fixed);
check('anderer Parameter → andere Signatur', $h1 !== $hDiff);
// Signatur von Hand nachrechnen und vergleichen (unabhängige Kontrolle).
$expBase = 'GET&' . rawurlencode('https://api.inexogy.com/public/v1/last_reading') . '&'
    . rawurlencode(implode('&', [
        'meterId=XYZ',
        'oauth_consumer_key=ck',
        'oauth_nonce=abc123',
        'oauth_signature_method=HMAC-SHA1',
        'oauth_timestamp=1700000000',
        'oauth_token=tok',
        'oauth_version=1.0',
    ]));
$expSig = rawurlencode(base64_encode(hash_hmac('sha1', $expBase, 'csecret&tsecret', true)));
check('Signatur = unabhängige Nachrechnung', strpos($h1, 'oauth_signature="' . $expSig . '"') !== false, $expSig);

// ==========================================================================
echo "\n3) Treiber: Skalierung und Feldzuordnung\n";
// Client-Stub: liefert ein festes last_reading (Dietmars echte Größenordnung).
class ClientStub {
    public $values;
    public function getLastReading($id) { return $this->values; }
}
// Hub-Stub: zeichnet auf, prüft Gruppen.
class HubStub {
    public $vals = [];
    public $groups;
    public function __construct(array $g) { $this->groups = $g; }
    public function GroupActive(string $g): bool { return in_array($g, $this->groups, true); }
    public function InexogyMeterId(): string { return 'XYZ'; }
    public function SetVarFloat($i, $v)     { $this->vals[$i] = round($v, 4); }
    public function SetVarBool($i, $v)      { $this->vals[$i] = $v; }
    public function SetVarEnergykWh($i, $v) { $this->vals[$i] = round($v, 4); }
}

$drv = new InexogyDriver();

// Fall A: klassische Feldnamen (power1/voltage1), Werte wie bei Dietmar.
$cs = new ClientStub();
$cs->values = [
    'energy'    => 105124169000000,   // /1e10 = 10512.4169 kWh
    'energyOut' =>  44829536000000,   // /1e10 =  4482.9536 kWh
    'power'     => -5216000,          // /1000 = -5216 W (Einspeisung)
    'power1' => -1494070, 'power2' => -1878280, 'power3' => -1843530,
    'voltage1' => 237600, 'voltage2' => 240300, 'voltage3' => 239000,
];
$hub = new HubStub(['GroupPowerPhase', 'GroupVoltagePhase']);
$ok = $drv->readFast($cs, $hub);
$drv->readSlow($cs, $hub);
check('readFast OK', $ok === true);
check('connected', ($hub->vals['connected'] ?? null) === true);
check('power_total = -5216 W', ($hub->vals['power_total'] ?? null) === -5216.0, (string)($hub->vals['power_total'] ?? 'fehlt'));
check('energy_import = 10512.4169 kWh', ($hub->vals['energy_import'] ?? null) === 10512.4169, (string)($hub->vals['energy_import'] ?? 'fehlt'));
check('energy_export = 4482.9536 kWh', ($hub->vals['energy_export'] ?? null) === 4482.9536, (string)($hub->vals['energy_export'] ?? 'fehlt'));
check('p_l1 = -1494.07 W', ($hub->vals['p_l1'] ?? null) === -1494.07);
check('u_l2_n = 240.3 V', ($hub->vals['u_l2_n'] ?? null) === 240.3);

// Fall B: neuere Firmware-Feldnamen (phase1Power/phase1Voltage).
$cs2 = new ClientStub();
$cs2->values = [
    'energy' => 105124169000000, 'energyOut' => 44829536000000, 'power' => 100000,
    'phase1Power' => 33000, 'phase2Power' => 34000, 'phase3Power' => 33000,
    'phase1Voltage' => 230000, 'phase2Voltage' => 231000, 'phase3Voltage' => 229000,
];
$hub2 = new HubStub(['GroupPowerPhase', 'GroupVoltagePhase']);
$drv->readFast($cs2, $hub2);
check('phase1Power-Variante erkannt (p_l1 = 33 W)', ($hub2->vals['p_l1'] ?? null) === 33.0, (string)($hub2->vals['p_l1'] ?? 'fehlt'));
check('phase1Voltage-Variante erkannt (u_l1_n = 230 V)', ($hub2->vals['u_l1_n'] ?? null) === 230.0);

// Fall C: Gruppen inaktiv → keine Phasenvariablen.
$hub3 = new HubStub([]);
$drv->readFast($cs, $hub3);
check('ohne Gruppen keine p_l1', !isset($hub3->vals['p_l1']));
check('Kern trotzdem da (power_total)', isset($hub3->vals['power_total']));

// Fall D: Verbindungsfehler (null) → connected=false, kein Absturz.
$csNull = new ClientStub();
$csNull->values = null;
$hub4 = new HubStub([]);
$okNull = $drv->readFast($csNull, $hub4);
check('null-Reading → readFast false', $okNull === false);
check('null-Reading → connected false', ($hub4->vals['connected'] ?? null) === false);

// Fall E: Vertragsfelder am Treiber selbst
check('getBaseVars hat KEINE Frequenz', !in_array('frequency', array_column($drv->getBaseVars(), 0), true));

echo "\n" . ($fails === 0 ? "ALLE PRÜFUNGEN BESTANDEN\n" : "$fails PRÜFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
