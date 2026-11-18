<?php
/**
 * Gemeinsame Hilfe für die Referenzläufe (Prüfwerkzeug, 18.11.2026).
 *
 * Ein Referenzlauf hält das HEUTIGE Verhalten fest, nicht das gewünschte: Registerantworten fest vorgegeben,
 * alles, was ein Treiber in den Hub schreibt, aufgezeichnet und gegen eine Datei in `.tools/ref/` verglichen.
 * Die Referenzdatei wird nur mit UPDATE_REF=1 neu geschrieben. Die Registerwerte sind MUSTER (je Adresse ein
 * eigener, plausibel großer Wert), keine am Gerät gemessenen Werte: der Lauf belegt, was der Treiber heute
 * daraus macht, nicht, dass es physikalisch stimmt.
 *
 * Das Modul kommt aus der Umgebungsvariable MHUB_MODULE (Mutationsproben), sonst aus dem Repository.
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        use I18nStub;
        public $InstanceID = 0;
        public function __construct($id = 0) { $this->InstanceID = $id; }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103,
          'KL_MESSAGE' => 101, 'KL_WARNING' => 104, 'KL_ERROR' => 103, 'KL_NOTIFY' => 102, 'KL_DEBUG' => 100] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}
if (!function_exists('IPS_LogMessage')) { function IPS_LogMessage($s, $m) {} }
require_once getenv('MHUB_MODULE') ?: dirname(__DIR__, 2) . '/MeterHub/module.php';

/** Zeichnet jeden Schreibzugriff des Treibers auf den Hub auf (Ident, Typ, Wert); unbekannte Aufrufe werden festgehalten. */
class RefHub
{
    public array $vars = [];     // ident => [typ, wert]
    public array $order = [];    // Reihenfolge der Schreibzugriffe (nur Idents)
    public array $calls = [];    // sonstige Hub-Aufrufe
    public array $groups = [];   // Gruppe => bool; fehlende Gruppen gelten als $defaultGroup
    public bool $defaultGroup = true;
    private function put(string $typ, $ident, $v): void
    {
        $this->vars[$ident] = [$typ, RefNorm::value($v)];
        $this->order[] = $ident;
    }
    public function SetVarFloat($i, $v)      { $this->put('F', $i, (float)$v); }
    public function SetVarInt($i, $v)        { $this->put('I', $i, (int)$v); }
    public function SetVarBool($i, $v)       { $this->put('B', $i, (bool)$v); }
    public function SetVarString($i, $v)     { $this->put('S', $i, (string)$v); }
    public function SetVarEnergyWh($i, $v)   { $this->put('EWh', $i, (float)$v); }
    public function SetVarEnergykWh($i, $v)  { $this->put('EkWh', $i, (float)$v); }
    public function GroupActive($g)          { return $this->groups[$g] ?? $this->defaultGroup; }
    public function __call($n, $a)           { $this->calls[] = $n . '(' . json_encode(RefNorm::value($a)) . ')'; return null; }
}

/** Typsichere Normalisierung: Zahlen als Float mit neun gültigen Stellen (60.0 und 60 sind gleich), NaN/INF lesbar. */
class RefNorm
{
    public static function value($v)
    {
        if (is_array($v)) { return array_map([self::class, 'value'], $v); }
        if (is_float($v) || is_int($v)) {
            if (is_float($v) && is_nan($v)) { return 'NaN'; }
            if (is_float($v) && is_infinite($v)) { return $v > 0 ? 'INF' : '-INF'; }
            return is_bool($v) ? $v : sprintf('%.9g', (float)$v);
        }
        return $v;
    }
}

/**
 * Registerdatei hinter dem echten MHUB_ModbusGatewayClient. Muster je Adresse: 0x4000 + ((Adresse*131+7) & 0x1FF)
 * (positiv, als Float32-Hälfte 2 bis 32), Vorzeichen-Variante 0xC000 + …, ungültig 0xFFFF, Nullen, keine Antwort.
 * $failAt: die n-te Anfrage (1-basiert) bleibt ohne Antwort.
 */
class RefMb
{
    public array $requests = [];
    public static function make(string $pattern, ?int $failAt, RefMb $log): MHUB_ModbusGatewayClient
    {
        return new MHUB_ModbusGatewayClient(1, function (string $json) use ($pattern, $failAt, $log): string {
            $req = json_decode($json, true);
            $log->requests[] = [$req['Function'], $req['Address'], $req['Quantity']];
            if ($failAt !== null && count($log->requests) === $failAt) { return ''; }
            if ($pattern === 'silent') { return ''; }
            $data = '';
            for ($i = 0; $i < $req['Quantity']; $i++) {
                $a = $req['Address'] + $i;
                $v = match ($pattern) {
                    'pos'     => 0x4000 + (($a * 131 + 7) & 0x1FF),
                    'neg'     => 0xC000 + (($a * 131 + 7) & 0x1FF),
                    'invalid' => 0xFFFF,
                    'zero'    => 0,
                };
                $data .= pack('n', $v);
            }
            return chr($req['Function']) . chr(strlen($data)) . $data;
        });
    }
}

/** Referenzdatei lesen/schreiben (deterministisch, sortiert). */
class RefFile
{
    public static function load(string $path): ?array
    {
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }
    public static function save(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    echo ($cond ? '  ok    ' : '  FEHLT ') . $label . ($cond || $detail === '' ? '' : "  ($detail)") . "\n";
    if (!$cond) { $fails++; }
}
